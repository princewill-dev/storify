@extends('admin.layout')
@section('title', 'Page Styling')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4 class="card-title">Page Styling</h4>
                    <a href="{{ route('admin.styling.create') }}" class="btn btn-primary btn-sm">
                        <i class="fi fi-rr-plus"></i> New Page Style
                    </a>
                </div>
                <div class="card-body">
                    @if(session('success'))
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            {{ session('success') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    @endif

                    @if($stylings->isEmpty())
                        <div class="alert alert-info">
                            No page stylings configured yet. <a href="{{ route('admin.styling.create') }}">Create one now</a>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Page Label</th>
                                        <th>Page Name</th>
                                        <th>Background Color</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($stylings as $styling)
                                    <tr>
                                        <td>{{ $loop->iteration }}</td>
                                        <td><strong>{{ $styling->page_label }}</strong></td>
                                        <td><code>{{ $styling->page_name }}</code></td>
                                        <td>
                                            @if($styling->background_color)
                                                <div class="d-flex align-items-center">
                                                    <div style="width: 30px; height: 30px; background-color: {{ $styling->background_color }}; border: 1px solid #ddd; border-radius: 4px; margin-right: 10px;"></div>
                                                    <code>{{ $styling->background_color }}</code>
                                                </div>
                                            @else
                                                <span class="text-muted">Not set</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($styling->is_active)
                                                <span class="badge bg-success">Active</span>
                                            @else
                                                <span class="badge bg-secondary">Inactive</span>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <a href="{{ route('admin.styling.edit', $styling) }}" class="btn btn-sm btn-primary">
                                                    <i class="fi fi-rr-edit"></i>
                                                </a>
                                                <form action="{{ route('admin.styling.destroy', $styling) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this styling?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-danger">
                                                        <i class="fi fi-rr-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
