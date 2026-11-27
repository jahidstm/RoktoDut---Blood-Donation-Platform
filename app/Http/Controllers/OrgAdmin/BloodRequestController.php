<?php

namespace App\Http\Controllers\OrgAdmin;

use Illuminate\Support\Facades\Notification;
use App\Notifications\BloodRequestMatchedNotification;
use App\Services\Matching\BloodCompatibility;
use App\Services\EligibilityService;
use App\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\BloodRequest;
use App\Models\BroadcastLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BloodRequestController extends Controller
{
    public function index()
    {
        $admin = Auth::user();
        $org = $admin->organization;

        if (!$org) {
            abort(403, 'আপনার কোনো অর্গানাইজেশন অ্যাসাইন করা নেই।');
        }

        $query = $org->areaRequests()->with(['requester', 'district', 'upazila']);

        $requests = $query->latest()->paginate(15);

        return view('org.blood-requests.index', compact('requests', 'org'));
    }

    public function broadcast(Request $request, BloodRequest $bloodRequest, BloodCompatibility $compatibility, EligibilityService $eligibility)
    {
        $admin = Auth::user();
        $org = $admin->organization;

        if (!$org) {
            abort(403, 'আনঅথোরাইজড অ্যাক্সেস!');
        }

        // Only requests shown on this organisation's own list can be broadcast.
        abort_unless($org->areaRequests()->whereKey($bloodRequest->id)->exists(), 404);

        // Rate limit / check duplicate broadcast for this request by this org
        $alreadyBroadcasted = BroadcastLog::where('organization_id', $org->id)
            ->where('blood_request_id', $bloodRequest->id)
            ->exists();

        if ($alreadyBroadcasted) {
            return back()->with('error', 'এই রিকোয়েস্টটি ইতিমধ্যে ব্রডকাস্ট করা হয়েছে।');
        }

        // Verified members who can actually help: right group, available, past their cooldown.
        $members = User::query()
            ->where('organization_id', $org->id)
            ->where('role', 'donor')
            ->where('nid_status', 'verified')
            ->where('is_available', true)
            ->where('opt_out_org_broadcast', false)
            ->whereIn('blood_group', $compatibility->donorGroupsFor($bloodRequest->blood_group, $bloodRequest->component_type))
            ->when($bloodRequest->requested_by, fn ($q, $requester) => $q->whereKeyNot($requester))
            ->tap(fn ($q) => $eligibility->scopeEligible($q, $bloodRequest->component_type))
            ->get();

        if ($members->isEmpty()) {
            return back()->with('error', 'এই অনুরোধের জন্য এখন রক্তদানে প্রস্তুত কোনো ভেরিফাইড মেম্বার নেই।');
        }

        Notification::sendNow($members, new BloodRequestMatchedNotification($bloodRequest, $bloodRequest->district?->name ?? ''));

        BroadcastLog::create([
            'organization_id' => $org->id,
            'blood_request_id' => $bloodRequest->id,
            'broadcasted_by' => $admin->id,
        ]);
        AuditLogger::log('org.request.broadcast', $bloodRequest, ['organization_id' => $org->id, 'notified' => $members->count()]);

        return back()->with('success', "{$members->count()} জন উপযুক্ত মেম্বারকে অ্যাপের নোটিফিকেশন পাঠানো হয়েছে।");
    }
}
