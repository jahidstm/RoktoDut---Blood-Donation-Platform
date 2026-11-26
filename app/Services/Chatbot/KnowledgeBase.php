<?php

namespace App\Services\Chatbot;

/**
 * The only facts the chatbot is allowed to answer from: the markdown files in
 * resources/knowledge, plus the donation intervals generated from config/eligibility.php
 * so the bot can never quote different numbers from the ones the matching code enforces.
 */
class KnowledgeBase
{
    /** @return list<array{id: string, title: string, keywords: string, text: string, source: string, url: ?string}> */
    public function passages(): array
    {
        $passages = [];

        foreach (glob(resource_path('knowledge/*.md')) ?: [] as $file) {
            array_push($passages, ...$this->parse((string) file_get_contents($file)));
        }

        $passages[] = $this->donationInterval();

        return $passages;
    }

    /**
     * File format: a front-matter block (source:, url:), then "## id | title" sections,
     * each with an optional "url:" and "keywords:" line before the text.
     */
    private function parse(string $markdown): array
    {
        $markdown = str_replace("\r\n", "\n", $markdown);
        $meta = ['source' => 'রক্তদূত', 'url' => null];

        if (preg_match('/\A---\n(.*?)\n---\n/s', $markdown, $m)) {
            foreach (explode("\n", $m[1]) as $line) {
                [$key, $value] = array_pad(explode(':', $line, 2), 2, '');
                $meta[trim($key)] = trim($value);
            }
            $markdown = substr($markdown, strlen($m[0]));
        }

        $out = [];
        foreach (preg_split('/^## /m', $markdown, -1, PREG_SPLIT_NO_EMPTY) as $section) {
            $lines = explode("\n", trim($section));
            if (! str_contains($lines[0], '|')) {
                continue;
            }

            [$id, $title] = array_map('trim', explode('|', array_shift($lines), 2));
            $url = $meta['url'] ?: null;
            $keywords = '';

            while ($lines && preg_match('/^(url|keywords):\s*(.*)$/', $lines[0], $field)) {
                $field[1] === 'url' ? $url = trim($field[2]) : $keywords = trim($field[2]);
                array_shift($lines);
            }

            $out[] = [
                'id' => $id,
                'title' => $title,
                'keywords' => $keywords,
                'text' => trim(implode("\n", $lines)),
                'source' => $meta['source'],
                'url' => $url,
            ];
        }

        return $out;
    }

    private function donationInterval(): array
    {
        $days = config('eligibility.cooldown_days');
        $bn = fn (int $n) => strtr((string) $n, ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯']);

        return [
            'id' => 'donation-interval',
            'title' => 'কত দিন পর পর রক্ত দেওয়া যায়',
            'keywords' => 'কতদিন পর, বিরতি, আবার কখন, প্লাটিলেট, প্লাটেলেট, প্লাজমা, cooldown, interval, koto din por, abar rokto, platelet, plasma',
            'text' => 'রক্তদূতে ন্যূনতম বিরতি: পূর্ণ রক্তদানের পর পুরুষের '.$bn($days['whole_blood']['male'])
                .' দিন, নারীর '.$bn($days['whole_blood']['female']).' দিন; প্লাজমা দানের পর '.$bn($days['plasma'])
                .' দিন; প্লাটিলেট দানের পর '.$bn($days['platelets']).' দিন। এগুলো অ্যাপের নিয়ম, চিকিৎসা-পরামর্শ নয়; চূড়ান্ত সিদ্ধান্ত হাসপাতালের।',
            'source' => 'রক্তদূতের যোগ্যতার নিয়ম (config)',
            'url' => '/faq',
        ];
    }
}
