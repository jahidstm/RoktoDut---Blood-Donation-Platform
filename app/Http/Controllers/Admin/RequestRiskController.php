<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RequestRiskFlag;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/**
 * Review queue for requests the risk rules flagged. The admin decides; nothing is hidden
 * until "confirm spam" is pressed. Both decisions are stored as labels.
 */
class RequestRiskController extends Controller
{
    public function index()
    {
        $flags = RequestRiskFlag::query()
            ->where('status', RequestRiskFlag::OPEN)
            ->with(['bloodRequest.district:id,name', 'bloodRequest.requester:id,name,spam_strikes'])
            ->orderByDesc('score')
            ->orderBy('created_at')
            ->paginate(15);

        $confirmed = RequestRiskFlag::where('status', RequestRiskFlag::CONFIRMED)->count();
        $dismissed = RequestRiskFlag::where('status', RequestRiskFlag::DISMISSED)->count();

        return view('admin.risk.index', [
            'flags' => $flags,
            'confirmed' => $confirmed,
            'dismissed' => $dismissed,
            // Share of reviewed flags that really were spam. Meaningless on a handful of reviews.
            'precision' => ($confirmed + $dismissed) > 0 ? $confirmed / ($confirmed + $dismissed) : null,
        ]);
    }

    public function dismiss(Request $request, RequestRiskFlag $flag)
    {
        return $this->review($request, $flag, RequestRiskFlag::DISMISSED, 'রিকোয়েস্টটি আসল হিসেবে চিহ্নিত হয়েছে, কিছু বদলানো হয়নি।');
    }

    public function confirm(Request $request, RequestRiskFlag $flag)
    {
        $flag->bloodRequest()->update(['is_hidden' => true]);

        return $this->review($request, $flag, RequestRiskFlag::CONFIRMED, 'রিকোয়েস্টটি স্প্যাম হিসেবে নিশ্চিত করা হয়েছে এবং ফিড থেকে লুকানো হয়েছে।');
    }

    private function review(Request $request, RequestRiskFlag $flag, string $status, string $message)
    {
        $data = $request->validate(['note' => 'nullable|string|max:255']);

        $flag->update([
            'status' => $status,
            'reviewed_score' => $flag->score,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $data['note'] ?? null,
        ]);

        AuditLogger::log("admin.risk.{$status}", $flag->bloodRequest, ['flag_id' => $flag->id, 'score' => $flag->score]);

        return back()->with('success', $message);
    }
}
