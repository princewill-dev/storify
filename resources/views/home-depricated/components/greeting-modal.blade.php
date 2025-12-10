@php
    $company = $company ?? null;
    $services = $services ?? collect();
@endphp

<div class="modal fade" id="greetingModal" tabindex="-1" aria-labelledby="greetingModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius: 12px; border: none;">
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="position: absolute; top: 15px; right: 15px; z-index: 10;"></button>
            
            <div class="modal-body p-0">
                <div class="row g-0">
                    <!-- Left side - Welcome message -->
                    <div class="col-12 p-5">
                        <div class="text-center mb-4">
                            <h6 class="text-uppercase mb-2" style="font-size: 12px; letter-spacing: 1px;">Welcome to {{ $company->company_name ?? 'Our Store' }}! 🎉</h6>
                            <p class="text-muted mb-4" style="font-size: 15px;">
                                {{ $company->company_description ?? 'Discover amazing products and services tailored just for you.' }}
                            </p>
                        </div>

                        @if($services->isNotEmpty())
                        <!-- Services Section -->
                        <div class="mb-4">
                            <!-- <h6 class="fw-semibold mb-3">Explore Our Services</h6> -->
                            <div class="accordion" id="servicesAccordion">
                                @foreach($services as $index => $service)
                                <div class="accordion-item border-0 mb-2">
                                    <h2 class="accordion-header" id="heading{{ $index }}">
                                        <button class="accordion-button collapsed py-3 px-3" type="button" data-bs-toggle="collapse" data-bs-target="#collapse{{ $index }}" aria-expanded="false" aria-controls="collapse{{ $index }}" style="background-color: #f8f9fa; border: 1px solid #e9ecef; border-radius: 8px; font-size: 14px; font-weight: 600; color: #212529;">
                                            <i class="fas fa-check-circle text-primary me-2"></i>
                                            {{ $service->title }}
                                        </button>
                                    </h2>
                                    <div id="collapse{{ $index }}" class="accordion-collapse collapse" aria-labelledby="heading{{ $index }}" data-bs-parent="#servicesAccordion">
                                        <div class="accordion-body px-3 py-2" style="font-size: 13px; color: #6c757d;">
                                            <p class="mb-2">{{ $service->description }}</p>
                                            @if($service->page_link)
                                            <a href="{{ $service->page_link }}" class="btn btn-sm btn-outline-primary mt-1" style="font-size: 12px;">
                                                Learn More <i class="fas fa-arrow-right ms-1"></i>
                                            </a>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                @endforeach
                            </div>
                        </div>
                        @endif

                        <!-- Call to Action -->
                        <div class="text-center mt-4">
                            <button type="button" class="btn btn-primary btn-lg px-5" data-bs-dismiss="modal" style="border-radius: 8px;">
                                Start Shopping
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
#greetingModal .modal-content {
    box-shadow: 0 10px 40px rgba(0,0,0,0.1);
    max-height: 90vh;
    overflow-y: auto;
}

#greetingModal .modal-body {
    max-height: 80vh;
    overflow-y: auto;
}

/* Accordion Styling */
#greetingModal .accordion-button {
    box-shadow: none !important;
    transition: all 0.2s ease;
}

#greetingModal .accordion-button:not(.collapsed) {
    background-color: #007bff !important;
    color: white !important;
    border-color: #007bff !important;
}

#greetingModal .accordion-button:not(.collapsed) i {
    color: white !important;
}

#greetingModal .accordion-button:hover {
    background-color: #e9ecef;
    transform: translateX(2px);
}

#greetingModal .accordion-button::after {
    font-size: 0.8rem;
}

#greetingModal .accordion-item {
    border-radius: 8px !important;
    overflow: hidden;
}

#greetingModal .accordion-body {
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
    border-top: none;
    border-radius: 0 0 8px 8px;
}

#greetingModal .btn-primary {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    border: none;
    transition: all 0.3s ease;
}

#greetingModal .btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,123,255,0.3);
}

#greetingModal .btn-close {
    background: transparent;
    opacity: 0.5;
}

#greetingModal .btn-close:hover {
    opacity: 1;
}

/* Mobile Optimization */
@media (max-width: 576px) {
    #greetingModal .modal-dialog {
        margin: 0.5rem;
    }
    
    #greetingModal .modal-body {
        padding: 2rem 1.5rem !important;
    }
    
    #greetingModal h2 {
        font-size: 1.5rem;
    }
    
    #greetingModal .accordion-button {
        font-size: 13px;
        padding: 0.75rem !important;
    }
}
</style>

<script>
    // Set modal data for JavaScript
    window.greetingModalFrequency = '{{ $company->greeting_modal_frequency ?? "never" }}';
    window.greetingModalContent = document.getElementById('greetingModal')?.outerHTML || '';
</script>
