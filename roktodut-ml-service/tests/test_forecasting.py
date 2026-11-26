"""Forecasting pipeline: weekly series, leak-free backtest, and a gate that can say both no and yes."""

import sys
from pathlib import Path

import numpy as np
import pandas as pd
import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))

from forecasting.backtest import folds, run, score, to_wide  # noqa: E402
from forecasting.models import BASELINES, MODELS  # noqa: E402
from forecasting.series import GROUPS, weekly_panel  # noqa: E402
from forecasting.simulate import simulate  # noqa: E402


def test_panel_fills_empty_weeks_with_zero_and_drops_an_incomplete_last_week():
    rows = pd.DataFrame([
        {"created_at": "2026-01-05T10:00:00", "division": "ঢাকা", "blood_group": "O+"},   # Monday, week 1
        {"created_at": "2026-01-19T10:00:00", "division": "ঢাকা", "blood_group": "O+"},   # week 3 (week 2 empty)
        {"created_at": "2026-01-26T09:00:00", "division": "ঢাকা", "blood_group": "A+"},   # week 4, data stops Monday
    ])
    p = weekly_panel(rows)
    assert sorted(p["week"].dt.strftime("%m-%d").unique()) == ["01-05", "01-12", "01-19"]
    assert len(p) == 3 * 1 * len(GROUPS)
    o_pos = p[p["blood_group"] == "O+"].sort_values("week")["y"].tolist()
    assert o_pos == [1.0, 0.0, 1.0]


def test_methods_only_see_the_past():
    """Changing the future must not change any forecast made before it."""
    Y = np.random.default_rng(0).poisson(5, size=(4, 80)).astype(float)
    weeks = list(pd.date_range("2025-01-06", periods=80, freq="7D"))
    meta = pd.DataFrame({"division": ["a", "a", "b", "b"], "blood_group": ["O+", "A+", "O+", "A+"]})
    Y2 = Y.copy()
    Y2[:, 60:] = 999
    for name, f in {**BASELINES, **MODELS}.items():
        assert np.allclose(f(Y[:, :60], weeks[:60], 4, meta), f(Y2[:, :60], weeks[:60], 4, meta)), name


def test_forecasts_are_never_negative_and_have_the_right_shape():
    Y = np.zeros((3, 70))
    Y[0, -3:] = [5, 0, 0]
    weeks = list(pd.date_range("2025-01-06", periods=70, freq="7D"))
    meta = pd.DataFrame({"division": ["a"] * 3, "blood_group": ["O+", "A+", "B+"]})
    for name, f in {**BASELINES, **MODELS}.items():
        out = f(Y, weeks, 4, meta)
        assert out.shape == (3, 4), name
        assert (out >= 0).all(), name


def test_score_weighs_by_volume():
    actual = np.array([[10.0, 10.0], [0.0, 1.0]])
    pred = np.array([[9.0, 9.0], [1.0, 0.0]])
    s = score(actual, pred, np.array([[8.0, 12.0], [0.0, 1.0]]))
    assert s["wape"] == pytest.approx(4 / 21)
    assert s["national_wape"] == pytest.approx(2 / 21)


def test_short_history_is_refused_not_forecast():
    Y, w, m = to_wide(weekly_panel(simulate(weeks=20, seed=1)))
    report, _ = run(Y, w, m)
    assert report["status"] == "insufficient_history"
    assert folds(200)[1] == 6


@pytest.mark.parametrize("signal,expected", [("none", False), ("seasonal", True)])
def test_gate_refuses_noise_and_accepts_real_structure(signal, expected):
    Y, w, m = to_wide(weekly_panel(simulate(signal=signal, seed=2)))
    report, _ = run(Y, w, m)
    assert report["promoted"] is expected, report["reasons_not_promoted"]
    if not expected:
        assert report["method_used"] in BASELINES
