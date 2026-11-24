<?php

namespace App\Http\Controllers;

use App\Services\RequestText\ParserUnavailable;
use App\Services\RequestText\RequestTextAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Paste the message, we fill the form" on the request form. Returns suggestions only;
 * the person still reviews and submits the form themselves.
 */
class RequestTextController extends Controller
{
    public function parse(Request $request, RequestTextAnalyzer $analyzer): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        try {
            // The form never uses the LLM: it would send the message to a third party,
            // and the rules cover the common cases. Telegram can opt in via config.
            $fields = $analyzer->analyze($validated['text'], useLlm: false);
        } catch (ParserUnavailable) {
            return response()->json([
                'ok' => false,
                'message' => 'স্বয়ংক্রিয় পূরণ এই মুহূর্তে কাজ করছে না। অনুগ্রহ করে ফর্মটি নিজে পূরণ করুন।',
            ], 503);
        }

        return response()->json(['ok' => true, 'fields' => $fields]);
    }
}
