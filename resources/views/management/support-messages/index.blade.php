@extends('management.layout')
@section('subtitle', 'Support Messages')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Support Messages" subtitle="Customer inquiries and support requests" />

<x-management.card>
    <div class="divide-y divide-slate-100 -mx-5 -mb-5">
        @forelse($messages ?? [] as $msg)
        <div class="px-5 py-4 hover:bg-slate-50 transition-colors">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-sm font-semibold text-slate-800">{{ $msg->name ?? $msg->email }}</span>
                        <span class="text-xs text-slate-400">{{ $msg->created_at?->diffForHumans() }}</span>
                    </div>
                    <p class="text-sm text-slate-600 line-clamp-2">{{ $msg->message }}</p>
                </div>
            </div>
        </div>
        @empty
        <div class="px-5 py-12 text-center">
            <x-management.empty-state icon="fi fi-rr-headset" title="No support messages" description="Customer inquiries will appear here." />
        </div>
        @endforelse
    </div>
</x-management.card>
@endsection
