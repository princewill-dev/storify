<div id="{{ $id }}" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('{{ $id }}')"></div>
        <div class="relative w-full max-w-sm bg-white rounded-2xl shadow-xl">
            <div class="px-6 py-4 border-b border-slate-100">
                <h3 class="text-base font-semibold {{ $danger ?? true ? 'text-slate-800' : 'text-slate-800' }}">{{ $title }}</h3>
            </div>
            <div class="p-6 space-y-4">
                <div class="text-center">
                    @if($danger ?? true)
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-red-100 mb-3">
                        <i class="fi fi-rr-trash text-xl text-red-500"></i>
                    </div>
                    @endif
                    <p class="text-sm text-slate-600">{{ $message }}</p>
                    @if(isset($warning))
                    <p class="text-xs text-slate-400 mt-2">{{ $warning }}</p>
                    @endif
                </div>
                <form method="POST" action="{{ $action }}">
                    @csrf
                    @if(strtoupper($method ?? 'POST') !== 'POST')
                        @method($method)
                    @endif
                    {{ $slot }}
                    <div class="flex items-center gap-3">
                        <button type="submit" class="flex-1 py-2.5 {{ ($danger ?? true) ? 'bg-red-600 hover:bg-red-700' : 'bg-slate-900 hover:bg-slate-800' }} text-white text-sm font-semibold rounded-lg transition-colors">{{ $confirmText ?? 'Confirm' }}</button>
                        <button type="button" onclick="closeModal('{{ $id }}')" class="flex-1 py-2 border border-slate-200 text-sm rounded-lg hover:bg-slate-50">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
