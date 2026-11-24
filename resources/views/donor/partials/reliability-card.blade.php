@php
    $levels = [
        'new'    => ['label' => 'নতুন ডোনার',   'hint' => 'এখনো যথেষ্ট তথ্য নেই। কয়েকটি অনুরোধে সাড়া দিলে এখানে আপনার চিত্র ফুটে উঠবে।', 'bar' => 'bg-slate-400',   'badge' => 'bg-slate-100 text-slate-700',   'border' => 'border-slate-200'],
        'low'    => ['label' => 'কম সাড়া',     'hint' => 'আপনি সাধারণত অনুরোধে সাড়া দেন না। এতে জরুরি অনুরোধে আপনাকে পরে জানানো হতে পারে।', 'bar' => 'bg-amber-500',   'badge' => 'bg-amber-100 text-amber-800',   'border' => 'border-amber-200'],
        'medium' => ['label' => 'মাঝারি সাড়া', 'hint' => 'আপনি মাঝে মাঝে সাড়া দেন।',                                                          'bar' => 'bg-blue-500',    'badge' => 'bg-blue-100 text-blue-800',     'border' => 'border-blue-200'],
        'high'   => ['label' => 'নির্ভরযোগ্য',  'hint' => 'আপনি প্রায়ই সাড়া দেন। জরুরি অনুরোধে আপনাকে আগে জানানো হয়।',                         'bar' => 'bg-emerald-500', 'badge' => 'bg-emerald-100 text-emerald-800', 'border' => 'border-emerald-200'],
    ];
    $ui = $levels[$reliability['level']] ?? $levels['new'];
    $minutes = $reliability['median_response_minutes'];
    $speed = $minutes === null ? null : ($minutes >= 60 ? intdiv($minutes, 60).' ঘণ্টা '.($minutes % 60).' মিনিট' : $minutes.' মিনিট');
@endphp

<div class="bg-white rounded-3xl border {{ $ui['border'] }} shadow-sm overflow-hidden scroll-reveal" data-scroll-reveal data-testid="reliability-card">
    <div class="px-5 sm:px-6 py-5">
        <div class="flex items-start justify-between gap-3 mb-4">
            <div>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">আপনার সাড়ার ইতিহাস</p>
                <h2 class="text-base font-extrabold text-slate-900 mt-1">অনুরোধে সাড়া দেওয়ার হার</h2>
            </div>
            <span class="text-xs font-black px-3 py-1 rounded-full {{ $ui['badge'] }}">{{ $ui['label'] }}</span>
        </div>

        @if($reliability['notified'] === 0)
            <p class="text-sm text-slate-500 font-medium">এখনো কোনো অনুরোধে আপনাকে জানানো হয়নি।</p>
        @else
            <div class="flex items-end gap-3 mb-2">
                <span class="text-4xl font-black text-slate-900 leading-none">{{ $reliability['score'] }}</span>
                <span class="text-sm font-bold text-slate-400 pb-1">/ ১০০</span>
            </div>
            <div class="h-2 bg-slate-100 rounded-full overflow-hidden mb-3">
                <div class="h-full {{ $ui['bar'] }} rounded-full" style="width: {{ $reliability['score'] }}%"></div>
            </div>
            <p class="text-sm text-slate-600 font-medium mb-4">{{ $ui['hint'] }}</p>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center">
                <div class="rounded-xl bg-emerald-50 p-3">
                    <p class="text-lg font-black text-emerald-700">{{ $reliability['accepted'] }}</p>
                    <p class="text-[11px] font-bold text-emerald-700/70">রাজি হয়েছেন</p>
                </div>
                <div class="rounded-xl bg-slate-50 p-3">
                    <p class="text-lg font-black text-slate-700">{{ $reliability['declined'] }}</p>
                    <p class="text-[11px] font-bold text-slate-500">অপারগতা জানিয়েছেন</p>
                </div>
                <div class="rounded-xl bg-amber-50 p-3">
                    <p class="text-lg font-black text-amber-700">{{ $reliability['ignored'] }}</p>
                    <p class="text-[11px] font-bold text-amber-700/70">সাড়া দেননি</p>
                </div>
                <div class="rounded-xl bg-blue-50 p-3">
                    <p class="text-lg font-black text-blue-700">{{ $speed ?? '—' }}</p>
                    <p class="text-[11px] font-bold text-blue-700/70">সাধারণ সাড়ার সময়</p>
                </div>
            </div>

            <p class="text-[11px] text-slate-400 font-medium mt-4 leading-relaxed">
                অন্য কেউ আগেই রক্ত দিয়ে দেওয়ায় যেসব অনুরোধ আর দরকার ছিল না, সেগুলো এই হিসাবে ধরা হয় না।
                এই তথ্য শুধু আপনি দেখতে পান, রোগীর পরিবার বা অন্য ডোনাররা নয়।
            </p>
        @endif
    </div>
</div>
