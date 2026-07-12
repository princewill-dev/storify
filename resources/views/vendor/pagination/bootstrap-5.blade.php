@if ($paginator->hasPages())
<div style="display:flex;align-items:center;justify-content:space-between;">
    <div style="display:flex;align-items:center;gap:4px;">
        @if ($paginator->onFirstPage())
            <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;color:#cbd5e1;cursor:default;">&lsaquo;</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;color:#64748b;text-decoration:none;border-radius:6px;">&lsaquo;</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span style="padding:0 2px;color:#94a3b8;font-size:13px;">…</span>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span style="display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:28px;padding:0 6px;background:#2563eb;color:#fff;font-size:12px;font-weight:700;border-radius:6px;">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" style="display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:28px;padding:0 6px;color:#475569;font-size:12px;font-weight:500;text-decoration:none;border-radius:6px;">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;color:#64748b;text-decoration:none;border-radius:6px;">&rsaquo;</a>
        @else
            <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;color:#cbd5e1;cursor:default;">&rsaquo;</span>
        @endif
    </div>

    <span style="font-size:12px;color:#94a3b8;white-space:nowrap;">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}</span>
</div>
@endif
