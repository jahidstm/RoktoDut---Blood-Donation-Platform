# Chatbot (retrieval-augmented)

Answers questions about blood donation and how to use RoktoDut, from a small knowledge base,
and shows which passage it used. It does not diagnose.

## How a question is handled

```
question
  -> safety rules        emergency or "can I donate with MY condition?" -> fixed answer, nothing sent anywhere
  -> retrieval           Gemini embeddings; character n-gram TF-IDF if no key or the API fails
  -> below threshold     "I don't have reliable information"
  -> generation          Gemini, told to use only the retrieved passages
  -> no key / API error  the closest passage is quoted, worded as "this may help"
```

| Part | Where |
|---|---|
| Knowledge base | `resources/knowledge/*.md`; donation intervals are generated from `config/eligibility.php` |
| Retrieval | `app/Services/Chatbot/EmbeddingRetriever.php`, `Retriever.php` |
| Safety, masking | `SafetyGuard.php`, `PiiMasker.php` |
| Orchestration | `ChatbotAssistant.php` |
| Thresholds | `config/chatbot.php` |
| Evaluation | `resources/knowledge/eval.json`, `php artisan chatbot:eval [--keyword] [--misses]` |

There is no vector database or LangChain. About twenty passages fit in a cached array; a
dot product over them is instant. Add a vector store only if the corpus grows by orders of
magnitude.

## Privacy

Sent to Google when a key is set: the retrieved passages, public blood-bank stock (for stock
questions), and the user's own current and last three questions with emails and long numbers
masked. **Not sent:** name, blood group, district, donation history (the previous version sent
all of these). Names or addresses typed as ordinary words are not detected. Google's free tier
may use submitted content to improve its products, so the widget should not be treated as
private. `CHATBOT_USE_EMBEDDINGS=false` stops the embedding calls; leaving `GEMINI_API_KEY`
empty stops all calls.

## Evaluation

All questions are written by the author. Retrieval only; generated text is not scored.

| Measure | n | Keyword n-grams | Gemini embeddings |
|---|---|---|---|
| Original questions, hit@1 | 30 | 100% | 90% |
| Original questions, hit@3 | 30 | 100% | 100% |
| **Rephrased questions, hit@1** | 15 | **40%** | **100%** |
| Personal-medical refused | 10 | 100% | (rules, same) |
| Emergency caught | 3 | 100% | (rules, same) |
| Off-topic declined | 5 | 80% | 100% |

How to read this:

- The keyword retriever's 100% on the original set is **misleading**: its passages' keyword
  lines and those questions were written together. The rephrased set, added afterwards and
  not tuned against, is why embeddings were chosen.
- The embedding threshold (0.59) was picked after looking at these scores, in a narrow gap
  (weakest answerable about 0.60, strongest off-topic about 0.58), so "off-topic declined"
  is optimistic. The model is also instructed to say when the passages do not answer.
- 100% refusal means the 10 listed questions are caught. Keyword safety rules will miss
  phrasings nobody thought of.
- Real accuracy needs real user questions. None are logged today.

## Content that still needs a human decision

- The public FAQ page (`resources/views/pages/faq.blade.php`) disagrees with the code: it says
  120 days after whole blood, 14 after plasma and 7 after platelets; `config/eligibility.php`
  uses 90 (male) / 120 (female), 28 and 14. The chatbot uses the config. Neither has been
  checked against a medical guideline.
- The FAQ also says registration is needed to request blood; the app lets guests request.
- The age, weight and haemoglobin thresholds come from the FAQ and are not verified by a
  transfusion authority. Each answer built on them names that source.
