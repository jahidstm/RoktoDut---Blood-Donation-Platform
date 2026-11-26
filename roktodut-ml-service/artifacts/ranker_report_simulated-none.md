# Donor response model report (simulated-none data)

Trained 2026-11-26T04:40:38+00:00. Labelled rows: 22626 (train 13771, validation 3372, test 5483). Acceptance rate 19.9%.

> **Simulated data.** These numbers describe the simulator's assumptions, not real donors. See `ranking/simulate.py`.

Chosen on validation: **logistic_regression** (validation NDCG@10: logistic_regression 0.5298, random_forest 0.529, lightgbm 0.5144).

## Test set (457 later requests with at least one accepter)

| ranking | hit@3 | hit@5 | NDCG@10 | MRR |
|---|---|---|---|---|
| model | 0.5449 | 0.7702 | 0.5398 | 0.4577 |
| random | 0.5492 | 0.733 | 0.5284 | 0.4355 |
| nearest_first | 0.5536 | 0.7462 | 0.5404 | 0.4695 |
| response_rate_first | 0.5317 | 0.7615 | 0.5228 | 0.4361 |
| current_rules | 0.5317 | 0.7615 | 0.524 | 0.4282 |

Classification view: ROC-AUC 0.5055, average precision 0.2033 (base rate 0.1997), Brier 0.1598.

## Promotion gate

Model minus nearest_first on NDCG@10: -0.0007 (95% bootstrap interval -0.0188 to 0.017). Needed: at least 0.01 with the interval above zero, on at least 50 test requests.

**Promoted: no.**
- NDCG@10 gain over nearest_first is -0.0007 (need >= 0.01)
- 95% interval of the gain includes zero (-0.0188 to 0.017)

Even when promoted, the service only uses a model trained on simulated data if `ROKTODUT_ALLOW_SIMULATED_MODEL=true` is set (demos only).
