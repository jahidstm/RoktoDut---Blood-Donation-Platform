"""Ranking evaluation, per request, the way dispatch actually uses the scores.

Dispatch notifies the top few eligible donors of a request first. So the questions are
"is someone who would say yes near the top of THIS request's list?", not a global AUC.
Metrics are computed per request over requests with at least one accepter, then averaged:

  hit@k     share of requests where the first k notified include an accepter
  ndcg@10   rewards accepters placed higher (binary relevance)
  mrr       1 / rank of the first accepter

Ties are broken randomly with a fixed seed, so a constant score is not secretly favoured
by the input order.
"""

from __future__ import annotations

import numpy as np
import pandas as pd


def _per_request(labels: np.ndarray, scores: np.ndarray, rng: np.random.Generator) -> dict[str, float]:
    order = np.lexsort((rng.random(len(scores)), -scores))
    ranked = labels[order]
    first = int(np.argmax(ranked)) + 1  # caller guarantees at least one positive
    gains = ranked[:10] / np.log2(np.arange(2, min(10, len(ranked)) + 2))
    ideal = np.sort(labels)[::-1][:10] / np.log2(np.arange(2, min(10, len(ranked)) + 2))
    return {
        "hit@3": float(ranked[:3].any()),
        "hit@5": float(ranked[:5].any()),
        "ndcg@10": float(gains.sum() / ideal.sum()),
        "mrr": 1.0 / first,
    }


def per_request_metrics(df: pd.DataFrame, score_col: str, seed: int = 0) -> pd.DataFrame:
    rng = np.random.default_rng(seed)
    rows = []
    for rid, g in df.groupby("request_id", sort=True):
        labels = g["label"].to_numpy()
        if labels.sum() == 0:
            continue
        rows.append({"request_id": rid, **_per_request(labels, g[score_col].to_numpy(dtype=float), rng)})
    return pd.DataFrame(rows).set_index("request_id")


def summarize(per_req: pd.DataFrame) -> dict[str, float]:
    return {k: round(float(v), 4) for k, v in per_req.mean().items()}


def paired_bootstrap(a: pd.Series, b: pd.Series, n: int = 2000, seed: int = 0) -> dict[str, float]:
    """Mean of (a - b) over requests with a 95% bootstrap interval. a, b share the index."""
    diff = (a - b).to_numpy()
    rng = np.random.default_rng(seed)
    means = rng.choice(diff, size=(n, len(diff)), replace=True).mean(axis=1)
    return {
        "mean_diff": round(float(diff.mean()), 4),
        "ci_low": round(float(np.percentile(means, 2.5)), 4),
        "ci_high": round(float(np.percentile(means, 97.5)), 4),
    }


def baseline_scores(df: pd.DataFrame, seed: int = 0) -> dict[str, np.ndarray]:
    """Rankings that need no model.

    current_rules is the score Laravel's dispatch jobs sort the first batch by
    (SmartBloodRequestBroadcastJob::rankScore, without the group and fatigue terms, which
    are constant within one tier): response rate x 100 minus distance x 2.
    nearest_first is DonorMatchingService's fallback when the model is not used.
    """
    rng = np.random.default_rng(seed)
    return {
        "random": rng.random(len(df)),
        "nearest_first": -df["distance_km"].to_numpy(dtype=float),
        "response_rate_first": df["historical_response_rate"].to_numpy(dtype=float),
        "current_rules": 100 * df["historical_response_rate"].to_numpy(dtype=float) - 2 * df["distance_km"].to_numpy(dtype=float),
    }


def calibration_table(labels: np.ndarray, probs: np.ndarray, bins: int = 10) -> list[dict[str, float]]:
    edges = np.linspace(0, 1, bins + 1)
    out = []
    for lo, hi in zip(edges[:-1], edges[1:]):
        m = (probs >= lo) & (probs < hi if hi < 1 else probs <= hi)
        if m.sum() == 0:
            continue
        out.append({"bin": f"{lo:.1f}-{hi:.1f}", "n": int(m.sum()),
                    "mean_predicted": round(float(probs[m].mean()), 4),
                    "observed_rate": round(float(labels[m].mean()), 4)})
    return out
