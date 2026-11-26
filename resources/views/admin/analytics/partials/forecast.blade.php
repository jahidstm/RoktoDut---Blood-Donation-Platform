@php
    /** @var array|null $forecast */
    $bnF = fn ($v) => \App\Support\BanglaDate::digits((string) $v);
    $round = fn ($v) => $bnF((int) round((float) $v));
@endphp

<div class="mt-6 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" data-testid="forecast">
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div>
            <h2 class="text-lg font-extrabold text-slate-900">আগামী সপ্তাহগুলোর চাহিদার পূর্বাভাস</h2>
            <p class="text-sm text-slate-500 font-medium mt-1">ক্যাম্প বা ডোনার জোগাড়ের পরিকল্পনার জন্য: কোন বিভাগে কোন গ্রুপের রক্ত কত লাগতে পারে।</p>
        </div>
        @if ($forecast && ($forecast['status'] ?? '') === 'ok')
            <span class="shrink-0 rounded-full px-3 py-1 text-xs font-extrabold {{ $forecast['is_model'] ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-700 border border-slate-200' }}">
                পদ্ধতি: {{ $forecast['method_label'] }}
            </span>
        @endif
    </div>

    @if (! $forecast)
        <div class="mt-4 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-600">
            এখনো কোনো পূর্বাভাস তৈরি হয়নি। ML সার্ভিস ফোল্ডারে চালান:
            <code class="block mt-2 text-xs bg-white border border-slate-200 rounded-lg p-2">python scripts/forecast_demand.py --input ../storage/app/analytics/requests_flat.csv</code>
            <span class="block mt-1 text-xs">(আগে <code>php artisan analytics:export</code> দিয়ে ফাইলটি বানাতে হবে।)</span>
        </div>
    @elseif (($forecast['status'] ?? '') !== 'ok')
        <div class="mt-4 rounded-2xl border border-slate-300 bg-slate-50 p-4 text-sm text-slate-700">
            পূর্বাভাস দেওয়ার মতো যথেষ্ট ইতিহাস নেই: {{ $bnF($forecast['weeks'] ?? 0) }} সপ্তাহের ডেটা আছে, অন্তত {{ $bnF($forecast['needed_weeks'] ?? 40) }} সপ্তাহ দরকার।
            সংখ্যা বানিয়ে দেখানোর চেয়ে কিছু না দেখানো নিরাপদ।
        </div>
    @else
        @if ($forecast['is_simulated'])
            <div class="mt-4 rounded-2xl border-2 border-amber-300 bg-amber-50 p-3 text-sm font-semibold text-amber-900" role="note">
                ⚠ এই পূর্বাভাস <strong>সিমুলেটেড ইতিহাস</strong> থেকে বানানো, আসল রিকোয়েস্ট থেকে নয়। এটা শুধু দেখায় পদ্ধতিটা কীভাবে কাজ করে; পরিকল্পনায় ব্যবহার করবেন না।
            </div>
        @endif
        @if ($forecast['is_stale'])
            <div class="mt-3 rounded-2xl border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-800" role="note">
                এই পূর্বাভাস পুরোনো: যে সপ্তাহগুলোর জন্য বানানো, সেগুলো পেরিয়ে গেছে। স্ক্রিপ্টটি আবার চালান।
            </div>
        @endif

        <div class="mt-5 grid grid-cols-1 xl:grid-cols-5 gap-6">
            <div class="xl:col-span-3">
                <p class="text-xs font-extrabold text-slate-500 mb-2">দেশজুড়ে সাপ্তাহিক রিকোয়েস্ট: গত সপ্তাহগুলো ও পূর্বাভাস</p>
                <div class="h-[260px]"><canvas id="forecast-chart" role="img" aria-label="সাপ্তাহিক রিকোয়েস্টের ইতিহাস ও পূর্বাভাস"></canvas></div>
                <p class="mt-2 text-xs text-slate-500">
                    @if ($forecast['intervals']['national']['reliable'])
                        ছায়া অংশ: ৮০% সম্ভাব্য সীমা। আগের পরীক্ষায় আসল সংখ্যা এই সীমার ভেতরে ছিল {{ $bnF(round($forecast['intervals']['national']['coverage_on_judging_folds'] * 100)) }}% বার।
                    @else
                        দেশজুড়ে সংখ্যার সম্ভাব্য সীমা দেখানো হচ্ছে না: আগের পরীক্ষায় সীমাটি আসল সংখ্যা মাত্র {{ $bnF(round($forecast['intervals']['national']['coverage_on_judging_folds'] * 100)) }}% বার ধরতে পেরেছিল (লক্ষ্য ৮০%)।
                    @endif
                </p>
            </div>

            <div class="xl:col-span-2">
                <p class="text-xs font-extrabold text-slate-500 mb-2">সবচেয়ে বেশি চাহিদা আশা করা হচ্ছে</p>
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-slate-500"><th class="py-1.5">বিভাগ · গ্রুপ</th><th>আগামী সপ্তাহ</th><th>৪ সপ্তাহে</th><th>গত ৪ সপ্তাহে</th></tr></thead>
                    <tbody>
                    @foreach (array_slice($forecast['top_series'], 0, 8) as $s)
                        <tr class="border-t border-slate-100">
                            <td class="py-1.5 font-bold">{{ $s['division'] }} · {{ $s['blood_group'] }}</td>
                            <td>
                                {{ $round($s['next_week']['mean']) }}
                                @if ($forecast['intervals']['series']['reliable'])
                                    <span class="text-xs text-slate-400">({{ $round($s['next_week']['lo']) }}–{{ $round($s['next_week']['hi']) }})</span>
                                @endif
                            </td>
                            <td>{{ $round($s['four_week_total']) }}</td>
                            <td class="text-slate-500">{{ $bnF($s['last_four_weeks']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <details class="mt-5 text-xs text-slate-600">
            <summary class="cursor-pointer font-bold text-slate-700">পদ্ধতিটা কতটা নির্ভরযোগ্য?</summary>
            <div class="mt-2 space-y-1.5">
                <p>
                    পেছনের {{ $bnF($forecast['backtest']['folds']) }}টি সময়ে "অতীতে দাঁড়িয়ে পরের ৪ সপ্তাহ অনুমান" করে আসল সংখ্যার সাথে মেলানো হয়েছে।
                    @if ($forecast['promoted'])
                        মডেলটি সহজ পদ্ধতিগুলোর চেয়ে স্পষ্টভাবে ভালো করেছে, তাই মডেলের অনুমান দেখানো হচ্ছে।
                    @else
                        কোনো মডেল সহজ পদ্ধতির চেয়ে নিশ্চিতভাবে ভালো করেনি, তাই সহজ পদ্ধতিটাই ({{ $forecast['method_label'] }}) ব্যবহার করা হয়েছে।
                    @endif
                </p>
                @php $j = $forecast['backtest']['judging'][$forecast['method_used']] ?? null; @endphp
                @if ($j)
                    <p>বিভাগ ও গ্রুপ পর্যায়ে গড় ভুল মোট রিকোয়েস্টের প্রায় {{ $bnF(round($j['wape'] * 100)) }}%; দেশজুড়ে মোট সংখ্যায় প্রায় {{ $bnF(round($j['national_wape'] * 100)) }}%। ছোট সংখ্যার ঘরে অনিশ্চয়তা অনেক বেশি।</p>
                @endif
                <p>তৈরি: {{ $forecast['generated_at'] }}। শেষ পূর্ণ সপ্তাহ: {{ $forecast['last_complete_week'] }}।</p>
            </div>
        </details>

        @push('scripts')
        <script>
        (function () {
            const f = @json($forecast['national']);
            const showBand = @json((bool) $forecast['intervals']['national']['reliable']);
            const bn = s => String(s).replace(/\d/g, d => '০১২৩৪৫৬৭৮৯'[d]);
            const labels = [...f.history.map(h => h.week), ...f.forecast.map(p => p.week)].map(w => bn(w.slice(5)));
            const pad = n => Array(n).fill(null);
            const last = f.history[f.history.length - 1];
            const fc = [...pad(f.history.length - 1), last.y, ...f.forecast.map(p => p.mean)];
            const datasets = [
                { label: 'আসল', data: [...f.history.map(h => h.y), ...pad(f.forecast.length)], borderColor: '#0f172a', backgroundColor: '#0f172a', tension: .25, pointRadius: 2 },
                { label: 'পূর্বাভাস', data: fc, borderColor: '#dc2626', backgroundColor: '#dc2626', borderDash: [6, 4], tension: .25, pointRadius: 2 },
            ];
            if (showBand) {
                datasets.push(
                    { label: '৮০% সীমা', data: [...pad(f.history.length - 1), last.y, ...f.forecast.map(p => p.hi)], borderWidth: 0, pointRadius: 0, backgroundColor: 'rgba(220,38,38,.12)', fill: '+1' },
                    { label: '_lo', data: [...pad(f.history.length - 1), last.y, ...f.forecast.map(p => p.lo)], borderWidth: 0, pointRadius: 0, fill: false },
                );
            }
            const draw = () => {
                const el = document.getElementById('forecast-chart');
                if (!el || typeof Chart === 'undefined') return;
                new Chart(el, {
                    type: 'line',
                    data: { labels, datasets },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        scales: { y: { beginAtZero: true, grid: { color: '#f1f5f9' } }, x: { grid: { display: false } } },
                        plugins: { legend: { labels: { filter: i => !i.text.startsWith('_') } } },
                    },
                });
            };
            document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', draw) : draw();
        })();
        </script>
        @endpush
    @endif
</div>
