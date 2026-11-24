{{--
    "Paste the message, we fill the form". Suggestions only: nothing is submitted, and
    a field the parser was unsure about is left for the person to fill.
    Server side: RequestTextController -> RequestTextAnalyzer (ML service + database).
--}}
<script>
window.requestTextAutofill = function () {
    return {
        text: '',
        loading: false,
        error: '',
        result: null,
        filled: [],

        missingLabels: {
            blood_group: 'রক্তের গ্রুপ',
            units: 'ব্যাগের সংখ্যা',
            urgency: 'জরুরিতা',
            contact_number: 'মোবাইল নম্বর',
            hospital: 'হাসপাতাল',
            district: 'জেলা',
        },

        async fill() {
            this.error = '';
            this.result = null;
            this.filled = [];

            if (this.text.trim().length < 3) {
                this.error = 'আগে রক্তের অনুরোধের বার্তাটি এখানে পেস্ট করুন।';
                return;
            }

            this.loading = true;
            try {
                const res = await fetch(@json(route('requests.parse-text')), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify({ text: this.text }),
                });
                const data = await res.json().catch(() => ({}));

                if (res.status === 429) {
                    this.error = 'অনেকবার চেষ্টা করা হয়েছে। এক মিনিট পরে আবার চেষ্টা করুন।';
                    return;
                }
                if (!res.ok || !data.ok) {
                    this.error = data.message || 'বার্তাটি বোঝা যায়নি। অনুগ্রহ করে ফর্মটি নিজে পূরণ করুন।';
                    return;
                }

                this.apply(data.fields);
                this.result = data.fields;
            } catch (e) {
                this.error = 'সংযোগে সমস্যা হয়েছে। অনুগ্রহ করে ফর্মটি নিজে পূরণ করুন।';
            } finally {
                this.loading = false;
            }
        },

        setField(name, value, label) {
            if (value === null || value === undefined) return;
            const el = this.$root.closest('form')?.querySelector(`[name="${name}"]`);
            if (!el) return;
            if (el.tagName === 'SELECT') {
                const option = Array.from(el.options).find(o => o.value === String(value));
                if (!option || option.disabled) return;
            }
            el.value = String(value);
            el.dispatchEvent(new Event('change', { bubbles: true }));
            el.classList.add('ring-2', 'ring-emerald-400');
            setTimeout(() => el.classList.remove('ring-2', 'ring-emerald-400'), 5000);
            this.filled.push(label);
        },

        apply(f) {
            this.setField('blood_group', f.blood_group, 'রক্তের গ্রুপ');
            this.setField('component_type', f.component_type, 'রক্তের ধরন');
            this.setField('bags_needed', f.units, 'ব্যাগ');
            this.setField('urgency', f.urgency, 'জরুরিতা');
            this.setField('contact_number', f.contact_number, 'মোবাইল নম্বর');

            if (f.hospital) {
                window.dispatchEvent(new CustomEvent('rd-set-hospital', { detail: f.hospital }));
                this.filled.push('হাসপাতাল');
            }
            if (f.division_id && f.district_id) {
                document.dispatchEvent(new CustomEvent('rd:set-location', {
                    detail: { division_id: f.division_id, district_id: f.district_id },
                }));
                this.filled.push('বিভাগ ও জেলা');
            }
        },

        get missingText() {
            if (!this.result) return '';
            return (this.result.missing || []).map(k => this.missingLabels[k]).filter(Boolean).join(', ');
        },
    };
};
</script>

<div x-data="requestTextAutofill()" class="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4 sm:p-5" data-testid="fill-from-message">
    <div class="flex items-start justify-between gap-3">
        <div>
            <p class="text-sm font-extrabold text-emerald-900">বার্তা থেকে স্বয়ংক্রিয়ভাবে পূরণ করুন <span class="text-emerald-500 font-semibold text-xs">(ঐচ্ছিক)</span></p>
            <p class="text-xs text-emerald-700 font-medium mt-0.5">ফেসবুক, মেসেঞ্জার বা এসএমএসের রক্তের অনুরোধটি এখানে পেস্ট করুন। আমরা ঘরগুলো পূরণ করে দেব, আপনি যাচাই করে জমা দেবেন।</p>
        </div>
    </div>

    <textarea x-model="text" rows="3" maxlength="2000"
              aria-label="রক্তের অনুরোধের বার্তা"
              placeholder="যেমন: জরুরি ভিত্তিতে ও পজিটিভ রক্ত লাগবে ২ ব্যাগ, ঢাকা মেডিকেল, যোগাযোগ ০১৭XXXXXXXX"
              class="mt-3 w-full rounded-xl border-emerald-200 bg-white focus:border-emerald-500 focus:ring-emerald-500 font-medium px-4 py-3 text-sm"></textarea>

    <div class="mt-3 flex flex-wrap items-center gap-3">
        <button type="button" @click="fill()" :disabled="loading"
                class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-60 text-white text-sm font-bold px-4 py-2.5 rounded-xl transition-colors">
            <span x-show="!loading">ঘরগুলো পূরণ করুন</span>
            <span x-show="loading">বোঝার চেষ্টা চলছে…</span>
        </button>
        <span class="text-[11px] text-emerald-700/80 font-medium">বার্তাটি শুধু ফর্ম পূরণের জন্য ব্যবহার হয়, সংরক্ষণ করা হয় না।</span>
    </div>

    <p x-show="error" x-text="error" class="mt-3 text-sm font-bold text-red-600"></p>

    <template x-if="result">
        <div class="mt-3 space-y-1.5 text-sm">
            <p x-show="filled.length" class="font-bold text-emerald-800">
                পূরণ করা হয়েছে: <span x-text="filled.join(', ')"></span>
            </p>
            <p x-show="!result.blood_group" class="font-bold text-red-600">
                রক্তের গ্রুপ নিশ্চিতভাবে বোঝা যায়নি (দুটি গ্রুপ লেখা, বা পজিটিভ/নেগেটিভ উল্লেখ নেই)। অনুগ্রহ করে নিজে বেছে নিন।
            </p>
            <p x-show="missingText" class="text-slate-600 font-medium">
                বার্তায় পাওয়া যায়নি: <span x-text="missingText"></span>
            </p>
            <p class="text-amber-700 font-bold">জমা দেওয়ার আগে প্রতিটি তথ্য মিলিয়ে নিন।</p>
        </div>
    </template>
</div>
