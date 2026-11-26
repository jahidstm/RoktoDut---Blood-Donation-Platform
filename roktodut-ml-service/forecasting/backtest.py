"""Rolling-origin backtest, model choice, promotion gate and prediction intervals.

The most recent weeks are cut into folds of H weeks. At each fold's origin every method
sees only the weeks before it and forecasts the next H weeks. Folds are split in two parts:

  selection folds (older, up to 10)  pick the better model; calibrate interval widths
  judging folds (newest, up to 6)    compare that model with the best baseline; check coverage

so the verdict is never computed on the folds used to make a choice.

Decisions use WAPE: total absolute error over total requests, across all division x
group series. It weighs a series by its volume, which is what matters for planning: an
error of 3 bags in Dhaka O+ matters more than one in Barishal AB-. (The first version
decided on MASE, which averages every series equally; with dozens of near-empty series,
pure noise dominated it and every method looked the same. That change was made after
seeing results, and is recorded in docs/ml/DEMAND_FORECAST.md.)

MASE is still reported: each series' absolute error divided by the average week-to-week
change in its own training history (Hyndman & Koehler 2006, non-seasonal scaling).
"""

from __future__ import annotations

import numpy as np
import pandas as pd

from forecasting.models import BASELINES, MODELS, SEASON

H = 4
MAX_SELECT_FOLDS = 10
MAX_JUDGE_FOLDS = 6
MIN_TRAIN = 16
MIN_GAIN = 0.05          # model WAPE must be at least 5% lower than the best baseline...
MIN_WIN_SHARE = 2 / 3    # ...and lower in at least two thirds of the judging folds
DECISION = "wape"
MAX_COVERAGE_GAP = 0.15  # intervals are shown only if judged coverage is within 15 points of nominal
INTERVAL = (0.1, 0.9)    # 80% prediction interval


def _scale(Y):
    d = np.abs(np.diff(Y, axis=1)).mean(axis=1)
    return np.where(d > 0, d, np.nan)


def score(actual, pred, Y_train):
    err = np.abs(actual - pred)
    scale = _scale(Y_train)
    mase = np.nanmean(err.mean(axis=1) / scale) if np.isfinite(scale).any() else np.nan
    nat_err = np.abs(actual.sum(axis=0) - pred.sum(axis=0))
    return {
        "mase": float(mase),
        "mae": float(err.mean()),
        "wape": float(err.sum() / max(actual.sum(), 1)),
        "national_wape": float(nat_err.sum() / max(actual.sum(), 1)),
    }


def folds(T):
    """(origins, number of judging folds). Fewer folds when history is short."""
    n = min(MAX_SELECT_FOLDS + MAX_JUDGE_FOLDS, (T - MIN_TRAIN) // H)
    judge = min(MAX_JUDGE_FOLDS, n // 2)
    return [T - H * (n - k) for k in range(n)], judge


def run(Y, weeks, meta):
    """Returns (report, per_fold_predictions). Y: series x weeks."""
    T = Y.shape[1]
    origins, n_judge = folds(T)
    if n_judge < 3:
        return {"status": "insufficient_history", "weeks": int(T),
                "needed_weeks": MIN_TRAIN + 6 * H}, {}

    methods = dict(BASELINES)
    if origins[0] < SEASON:
        methods.pop("seasonal_naive")
    methods.update(MODELS)

    preds = {m: [] for m in methods}
    scores = {m: [] for m in methods}
    for o in origins:
        Ytr, actual = Y[:, :o], Y[:, o:o + H]
        for name, f in methods.items():
            p = f(Ytr, weeks[:o], H, meta)
            preds[name].append(p)
            scores[name].append(score(actual, p, Ytr))

    half = len(origins) - n_judge
    sel, judge = slice(0, half), slice(half, None)
    mean = lambda name, part, key=DECISION: float(np.mean([s[key] for s in scores[name][part]]))  # noqa: E731

    chosen = min(MODELS, key=lambda m: mean(m, sel))
    best_baseline = min((b for b in methods if b in BASELINES), key=lambda b: mean(b, judge))
    m_j, b_j = mean(chosen, judge), mean(best_baseline, judge)
    wins = sum(a[DECISION] < b[DECISION] for a, b in zip(scores[chosen][judge], scores[best_baseline][judge]))

    reasons = []
    if m_j > b_j * (1 - MIN_GAIN):
        reasons.append(f"{chosen} WAPE {m_j:.3f} is not at least {MIN_GAIN:.0%} below {best_baseline} {b_j:.3f}")
    if wins < np.ceil(MIN_WIN_SHARE * n_judge):
        reasons.append(f"{chosen} beat {best_baseline} in only {wins} of {len(scores[chosen][judge])} judging folds")
    promoted = not reasons
    used = chosen if promoted else min((b for b in methods if b in BASELINES), key=lambda b: mean(b, sel))

    report = {
        "status": "ok",
        "weeks": int(T),
        "series": int(Y.shape[0]),
        "horizon_weeks": H,
        "folds": len(origins),
        "fold_origins": [str(weeks[o].date()) for o in origins],
        "decision_metric": DECISION,
        "selection": {m: round(mean(m, sel), 4) for m in methods},
        "judging": {m: {k: round(mean(m, judge, k), 4) for k in ("mase", "wape", "national_wape")} for m in methods},
        "chosen_model": chosen,
        "best_baseline": best_baseline,
        "fold_wins": int(wins),
        "promoted": promoted,
        "reasons_not_promoted": reasons,
        "method_used": used,
        "intervals": intervals(Y, preds[used], origins, half),
    }
    return report, preds


def _std_resid(actual, pred):
    return (actual - pred) / np.sqrt(pred + 1)


def intervals(Y, preds, origins, half):
    """Interval multipliers learned on the selection folds, coverage measured on the judging folds.

    Errors are standardised by sqrt(forecast + 1) because count noise grows with the count,
    so one set of multipliers fits both a busy and a quiet series.
    """
    def resid(part, national):
        out = []
        for p, o in zip(preds[part], origins[part]):
            a = Y[:, o:o + H]
            out.append(_std_resid(a.sum(axis=0), p.sum(axis=0)) if national else _std_resid(a, p).ravel())
        return np.concatenate(out)

    result = {}
    for level, national in (("series", False), ("national", True)):
        lo, hi = np.quantile(resid(slice(0, half), national), INTERVAL)
        check = resid(slice(half, None), national)
        coverage = float(np.mean((check >= lo) & (check <= hi)))
        result[level] = {"lo": float(lo), "hi": float(hi),
                         "coverage_on_judging_folds": round(coverage, 3),
                         "checked_points": int(check.size),
                         "reliable": abs(coverage - (INTERVAL[1] - INTERVAL[0])) <= MAX_COVERAGE_GAP}
    result["nominal"] = INTERVAL[1] - INTERVAL[0]
    return result


def apply_interval(pred, q):
    s = np.sqrt(pred + 1)
    return np.clip(pred + q["lo"] * s, 0, None), pred + q["hi"] * s


def to_wide(panel: pd.DataFrame):
    wide = panel.pivot_table(index=["division", "blood_group"], columns="week", values="y", aggfunc="sum").sort_index()
    meta = wide.index.to_frame(index=False)
    return wide.to_numpy(dtype=float), list(wide.columns), meta
