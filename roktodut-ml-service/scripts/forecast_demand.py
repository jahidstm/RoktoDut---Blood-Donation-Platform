"""Backtest the forecasters, then forecast the next weeks of requests per division and blood group.

    # real requests exported from Laravel
    php artisan analytics:export --source=real
    python scripts/forecast_demand.py --input ../storage/app/analytics/requests_flat.csv

    # simulated history (see forecasting/simulate.py for what it does and does not mean)
    python scripts/forecast_demand.py --simulate seasonal
    python scripts/forecast_demand.py --simulate none        # nothing to learn: the gate must say no

Writes storage/app/forecast/latest.json for the admin analytics page, and a report under
artifacts/. The forecast always comes from the method the backtest supports: the model if
it passed the gate, otherwise the best simple baseline, and the file says which.
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime, timezone
from pathlib import Path

import numpy as np
import pandas as pd

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from forecasting.backtest import H, apply_interval, run, to_wide  # noqa: E402
from forecasting.models import BASELINES, MODELS  # noqa: E402
from forecasting.series import weekly_panel  # noqa: E402
from forecasting.simulate import simulate  # noqa: E402

TOP_SERIES = 12
HISTORY_SHOWN = 16


def build(requests: pd.DataFrame, data_source: str) -> tuple[dict, dict]:
    panel = weekly_panel(requests)
    if panel.empty:
        report = {"status": "insufficient_history", "weeks": 0}
    else:
        Y, weeks, meta = to_wide(panel)
        report, _ = run(Y, weeks, meta)

    base = {
        "data_source": data_source,
        "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "horizon_weeks": H,
    }
    if report["status"] != "ok":
        return {**base, **report}, report

    methods = {**BASELINES, **MODELS}
    pred = methods[report["method_used"]](Y, weeks, H, meta)
    q = report["intervals"]
    future = [weeks[-1] + pd.Timedelta(weeks=h) for h in range(1, H + 1)]

    nat = pred.sum(axis=0)
    nat_lo, nat_hi = apply_interval(nat, q["national"])
    lo, hi = apply_interval(pred, q["series"])

    series = []
    for i, row in meta.iterrows():
        series.append({
            "division": row["division"],
            "blood_group": row["blood_group"],
            "next_week": {"mean": round(float(pred[i, 0]), 1), "lo": round(float(lo[i, 0]), 1), "hi": round(float(hi[i, 0]), 1)},
            "four_week_total": round(float(pred[i].sum()), 1),
            "last_four_weeks": int(Y[i, -4:].sum()),
        })
    series.sort(key=lambda s: -s["four_week_total"])

    by_division = (
        pd.DataFrame({"division": meta["division"], "next": pred.sum(axis=1), "last": Y[:, -4:].sum(axis=1)})
        .groupby("division").sum().sort_values("next", ascending=False)
    )

    out = {
        **base,
        "status": "ok",
        "history_weeks": report["weeks"],
        "last_complete_week": str(weeks[-1].date()),
        "method_used": report["method_used"],
        "promoted": report["promoted"],
        "chosen_model": report["chosen_model"],
        "best_baseline": report["best_baseline"],
        "reasons_not_promoted": report["reasons_not_promoted"],
        "backtest": {"judging": report["judging"], "folds": report["folds"], "decision_metric": report["decision_metric"]},
        "intervals": {"nominal": q["nominal"],
                      "national": {k: q["national"][k] for k in ("coverage_on_judging_folds", "reliable")},
                      "series": {k: q["series"][k] for k in ("coverage_on_judging_folds", "reliable")}},
        "national": {
            "history": [{"week": str(w.date()), "y": int(v)} for w, v in zip(weeks[-HISTORY_SHOWN:], Y.sum(axis=0)[-HISTORY_SHOWN:])],
            "forecast": [{"week": str(w.date()), "mean": round(float(m), 1), "lo": round(float(a), 1), "hi": round(float(b), 1)}
                         for w, m, a, b in zip(future, nat, nat_lo, nat_hi)],
        },
        "by_division": [{"division": d, "four_week_total": round(float(r["next"]), 1), "last_four_weeks": int(r["last"])}
                        for d, r in by_division.iterrows()],
        "top_series": series[:TOP_SERIES],
    }
    return out, report


def markdown(out: dict, report: dict) -> str:
    lines = [f"# Demand forecast backtest ({out['data_source']} data)", ""]
    if out["data_source"] != "real":
        lines += ["> **Simulated data.** These numbers describe the simulator's assumptions "
                  "(`forecasting/simulate.py`), not real demand.", ""]
    if report["status"] != "ok":
        return "\n".join(lines + [f"Not enough history: {report['weeks']} complete weeks, need about {report.get('needed_weeks')}."]) + "\n"

    lines += [
        f"{report['weeks']} weeks x {report['series']} series (division x blood group), horizon {report['horizon_weeks']} weeks, "
        f"{report['folds']} rolling-origin folds. Decision metric: {report['decision_metric'].upper()} (lower is better).",
        "",
        "Selection folds: " + ", ".join(f"{k} {v}" for k, v in report["selection"].items())
        + f". Chosen model: **{report['chosen_model']}**.",
        "",
        "## Judging folds",
        "",
        "| method | WAPE | national WAPE | MASE |",
        "|---|---|---|---|",
    ]
    for name, m in report["judging"].items():
        lines.append(f"| {name} | {m['wape']} | {m['national_wape']} | {m['mase']} |")
    lines += ["", f"**Promoted: {'yes' if report['promoted'] else 'no'}.** Forecast made with: **{report['method_used']}**."]
    lines += [f"- {r}" for r in report["reasons_not_promoted"]]
    iv = report["intervals"]
    lines += ["", f"80% intervals, coverage on judging folds: per series {iv['series']['coverage_on_judging_folds']:.0%} "
              f"({iv['series']['checked_points']} points), national {iv['national']['coverage_on_judging_folds']:.0%} "
              f"({iv['national']['checked_points']} points). Shown only when within 15 points of 80%."]
    return "\n".join(lines) + "\n"


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    src = p.add_mutually_exclusive_group(required=True)
    src.add_argument("--input", type=Path, help="CSV from php artisan analytics:export")
    src.add_argument("--simulate", choices=["seasonal", "none"])
    p.add_argument("--data-source", default=None)
    p.add_argument("--seed", type=int, default=42)
    p.add_argument("--output", type=Path, default=ROOT.parent / "storage" / "app" / "forecast" / "latest.json")
    p.add_argument("--report-dir", type=Path, default=ROOT / "artifacts")
    args = p.parse_args()

    if args.input:
        requests = pd.read_csv(args.input)
        source = args.data_source or "real"
    else:
        requests = simulate(signal=args.simulate, seed=args.seed)
        source = args.data_source or f"simulated-{args.simulate}"

    out, report = build(requests, source)

    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(out, ensure_ascii=False, indent=1), encoding="utf-8")
    args.report_dir.mkdir(parents=True, exist_ok=True)
    (args.report_dir / f"forecast_report_{source}.json").write_text(json.dumps(report, indent=2, default=str), encoding="utf-8")
    (args.report_dir / f"forecast_report_{source}.md").write_text(markdown(out, report), encoding="utf-8")

    print(markdown(out, report))
    print(f"Forecast file: {args.output}")


if __name__ == "__main__":
    main()
