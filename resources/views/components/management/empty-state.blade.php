@props(['icon' => 'fi fi-rr-inbox', 'title' => 'Nothing here', 'description' => null, 'actionLabel' => null, 'actionUrl' => null])

<div class="flex flex-col items-center justify-center py-12 text-center">
    <i class="{{ $icon }} text-4xl text-slate-300 mb-3"></i>
    <h3 class="text-sm font-semibold text-slate-600">{{ $title }}</h3>
    @if($description)
        <p class="text-xs text-slate-400 mt-1 max-w-sm">{{ $description }}</p>
    @endif
    @if($actionLabel && $actionUrl)
        <a href="{{ $actionUrl }}" class="mt-4 inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors">
            {{ $actionLabel }}
        </a>
    @endif
</div>
