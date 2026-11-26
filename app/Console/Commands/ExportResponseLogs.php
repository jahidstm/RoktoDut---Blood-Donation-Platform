<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Exports donor_response_logs as training data for the response model
 * (roktodut-ml-service/scripts/train_ranker.py).
 *
 * No personal data leaves: donors are replaced by a keyed hash (stable within this app,
 * meaningless outside it), and no names, phones, coordinates or blood groups are written.
 * The stored historical_response_rate is NOT exported: the trainer recomputes it point in
 * time from these rows, so it can never include the outcome it is meant to predict.
 */
class ExportResponseLogs extends Command
{
    protected $signature = 'ml:export-response-logs
                            {--path=ml/response_logs.csv : File under storage/app}
                            {--include-demo : Also export logs of synthetic demo requests}';

    protected $description = 'Export anonymised donor response logs for training the response model';

    public function handle(): int
    {
        $path = storage_path('app/'.ltrim((string) $this->option('path'), '/'));
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $q = DB::table('donor_response_logs as l')
            ->join('blood_requests as r', 'r.id', '=', 'l.request_id')
            ->orderBy('l.id')
            ->select('l.request_id', 'l.donor_id', 'l.notified_at', 'l.status', 'l.responded_at', 'l.distance_km',
                'l.days_since_last_donation', 'l.temporal_hour', 'l.is_weekend', 'r.urgency');

        if (! $this->option('include-demo')) {
            $q->where('r.is_demo', false);
        }

        $key = (string) config('app.key');
        $out = fopen($path, 'w');
        fputcsv($out, ['request_id', 'donor_key', 'notified_at', 'status', 'responded_at', 'distance_km',
            'days_since_last_donation', 'temporal_hour', 'is_weekend', 'urgency']);

        $counts = [];
        foreach ($q->cursor() as $row) {
            fputcsv($out, [
                $row->request_id,
                substr(hash_hmac('sha256', (string) $row->donor_id, $key), 0, 16),
                $this->iso($row->notified_at),
                $row->status,
                $this->iso($row->responded_at),
                $row->distance_km,
                $row->days_since_last_donation,
                $row->temporal_hour,
                (int) $row->is_weekend,
                $row->urgency,
            ]);
            $counts[$row->status] = ($counts[$row->status] ?? 0) + 1;
        }
        fclose($out);

        $total = array_sum($counts);
        $this->info("Wrote {$total} rows to {$path}");
        foreach ($counts as $status => $n) {
            $this->line("  {$status}: {$n}");
        }

        $labelled = ($counts['accepted'] ?? 0) + ($counts['declined'] ?? 0) + ($counts['ignored'] ?? 0);
        if ($labelled < 2000) {
            $this->warn("Only {$labelled} labelled rows. The trainer will run, but expect the promotion gate to say no.");
        }

        return self::SUCCESS;
    }

    private function iso(?string $value): string
    {
        return $value ? \Illuminate\Support\Carbon::parse($value)->utc()->toIso8601String() : '';
    }
}
