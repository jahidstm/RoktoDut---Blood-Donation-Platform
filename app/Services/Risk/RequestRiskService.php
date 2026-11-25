<?php

namespace App\Services\Risk;

use App\Models\BloodRequest;
use App\Models\RequestRiskFlag;
use Illuminate\Support\Facades\DB;

/**
 * Rule-based triage of blood requests. It produces a score and, for every point of that
 * score, a reason an admin can read. It never changes the request itself: flagging puts
 * a row in the review queue, and only a human decision hides anything.
 *
 * No ML on purpose: there are no labelled fake requests yet, and rules can be explained.
 * Once admins have dismissed or confirmed enough flags, those decisions are the labels a
 * later model (for example an Isolation Forest used only to order the queue) would need.
 */
class RequestRiskService
{
    /** @return array{score: int, signals: list<array{code: string, weight: int, reason: string}>} */
    public function assess(BloodRequest $request): array
    {
        $signals = array_values(array_filter([
            $this->phoneBurst($request),
            $this->ipBurst($request),
            $this->duplicateText($request),
            $this->spamReports($request),
            $this->managedReports($request),
            $this->requesterStrikes($request),
            $this->implausibleUnits($request),
            $this->phonePattern($request),
        ]));

        return ['score' => (int) array_sum(array_column($signals, 'weight')), 'signals' => $signals];
    }

    /**
     * Assess and, if the score reaches the threshold, put the request in the review queue.
     * Safe to call repeatedly (after creation, after each report). Returns the flag or null.
     */
    public function evaluate(BloodRequest $request): ?RequestRiskFlag
    {
        $result = $this->assess($request);
        $flag = RequestRiskFlag::where('blood_request_id', $request->id)->first();

        if ($result['score'] < (int) config('risk.review_threshold', 3)) {
            // Below the bar: leave an open flag's evidence fresh, never create one.
            if ($flag && $flag->status === RequestRiskFlag::OPEN) {
                $flag->update(['score' => $result['score'], 'signals' => $result['signals']]);
            }

            return $flag;
        }

        if (! $flag) {
            return RequestRiskFlag::create([
                'blood_request_id' => $request->id,
                'score' => $result['score'],
                'signals' => $result['signals'],
                'status' => RequestRiskFlag::OPEN,
            ]);
        }

        $update = ['score' => $result['score'], 'signals' => $result['signals']];

        // A reviewed flag only comes back if the picture got clearly worse since the review.
        if ($flag->status === RequestRiskFlag::DISMISSED
            && $result['score'] >= (int) $flag->reviewed_score + (int) config('risk.reopen_after_increase', 2)) {
            $update['status'] = RequestRiskFlag::OPEN;
        }

        if ($flag->status !== RequestRiskFlag::CONFIRMED) {
            $flag->update($update);
        }

        return $flag;
    }

    private function phoneBurst(BloodRequest $r): ?array
    {
        $cfg = config('risk.phone_burst');
        $phone = (string) $r->contact_number_normalized;
        if ($phone === '') {
            return null;
        }

        $n = BloodRequest::where('contact_number_normalized', $phone)
            ->where('created_at', '>=', $r->created_at->copy()->subHours($cfg['window_hours']))
            ->where('created_at', '<=', $r->created_at)
            ->count();

        return $n >= $cfg['min_requests']
            ? $this->signal('phone_burst', $cfg['weight'], "একই ফোন নম্বর থেকে {$cfg['window_hours']} ঘণ্টায় {$n}টি অনুরোধ।")
            : null;
    }

    private function ipBurst(BloodRequest $r): ?array
    {
        $cfg = config('risk.ip_burst');
        $hash = (string) $r->created_ip_hash;
        if ($hash === '') {
            return null;
        }

        $rows = BloodRequest::where('created_ip_hash', $hash)
            ->where('created_at', '>=', $r->created_at->copy()->subHours($cfg['window_hours']))
            ->where('created_at', '<=', $r->created_at)
            ->get(['contact_number_normalized']);

        $phones = $rows->pluck('contact_number_normalized')->unique()->count();

        // Several requests from one connection is normal at a hospital with one patient;
        // several DIFFERENT phone numbers from one connection is the suspicious part.
        return $rows->count() >= $cfg['min_requests'] && $phones >= 2
            ? $this->signal('ip_burst', $cfg['weight'], "একই ইন্টারনেট সংযোগ থেকে {$cfg['window_hours']} ঘণ্টায় {$rows->count()}টি অনুরোধ, {$phones}টি ভিন্ন ফোন নম্বর দিয়ে।")
            : null;
    }

    private function duplicateText(BloodRequest $r): ?array
    {
        $cfg = config('risk.duplicate_text');
        $text = mb_strtolower(trim((string) $r->notes));
        if (mb_strlen($text) < $cfg['min_length']) {
            return null;
        }

        $n = BloodRequest::where('id', '!=', $r->id)
            ->whereRaw('LOWER(TRIM(notes)) = ?', [$text])
            ->where('created_at', '>=', $r->created_at->copy()->subDays($cfg['window_days']))
            ->count();

        return $n > 0
            ? $this->signal('duplicate_text', $cfg['weight'], "হুবহু একই বিবরণ গত {$cfg['window_days']} দিনে আরও {$n}টি অনুরোধে আছে।")
            : null;
    }

    private function spamReports(BloodRequest $r): ?array
    {
        $cfg = config('risk.spam_reports');
        $n = DB::table('blood_request_reports')
            ->where('blood_request_id', $r->id)
            ->whereIn('reason', ['fake_number', 'spam', 'abusive'])
            ->distinct()->count('user_id');

        if ($n >= $cfg['strong_min_reports']) {
            return $this->signal('spam_reports', $cfg['strong_weight'], "{$n} জন আলাদা ব্যবহারকারী ভুয়া বা স্প্যাম বলে রিপোর্ট করেছেন।");
        }

        return $n >= $cfg['min_reports']
            ? $this->signal('spam_reports', $cfg['weight'], "{$n} জন আলাদা ব্যবহারকারী ভুয়া বা স্প্যাম বলে রিপোর্ট করেছেন।")
            : null;
    }

    private function managedReports(BloodRequest $r): ?array
    {
        $cfg = config('risk.managed_reports');
        $n = DB::table('blood_request_reports')
            ->where('blood_request_id', $r->id)
            ->where('reason', 'already_managed')
            ->distinct()->count('user_id');

        return $n >= $cfg['min_reports']
            ? $this->signal('managed_reports', $cfg['weight'], "{$n} জন জানিয়েছেন রক্ত ইতিমধ্যে জোগাড় হয়ে গেছে। অনুরোধটি বন্ধ করা দরকার কি না দেখুন।")
            : null;
    }

    private function requesterStrikes(BloodRequest $r): ?array
    {
        $cfg = config('risk.requester_strikes');
        if (! $r->requested_by) {
            return null;
        }

        $strikes = (int) DB::table('users')->where('id', $r->requested_by)->value('spam_strikes');

        return $strikes >= $cfg['min_strikes']
            ? $this->signal('requester_strikes', $cfg['weight'], "অনুরোধকারী আগে {$strikes}বার স্প্যামের স্ট্রাইক পেয়েছেন।")
            : null;
    }

    private function implausibleUnits(BloodRequest $r): ?array
    {
        $cfg = config('risk.implausible_units');
        $attrs = $r->getAttributes(); // not getRawOriginal(): inside the created event the original is not synced yet
        $units = max((int) ($attrs["units_needed"] ?? 0), (int) ($attrs["bags_needed"] ?? 0));

        return $units >= $cfg['min_units']
            ? $this->signal('implausible_units', $cfg['weight'], "একটি অনুরোধে {$units} ব্যাগ রক্ত চাওয়া হয়েছে, যা অস্বাভাবিক।")
            : null;
    }

    private function phonePattern(BloodRequest $r): ?array
    {
        $digits = preg_replace('/\D+/', '', (string) $r->contact_number);

        $suspicious = preg_match('/(\d)\1{6,}/', $digits) === 1
            || str_contains($digits, '1234567')
            || str_contains($digits, '7654321');

        return $suspicious
            ? $this->signal('phone_pattern', (int) config('risk.phone_pattern.weight'), 'ফোন নম্বরটি একই অঙ্ক বা ক্রমিক অঙ্কের পুনরাবৃত্তি, যা ভুয়া নম্বরের লক্ষণ।')
            : null;
    }

    private function signal(string $code, int $weight, string $reason): array
    {
        return ['code' => $code, 'weight' => $weight, 'reason' => $reason];
    }
}
