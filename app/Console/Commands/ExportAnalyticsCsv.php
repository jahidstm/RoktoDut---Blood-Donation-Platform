<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One row per blood request, no personal data, for Power BI / pandas / Excel.
 * Dropped on purpose: patient and contact names, phone numbers, addresses, notes,
 * coordinates, requester id. Kept: only what a demand chart needs.
 */
class ExportAnalyticsCsv extends Command
{
    protected $signature = 'analytics:export
                            {--source=real : real, demo or all}
                            {--path=analytics/requests_flat.csv : File under storage/app}';

    protected $description = 'Export an anonymised, one-row-per-request CSV for external analysis';

    public function handle(): int
    {
        $source = $this->option('source');
        if (! in_array($source, ['real', 'demo', 'all'], true)) {
            $this->error('--source must be real, demo or all');

            return self::INVALID;
        }

        $path = storage_path('app/'.ltrim((string) $this->option('path'), '/'));
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $first = DB::table('blood_request_responses')
            ->whereNotNull('fulfilled_at')
            ->selectRaw('blood_request_id, MIN(fulfilled_at) as first_fulfilled_at')
            ->groupBy('blood_request_id');

        $q = DB::table('blood_requests as r')
            ->leftJoin('districts as d', 'd.id', '=', 'r.district_id')
            ->leftJoin('divisions as v', 'v.id', '=', 'r.division_id')
            ->leftJoinSub($first, 'f', 'f.blood_request_id', '=', 'r.id')
            ->whereIn('r.status', ['pending', 'approved', 'fulfilled', 'rejected'])
            ->orderBy('r.id')
            ->select('r.id', 'r.created_at', 'r.blood_group', 'r.component_type', 'r.units_needed', 'r.urgency',
                'r.status', 'r.is_demo', 'v.name as division', 'd.name as district', 'f.first_fulfilled_at');

        if ($source !== 'all') {
            $q->where('r.is_demo', $source === 'demo');
        }

        $out = fopen($path, 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel and Power BI read the Bangla names as UTF-8
        fputcsv($out, ['request_id', 'created_at', 'blood_group', 'component', 'units', 'urgency', 'status',
            'is_demo', 'division', 'district', 'hours_to_fulfil']);

        $rows = 0;
        foreach ($q->cursor() as $r) {
            $hours = $r->first_fulfilled_at
                ? round(max(0, strtotime($r->first_fulfilled_at) - strtotime($r->created_at)) / 3600, 2)
                : '';
            fputcsv($out, [$r->id, $r->created_at, $r->blood_group, $r->component_type, $r->units_needed,
                $r->urgency, $r->status, (int) $r->is_demo, $r->division, $r->district, $hours]);
            $rows++;
        }
        fclose($out);

        $this->info("Wrote {$rows} rows to {$path}");

        return self::SUCCESS;
    }
}
