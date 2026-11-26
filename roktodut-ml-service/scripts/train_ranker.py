"""Train, compare and (maybe) promote the donor response model.

    # real data exported from Laravel
    php artisan ml:export-response-logs
    python scripts/train_ranker.py --input ../storage/app/ml/response_logs.csv --data-source real

    # simulated data (see ranking/simulate.py for what that does and does not mean)
    python scripts/train_ranker.py --simulate realistic
    python scripts/train_ranker.py --simulate none       # nothing learnable: must NOT be promoted
    python scripts/train_ranker.py --simulate nonlinear  # the rule misses real structure: SHOULD be promoted

Steps:
  1. point-in-time features (ranking/features.py), labelled rows only;
  2. time split by request: oldest 60% train, next 15% validation, newest 25% test, so the
     model is always judged on requests that happened after everything it learned from;
  3. three Phitron-syllabus models (logistic regression, random forest, LightGBM), chosen
     by validation NDCG@10, refit on train+validation, scored ONCE on test;
  4. compared on test with rankings that need no model (random, nearest first, response
     rate first, and the current dispatch rule);
  5. promotion gate: the model is marked promoted only if it beats the best of those by at
     least MIN_GAIN NDCG@10 with a 95% paired bootstrap interval above zero, on enough
     test requests. Otherwise the artifact is saved but the service will not use it.
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime, timezone
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
from lightgbm import LGBMClassifier
from sklearn.ensemble import RandomForestClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import average_precision_score, brier_score_loss, roc_auc_score
from sklearn.pipeline import make_pipeline
from sklearn.preprocessing import StandardScaler

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from ranking.evaluate import (  # noqa: E402
    baseline_scores,
    calibration_table,
    paired_bootstrap,
    per_request_metrics,
    summarize,
)
from ranking.features import BASE_FEATURES, MODEL_FEATURES, model_matrix, training_frame  # noqa: E402
from ranking.simulate import simulate  # noqa: E402

MIN_GAIN = 0.01
MIN_TEST_REQUESTS = 50


def candidate_models(seed: int) -> dict:
    return {
        "logistic_regression": make_pipeline(StandardScaler(), LogisticRegression(max_iter=2000)),
        "random_forest": RandomForestClassifier(
            n_estimators=300, min_samples_leaf=25, max_features="sqrt", n_jobs=-1, random_state=seed
        ),
        "lightgbm": LGBMClassifier(
            n_estimators=300, learning_rate=0.03, num_leaves=15, min_child_samples=50,
            subsample=0.9, subsample_freq=1, colsample_bytree=0.9, random_state=seed, verbose=-1,
        ),
    }


def time_split(df: pd.DataFrame) -> tuple[pd.DataFrame, pd.DataFrame, pd.DataFrame]:
    first_seen = df.groupby("request_id")["notified_at"].min().sort_values()
    ids = first_seen.index.to_numpy()
    a, b = int(len(ids) * 0.60), int(len(ids) * 0.75)
    pick = lambda part: df[df["request_id"].isin(set(part))].copy()  # noqa: E731
    return pick(ids[:a]), pick(ids[a:b]), pick(ids[b:])


def ndcg(df: pd.DataFrame, scores: np.ndarray) -> pd.DataFrame:
    return per_request_metrics(df.assign(_s=scores), "_s")


def run(raw: pd.DataFrame, data_source: str, seed: int = 42) -> tuple[dict, dict]:
    df = training_frame(raw)
    if df.empty or df["label"].nunique() < 2:
        raise SystemExit("Not enough labelled data: need both accepted and not-accepted rows.")

    train, val, test = time_split(df)

    # 3. choose on validation
    val_scores = {}
    for name, model in candidate_models(seed).items():
        model.fit(model_matrix(train), train["label"])
        val_scores[name] = summarize(ndcg(val, model.predict_proba(model_matrix(val))[:, 1]))["ndcg@10"]
    chosen = max(val_scores, key=val_scores.get)

    final = candidate_models(seed)[chosen]
    train_val = pd.concat([train, val])
    final.fit(model_matrix(train_val), train_val["label"])
    probs = final.predict_proba(model_matrix(test))[:, 1]

    # 4. compare on test
    per_req = {"model": ndcg(test, probs)}
    for name, scores in baseline_scores(test, seed).items():
        per_req[name] = ndcg(test, scores)
    summary = {name: summarize(pr) for name, pr in per_req.items()}

    baselines = [n for n in per_req if n != "model"]
    best_baseline = max(baselines, key=lambda n: summary[n]["ndcg@10"])
    gain = paired_bootstrap(per_req["model"]["ndcg@10"], per_req[best_baseline]["ndcg@10"], seed=seed)

    # 5. gate
    n_test = len(per_req["model"])
    reasons = []
    if n_test < MIN_TEST_REQUESTS:
        reasons.append(f"only {n_test} test requests with an accepter (need {MIN_TEST_REQUESTS})")
    if gain["mean_diff"] < MIN_GAIN:
        reasons.append(f"NDCG@10 gain over {best_baseline} is {gain['mean_diff']} (need >= {MIN_GAIN})")
    if gain["ci_low"] <= 0:
        reasons.append(f"95% interval of the gain includes zero ({gain['ci_low']} to {gain['ci_high']})")
    promoted = not reasons

    report = {
        "data_source": data_source,
        "trained_at_utc": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "rows": {"labelled": int(len(df)), "train": int(len(train)), "validation": int(len(val)), "test": int(len(test))},
        "positive_rate": round(float(df["label"].mean()), 4),
        "validation_ndcg@10": val_scores,
        "chosen_model": chosen,
        "test_requests_with_accepter": n_test,
        "test_ranking": summary,
        "test_classification": {
            "roc_auc": round(float(roc_auc_score(test["label"], probs)), 4),
            "average_precision": round(float(average_precision_score(test["label"], probs)), 4),
            "brier": round(float(brier_score_loss(test["label"], probs)), 4),
            "base_rate": round(float(test["label"].mean()), 4),
        },
        "calibration": calibration_table(test["label"].to_numpy(), probs),
        "gate": {
            "compared_with": best_baseline,
            "ndcg@10_gain": gain,
            "min_gain": MIN_GAIN,
            "min_test_requests": MIN_TEST_REQUESTS,
            "promoted": promoted,
            "reasons_not_promoted": reasons,
        },
    }
    if hasattr(final, "feature_importances_"):
        report["feature_importance"] = dict(zip(MODEL_FEATURES, [round(float(x), 4) for x in final.feature_importances_ / max(final.feature_importances_.sum(), 1)]))

    artifact = {
        "model": final,
        "model_name": chosen,
        "base_features": BASE_FEATURES,
        "feature_columns": MODEL_FEATURES,
        "promoted": promoted,
        "data_source": data_source,
        "trained_at_utc": report["trained_at_utc"],
        "report": report,
    }
    return artifact, report


def markdown(report: dict) -> str:
    g = report["gate"]
    lines = [
        f"# Donor response model report ({report['data_source']} data)",
        "",
        f"Trained {report['trained_at_utc']}. Labelled rows: {report['rows']['labelled']} "
        f"(train {report['rows']['train']}, validation {report['rows']['validation']}, test {report['rows']['test']}). "
        f"Acceptance rate {report['positive_rate']:.1%}.",
        "",
    ]
    if report["data_source"] != "real":
        lines += ["> **Simulated data.** These numbers describe the simulator's assumptions, not real donors. "
                  "See `ranking/simulate.py`.", ""]
    lines += [
        f"Chosen on validation: **{report['chosen_model']}** (validation NDCG@10: "
        + ", ".join(f"{k} {v}" for k, v in report["validation_ndcg@10"].items()) + ").",
        "",
        f"## Test set ({report['test_requests_with_accepter']} later requests with at least one accepter)",
        "",
        "| ranking | hit@3 | hit@5 | NDCG@10 | MRR |",
        "|---|---|---|---|---|",
    ]
    for name, m in report["test_ranking"].items():
        lines.append(f"| {name} | {m['hit@3']} | {m['hit@5']} | {m['ndcg@10']} | {m['mrr']} |")
    c = report["test_classification"]
    gain = g["ndcg@10_gain"]
    lines += [
        "",
        f"Classification view: ROC-AUC {c['roc_auc']}, average precision {c['average_precision']} "
        f"(base rate {c['base_rate']}), Brier {c['brier']}.",
        "",
        "## Promotion gate",
        "",
        f"Model minus {g['compared_with']} on NDCG@10: {gain['mean_diff']} "
        f"(95% bootstrap interval {gain['ci_low']} to {gain['ci_high']}). Needed: at least {g['min_gain']} "
        f"with the interval above zero, on at least {g['min_test_requests']} test requests.",
        "",
        f"**Promoted: {'yes' if g['promoted'] else 'no'}.**",
    ]
    lines += [f"- {r}" for r in g["reasons_not_promoted"]]
    if report["data_source"] != "real":
        lines += ["", "Even when promoted, the service only uses a model trained on simulated data if "
                  "`ROKTODUT_ALLOW_SIMULATED_MODEL=true` is set (demos only)."]
    return "\n".join(lines) + "\n"


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    src = p.add_mutually_exclusive_group(required=True)
    src.add_argument("--input", type=Path, help="CSV from php artisan ml:export-response-logs")
    src.add_argument("--simulate", choices=["realistic", "none", "nonlinear"])
    p.add_argument("--data-source", default=None, help="Label stored with the model (default: real for --input, simulated-<mode> for --simulate)")
    p.add_argument("--output", type=Path, default=ROOT / "models" / "donor_ranker.joblib")
    p.add_argument("--report-dir", type=Path, default=ROOT / "artifacts")
    p.add_argument("--seed", type=int, default=42)
    args = p.parse_args()

    if args.input:
        raw = pd.read_csv(args.input)
        source = args.data_source or "real"
    else:
        raw = simulate(signal=args.simulate, seed=args.seed)
        source = args.data_source or f"simulated-{args.simulate}"

    artifact, report = run(raw, source, seed=args.seed)

    args.output.parent.mkdir(parents=True, exist_ok=True)
    joblib.dump(artifact, args.output)
    args.report_dir.mkdir(parents=True, exist_ok=True)
    (args.report_dir / f"ranker_report_{source}.json").write_text(json.dumps(report, indent=2), encoding="utf-8")
    (args.report_dir / f"ranker_report_{source}.md").write_text(markdown(report), encoding="utf-8")

    print(markdown(report))
    print(f"Model artifact: {args.output}")


if __name__ == "__main__":
    main()
