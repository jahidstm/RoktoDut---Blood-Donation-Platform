<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestRiskFlag extends Model
{
    public const OPEN = 'open';
    public const DISMISSED = 'dismissed';
    public const CONFIRMED = 'confirmed';

    protected $fillable = [
        'blood_request_id', 'score', 'signals', 'status',
        'reviewed_score', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return ['signals' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function bloodRequest(): BelongsTo
    {
        return $this->belongsTo(BloodRequest::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
