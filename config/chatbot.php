<?php

/*
 * Both thresholds say "below this the question is outside the knowledge base". They were
 * chosen after looking at resources/knowledge/eval.json, and the gap they sit in is narrow
 * (for embeddings the weakest answerable question scored about 0.60 and the strongest
 * off-topic one about 0.58). Re-check them against real questions.
 */
return [
    // Passages given to the language model, best first.
    'top_k' => 3,

    // Meaning-based retrieval (needs GEMINI_API_KEY). Set false to keep questions away
    // from the embedding API; keyword retrieval is much weaker on rephrased questions.
    'use_embeddings' => env('CHATBOT_USE_EMBEDDINGS', true),
    'embedding_model' => env('CHATBOT_EMBEDDING_MODEL', 'gemini-embedding-001'),
    'embedding_min_score' => 0.59,

    // Character n-gram fallback.
    'min_score' => 0.08,
];
