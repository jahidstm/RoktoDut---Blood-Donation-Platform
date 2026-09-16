# RoktoDut (রক্তদূত)

RoktoDut is a Laravel-based blood-donor discovery platform for Bangladesh. It combines emergency request workflows, donor verification, privacy controls, notifications, maps, and an optional ML service.

> **Project status:** The repository contains an actively developed application and local development workflows. A public demo URL and production deployment status are not specified in the repository.

## Problem statement

During an emergency, patients and hospitals need to find suitable donors quickly without exposing donor contact details indiscriminately. RoktoDut is designed to rank available donors, coordinate requests, and confirm donation activity while limiting disclosure.

## Features and analysis scope

- No-login emergency donor search and availability/verification-based ranking.
- Emergency requests with response tracking and urgency ordering.
- Donation claim, recipient confirmation, cooldown, points, badges, and leaderboards.
- Organization workflows for hospitals and blood clubs.
- Masked phone numbers, challenge/rate-limit checks, QR tokens, retention controls, and audit trails.
- Leaflet/GeoJSON demand visualization, Firebase push notifications, Telegram alerts, and PWA support.
- Optional FastAPI/scikit-learn donor-ranking and request-parsing service under `roktodut-ml-service`.

These are repository capabilities; no outcome metrics or real-world clinical impact are reported here.

## Stack

Laravel 12 and PHP 8.2; MySQL; Redis; Sanctum; Reverb; Blade; Tailwind CSS; Alpine.js; Vite; Leaflet; Firebase; Telegram; and the optional FastAPI/scikit-learn service. Exact dependencies are defined in `composer.json`, `package.json`, and the ML service requirements file.

## Setup

Prerequisites: PHP 8.2+, Composer, Node.js 18+, MySQL 8+, and Redis.

```bash
composer install
npm install
copy .env.example .env          # Windows
# cp .env.example .env          # macOS/Linux
php artisan key:generate
php artisan migrate
npm run build
composer run dev
```

Configure database, Redis, Firebase, OAuth, notification, and application secrets in `.env`. The optional ML service is set up separately:

```bash
pip install -r roktodut-ml-service/requirements.txt
uvicorn roktodut-ml-service.main:app --host 127.0.0.1 --port 8001
```

On Windows, use the equivalent path separator for the ML requirements path. Useful repository scripts include `composer run test`, `composer run ops-check`, and `composer run smoke-check`.

## Limitations and safety

This repository is software for coordinating donor discovery, not medical advice or a substitute for hospital verification. Deployment credentials, external notification services, and populated application data are required for end-to-end operation. The repository has no dedicated `LICENSE` file; `composer.json` declares the application package as MIT, but license status for the complete repository is not otherwise specified.

## Dataset and citation

No external dataset or formal citation is identified in the repository README. Demo/seed data should not be treated as verified donor records.

## Team and contact

Jahid Hasan (lead backend/database/security) — [GitHub](https://github.com/jahidstm) · [LinkedIn](https://www.linkedin.com/in/jahidstm/). The existing project documentation also credits Md. Alif Khan, Nohzat Tabassum, and Mst. Moumita Rahman Meem.
