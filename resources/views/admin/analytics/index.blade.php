@extends('layouts.admin-dashboard')

@section('title', 'অ্যানালিটিক্স ড্যাশবোর্ড — রক্তদূত অ্যাডমিন')

@push('head')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1"></script>
@endpush

@section('content')
@php
    use App\Support\BanglaDate;
    use App\Services\Analytics\DemandAnalytics;

    $bn = fn ($v) => BanglaDate::digits((string) $v);
    $pct = fn ($v) => $v === null ? '—' : $bn(round($v * 100)) . '%';
    $hrs = fn ($v) => $v === null ? '—' : $bn(rtrim(rtrim(number_format($v, 1), '0'), '.')) . ' ঘণ্টা';

    $kpi = $data['kpi'];
    $resp = $data['donor_response'];
    $isDemo = $source !== DemandAnalytics::SOURCE_REAL;

    $tabs = [
        DemandAnalytics::SOURCE_REAL => 'আসল ডেটা',
        DemandAnalytics::SOURCE_DEMO => 'ডেমো ডেটা',
        DemandAnalytics::SOURCE_ALL => 'দুটোই',
    ];

    $chartData = [
        'weekly' => $data['weekly'],
        'groups' => $data['by_blood_group'],
        'districts' => $data['by_district'],
    ];
@endphp

<div data-panel-id="analytics" class="max-w-7xl mx-auto px-4 py-8"
     x-data="analyticsDashboard(@js($chartData))" x-init="initCharts()">

    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900 flex items-center gap-2">
                <span class="w-9 h-9 rounded-xl bg-red-100 text-red-600 flex items-center justify-center text-lg">📊</span>
                অ্যানালিটিক্স ড্যাশবোর্ড
            </h1>
            <p class="mt-1 text-slate-500 text-sm font-semibold">
                রক্তের চাহিদা, পূরণের হার ও ডোনারের সাড়া। সময়কাল: {{ $bn($data['window']['from']) }} থেকে {{ $bn($data['window']['to']) }}।
            </p>
        </div>
        <a href="{{ route('admin.analytics.export') }}"
           class="inline-flex items-center justify-center gap-2 bg-slate-900 hover:bg-slate-800 text-white font-extrabold px-5 py-3 rounded-xl shadow-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 focus-visible:ring-offset-2">
            <span>⬇</span> রিপোর্ট ডাউনলোড করুন (CSV)
        </a>
    </div>

    {{-- Which data the page is showing --}}
    <div class="mb-4 inline-flex rounded-xl border border-slate-200 bg-white p-1 text-sm font-extrabold" role="tablist">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('admin.analytics.index', ['source' => $key]) }}"
               class="px-4 py-2 rounded-lg {{ $source === $key ? 'bg-slate-900 text-white' : 'text-slate-600 hover:text-slate-900' }}">{{ $label }}</a>
        @endforeach
    </div>

    @if ($isDemo)
        <div class="mb-6 rounded-2xl border-2 border-amber-300 bg-amber-50 p-4 text-sm font-semibold text-amber-900" role="note">
            <strong>⚠ ডেমো ডেটা।</strong>
            @if ($source === DemandAnalytics::SOURCE_ALL)
                এখানে আসল ও বানানো (কৃত্রিম) রিকোয়েস্ট মিশে আছে।
            @else
                এই পাতার রিকোয়েস্টগুলো কম্পিউটারে বানানো, আসল কোনো রোগীর নয়।
            @endif
            সংখ্যাগুলো ধরে নেওয়া অনুমান থেকে তৈরি, তাই এগুলো বাস্তব সিদ্ধান্তের জন্য নয়, শুধু ড্যাশবোর্ড কীভাবে কাজ করে তা দেখানোর জন্য।
            @if ($autoDemo)
                আসল রিকোয়েস্ট এখন মাত্র {{ $bn($realCount) }}টি (ন্যূনতম {{ $bn($minReal) }}টি হলে আসল ডেটা দেখানো হয়)।
            @endif
        </div>
    @elseif ($kpi['requests'] < $minReal)
        <div class="mb-6 rounded-2xl border border-slate-300 bg-slate-50 p-4 text-sm font-semibold text-slate-700" role="note">
            আসল রিকোয়েস্ট মাত্র {{ $bn($kpi['requests']) }}টি। এই সংখ্যায় শতাংশ ও গড় ভরসাযোগ্য নয়।
        </div>
    @endif

    <div class="grid grid-cols-2 xl:grid-cols-5 gap-4 mb-8">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs font-extrabold text-slate-500">রিকোয়েস্ট</p>
            <p class="mt-2 text-3xl font-black text-slate-900">{{ $bn($kpi['requests']) }}</p>
            <p class="text-xs text-slate-500 mt-1">{{ $bn($kpi['bags']) }} ব্যাগ রক্ত</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs font-extrabold text-slate-500">পূরণের হার</p>
            <p class="mt-2 text-3xl font-black text-emerald-600">{{ $pct($kpi['fulfilment_rate']) }}</p>
            <p class="text-xs text-slate-500 mt-1">{{ $bn($kpi['mature_requests']) }}টি রিকোয়েস্টের মধ্যে, যেগুলো ৭ দিনের বেশি পুরোনো</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs font-extrabold text-slate-500">ডোনার পেতে মধ্যম সময়</p>
            <p class="mt-2 text-3xl font-black text-slate-900">{{ $hrs($kpi['median_hours_to_fulfil']) }}</p>
            <p class="text-xs text-slate-500 mt-1">৯০% রিকোয়েস্ট: {{ $hrs($kpi['p90_hours_to_fulfil']) }} (মোট {{ $bn($kpi['timed_requests']) }}টি থেকে)</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs font-extrabold text-slate-500">ডোনারের সাড়ার হার</p>
            <p class="mt-2 text-3xl font-black text-red-600">{{ $pct($resp['accept_rate']) }}</p>
            <p class="text-xs text-slate-500 mt-1">{{ $bn($resp['answered']) }}টি নোটিফিকেশনের মধ্যে {{ $bn($resp['accepted']) }}টিতে রাজি</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm col-span-2 xl:col-span-1">
            <p class="text-xs font-extrabold text-slate-500">নিবন্ধিত ডোনার</p>
            <p class="mt-2 text-3xl font-black text-slate-900">{{ $bn($totalDonors) }}</p>
            <p class="text-xs text-slate-500 mt-1">সিস্টেমে সক্রিয় থাকা সব ডোনার, আজ যোগ্য কি না ধরা হয়নি</p>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-extrabold text-slate-900">সাপ্তাহিক রিকোয়েস্ট</h2>
            <p class="text-sm text-slate-500 font-medium mt-1">প্রতি সপ্তাহে কতগুলো এলো, তার মধ্যে কতগুলো পূরণ হলো (শেষ কয়েক সপ্তাহের সংখ্যা কম দেখাতে পারে, কারণ সেগুলো এখনো চলমান)</p>
            <div class="mt-5 h-[300px]"><canvas x-ref="weekly" aria-label="সাপ্তাহিক রিকোয়েস্টের লাইন চার্ট" role="img"></canvas></div>
        </div>

        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-extrabold text-slate-900">চাহিদা বনাম ডোনার, রক্তের গ্রুপ অনুযায়ী</h2>
            <p class="text-sm text-slate-500 font-medium mt-1">চাহিদার অংশ ডোনারের অংশের চেয়ে অনেক বেশি হলে ওই গ্রুপে ডোনার জোগাড়ে জোর দেওয়া দরকার</p>
            <div class="mt-5 h-[300px]"><canvas x-ref="groups" aria-label="রক্তের গ্রুপ অনুযায়ী চাহিদা ও ডোনারের তুলনা" role="img"></canvas></div>
        </div>

        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-extrabold text-slate-900">সবচেয়ে বেশি চাহিদার ১০টি জেলা</h2>
            <div class="mt-5 h-[320px]"><canvas x-ref="districts" aria-label="জেলাভিত্তিক রিকোয়েস্ট" role="img"></canvas></div>
        </div>

        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-extrabold text-slate-900">গ্রুপ অনুযায়ী পূরণের হার</h2>
            <p class="text-sm text-slate-500 font-medium mt-1">শুধু ৭ দিনের বেশি পুরোনো রিকোয়েস্ট ধরে</p>
            <table class="mt-4 w-full text-sm">
                <thead><tr class="text-left text-slate-500 text-xs"><th class="py-2">গ্রুপ</th><th>রিকোয়েস্ট</th><th>পূরণের হার</th><th>নিবন্ধিত ডোনার</th></tr></thead>
                <tbody>
                @foreach ($data['by_blood_group'] as $row)
                    <tr class="border-t border-slate-100">
                        <td class="py-2 font-extrabold">{{ $row['group'] }}</td>
                        <td>{{ $bn($row['requests']) }}</td>
                        <td>{{ $pct($row['fulfilment_rate']) }} <span class="text-xs text-slate-400">(n={{ $bn($row['mature_requests']) }})</span></td>
                        <td>{{ $bn($row['donors']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-extrabold text-slate-900">বিভাগ অনুযায়ী</h2>
        <div class="mt-3 grid grid-cols-2 md:grid-cols-4 gap-3">
            @foreach ($data['by_division'] as $row)
                <div class="rounded-xl bg-slate-50 p-3">
                    <p class="text-xs font-bold text-slate-500">{{ $row['name'] }}</p>
                    <p class="text-xl font-black text-slate-900">{{ $bn($row['requests']) }}</p>
                </div>
            @endforeach
        </div>
    </div>

    <p class="mt-6 text-xs text-slate-500 font-semibold">
        উপরের সব সংখ্যা গণনা করা বর্ণনামূলক হিসাব, কোনো পূর্বাভাস নয়। পূর্বাভাস নিচে আলাদা করে দেখানো হলো।
    </p>

    @include('admin.analytics.partials.forecast')

    {{-- Heatmap link --}}
    <div class="mt-8">
        <div class="rounded-3xl border border-teal-200 bg-teal-50/30 p-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 shadow-sm">
            <div>
                <h2 class="text-lg font-extrabold text-slate-900">জেলাভিত্তিক হীটম্যাপ</h2>
                <p class="text-slate-600 font-medium mt-1 text-sm max-w-2xl">
                    জেলার রক্তের চাহিদা ও ডোনারের ক্লান্তির সূচক মিলিয়ে একটি স্কোর দেখায়। স্কোরটি হাতে ঠিক করা ওজনে (০.৬ ও ০.৪) বানানো একটি হিউরিস্টিক সূচক, প্রশিক্ষিত মডেল নয়।
                </p>
            </div>
            <a href="{{ route('admin.heatmap.index') }}" class="inline-flex items-center gap-2 bg-teal-600 hover:bg-teal-700 text-white font-extrabold px-6 py-3 rounded-xl shadow-md transition shrink-0">হীটম্যাপ খুলুন</a>
        </div>
    </div>

    <div class="mt-6">
        <a href="{{ route('admin.dashboard') }}" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-red-600 transition">← অ্যাডমিন ড্যাশবোর্ডে ফিরে যান</a>
    </div>
</div>
@endsection

@push('scripts')
<script>
function analyticsDashboard(d) {
    const font = { family: 'Hind Siliguri' };
    const grid = { color: '#f1f5f9' };
    const bnDigits = n => String(n).replace(/\d/g, c => '০১২৩৪৫৬৭৮৯'[c]);

    return {
        initCharts() {
            if (typeof Chart === 'undefined') return;

            new Chart(this.$refs.weekly, {
                type: 'line',
                data: {
                    labels: d.weekly.map(w => bnDigits(w.week_start.slice(5))),
                    datasets: [
                        { label: 'এলো', data: d.weekly.map(w => w.created), borderColor: '#dc2626', backgroundColor: '#dc2626', tension: .25 },
                        { label: 'পূরণ হলো', data: d.weekly.map(w => w.fulfilled), borderColor: '#059669', backgroundColor: '#059669', tension: .25 },
                    ],
                },
                options: { responsive: true, maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, ticks: { precision: 0, font }, grid }, x: { ticks: { font }, grid: { display: false } } },
                    plugins: { legend: { labels: { font } } } },
            });

            new Chart(this.$refs.groups, {
                type: 'bar',
                data: {
                    labels: d.groups.map(g => g.group),
                    datasets: [
                        { label: 'চাহিদার অংশ (%)', data: d.groups.map(g => +(g.demand_share * 100).toFixed(1)), backgroundColor: '#dc2626', borderRadius: 6 },
                        { label: 'ডোনারের অংশ (%)', data: d.groups.map(g => +(g.donor_share * 100).toFixed(1)), backgroundColor: '#64748b', borderRadius: 6 },
                    ],
                },
                options: { responsive: true, maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, ticks: { font }, grid }, x: { ticks: { font }, grid: { display: false } } },
                    plugins: { legend: { labels: { font } } } },
            });

            new Chart(this.$refs.districts, {
                type: 'bar',
                data: {
                    labels: d.districts.map(x => x.name),
                    datasets: [{ label: 'রিকোয়েস্ট', data: d.districts.map(x => x.requests), backgroundColor: '#dc2626', borderRadius: 6 }],
                },
                options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                    scales: { x: { beginAtZero: true, ticks: { precision: 0, font }, grid }, y: { ticks: { font }, grid: { display: false } } },
                    plugins: { legend: { display: false } } },
            });
        },
    };
}
</script>
@endpush
