@extends('vendors.layout')
@section('subtitle', 'Support Messages')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4 class="card-title">Support Messages</h4>
                    <div>
                        <span class="badge bg-warning me-2">{{ $messages->where('status', 'pending')->count() }} Pending</span>
                        <span class="badge bg-success">{{ $messages->where('status', 'replied')->count() }} Replied</span>
                    </div>
                </div>
                <div class="card-body">
                    @if(session('success'))
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="fa fa-check-circle me-2"></i>{{ session('success') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif

                    @if(session('error'))
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="fa fa-exclamation-circle me-2"></i>{{ session('error') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif

                    <div class="table-responsive">
                        <table class="table table-hover table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 60px;">ID</th>
                                    <th>Customer</th>
                                    <th>Message</th>
                                    <th style="width: 100px;">Status</th>
                                    <th style="width: 120px;">Date</th>
                                    <th style="width: 150px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($messages as $message)
                                <tr class="{{ $message->status === 'pending' ? 'table-warning' : '' }}">
                                    <td>#{{ $message->id }}</td>
                                    <td>
                                        <strong>{{ $message->name }}</strong><br>
                                        <small class="text-muted">{{ $message->email }}</small>
                                        @if($message->phone)
                                        <br><small class="text-muted">{{ $message->phone }}</small>
                                        @endif
                                    </td>
                                    <td>{{ Str::limit($message->message, 80) }}</td>
                                    <td>
                                        @if($message->status === 'pending')
                                            <span class="badge bg-warning">Pending</span>
                                        @elseif($message->status === 'replied')
                                            @if($message->replied_by_type === 'admin')
                                                <span class="badge bg-info" title="Admin replied on your behalf">
                                                    <i class="fa fa-user-shield"></i> Admin Replied
                                                </span>
                                            @else
                                                <span class="badge bg-success">Replied</span>
                                            @endif
                                        @else
                                            <span class="badge bg-secondary">Closed</span>
                                        @endif
                                    </td>
                                    <td>{{ $message->created_at->format('M d, Y') }}</td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#viewModal{{ $message->id }}">
                                                <i class="fa fa-eye"></i>
                                            </button>
                                            @if($message->status !== 'replied')
                                            <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#replyModal{{ $message->id }}">
                                                <i class="fa fa-reply"></i>
                                            </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>

                                <!-- View Modal -->
                                <div class="modal fade" id="viewModal{{ $message->id }}" tabindex="-1">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Support Message Details</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="row mb-3">
                                                    <div class="col-md-6">
                                                        <p class="mb-1"><strong>Customer Name:</strong></p>
                                                        <p>{{ $message->name }}</p>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <p class="mb-1"><strong>Email:</strong></p>
                                                        <p>{{ $message->email }}</p>
                                                    </div>
                                                </div>
                                                <div class="row mb-3">
                                                    <div class="col-md-6">
                                                        <p class="mb-1"><strong>Phone:</strong></p>
                                                        <p>{{ $message->phone ?? 'N/A' }}</p>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <p class="mb-1"><strong>Date Submitted:</strong></p>
                                                        <p>{{ $message->created_at->format('M d, Y \a\t h:i A') }}</p>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <p class="mb-1"><strong>Message:</strong></p>
                                                    <div class="alert alert-light">
                                                        {{ $message->message }}
                                                    </div>
                                                </div>
                                                @if($message->reply)
                                                <div class="mb-3">
                                                    <p class="mb-1"><strong>Reply (by {{ ucfirst($message->replied_by_type) }}):</strong></p>
                                                    @if($message->replied_by_type === 'admin')
                                                    <div class="alert alert-info">
                                                        <i class="fa fa-user-shield me-2"></i><strong>Admin replied on your behalf:</strong><br>
                                                        {{ $message->reply }}
                                                    </div>
                                                    @else
                                                    <div class="alert alert-success">
                                                        {{ $message->reply }}
                                                    </div>
                                                    @endif
                                                    <p class="text-muted small">Replied on: {{ $message->replied_at->format('M d, Y \a\t h:i A') }}</p>
                                                </div>
                                                @endif
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                @if($message->status !== 'replied')
                                                <button type="button" class="btn btn-success" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#replyModal{{ $message->id }}">
                                                    Reply to Customer
                                                </button>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Reply Modal -->
                                @if($message->status !== 'replied')
                                <div class="modal fade" id="replyModal{{ $message->id }}" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form action="{{ route('vendor.support-messages.reply', ['vendor' => $vendor, 'supportMessage' => $message]) }}" method="POST">
                                                @csrf
                                                <div class="modal-header bg-success text-white">
                                                    <h5 class="modal-title">Reply to {{ $message->name }}</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="mb-3">
                                                        <label class="form-label"><strong>Customer Message:</strong></label>
                                                        <div class="alert alert-light">
                                                            {{ $message->message }}
                                                        </div>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">Your Reply <span class="text-danger">*</span></label>
                                                        <textarea name="reply" rows="6" class="form-control @error('reply') is-invalid @enderror" required></textarea>
                                                        @error('reply')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                                    </div>
                                                    <p class="text-muted small">
                                                        <i class="fa fa-info-circle"></i> An email notification will be sent to {{ $message->email }} with your reply.
                                                    </p>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-success">Send Reply</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                @endif
                                @empty
                                <tr>
                                    <td colspan="6" class="text-center py-4">
                                        <i class="fa fa-inbox fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No support messages found.</p>
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
