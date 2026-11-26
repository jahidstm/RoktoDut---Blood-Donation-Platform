# Weekly demand forecast

**Question:** in the next four weeks, how many requests should we expect for each blood
group in each division? This helps plan donation camps and recruitment drives.

**Status:** the pipeline works. RoktoDut does not have enough real history to forecast
(it needs about 40 complete weeks), so the admin page currently shows a forecast built
from **simulated** history, with a banner saying so.

## Running it

```
php artisan analytics:export --source=real
cd roktodut-ml-service
python scripts/forecast_demand.py --input ../storage/app/analytics/requests_flat.csv
```

This writes `storage/app/forecast/latest.json`, which the "পূর্বাভাস" panel on `/admin/analytics`
reads, and a report in `roktodut-ml-service/artifacts/`. Run it weekly (e.g. a scheduled
task). The page flags a file whose forecast weeks have already passed.

## Method

| Step | Where | Detail |
|---|---|---|
| Series | `forecasting/series.py` | Weekly counts per division × blood group (8 × 8 = 64 series). Empty weeks are zeros. An unfinished last week is dropped. |
| Baselines | `forecasting/models.py` | last week; 4- and 13-week moving averages; same week last year. |
| Models | same | Damped-trend exponential smoothing per series (statsmodels); one global LightGBM (Poisson loss) over all series with lag, moving-average, same-week-last-year and week-of-year features, one row per forecast horizon. |
| Backtest | `forecasting/backtest.py` | Rolling origin, 4-week horizon, up to 16 folds. Older folds (up to 10) choose the model and size the intervals; the newest 6 judge it. |
| Metric | same | WAPE across all series (errors weighted by volume). National WAPE and MASE are reported too. |
| Gate | same | The chosen model is used only if its WAPE is at least 5% below the best baseline's on the judging folds and it wins at least 4 of the 6. Otherwise the best baseline makes the forecast and the page names it. |
| Intervals | same | 80% intervals from errors on the selection folds, scaled by √(forecast + 1). Shown only if they covered between 65% and 95% of outcomes on the judging folds. |
| Too little data | same | Fewer than 3 judging folds: no forecast at all, and the page says so. |

## Results (simulated data only)

`forecasting/simulate.py` assumes platform growth, a mild yearly cycle and a July–October
bump (a stand-in for dengue season), stronger in Dhaka and Chattogram, with noisy counts.
Every number in it is an assumption; these results describe the simulator, not Bangladesh.

**Controls over 8 random seeds**

| History | What should happen | Promoted |
|---|---|---|
| `none`: only noise around a fixed mean | the gate says no | 0 of 8 ✔ |
| `seasonal`: growth and season to learn | the gate says yes | 6 of 8 |

In the `seasonal` runs LightGBM's WAPE was 8–13% below the best baseline in all 8 seeds. The
two misses fell just under the bar on fold wins or margin. This is the cost of a gate that never says yes to noise.

**Default run (seed 42)**, judging folds:

| method | WAPE | national WAPE |
|---|---|---|
| best baseline (4-week average) | 0.588 | 0.159 |
| exponential smoothing | 0.576 | 0.241 |
| **LightGBM (promoted)** | **0.501** | **0.092** |

Per-series intervals covered 73% (target 80%). National intervals covered only 62% on 24
points, so the page hides them.

### What that means

- National totals can be forecast well. Single division × group cells cannot: a cell with
  two requests a week is mostly noise, and the per-series error stays around 50%
  whatever the method. Plan at the division or national level and treat cell numbers as rough.
- The model wins because the simulator has a seasonal bump and growth that moving averages
  lag behind. Whether real demand has a learnable season is unknown until real data exists.

## Decisions made after seeing results

These are recorded because they make the simulated scores less independent:

1. The decision metric was first MASE (each series weighted equally). With 64 mostly tiny
   series, noise dominated and every method looked equal. It was switched to WAPE, which
   weighs series by volume, matching the planning use.
2. The first version chose the model on 4 folds. That choice was noisy (it picked the worse
   model in half of the seeds), so selection now uses up to 10 older folds.
3. The rule to hide intervals with poor coverage was added after the national intervals
   covered only 19% in an early run.

Thresholds (5% margin, two-thirds of folds) were not tuned after that.

## Limitations

- No holidays (Eid, Puja), floods or outbreaks as inputs. A real structural break will make
  every method wrong for a while, and the moving averages recover first.
- Divisions and groups are forecast independently and then summed. There is no reconciliation.
- Demand is "requests posted on RoktoDut", not national blood need. It grows with the platform itself.
