"""Forecasters over a wide matrix Y[series, week] of weekly counts.

Every forecaster has the same signature: f(Y_train, weeks_train, H, meta) -> yhat[series, H],
where weeks_train are the Monday dates of Y_train's columns and meta has one row per series
(division, blood_group). Forecasts are never negative.

Baselines need no fitting: naive (last week), ma4 / ma13 (moving averages), seasonal_naive
(same week last year). Models: ets (damped-trend exponential smoothing per series) and
lightgbm (one global model over all series with lag and calendar features).
"""

from __future__ import annotations

import warnings

import numpy as np
import pandas as pd

SEASON = 52


def naive(Y, weeks, H, meta):
    return np.repeat(Y[:, -1:], H, axis=1)


def ma4(Y, weeks, H, meta):
    return np.repeat(Y[:, -4:].mean(axis=1, keepdims=True), H, axis=1)


def ma13(Y, weeks, H, meta):
    return np.repeat(Y[:, -13:].mean(axis=1, keepdims=True), H, axis=1)


def seasonal_naive(Y, weeks, H, meta):
    if Y.shape[1] < SEASON:
        raise ValueError("needs a year of history")
    T = Y.shape[1]
    return np.stack([Y[:, T - SEASON + h] for h in range(H)], axis=1)


def ets(Y, weeks, H, meta):
    from statsmodels.tsa.holtwinters import ExponentialSmoothing

    out = np.zeros((Y.shape[0], H))
    for i, y in enumerate(Y):
        if y[-26:].sum() == 0:
            continue
        try:
            with warnings.catch_warnings():
                warnings.simplefilter("ignore")
                fit = ExponentialSmoothing(y, trend="add", damped_trend=True, initialization_method="estimated").fit()
            out[i] = fit.forecast(H)
        except Exception:  # noqa: BLE001 - a series that will not fit falls back to its recent mean
            out[i] = y[-13:].mean()
    return np.clip(out, 0, None)


def _features(Y, weeks, t, h, meta_codes):
    """Features known at the end of week t, for the target week t + h (direct forecasting)."""
    S, T = Y.shape
    target_week = weeks[0] + pd.Timedelta(weeks=t + h)
    woy = target_week.isocalendar().week
    seasonal = Y[:, t + h - SEASON] if t + h - SEASON >= 0 else np.full(S, np.nan)
    return np.column_stack([
        Y[:, t], Y[:, t - 1], Y[:, t - 2], Y[:, t - 3],
        Y[:, t - 3:t + 1].mean(axis=1),
        Y[:, t - 12:t + 1].mean(axis=1),
        Y[:, max(0, t - 51):t + 1].mean(axis=1),
        seasonal,
        np.full(S, np.sin(2 * np.pi * woy / 52)), np.full(S, np.cos(2 * np.pi * woy / 52)),
        np.full(S, h),
        meta_codes,
    ])


def lightgbm(Y, weeks, H, meta):
    from lightgbm import LGBMRegressor

    S, T = Y.shape
    codes = np.column_stack([
        pd.Categorical(meta["division"]).codes, pd.Categorical(meta["blood_group"]).codes,
    ])

    X, y = [], []
    for t in range(12, T - 1):
        for h in range(1, H + 1):
            if t + h < T:
                X.append(_features(Y, weeks, t, h, codes))
                y.append(Y[:, t + h])
    model = LGBMRegressor(objective="poisson", n_estimators=300, learning_rate=0.03, num_leaves=15,
                          min_child_samples=30, subsample=0.9, subsample_freq=1, random_state=0, verbose=-1)
    with warnings.catch_warnings():
        warnings.simplefilter("ignore")
        model.fit(np.vstack(X), np.concatenate(y), categorical_feature=[11, 12])
        out = np.column_stack([model.predict(_features(Y, weeks, T - 1, h, codes)) for h in range(1, H + 1)])
    return np.clip(out, 0, None)


BASELINES = {"naive": naive, "ma4": ma4, "ma13": ma13, "seasonal_naive": seasonal_naive}
MODELS = {"ets": ets, "lightgbm": lightgbm}
