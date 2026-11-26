"""Simulated donor response logs, in the same raw schema as the Laravel export.

Why a simulator: RoktoDut has almost no real response data yet. A simulator lets the whole
pipeline (point-in-time features, time split, baselines, promotion gate) be built and tested
now, and swapped onto real exports later without code changes.

What it can NOT do: tell us how real donors behave. Every effect below is an assumption.
A model trained here learns these assumptions back, so its scores describe the simulator,
not Bangladesh. Two modes make that explicit:

  realistic  each donor has a hidden willingness (the thing response history is a noisy view
             of), plus assumed effects of distance, time of day and recent donation.
  none       everyone accepts with the same probability; nothing is learnable. The promotion
             gate must refuse any model trained on this (negative control).
  nonlinear  like realistic, but donors who donated recently (under 150 days) are much less
             willing and willingness falls off a cliff beyond 8 km. The current dispatch rule
             ignores the first and treats distance linearly, so a model SHOULD beat it here
             (positive control: proves the gate can say yes).
"""

from __future__ import annotations

import numpy as np
import pandas as pd


def _logit(p):
    return np.log(p / (1 - p))


def simulate(
    n_requests: int = 3000,
    n_donors: int = 1500,
    months: int = 9,
    signal: str = "realistic",
    seed: int = 42,
) -> pd.DataFrame:
    if signal not in ("realistic", "none", "nonlinear"):
        raise ValueError("signal must be 'realistic', 'none' or 'nonlinear'")

    rng = np.random.default_rng(seed)
    start = pd.Timestamp("2026-01-01", tz="UTC")
    span_min = months * 30 * 24 * 60

    # Hidden per-donor willingness, mean about 0.25. Never exported.
    willingness = rng.beta(2.0, 6.0, size=n_donors)
    # Each donor's last donation, as days before the start (all eligible at first notification).
    last_gap = rng.integers(90, 500, size=n_donors)

    rows = []
    request_times = np.sort(rng.integers(0, span_min, size=n_requests))
    for rid, offset in enumerate(request_times, start=1):
        t = start + pd.Timedelta(minutes=int(offset))
        hour = int(t.hour)
        urgency = rng.choice(["normal", "urgent", "emergency"], p=[0.55, 0.3, 0.15])
        # Laravel only notifies eligible donors, so neither does the simulator.
        eligible = np.flatnonzero(last_gap + (t - start).days >= 90)
        if eligible.size == 0:
            continue
        notified = rng.choice(eligible, size=min(eligible.size, int(rng.integers(8, 16))), replace=False)

        for d in notified:
            distance = float(np.clip(rng.lognormal(np.log(6.0), 0.7), 0.2, 35.0))
            days = int(last_gap[d] + (t - start).days)

            if signal == "none":
                p = 0.2
            elif signal == "nonlinear":
                z = (
                    _logit(willingness[d])
                    - (2.0 if distance > 8 else 0.0)
                    - (1.8 if days < 150 else 0.0)
                    + (0.35 if 18 <= hour <= 22 else 0.0)
                )
                p = 1 / (1 + np.exp(-z))
            else:
                z = (
                    _logit(willingness[d])
                    - 0.09 * distance
                    + (0.35 if 18 <= hour <= 22 else 0.0)
                    - (1.3 if 1 <= hour <= 5 else 0.0)
                    + (0.4 if urgency == "emergency" else 0.0)
                    + 0.15 * min(days - 90, 300) / 300
                )
                p = 1 / (1 + np.exp(-z))

            roll = rng.random()
            if roll < p:
                status = "accepted"
            elif roll < p + (1 - p) * 0.3:
                status = "declined"
            else:
                status = "ignored"

            responded = None
            if status != "ignored":
                responded = t + pd.Timedelta(minutes=float(np.clip(rng.lognormal(np.log(25), 1.0), 1, 1400)))

            rows.append({
                "request_id": rid,
                "donor_key": f"sim-{d}",
                "notified_at": t.isoformat(),
                "status": status,
                "responded_at": responded.isoformat() if responded is not None else "",
                "distance_km": round(distance, 2),
                "days_since_last_donation": days,
                "temporal_hour": hour,
                "is_weekend": int(t.dayofweek >= 5),
                "urgency": urgency,
            })

            # A donor who accepted has donated: their clock restarts and they drop out of the
            # eligible pool for 90 days.
            if status == "accepted":
                last_gap[d] = -(t - start).days

    return pd.DataFrame(rows)
