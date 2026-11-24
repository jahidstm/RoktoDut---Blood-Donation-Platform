<?php

namespace App\Services\RequestText;

use App\Models\BloodRequest;
use App\Models\District;
use App\Models\Upazila;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Log;

/**
 * Creates a blood request from a free-text message (Telegram bot, internal API).
 *
 * Requests made this way are stored as `nlp_pending` and are never broadcast until an
 * admin approves them. Two fields are required because guessing them is dangerous:
 * the blood group, and the district (the old code silently used the FIRST district in
 * the database, which sent requests to the wrong part of the country).
 */
class NlpRequestIntake
{
    public const CREATED = 'created';
    public const NEEDS_INFO = 'needs_info';
    public const UNAVAILABLE = 'unavailable';

    public function __construct(private readonly RequestTextAnalyzer $analyzer) {}

    /**
     * @return array{status: string, request?: BloodRequest, missing?: list<string>, reply: string}
     */
    public function fromText(string $text, ?User $requester = null, ?string $telegramChatId = null): array
    {
        try {
            $fields = $this->analyzer->analyze($text, (bool) config('services.roktodut_ml.telegram_use_llm', false));
        } catch (ParserUnavailable) {
            return [
                'status' => self::UNAVAILABLE,
                'reply' => 'দুঃখিত, এই মুহূর্তে আপনার বার্তাটি প্রক্রিয়া করা যাচ্ছে না। একটু পরে আবার চেষ্টা করুন।',
            ];
        }

        $missing = [];
        if ($fields['blood_group'] === null) {
            $missing[] = 'blood_group';
        }
        if ($fields['district_id'] === null) {
            $missing[] = 'district';
        }

        if ($missing !== []) {
            $asks = [
                'blood_group' => 'রক্তের গ্রুপ (যেমন A+, O-, এবি নেগেটিভ)',
                'district' => 'জেলা বা হাসপাতালের নাম',
            ];

            return [
                'status' => self::NEEDS_INFO,
                'missing' => $missing,
                'reply' => "আপনার বার্তা থেকে নিশ্চিতভাবে বোঝা যায়নি: "
                    . implode(' এবং ', array_map(fn ($m) => $asks[$m], $missing))
                    . "। অনুগ্রহ করে এগুলো উল্লেখ করে পুরো অনুরোধটি আবার পাঠান।",
            ];
        }

        $request = $this->createPending($fields, $text, $requester, $telegramChatId);

        return [
            'status' => self::CREATED,
            'request' => $request,
            'reply' => "আপনার রিকোয়েস্টটি (#{$request->id}) পাওয়া গেছে। অ্যাডমিন যাচাই করার পর ডোনারদের জানানো হবে।",
        ];
    }

    /**
     * @param array{blood_group: string, district_id: int, units?: ?int, urgency?: ?string,
     *              component_type?: ?string, contact_number?: ?string, hospital?: ?array,
     *              location_text?: ?string, confidence?: ?float} $fields
     */
    public function createPending(array $fields, string $rawText, ?User $requester = null, ?string $telegramChatId = null): BloodRequest
    {
        $district = District::query()->findOrFail($fields['district_id']);
        // The message rarely names the upazila. Dispatch uses the district (and coordinates
        // when present), so the district's first upazila is a placeholder the admin can edit.
        $upazilaId = Upazila::query()->where('district_id', $district->id)->orderBy('id')->value('id');

        $units = (int) ($fields['units'] ?? 1) ?: 1;
        $phone = $fields['contact_number'] ?? $requester?->phone ?? (string) config('services.roktodut_ml.nlp_placeholder_phone');

        $request = BloodRequest::create([
            'requested_by' => $requester?->id,
            'patient_name' => null,
            'blood_group' => $fields['blood_group'],
            'component_type' => $fields['component_type'] ?? 'whole_blood',
            'location_text' => $fields['location_text'] ?? ($fields['hospital']['display'] ?? null),
            'hospital_id' => $fields['hospital']['id'] ?? null,
            'bags_needed' => $units,
            'units_needed' => $units,
            'division_id' => $district->division_id,
            'district_id' => $district->id,
            'upazila_id' => $upazilaId,
            'address' => $fields['location_text'] ?? null,
            'contact_name' => 'NLP Intake',
            'contact_number' => $phone,
            // The parser never guesses "normal"; an unstated urgency is stored as normal
            // because the request waits for an admin anyway.
            'urgency' => $fields['urgency'] ?? 'normal',
            'status' => 'nlp_pending',
            'ml_confidence_score' => $fields['confidence'] ?? null,
            'notes' => $this->notes($rawText, $telegramChatId, $upazilaId !== null),
        ]);

        $this->notifyAdmin($request);

        return $request;
    }

    private function notes(string $rawText, ?string $telegramChatId, bool $upazilaGuessed): string
    {
        $lines = ['[NLP intake]'];
        if ($telegramChatId) {
            $lines[] = "Telegram chat: {$telegramChatId}";
        }
        if ($upazilaGuessed) {
            $lines[] = 'Upazila was not in the message; placeholder set, please check.';
        }
        $lines[] = 'Original message:';
        $lines[] = mb_substr(trim($rawText), 0, 1000);

        return implode("\n", $lines);
    }

    private function notifyAdmin(BloodRequest $request): void
    {
        $adminChatId = config('services.telegram.admin_chat_id');
        if (!$adminChatId) {
            return;
        }

        $group = $request->blood_group?->value ?? (string) $request->blood_group;
        $msg = "🤖 <b>New NLP Request Needs Approval</b>\n\n"
            . "<b>ID:</b> {$request->id}\n"
            . "<b>Group:</b> {$group}\n"
            . "<b>Location:</b> " . e((string) $request->location_text) . "\n"
            . "<b>Urgency:</b> {$request->urgency}\n\n"
            . "<i>Reply with </i><code>/approve {$request->id}</code><i> to publish this request.</i>";

        try {
            app(TelegramService::class)->send($adminChatId, $msg);
        } catch (\Throwable $e) {
            Log::error('Failed to notify admin of NLP request: ' . $e->getMessage());
        }
    }
}
