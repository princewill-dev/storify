@extends('home.layout')
@section('title', 'Leave us a message')

@section('content')



	<!-- CONTACTS-1
	============================================= -->
	<section id="contacts-1" class="pb-50 inner-page-hero contacts-section division">				
		<div class="container">


			<!-- SECTION TITLE -->	
		<div class="row justify-content-center">	
				<div class="col-md-10 col-lg-8">
					<div class="section-title text-center mb-80">	

						<!-- Title -->	
						<h2 class="s-52 w-700">Questions? Let's Talk</h2>	

						<!-- Text -->	
						<p class="p-lg">Want to start your digital journey?
							Fill out the form below and we'll get back to you right away
						</p>

					</div>	
				</div>
			</div>

			<div class="row justify-content-center">
				<div class="col-md-10 col-lg-8">
					<div class="form-holder">
						<form name="supportForm" class="row contact-form" id="supportForm">
							@csrf
							<!-- Name -->
							<div class="col-md-6">
								<p class="p-lg">Your Name*</p>
								<input type="text" name="name" class="form-control" placeholder="John Doe" required> 
							</div>
							
							<!-- Email -->
							<div class="col-md-6">
								<p class="p-lg">Your Email*</p>
								<input type="email" name="email" class="form-control" placeholder="john@example.com" required> 
							</div>

							<!-- Phone -->
							<div class="col-md-6">
								<p class="p-lg">Your Phone*</p>
								<input type="text" name="phone" class="form-control" placeholder="+234..." required> 
							</div>

							<!-- Subject -->
							<div class="col-md-6">
								<p class="p-lg">Subject*</p>
								<input type="text" name="subject" class="form-control" placeholder="How can we help?" required> 
							</div>

							<!-- Message -->
							<div class="col-12">
								<p class="p-lg">Your Message*</p>
								<textarea name="message" class="form-control" rows="6" placeholder="Tell us about your project or question..." required></textarea>
							</div>    
															
							<!-- Contact Form Button -->
							<div class="col-md-12 mt-15 form-btn text-right">	
								<button type="submit" class="btn btn--theme hover--theme submit" id="sendBtn">Send Message</button> 
							</div>

							<!-- Contact Form Message -->
							<div class="col-lg-12 contact-form-msg">
								<div class="sending-msg"><span class="loading"></span></div>
							</div>	
														
						</form>
					</div>
				</div>
			</div>
			
				<br>
				<br>
				<div class="contact-form-notice text-center">
					<p class="p-sm">We are committed to your privacy. DigiSwitch uses the information you 
					provide us to contact you about our relevant content, products, and services.
					</p>
				</div>

			<!-- END CONTACT FORM -->


		</div>	   <!-- End container -->	
	</section>	<!-- END CONTACTS-1 -->

	<!-- Success Modal -->
	<div id="supportSuccessModal" class="modal fade auto-off" tabindex="-1" role="dialog" aria-hidden="true" style="padding: 20px;">
		<div class="modal-dialog modal-dialog-centered" role="document">
			<div class="modal-content border-0 shadow-lg">
				<div class="modal-body text-center p-5">
					<div class="mb-4 text-success">
						<i class="fa fa-check-circle display-1 text-success"></i>
					</div>
					<span style="font-size: 5rem;">🎉</span>
					<h6 class="mb-3 fw-bold text-success">Message Received!</h6>
					<p class="mb-5 text-muted">Thank you for contacting us. We have received your message and will get back to you shortly.</p>
					<button type="button" class="btn btn--theme hover--theme px-5" data-bs-dismiss="modal">Close</button>
					<br>
					<br>
				</div>
			</div>
		</div>
	</div>

	@push('scripts')
	<script>
	$(document).ready(function() {
		$('#supportForm').on('submit', function(e) {
			e.preventDefault();
			
			var $form = $(this);
			var $btn = $('#sendBtn');
			var $msg = $('.sending-msg');
			
			// Disable button and show loading
			$btn.prop('disabled', true).text('Sending...');
			$msg.html('<span class="loading"></span> Sending your message...').show();
			
			$.ajax({
				url: "{{ route('home.support.send') }}",
				type: "POST",
				data: $form.serialize(),
				dataType: "json",
				success: function(response) {
					if(response.success) {
						// Reset form
						$form[0].reset();
						// Show success modal
						$('#supportSuccessModal').modal('show');
						$msg.hide();
					} else {
						$msg.html('<span class="text-danger">' + response.message + '</span>').show();
					}
				},
				error: function(xhr) {
					var errorMsg = 'An error occurred. Please try again.';
					if(xhr.responseJSON && xhr.responseJSON.message) {
						errorMsg = xhr.responseJSON.message;
					}
					$msg.html('<span class="text-danger">' + errorMsg + '</span>').show();
				},
				complete: function() {
					// Re-enable button
					$btn.prop('disabled', false).text('Send Message');
				}
			});
		});
	});
	</script>
	@endpush

@endsection
