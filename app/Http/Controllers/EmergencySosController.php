<?php

namespace App\Http\Controllers;

use App\Enums\BloodComponentType;
use App\Enums\BloodGroup;
use App\Jobs\SosBloodRequestBroadcastJob;
use App\Models\BloodRequest;
use App\Support\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class EmergencySosController extends Controller
{
    // ── Constants ──────────────────────────────────────────────────────────
    private const LOCK_TTL_SECONDS  = 1800; // 30 minutes
    private const SOS_EXPIRE_HOURS  = 6;
    private const TOP_K             = 20;   // Strict: prevents SMS bankruptcy

    // ── POST /sos/trigger ──────────────────────────────────────────────────
    public function trigger(Request $request): JsonResponse
    {
        $user = $request->user();

        // SOS alerts many donors at once, so accounts already flagged for abuse get none.
        if ($user->is_shadowbanned || ($user->spam_strikes ?? 0) >= 3) {
            return response()->json(['success' => false, 'message' => 'আপনার অ্যাকাউন্ট থেকে SOS পাঠানো সাময়িকভাবে বন্ধ আছে। অনুগ্রহ করে সাধারণ রিকোয়েস্ট তৈরি করুন।'], 403);
        }

        // ── 1. 30-minute Cache Lock (per user) ─────────────────────────────
        $lockKey = "sos_lock:user:{$user->id}";
        $existing = Cache::get($lockKey);
        if ($existing !== null) {
            $remainingSeconds = Cache::getStore()->get($lockKey . ':ttl') ?? self::LOCK_TTL_SECONDS;
            $remainingMinutes = (int) ceil($remaining = (self::LOCK_TTL_SECONDS - (time() - (int) $existing)) / 60);

            // Recalculate remaining from stored timestamp
            $sentAt = (int) $existing;
            $elapsed = time() - $sentAt;
            $remaining = self::LOCK_TTL_SECONDS - $elapsed;
            $remainingMinutes = max(1, (int) ceil($remaining / 60));

            return response()->json([
                'success'  => false,
                'code'     => 'RATE_LIMITED',
                'message'  => "আপনি {$remainingMinutes} মিনিট আগে SOS পাঠিয়েছেন। অনুগ্রহ করে কিছুক্ষণ অপেক্ষা করুন।",
                'retry_after_minutes' => $remainingMinutes,
            ], 429);
        }

        // ── 2. Validate Input ──────────────────────────────────────────────
        $validated = $request->validate([
            'blood_group' => ['required', 'string', 'in:A+,A-,B+,B-,AB+,AB-,O+,O-'],
            'latitude'    => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'   => ['nullable', 'numeric', 'between:-180,180'],
        ], [
            'blood_group.required' => 'রক্তের গ্রুপ বাধ্যতামূলক।',
            'blood_group.in'       => 'অবৈধ রক্তের গ্রুপ।',
        ]);

        // Without a number nobody can call back; without a district the wrong donors get alerted.
        if (blank($user->phone) || ! $user->district_id || ! $user->division_id || ! $user->upazila_id) {
            return response()->json([
                'success' => false,
                'message' => 'SOS পাঠাতে প্রোফাইলে ফোন নম্বর, জেলা ও উপজেলা যোগ করুন।',
            ], 422);
        }

        // Daily caps, per account and per address.
        $daily = ['sos:day:user:' . $user->id => 3, 'sos:day:ip:' . $request->ip() => 6];
        foreach ($daily as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return response()->json(['success' => false, 'code' => 'RATE_LIMITED', 'message' => 'আজকের SOS সীমা শেষ। অনুগ্রহ করে সাধারণ রিকোয়েস্ট তৈরি করুন।'], 429);
            }
        }

        // ── 3. Location: GPS if sent, district always from the profile ─────
        $latitude   = isset($validated['latitude'])  ? (float) $validated['latitude']  : null;
        $longitude  = isset($validated['longitude']) ? (float) $validated['longitude'] : null;
        $divisionId = (int) $user->division_id;
        $districtId = (int) $user->district_id;
        $upazilaId  = (int) $user->upazila_id;

        // ── 4. Resolve contact number ──────────────────────────────────────
        $rawPhone        = (string) ($user->phone ?? '');
        $normalizedPhone = $rawPhone !== ''
            ? PhoneNormalizer::normalizeBdPhone($rawPhone)
            : '';

        // Atomic, so two simultaneous clicks cannot both get through.
        if (! Cache::add($lockKey, (string) time(), self::LOCK_TTL_SECONDS)) {
            return response()->json(['success' => false, 'code' => 'RATE_LIMITED', 'message' => 'আপনার SOS ইতিমধ্যে পাঠানো হয়েছে।'], 429);
        }
        foreach (array_keys($daily) as $key) {
            RateLimiter::hit($key, 86400);
        }

        // ── 5. Create the BloodRequest ─────────────────────────────────────
        $bloodRequest = BloodRequest::create([
            'requested_by'              => $user->id,
            'patient_name'              => $user->name,
            'blood_group'               => $validated['blood_group'],
            'component_type'            => BloodComponentType::WHOLE_BLOOD->value,
            'bags_needed'               => 1,
            'units_needed'              => 1,
            'division_id'               => $divisionId,
            'district_id'               => $districtId,
            'upazila_id'                => $upazilaId,
            'latitude'                  => $latitude,
            'longitude'                 => $longitude,
            'contact_name'              => $user->name,
            'contact_number'            => $rawPhone,
            'contact_number_normalized' => $normalizedPhone,
            'urgency'                   => 'emergency',
            'needed_at'                 => now()->addHours(self::SOS_EXPIRE_HOURS),
            'status'                    => 'pending',
            'is_super_critical'         => true,   // ← Full bypass of all filters
            'is_phone_hidden'           => false,
            'notes'                     => '🆘 SOS — জরুরি রক্ত প্রয়োজন! (Emergency SOS বাটন থেকে তৈরি)',
            'created_ip_hash'           => hash('sha256', ((string) $request->ip()) . '|' . ((string) config('app.key'))),
        ]);

        // ── 7. Dispatch SOS Job on dedicated high-priority queue ───────────
        SosBloodRequestBroadcastJob::dispatch(
            bloodRequestId: $bloodRequest->id,
            topK: self::TOP_K
        )->onQueue('sos')->afterCommit();

        return response()->json([
            'success'      => true,
            'message'      => '🆘 SOS পাঠানো হয়েছে! কাছের ডোনারদের এখনই নোটিফাই করা হচ্ছে।',
            'request_id'   => $bloodRequest->id,
            'redirect_url' => route('requests.show', $bloodRequest->id),
        ]);
    }
}
