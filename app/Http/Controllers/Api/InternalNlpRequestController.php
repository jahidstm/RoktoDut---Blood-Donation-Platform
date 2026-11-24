<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RequestText\NlpRequestIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Internal endpoint (X-Internal-Secret) for services that already parsed a message.
 *
 * Two fixes over the original:
 *  - urgency accepts the app's own values; the old parser's "high"/"medium" are mapped
 *    (they used to be written straight into a column that only allows normal/urgent/emergency);
 *  - a district is required. The old code fell back to the FIRST district in the database
 *    when none was given, which put requests in the wrong part of the country.
 */
class InternalNlpRequestController extends Controller
{
    private const LEGACY_URGENCY = ['high' => 'urgent', 'medium' => 'normal'];

    public function store(Request $request, NlpRequestIntake $intake): JsonResponse
    {
        $validated = $request->validate([
            'blood_group' => ['required', 'string', 'in:A+,A-,B+,B-,AB+,AB-,O+,O-'],
            'district_id' => ['required', 'integer', 'exists:districts,id'],
            'urgency' => ['nullable', 'string', 'in:normal,urgent,emergency,high,medium'],
            'units_needed' => ['nullable', 'integer', 'min:1', 'max:20'],
            'component_type' => ['nullable', 'string', 'in:whole_blood,packed_rbc,platelets,plasma'],
            'contact_number' => ['nullable', 'string', 'regex:/^01[3-9]\d{8}$/'],
            'location_text' => ['nullable', 'string', 'max:255'],
            'confidence_score' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'requested_by' => ['nullable', 'integer', 'exists:users,id'],
            'telegram_chat_id' => ['nullable', 'string', 'max:255'],
            'raw_text' => ['nullable', 'string', 'max:1000'],
        ]);

        $urgency = $validated['urgency'] ?? null;

        $bloodRequest = $intake->createPending(
            [
                'blood_group' => $validated['blood_group'],
                'district_id' => (int) $validated['district_id'],
                'units' => $validated['units_needed'] ?? null,
                'urgency' => self::LEGACY_URGENCY[$urgency] ?? $urgency,
                'component_type' => $validated['component_type'] ?? null,
                'contact_number' => $validated['contact_number'] ?? null,
                'location_text' => $validated['location_text'] ?? null,
                'confidence' => isset($validated['confidence_score']) ? (float) $validated['confidence_score'] : null,
            ],
            rawText: (string) ($validated['raw_text'] ?? ''),
            requester: isset($validated['requested_by']) ? User::find($validated['requested_by']) : null,
            telegramChatId: $validated['telegram_chat_id'] ?? null,
        );

        return response()->json([
            'id' => $bloodRequest->id,
            'status' => $bloodRequest->status,
            'message' => 'NLP request stored for human approval.',
        ], 201);
    }
}
