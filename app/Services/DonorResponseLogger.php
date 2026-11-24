<?php

namespace App\Services;

use App\Models\DonorResponseLog;

/**
 * Keeps `donor_response_logs` honest.
 *
 * The dispatch jobs create a row with status "pending" when a donor is notified. Before this
 * service nothing ever moved a row out of "pending", so the table (the training data for the
 * response model) had no outcomes at all.
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
     * Anyone notified more than IGNORE_AFTER_HOURS ago who never answered counts as having ignored it.
     */
    public function markStaleAsIgnored(): int
    {
        return DonorResponseLog::query()
            ->where('status', 'pending')
            ->where('notified_at', '<', now()->subHours(self::IGNORE_AFTER_HOURS))
            ->update(['status' => 'ignored']);
    }
}
