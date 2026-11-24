<?php

namespace App\Services;

use App\Enums\BloodComponentType;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * "Is this donor allowed to give this component again yet?" in exactly one place.
 *
 * The same rule is needed in two shapes: per user in PHP (profile page, respond button)
 * and as a SQL filter (candidate search). Both are built from config/eligibility.php, so
 * they cannot drift apart again.
 *
 * Rules are deliberately never ML: eligibility is medical policy, not a prediction.
 */
class EligibilityService
{
    /** Normalise a component to a config key. packed_rbc follows whole_blood. */
    public function componentKey(mixed $component): string
    {
        $value = $component instanceof BloodComponentType
            ? $component->value
            : (string) ($component ?? BloodComponentType::WHOLE_BLOOD->value);

        return match ($value) {
            BloodComponentType::PLASMA->value => 'plasma',
            BloodComponentType::PLATELETS->value => 'platelets',
            default => 'whole_blood',
        };
    }

    public function cooldownDays(mixed $component, ?string $gender = null): int
    {
        $key = $this->componentKey($component);
        $rule = config("eligibility.cooldown_days.{$key}");

        if (! is_array($rule)) {
            return (int) $rule;
        }

        $g = strtolower((string) $gender);

        return (int) ($rule[$g === 'male' || $g === 'female' ? $g : 'unknown']);
    }

    /**
     * The most recent donation relevant to this component, or null.
     * For whole blood both legacy columns are checked and the later one wins, so a
     * stale value in one column can never make a donor look eligible too early.
     */
    public function lastDonationDate(User $user, mixed $component = null): ?Carbon
    {
        return match ($this->componentKey($component)) {
            'plasma' => $user->last_plasma_donated_at,
            'platelets' => $user->last_platelet_donated_at,
            default => collect([$user->last_whole_blood_donated_at, $user->last_donated_at])
                ->filter()
                ->sortDesc()
                ->first(),
        };
    }

    /** Days since a donor never donated, for the ML feature. Matches the training data range. */
    public const NEVER_DONATED_DAYS = 365;

    /**
     * ML feature `days_since_last_donation`, identical in every dispatch path.
     * (The DFI service counts "never donated" as 0, which is right for fatigue but
     * the opposite of what the response model was trained on.)
     */
    public function daysSinceLastDonation(User $user, mixed $component = null, ?Carbon $now = null): int
    {
        $last = $this->lastDonationDate($user, $component);

        return $last ? max(0, (int) $last->diffInDays($now ?? now())) : self::NEVER_DONATED_DAYS;
    }

    public function nextEligibleDate(User $user, mixed $component = null): ?Carbon
    {
        $last = $this->lastDonationDate($user, $component);

        return $last?->copy()->startOfDay()->addDays($this->cooldownDays($component, $user->gender));
    }

    public function daysUntilEligible(User $user, mixed $component = null): int
    {
        $next = $this->nextEligibleDate($user, $component);

        if ($next === null) {
            return 0;
        }

        return max(0, (int) now()->startOfDay()->diffInDays($next, false));
    }

    /**
     * SQL equivalent of daysUntilEligible() === 0, for candidate queries on `users`.
     *
     * @param Builder|BuilderContract $query
     * @return Builder|BuilderContract
     */
    public function scopeEligible($query, mixed $component)
    {
        $key = $this->componentKey($component);

        if ($key === 'plasma') {
            return $query->where(fn ($q) => $this->olderThan($q, ['last_plasma_donated_at'], $this->cooldownDays($key)));
        }

        if ($key === 'platelets') {
            return $query->where(fn ($q) => $this->olderThan($q, ['last_platelet_donated_at'], $this->cooldownDays($key)));
        }

        $columns = ['last_whole_blood_donated_at', 'last_donated_at'];

        // One branch per gender group, each with its own interval.
        return $query->where(function ($q) use ($columns) {
            $q->where(fn ($g) => $g->whereRaw('LOWER(gender) = ?', ['male'])
                    ->where(fn ($d) => $this->olderThan($d, $columns, $this->cooldownDays('whole_blood', 'male'))))
                ->orWhere(fn ($g) => $g->whereRaw('LOWER(gender) = ?', ['female'])
                    ->where(fn ($d) => $this->olderThan($d, $columns, $this->cooldownDays('whole_blood', 'female'))))
                ->orWhere(fn ($g) => $g
                    ->where(fn ($x) => $x->whereNull('gender')->orWhereRaw('LOWER(gender) NOT IN (?, ?)', ['male', 'female']))
                    ->where(fn ($d) => $this->olderThan($d, $columns, $this->cooldownDays('whole_blood', null))));
        });
    }

    /** Every listed column is either NULL or at least $days old. */
    private function olderThan($query, array $columns, int $days): void
    {
        $cutoff = now()->subDays($days);

        foreach ($columns as $column) {
            $query->where(fn ($q) => $q->whereNull($column)->orWhere($column, '<=', $cutoff));
        }
    }
}
