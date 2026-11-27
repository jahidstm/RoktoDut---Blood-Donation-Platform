@extends('layouts.admin-dashboard')

@section('title', 'Audit Logs — Admin — রক্তদূত')

@section('content')
<div data-panel-id="audit-logs" class="max-w-7xl mx-auto px-4 py-8">
    <div class="mb-6">
        <h1 class="text-2xl font-extrabold text-slate-900">Audit Logs</h1>
        <p class="text-sm font-semibold text-slate-500 mt-1">কে, কখন, কী করেছেন তার তালিকা</p>
    </div>

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach(['' => 'সব'] + $areas as $key => $label)
            <a href="{{ route('admin.audit-logs.index', $key ? ['area' => $key] : []) }}"
               class="rounded-xl border px-4 py-2 text-xs font-bold transition {{ (string) $area === $key ? 'border-slate-800 bg-slate-800 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300' }}">{{ $label }}</a>
        @endforeach
    </div>

    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-500 uppercase text-xs">
                <tr>
                    <th class="text-left px-4 py-3">Time</th>
                    <th class="text-left px-4 py-3">Actor</th>
                    <th class="text-left px-4 py-3">Action</th>
                    <th class="text-left px-4 py-3">Target</th>
                    <th class="text-left px-4 py-3">Metadata</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($logs as $log)
                    <tr>
                        <td class="px-4 py-3 text-xs font-semibold text-slate-500">{{ $log->created_at?->format('d M Y, h:i A') }}</td>
                        <td class="px-4 py-3">
                            @if($log->actor)
                                <div class="font-bold text-slate-800">{{ $log->actor->name }}</div>
                                <div class="text-xs text-slate-500">{{ $log->actor->email }}</div>
                            @else
                                <span class="text-xs font-semibold text-slate-500">System/Guest</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-bold text-slate-800">{{ $log->action }}</td>
                        <td class="px-4 py-3">
                            @if($log->target_type || $log->target_id)
                                <span class="text-xs font-semibold text-slate-700">{{ class_basename((string) $log->target_type) }} #{{ $log->target_id }}</span>
                            @else
                                <span class="text-xs text-slate-400">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <pre class="whitespace-pre-wrap break-words text-xs text-slate-600">{{ json_encode($log->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) }}</pre>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-slate-500 font-semibold">No audit logs found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($logs->hasPages())
        <div class="mt-6">{{ $logs->links() }}</div>
    @endif
</div>
@endsection
