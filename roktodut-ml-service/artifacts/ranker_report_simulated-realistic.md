# Donor response model report (simulated-realistic data)

Trained 2026-11-26T04:41:15+00:00. Labelled rows: 34583 (train 20844, validation 5145, test 8594). Acceptance rate 11.4%.

> **Simulated data.** These numbers describe the simulator's assumptions, not real donors. See `ranking/simulate.py`.

Chosen on validation: **logistic_regression** (validation NDCG@10: logistic_regression 0.6108, random_forest 0.5984, lightgbm 0.5967).

## Test set (515 later requests with at least one accepter)

| ranking | hit@3 | hit@5 | NDCG@10 | MRR |
|---|---|---|---|---|
| model | 0.6252 | 0.8 | 0.5857 | 0.4918 |
| random | 0.4136 | 0.6058 | 0.4683 | 0.3707 |
| nearest_first | 0.5262 | 0.7262 | 0.5244 | 0.4192 |
| response_rate_first | 0.5573 | 0.7437 | 0.5603 | 0.4531 |
| current_rules | 0.6233 | 0.8039 | 0.5866 | 0.4922 |

Classification view: ROC-AUC 0.6975, average precision 0.2073 (base rate 0.1065), Brier 0.0906.

## Promotion gate

Model minus current_rules on NDCG@10: -0.0009 (95% bootstrap interval -0.004 to 0.0018). Needed: at least 0.01 with the interval above zero, on at least 50 test requests.

**Promoted: no.**
- NDCG@10 gain over current_rules is -0.0009 (need >= 0.01)
- 95% interval of the gain includes zero (-0.004 to 0.0018)

Even when promoted, the service only uses a model trained on simulated data if `ROKTODUT_ALLOW_SIMULATED_MODEL=true` is set (demos only).
