<?php

namespace App\Http\Controllers;

use App\Services\Chatbot\ChatbotAssistant;
use App\Services\RequestText\BanglaText;
use Illuminate\Http\Request;

class ChatbotController extends Controller
{
    /** Keyword => button shown under the reply. First match wins. */
    private const ACTIONS = [
        [['রক্ত লাগবে', 'রক্ত চাই', 'জরুরি রক্ত', 'রক্তের আবেদন', 'request করতে', 'রিকোয়েস্ট করতে', 'blood needed', 'need blood'],
            ['type' => 'link', 'label' => '🩸 রক্তের আবেদন করুন', 'url' => '/requests/create', 'style' => 'danger']],
        [['ডোনার খুঁজ', 'donor খুঁজ', 'রক্তদাতা', 'ডোনার পাব', 'কে দেবে', 'donor পাব', 'donor search'],
            ['type' => 'link', 'label' => '🔍 ডোনার খুঁজুন', 'url' => '/search', 'style' => 'primary']],
        [['ব্লাড ব্যাংক', 'ইনভেন্টরি', 'স্টক', 'blood bank'],
            ['type' => 'link', 'label' => '🏥 ব্লাড ব্যাংক দেখুন', 'url' => '/blood-bank', 'style' => 'info']],
    ];

    public function ask(Request $request, ChatbotAssistant $assistant)
    {
        $message = trim((string) $request->input('message', ''));

        if ($message === '') {
            return response()->json(['reply' => 'অনুগ্রহ করে আপনার প্রশ্নটি লিখুন।'], 422);
        }

        if (mb_strlen($message) > 1000) {
            return response()->json(['reply' => 'প্রশ্নটি একটু ছোট করে লিখুন (সর্বোচ্চ ১০০০ অক্ষর)।'], 422);
        }

        // Only the user's own earlier messages are used as context; "bot" turns sent by
        // the browser could say anything.
        $previous = collect((array) $request->input('history', []))
            ->filter(fn ($m) => is_array($m) && ! empty($m['isUser']))
            ->map(fn ($m) => mb_substr(trim((string) ($m['text'] ?? '')), 0, 300))
            ->filter(fn ($t) => $t !== '' && $t !== $message)
            ->values()
            ->all();

        $result = $assistant->answer($message, $previous);

        return response()->json([
            'reply' => $result['reply'],
            'sources' => $result['sources'],
            'action' => $this->action($message),
        ]);
    }

    private function action(string $message): ?array
    {
        $text = BanglaText::normalize($message);

        foreach (self::ACTIONS as [$keywords, $action]) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, BanglaText::normalize($keyword))) {
                    return $action;
                }
            }
        }

        return null;
    }
}
