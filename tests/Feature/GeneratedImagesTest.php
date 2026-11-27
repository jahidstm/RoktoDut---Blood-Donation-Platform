<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Both images carry a QR code; they used to need the imagick extension and failed without it. */
class GeneratedImagesTest extends TestCase
{
    use RefreshDatabase;

    private function assertRenders(string $url, string $file): void
    {
        File::delete($file);

        $this->get($url)->assertOk();

        $this->assertSame('image/png', mime_content_type($file));
        File::delete($file);
    }

    public function test_the_smart_card_pass_renders(): void
    {
        $user = User::factory()->create(['qr_token' => str_repeat('b', 64)]);

        $this->assertRenders('/verify/'.$user->qr_token.'/download-pass', storage_path("app/private/smart-cards/passes/pass-{$user->id}.png"));
    }

    public function test_the_certificate_preview_renders(): void
    {
        $donation = Donation::factory()->create();
        $donation->forceFill(['certificate_token' => str_repeat('c', 32)])->save();

        $this->assertRenders('/certificate/'.$donation->certificate_token.'/preview', storage_path("app/private/certificates/cert-{$donation->id}.png"));
    }
}
