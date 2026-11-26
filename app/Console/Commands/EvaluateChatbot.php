<?php

namespace App\Console\Commands;

use App\Services\Chatbot\ChatbotAssistant;
use App\Services\Chatbot\EmbeddingRetriever;
use App\Services\Chatbot\Retriever;
use App\Services\Chatbot\SafetyGuard;
use Illuminate\Console\Command;

/** Scores retrieval and the safety rules on resources/knowledge/eval.json. No text is generated. */
class EvaluateChatbot extends Command
{
    protected $signature = 'chatbot:eval {--misses : List the questions that failed}
                            {--keyword : Force keyword retrieval (no API calls)}';

    protected $description = 'Evaluate chatbot retrieval and safety rules (no text generation)';

    public function handle(Retriever $retriever, SafetyGuard $guard, ChatbotAssistant $assistant): int
    {
        if ($this->option('keyword')) {
            config(['chatbot.use_embeddings' => false]);
        }

        $this->info('Retriever: '.(app(EmbeddingRetriever::class)->available() ? 'Gemini embeddings (calls the API)' : 'character n-grams'));
        $r = $this->evaluate($retriever, $guard, $assistant);

        $this->table(['measure', 'score'], [
            ["retrieval hit@1 ({$r['n']['answerable']} questions)", sprintf('%.1f%%', 100 * $r['hit1'])],
            ['retrieval hit@3', sprintf('%.1f%%', 100 * $r['hit3'])],
            ['mean reciprocal rank', sprintf('%.3f', $r['mrr'])],
            ["rephrased questions hit@1 ({$r['n']['holdout']})", sprintf('%.1f%%', 100 * $r['holdout_hit1'])],
            ["personal-medical refused ({$r['n']['refuse']})", sprintf('%.1f%%', 100 * $r['refused'])],
            ["emergency caught ({$r['n']['emergency']})", sprintf('%.1f%%', 100 * $r['emergency'])],
            ["off-topic declined ({$r['n']['unknown']})", sprintf('%.1f%%', 100 * $r['unknown'])],
        ]);

        if ($this->option('misses')) {
            foreach ($r['misses'] as $miss) {
                $this->line($miss);
            }
        }

        $this->warn('The questions are author-written: this checks the rules work as intended, not real-user accuracy.');

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    public function evaluate(Retriever $retriever, SafetyGuard $guard, ChatbotAssistant $assistant): array
    {
        $set = json_decode((string) file_get_contents(resource_path('knowledge/eval.json')), true, 512, JSON_THROW_ON_ERROR);
        $misses = [];
        $hit1 = $hit3 = 0;
        $mrr = 0.0;

        foreach ($set['answerable'] as [$question, $expected]) {
            // Through the same path as a real question: guard first, then thresholded retrieval.
            $ids = $guard->check($question) ? [] : array_column($assistant->retrieve($question), 'id');
            $rank = array_search($expected, $ids, true);
            $hit1 += $rank === 0 ? 1 : 0;
            $hit3 += $rank !== false && $rank < 3 ? 1 : 0;
            $mrr += $rank === false ? 0 : 1 / ($rank + 1);
            if ($rank !== 0) {
                $misses[] = "retrieval: {$question} | want {$expected} | got ".implode(',', $ids ?: ['-']);
            }
        }

        $share = function (string $key, callable $ok) use ($set, &$misses): float {
            $good = 0;
            foreach ($set[$key] as $question) {
                if ($ok($question)) {
                    $good++;
                } else {
                    $misses[] = "{$key}: {$question}";
                }
            }

            return $good / count($set[$key]);
        };

        $holdout = 0;
        foreach ($set['holdout'] as [$question, $expected]) {
            $ids = $guard->check($question) ? [] : array_column($assistant->retrieve($question), 'id');
            if (($ids[0] ?? null) === $expected) {
                $holdout++;
            } else {
                $misses[] = "holdout: {$question} | want {$expected} | got ".implode(',', $ids ?: ['-']);
            }
        }

        $n = count($set['answerable']);

        return [
            'n' => array_map('count', array_intersect_key($set, array_flip(['answerable', 'holdout', 'refuse', 'emergency', 'unknown']))),
            'hit1' => $hit1 / $n,
            'hit3' => $hit3 / $n,
            'mrr' => $mrr / $n,
            'holdout_hit1' => $holdout / count($set['holdout']),
            'refused' => $share('refuse', fn ($q) => $guard->check($q) === SafetyGuard::PERSONAL_MEDICAL),
            'emergency' => $share('emergency', fn ($q) => $guard->check($q) === SafetyGuard::EMERGENCY),
            'unknown' => $share('unknown', fn ($q) => ! $guard->check($q) && ! $assistant->retrieve($q)),
            'misses' => $misses,
        ];
    }
}
