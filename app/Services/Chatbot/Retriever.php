<?php

namespace App\Services\Chatbot;

use App\Services\RequestText\BanglaText;

/**
 * TF-IDF cosine similarity over character 3- and 4-grams.
 *
 * Character n-grams cope with Bangla case endings (রক্তদানের / রক্তদান) and Banglish
 * spelling variation without a tokenizer, a model download or another service. This is
 * the baseline: an embedding retriever should replace it only if it scores better on
 * resources/knowledge/eval.json (`php artisan chatbot:eval`).
 */
class Retriever
{
    /** Question words that say nothing about the topic. */
    private const STOPWORDS = [
        'কী', 'কি', 'কীভাবে', 'কিভাবে', 'কেন', 'কোন', 'কোনো', 'আমি', 'আমার', 'আমাদের', 'আপনি', 'আপনার', 'আপনারা',
        'করব', 'করবো', 'করা', 'করে', 'করি', 'করেন', 'হয়', 'হবে', 'যায়', 'যাবে', 'পারি', 'পারব', 'পারে', 'পারবেন', 'একটা', 'এবং', 'আর',
        'the', 'a', 'an', 'is', 'are', 'how', 'do', 'i', 'can', 'to', 'of', 'in', 'what', 'my', 'me',
        'ki', 'kivabe', 'ami', 'amar', 'korbo', 'jay', 'parbo', 'hobe',
    ];

    /** @var list<array{passage: array, vector: array<string, float>}>|null */
    private ?array $index = null;

    /** @var array<string, float> */
    private array $idf = [];

    public function __construct(private KnowledgeBase $knowledge)
    {
    }

    /** @return list<array{passage: array, score: float}> best first */
    public function search(string $query, int $k = 3): array
    {
        $this->build();

        $q = $this->weigh($this->grams($query));
        if (! $q) {
            return [];
        }

        $hits = [];
        foreach ($this->index as $doc) {
            $score = 0.0;
            foreach ($q as $gram => $weight) {
                $score += $weight * ($doc['vector'][$gram] ?? 0.0);
            }
            if ($score > 0) {
                $hits[] = ['passage' => $doc['passage'], 'score' => round($score, 4)];
            }
        }

        usort($hits, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($hits, 0, $k);
    }

    private function build(): void
    {
        if ($this->index !== null) {
            return;
        }

        $passages = $this->knowledge->passages();
        $counts = [];
        $df = [];

        foreach ($passages as $i => $p) {
            // Title and keywords count twice: they say what the passage is about.
            $counts[$i] = $this->grams(str_repeat($p['title'].' '.$p['keywords'].' ', 2).$p['text']);
            foreach (array_keys($counts[$i]) as $gram) {
                $df[$gram] = ($df[$gram] ?? 0) + 1;
            }
        }

        $n = count($passages);
        foreach ($df as $gram => $d) {
            $this->idf[$gram] = log(1 + $n / $d);
        }

        $this->index = [];
        foreach ($passages as $i => $p) {
            $this->index[] = ['passage' => $p, 'vector' => $this->weigh($counts[$i])];
        }
    }

    /** @return array<string, int> */
    private function grams(string $text): array
    {
        $text = BanglaText::normalize($text);
        $words = preg_split('/[^\p{L}\p{M}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $out = [];
        foreach ($words as $word) {
            if (in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $padded = ' '.$word.' ';
            $len = mb_strlen($padded);
            foreach ([3, 4] as $size) {
                for ($i = 0; $i + $size <= $len; $i++) {
                    $gram = mb_substr($padded, $i, $size);
                    $out[$gram] = ($out[$gram] ?? 0) + 1;
                }
            }
        }

        return $out;
    }

    /**
     * Sub-linear TF times IDF, L2-normalised. Grams no passage contains keep the highest
     * IDF, so an off-topic question is mostly "unseen" and scores low.
     *
     * @param array<string, int> $counts
     * @return array<string, float>
     */
    private function weigh(array $counts): array
    {
        $unseen = log(1 + max(1, count($this->index ?? []) ?: 20));
        $vector = [];
        $norm = 0.0;

        foreach ($counts as $gram => $tf) {
            $w = (1 + log($tf)) * ($this->idf[$gram] ?? $unseen);
            $vector[$gram] = $w;
            $norm += $w * $w;
        }

        $norm = sqrt($norm) ?: 1.0;

        return array_map(fn ($w) => $w / $norm, $vector);
    }
}
