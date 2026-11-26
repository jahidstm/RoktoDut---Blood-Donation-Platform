"""The ranking pipeline: leakage-free features, metrics, and a gate that can say both no and yes."""

import sys
from pathlib import Path

import numpy as np
import pandas as pd
import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))

from ranking.evaluate import per_request_metrics, summarize  # noqa: E402
from ranking.features import add_point_in_time_history, training_frame  # noqa: E402
from ranking.simulate import simulate  # noqa: E402


def log(donor, notified, status, responded=""):
    return {"request_id": 1, "donor_key": donor, "notified_at": notified, "status": status, "responded_at": responded,
            "distance_km": 1.0, "days_since_last_donation": 200, "temporal_hour": 10, "is_weekend": 0, "urgency": "normal"}


def test_history_only_uses_outcomes_known_before_the_notification():
    raw = pd.DataFrame([
        log("a", "2026-01-01T10:00:00Z", "accepted", "2026-01-01T10:30:00Z"),
        log("a", "2026-01-01T12:00:00Z", "declined", "2026-01-01T12:05:00Z"),
        # An ignored row only counts once its 24h window has passed.
        log("a", "2026-01-01T13:00:00Z", "ignored"),
        log("a", "2026-01-02T12:00:00Z", "accepted", "2026-01-02T12:10:00Z"),
        log("a", "2026-01-02T14:00:00Z", "pending"),
        log("a", "2026-01-03T14:00:00Z", "moot"),
    ])
    h = add_point_in_time_history(raw)
    assert h["prior_answers"].tolist() == [0, 1, 2, 2, 4, 4]
    assert h["prior_accepts"].tolist() == [0, 1, 1, 1, 2, 2]
    # Smoothed exactly like Laravel's ResponseRateService: (accepts + 5 * 0.3) / (answers + 5)
    assert h["historical_response_rate"].iloc[0] == pytest.approx(0.3)
    assert h["historical_response_rate"].iloc[4] == pytest.approx((2 + 1.5) / (4 + 5))


def test_a_donors_own_outcome_never_feeds_their_feature():
    raw = pd.DataFrame([log("b", "2026-01-01T10:00:00Z", "accepted", "2026-01-01T10:01:00Z")])
    assert add_point_in_time_history(raw)["prior_answers"].iloc[0] == 0


def test_training_frame_drops_unresolved_and_unlocated_rows():
    rows = [log("c", f"2026-01-0{i + 1}T10:00:00Z", s, f"2026-01-0{i + 1}T10:01:00Z")
            for i, s in enumerate(["accepted", "declined", "ignored", "pending", "moot"])]
    rows[1]["distance_km"] = None
    df = training_frame(pd.DataFrame(rows))
    assert sorted(df["status"]) == ["accepted", "ignored"]
    assert sorted(df["label"].tolist()) == [0, 1]


def test_metrics_reward_putting_the_accepter_first():
    df = pd.DataFrame({"request_id": [1, 1, 1], "label": [0, 0, 1], "good": [0.1, 0.2, 0.9], "bad": [0.9, 0.5, 0.1]})
    good = summarize(per_request_metrics(df, "good"))
    bad = summarize(per_request_metrics(df, "bad"))
    assert good["mrr"] == 1.0 and good["ndcg@10"] == 1.0 and good["hit@3"] == 1.0
    assert bad["mrr"] == pytest.approx(1 / 3, abs=1e-4)


def test_constant_scores_are_not_favoured_by_input_order():
    df = pd.DataFrame({"request_id": np.repeat(np.arange(400), 5), "label": np.tile([1, 0, 0, 0, 0], 400), "s": 0.0})
    # The accepter is always listed first; a stable sort would give MRR 1.0.
    assert summarize(per_request_metrics(df, "s"))["mrr"] < 0.6


@pytest.mark.parametrize("signal,expected", [("none", False), ("nonlinear", True)])
def test_gate_refuses_noise_and_accepts_real_structure(signal, expected):
    from train_ranker import run

    _, report = run(simulate(n_requests=1500, n_donors=800, signal=signal, seed=3), f"simulated-{signal}", seed=3)
    assert report["gate"]["promoted"] is expected, report["gate"]
