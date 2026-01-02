@extends('vendors.auth.layout')

@section('title', 'Set Up Delivery Routes')

@section('content')
<div class="card shadow-sm">
    <div class="card-body p-4">
        <h2 class="mb-2">Set Up Delivery Routes</h2>
        <p class="text-muted mb-4">Configure delivery options for your store. You can add multiple routes.</p>

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('vendor.kyc.delivery-routes.save', ['vendor' => $vendor]) }}" id="deliveryRoutesForm">
            @csrf

            <div id="routesContainer">
                @if($existingRoutes && $existingRoutes->count() > 0)
                    @foreach($existingRoutes as $index => $route)
                        <div class="route-item card mb-3" data-route-index="{{ $index }}">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="mb-0">Route {{ $index + 1 }}</h5>
                                    <button type="button" class="btn btn-sm btn-danger remove-route" data-route-index="{{ $index }}">
                                        <i class="fas fa-times"></i> Remove
                                    </button>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Country <span class="text-danger">*</span></label>
                                        <input type="text" name="routes[{{ $index }}][country]" class="form-control" value="{{ old('routes.'.$index.'.country', $route->country) }}" required>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">State <span class="text-danger">*</span></label>
                                        <input type="text" name="routes[{{ $index }}][state]" class="form-control" value="{{ old('routes.'.$index.'.state', $route->state) }}" required>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Area</label>
                                        <input type="text" name="routes[{{ $index }}][area]" class="form-control" value="{{ old('routes.'.$index.'.area', $route->area) }}" placeholder="Optional">
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Delivery Fee (₦) <span class="text-danger">*</span></label>
                                        <input type="number" name="routes[{{ $index }}][fee]" class="form-control" value="{{ old('routes.'.$index.'.fee', $route->fee / 100) }}" step="0.01" min="0" required>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Delivery Days <span class="text-danger">*</span></label>
                                        <input type="number" name="routes[{{ $index }}][delivery_days]" class="form-control" value="{{ old('routes.'.$index.'.delivery_days', $route->delivery_days) }}" min="1" required>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <div class="form-check mt-4">
                                            <input class="form-check-input" type="checkbox" name="routes[{{ $index }}][active]" value="1" {{ old('routes.'.$index.'.active', $route->active) ? 'checked' : '' }} id="active_{{ $index }}">
                                            <label class="form-check-label" for="active_{{ $index }}">
                                                Active
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                @else
                    <!-- Initial empty route -->
                    <div class="route-item card mb-3" data-route-index="0">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0">Route 1</h5>
                                <button type="button" class="btn btn-sm btn-danger remove-route d-none" data-route-index="0">
                                    <i class="fas fa-times"></i> Remove
                                </button>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Country <span class="text-danger">*</span></label>
                                    <input type="text" name="routes[0][country]" class="form-control" value="{{ old('routes.0.country', 'Nigeria') }}" required>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">State <span class="text-danger">*</span></label>
                                    <input type="text" name="routes[0][state]" class="form-control" value="{{ old('routes.0.state') }}" required>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Area</label>
                                    <input type="text" name="routes[0][area]" class="form-control" value="{{ old('routes.0.area') }}" placeholder="Optional">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Delivery Fee (₦) <span class="text-danger">*</span></label>
                                    <input type="number" name="routes[0][fee]" class="form-control" value="{{ old('routes.0.fee') }}" step="0.01" min="0" required>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Delivery Days <span class="text-danger">*</span></label>
                                    <input type="number" name="routes[0][delivery_days]" class="form-control" value="{{ old('routes.0.delivery_days', 3) }}" min="1" required>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <div class="form-check mt-4">
                                        <input class="form-check-input" type="checkbox" name="routes[0][active]" value="1" checked id="active_0">
                                        <label class="form-check-label" for="active_0">
                                            Active
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <div class="mb-4">
                <button type="button" class="btn btn-outline-primary" id="addRouteBtn">
                    <i class="fas fa-plus"></i> Add Another Route
                </button>
            </div>

            <hr>

            <div class="d-flex justify-content-between">
                <a href="{{ route('vendor.subscription.plan', ['vendor' => $vendor]) }}" style="color: #000000">
                    <br>
                    Skip for Now
                </a>
                <button type="submit" class="btn btn-primary">
                    Save
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    let routeIndex = {{ $existingRoutes && $existingRoutes->count() > 0 ? $existingRoutes->count() : 1 }};

    document.getElementById('addRouteBtn').addEventListener('click', function() {
        const container = document.getElementById('routesContainer');
        
        const routeHtml = `
            <div class="route-item card mb-3" data-route-index="${routeIndex}">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Route ${routeIndex + 1}</h5>
                        <button type="button" class="btn btn-sm btn-danger remove-route" data-route-index="${routeIndex}">
                            <i class="fas fa-times"></i> Remove
                        </button>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Country <span class="text-danger">*</span></label>
                            <input type="text" name="routes[${routeIndex}][country]" class="form-control" value="Nigeria" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">State <span class="text-danger">*</span></label>
                            <input type="text" name="routes[${routeIndex}][state]" class="form-control" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Area</label>
                            <input type="text" name="routes[${routeIndex}][area]" class="form-control" placeholder="Optional">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Delivery Fee (₦) <span class="text-danger">*</span></label>
                            <input type="number" name="routes[${routeIndex}][fee]" class="form-control" step="0.01" min="0" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Delivery Days <span class="text-danger">*</span></label>
                            <input type="number" name="routes[${routeIndex}][delivery_days]" class="form-control" value="3" min="1" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" name="routes[${routeIndex}][active]" value="1" checked id="active_${routeIndex}">
                                <label class="form-check-label" for="active_${routeIndex}">
                                    Active
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        container.insertAdjacentHTML('beforeend', routeHtml);
        routeIndex++;
        
        // Update remove button visibility
        updateRemoveButtons();
    });

    // Handle remove route
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-route') || e.target.closest('.remove-route')) {
            const btn = e.target.classList.contains('remove-route') ? e.target : e.target.closest('.remove-route');
            const routeItem = btn.closest('.route-item');
            routeItem.remove();
            
            // Renumber routes
            updateRouteNumbers();
            updateRemoveButtons();
        }
    });

    function updateRouteNumbers() {
        const routes = document.querySelectorAll('.route-item');
        routes.forEach((route, index) => {
            route.querySelector('h5').textContent = `Route ${index + 1}`;
        });
    }

    function updateRemoveButtons() {
        const routes = document.querySelectorAll('.route-item');
        routes.forEach((route, index) => {
            const removeBtn = route.querySelector('.remove-route');
            if (routes.length === 1) {
                removeBtn.classList.add('d-none');
            } else {
                removeBtn.classList.remove('d-none');
            }
        });
    }

    // Initial update
    updateRemoveButtons();
</script>
@endsection
