@extends('account.layout')
@section('title', 'My Downloads')
@section('subtitle', 'Downloads')

@section('content')
<div class="card">
    <div class="p-3 border-bottom d-flex align-items-center justify-content-between">
        <div>
            <h6 class="mb-0 fw-semibold">Digital Downloads</h6>
            <p class="mb-0 text-muted" style="font-size:12px;">Files you purchased. Each link is unique to your order and expires after the download limit or expiry date.</p>
        </div>
    </div>

    @if($downloads->count() > 0)
    <div class="table-responsive">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th class="ps-4">Product</th>
                    <th>Order</th>
                    <th>Status</th>
                    <th>Downloads Left</th>
                    <th>Expires</th>
                    <th class="text-end pe-4">Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach($downloads as $download)
                <tr>
                    <td class="ps-4">
                        <span class="fw-semibold">{{ $download->product?->name ?? 'Digital product' }}</span>
                    </td>
                    <td class="text-muted">{{ $download->order?->order_number ?? '—' }}</td>
                    <td>
                        <span class="badge {{ $download->isActive() ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $download->status_label }}</span>
                    </td>
                    <td>{{ $download->downloadsRemaining() }}</td>
                    <td class="text-muted">{{ $download->expires_at?->format('d M Y') ?? '—' }}</td>
                    <td class="text-end pe-4">
                        <a href="{{ route('downloads.show', ['token' => $download->token]) }}" class="btn btn-sm btn-primary">
                            {{ $download->isActive() ? 'Download' : 'View' }}
                        </a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if($downloads->hasPages())
    <div class="p-3 border-top">{{ $downloads->links() }}</div>
    @endif
    @else
    <div class="p-5 text-center">
        <p class="mb-1 fw-semibold">No downloads yet</p>
        <p class="mb-3 text-muted" style="font-size:13px;">When you buy a digital product, your download links will appear here.</p>
        <a href="{{ route('home.index') }}" class="btn btn-sm btn-outline-secondary">Browse products</a>
    </div>
    @endif
</div>
@endsection
