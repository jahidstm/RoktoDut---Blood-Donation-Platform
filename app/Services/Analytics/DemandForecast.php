<?php

namespace App\Services\Analytics;

/**
 * Reads the forecast written by roktodut-ml-service/scripts/forecast_demand.py.
 *
 * The forecast is produced offline (weekly is enough for planning blood camps), so the web
 * request never waits for a model. A missing, unreadable or stale file is reported as such
 * instead of being shown as if it were current.
 */
class DemandForecast
{
    public const METHOD_LABELS = [
        'lightgbm' => 'LightGBM মডেল',
        'ets' => 'Exponential smoothing মডেল',
        'naive' => 'গত সপ্তাহের সংখ্যা',
        'ma4' => 'গত ৪ সপ্তাহের গড়',
        'ma13' => 'গত ১৩ সপ্তাহের গড়',
        'seasonal_naive' => 'গত বছরের একই সপ্তাহ',
    ];

    /** @return array<string, mixed>|null null when there is no usable forecast file */
    public function latest(): ?array
    {
        $path = (string) config('analytics.forecast_path');
        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! isset($data['status'], $data['data_source'])) {
            return null;
        }

        $data['is_simulated'] = $data['data_source'] !== 'real';
        $data['method_label'] = self::METHOD_LABELS[$data['method_used'] ?? ''] ?? ($data['method_used'] ?? '');
        $data['is_model'] = in_array($data['method_used'] ?? '', ['lightgbm', 'ets'], true);

        // Forecast weeks that have already passed mean nobody re-ran the script.
        $first = $data['national']['forecast'][0]['week'] ?? null;
        $data['is_stale'] = $first !== null
            && now()->startOfWeek()->greaterThan(\Illuminate\Support\Carbon::parse($first)->addWeeks((int) ($data['horizon_weeks'] ?? 4)));

        return $data;
    }
}
