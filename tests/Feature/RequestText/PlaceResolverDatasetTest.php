<?php

namespace Tests\Feature\RequestText;

use App\Services\RequestText\PlaceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsBangladeshLocations;
use Tests\TestCase;

/**
 * Scores the hospital/district resolver on the labelled sets in
 * roktodut-ml-service/data/nlp/ (see that folder's README for the caveats).
 *
 * The dev floor is a regression guard, not a quality claim. Run with
 *   php artisan test --filter PlaceResolverDatasetTest
 * to see the per-split numbers and misses.
 */
class PlaceResolverDatasetTest extends TestCase
{
    use RefreshDatabase;
    use SeedsBangladeshLocations;

    /** Hospital accuracy on dev at the time this was written was well above this. */
    private const DEV_FLOOR = 0.85;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocationsAndHospitals(withUpazilas: false);
    }

    /** @return array{hospital: float, district: float, misses: list<string>} */
    private function score(string $split): array
    {
        $path = base_path("roktodut-ml-service/data/nlp/{$split}.jsonl");
        $rows = array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", file_get_contents($path))));
        $resolver = app(PlaceResolver::class);

        $ok = ['hospital' => 0, 'district' => 0];
        $misses = [];
        foreach ($rows as $row) {
            $r = $resolver->resolve($row['text']);
            $pred = ['hospital' => $r['hospital']?->name, 'district' => $r['district']?->name];
            foreach (['hospital', 'district'] as $f) {
                if ($pred[$f] === $row['labels'][$f]) {
                    $ok[$f]++;
                } else {
                    $misses[] = sprintf('%s %s: gold=%s pred=%s', $row['id'], $f, $row['labels'][$f] ?? 'null', $pred[$f] ?? 'null');
                }
            }
        }

        $n = count($rows);

        return ['hospital' => $ok['hospital'] / $n, 'district' => $ok['district'] / $n, 'misses' => $misses];
    }

    public function test_dev_and_test_scores(): void
    {
        $dev = $this->score('dev');
        $test = $this->score('test');

        fwrite(STDERR, sprintf(
            "\n[places] dev  hospital=%.3f district=%.3f\n[places] test hospital=%.3f district=%.3f\n%s\n",
            $dev['hospital'], $dev['district'], $test['hospital'], $test['district'],
            implode("\n", array_map(fn ($m) => "  test miss: {$m}", $test['misses'])),
        ));

        $this->assertGreaterThanOrEqual(self::DEV_FLOOR, $dev['hospital'], implode("\n", $dev['misses']));
        $this->assertGreaterThanOrEqual(self::DEV_FLOOR, $dev['district'], implode("\n", $dev['misses']));
    }
}
