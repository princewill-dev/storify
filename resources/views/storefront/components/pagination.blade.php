<nav class="store-pagination" style="display:flex;align-items:center;justify-content:center;gap:4px;margin-top:24px;">
    @if($paginator->onFirstPage())
        <span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;border:1px solid #e2e8f0;color:#cbd5e1;font-size:13px;cursor:default;">‹</span>
    @else
        <a href="{{ $paginator->previousPageUrl() }}" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;border:1px solid #e2e8f0;color:#64748b;font-size:13px;text-decoration:none;transition:all 0.15s;" onmouseover="this.style.background='#f1f5f9';this.style.color='#0f172a'" onmouseout="this.style.background='transparent';this.style.color='#64748b'">‹</a>
    @endif

    @foreach($paginator->getUrlRange(1, $paginator->lastPage()) as $page => $url)
        @if($page == $paginator->currentPage())
            <span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;background:#0f172a;color:#fff;font-size:13px;font-weight:600;">{{ $page }}</span>
        @elseif($page <= 3 || $page > $paginator->lastPage() - 3 || abs($page - $paginator->currentPage()) <= 1)
            <a href="{{ $url }}" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;border:1px solid #e2e8f0;color:#64748b;font-size:13px;text-decoration:none;transition:all 0.15s;" onmouseover="this.style.background='#f1f5f9';this.style.color='#0f172a'" onmouseout="this.style.background='transparent';this.style.color='#64748b'">{{ $page }}</a>
        @elseif($page == 4 || $page == $paginator->lastPage() - 3)
            <span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;color:#cbd5e1;font-size:13px;">…</span>
        @endif
    @endforeach

    @if($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;border:1px solid #e2e8f0;color:#64748b;font-size:13px;text-decoration:none;transition:all 0.15s;" onmouseover="this.style.background='#f1f5f9';this.style.color='#0f172a'" onmouseout="this.style.background='transparent';this.style.color='#64748b'">›</a>
    @else
        <span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;border:1px solid #e2e8f0;color:#cbd5e1;font-size:13px;cursor:default;">›</span>
    @endif
</nav>
