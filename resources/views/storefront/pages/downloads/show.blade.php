@extends('storefront.layout')
@section('title', 'Your Downloads')

@section('content')
<div style="max-width:640px;margin:48px auto;padding:0 16px;">

    <div style="background:#141417;border:1px solid #27272a;border-radius:20px;padding:36px 32px;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
            <div style="width:44px;height:44px;border-radius:50%;background:rgba(99,102,241,.12);display:flex;align-items:center;justify-content:center;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#818cf8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4m0 0L8 8m4-4l4 4M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2"/></svg>
            </div>
            <div>
                <h1 style="margin:0;font-size:20px;font-weight:700;color:#f4f4f5;">Your Downloads</h1>
                <p style="margin:2px 0 0;font-size:13px;color:#a1a1aa;">Order {{ $download->order?->order_number }}</p>
            </div>
        </div>

        @if(session('error'))
        <div style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:#fca5a5;border-radius:12px;padding:12px 14px;font-size:13px;margin-bottom:18px;">
            {{ session('error') }}
        </div>
        @endif

        <div style="font-size:17px;font-weight:700;color:#f4f4f5;">{{ $download->product?->name ?? 'Digital product' }}</div>

        <div style="display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 22px;">
            <span style="font-size:11px;font-weight:600;padding:4px 10px;border-radius:999px;
                @if($download->isActive()) background:rgba(34,197,94,.12);color:#4ade80;
                @else background:rgba(148,163,184,.12);color:#94a3b8; @endif">
                {{ $download->status_label }}
            </span>
            <span style="font-size:11px;font-weight:600;padding:4px 10px;border-radius:999px;background:rgba(148,163,184,.12);color:#a1a1aa;">
                {{ $download->downloadsRemaining() }} download(s) remaining
            </span>
            @if($download->expires_at)
            <span style="font-size:11px;font-weight:600;padding:4px 10px;border-radius:999px;background:rgba(148,163,184,.12);color:#a1a1aa;">
                Expires {{ $download->expires_at->format('d M Y') }}
            </span>
            @endif
        </div>

        @if($download->product && $download->product->files->isNotEmpty())
            @if($download->isActive())
            <div style="display:flex;flex-direction:column;gap:10px;">
                @foreach($download->product->files as $file)
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;background:#18181b;border:1px solid #27272a;border-radius:12px;padding:12px 14px;">
                    <div style="min-width:0;">
                        <div style="font-size:14px;font-weight:600;color:#e4e4e7;word-break:break-all;">{{ $file->original_name }}</div>
                        <div style="font-size:11px;color:#71717a;margin-top:2px;">{{ $file->formatted_size }}</div>
                    </div>
                    <a href="{{ route('downloads.file', ['token' => $token, 'file' => $file->id]) }}"
                       style="flex-shrink:0;background:#4f46e5;color:#fff;text-decoration:none;font-size:13px;font-weight:600;padding:9px 16px;border-radius:10px;">
                        Download
                    </a>
                </div>
                @endforeach
            </div>
            @else
            <div style="background:rgba(148,163,184,.08);border:1px solid #27272a;border-radius:12px;padding:16px;font-size:13px;color:#a1a1aa;">
                This download link is no longer available
                @if($download->isExpired()) because it expired on {{ $download->expires_at?->format('d M Y') }}@else because the download limit was reached @endif.
                Please contact the store if you need it re-issued.
            </div>
            @endif
        @else
            <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:12px;padding:16px;font-size:13px;color:#fca5a5;">
                No files are attached to this product yet. Please contact the store.
            </div>
        @endif

        @if($download->isActive() && $download->product && $download->product->files->isNotEmpty())
        <p style="margin:18px 0 0;font-size:12px;color:#71717a;">
            Each file download counts against your limit. Keep this link private — it is unique to your purchase.
        </p>
        @endif
    </div>

    <div style="text-align:center;margin-top:20px;">
        <a href="{{ route('home.index') }}" style="font-size:13px;color:#71717a;text-decoration:none;">← Back to home</a>
    </div>
</div>
@endsection
