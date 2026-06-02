@props(['value' => '', 'label' => null, 'subtitle' => null, 'icon' => null])

<div {{ $attributes->merge(['class' => 'bg-white rounded-xl shadow-sm border border-slate-200 p-5 hover:shadow-md transition-shadow']) }}>
    <div class="flex items-start justify-between gap-3">
        <div class="flex-1 min-w-0">
            @if($label)
                <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1">{{ $label }}</p>
            @endif
            <p class="text-[26px] font-bold text-slate-900 leading-tight">{{ $value }}</p>
            @if($subtitle)
                <p class="text-[13px] text-slate-400 mt-1">{{ $subtitle }}</p>
            @endif
        </div>
        @if($icon)
            <span class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-slate-100 text-slate-500 shrink-0">
                <i class="{{ $icon }} text-lg"></i>
            </span>
        @endif
    </div>
</div>
