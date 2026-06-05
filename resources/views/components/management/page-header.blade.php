@props(['title', 'subtitle' => null, 'breadcrumbs' => []])

<div class="mb-6">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-slate-900">{{ $title }}</h1>
            @if($subtitle)
                <p class="text-sm text-slate-500 mt-0.5">{{ $subtitle }}</p>
            @endif
        </div>
        @if(isset($actions))
            <div class="flex items-center gap-2 shrink-0">
                {{ $actions }}
            </div>
        @endif
    </div>
</div>
