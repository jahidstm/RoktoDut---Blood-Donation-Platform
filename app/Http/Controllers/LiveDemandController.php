<?php

namespace App\Http\Controllers;

use App\Models\District;
use App\Services\SpatialAnalyticsService;
use Illuminate\View\View;

class LiveDemandController extends Controller
{
    public function index(SpatialAnalyticsService $service): View
    {
        // Map shapes are keyed by the English GeoJSON name; donor search needs database ids.
        $names = $service->getDistrictMap();   // Bangla => GeoJSON English
        $districtIds = District::query()->get(['id', 'name', 'division_id'])
            ->filter(fn (District $d) => isset($names[$d->name]))
            ->mapWithKeys(fn (District $d) => [$names[$d->name] => ['division_id' => $d->division_id, 'district_id' => $d->id]]);

        return view('maps.demand', [
            'public'      => true,
            'source'      => 'real',   // demo data is never shown to the public
            'autoDemo'    => false,
            'dateRange'   => 'all_time',
            'bloodGroup'  => '',
            'generatedAt' => now()->format('d M Y, h:i A'),
            'districtIds' => $districtIds->isEmpty() ? new \stdClass : $districtIds,
        ]);
    }
}
