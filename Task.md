# RoktoDut AI/ML ও Data Platform Task Board

> **Source plan:** [IMPLEMENTATION_PLAN.md](./IMPLEMENTATION_PLAN.md)  
> **Created:** 2026-10-17  
> **Repository:** `https://github.com/jahidstm/roktodut`  
> **Current status:** Planning only — কোনো Phase বা task execution শুরু হয়নি।

এই ফাইলটি implementation চালানোর সময় source of truth হিসেবে ব্যবহার করা হবে। প্রতিটি checkbox একটি independently verifiable task বা sub-task। কাজ শেষ হলে checkbox টিক দিতে হবে, status ও evidence লিখতে হবে, তারপর সংশ্লিষ্ট commit তৈরি করে `origin/main`-এ push করতে হবে।

## 1. কীভাবে এই Task Board ব্যবহার করব

### Status convention

- `[ ] Planned` — কাজ শুরু হয়নি
- `[>] In progress` — কাজ চলছে; একসঙ্গে একটির বেশি execution task শুরু করা হবে না
- `[x] Done` — acceptance criteria পূরণ, validation শেষ, commit ও push সম্পন্ন
- `[!] Blocked` — user/third-party action ছাড়া এগোনো যাচ্ছে না; blocker ও next step লিখতে হবে
- `[~] Deferred` — scope বা priority review-এর পর পরে করা হবে

### Owner convention

- **AI/Developer:** repository-র code, configuration, documentation, tests, scripts ও local validation
- **User:** credential rotation, account approval, billing/education verification, destructive history rewrite approval, এবং external UI actions
- **Third-party:** GitHub, GitHub Student Pack, Groq, Firebase, Power BI, Docker বা অন্য external service

### প্রতিটি task-এর completion gate

1. Task-এর সব sub-task সম্পন্ন এবং checkbox আপডেট।
2. সংশ্লিষ্ট automated test, lint, build বা manual verification চালানো।
3. Privacy/security impact ও documentation update যাচাই।
4. `git diff` ও `git status` review করে শুধু সংশ্লিষ্ট পরিবর্তন রাখা।
5. Meaningful commit তৈরি করা:
   `type(scope): short imperative description`
6. Commit সফলভাবে `origin/main`-এ push করা।
7. নিচের task-এর **Evidence / Commit** ঘরে commit SHA, validation command এবং ফল লিখে `[x] Done` করা।

> **Safety gate:** secret rotation, Git history rewrite, production deployment, paid service enablement বা data deletion-এর মতো কাজ user-এর explicit approval ছাড়া শুরু করা যাবে না। এই board তৈরি করার সময় এসবের কোনো execution করা হয়নি।

## 2. Repository baseline

বর্তমান audit থেকে গুরুত্বপূর্ণ baseline:

- Laravel 12 / PHP 8.2 application, Blade/Tailwind/Alpine/Vite frontend।
- Optional FastAPI ML service: `roktodut-ml-service/`; donor ranking LightGBM এবং Groq-based request parsing ইতিমধ্যে আছে।
- Demo/seed ও synthetic data আছে; verified real production data নেই।
- `origin` বর্তমানে `https://github.com/jahidstm/roktodut.git`-এ সেট করা।
- `.env` এবং `firebase_credentials.json` বর্তমানে tracked নয়, তবে historical exposure আছে কি না আলাদা করে যাচাই করতে হবে।
- `.github/workflows/` এখনো নেই।
- Root-এ temporary-looking scripts/files এবং substantive automated test coverage যাচাই করা বাকি।
- DuckDB, analytics, Great Expectations, dashboard ও MLOps layer এখনো এই board-এর future scope।

## 3. Execution order ও dependency map

1. **Phase 0** আগে শেষ হবে; বিশেষত security ও repository hygiene task-গুলো Phase 1-এর আগে বাধ্যতামূলক।
2. **Phase 0 Task 1–3** স্বাধীনভাবে শুরু করা গেলেও secret/history cleanup-এ user approval লাগবে।
3. **Phase 1** শুরু হবে Phase 0 Task 1, 2, 3, 4, 5, 6 শেষ হওয়ার পরে।
4. **Phase 1 Task 1 (ETL)** শেষ না হলে Data Quality, Dashboard ও Forecasting শুরু হবে না।
5. **Phase 1 Task 4 (ML enhancement)**-এর আগে existing model/data contract baseline করতে হবে।
6. **Phase 2** Phase 1-এর relevant deliverable ও test foundation-এর ওপর নির্ভরশীল।
7. **Phase 3** optional; Phase 0–2-এর কোনো MVP deliverable-এর prerequisite নয়।

---

# Phase 0 — Foundation, Security ও Repository Hygiene

**Goal:** নিরাপদ, reproducible এবং CI-ready repository তৈরি করা।  
**Exit criteria:** secrets protected/rotated, temporary files handled, license/docs/config clean, basic CI green।

## Phase 0 Task 1 — Repository audit ও temporary-file policy

**Owner:** AI/Developer  
**Dependency:** None  
**Status:** `[ ] Planned`

- [ ] Tracked/untracked root-level files inventory করা।
- [ ] প্রতিটি `fix_*.php`, `test_*.php`, `temp_*.blade.php`-এর ব্যবহার/প্রয়োজন যাচাই করা।
- [ ] দরকারি test/verification script হলে proper `tests/` বা `tools/` location-এ নেওয়ার সিদ্ধান্ত লিখে রাখা।
- [ ] অপ্রয়োজনীয় temporary file সরানো অথবা `.gitignore`-এ যোগ করা।
- [ ] `.gitignore`-এ logs, local DB/artifacts, credentials, build output ও local environment coverage যাচাই করা।
- [ ] `git status`, targeted PHP syntax check এবং relevant application smoke check চালানো।
- [ ] **Acceptance:** কোনো required runtime file না ভেঙে root পরিষ্কার; ignore rules reproducible।
- **Evidence / Commit:** _execution-এর সময় পূরণ করতে হবে_

## Phase 0 Task 2 — Secret exposure audit, rotation ও history cleanup

**Owner:** AI/Developer + User + Third-party  
**Dependency:** Phase 0 Task 1-এর audit  
**Status:** `[ ] Planned`

- [ ] বর্তমান tree ও পুরো Git history-তে `.env`, Firebase credential, API key, token এবং private key scan করা।
- [ ] Historical secret পাওয়া গেলে কোন credential কোন provider-এর তা user-কে জানিয়ে তালিকা দেওয়া।
- [ ] User নিজে Firebase, Groq, OAuth, Telegram, mail ও অন্য exposed credential revoke/rotate করবেন।
- [ ] User-এর explicit approval ছাড়া `git filter-repo`/equivalent history rewrite করা যাবে না।
- [ ] Rewrite দরকার হলে backup, force-push impact, collaborator notification ও recovery plan user-এর সঙ্গে নিশ্চিত করা।
- [ ] `.env`, `firebase_credentials.json` ও সব secret pattern `.gitignore`/example policy-তে রাখা।
- [ ] `.env.example`-এ real secret নয়, safe placeholder রাখা।
- [ ] `git log --all --name-only` ও secret scan দিয়ে post-cleanup verification করা।
- [ ] **Acceptance:** active credentials rotated; history policy user-approved; repository-তে secret নেই; force-push risk documented।
- **Blocked condition:** user rotation/approval না দেওয়া পর্যন্ত task `[!] Blocked` থাকবে।
- **Evidence / Commit:** _execution-এর সময় পূরণ করতে হবে_

## Phase 0 Task 3 — License ও repository documentation foundation

**Owner:** AI/Developer  
**Dependency:** None  
**Status:** `[ ] Planned`

- [ ] Composer-এর MIT declaration-এর সঙ্গে সামঞ্জস্য রেখে root `LICENSE` যোগ করা।
- [ ] README-তে architecture, local setup, optional ML service, test/check commands এবং limitations update করা।
- [ ] Synthetic/demo data, medical-safety disclaimer এবং no-real-production-data statement স্পষ্ট করা।
- [ ] Setup instructions Windows-first এবং external credentials/configuration boundary-সহ লেখা।
- [ ] README-র দাবি repository-র actual code/config দিয়ে cross-check করা।
- [ ] **Acceptance:** নতুন contributor clean checkout থেকে setup করতে পারবে; license ও limitations unambiguous।
- **Evidence / Commit:** _execution-এর সময় পূরণ করতে হবে_

## Phase 0 Task 4 — Configuration encoding ও environment contract

**Owner:** AI/Developer  
**Dependency:** Phase 0 Task 2  
**Status:** `[ ] Planned`

- [ ] `.env.example` UTF-8 হিসেবে normalize করা।
- [ ] Laravel এবং ML service-এর সব required environment variable inventory করা।
- [ ] Safe defaults বনাম mandatory production secret আলাদা করা।
- [ ] Missing/invalid configuration-এর জন্য existing error handling যাচাই করা।
- [ ] Local SQLite এবং production MySQL/Redis assumptions documentation-এ মিলিয়ে নেওয়া।
- [ ] **Acceptance:** `.env.example` parse হয়, encoding issue নেই, setup docs-এর variable-এর সঙ্গে contract মেলে।
- **Evidence / Commit:** _execution-এর সময় পূরণ করতে হবে_

## Phase 0 Task 5 — Basic GitHub Actions CI

**Owner:** AI/Developer  
**Dependency:** Phase 0 Task 1, 3, 4  
**Third-party prerequisite:** GitHub Actions enabled on repository  
**Status:** `[ ] Planned`

- [ ] PHP syntax/lint এবং Laravel test command-এর জন্য workflow তৈরি করা।
- [ ] Node dependency install/build check যোগ করা।
- [ ] ML service-এর lightweight import/syntax/test check নির্ধারণ করা; secret-dependent live API call CI-তে নয়।
- [ ] Dependency caching ও supported runtime versions document করা।
- [ ] Pull request এবং main push trigger যোগ করা।
- [ ] Local equivalent commands চালিয়ে failure fix করা।
- [ ] GitHub Actions run green হওয়া পর্যন্ত verify করা।
- [ ] **Acceptance:** নতুন PR-এ repeatable CI চলে এবং কোনো external secret ছাড়া basic checks pass করে।
- **Blocked condition:** GitHub settings/runner permission না থাকলে user action চাইতে হবে।
- **Evidence / Commit:** _execution-এর সময় পূরণ করতে হবে_

## Phase 0 Task 6 — Seed/demo data ও test baseline

**Owner:** AI/Developer  
**Dependency:** Phase 0 Task 1, 4, 5  
**Status:** `[ ] Planned`

- [ ] Existing seeders, factories, migrations এবং current test suite audit করা।
- [ ] Seed data-তে realistic কিন্তু clearly synthetic/demo labeling নিশ্চিত করা।
- [ ] অন্তত ৫০ donor, ১০০ blood request এবং ৫০ donation-এর reproducible fixture strategy তৈরি করা (প্রয়োজন হলে)।
- [ ] Timeline, blood-group distribution, district coverage ও response records-এর consistency যাচাই করা।
- [ ] Existing domain behavior ভাঙে না এমন focused tests যোগ করা।
- [ ] **Acceptance:** clean local database seed হয়; analytics/ML pipeline-এর জন্য deterministic fixture পাওয়া যায়; tests green।
- **Evidence / Commit:** _execution-এর সময় পূরণ করতে হবে_

## Phase 0 Task 7 — Phase 0 review gate

**Owner:** AI/Developer + User  
**Dependency:** Phase 0 Task 1–6  
**Status:** `[ ] Planned`

- [ ] সব Phase 0 task-এর evidence, commit SHA এবং CI run link review করা।
- [ ] User-এর সঙ্গে history rewrite, third-party account এবং remaining blocker review করা।
- [ ] Phase 1 শুরু করার explicit approval নেওয়া।
- [ ] **Acceptance:** Phase 1-এর prerequisites complete এবং user review complete।
- **Evidence / Commit:** _review note ও approval execution-এর সময় পূরণ করতে হবে_

---

# Phase 1 — MVP Data Pipeline, Quality, Dashboard ও ML Foundation

**Goal:** Laravel operational data থেকে privacy-safe analytics warehouse ও portfolio-ready MVP তৈরি করা।  
**Exit criteria:** reproducible ETL, data quality checks, dashboard, explainable ranking baseline এবং CI artifact।

## Phase 1 Task 1 — Analytics project structure ও ETL extract

**Owner:** AI/Developer  
**Dependency:** Phase 0 Task 6, Phase 0 Task 7  
**Status:** `[ ] Planned`

- [ ] `analytics/`-এর extract/transform/load/config/tests structure তৈরি করা।
- [ ] SQLite/MySQL source থেকে প্রয়োজনীয় table/column-এর explicit allowlist করা।
- [ ] PII (phone, NID, precise coordinates, tokens) warehouse-এ copy না করার guard যোগ করা।
- [ ] District-level geography ও aggregated health/donation fields-এর contract নির্ধারণ করা।
- [ ] DuckDB local warehouse path ও artifact policy ঠিক করা।
- [ ] Extract row counts, schema এবং run metadata log করা।
- [ ] **Acceptance:** source থেকে deterministic, privacy-safe extract তৈরি হয় এবং repeat run idempotent।
- **Evidence / Commit:** _execution-এর সময় পূরণ করতে হবে_

## Phase 1 Task 2 — ETL transform/load ও analytics views

**Owner:** AI/Developer  
**Dependency:** Phase 1 Task 1  
**Status:** `[ ] Planned`

- [ ] Type normalization, null handling, deduplication ও date normalization করা।
- [ ] Blood-group, urgency, district এবং time aggregation তৈরি করা।
- [ ] DuckDB tables/views ও schema documentation যোগ করা।
- [ ] Failed row handling ও run summary implement করা।
- [ ] Local scheduled run এবং rerun behavior যাচাই করা।
- [ ] **Acceptance:** DuckDB warehouse-এ documented views থেকে dashboard queries চলে; duplicate/PII leakage নেই।
- **Evidence / Commit:** _execution-এর সময় পূরণ করতে হবে_

## Phase 1 Task 3 — Data quality framework

**Owner:** AI/Developer  
**Dependency:** Phase 1 Task 2  
**Third-party prerequisite:** Great Expectations install/compatibility  
**Status:** `[ ] Planned`

- [ ] Nullability, allowed blood groups, date range, non-negative counts এবং referential consistency-এর expectation লিখা।
- [ ] কমপক্ষে ৫টি meaningful validation rule যোগ করা।
- [ ] Pass/fail report এবং non-zero failure exit code তৈরি করা।
- [ ] pytest ও CI workflow-এর সঙ্গে integrate করা।
- [ ] Synthetic/demo data-র known limitations report-এ রাখা।
- [ ] **Acceptance:** valid fixture pass করে; intentionally invalid fixture fail করে এবং actionable report দেয়।
- **Evidence / Commit:** _execution-এর সময় পূরণ করতে হবে_

## Phase 1 Task 4 — Dashboard ও operational analytics

**Owner:** AI/Developer + User  
**Dependency:** Phase 1 Task 2, 3  
**Third-party prerequisite:** Power BI Desktop অথবা approved Metabase setup  
**Status:** `[ ] Planned`

- [ ] Dashboard tool নির্বাচন user review-তে confirm করা।
- [ ] Overview, blood-group, geography এবং time-trend page/spec তৈরি করা।
- [ ] Sensitive row-level donor data বাদ দিয়ে aggregate-only dataset ব্যবহার করা।
- [ ] Filters, definitions, refresh instructions ও screenshot evidence তৈরি করা।
- [ ] Power BI হলে user local install/connect steps সম্পন্ন করবেন; Metabase হলে Docker availability user নিশ্চিত করবেন।
- [ ] **Acceptance:** ৪টি useful view, documented refresh path, এবং no-PII dashboard।
- **Blocked condition:** Power BI/Docker installation বা account access না থাকলে user guide দিতে হবে।
- **Evidence / Commit:** _execution-এর সময় পূরণ করতে হবে_

## Phase 1 Task 5 — Existing donor-ranking model enhancement

**Owner:** AI/Developer  
**Dependency:** Phase 0 Task 6, Phase 1 Task 1  
**Status:** `[ ] Planned`

- [ ] Current model artifact, feature contract এবং rule-based eligibility boundary baseline করা।
- [ ] Hard eligibility rules ML দিয়ে override না করার test যোগ করা।
- [ ] Reproducible train/test split, stratified cross-validation ও distance-only baseline তৈরি করা।
- [ ] ROC-AUC, average precision এবং nDCG@5 report করা।
- [ ] SHAP/feature-importance output এবং synthetic-data disclaimer publish করা।
- [ ] FastAPI response schema ও Laravel integration contract regression test করা।
- [ ] **Acceptance:** model comparison reproducible; metrics honest; rule-based safety invariants pass।
- **Evidence / Commit:** _execution-এর সময় পূরণ করতে হবে_

## Phase 1 Task 6 — ML CI/CD ও model artifact workflow

**Owner:** AI/Developer  
**Dependency:** Phase 1 Task 5, Phase 0 Task 5  
**Third-party prerequisite:** GitHub Actions artifact permissions; DVC only if explicitly approved  
**Status:** `[ ] Planned`

- [ ] Train → evaluate → artifact upload workflow তৈরি করা।
- [ ] Model metadata, feature columns, data version ও synthetic label সংরক্ষণ করা।
- [ ] Artifact reproducibility এবং incompatible feature schema failure test করা।
- [ ] DVC ব্যবহার করলে remote/storage cost ও credential plan user-এর সঙ্গে confirm করা।
- [ ] Live Groq/API call CI-তে না রেখে mock/contract test রাখা।
- [ ] **Acceptance:** workflow deterministic এবং evaluation threshold/quality gate ছাড়া artifact publish করে না।
- **Evidence / Commit:** _execution-এর সময় পূরণ করতে হবে_

## Phase 1 Task 7 — Phase 1 MVP review gate

**Owner:** AI/Developer + User  
**Dependency:** Phase 1 Task 1–6  
**Status:** `[ ] Planned`

- [ ] ETL, DQ, dashboard, model এবং CI evidence review করা।
- [ ] README, screenshots, metrics ও synthetic-data disclaimers final করা।
- [ ] Phase 2 নেওয়া হবে কি না user-এর সঙ্গে সিদ্ধান্ত নেওয়া।
- [ ] **Acceptance:** portfolio-ready MVP অথবা explicitly documented deferrals।
- **Evidence / Commit:** _review note execution-এর সময় পূরণ করতে হবে_

---

# Phase 2 — Advanced Analytics, NLP ও Monitoring

**Goal:** existing ML/NLP capability-কে evaluated, observable ও honest demo হিসেবে উন্নত করা।

## Phase 2 Task 1 — Bilingual NLP evaluation ও fallback

**Owner:** AI/Developer + User  
**Dependency:** Phase 1 Task 7  
**Third-party prerequisite:** Groq API access/rate limit confirmation অথবা approved local fallback  
**Status:** `[ ] Planned`

- [ ] ৫০+ hand-labeled Bengali/English/mixed/typo examples তৈরি ও version করা।
- [ ] Blood group, urgency, location, units-এর field-level precision/recall ও exact match মাপা।
- [ ] Missing blood group-এ `unknown` এবং low-confidence behavior test করা।
- [ ] Rate limit, timeout, malformed JSON ও provider outage-এর fallback নির্ধারণ করা।
- [ ] User Groq key নিজে configure করবেন; key repository/CI log-এ যাবে না।
- [ ] **Acceptance:** evaluation report, edge-case analysis ও deterministic fallback আছে; medical advice নয়।
- **Evidence / Commit:** _execution-এর সময় পূরণ করতে হবে_

## Phase 2 Task 2 — Demand forecasting demo

**Owner:** AI/Developer  
**Dependency:** Phase 1 Task 2, 3  
**Status:** `[ ] Planned`

- [ ] Reproducible 365-day synthetic demand generator (`--seed`) তৈরি করা।
- [ ] Moving-average baseline এবং Prophet/statsmodels candidate compare করা।
- [ ] Time split (প্রথম ৯ মাস train, শেষ ৩ মাস test), MAPE/RMSE report করা।
- [ ] Notebook ও optional FastAPI endpoint-এ **demo only / synthetic data** label রাখা।
- [ ] Real demand/clinical prediction claim না করা।
- [ ] **Acceptance:** notebook reproducible, baseline comparison আছে, forecast limitation clear।
- **Evidence / Commit:** _execution-এর সময় পূরণ করতে হবে_

## Phase 2 Task 3 — Rule-based anomaly detection

**Owner:** AI/Developer  
**Dependency:** Phase 1 Task 2, 3  
**Status:** `[ ] Planned`

- [ ] কমপক্ষে ৫টি explainable rule define করা (rate burst, duplicate pattern, impossible changes ইত্যাদি)।
- [ ] `AnomalyDetectionService.php` ও focused tests তৈরি করা।
- [ ] Admin alert, audit log এবং false-positive review path যোগ করা।
- [ ] কোনো health/eligibility decision ML দিয়ে override না করা।
- [ ] **Acceptance:** each rule has test fixtures, severity/reason recorded, admin can investigate।
- **Evidence / Commit:** _execution-এর সময় পূরণ করতে হবে_

## Phase 2 Task 4 — Model/data monitoring

**Owner:** AI/Developer  
**Dependency:** Phase 1 Task 5, 6  
**Status:** `[ ] Planned`

- [ ] Prediction volume, score distribution, feature drift এবং validation metrics tracking design করা।
- [ ] Weekly report ও threshold-based alert তৈরি করা।
- [ ] Synthetic-data baseline-এর সঙ্গে production claim আলাদা রাখা।
- [ ] Sensitive payload log না করার privacy review করা।
- [ ] **Acceptance:** monitoring script/report reproducible এবং actionable alert দেয়।
- **Evidence / Commit:** _execution-এর সময় পূরণ করতে হবে_

## Phase 2 Task 5 — Metabase ও documentation polish

**Owner:** AI/Developer + User  
**Dependency:** Phase 1 Task 4  
**Third-party prerequisite:** Docker/Metabase availability  
**Status:** `[ ] Planned`

- [ ] Docker compose বা approved local setup তৈরি করা।
- [ ] DuckDB connection, অন্তত ৩টি saved question এবং access instructions তৈরি করা।
- [ ] EDA, training ও evaluation notebooks polish করা।
- [ ] Screenshots, limitations, data lineage এবং refresh guide README/docs-এ যোগ করা।
- [ ] **Acceptance:** another developer reproduce করতে পারে; dashboard-এ PII নেই।
- **Evidence / Commit:** _execution-এর সময় পূরণ করতে হবে_

---

# Phase 3 — Optional Stretch Goals

**Start condition:** Phase 0–2-এর MVP stable এবং user explicitly prioritise করেছেন।  
**Status:** `[ ] Deferred until review`

## Phase 3 Task 1 — RAG chatbot

- [ ] Eligibility/FAQ knowledge base-এর scope ও source approve করা।
- [ ] FAISS retrieval, citation/context boundary ও Gemini/Groq fallback design করা।
- [ ] Hallucination, medical disclaimer, prompt injection ও no-diagnosis tests যোগ করা।
- [ ] API quota ও credential setup user করবেন।
- **Acceptance:** retrieval-grounded answers, safe fallback, evaluation examples, no medical decision.

## Phase 3 Task 2 — Ranking A/B testing

- [ ] Feature flag, cohort assignment ও rollback design করা।
- [ ] Ranking quality, response rate, latency ও fairness metrics define করা।
- [ ] Synthetic data-তে A/B result production claim হিসেবে ব্যবহার না করা।
- **Acceptance:** reproducible comparison এবং safe rollback।

## Phase 3 Task 3 — Full-stack Docker Compose

- [ ] Laravel, FastAPI, DuckDB, queue/Redis এবং optional Metabase service definitions তৈরি করা।
- [ ] Secrets `.env`/Docker secrets boundary-তে রাখা।
- [ ] Windows developer setup ও health checks document করা।
- **Acceptance:** one-command local startup, health checks, no bundled secrets।

## Phase 3 Task 4 — Public dataset comparison

- [ ] WHO বা অন্য dataset-এর license/terms verify করা।
- [ ] Source citation ও schema mapping document করা।
- [ ] RoktoDut donor PII-এর সঙ্গে public data join না করা।
- **Acceptance:** reproducible comparative notebook এবং attribution।

---

## 4. User/third-party action checklist

এই কাজগুলো AI একা সম্পন্ন করতে পারবে না; প্রয়োজন হলে সংশ্লিষ্ট task-এ step-by-step guide দেওয়া হবে:

- [ ] Firebase/Groq/OAuth/Telegram/mail credential revoke ও rotate করা।
- [ ] Git history rewrite এবং force-push-এর explicit approval দেওয়া।
- [ ] GitHub Actions settings/permissions নিশ্চিত করা।
- [ ] GitHub Student Pack offer ও eligibility নিজে verify/claim করা।
- [ ] Power BI Desktop, Docker Desktop, বা Metabase environment install/authorize করা।
- [ ] Groq/Gemini/Azure/other external API quota বা billing policy confirm করা।
- [ ] Dashboard/production deployment target ও domain/hosting decision নেওয়া।

## 5. Commit and push ledger

প্রতিটি completed task-এর পরে এই table-এ entry যোগ করতে হবে:

| Task | Commit | Validation | Push status | Date |
|---|---|---|---|---|
| _এখনো কোনো task executed হয়নি_ | — | — | — | 2026-10-17 |

> Task board তৈরি করা planning deliverable; Phase 0 Task 1 execution শুরু হয়নি। User review ও explicit go-ahead না পাওয়া পর্যন্ত এই checklist-এর কোনো implementation checkbox tick করা যাবে না।
