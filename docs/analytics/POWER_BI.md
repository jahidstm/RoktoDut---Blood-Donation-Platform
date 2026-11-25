# Power BI and other external analysis

The admin dashboard (`/admin/analytics`) covers the everyday view. For deeper work in Power BI,
pandas or Excel, export a flat file:

```bash
php artisan analytics:export --source=real     # real requests only
php artisan analytics:export --source=demo     # synthetic requests only
php artisan analytics:export --source=all --path=analytics/all.csv
```

The file is written under `storage/app/` (git-ignored). One row per request, no personal data:
`request_id, created_at, blood_group, component, units, urgency, status, is_demo, division, district, hours_to_fulfil`.
Names, phones, addresses, notes, coordinates and requester ids are deliberately left out.

## Loading it in Power BI Desktop (free)

1. Get data, Text/CSV, pick the file. File origin: UTF-8 (the file has a BOM, so Bangla district names load correctly).
2. Check types: `created_at` as Date/Time, `units` and `hours_to_fulfil` as decimal numbers, `is_demo` as whole number.
3. Always add `is_demo` as a slicer, or filter the page on `is_demo = 0`. Never show demo and real in one chart without it.
4. Suggested measures (DAX):
   - `Requests = COUNTROWS(requests_flat)`
   - `Fulfilled = CALCULATE([Requests], requests_flat[status] = "fulfilled")`
   - `Fulfilment rate = DIVIDE([Fulfilled], [Requests])`. Filter out requests younger than 7 days first, otherwise recent weeks look worse than they are.
   - `Median hours to fulfil = MEDIAN(requests_flat[hours_to_fulfil])`

## What the dashboard numbers mean

| Number | Definition |
|---|---|
| Fulfilment rate | fulfilled / all requests older than 7 days. Younger requests are excluded because they have not had time to be fulfilled. |
| Median / p90 time | Hours from request creation to the first donor marked as fulfilled, over requests that have one. |
| Donor response rate | accepted / (accepted + declined + ignored) over donor response logs. `pending` and `moot` are excluded. |
| Demand vs donor share | Share of requested bags per blood group, next to each group's share of registered donors. Registered donors are a proxy: eligibility today is not considered. |

## Demo data

`php artisan demo:generate-demand` creates synthetic requests flagged `is_demo`. The group mix, district
weights, response rates and waiting times are the author's assumptions, not Bangladeshi statistics.
`php artisan demo:generate-demand --purge` removes only those rows.
