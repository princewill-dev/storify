<!-- {{-- Onboarding Progress Stepper --}}
@php
    $user = $user ?? auth()->user();
    $progress = $user ? $user->getOnboardingProgress() : ['step_number' => 1, 'step' => 'store'];
    $currentStep = $progress['step_number'];
    
    $steps = [
        ['number' => 1, 'name' => 'Store', 'key' => 'store'],
        ['number' => 2, 'name' => 'Payment', 'key' => 'payment-methods'],
        ['number' => 3, 'name' => 'Delivery', 'key' => 'delivery-routes'],
    ];
@endphp

<div class="onboarding-stepper mb-4">
    <div class="stepper-container">
        @foreach($steps as $step)
            <div class="stepper-step {{ $step['number'] < $currentStep ? 'completed' : '' }} {{ $step['number'] == $currentStep ? 'active' : '' }} {{ $step['number'] > $currentStep ? 'pending' : '' }}">
                <div class="step-circle">
                    @if($step['number'] < $currentStep)
                        <i class="fas fa-check"></i>
                    @else
                        <span>{{ $step['number'] }}</span>
                    @endif
                </div>
                <div class="step-label">{{ $step['name'] }}</div>
            </div>
            @if(!$loop->last)
                <div class="step-connector {{ $step['number'] < $currentStep ? 'completed' : '' }}"></div>
            @endif
        @endforeach
    </div>
    <div class="progress-bar-container mt-2">
        <div class="progress" style="height: 4px;">
            <div class="progress-bar bg-success" role="progressbar" style="width: {{ $progress['progress_percentage'] ?? 0 }}%" aria-valuenow="{{ $progress['progress_percentage'] ?? 0 }}" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
        <div class="text-center mt-1 small text-muted">{{ $progress['progress_percentage'] ?? 0 }}% Complete</div>
    </div>
</div>

<style>
.onboarding-stepper {
    max-width: 600px;
    margin: 0 auto;
    padding: 20px 0;
}

.stepper-container {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.stepper-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    flex: 0 0 auto;
}

.step-circle {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    margin-bottom: 8px;
    transition: all 0.3s ease;
}

/* Completed step */
.stepper-step.completed .step-circle {
    background: #198754;
    color: white;
    border: 2px solid #198754;
}

/* Active step */
.stepper-step.active .step-circle {
    background: #0d6efd;
    color: white;
    border: 2px solid #0d6efd;
    box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.2);
}

/* Pending step */
.stepper-step.pending .step-circle {
    background: #f8f9fa;
    color: #6c757d;
    border: 2px solid #dee2e6;
}

.step-label {
    font-size: 12px;
    font-weight: 500;
    color: #6c757d;
    text-align: center;
}

.stepper-step.completed .step-label,
.stepper-step.active .step-label {
    color: #212529;
    font-weight: 600;
}

.step-connector {
    flex: 1;
    height: 2px;
    background: #dee2e6;
    margin: 0 8px;
    margin-bottom: 28px;
    transition: all 0.3s ease;
}

.step-connector.completed {
    background: #198754;
}

/* Responsive */
@media (max-width: 576px) {
    .step-circle {
        width: 32px;
        height: 32px;
        font-size: 14px;
    }
    
    .step-label {
        font-size: 10px;
    }
    
    .step-connector {
        margin: 0 4px;
        margin-bottom: 22px;
    }
}
</style> -->
