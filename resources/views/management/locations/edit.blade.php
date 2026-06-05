@extends('management.layout')
@section('subtitle', 'Edit Location')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Edit: {{ $location->name }}" subtitle="Update location details" />

<div>
    <x-management.card>
        <form action="{{ route('management.locations.update', $location) }}" method="POST" class="space-y-5">
            @csrf @method('PUT')
            <x-management.form-input name="name" label="Location Name" :value="$location->name" required />
            <x-management.form-input name="address" label="Address" :value="$location->address" placeholder="Street address" />
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <input type="hidden" name="country" value="Nigeria">
                <div class="sm:col-span-1">
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Country</label>
                    <p class="block w-full rounded-lg border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-500">Nigeria</p>
                </div>
                <div>
                    <x-management.form-input name="state" label="State" type="select">
                        <option value="">Select state</option>
                        @foreach($nigerianStates as $abbr => $name)
                            <option value="{{ $name }}" @selected($location->state == $name)>{{ $name }}</option>
                        @endforeach
                    </x-management.form-input>
                </div>
                <div>
                    <x-management.form-input name="city" label="City" type="select">
                        <option value="">Select city</option>
                        @foreach($nigerianCities as $city)
                            <option value="{{ $city }}" @selected($location->city == $city)>{{ $city }}</option>
                        @endforeach
                    </x-management.form-input>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <input type="checkbox" name="is_active" value="1" {{ $location->is_active ? 'checked' : '' }} class="rounded border-slate-300 text-slate-900 focus:ring-slate-500">
                <span class="text-sm text-slate-700">Active</span>
            </div>
            <div class="flex items-center gap-3 pt-2 border-t border-slate-100">
                <button type="submit" class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">Save Changes</button>
                <a href="{{ route('management.locations.index') }}" class="text-sm text-slate-500 hover:text-slate-700">Cancel</a>
            </div>
        </form>
    </x-management.card>
</div>
@endsection
