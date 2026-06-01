<div {{ $attributes->merge(['class' => 'overflow-visible bg-white rounded-xl shadow-sm border border-slate-200']) }}>
    @if(isset($search) || isset($filters))
    <div class="flex items-center gap-3 px-5 py-3 border-b border-slate-100">
        @if(isset($search))
            {{ $search }}
        @endif
        @if(isset($filters))
            {{ $filters }}
        @endif
    </div>
    @endif
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            @if(isset($header))
            <thead class="bg-slate-50">
                <tr>
                    {{ $header }}
                </tr>
            </thead>
            @endif
            <tbody class="divide-y divide-slate-100 bg-white">
                {{ $slot }}
            </tbody>
        </table>
    </div>
    @if(isset($pagination))
    <div class="px-5 py-3 border-t border-slate-100">
        {{ $pagination }}
    </div>
    @endif
</div>
