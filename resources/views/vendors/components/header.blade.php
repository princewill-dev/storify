<div class="nav-header">
    <a href="{{ route('admin.dashboard') }}" class="brand-logo" aria-label="Brand Logo">
        <img src="{{ $vendorBrandLogo }}" alt="{{ $vendorBrandName }}" style="width: 40px; height: 40px; object-fit: contain;">
    </a>
    <div class="nav-control">
        <div class="hamburger">
            <span class="line"></span>
            <span class="line"></span>
        </div>
    </div>
</div>

<div class="header">
	<div class="header-content">
		<nav class="navbar navbar-expand">
			<div class="collapse navbar-collapse justify-content-between">
				<a href="{{ route('admin.dashboard') }}" class="brand-logo" aria-label="Brand Logo">
					<img src="{{ $company->favicon }}" alt="{{ $company->name }}" style="width: 50px">
				</a>
				<!-- <div class="header-left">
					<h1 class="main-title">{{ $company->name }}</h1>
				</div> -->
				<ul class="navbar-nav header-right">
					<li class="nav-item">
						<select class="selectpicker form-select i18n-select" id="langSwitcher">
							<option value="en_GB" title="English" data-content="<img src='{{ asset('vendor_files/assets/images/flags/gb-eng.svg') }}' alt=''> English">English</option>
							<option value="pt_BR" title="Portuguese" data-content="<img src='{{ asset('vendor_files/assets/images/flags/pt.svg') }}' alt=''> Português">Português</option>
							<option value="fr_FR" title="French" data-content="<img src='{{ asset('vendor_files/assets/images/flags/fr.svg') }}' alt=''> Français">Français</option>
							<option value="de_DE" title="German" data-content="<img src='{{ asset('vendor_files/assets/images/flags/de.svg') }}' alt=''> Deutsch">Deutsch</option>
							<option value="it_IT" title="Italian" data-content="<img src='{{ asset('vendor_files/assets/images/flags/it.svg') }}' alt=''> Italiano">Italiano</option>
							<option value="jp_JP" title="Japanese" data-content="<img src='{{ asset('vendor_files/assets/images/flags/jp.svg') }}' alt=''> 日本語">日本語</option>
							<option value="es_ES" title="Spanish" data-content="<img src='{{ asset('vendor_files/assets/images/flags/es.svg') }}' alt=''> Español">Español</option>
							<option value="ar_TN" title="Arabic" data-content="<img src='{{ asset('vendor_files/assets/images/flags/arab.svg') }}' alt=''> العربية">العربية</option>
							<option value="tr_TR" title="Turkish" data-content="<img src='{{ asset('vendor_files/assets/images/flags/tr.svg') }}' alt=''> Türkçe">Türkçe</option>
						</select>
					</li>
					<li class="nav-item dropdown notification_dropdown">
						<div class="dropdown">
							<button class="nav-link" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notification Dropdown">
								<i class="fi fi-rr-bell"></i>
							</button>
							<div class="dropdown-menu dropdown-menu-end py-0">
								<div class="ic-scroll p-2" style="height: 380px;">
									<div class="d-flex align-items-center p-2 bg-action-light rounded">
										<div class="d-inline-block">
											<img src="{{ asset('vendor_files/assets/images/avatar/small/avatar1.webp') }}" alt="" class="rounded-circle avatar avatar-sm">
										</div>
										<div class="clearfix ms-2">
											<h6 class="fs-13 mb-0 fw-semibold">Dr sultads Send you Photo</h6>
											<small>29 July 2020 - 02:26 PM</small>
										</div>
									</div>
									<div class="d-flex align-items-center p-2 bg-action-light rounded">
										<div class="d-inline-block">
											<div class="avatar avatar-sm avatar-success rounded-circle">KG</div>
										</div>
										<div class="clearfix ms-2">
											<h6 class="fs-13 mb-0 fw-semibold">Resport created successfully</h6>
											<small>29 July 2020 - 02:26 PM</small>
										</div>
									</div>
									<div class="d-flex align-items-center p-2 bg-action-light rounded">
										<div class="d-inline-block">
											<div class="avatar avatar-sm avatar-primary rounded-circle"><i class="fa fa-home"></i></div>
										</div>
										<div class="clearfix ms-2">
											<h6 class="fs-13 mb-0 fw-semibold">Reminder : Treatment Time!</h6>
											<small>29 July 2020 - 02:26 PM</small>
										</div>
									</div>
									<div class="d-flex align-items-center p-2 bg-action-light rounded">
										<div class="d-inline-block">
											<img src="{{ asset('vendor_files/assets/images/avatar/small/avatar2.webp') }}" alt="" class="rounded-circle avatar avatar-sm">
										</div>
										<div class="clearfix ms-2">
											<h6 class="fs-13 mb-0 fw-semibold">Resport created successfully</h6>
											<small>29 July 2020 - 02:26 PM</small>
										</div>
									</div>
									<div class="d-flex align-items-center p-2 bg-action-light rounded">
										<div class="d-inline-block">
											<img src="{{ asset('vendor_files/assets/images/avatar/small/avatar3.webp') }}" alt="" class="rounded-circle avatar avatar-sm">
										</div>
										<div class="clearfix ms-2">
											<h6 class="fs-13 mb-0 fw-semibold">Dr sultads Send you Photo</h6>
											<small>29 July 2020 - 02:26 PM</small>
										</div>
									</div>
									<div class="d-flex align-items-center p-2 bg-action-light rounded">
										<div class="d-inline-block">
											<div class="avatar avatar-sm avatar-success rounded-circle">KG</div>
										</div>
										<div class="clearfix ms-2">
											<h6 class="fs-13 mb-0 fw-semibold">Resport created successfully</h6>
											<small>29 July 2020 - 02:26 PM</small>
										</div>
									</div>
									<div class="d-flex align-items-center p-2 bg-action-light rounded">
										<div class="d-inline-block">
											<div class="avatar avatar-sm avatar-primary rounded-circle"><i class="fa fa-home"></i></div>
										</div>
										<div class="clearfix ms-2">
											<h6 class="fs-13 mb-0 fw-semibold">Reminder : Treatment Time!</h6>
											<small>29 July 2020 - 02:26 PM</small>
										</div>
									</div>
									<div class="d-flex align-items-center p-2 bg-action-light rounded">
										<div class="d-inline-block">
											<img src="{{ asset('vendor_files/assets/images/avatar/small/avatar4.webp') }}" alt="" class="rounded-circle avatar avatar-sm">
										</div>
										<div class="clearfix ms-2">
											<h6 class="fs-13 mb-0 fw-semibold">Resport created successfully</h6>
											<small>29 July 2020 - 02:26 PM</small>
										</div>
									</div>
								</div>
								<a class="d-block text-center p-3 border-top" href="javascript:void(0);">See all notifications <i class="fa fa-arrow-right"></i></a>
							</div>
						</div>
					</li>
					<li class="nav-item notification_dropdown">
						<a class="nav-link btn-chatbox" href="javascript:void(0);" aria-label="Sidabar Chatbox">
							<i class="fi fi-rr-comment"></i>
						</a>
					</li>
					<li class="nav-item notification_dropdown d-none d-sm-flex">
						<a class="nav-link ic-fullscreen" href="javascript:void(0);" aria-label="Fullscreen">
							<i id="icon-full" class="fi fi-rr-expand"></i>
							<i id="icon-minimize" class="fi fi-rr-compress"></i>
						</a>
					</li>	
					<li class="nav-item dropdown header-profile-dropdown">
						<a class="nav-link" href="javascript:void(0);" role="button" data-bs-toggle="dropdown" aria-expanded="false">
							<div class="profile-head">
								<div class="profile-media">
									<img src="{{ asset('vendor_files/assets/images/avatar/small/avatar5.webp') }}" alt="">
								</div>
							</div>
						</a>
						<ul class="dropdown-menu dropdown-menu-end">
							<li>
								<div class="py-2 d-flex px-3">
									<img src="{{ asset('vendor_files/assets/images/tab/1.jpg') }}" class="avatar avatar-sm rounded-circle" alt="">
									<div class="ms-2">
										<h6 class="mb-0">Thomas Fleming</h6>
										<small>Web Designer</small>
									</div>	
								</div>
							</li>
							<li><hr class="dropdown-divider"></li>
							<li>
								<a class="dropdown-item" href="app-profile.html">
									<svg  width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path fill-rule="evenodd" clip-rule="evenodd" d="M11.9848 15.3462C8.11714 15.3462 4.81429 15.931 4.81429 18.2729C4.81429 20.6148 8.09619 21.2205 11.9848 21.2205C15.8524 21.2205 19.1543 20.6348 19.1543 18.2938C19.1543 15.9529 15.8733 15.3462 11.9848 15.3462Z" stroke="var(--bs-primary)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
										<path fill-rule="evenodd" clip-rule="evenodd" d="M11.9848 12.0059C14.5229 12.0059 16.58 9.94779 16.58 7.40969C16.58 4.8716 14.5229 2.81445 11.9848 2.81445C9.44667 2.81445 7.38857 4.8716 7.38857 7.40969C7.38 9.93922 9.42381 11.9973 11.9524 12.0059H11.9848Z" stroke="var(--bs-primary)" stroke-width="1.42857" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
									<span class="ms-2">Profile</span>
								</a>
							</li>
							<li>
								<a class="dropdown-item" href="app-profile.html">
									<svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-pie-chart">
										<path stroke="var(--bs-primary)" d="M21.21 15.89A10 10 0 1 1 8 2.83"></path>
										<path stroke="var(--bs-primary)" d="M22 12A10 10 0 0 0 12 2v10z"></path>
									</svg>
									<span class="ms-2">My Project</span>
									<span class="badge badge-sm badge-primary light rounded-circle float-end">4</span>
								</a>
							</li>
							<li>
								<a href="javascript:void(0);" class="dropdown-item">
									<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M17.9026 8.85114L13.4593 12.4642C12.6198 13.1302 11.4387 13.1302 10.5992 12.4642L6.11844 8.85114" stroke="var(--bs-primary)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
										<path fill-rule="evenodd" clip-rule="evenodd" d="M16.9089 21C19.9502 21.0084 22 18.5095 22 15.4384V8.57001C22 5.49883 19.9502 3 16.9089 3H7.09114C4.04979 3 2 5.49883 2 8.57001V15.4384C2 18.5095 4.04979 21.0084 7.09114 21H16.9089Z" stroke="var(--bs-primary)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
									<span class="ms-2">Message </span>
								</a>
							</li>
							<li>
								<a href="email-inbox.html" class="dropdown-item">
									<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path fill-rule="evenodd" clip-rule="evenodd" d="M12 17.8476C17.6392 17.8476 20.2481 17.1242 20.5 14.2205C20.5 11.3188 18.6812 11.5054 18.6812 7.94511C18.6812 5.16414 16.0452 2 12 2C7.95477 2 5.31885 5.16414 5.31885 7.94511C5.31885 11.5054 3.5 11.3188 3.5 14.2205C3.75295 17.1352 6.36177 17.8476 12 17.8476Z" stroke="var(--bs-primary)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
										<path d="M14.3888 20.8572C13.0247 22.372 10.8967 22.3899 9.51947 20.8572" stroke="var(--bs-primary)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
									<span class="ms-2">Notification </span>
								</a>
							</li>
							<li>
								<a href="javascript:void(0);" class="dropdown-item">
									<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path fill-rule="evenodd" clip-rule="evenodd" d="M20.8066 7.62355L20.1842 6.54346C19.6576 5.62954 18.4907 5.31426 17.5755 5.83866V5.83866C17.1399 6.09528 16.6201 6.16809 16.1307 6.04103C15.6413 5.91396 15.2226 5.59746 14.9668 5.16131C14.8023 4.88409 14.7139 4.56833 14.7105 4.24598V4.24598C14.7254 3.72916 14.5304 3.22834 14.17 2.85761C13.8096 2.48688 13.3145 2.2778 12.7975 2.27802H11.5435C11.0369 2.27801 10.5513 2.47985 10.194 2.83888C9.83666 3.19791 9.63714 3.68453 9.63958 4.19106V4.19106C9.62457 5.23686 8.77245 6.07675 7.72654 6.07664C7.40418 6.07329 7.08843 5.98488 6.8112 5.82035V5.82035C5.89603 5.29595 4.72908 5.61123 4.20251 6.52516L3.53432 7.62355C3.00838 8.53633 3.31937 9.70255 4.22997 10.2322V10.2322C4.82187 10.574 5.1865 11.2055 5.1865 11.889C5.1865 12.5725 4.82187 13.204 4.22997 13.5457V13.5457C3.32053 14.0719 3.0092 15.2353 3.53432 16.1453V16.1453L4.16589 17.2345C4.41262 17.6797 4.82657 18.0082 5.31616 18.1474C5.80575 18.2865 6.33061 18.2248 6.77459 17.976V17.976C7.21105 17.7213 7.73116 17.6515 8.21931 17.7821C8.70746 17.9128 9.12321 18.233 9.37413 18.6716C9.53867 18.9488 9.62708 19.2646 9.63043 19.5869V19.5869C9.63043 20.6435 10.4869 21.5 11.5435 21.5H12.7975C13.8505 21.5 14.7055 20.6491 14.7105 19.5961V19.5961C14.7081 19.088 14.9088 18.6 15.2681 18.2407C15.6274 17.8814 16.1154 17.6806 16.6236 17.6831C16.9451 17.6917 17.2596 17.7797 17.5389 17.9393V17.9393C18.4517 18.4653 19.6179 18.1543 20.1476 17.2437V17.2437L20.8066 16.1453C21.0617 15.7074 21.1317 15.1859 21.0012 14.6963C20.8706 14.2067 20.5502 13.7893 20.111 13.5366V13.5366C19.6717 13.2839 19.3514 12.8665 19.2208 12.3769C19.0902 11.8872 19.1602 11.3658 19.4153 10.9279C19.5812 10.6383 19.8213 10.3981 20.111 10.2322V10.2322C21.0161 9.70283 21.3264 8.54343 20.8066 7.63271V7.63271V7.62355Z" stroke="var(--bs-primary)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
										<circle cx="12.175" cy="11.889" r="2.63616" stroke="var(--bs-primary)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
									<span class="ms-2">Settings </span>
								</a>
							</li>
							<li><hr class="dropdown-divider"></li>
							<li>
								<a href="page-login.html" class="dropdown-item">
									<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--bs-danger)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
										<path stroke="var(--bs-danger)" d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
										<polyline stroke="var(--bs-danger)" points="16 17 21 12 16 7"></polyline>
										<line x1="21" y1="12" x2="9" y2="12"></line>
									</svg>
									<span class="ms-2 text-danger">Logout </span>
								</a>
							</li>
						</ul>
					</li>
				</ul>
			</div>
		</nav>
	</div>
</div>