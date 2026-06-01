@props(['title', 'subtitle' => null, 'breadcrumbs' => []])

<div class="mb-6">
    @if(count($breadcrumbs) > 1)
    <nav class="flex items-center gap-1.5 text-sm text-slate-500 mb-1">
        @foreach($breadcrumbs as $i => $crumb)
            @if($i > 0)<i class="fi fi-rr-angle-small-right text-xs text-slate-400"></i>@endif
            @if($loop->last)
                <span class="text-slate-800 font-medium">{{ $crumb }}</span>
            @else
                <a href="{{ is_array($crumb) ? $crumb['url'] : '#' }}" class="hover:text-slate-700">{{ is_array($crumb) ? $crumb['label'] : $crumb }}</a>
            @endif
        @endforeach
    </nav>
    @endif
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
