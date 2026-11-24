# Blood-request text: evaluation data

Two hand-labelled sets for the request parser (`/api/v2/parse-request`) and the
Laravel hospital/district resolver.

| File | Rows | Use |
|---|---|---|
| `dev.jsonl` | 40 | Looked at while writing the rules. Scores on it are optimistic. |
| `test.jsonl` | 80 | Held out. Committed **before** the parser was written; only scored at the end. |

## Honesty notes

- **Synthetic.** Every message was written by the project author (with AI assistance) in the
  style of Bangladeshi blood-request posts. None is a real request. Real messages are messier
  (typos, emoji, forwarded chains, several requests in one post), so real-world accuracy will be lower.
- **Same author as the rules.** Writing the test set before the rules limits, but does not remove,
  the bias of one person writing both. The single most useful improvement is a set of real,
  anonymised messages labelled by someone else.
- **Phone numbers are fake placeholders** (`017000000NN`, `017110001NN`, ...). They follow the
  Bangladeshi mobile format so the parser can be tested, but they are not anyone's contact.
- Hospital labels use names from `database/seeders/HospitalSeeder.php`; a hospital that is not in
  that list is labelled `null` even if the text names one (e.g. "CMH", "Evercare Chattogram").

## Labelling rules (the spec)

| Field | Rule |
|---|---|
| `blood_group` | One of `A+ A- B+ B- AB+ AB- O+ O-` only when the text states both ABO **and** Rh. `null` when missing, when Rh is missing ("B group"), or when two different groups are given ("A+ or O+"). The same group repeated is fine. |
| `units` | Number of bags / units / pints / donors (`২ জন ডোনার` = 2). `null` when not stated. Millilitres, ages, dates and Hb values are not units. |
| `component_type` | `whole_blood`, `packed_rbc`, `platelets`, `plasma` only when named explicitly; otherwise `null`. |
| `urgency` | `emergency`: emergency / ইমার্জেন্সি, "very urgent" (অতি / খুব / খুবই / অত্যন্ত জরুরি), now (এখনই, এক্ষুনি, এখুনি, immediately, asap). `urgent`: জরুরি / জরুরী / আর্জেন্ট / urgent(ly), or same day (আজ, আজকে, আজই, today, tonight, ajke). Otherwise `null`; tomorrow and later are `null`, and nothing is ever guessed as `normal`. |
| `contact_number` | First Bangladeshi mobile number, normalised to `01XXXXXXXXX`. |
| `hospital` | Canonical English name from the seeder, or `null`. |
| `district` | Bangla district name; if a known hospital is named, its district. `null` when neither a district nor a known hospital is mentioned (an area like "Mirpur" is not a district). |

Each line: `{"id", "text", "labels": {blood_group, units, component_type, urgency, contact_number, hospital, district}}`.
