<?php

namespace App\Services\Chatbot;

/**
 * Masks identifiers before text leaves this server for a language model.
 * Catches emails and long digit runs (phone, NID, card numbers). It cannot recognise
 * names or addresses typed as ordinary words.
 */
class PiiMasker
{
    public function mask(string $text): string
    {
        $text = preg_replace('/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.[a-z]{2,}/iu', '[ইমেইল]', $text) ?? $text;

        // 7 or more digits (ASCII or Bangla), allowing spaces, dashes and a leading +.
        return preg_replace('/\+?(?:[0-9০-৯][\s\-]?){6,}[0-9০-৯]/u', '[নম্বর]', $text) ?? $text;
    }
}
