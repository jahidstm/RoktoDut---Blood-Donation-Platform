"""Turn request rows into weekly count series, one per (division, blood group).

Input is the flat file from `php artisan analytics:export` (one row per request):
created_at, blood_group, division, ... . Every week x division x group cell exists in the
output, zeros included: a week with no requests is information, not missing data.
"""

from __future__ import annotations

import pandas as pd

GROUPS = ["A+", "A-", "B+", "B-", "AB+", "AB-", "O+", "O-"]


def week_start(ts: pd.Series) -> pd.Series:
    ts = pd.to_datetime(ts).dt.tz_localize(None)
    return (ts - pd.to_timedelta(ts.dt.dayofweek, unit="D")).dt.normalize()


def weekly_panel(requests: pd.DataFrame, divisions: list[str] | None = None) -> pd.DataFrame:
    """Columns: week, division, blood_group, y. Drops the last week if it is not complete."""
    df = requests.dropna(subset=["created_at", "division", "blood_group"]).copy()
    if df.empty:
        return pd.DataFrame(columns=["week", "division", "blood_group", "y"])

    df["week"] = week_start(df["created_at"])
    last_seen = pd.to_datetime(df["created_at"]).dt.tz_localize(None).max()
    last_week = df["week"].max()
    if last_seen < last_week + pd.Timedelta(days=6):
        df = df[df["week"] < last_week]

    weeks = pd.date_range(df["week"].min(), df["week"].max(), freq="7D")
    divisions = divisions or sorted(df["division"].unique())
    grid = pd.MultiIndex.from_product([weeks, divisions, GROUPS], names=["week", "division", "blood_group"])

    counts = df.groupby(["week", "division", "blood_group"]).size()
    out = counts.reindex(grid, fill_value=0).rename("y").reset_index()
    out["y"] = out["y"].astype(float)
    return out


def series_key(df: pd.DataFrame) -> pd.Series:
    return df["division"].astype(str) + " | " + df["blood_group"].astype(str)
