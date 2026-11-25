<?php

namespace Tests\Feature\Risk;

use App\Enums\UserRole;
use App\Models\BloodRequest;
use App\Models\RequestRiskFlag;
use App\Models\User;
use App\Services\Risk\RequestRiskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsBangladeshLocations;
use Tests\TestCase;

class RequestRiskTest extends TestCase
{
    use RefreshDatabase;
    use SeedsBangladeshLocations;

    private int $districtId;
    private int $divisionId;
    private int $upazilaId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocationsAndHospitals(withUpazilas: true);
        $d = DB::table('districts')->where('name', 'ঢাকা')->first();
        $this->districtId = $d->id;
        $this->divisionId = $d->division_id;
        $this->upazilaId = DB::table('upazilas')->where('district_id', $d->id)->value('id');
    }

    private function req(array $over = []): BloodRequest
    {
        return BloodRequest::create(array_merge([
            'blood_group' => 'O+',
            'component_type' => 'whole_blood',
            'units_needed' => 1,
            'bags_needed' => 1,
            'division_id' => $this->divisionId,
            'district_id' => $this->districtId,
            'upazila_id' => $this->upazilaId,
            'contact_number' => '01712345098',
            'contact_number_normalized' => '8801712345098',
            'urgency' => 'normal',
            'status' => 'pending',
        ], $over));
    }

    private function codes(BloodRequest $r): array
    {
        return array_column(app(RequestRiskService::class)->assess($r)['signals'], 'code');
    }

    private function report(BloodRequest $r, string $reason, ?User $by = null): User
    {
        $by ??= User::factory()->create();
        DB::table('blood_request_reports')->insert([
            'user_id' => $by->id, 'blood_request_id' => $r->id, 'reason' => $reason,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $by;
    }

    public function test_an_ordinary_request_raises_no_signal_and_no_flag(): void
    {
        $r = $this->req(['notes' => 'Patient admitted at DMCH, needs blood tonight']);

        $this->assertSame([], $this->codes($r));
        $this->assertSame(0, RequestRiskFlag::count());
    }

    public function test_phone_burst_counts_requests_inside_the_window_only(): void
    {
        $old = $this->req();
        DB::table('blood_requests')->where('id', $old->id)->update(['created_at' => now()->subDays(3)]);
        $this->req();
        $this->assertNotContains('phone_burst', $this->codes($this->req()), 'two recent + one old is under the limit');

        $this->assertContains('phone_burst', $this->codes($this->req()), 'three in 24h');
    }

    public function test_ip_burst_needs_several_different_phones_not_just_several_requests(): void
    {
        foreach (range(1, 4) as $i) {
            $r = $this->req(['created_ip_hash' => 'same', 'contact_number_normalized' => '880171000000'.$i, 'contact_number' => '0171234509'.$i]);
        }
        $this->assertContains('ip_burst', $this->codes($r));

        // One family, one phone, one connection: not suspicious on its own.
        foreach (range(1, 4) as $i) {
            $r = $this->req(['created_ip_hash' => 'family', 'contact_number_normalized' => '8801999999990']);
        }
        $this->assertNotContains('ip_burst', $this->codes($r));
    }

    public function test_duplicate_text_ignores_short_text(): void
    {
        $this->req(['notes' => 'urgent']);
        $this->assertNotContains('duplicate_text', $this->codes($this->req(['notes' => 'urgent'])));

        $text = 'Please help, my father is in ICU and needs two bags today';
        $this->req(['notes' => $text, 'contact_number_normalized' => '8801700000001']);
        $this->assertContains('duplicate_text', $this->codes($this->req(['notes' => '  '.strtoupper($text), 'contact_number_normalized' => '8801700000002'])));
    }

    public function test_reports_count_distinct_reporters_and_escalate(): void
    {
        $r = $this->req();
        $one = $this->report($r, 'spam');
        $this->assertNotContains('spam_reports', $this->codes($r));

        $this->report($r, 'fake_number');
        $this->assertSame(1, $this->weight($r, 'spam_reports'));

        $this->report($r, 'abusive');
        $this->assertSame(3, $this->weight($r, 'spam_reports'));
    }

    private function weight(BloodRequest $r, string $code): int
    {
        foreach (app(RequestRiskService::class)->assess($r)['signals'] as $s) {
            if ($s['code'] === $code) {
                return $s['weight'];
            }
        }

        return 0;
    }

    public function test_other_signals(): void
    {
        $this->assertContains('implausible_units', $this->codes($this->req(['units_needed' => 9, 'bags_needed' => 9])));
        $this->assertContains('phone_pattern', $this->codes($this->req(['contact_number' => '01711111111'])));
        $this->assertContains('phone_pattern', $this->codes($this->req(['contact_number' => '01712345678'])));

        $striker = User::factory()->create(['spam_strikes' => 1]);
        $this->assertContains('requester_strikes', $this->codes($this->req(['requested_by' => $striker->id])));
    }

    public function test_flag_is_created_only_at_threshold_and_never_hides_the_request(): void
    {
        // phone pattern (1) + implausible units (1) = 2: below the bar of 3.
        $below = $this->req(['contact_number' => '01711111111', 'units_needed' => 9, 'bags_needed' => 9]);
        $this->assertNull(RequestRiskFlag::where('blood_request_id', $below->id)->first());

        // Add a duplicated text for +2 = 4.
        $text = 'Same long text used again and again for many requests';
        $this->req(['notes' => $text, 'contact_number_normalized' => '8801700000009']);
        $flagged = $this->req(['contact_number' => '01711111111', 'units_needed' => 9, 'bags_needed' => 9, 'notes' => $text, 'contact_number_normalized' => '8801700000008']);

        $flag = RequestRiskFlag::where('blood_request_id', $flagged->id)->first();
        $this->assertNotNull($flag);
        $this->assertSame(4, $flag->score, json_encode($flag->signals, JSON_UNESCAPED_UNICODE));
        $this->assertSame(RequestRiskFlag::OPEN, $flag->status);
        $this->assertNotEmpty($flag->signals[0]['reason']);
        $this->assertFalse((bool) $flagged->fresh()->is_hidden);
        $this->assertSame('pending', $flagged->fresh()->status);
    }

    public function test_drafts_are_not_scored(): void
    {
        $this->req(['status' => 'nlp_pending', 'units_needed' => 9, 'contact_number' => '01711111111', 'notes' => null]);
        $this->assertSame(0, RequestRiskFlag::count());
    }

    public function test_reevaluating_is_idempotent_and_dismissals_stick_unless_things_get_worse(): void
    {
        $r = $this->req(['contact_number' => '01711111111']);
        foreach (range(1, 3) as $i) {
            $this->report($r, 'spam');
        }
        $svc = app(RequestRiskService::class);
        $flag = $svc->evaluate($r);
        $this->assertSame(4, $flag->score);
        $svc->evaluate($r);
        $this->assertSame(1, RequestRiskFlag::count());

        $flag->update(['status' => RequestRiskFlag::DISMISSED, 'reviewed_score' => 4]);
        $this->assertSame(RequestRiskFlag::DISMISSED, $svc->evaluate($r)->status, 'no change: stays dismissed');

        $r->update(['units_needed' => 9, 'bags_needed' => 9]); // +1: not enough to reopen
        $this->assertSame(RequestRiskFlag::DISMISSED, $svc->evaluate($r)->status);

        $this->report($r, 'already_managed');
        $this->report($r, 'already_managed');
        $this->report($r, 'already_managed'); // +3: score 8, well above 4 + 2
        $this->assertSame(RequestRiskFlag::OPEN, $svc->evaluate($r)->status);
    }

    public function test_reporting_works_and_three_reports_do_not_hide_or_close_a_request(): void
    {
        $r = $this->req(['contact_number' => '01712345098']);

        foreach (range(1, 3) as $i) {
            $this->actingAs(User::factory()->create())
                ->post("/requests/{$r->id}/report", ['reason' => 'spam'])
                ->assertSessionHas('success');
        }

        $r->refresh();
        $this->assertSame(3, $r->spam_report_count);
        $this->assertFalse((bool) $r->is_hidden, 'reports alone must not hide a request');
        $this->assertSame('pending', $r->status);
        $this->assertSame(RequestRiskFlag::OPEN, RequestRiskFlag::where('blood_request_id', $r->id)->value('status'));
    }

    public function test_requester_cannot_report_own_request_or_report_twice(): void
    {
        $owner = User::factory()->create();
        $r = $this->req(['requested_by' => $owner->id]);

        $this->actingAs($owner)->post("/requests/{$r->id}/report", ['reason' => 'spam'])->assertSessionHas('error');

        $other = User::factory()->create();
        $this->actingAs($other)->post("/requests/{$r->id}/report", ['reason' => 'spam'])->assertSessionHas('success');
        $this->actingAs($other)->post("/requests/{$r->id}/report", ['reason' => 'spam'])->assertSessionHas('error');
        $this->assertSame(1, $r->fresh()->spam_report_count);
    }

    private function openFlag(): RequestRiskFlag
    {
        $r = $this->req();

        return RequestRiskFlag::create([
            'blood_request_id' => $r->id, 'score' => 4, 'status' => 'open',
            'signals' => [['code' => 'phone_burst', 'weight' => 2, 'reason' => 'একই ফোন নম্বর থেকে ২৪ ঘণ্টায় ৩টি অনুরোধ।']],
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN->value]);
    }

    public function test_admin_queue_shows_reasons_and_blocks_non_admins(): void
    {
        $flag = $this->openFlag();

        $this->actingAs($this->admin())->get('/admin/risk-queue')
            ->assertOk()
            ->assertSee('একই ফোন নম্বর থেকে', false)
            ->assertSee('কিছুই নিজে থেকে লুকানো বা বন্ধ হয়নি', false);

        $this->actingAs(User::factory()->create(['role' => UserRole::DONOR->value]))
            ->get('/admin/risk-queue')->assertStatus(403);
    }

    public function test_dismiss_changes_nothing_on_the_request_and_confirm_hides_it(): void
    {
        $admin = $this->admin();

        $a = $this->openFlag();
        $this->actingAs($admin)->post("/admin/risk-queue/{$a->id}/dismiss", ['note' => 'real patient'])->assertSessionHas('success');
        $a->refresh();
        $this->assertSame(RequestRiskFlag::DISMISSED, $a->status);
        $this->assertSame($admin->id, $a->reviewed_by);
        $this->assertSame(4, $a->reviewed_score);
        $this->assertFalse((bool) $a->bloodRequest->is_hidden);

        $b = $this->openFlag();
        $this->actingAs($admin)->post("/admin/risk-queue/{$b->id}/confirm")->assertSessionHas('success');
        $this->assertSame(RequestRiskFlag::CONFIRMED, $b->fresh()->status);
        $this->assertTrue((bool) $b->bloodRequest->fresh()->is_hidden);
    }
}
