<?php

namespace App\Services\Chatbot;

use App\Services\RequestText\BanglaText;

/**
 * Runs before anything is retrieved or sent to a language model. Two cases get a fixed
 * answer written by us:
 *  - emergency: something is happening to a person now;
 *  - personal medical: "can I donate, given MY condition?" Only a doctor can answer that.
 *
 * Keyword rules miss things. They are one layer; the model is also told not to diagnose,
 * and answers only from the knowledge base.
 */
class SafetyGuard
{
    public const EMERGENCY = 'emergency';
    public const PERSONAL_MEDICAL = 'personal_medical';

    private const EMERGENCY_TERMS = [
        'রক্তক্ষরণ', 'অজ্ঞান', 'শ্বাস', 'শ্বাসকষ্ট', 'খিঁচুনি', 'বুকে ব্যথা', 'জ্ঞান হারা',
        'unconscious', 'fainted', 'bleeding heavily', 'heavy bleeding', 'cant breathe', "can't breathe", 'chest pain', 'seizure',
        'shash nite', 'ogyan', 'oggan',
    ];

    private const FIRST_PERSON = [
        'আমার', 'আমি', 'amar', 'ami', 'i', 'my', 'me', 'দিতে পারব', 'দেব', 'parbo',
    ];

    private const HEALTH_TERMS = [
        'হিমোগ্লোবিন', 'ওষুধ', 'ঔষধ', 'জ্বর', 'সর্দি', 'হেপাটাইটিস', 'জন্ডিস', 'এইচআইভি', 'ক্যান্সার', 'যক্ষ্মা',
        'অপারেশন', 'সার্জারি', 'ট্যাটু', 'অ্যান্টিবায়োটিক', 'রিপোর্ট', 'মাথা ঘুর', 'বমি', 'দুর্বল', 'অ্যালার্জি', 'হাঁপানি',
        'ডায়াবেটিস', 'প্রেশার', 'রক্তচাপ', 'হার্ট', 'কিডনি', 'রোগ আছে', 'কী রোগ', 'কি রোগ', 'বয়স', 'ওজন', 'টিকা', 'পিরিয়ড',
        'hemoglobin', 'haemoglobin', 'medicine', 'oshudh', 'osud', 'fever', 'jor', 'hepatitis', 'jaundice', 'hiv', 'cancer',
        'surgery', 'operation', 'tattoo', 'antibiotic', 'report', 'diabetes', 'pressure', 'asthma', 'allergy', 'vaccine', 'period',
    ];

    /** Asking for a medicine is refused even without "my". */
    private const ALWAYS_MEDICAL = ['কী ওষুধ', 'কি ওষুধ', 'কোন ওষুধ', 'ki oshudh', 'which medicine', 'what medicine'];

    public function check(string $message): ?string
    {
        $text = BanglaText::normalize($message);

        if ($this->any($text, self::EMERGENCY_TERMS)) {
            return self::EMERGENCY;
        }

        if ($this->any($text, self::ALWAYS_MEDICAL)
            || ($this->any($text, self::FIRST_PERSON) && $this->any($text, self::HEALTH_TERMS))) {
            return self::PERSONAL_MEDICAL;
        }

        return null;
    }

    public function reply(string $verdict): string
    {
        return $verdict === self::EMERGENCY
            ? 'এটি জরুরি অবস্থা হতে পারে। দেরি না করে রোগীকে নিকটস্থ হাসপাতালে নিন বা ৯৯৯ নম্বরে কল করুন। আমি চিকিৎসা-পরামর্শ দিতে পারি না।'
            : 'আপনার শারীরিক অবস্থা বিচার করে রক্ত দেওয়া যাবে কি না, তা আমি বলতে পারি না। অনুগ্রহ করে একজন চিকিৎসক বা হাসপাতালের ব্লাড ব্যাংকের সাথে কথা বলুন; রক্তদানের আগে তারা পরীক্ষা করে সিদ্ধান্ত নেন।';
    }

    private function any(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if (BanglaText::contains($text, BanglaText::normalize($term))) {
                return true;
            }
        }

        return false;
    }
}
