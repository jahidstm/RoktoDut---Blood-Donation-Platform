"""Features for "will this donor accept this request?".

Raw log schema (one row per donor notified for a request), produced both by
`php artisan ml:export-response-logs` and by ranking.simulate:

    request_id, donor_key, notified_at, status, responded_at,
    distance_km, days_since_last_donation, temporal_hour, is_weekend, urgency

The response-rate feature is recomputed here, point in time: for each notification it only
uses the same donor's earlier notifications whose outcome was already known at that moment.
Using the donor's overall rate (which includes this very notification and later ones) would
leak the label into the feature and inflate every score.

The definition matches Laravel's ResponseRateService so training and serving agree:
    rate = (accepted + strength * prior_mean) / (answered + strength)
with answered = accepted + declined + ignored. Pending and moot rows are not outcomes.
"""

from __future__ import annotations

import numpy as np
import pandas as pd

PRIOR_MEAN = 0.3
PRIOR_STRENGTH = 5.0
IGNORE_AFTER = pd.Timedelta(hours=24)

LABELLED_STATUSES = ("accepted", "declined", "ignored")

# What Laravel sends for each candidate (see DonorMatchingService::generateCandidateList).
BASE_FEATURES = [
    "distance_km",
    "days_since_last_donation",
    "temporal_hour",
    "is_weekend",
    "historical_response_rate",
]

# What the models see. Hour is also given as a point on a circle, so 23:00 and 00:00 are
# neighbours; linear models cannot learn "evenings are better" from a raw 0-23 number.
MODEL_FEATURES = BASE_FEATURES + ["hour_sin", "hour_cos"]


def model_matrix(df: pd.DataFrame) -> pd.DataFrame:
    """The exact columns the models are trained and served on, in order."""
    out = df[BASE_FEATURES].astype(float).copy()
    angle = 2 * np.pi * out["temporal_hour"] / 24.0
    out["hour_sin"] = np.sin(angle)
    out["hour_cos"] = np.cos(angle)
    return out[MODEL_FEATURES]


def resolved_at(df: pd.DataFrame) -> pd.Series:
    """When each outcome became known; NaT for rows that never resolved."""
    notified = pd.to_datetime(df["notified_at"], utc=True)
    responded = pd.to_datetime(df["responded_at"], utc=True, errors="coerce")
    answered = df["status"].isin(["accepted", "declined"])
    out = pd.Series(pd.NaT, index=df.index, dtype="datetime64[ns, UTC]")
    out[answered] = responded[answered].fillna(notified[answered])
    ignored = df["status"] == "ignored"
    out[ignored] = notified[ignored] + IGNORE_AFTER
    return out


def add_point_in_time_history(df: pd.DataFrame) -> pd.DataFrame:
    """Adds prior_answers, prior_accepts and historical_response_rate (smoothed), point in time."""
    df = df.copy()
    df["notified_at"] = pd.to_datetime(df["notified_at"], utc=True)
    df["_resolved_at"] = resolved_at(df)
    df["prior_answers"] = 0
    df["prior_accepts"] = 0

    for _, idx in df.groupby("donor_key").groups.items():
        g = df.loc[idx]
        done = g[g["_resolved_at"].notna()].sort_values("_resolved_at")
        if done.empty:
            continue
        times = done["_resolved_at"].to_numpy()
        accepts = np.cumsum((done["status"] == "accepted").to_numpy())
        # Outcomes resolved strictly before each notification.
        k = np.searchsorted(times, g["notified_at"].to_numpy(), side="left")
        df.loc[idx, "prior_answers"] = k
        df.loc[idx, "prior_accepts"] = np.where(k > 0, accepts[np.maximum(k - 1, 0)], 0)

    df["historical_response_rate"] = (
        (df["prior_accepts"] + PRIOR_STRENGTH * PRIOR_MEAN) / (df["prior_answers"] + PRIOR_STRENGTH)
    )
    return df.drop(columns=["_resolved_at"])


def training_frame(raw: pd.DataFrame) -> pd.DataFrame:
    """Point-in-time features plus a label, restricted to rows that can be learned from.

    Dropped: unresolved rows (pending, moot) and rows without a distance (Laravel never asks
    the model about those; it falls back to rules when any distance is unknown).
    """
    df = add_point_in_time_history(raw)
    df = df[df["status"].isin(LABELLED_STATUSES)]
    df = df[df["distance_km"].notna()].copy()
    df["is_weekend"] = df["is_weekend"].astype(str).str.lower().isin(["1", "true"]).astype(int)
    df["label"] = (df["status"] == "accepted").astype(int)
    return df.reset_index(drop=True)
