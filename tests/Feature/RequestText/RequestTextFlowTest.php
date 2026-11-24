<?php

namespace Tests\Feature\RequestText;

use App\Models\BloodRequest;
use App\Models\User;
use App\Services\RequestText\NlpRequestIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SeedsBangladeshLocations;
use Tests\TestCase;

class RequestTextFlowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsBangladeshLocations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocationsAndHospitals();
        config([
            'services.roktodut_ml.base_url' => 'http://ml.test',
            'services.roktodut_ml.api_key' => 'k',
            'services.roktodut_ml.internal_secret' => 'internal-test-secret',
            'services.telegram.admin_chat_id' => null,
        ]);
    }

    /** What the Python parser would say about a message; tests stay offline. */
    private function fakeParser(array $fields): void
    {
        Http::fake(['ml.test/api/v2/parse-request' => Http::response(array_merge([
            'blood_group' => null, 'units' => null, 'component_type' => null, 'urgency' => null,
            'contact_number' => null, 'location_text' => null, 'source' => [], 'evidence' => [],
            'warnings' => [], 'llm_used' => false,
        ], $fields))]);
    }

    // ── Form endpoint ───────────────────────────────────────────────────────

    public function test_form_endpoint_combines_parser_fields_with_database_places(): void
    {
        $this->fakeParser(['blood_group' => 'O+', 'units' => 2, 'urgency' => 'urgent', 'contact_number' => '01700000001', 'source' => ['blood_group' => 'rules']]);

        $response = $this->postJson(route('requests.parse-text'), ['text' => 'আজ ঢাকা মেডিকেলে ২ ব্যাগ ও পজিটিভ, ০১৭০০০০০০০১']);

        $response->assertOk()->assertJsonPath('ok', true)
            ->assertJsonPath('fields.blood_group', 'O+')
            ->assertJsonPath('fields.units', 2)
            ->assertJsonPath('fields.hospital.name', 'Dhaka Medical College Hospital')
            ->assertJsonPath('fields.district_id', $this->districtId('ঢাকা'))
            ->assertJsonPath('fields.source.hospital', 'database')
            ->assertJsonPath('fields.needs_review', false);

        Http::assertSent(fn (HttpRequest $r) => $r['use_llm'] === false && $r->hasHeader('X-API-Key', 'k'));
    }

    public function test_values_the_form_cannot_accept_are_dropped(): void
    {
        $this->fakeParser(['blood_group' => 'A positive', 'units' => 99, 'urgency' => 'high', 'contact_number' => '12345', 'component_type' => 'blood']);

        $fields = $this->postJson(route('requests.parse-text'), ['text' => 'some text'])->json('fields');

        $this->assertSame([null, null, null, null, null], [$fields['blood_group'], $fields['units'], $fields['urgency'], $fields['contact_number'], $fields['component_type']]);
        $this->assertTrue($fields['needs_review']);
    }

    public function test_form_endpoint_degrades_gracefully_when_the_parser_is_down(): void
    {
        Http::fake(['ml.test/*' => Http::response('down', 503)]);

        $this->postJson(route('requests.parse-text'), ['text' => 'O+ 2 bag'])
            ->assertStatus(503)
            ->assertJsonPath('ok', false);
    }

    public function test_form_endpoint_validates_input(): void
    {
        Http::fake();
        $this->postJson(route('requests.parse-text'), ['text' => 'ab'])->assertStatus(422);
        $this->postJson(route('requests.parse-text'), ['text' => str_repeat('x', 2001)])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_request_form_shows_the_panel(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('requests.create'))
            ->assertOk()
            ->assertSee('data-testid="fill-from-message"', false)
            ->assertSee(json_encode(route('requests.parse-text')), false); // as written by @json()
    }

    // ── Telegram / API intake ───────────────────────────────────────────────

    public function test_intake_creates_a_pending_request_in_the_right_district(): void
    {
        $this->fakeParser(['blood_group' => 'B-', 'units' => 1, 'urgency' => 'emergency', 'contact_number' => '01800000003']);

        $out = app(NlpRequestIntake::class)->fromText('B- এখনই, চট্টগ্রাম মেডিকেল, 01800000003', null, '12345');

        $this->assertSame(NlpRequestIntake::CREATED, $out['status']);
        $req = $out['request']->fresh();
        $this->assertSame('nlp_pending', $req->status, 'never broadcast before an admin approves');
        $this->assertSame($this->districtId('চট্টগ্রাম'), $req->district_id);
        $this->assertNotSame(1, $req->district_id, 'the old code fell back to the first district');
        $this->assertSame('emergency', $req->urgency);
        $this->assertSame('01800000003', $req->contact_number);
        $this->assertSame('Chittagong Medical College Hospital', $req->hospital?->name);
        $this->assertStringContainsString('Telegram chat: 12345', $req->notes);
    }

    public function test_intake_asks_for_what_is_missing_instead_of_guessing(): void
    {
        $this->fakeParser(['blood_group' => null, 'units' => 2]);

        $out = app(NlpRequestIntake::class)->fromText('রক্ত লাগবে ২ ব্যাগ');

        $this->assertSame(NlpRequestIntake::NEEDS_INFO, $out['status']);
        $this->assertSame(['blood_group', 'district'], $out['missing']);
        $this->assertStringContainsString('রক্তের গ্রুপ', $out['reply']);
        $this->assertSame(0, BloodRequest::count());
    }

    public function test_intake_reports_an_unavailable_parser(): void
    {
        Http::fake(['ml.test/*' => Http::response('down', 500)]);

        $out = app(NlpRequestIntake::class)->fromText('O+ ঢাকা');

        $this->assertSame(NlpRequestIntake::UNAVAILABLE, $out['status']);
        $this->assertSame(0, BloodRequest::count());
    }

    public function test_internal_api_maps_old_urgency_values_and_requires_a_district(): void
    {
        $headers = ['X-Internal-Secret' => 'internal-test-secret'];
        $base = ['blood_group' => 'A+', 'location_text' => 'DMCH', 'units_needed' => 2];

        // No district: rejected instead of silently using the first district.
        $this->postJson(route('api.internal.requests.nlp.store'), $base + ['urgency' => 'high'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('district_id');

        $this->postJson(route('api.internal.requests.nlp.store'), $base + ['urgency' => 'high', 'district_id' => $this->districtId('ঢাকা')], $headers)
            ->assertCreated();

        $this->assertSame('urgent', BloodRequest::latest('id')->first()->urgency, '"high" from the old parser becomes "urgent"');
    }
}
