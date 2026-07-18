<div id="{{ $id }}" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="{{ $id }}Label" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('{{ $id }}')"></div>
    <div class="relative min-h-screen flex items-center justify-center p-4">
        <div class="relative bg-white rounded-xl shadow-xl max-w-sm w-full p-6">
            <div class="text-center mb-4">
                @if($danger ?? true)
                <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-red-50 mb-3">
                    <i class="fi fi-rr-trash text-red-600 text-xl"></i>
                </div>
                @endif
                <h5 class="text-lg font-semibold text-slate-900" id="{{ $id }}Label">{{ $title }}</h5>
                <p class="text-sm text-slate-600 mt-1">{{ $message }}</p>
                @if(isset($warning))
                <p class="text-xs text-slate-400 mt-1">{{ $warning }}</p>
                @endif
            </div>
            <form method="POST" action="{{ $action }}">
                @csrf
                @if(strtoupper($method ?? 'POST') !== 'POST')
                    @method($method)
                @endif
                {{ $slot }}
                <div class="flex items-center justify-center gap-3">
                    <button type="submit" class="px-4 py-2 text-sm font-medium rounded-lg {{ ($danger ?? true) ? 'bg-red-600 hover:bg-red-700' : 'bg-slate-900 hover:bg-slate-800' }} text-white">{{ $confirmText ?? 'Confirm' }}</button>
                    <button type="button" onclick="closeModal('{{ $id }}')" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
