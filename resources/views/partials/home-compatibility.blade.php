@php
    $groups = \App\Enums\BloodGroup::cases();
    // Red-cell compatibility, the same table the matching engine uses.
    $compat = collect($groups)->mapWithKeys(fn ($g) => [$g->value => array_map(fn ($d) => $d->value, $g->compatibleDonors())]);
    $show = fn (string $g) => str_replace('-', '−', $g);
@endphp

<section class="py-24 bg-white border-t border-slate-100" aria-labelledby="compat-title"
         x-data="{ pick: 'A+', table: @js($compat), get donors() { return this.table[this.pick] } }">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 grid grid-cols-1 lg:grid-cols-12 gap-12 items-start">

        <div class="lg:col-span-5">
            <div class="inline-flex items-center gap-2 bg-red-50 border border-red-100 text-red-600 text-xs font-bold px-4 py-1.5 rounded-full mb-5 scroll-reveal" data-scroll-reveal>রক্তের মিল</div>
            <h2 id="compat-title" class="text-4xl font-black text-slate-900 tracking-tight leading-tight scroll-reveal" data-scroll-reveal>কার শরীরে কার রক্ত চলে</h2>
            <p class="text-slate-500 font-medium text-lg mt-4 leading-relaxed scroll-reveal" data-scroll-reveal>রোগীর গ্রুপ বেছে নিন। দেখবেন কোন কোন গ্রুপের দাতা লোহিত রক্তকণিকা দিতে পারেন।</p>
            <p class="text-slate-500 font-medium text-sm mt-5 leading-relaxed scroll-reveal" data-scroll-reveal>পূর্ণ রক্ত, প্লাটিলেট আর প্লাজমার জন্য রক্তদূত একই গ্রুপের দাতা খোঁজে। রক্ত দেওয়ার আগে চূড়ান্ত মিল (ক্রসম্যাচ) সবসময় হাসপাতাল যাচাই করে।</p>
        </div>

        <div class="lg:col-span-7 bg-slate-50 border border-slate-100 rounded-[2rem] p-5 sm:p-8 shadow-sm scroll-reveal" data-scroll-reveal>
            <p id="compat-patient" class="text-sm font-black text-slate-700">রোগীর গ্রুপ</p>
            <div class="mt-3 grid grid-cols-4 sm:grid-cols-8 gap-2" role="radiogroup" aria-labelledby="compat-patient">
                @foreach($groups as $g)
                    {{-- data-btn-skip: the layout restyles every other button in <main>. --}}
                    <button type="button" data-btn-skip role="radio" :aria-checked="pick === '{{ $g->value }}'" @click="pick = '{{ $g->value }}'"
                            class="h-12 rounded-xl border text-base font-black transition-all duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-400"
                            :class="pick === '{{ $g->value }}' ? 'bg-slate-900 border-slate-900 text-white shadow-md' : 'bg-white border-slate-200 text-slate-800 hover:border-red-300 hover:text-red-600'">{{ $show($g->value) }}</button>
                @endforeach
            </div>

            <p class="mt-8 text-sm font-black text-slate-700">যাঁরা দিতে পারেন</p>
            <div class="mt-3 grid grid-cols-4 gap-2 sm:gap-3">
                @foreach($groups as $g)
                    <div class="flex h-20 sm:h-28 items-end justify-between rounded-2xl border p-2.5 sm:p-3 transition-all duration-300"
                         :class="donors.includes('{{ $g->value }}') ? 'bg-red-600 border-red-600 text-white shadow-[0_10px_25px_rgba(239,68,68,0.3)] -translate-y-1' : 'bg-white border-slate-200 text-slate-400'"
                         :style="{ transitionDelay: Math.max(donors.indexOf('{{ $g->value }}'), 0) * 60 + 'ms' }">
                        <span class="text-xl sm:text-3xl font-black">{{ $show($g->value) }}</span>
                        <svg x-show="donors.includes('{{ $g->value }}')" class="h-4 w-4 sm:h-5 sm:w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                    </div>
                @endforeach
            </div>

            <p class="mt-5 font-bold text-slate-800" aria-live="polite">
                <span x-text="pick.replace('-', '−')">A+</span> গ্রুপের রোগী <span class="text-red-600" x-text="donors.length.toLocaleString('bn-BD') + 'টি'"></span> গ্রুপ থেকে লোহিত কণিকা নিতে পারেন।
            </p>
        </div>
    </div>
</section>
