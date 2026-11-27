<?php

namespace App\Policies;

use App\Models\BloodRequest;
use App\Models\User;

class BloodRequestPolicy
{
    public function view(User $user, BloodRequest $bloodRequest): bool
    {
        return true;
    }

    public function respond(User $user, BloodRequest $bloodRequest): bool
    {
        if ((int) $bloodRequest->requested_by === (int) $user->id) {
            return false;
        }

        if ($bloodRequest->isClosed()) {
            return false;
        }

        return true;
    }

    public function markFulfilled(User $user, BloodRequest $bloodRequest): bool
    {
        if ((int) $bloodRequest->requested_by !== (int) $user->id) {
            return false;
        }

        if ($bloodRequest->isClosed()) {
            return false;
        }

        return true;
    }

    public function viewAcceptedDonors(User $user, BloodRequest $bloodRequest): bool
    {
        if ((int) $bloodRequest->requested_by === (int) $user->id) {
            return true;
        }

        // Organisation admins are deliberately not included: these are donors' phone numbers.
        return $user->isAdmin();
    }
}