@props(['name', 'label' => null, 'type' => 'text', 'required' => false, 'placeholder' => '', 'value' => null, 'error' => null])

<div class="space-y-1.5">
    @if($label)
        <label for="{{ $name }}" class="block text-sm font-medium text-slate-700">
            {{ $label }}
            @if($required)<span class="text-red-500 ml-0.5">*</span>@endif
        </label>
    @endif
    
    @if($type === 'textarea')
        <textarea 
            id="{{ $name }}" name="{{ $name }}" rows="3"
            placeholder="{{ $placeholder }}"
            {{ $attributes->merge(['class' => 'block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm ' . ($error ? 'border-red-300 focus:border-red-500 focus:ring-red-500' : '')]) }}
        >{{ old($name, $value) }}</textarea>
    @elseif($type === 'select')
        <select 
            id="{{ $name }}" name="{{ $name }}"
            {{ $attributes->merge(['class' => 'block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm ' . ($error ? 'border-red-300' : '')]) }}
        >
            {{ $slot }}
        </select>
    @else
        <input 
            type="{{ $type }}" id="{{ $name }}" name="{{ $name }}"
            value="{{ old($name, $value) }}"
            placeholder="{{ $placeholder }}"
            @if($required) required @endif
            {{ $attributes->merge(['class' => 'block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm ' . ($error ? 'border-red-300 focus:border-red-500 focus:ring-red-500' : '')]) }}
        >
    @endif

    @if($error)
        <p class="text-xs text-red-500">{{ $error }}</p>
    @endif
</div>
