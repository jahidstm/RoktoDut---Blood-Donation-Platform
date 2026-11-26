# Demand forecast backtest (simulated-none data)

> **Simulated data.** These numbers describe the simulator's assumptions (`forecasting/simulate.py`), not real demand.

156 weeks x 64 series (division x blood group), horizon 4 weeks, 16 rolling-origin folds. Decision metric: WAPE (lower is better).

Selection folds: naive 0.8486, ma4 0.6748, ma13 0.6442, seasonal_naive 0.852, ets 0.6247, lightgbm 0.6262. Chosen model: **ets**.

## Judging folds

| method | WAPE | national WAPE | MASE |
|---|---|---|---|
| naive | 0.7853 | 0.137 | 1.0086 |
| ma4 | 0.649 | 0.1053 | 0.8911 |
| ma13 | 0.6087 | 0.097 | 0.8621 |
| seasonal_naive | 0.7811 | 0.1734 | 1.0068 |
| ets | 0.5992 | 0.1036 | 0.8608 |
| lightgbm | 0.6018 | 0.0989 | 0.9011 |

**Promoted: no.** Forecast made with: **ma13**.
- ets WAPE 0.599 is not at least 5% below ma13 0.609

80% intervals, coverage on judging folds: per series 82% (1536 points), national 88% (24 points). Shown only when within 15 points of 80%.
