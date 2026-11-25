@extends('layouts.admin-dashboard')

@section('title', 'সন্দেহজনক রিকোয়েস্ট রিভিউ — রক্তদূত অ্যাডমিন')

@section('content')
@php
    $bn = fn ($v) => \App\Support\BanglaDate::digits((string) $v);
@endphp
<div data-panel-id="risk-queue" class="max-w-5xl mx-auto px-4 py-8">
    <div class="mb-6">
        <h1 class="text-2xl font-extrabold text-slate-900">সন্দেহজনক রিকোয়েস্ট রিভিউ</h1>
        <p class="mt-1 text-sm font-semibold text-slate-500">
            নিয়ম মিলিয়ে যেগুলো সন্দেহজনক মনে হয়েছে, সেগুলো এখানে আছে। <strong>কিছুই নিজে থেকে লুকানো বা বন্ধ হয়নি।</strong>
            সিদ্ধান্ত আপনার। রক্ত জরুরি, তাই সন্দেহ থাকলে "আসল" বেছে নিন।
        </p>
        <p class="mt-2 text-xs text-slate-500">
            এ পর্যন্ত রিভিউ হয়েছে {{ $bn($confirmed + $dismissed) }}টি: স্প্যাম {{ $bn($confirmed) }}টি, আসল {{ $bn($dismissed) }}টি।
            @if ($precision !== null && ($confirmed + $dismissed) >= 20)
                নিয়মগুলোর সঠিকতা প্রায় {{ $bn(round($precision * 100)) }}%।
            @else
                সঠিকতার শতাংশ বলার মতো পর্যাপ্ত রিভিউ এখনো হয়নি।
            @endif
        </p>
    </div>

    @if (session('success'))
        <div class="mb-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-green-700 font-semibold">{{ session('success') }}</div>
    @endif

    <div class="space-y-4">
        @forelse ($flags as $flag)
            @php $r = $flag->bloodRequest; @endphp
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <span class="px-2.5 py-1 text-xs font-extrabold rounded-lg bg-amber-100 text-amber-800">ঝুঁকির স্কোর {{ $bn($flag->score) }}</span>
                    <a href="{{ route('requests.show', $r) }}" class="text-sm font-bold text-slate-600 hover:text-red-600">রিকোয়েস্ট #{{ $bn($r->id) }}</a>
                    <span class="text-sm text-slate-500">{{ $r->blood_group?->value ?? $r->blood_group }}, {{ $bn($r->units_needed ?? 1) }} ব্যাগ, {{ optional($r->district)->name }}</span>
                    @if ($r->is_hidden)<span class="px-2 py-0.5 text-xs font-bold rounded bg-slate-100 text-slate-600">লুকানো</span>@endif
                </div>

                <p class="text-xs font-extrabold text-slate-500 mt-3 mb-1">কেন সন্দেহ হয়েছে</p>
                <ul class="space-y-1 text-sm text-slate-700">
                    @foreach ($flag->signals as $s)
                        <li>• {{ $s['reason'] }} <span class="text-xs text-slate-400">(+{{ $bn($s['weight']) }})</span></li>
                    @endforeach
                </ul>

                <form method="POST" class="mt-4 flex flex-col sm:flex-row gap-2 sm:items-center">
                    @csrf
                    <input type="text" name="note" maxlength="255" placeholder="নোট (ঐচ্ছিক)"
                           class="flex-1 rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <button formaction="{{ route('admin.risk.dismiss', $flag) }}"
                            class="px-4 py-2 rounded-xl bg-emerald-600 text-white font-extrabold text-sm hover:bg-emerald-700">আসল, ঠিক আছে</button>
                    <button formaction="{{ route('admin.risk.confirm', $flag) }}"
                            onclick="return confirm('রিকোয়েস্টটি ফিড থেকে লুকানো হবে। নিশ্চিত?')"
                            class="px-4 py-2 rounded-xl bg-red-600 text-white font-extrabold text-sm hover:bg-red-700">স্প্যাম, লুকান</button>
                </form>
            </div>
        @empty
            <div class="text-center py-16 bg-white rounded-3xl border border-dashed border-slate-300">
                <p class="text-lg font-extrabold text-slate-900">রিভিউয়ের জন্য কিছু নেই</p>
                <p class="text-sm text-slate-500 mt-1">এই মুহূর্তে কোনো রিকোয়েস্ট ঝুঁকির সীমা পার করেনি।</p>
            </div>
        @endforelse
    </div>

    <div class="mt-6">{{ $flags->links() }}</div>
    <a href="{{ route('admin.spam-radar.index') }}" class="mt-4 inline-block text-sm font-bold text-slate-600 hover:text-red-600">রিপোর্ট-ভিত্তিক Spam Radar →</a>
</div>
@endsection
