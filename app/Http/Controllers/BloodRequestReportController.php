<?php

namespace App\Http\Controllers;

use App\Models\BloodRequest;
use App\Models\BloodRequestReport;
use App\Services\Risk\RequestRiskService;
use Illuminate\Http\Request;

class BloodRequestReportController extends Controller
{
    /**
     * Store a report. Reports are evidence for the admin review queue; they never hide or
     * close a request by themselves, because three accounts could otherwise take down a
     * genuine emergency request.
     */
    public function store(Request $request, BloodRequest $bloodRequest, RequestRiskService $risk)
    {
        $request->validate([
            'reason' => 'required|in:fake_number,already_managed,spam,abusive',
        ]);

        $user = auth()->user();

        if ($user->id === $bloodRequest->requested_by) {
            return back()->with('error', 'আপনি নিজের রিকোয়েস্টে রিপোর্ট করতে পারবেন না।');
        }

        $alreadyReported = BloodRequestReport::where('user_id', $user->id)
            ->where('blood_request_id', $bloodRequest->id)
            ->exists();

        if ($alreadyReported) {
            return back()->with('error', 'আপনি ইতোমধ্যেই এই রিকোয়েস্টে রিপোর্ট করেছেন।');
        }

        BloodRequestReport::create([
            'user_id' => $user->id,
            'blood_request_id' => $bloodRequest->id,
            'reason' => $request->reason,
            'status' => 'pending',
        ]);

        $bloodRequest->increment($request->reason === 'already_managed' ? 'managed_report_count' : 'spam_report_count');

        $risk->evaluate($bloodRequest->fresh());

        return back()->with('success', 'আপনার রিপোর্টটি সফলভাবে গ্রহণ করা হয়েছে। ধন্যবাদ!');
    }
}
