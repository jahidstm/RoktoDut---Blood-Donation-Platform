"""Simulated request history, in the same row format as `php artisan analytics:export`.

RoktoDut has a handful of real requests and six months of generated demo requests, which
is not enough to learn a yearly pattern. The simulator exists to build and test the
forecasting pipeline. Every number below is an assumption, not a Bangladeshi statistic.

  seasonal  growth of the platform, a yearly cycle and a monsoon-season bump
            (July-October, loosely modelled on dengue season) on top of noisy counts.
            There IS a pattern to learn (positive control).
  none      every week is a fresh draw around a fixed mean. Nothing to learn beyond the
            average; a model must not be preferred over a moving average (negative control).
"""

from __future__ import annotations

import numpy as np
import pandas as pd

from forecasting.series import GROUPS

# Assumed share of requests by division (roughly population-shaped).
DIVISIONS = {
    "ঢাকা": 0.34, "চট্টগ্রাম": 0.20, "রাজশাহী": 0.11, "খুলনা": 0.10,
    "রংপুর": 0.08, "ময়মনসিংহ": 0.07, "সিলেট": 0.06, "বরিশাল": 0.04,
}
# Same assumed mix as the Laravel demo generator.
GROUP_SHARE = {"O+": 0.30, "B+": 0.27, "A+": 0.22, "AB+": 0.09, "O-": 0.04, "B-": 0.04, "A-": 0.03, "AB-": 0.01}


def simulate(weeks: int = 156, weekly_requests: float = 120.0, signal: str = "seasonal", seed: int = 42,
             start: str | None = None) -> pd.DataFrame:
    """By default the simulated history ends with last week, so its forecast is for the coming weeks."""
    if signal not in ("seasonal", "none"):
        raise ValueError("signal must be 'seasonal' or 'none'")

    rng = np.random.default_rng(seed)
    if start is None:
        today = pd.Timestamp.now().normalize()
        start = str((today - pd.Timedelta(days=today.dayofweek) - pd.Timedelta(weeks=weeks)).date())
    week0 = pd.Timestamp(start) - pd.Timedelta(days=pd.Timestamp(start).dayofweek)
    rows = []

    for w in range(weeks):
        week = week0 + pd.Timedelta(weeks=w)
        woy = week.isocalendar().week

        if signal == "none":
            level = weekly_requests
        else:
            growth = 1 + 0.25 * w / 52                                   # platform grows 25% a year
            cycle = 1 + 0.12 * np.sin(2 * np.pi * (woy - 10) / 52)          # mild yearly cycle
            monsoon = 1.45 if 27 <= woy <= 43 else 1.0                       # July-October bump
            level = weekly_requests * growth * cycle * monsoon

        for division, d_share in DIVISIONS.items():
            # Dengue-style bump is strongest in the biggest cities (assumption).
            local = 1.15 if signal == "seasonal" and division in ("ঢাকা", "চট্টগ্রাম") and 27 <= woy <= 43 else 1.0
            for group in GROUPS:
                mean = level * d_share * GROUP_SHARE[group] * local
                # Negative binomial: counts are noisier than Poisson.
                n = int(rng.negative_binomial(5, 5 / (5 + mean))) if mean > 0 else 0
                for _ in range(n):
                    t = week + pd.Timedelta(minutes=int(rng.integers(0, 7 * 24 * 60)))
                    rows.append({"created_at": t.isoformat(), "blood_group": group, "division": division,
                                 "status": "approved", "is_demo": 1})

    return pd.DataFrame(rows)
