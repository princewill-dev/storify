@extends('management.layout')
@section('subtitle', 'Add Warehouse')

@section('content')
<x-management.page-header title="Add Warehouse" subtitle="Set up a new inventory storage location" />

<div>
    <x-management.card>
        <form action="{{ route('management.warehouses.store') }}" method="POST" class="space-y-5">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-management.form-input name="name" label="Warehouse Name" placeholder="Main Warehouse" required />
                <x-management.form-input name="contact_person" label="Contact Person" placeholder="Who manages this location?" />
            </div>
            <x-management.form-input name="address" label="Address" placeholder="Street address" />
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <input type="hidden" name="country" value="Nigeria">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Country</label>
                    <p class="block w-full rounded-lg border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-500">Nigeria</p>
                </div>
                <x-management.form-input name="state" label="State" type="select">
                    <option value="">Select state</option>
                    @foreach($nigerianStates as $abbr => $name)<option value="{{ $name }}" @selected(old('state') == $name)>{{ $name }}</option>@endforeach
                </x-management.form-input>
                <x-management.form-input name="city" label="City" :value="old('city')" placeholder="Enter city name" />
            </div>
            <x-management.form-input name="contact_phone" label="Contact Phone" />
            <x-management.form-input name="description" label="Description" type="textarea" placeholder="Notes about this warehouse..." />
            <div class="flex items-center gap-2">
                <input type="checkbox" name="is_active" value="1" checked class="rounded border-slate-300 text-slate-900 focus:ring-slate-500">
                <span class="text-sm text-slate-700">Active</span>
            </div>

            @if($activeStaff->isNotEmpty())
            <div>
                <div class="flex items-center gap-2 mb-3">
                    <h3 class="text-sm font-semibold text-slate-800">Assign Staff</h3>
                    <span class="text-xs text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full">Optional — manage later from Staff page</span>
                </div>
                <select name="staff_ids[]" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm">
                    <option value="">None</option>
                    @foreach($activeStaff as $member)
                    <option value="{{ $member->id }}" @selected(in_array($member->id, old('staff_ids', [])))>
                        {{ $member->name }} — {{ $member->roles->pluck('name')->join(', ') ?: 'No role' }}
                    </option>
                    @endforeach
                </select>
            </div>
            @endif

            <div class="flex items-center gap-3 pt-2 border-t border-slate-100">
                <button type="submit" class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">Create Warehouse</button>
                <a href="{{ route('management.warehouses.index') }}" class="text-sm text-slate-500 hover:text-slate-700">Cancel</a>
            </div>
        </form>
    </x-management.card>
</div>
@endsection
