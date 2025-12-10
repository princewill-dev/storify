@extends('admin.layout')
@section('subtitle', 'activity logs')

@section('content')
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="card-title mb-0">Activity Logs</h6>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#filterLogsModal">Filter</button>
        </div>
      </div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead>
              <tr>
                <th>When</th>
                <th>User</th>
                <th>Action</th>
                <th>Description</th>
                <th>IP</th>
                <th>User Agent</th>
              </tr>
            </thead>
            <tbody>
              @forelse($logs as $log)
                <tr>
                  <td>{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                  <td>{{ $log->user?->name ?? '—' }}</td>
                  <td><span class="badge bg-light text-dark">{{ $log->action }}</span></td>
                  <td style="max-width: 460px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="{{ $log->description }}">{{ $log->description }}</td>
                  <td>{{ $log->ip_address }}</td>
                  <td style="max-width: 360px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="{{ $log->user_agent }}">{{ $log->user_agent }}</td>
                </tr>
              @empty
                <tr><td colspan="6" class="text-center text-muted">No activity yet.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
        <div class="mt-2">{{ $logs->links() }}</div>
      </div>
    </div>
  </div>
</div>

<!-- Filter Logs Modal -->
<div class="modal fade" id="filterLogsModal" tabindex="-1" aria-labelledby="filterLogsLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="filterLogsLabel">Filter Activity Logs</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="GET">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">User</label>
              <select name="user_id" class="form-select">
                <option value="">All</option>
                @foreach(($users ?? []) as $u)
                  <option value="{{ $u->id }}" @selected(($userId ?? '')==$u->id)>{{ $u->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Action</label>
              <select name="action" class="form-select">
                <option value="">All</option>
                @foreach(($actions ?? []) as $a)
                  <option value="{{ $a }}" @selected(($action ?? '')===$a)>{{ $a }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Search</label>
              <input type="text" name="q" value="{{ $q ?? '' }}" class="form-control" placeholder="Action, description, IP, user agent">
            </div>
            <div class="col-md-4">
              <label class="form-label">From</label>
              <input type="date" name="from" value="{{ $from ?? '' }}" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">To</label>
              <input type="date" name="to" value="{{ $to ?? '' }}" class="form-control">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <a href="{{ route('admin.activity-logs.index') }}" class="btn btn-light">Reset</a>
          <button type="submit" class="btn btn-primary">Apply Filters</button>
        </div>
      </form>
    </div>
  </div>
</div>
@endsection
