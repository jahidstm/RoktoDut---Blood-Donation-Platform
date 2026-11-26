# Demand forecast backtest (simulated-seasonal data)

> **Simulated data.** These numbers describe the simulator's assumptions (`forecasting/simulate.py`), not real demand.

156 weeks x 64 series (division x blood group), horizon 4 weeks, 16 rolling-origin folds. Decision metric: WAPE (lower is better).

Selection folds: naive 0.7857, ma4 0.6107, ma13 0.6005, seasonal_naive 0.6592, ets 0.5951, lightgbm 0.5379. Chosen model: **lightgbm**.

## Judging folds

| method | WAPE | national WAPE | MASE |
|---|---|---|---|
| naive | 0.7043 | 0.1876 | 1.3598 |
| ma4 | 0.5868 | 0.2043 | 1.1772 |
| ma13 | 0.5569 | 0.2425 | 1.1062 |
| seasonal_naive | 0.6625 | 0.1454 | 1.3164 |
| ets | 0.5414 | 0.1988 | 1.088 |
| lightgbm | 0.5181 | 0.1114 | 1.1025 |

**Promoted: yes.** Forecast made with: **lightgbm**.

80% intervals, coverage on judging folds: per series 73% (1536 points), national 62% (24 points). Shown only when within 15 points of 80%.
