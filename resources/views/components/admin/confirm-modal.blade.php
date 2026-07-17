<div id="{{ $id }}" class="modal fade" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title">{{ $title }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center py-4">
                @if($danger ?? true)
                <div class="mb-3">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-danger bg-opacity-10" style="width:48px;height:48px">
                        <i class="fi fi-rr-trash text-danger fs-5"></i>
                    </span>
                </div>
                @endif
                <p class="mb-1">{{ $message }}</p>
                @if(isset($warning))
                <small class="text-muted">{{ $warning }}</small>
                @endif
            </div>
            <form method="POST" action="{{ $action }}">
                @csrf
                @if(strtoupper($method ?? 'POST') !== 'POST')
                    @method($method)
                @endif
                {{ $slot }}
                <div class="modal-footer border-0 pt-0 justify-content-center gap-2">
                    <button type="submit" class="btn {{ ($danger ?? true) ? 'btn-danger' : 'btn-dark' }}">{{ $confirmText ?? 'Confirm' }}</button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
