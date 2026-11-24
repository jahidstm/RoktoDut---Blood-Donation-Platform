<?php

namespace App\Services;

use App\Enums\UrgencyLevel;
use App\Models\BloodRequest;
use App\Models\User;
use App\Services\Matching\DonorCandidate;
use App\Services\Matching\DonorCandidateFinder;
use App\Services\Matching\ResponseRateService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Ranking used by CheckAndCascadeRequestJob (when its cache is empty) and the chronic
 * subscription dispatcher. Hard rules come from DonorCandidateFinder; the ML service
 * only orders donors who already passed them.
 */
class DonorMatchingService
{
    private const RANKING_PATH = '/api/v1/rank-donors';
    private const MAX_CANDIDATES = 50;
    private const DEFAULT_BATCH_SIZE = 5;
    private const MAX_DISTANCE_KM = 20.0;
    private const FATIGUE_EXCLUDE_FROM = 80;

    public function __construct(
        private readonly DonorCandidateFinder $finder,
        private readonly ResponseRateService $responseRates,
        private readonly EligibilityService $eligibility,
    ) {}

    /**
     * Backward-compatible method used in existing flows.
     */
    public function match(BloodRequest $request): EloquentCollection
    {
        $ids = $this->rankDonors($request, self::MAX_CANDIDATES)
            ->pluck('donor_id')
            ->values()
            ->all();

        if ($ids === []) {
            return new EloquentCollection();
        }

        $donorsById = User::query()->whereIn('id', $ids)->get()->keyBy('id');

        return new EloquentCollection(
            collect($ids)->map(fn (int $id) => $donorsById->get($id))->filter()->values()->all()
        );
    }

    /**
     * Eligible, available, compatible donors with the features the ML model expects.
     * Identical blood group first, then nearest.
     */
    public function generateCandidateList(BloodRequest $request, int $limit = self::MAX_CANDIDATES): Collection
    {
        $limit = max(1, min($limit, self::MAX_CANDIDATES));
        $now = now();

        $candidates = $this->finder->find(
            $request,
            radiusKm: self::MAX_DISTANCE_KM,
            limit: $limit,
            excludeDonorIds: $this->fatiguedDonorIds($request),
        );

        if ($candidates->isEmpty()) {
            return collect();
        }

        $rates = $this->responseRates->forDonors($candidates->map(fn (DonorCandidate $c) => $c->user->id)->all());

        return $candidates->map(function (DonorCandidate $c) use ($rates, $now, $request): array {
            return [
                'donor_id' => (int) $c->user->id,
                'distance_km' => $c->distanceKm,
                'days_since_last_donation' => $this->eligibility->daysSinceLastDonation($c->user, $request->component_type, $now),
                'temporal_hour' => (int) $now->hour,
                'is_weekend' => $now->isWeekend(),
                'historical_response_rate' => $rates[$c->user->id],
                'is_identical_group' => $c->isIdenticalGroup,
            ];
        })->values();
    }

    /**
     * Ranked candidates for dispatch. Order:
     *  1. identical blood group before compatible-but-different (a hard preference, not learned);
     *  2. within a group tier, the ML score when it can be used, else distance / response rate.
     */
    public function rankDonors(BloodRequest $request, int $limit = self::DEFAULT_BATCH_SIZE): Collection
    {
        $limit = max(1, $limit);
        $candidates = $this->generateCandidateList($request, self::MAX_CANDIDATES);

        if ($candidates->isEmpty()) {
            return collect();
        }

        // Without request coordinates distance is unknown. The model was trained on
        // 0.2–35 km, so sending a placeholder like 9999 would be out-of-distribution.
        $hasDistance = $candidates->every(fn (array $c) => $c['distance_km'] !== null);

        $ranked = $hasDistance ? $this->rankWithModel($request, $candidates) : null;

        if ($ranked === null) {
            $ranked = $candidates
                ->sortBy([
                    fn ($a, $b) => $b['is_identical_group'] <=> $a['is_identical_group'],
                    fn ($a, $b) => ($a['distance_km'] ?? INF) <=> ($b['distance_km'] ?? INF),
                    fn ($a, $b) => $b['historical_response_rate'] <=> $a['historical_response_rate'],
                ])
                ->map(fn (array $c) => $c + ['probability_score' => 0.0]);
        }

        return $ranked
            ->take($limit)
            ->values()
            ->map(fn (array $row, int $i) => array_merge($row, ['rank' => $i + 1]));
    }

    /**
     * @return Collection<int, array>|null null when the ML service is unavailable or answers badly
     */
    private function rankWithModel(BloodRequest $request, Collection $candidates): ?Collection
    {
        $modelFields = ['donor_id', 'distance_km', 'days_since_last_donation', 'temporal_hour', 'is_weekend', 'historical_response_rate'];

        try {
            $response = Http::timeout(2)
                ->withHeaders(['X-API-Key' => config('services.roktodut_ml.api_key')])
                ->post(rtrim((string) config('services.roktodut_ml.base_url'), '/') . self::RANKING_PATH, [
                    'request_details' => [
                        'request_id' => $request->id,
                        'blood_group' => (string) ($request->blood_group?->value ?? $request->blood_group),
                        'urgency' => $this->urgencyValue($request),
                        'units_needed' => (int) ($request->units_needed ?? $request->bags_needed ?? 1),
                    ],
                    'candidate_donors' => $candidates->map(fn (array $c) => array_intersect_key($c, array_flip($modelFields)))->values()->all(),
                ]);

            $response->throw();

            $scores = collect($response->json())
                ->filter(fn ($row) => is_array($row) && isset($row['donor_id']))
                ->mapWithKeys(fn (array $row) => [(int) $row['donor_id'] => (float) ($row['probability_score'] ?? 0.0)]);

            if ($scores->isEmpty()) {
                throw new \RuntimeException('Empty ranking response from AI service.');
            }

            return $candidates
                ->filter(fn (array $c) => $scores->has($c['donor_id']))
                ->map(fn (array $c) => $c + ['probability_score' => $scores[$c['donor_id']]])
                ->sortBy([
                    fn ($a, $b) => $b['is_identical_group'] <=> $a['is_identical_group'],
                    fn ($a, $b) => $b['probability_score'] <=> $a['probability_score'],
                ])
                ->values();
        } catch (\Throwable $e) {
            Log::warning('AI donor ranking failed; using rule-based order.', [
                'blood_request_id' => $request->id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Donors with a fatigue score above the threshold are skipped, except for emergencies.
     *
     * @return array<int>
     */
    private function fatiguedDonorIds(BloodRequest $request): array
    {
        // Stored values are lowercase ('emergency'). The old code compared against
        // 'EMERGENCY', so the emergency exemption never applied.
        if ($this->urgencyValue($request) === UrgencyLevel::EMERGENCY->value) {
            return [];
        }

        try {
            return array_map('intval', Redis::zrangebyscore('donor_fatigue', self::FATIGUE_EXCLUDE_FROM, 100) ?: []);
        } catch (\Throwable $e) {
            Log::warning('Redis unavailable for DFI fetching: ' . $e->getMessage());

            return [];
        }
    }

    private function urgencyValue(BloodRequest $request): string
    {
        $urgency = $request->urgency instanceof UrgencyLevel ? $request->urgency->value : (string) $request->urgency;

        return strtolower($urgency);
    }
}
