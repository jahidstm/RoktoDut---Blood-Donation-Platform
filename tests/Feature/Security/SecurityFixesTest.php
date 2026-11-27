<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\BloodRequest;
use App\Models\User;
use Database\Seeders\DemoAdminSeeder;
use Database\Seeders\DemoDonorSeeder;
use Database\Seeders\DemoDonorsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialUser;
use Tests\Concerns\SeedsBangladeshLocations;
use Tests\TestCase;

/** Regression tests for the issues found in the 2026-11 security audit. */
class SecurityFixesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsBangladeshLocations;

    private function donor(array $over = []): User
    {
        return User::factory()->create(array_merge(['role' => 'donor', 'phone' => '017'.random_int(10000000, 99999999)], $over));
    }

    private function member(): User
    {
        return User::factory()->create(['role' => 'recipient', 'is_donor' => false]);
    }

    private function captchaFor(User $donor, array $headers = []): int
    {
        $this->withHeaders($headers)->postJson("/donors/{$donor->id}/reveal/start")->assertOk();

        return session('captcha.answer');
    }

    private function reveal(User $donor, array $headers = [])
    {
        $answer = $this->captchaFor($donor, $headers);

        return $this->withHeaders($headers)->postJson("/donors/{$donor->id}/reveal/verify", ['captcha_answer' => $answer]);
    }

    // ── Phone reveal ────────────────────────────────────────────────

    public function test_guests_cannot_reveal_a_donor_phone(): void
    {
        $donor = $this->donor();

        $this->postJson("/donors/{$donor->id}/reveal/start")->assertUnauthorized();
        $this->postJson("/donors/{$donor->id}/reveal/verify", ['captcha_answer' => 1])->assertUnauthorized();
    }

    public function test_a_hidden_phone_is_never_returned(): void
    {
        $open = $this->donor();
        $hidden = $this->donor(['hide_phone' => true]);
        $this->actingAs($this->member());
        $answer = $this->captchaFor($open);

        $this->postJson("/donors/{$hidden->id}/reveal/verify", ['captcha_answer' => $answer])
            ->assertForbidden()
            ->assertJsonMissingPath('phone');
    }

    public function test_a_captcha_only_works_for_the_donor_it_was_issued_for(): void
    {
        $first = $this->donor();
        $second = $this->donor();
        $this->actingAs($this->member());
        $answer = $this->captchaFor($first);

        $this->postJson("/donors/{$second->id}/reveal/verify", ['captcha_answer' => $answer])->assertStatus(422);
    }

    public function test_changing_headers_does_not_reset_the_reveal_limit(): void
    {
        $this->actingAs($this->member());
        $statuses = [];
        for ($i = 0; $i < 5; $i++) {
            $statuses[] = $this->reveal($this->donor(), ['User-Agent' => "ua-$i", 'Accept-Language' => "l-$i"])->status();
        }

        $this->assertSame([200, 200, 200, 429, 429], $statuses);
    }

    public function test_many_accounts_on_one_address_hit_an_address_limit(): void
    {
        $got = 0;
        for ($a = 0; $a < 5; $a++) {
            $this->actingAs($this->member());
            for ($i = 0; $i < 3; $i++) {
                $got += $this->reveal($this->donor())->status() === 200 ? 1 : 0;
            }
        }

        $this->assertSame(10, $got);
    }

    public function test_a_forged_forwarded_for_header_is_ignored(): void
    {
        \Illuminate\Support\Facades\Route::get('/__ip', fn () => request()->ip());

        $this->get('/__ip', ['X-Forwarded-For' => '203.0.113.77'])->assertSee('127.0.0.1');
    }

    // ── Organisations ───────────────────────────────────────────────

    private function orgAdmin(string $status, bool $verified): User
    {
        $this->seedLocationsAndHospitals(withUpazilas: true);
        $d = DB::table('districts')->first();
        $admin = User::factory()->create(['role' => 'org_admin', 'is_donor' => false]);
        $orgId = DB::table('organizations')->insertGetId([
            'admin_id' => $admin->id, 'name' => 'Org', 'type' => 'ngo', 'status' => $status, 'is_verified' => $verified,
            'email' => 'org@example.test', 'phone' => '01600000000', 'address' => 'x',
            'division' => $d->division_id, 'district' => $d->id,
            'upazila' => DB::table('upazilas')->where('district_id', $d->id)->value('id'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin->forceFill(['organization_id' => $orgId])->save();

        return $admin->fresh();
    }

    public function test_a_pending_organisation_cannot_use_the_organisation_area(): void
    {
        $this->actingAs($this->orgAdmin('pending', false));

        $this->get('/org/dashboard')->assertForbidden();
        $this->post('/org/ambulances', [])->assertForbidden();
        $this->assertSame(0, DB::table('ambulances')->count());
    }

    public function test_a_rejected_organisation_stays_blocked(): void
    {
        $this->actingAs($this->orgAdmin('rejected', false))->get('/org/dashboard')->assertForbidden();
    }

    public function test_an_approved_organisation_can_use_the_organisation_area(): void
    {
        $this->actingAs($this->orgAdmin('approved', true))->get('/org/dashboard')->assertOk();
    }

    // ── Hidden requests and policy ──────────────────────────────────

    private function request(User $owner, array $over = []): BloodRequest
    {
        $this->seedLocationsAndHospitals(withUpazilas: true);
        $d = DB::table('districts')->first();

        return BloodRequest::create(array_merge([
            'requested_by' => $owner->id, 'blood_group' => 'O+', 'component_type' => 'whole_blood', 'bags_needed' => 1,
            'division_id' => $d->division_id, 'district_id' => $d->id,
            'upazila_id' => DB::table('upazilas')->where('district_id', $d->id)->value('id'),
            'contact_number' => '01555555555', 'urgency' => 'normal', 'needed_at' => now()->addDays(5),
            'status' => 'pending', 'patient_name' => 'p',
        ], $over));
    }

    public function test_a_hidden_request_is_only_visible_to_its_owner_and_admins(): void
    {
        $owner = $this->member();
        $req = $this->request($owner, ['is_hidden' => true]);

        $this->get("/requests/{$req->id}")->assertNotFound();
        $this->actingAs($this->member())->get("/requests/{$req->id}")->assertNotFound();
        $this->actingAs($owner)->get("/requests/{$req->id}")->assertOk();
        $this->actingAs(User::factory()->create(['role' => UserRole::ADMIN->value]))->get("/requests/{$req->id}")->assertOk();
    }

    public function test_only_the_owner_and_admins_see_accepted_donors(): void
    {
        $owner = $this->member();
        $req = $this->request($owner);

        $this->assertTrue($owner->can('viewAcceptedDonors', $req));
        $this->assertTrue(User::factory()->create(['role' => 'admin'])->can('viewAcceptedDonors', $req));
        $this->assertFalse(User::factory()->create(['role' => 'org_admin'])->can('viewAcceptedDonors', $req));
    }

    // ── Files, seeders, social login, headers ───────────────────────

    public function test_smart_card_images_are_not_written_to_the_public_disk(): void
    {
        $user = $this->donor(['qr_token' => str_repeat('a', 64)]);
        // The OG card has no QR code, so it renders without the imagick extension.
        $public = storage_path("app/public/smart-cards/og-images/og-{$user->id}.png");
        $private = storage_path("app/private/smart-cards/og-images/og-{$user->id}.png");
        $existedPublic = File::exists($public);
        File::delete($private);

        $this->get('/verify/'.$user->qr_token.'/og-image.png')->assertOk();

        $this->assertTrue(File::exists($private));
        $this->assertSame($existedPublic, File::exists($public));
        File::delete($private);
    }

    public function test_seeding_production_creates_no_demo_accounts(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        // DatabaseSeeder itself cannot run on SQLite (LocationSeeder uses MySQL statements).
        foreach ([DemoAdminSeeder::class, DemoDonorSeeder::class, DemoDonorsSeeder::class] as $seeder) {
            (new $seeder)->setContainer($this->app)->__invoke();
        }

        $this->assertSame(0, User::count());
    }

    public function test_the_first_production_admin_is_made_by_command(): void
    {
        $this->artisan('app:create-admin', ['email' => 'boss@example.test'])
            ->expectsQuestion('Password (at least 12 characters)', 'a-long-password-1')
            ->assertSuccessful();

        $this->assertTrue(User::where('email', 'boss@example.test')->first()->isAdmin());
    }

    public function test_google_login_takes_an_unverified_account_away_from_whoever_set_its_password(): void
    {
        $squatter = User::factory()->create([
            'email' => 'victim@example.test', 'email_verified_at' => null, 'password' => Hash::make('squatter-pass'),
        ]);
        Socialite::shouldReceive('driver->user')->andReturn(
            (new SocialUser)->map(['id' => 'g-1', 'name' => 'Victim', 'email' => 'victim@example.test'])
        );

        $this->get('/auth/google/callback')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($squatter);

        $fresh = $squatter->fresh();
        $this->assertFalse(Hash::check('squatter-pass', $fresh->password));
        $this->assertNotNull($fresh->email_verified_at);
    }

    public function test_pages_send_security_headers(): void
    {
        $this->get('/about')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'; object-src 'none'; base-uri 'self'");
    }
}
