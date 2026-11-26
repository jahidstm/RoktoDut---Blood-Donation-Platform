<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Meaning-based retrieval with Gemini embeddings.
 *
 * The "vector store" is deliberately just a cached array: the knowledge base is a few
 * dozen passages, so a dot product over all of them is instant and a vector database
 * would add a moving part for nothing. Swap one in if the corpus grows by orders of
 * magnitude.
 *
 * Passage vectors are cached under a hash of their text, so editing the knowledge base
 * re-embeds automatically. search() returns null whenever it cannot be used (no key, API
 * error) and the caller falls back to the character n-gram Retriever.
 */
class EmbeddingRetriever
{
    private const DIMENSIONS = 768;

    public function __construct(private KnowledgeBase $knowledge, private PiiMasker $masker)
    {
    }

    public function available(): bool
    {
        return (bool) config('chatbot.use_embeddings', true) && (string) config('services.gemini.api_key') !== '';
    }

    /** @return list<array{passage: array, score: float}>|null best first */
    public function search(string $query, int $k = 3): ?array
    {
        if (! $this->available()) {
            return null;
        }

        try {
            $passages = $this->knowledge->passages();
            $vectors = $this->passageVectors($passages);
            [$q] = $this->embed([$this->masker->mask($query)], 'RETRIEVAL_QUERY');
        } catch (\Throwable $e) {
            Log::warning('Chatbot embedding failed; using keyword retrieval', ['type' => $e::class, 'code' => $e->getCode()]);

            return null;
        }

        $hits = [];
        foreach ($passages as $i => $passage) {
            $hits[] = ['passage' => $passage, 'score' => round($this->dot($q, $vectors[$i]), 4)];
        }

        usort($hits, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($hits, 0, $k);
    }

    private function passageVectors(array $passages): array
    {
        $texts = array_map(fn ($p) => $p['title'].'. '.$p['text'], $passages);
        $key = 'chatbot.vectors.'.hash('sha256', $this->model().'|'.self::DIMENSIONS.'|'.implode("\n", $texts));

        return Cache::rememberForever($key, fn () => $this->embed($texts, 'RETRIEVAL_DOCUMENT'));
    }

    /** @return list<list<float>> unit-length vectors, one per text */
    private function embed(array $texts, string $task): array
    {
        $model = $this->model();

        $response = Http::timeout(15)
            ->withHeaders(['x-goog-api-key' => (string) config('services.gemini.api_key')])
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:batchEmbedContents", [
                'requests' => array_map(fn ($text) => [
                    'model' => "models/{$model}",
                    'content' => ['parts' => [['text' => $text]]],
                    'taskType' => $task,
                    'outputDimensionality' => self::DIMENSIONS,
                ], array_values($texts)),
            ])->throw();

        $vectors = array_map(function ($row) {
            $values = $row['values'];
            $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $values))) ?: 1.0;

            return array_map(fn ($x) => $x / $norm, $values);
        }, (array) $response->json('embeddings'));

        if (count($vectors) !== count($texts)) {
            throw new \RuntimeException('Embedding count mismatch');
        }

        return $vectors;
    }

    private function model(): string
    {
        return (string) config('chatbot.embedding_model', 'gemini-embedding-001');
    }

    private function dot(array $a, array $b): float
    {
        $sum = 0.0;
        foreach ($a as $i => $value) {
            $sum += $value * $b[$i];
        }

        return $sum;
    }
}
