@extends('admin.layout')
@section('subtitle', 'Bank Accounts')

@section('content')
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Bank Accounts</h4>
    <a href="{{ route('admin.bank-accounts.create') }}" class="btn btn-primary">Add Bank Account</a>
  </div>

  <div class="card">
    <div class="card-body table-responsive">
      <table class="table">
        <thead>
          <tr>
            <th>Logo</th>
            <th>Bank Name</th>
            <th>Account Name</th>
            <th>Account Number</th>
            <th>Sort Order</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse($bankAccounts as $account)
            <tr>
              <td>
                @if($account->logo)
                  <img src="{{ Storage::url($account->logo) }}" alt="{{ $account->bank_name }}" style="height: 40px; width: auto;">
                @else
                  <span class="text-muted">No logo</span>
                @endif
              </td>
              <td>{{ $account->bank_name }}</td>
              <td>{{ $account->account_name ?? 'N/A' }}</td>
              <td><code>{{ $account->account_number }}</code></td>
              <td>{{ $account->sort_order }}</td>
              <td>
                <form method="post" action="{{ route('admin.bank-accounts.toggle-active', $account) }}" class="d-inline">
                  @csrf
                  @if($account->is_active)
                    <span class="badge bg-success">Active</span>
                  @else
                    <span class="badge bg-secondary">Inactive</span>
                  @endif
                </form>
              </td>
              <td class="text-end">
                <form method="post" action="{{ route('admin.bank-accounts.toggle-active', $account) }}" class="d-inline">
                  @csrf
                  <button type="submit" class="btn btn-sm btn-outline-{{ $account->is_active ? 'warning' : 'success' }}">
                    {{ $account->is_active ? 'Deactivate' : 'Activate' }}
                  </button>
                </form>
                <a href="{{ route('admin.bank-accounts.edit', $account) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                <form method="post" action="{{ route('admin.bank-accounts.destroy', $account) }}" class="d-inline" onsubmit="return confirm('Delete this bank account?')">
                  @csrf @method('DELETE')
                  <button class="btn btn-sm btn-outline-danger">Delete</button>
                </form>
              </td>
            </tr>
          @empty
            <tr><td colspan="7" class="text-center text-muted">No bank accounts found. Add one to get started.</td></tr>
          @endforelse
        </tbody>
      </table>
      <div class="mt-3">{{ $bankAccounts->links() }}</div>
    </div>
  </div>
</div>
@endsection
