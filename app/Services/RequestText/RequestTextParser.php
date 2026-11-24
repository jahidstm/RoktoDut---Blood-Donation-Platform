<?php

namespace App\Services\RequestText;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client for the ML service's /api/v2/parse-request (roktodut-ml-service/nlp/).
 */
class RequestTextParser
{
    private const PATH = '/api/v2/parse-request';

    /**
     * @return array<string, mixed> the parser's JSON (see nlp/parser.py ParseResult)
     *
     * @throws ParserUnavailable when the service is down, slow, or answers badly
     */
    public function parse(string $text, bool $useLlm = false): array
    {
        try {
            $response = Http::timeout($useLlm ? 15 : 5)
                ->acceptJson()
                ->withHeaders(['X-API-Key' => (string) config('services.roktodut_ml.api_key')])
                ->post(rtrim((string) config('services.roktodut_ml.base_url'), '/') . self::PATH, [
                    'text' => $text,
                    'use_llm' => $useLlm,
                ]);

            $response->throw();
            $data = $response->json();
        } catch (\Throwable $e) {
            Log::warning('Request text parser unavailable', ['message' => $e->getMessage()]);

            throw new ParserUnavailable('The text parser is not reachable.', previous: $e);
        }

        if (!is_array($data) || !array_key_exists('blood_group', $data)) {
            throw new ParserUnavailable('The text parser returned an unexpected response.');
        }

        return $data;
    }
}
