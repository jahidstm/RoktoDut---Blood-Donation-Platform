<?php

namespace App\Services;

use App\Models\BloodRequest;
use App\Models\DonorResponseLog;

/**
 * Keeps `donor_response_logs` honest.
 *
 * The dispatch jobs create a row with status "pending" when a donor is notified. Before this
 * service nothing ever moved a row out of "pending", so the table (the training data for the
 * response model) had no outcomes at all.
 *
 * Outcomes: pending (not yet known), accepted, declined, ignored (no answer), moot (settled by
 * someone else before the donor answered; neutral).
 *
 * Vocabulary: the donor-side statuses on `blood_request_responses` mean
 *   pending / contacted  -> the donor said "I can donate"   -> log "accepted"
 *   declined / withdrawn -> the donor said no               -> log "declined"
 * The requester later approving a donor ("accepted" on the response row) is NOT donor
 * behaviour and is deliberately not recorded here.
 *
 * Rows are only updated, never created: a donor who finds a request by browsing was never
 * notified, and mixing them in would bias the data toward people who respond anyway.
 */
class DonorResponseLogger
{
    /** A notified donor who has not answered within this many hours counts as having ignored it. */
    public const IGNORE_AFTER_HOURS = 24;

    /** Request statuses meaning someone else (or nobody) settled it. */
    private const SETTLED_REQUEST_STATUSES = ['fulfilled', 'rejected', 'cancelled'];

    /**
     * Record a donor's answer to a request they were notified about.
     * Returns true when a notified-donor row existed and was updated.
     */
    public function recordResponse(int $requestId, int $donorId, string $responseStatus): bool
    {
        $logStatus = match ($responseStatus) {
            'pending', 'contacted' => 'accepted',
            'declined', 'withdrawn' => 'declined',
            default => null,
        };

        if ($logStatus === null) {
            return false;
        }

        $log = DonorResponseLog::query()
            ->where('request_id', $requestId)
            ->where('donor_id', $donorId)
            ->first();

        if ($log === null) {
            return false;
        }

        $now = now();

        // The first answer defines the response time; a later change of mind only updates status.
        $log->status = $logStatus;
        if ($log->responded_at === null) {
            $log->responded_at = $now;
            $log->response_time_minutes = max(0, (int) $log->notified_at->diffInMinutes($now));
        }
        $log->save();

        return true;
    }

    /**
     * Requests that have closed: everyone still "pending" never answered in time.
     *
     * @param array<int> $requestIds
     */
    public function markIgnoredForRequests(array $requestIds): int
    {
        if ($requestIds === []) {
            return 0;
        }

        return DonorResponseLog::query()
            ->whereIn('request_id', $requestIds)
            ->where('status', 'pending')
            ->update(['status' => 'ignored']);
    }

    /**
     * Anyone notified more than IGNORE_AFTER_HOURS ago who never answered:
     *  - request already settled (fulfilled / rejected) -> "moot": the donor
     *    may never have had a chance to matter, so it must not count against them;
     *  - otherwise -> "ignored".
     * "moot" errs on the side of not penalising: a donor who sat on a request for hours
     * before someone else was confirmed is also counted moot.
     *
     * @return int number of rows changed
     */
    public function markStaleAsIgnored(): int
    {
        $stale = fn () => DonorResponseLog::query()
            ->where('status', 'pending')
            ->where('notified_at', '<', now()->subHours(self::IGNORE_AFTER_HOURS));

        $settled = BloodRequest::query()
            ->whereIn('status', self::SETTLED_REQUEST_STATUSES)
            ->select('id');

        $moot = $stale()->whereIn('request_id', $settled)->update(['status' => 'moot']);
        $ignored = $stale()->update(['status' => 'ignored']);

        return $moot + $ignored;
    }
}
