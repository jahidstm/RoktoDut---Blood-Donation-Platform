# Donor response model (model card)

**Question it answers:** of the eligible, blood-compatible donors for a request, who is most
likely to accept? It only orders donors *inside* a group tier. Eligibility, compatibility,
distance limits and fatigue exclusions are rules and stay rules.

**Status: not used in production.** No model has passed the promotion gate on real data,
because there is no real data yet. Laravel ranks with its rules, and the ML service answers
`503` on `/api/v1/rank-donors` (by design; see "Serving").

## Pipeline

```
php artisan ml:export-response-logs            # anonymised CSV, storage/app/ml/response_logs.csv
python roktodut-ml-service/scripts/train_ranker.py --input storage/app/ml/response_logs.csv
```

| Step | Where | Why |
|---|---|---|
| Export | `app/Console/Commands/ExportResponseLogs.php` | Donor ids become a keyed hash. No names, phones, coordinates. Demo requests are skipped unless `--include-demo`. |
| Labels | `ranking/features.py` | accepted = 1; declined or ignored = 0; `pending` and `moot` dropped (unknown or not the donor's choice). Rows without a distance are dropped: Laravel never asks the model about them. |
| Features | `ranking/features.py` | distance, days since last donation, hour (also as sin/cos), weekend, and the donor's smoothed response rate **recomputed point in time**: only earlier outcomes already known at notification time. Same formula as Laravel's `ResponseRateService`. |
| Split | `scripts/train_ranker.py` | By request time: oldest 60% train, next 15% validation, newest 25% test. The model is judged only on requests that happened after everything it learned from. |
| Models | same | Logistic regression, random forest, LightGBM. Chosen by validation NDCG@10, refit on train+validation, scored once on test. |
| Metrics | `ranking/evaluate.py` | Per request, the way dispatch uses scores: hit@3, hit@5, NDCG@10, MRR, plus ROC-AUC, average precision, Brier and a calibration table. Ties broken randomly. |
| Baselines | same | random; nearest first (Laravel fallback); response rate first; the current dispatch score (`rate × 100 − distance × 2`). |
| Gate | same | Promote only if the model beats the best baseline by ≥ 0.01 NDCG@10, the 95% paired bootstrap interval is above zero, and there are ≥ 50 test requests with an accepter. |

## Results so far (simulated data only)

There is no real data, so the pipeline was run on `ranking/simulate.py`. Every effect in the
simulator is an assumption; these results describe the simulator, not Bangladeshi donors.

| Simulation | What it tests | Best model | NDCG@10 model vs best baseline | Promoted |
|---|---|---|---|---|
| `none` (no signal) | the gate must say no to noise | logistic regression | −0.0007 vs nearest first (CI −0.019 to 0.017) | no ✔ |
| `nonlinear` (recent donors and > 8 km much less willing) | the gate can say yes when the rule misses structure | random forest | +0.075 vs nearest first (CI 0.047 to 0.104) | yes ✔ |
| `realistic` (log-odds linear in willingness and distance) | the realistic case | logistic regression | −0.0009 vs current rule (CI −0.004 to 0.002) | no |

Full reports: `roktodut-ml-service/artifacts/ranker_report_simulated-*.md`. The `realistic`
result held across seeds 1, 2 and 3 (gains between −0.005 and +0.003, every interval
crossing zero).

### What that means

When willingness depends on response history and distance roughly linearly, the existing
hand-written dispatch score is already about as good an ordering as a model can learn.
The reason is structural: inside one request, every candidate shares the same hour,
weekday and urgency, so those features cannot change the order. Only the donor-specific
features (distance, response history, days since donation) can. A model can only beat the
rule if real donors behave non-linearly in those, as the `nonlinear` simulation shows.

That is a question only real data can answer. Until then the honest answer is **ML is not
needed for this ranking**, and the system does not use it.

## Serving

`main.py` serves a model only if the artifact says `promoted: true` **and** it was trained
on real data. A promoted model trained on simulated data is served only with
`ROKTODUT_ALLOW_SIMULATED_MODEL=true` (for demos). Otherwise `/api/v1/rank-donors` returns
`503` with the reason, `/health` shows it, and `DonorMatchingService` falls back to rules
(tested in `DispatchPathsTest::test_no_promoted_model_means_rule_order`).

## When real data arrives

1. `php artisan ml:export-response-logs`. Under about 2,000 labelled rows it warns; expect "not promoted".
2. Train with `--input`. Read the report, not only the verdict.
3. If promoted, restart the ML service. Check `/health` shows `servable: true`.
4. Retrain periodically; a model that stops beating the rule should be dropped the same way.

## Known limitations

- The simulator keeps notifying donors who just accepted after their cooldown; real fatigue effects are not modelled.
- Only the five features Laravel sends are used. Per-donor time-of-day habits or the number of past answers (confidence) could help but need a Laravel change first.
- Selection bias: donors are only observed when the current rules chose to notify them. A model trained on that data learns about the donors the rules already like; an occasional exploration slot would be needed to fix it properly.
- The old LightGBM model, `bootstrap_data.py` and `train_model.py` were removed: that model was trained on rows generated from a hand-written formula, so it only re-learned the formula. `shap_test.py` and its old outputs belong to that model and were left in place because they had local, uncommitted changes.
