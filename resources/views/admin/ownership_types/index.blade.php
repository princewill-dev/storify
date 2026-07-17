@extends('admin.layout')
@section('subtitle', 'Ownership Types')

@section('content')
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Ownership Types</h4>
    <a href="{{ route('admin.ownership-types.create') }}" class="btn btn-primary">New type</a>
  </div>

  <div class="card">
    <div class="card-body table-responsive">
      <table class="table">
        <thead>
          <tr>
            <th>Name</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          @forelse($types as $t)
            <tr>
              <td>{{ $t->name }}</td>
              <td class="text-end">
                <a href="{{ route('admin.ownership-types.edit', $t) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteOwnershipType{{ $t->id }}">Delete</button>
                <x-admin.confirm-modal id="deleteOwnershipType{{ $t->id }}" title="Delete Ownership Type" message="Delete this type?" action="{{ route('admin.ownership-types.destroy', $t) }}" method="DELETE" />
              </td>
            </tr>
          @empty
            <tr><td colspan="2" class="text-center text-muted">No items</td></tr>
          @endforelse
        </tbody>
      </table>
      <div class="mt-3">{{ $types->links() }}</div>
    </div>
  </div>
</div>
@endsection
