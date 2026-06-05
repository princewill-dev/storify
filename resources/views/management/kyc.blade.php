@extends('management.layout')
@section('subtitle', 'KYC Verification')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="KYC Verification" subtitle="Submit identity documents for account verification" />

<div>
    @if(isset($application) && $application)
    <x-management.card>
        <div class="flex items-center gap-3 mb-4">
            <x-management.status-badge :status="$application->status" />
            <span class="text-sm text-slate-500">Submitted {{ $application->created_at->diffForHumans() }}</span>
        </div>
    </x-management.card>
    @else
    <x-management.card header="Submit KYC Documents">
        <form action="{{ route('management.kyc.submit') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
            @csrf
            <x-management.form-input name="document_type" label="Document Type" type="select" required>
                <option value="">Select document type</option>
                @foreach(\App\Models\KycDocumentType::all() as $doc)
                    <option value="{{ $doc->id }}">{{ $doc->name }}</option>
                @endforeach
            </x-management.form-input>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Upload Document</label>
                <input type="file" name="document" class="block w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
            </div>
            <div class="pt-2">
                <button type="submit" class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors">Submit for Review</button>
            </div>
        </form>
    </x-management.card>
    @endif
</div>
@endsection
