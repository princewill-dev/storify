"use strict"

function addSwitcher(){
	
	var icSwitcherPanel=`<div class="switcher-panel" id="icSwitcherPanel">
		<div class="switcher-overlay"></div>
		<a class="switcher-trigger" href="javascript:void(0);">Theme Option</a>
		<div class="switcher-inner">
			<div class="switcher-header">
				<h4 class="switcher-title">Theme Option</h4>
				<div class="nav switcher-nav-tabs" id="switcher-tab" role="tablist">
					<button class="nav-link active" id="switcherPreBuilt-tab" data-bs-toggle="tab" data-bs-target="#switcherPreBuilt" type="button" role="tab" aria-controls="switcherPreBuilt" aria-selected="true">Pre-Build</button>
					<button class="nav-link" id="switcherSettings-tab" data-bs-toggle="tab" data-bs-target="#switcherSettings" type="button" role="tab" aria-controls="switcherSettings" aria-selected="false">Settings</button>
				</div>
			</div>
			<div class="switcher-body">
				<div class="tab-content" id="switcher-tabContent">
					<div class="tab-pane fade show active" id="switcherPreBuilt" role="tabpanel" aria-labelledby="switcherPreBuilt-tab" tabindex="0">
						<div class="switcher-demo">
							<img src="/vendor_files/assets/js/switcher/demo/demo1.webp" alt="">
							<div class="demo-btns">
								<a href="javascript:void(0);" data-theme="0" class="btn ic_theme_demo btn-primary btn-sm me-1">LTR Version</a>
								<a href="javascript:void(0);" data-theme="0" class="btn ic_theme_demo_rtl btn-success btn-sm">RTL Version</a>
							</div>
						</div>
						<div class="switcher-demo">
							<img src="/vendor_files/assets/js/switcher/demo/demo2.webp" alt="">
							<div class="demo-btns">
								<a href="javascript:void(0);" data-theme="1" class="btn ic_theme_demo btn-primary btn-sm me-1">LTR Version</a>
								<a href="javascript:void(0);" data-theme="1" class="btn ic_theme_demo_rtl btn-success btn-sm">RTL Version</a>
							</div>
						</div>
						<div class="switcher-demo">
							<img src="/vendor_files/assets/js/switcher/demo/demo3.webp" alt="">
							<div class="demo-btns">
								<a href="javascript:void(0);" data-theme="2" class="btn ic_theme_demo btn-primary btn-sm me-1">LTR Version</a>
								<a href="javascript:void(0);" data-theme="2" class="btn ic_theme_demo_rtl btn-success btn-sm">RTL Version</a>
							</div>
						</div>
						<div class="switcher-demo">
							<img src="/vendor_files/assets/js/switcher/demo/demo4.webp" alt="">
							<div class="demo-btns">
								<a href="javascript:void(0);" data-theme="3" class="btn ic_theme_demo btn-primary btn-sm me-1">LTR Version</a>
								<a href="javascript:void(0);" data-theme="3" class="btn ic_theme_demo_rtl btn-success btn-sm">RTL Version</a>
							</div>
						</div>
					</div>
					<div class="tab-pane fade" id="switcherSettings" role="tabpanel" aria-labelledby="switcherSettings-tab" tabindex="0">
						<div class="row">
							<div class="col-sm-12 mb-4">
								<h5 class="switcher-label">Theme Mode</h5>
								<div class="row gx-3 mb-1">
									<div class="col-lg-6">
										<div class="switcher-theme">
											<input id="switcherThemeLight" type="radio" name="theme_version" value="light">
											<label for="switcherThemeLight" class="form-check-label">
												<img src="/vendor_files/assets/js/switcher/light.svg" alt="">
												<span class="theme-label">
													<span class="theme-checkbox"></span>
													Light
												</span>
											</label>
										</div>
									</div>
									<div class="col-lg-6">
										<div class="switcher-theme">
											<input id="switcherThemeDark" type="radio" name="theme_version" value="dark">
											<label for="switcherThemeDark" class="form-check-label">
												<img src="/vendor_files/assets/js/switcher/dark.svg" alt="">
												
												<span class="theme-label">
													<span class="theme-checkbox"></span>
													Dark
												</span>
											</label>
										</div>
									</div>
								</div>	
							</div>	
							<div class="col-sm-12 mb-4">
								<h5 class="switcher-label">Font Family</h5>
								<select class="selectpicker form-select switcher-typography" id="typography" name="typography">
									<option value="inter">Choose Font Family</option>
									<option value="inter">Inter</option>
									<option value="roboto">Roboto</option>
									<option value="poppins">Poppins</option>
									<option value="opensans">Open Sans</option>
								</select>
							</div>
							<div class="col-sm-12 mb-3">
								<h5 class="switcher-label">Sidebar Style</h5>
								<div class="row gx-3">
									<div class="col-md-4 mb-1">
										<div class="switcher-sidebar">
											<input id="sidebar_full" type="radio" name="sidebar_style" value="full">
											<label for="sidebar_full" class="form-check-label">
												<svg width="95" height="72" viewBox="0 0 95 72" fill="none" xmlns="http://www.w3.org/2000/svg">
													<rect x="0.75" y="0.75" width="93.5" height="70.5" rx="4.25" fill="white" stroke="var(--panel-color)" stroke-width="1.5"/>
													<path d="M4 0.5H26.5V71.5H4C2.067 71.5 0.5 69.933 0.5 68V4C0.500001 2.067 2.067 0.5 4 0.5Z" fill="var(--panel-color)" stroke="var(--panel-color)"/>
												</svg>
											</label>
											<span class="sidebar-label">Full</span>
										</div>
									</div>
									<div class="col-md-4 mb-1">
										<div class="switcher-sidebar">
											<input id="sidebar_mini" type="radio" name="sidebar_style" value="mini">
											<label for="sidebar_mini" class="form-check-label">
												<svg width="95" height="72" viewBox="0 0 95 72" fill="none" xmlns="http://www.w3.org/2000/svg">
													<rect x="0.75" y="0.75" width="93.5" height="70.5" rx="4.25" fill="white" stroke="var(--panel-color)" stroke-width="1.5"/>
													<path d="M4 0.5H6.5V71.5H4C2.067 71.5 0.5 69.933 0.5 68V4C0.500001 2.067 2.067 0.5 4 0.5Z" fill="var(--panel-color)" stroke="var(--panel-color)"/>
												</svg>
											</label>
											<span class="sidebar-label">Mini</span>
										</div>
									</div>
									<div class="col-md-4 mb-1">
										<div class="switcher-sidebar">
											<input id="sidebar_compact" type="radio" name="sidebar_style" value="compact">
											<label for="sidebar_compact" class="form-check-label">
												<svg width="95" height="72" viewBox="0 0 95 72" fill="none" xmlns="http://www.w3.org/2000/svg">
													<rect x="0.75" y="0.75" width="93.5" height="70.5" rx="4.25" fill="white" stroke="var(--panel-color)" stroke-width="1.5"/>
													<path d="M4 0.5H17.5V71.5H4C2.067 71.5 0.5 69.933 0.5 68V4C0.500001 2.067 2.067 0.5 4 0.5Z" fill="var(--panel-color)" stroke="var(--panel-color)"/>
												</svg>
											</label>
											<span class="sidebar-label">Compact</span>
										</div>
									</div>
									<div class="col-md-4 mb-1">
										<div class="switcher-sidebar">
											<input id="sidebar_modern" type="radio" name="sidebar_style" value="modern">
											<label for="sidebar_modern" class="form-check-label">
												<svg width="95" height="72" viewBox="0 0 95 72" fill="none" xmlns="http://www.w3.org/2000/svg">
													<rect x="0.75" y="0.75" width="93.5" height="70.5" rx="4.25" fill="white" stroke="var(--panel-color)" stroke-width="1.5"/>
													<path d="M4 0.5H17.5V71.5H4C2.067 71.5 0.5 69.933 0.5 68V4C0.500001 2.067 2.067 0.5 4 0.5Z" fill="var(--panel-color)" stroke="var(--panel-color)"/>
													<path opacity="0.5" d="M18 0H33C34.6569 0 36 1.34315 36 3V33C36 34.6569 34.6569 36 33 36H18V0Z" fill="var(--panel-color)"/>
												</svg>
											</label>
											<span class="sidebar-label">Modern</span>
										</div>
									</div>
									<div class="col-md-4 mb-1">
										<div class="switcher-sidebar">
											<input id="sidebar_overlay" type="radio" name="sidebar_style" value="overlay">
											<label for="sidebar_overlay" class="form-check-label">
												<svg width="95" height="72" viewBox="0 0 95 72" fill="none" xmlns="http://www.w3.org/2000/svg">
													<rect x="0.75" y="0.75" width="93.5" height="70.5" rx="4.25" fill="white" stroke="var(--panel-color)" stroke-width="1.5"/>
													<path d="M4 0.5H26.5V71.5H4C2.067 71.5 0.5 69.933 0.5 68V4C0.500001 2.067 2.067 0.5 4 0.5Z" fill="var(--panel-color)" stroke="var(--panel-color)"/>
													<path opacity="0.3" d="M27 0H91C93.2091 0 95 1.79086 95 4V68C95 70.2091 93.2091 72 91 72H27V0Z" fill="var(--panel-color)"/>
												</svg>
											</label>
											<span class="sidebar-label">Overlay</span>
										</div>
									</div>
									<div class="col-md-4 mb-1">
										<div class="switcher-sidebar">
											<input id="sidebar_icon_hover" type="radio" name="sidebar_style" value="icon-hover">
											<label for="sidebar_icon_hover" class="form-check-label">
												<svg width="95" height="72" viewBox="0 0 95 72" fill="none" xmlns="http://www.w3.org/2000/svg">
													<rect x="0.75" y="0.75" width="93.5" height="70.5" rx="4.25" fill="white" stroke="var(--panel-color)" stroke-width="1.5"/>
													<path d="M5 0.5H11.5V71.5H5C3.067 71.5 1.5 69.933 1.5 68V4C1.5 2.067 3.067 0.5 5 0.5Z" fill="var(--panel-color)" stroke="var(--panel-color)"/>
													<rect x="3.61914" y="42.5234" width="5.42857" height="5.42857" rx="2" fill="white"/>
													<rect x="3.61914" y="6.33301" width="5.42857" height="5.42857" rx="2" fill="white"/>
													<rect x="3.61914" y="15.3807" width="5.42857" height="5.42857" rx="2" fill="white"/>
													<rect x="3.61914" y="24.4287" width="5.42857" height="5.42857" rx="2" fill="white"/>
													<rect x="3.61914" y="33.4764" width="5.42857" height="5.42857" rx="2" fill="white"/>
												</svg>
											</label>
											<span class="sidebar-label">Icon Hover</span>
										</div>
									</div>
								</div>
							</div>
							<div class="col-sm-12 mb-4">
								<div class="switcher-switch">
									<svg width="20" height="21" viewBox="0 0 20 21" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M17.4999 8.71417H5.83325" stroke="#081226" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										<path d="M17.5 5.38086H2.5" stroke="#081226" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										<path d="M17.5 12.0475H2.5" stroke="#081226" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										<path d="M17.4999 15.3809H5.83325" stroke="#081226" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
									<span class="switch-label">RTL Mode</span>
									<div class="form-check form-switch">
										<input class="form-check-input" id="theme_direction" type="checkbox" name="theme_direction" value="rtl">
										<label for="theme_direction" class="form-check-label"></label>
									</div>
								</div>
								<div class="switcher-switch">
									<svg width="20" height="21" viewBox="0 0 20 21" fill="none" xmlns="http://www.w3.org/2000/svg">
										<rect x="1.83325" y="2.21417" width="16.3333" height="16.3333" rx="3" stroke="#081226" stroke-width="2"/>
										<path d="M0.833252 5.21417C0.833252 3.00504 2.62411 1.21417 4.83325 1.21417H7.49992V19.5475H4.83325C2.62411 19.5475 0.833252 17.7566 0.833252 15.5475V5.21417Z" fill="#081226"/>
									</svg>
									<span class="switch-label">Sidebar position static</span>
									<div class="form-check form-switch">
										<input class="form-check-input" id="sidebar_position" type="checkbox" name="sidebar_position" value="static">
										<label for="sidebar_position" class="form-check-label"></label>
									</div>
								</div>
								<div class="switcher-switch">
									<svg width="20" height="21" viewBox="0 0 20 21" fill="none" xmlns="http://www.w3.org/2000/svg">
										<rect x="1.83325" y="2.21417" width="16.3333" height="16.3333" rx="3" stroke="#081226" stroke-width="2"/>
										<path d="M0.833252 5.21417C0.833252 3.00503 2.62411 1.21417 4.83325 1.21417H15.1666C17.3757 1.21417 19.1666 3.00503 19.1666 5.21417V7.04751H0.833252V5.21417Z" fill="#081226"/>
									</svg>
									<span class="switch-label">Header position static</span>
									<div class="form-check form-switch">
										<input class="form-check-input" id="header_position" type="checkbox" name="header_position" value="static">
										<label for="header_position" class="form-check-label"></label>
									</div>
								</div>
								<div class="switcher-switch">
									<svg width="20" height="21" viewBox="0 0 20 21" fill="none" xmlns="http://www.w3.org/2000/svg">
										<rect x="1.83325" y="2.21417" width="16.3333" height="16.3333" rx="3" stroke="#081226" stroke-width="2"/>
										<path d="M0.833252 5.21417C0.833252 3.00503 2.62411 1.21417 4.83325 1.21417H15.1666C17.3757 1.21417 19.1666 3.00503 19.1666 5.21417V7.04751H0.833252V5.21417Z" fill="#081226"/>
									</svg>
									<span class="switch-label">Header Horizontal</span>
									<div class="form-check form-switch">
										<input class="form-check-input" id="theme_layout" type="checkbox" name="layout" value="horizontal">
										<label for="theme_layout" class="form-check-label"></label>
									</div>
								</div>
								<div class="switcher-switch">
									<svg width="20" height="21" viewBox="0 0 20 21" fill="none" xmlns="http://www.w3.org/2000/svg">
										<rect x="1.83325" y="2.21417" width="16.3333" height="16.3333" rx="3" stroke="#081226" stroke-width="2"/>
										<path d="M0.833252 5.21417C0.833252 3.00503 2.62411 1.21417 4.83325 1.21417H15.1666C17.3757 1.21417 19.1666 3.00503 19.1666 5.21417V7.04751H0.833252V5.21417Z" fill="#081226"/>
									</svg>
									<span class="switch-label">Wide Width</span>
									<div class="form-check form-switch">
										<input class="form-check-input" id="container_width" type="checkbox" name="container_width" value="wide">
										<label for="container_width" class="form-check-label"></label>
									</div>
								</div>								
							</div>
							<div class="col-sm-12">
								<h5 class="switcher-label">Primary Color</h5>
								<ul class="color-palette">
									<li>
										<input class="chk-col-primary filled-in" id="primary_color_1" type="radio" name="primary_bg" value="color_1">
										<label for="primary_color_1" class="bg-label-pattern"></label>
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="primary_color_2" type="radio" name="primary_bg" value="color_2">
										<label for="primary_color_2"></label>
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="primary_color_3" type="radio" name="primary_bg" value="color_3">
										<label for="primary_color_3"></label>
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="primary_color_4" type="radio" name="primary_bg" value="color_4">
										<label for="primary_color_4"></label>
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="primary_color_5" type="radio" name="primary_bg" value="color_5"> 
										<label for="primary_color_5"></label>
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="primary_color_6" type="radio" name="primary_bg" value="color_6">
										<label for="primary_color_6"></label>
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="primary_color_7" type="radio" name="primary_bg" value="color_7">
										<label for="primary_color_7"></label>
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="primary_color_8" type="radio" name="primary_bg" value="color_8">
										<label for="primary_color_8"></label>
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="primary_color_9" type="radio" name="primary_bg" value="color_9">
										<label for="primary_color_9"></label>
									</li>
								</ul>
							</div>
							<div class="col-sm-12">
								<h5 class="switcher-label">Navigation Header</h5>
								<ul class="color-palette">
									<li>
										<input class="chk-col-primary filled-in" id="nav_header_color_1" type="radio" name="navigation_header" value="color_1">
										<label for="nav_header_color_1" class="bg-label-pattern"></label> 
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="nav_header_color_2" type="radio" name="navigation_header" value="color_2"> 
										<label for="nav_header_color_2"></label> 
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="nav_header_color_3" type="radio" name="navigation_header" value="color_3">
										<label for="nav_header_color_3"></label> 
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="nav_header_color_4" type="radio" name="navigation_header" value="color_4"> 
										<label for="nav_header_color_4"></label>
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="nav_header_color_5" type="radio" name="navigation_header" value="color_5"> 
										<label for="nav_header_color_5"></label> 
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="nav_header_color_6" type="radio" name="navigation_header" value="color_6"> 
										<label for="nav_header_color_6"></label> 
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="nav_header_color_7" type="radio" name="navigation_header" value="color_7"> 
										<label for="nav_header_color_7"></label> 
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="nav_header_color_8" type="radio" name="navigation_header" value="color_8">
										<label for="nav_header_color_8" class="border"></label> 
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="nav_header_color_9" type="radio" name="navigation_header" value="color_9">
										<label for="nav_header_color_9"></label>
									</li>
								</ul>
							</div>
							<div class="col-sm-12">
								<h5 class="switcher-label">Header Color</h5>
								<ul class="color-palette">
									<li>
										<input class="chk-col-primary filled-in" id="header_color_1" type="radio" name="header_bg" value="color_1"> 
										<label for="header_color_1" class="bg-label-pattern"></label> 
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="header_color_2" type="radio" name="header_bg" value="color_2"> 
										<label for="header_color_2"></label>
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="header_color_3" type="radio" name="header_bg" value="color_3"> 
										<label for="header_color_3"></label> 
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="header_color_4" type="radio" name="header_bg" value="color_4">
										<label for="header_color_4"></label>
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="header_color_5" type="radio" name="header_bg" value="color_5">
										<label for="header_color_5"></label> 
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="header_color_6" type="radio" name="header_bg" value="color_6">
										<label for="header_color_6"></label> 
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="header_color_7" type="radio" name="header_bg" value="color_7">
										<label for="header_color_7"></label> 
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="header_color_8" type="radio" name="header_bg" value="color_8"> 
										<label for="header_color_8" class="border"></label> 
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="header_color_9" type="radio" name="header_bg" value="color_9">
										<label for="header_color_9"></label> 
									</li>
								</ul>
							</div>
							<div class="col-sm-12">
								<h5 class="switcher-label">Sidebar Color</h5>
								<ul class="color-palette">
									<li>
										<input class="chk-col-primary filled-in" id="sidebar_color_1" type="radio" name="sidebar_bg" value="color_1"> 
										<label for="sidebar_color_1" class="bg-label-pattern"></label>
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="sidebar_color_2" type="radio" name="sidebar_bg" value="color_2">
										<label for="sidebar_color_2"></label> 
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="sidebar_color_3" type="radio" name="sidebar_bg" value="color_3"> 
										<label for="sidebar_color_3"></label> 
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="sidebar_color_4" type="radio" name="sidebar_bg" value="color_4"> 
										<label for="sidebar_color_4"></label> 
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="sidebar_color_5" type="radio" name="sidebar_bg" value="color_5">
										<label for="sidebar_color_5"></label>
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="sidebar_color_6" type="radio" name="sidebar_bg" value="color_6">
										<label for="sidebar_color_6"></label> 
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="sidebar_color_7" type="radio" name="sidebar_bg" value="color_7">
										<label for="sidebar_color_7"></label>
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="sidebar_color_8" type="radio" name="sidebar_bg" value="color_8"> 
										<label for="sidebar_color_8" class="border"></label>
									</li>
									<li>
										<input class="chk-col-primary filled-in" id="sidebar_color_9" type="radio" name="sidebar_bg" value="color_9">
										<label for="sidebar_color_9"></label>
									</li>
								</ul>
							</div>
						</div>
						<div class="align-items-center d-flex justify-content-between mt-4">
							<a class="btn w-100 btn-danger light me-2" href="javascript:void(0);" onclick="deleteAllCookie()">Reset</a>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>`;
	
	if($("#icSwitcherPanel").length == 0) {
		jQuery('body').append(icSwitcherPanel);
		
		$('.switcher-trigger').on('click', function() {
			$('.switcher-panel').toggleClass('show');
		});
		$('.switcher-overlay').on('click', function() {
			$('.switcher-panel').removeClass('show');
		});
	}
}

(function($) {
    "use strict"
	
	addSwitcher();

	const body = $('body');
    const html = $('html');

    // get the DOM elements from right sidebar
    const languageSelect = $('#langSwitcher');
    const typographySelect = $('#typography');
    const versionSelect = $('input[name="theme_version"]');
    const layoutSelect = $('#theme_layout');
    const sidebarStyleSelect = $('input[name="sidebar_style"]');
    const sidebarPositionSelect = $('#sidebar_position');
    const headerPositionSelect = $('#header_position');
    const containerWidth = $('#container_width');
    const themeDirectionSelect = $('#theme_direction');
	
	// Change the theme version controller
    languageSelect.on('change', function() {
		var langValue = $(this).val();
		body.attr('data-language', langValue);
		setCookie('language', langValue);
    });

    // Change the theme typography controller
    typographySelect.on('change', function() {
        body.attr('data-typography', this.value);
		setCookie('typography', this.value);
    });

    versionSelect.on('change', function() {
		body.attr('data-theme-version', this.value);
		body.attr('data-bs-theme', this.value);
		setCookie('version', this.value);
    });
	
    // Change the sidebar position controller
    sidebarPositionSelect.on('change', function() {
        const value = this.checked === false ?  'fixed' : 'static';
		
		value === "fixed" && body.attr('data-sidebar-style') === "modern" && body.attr('data-layout') === "vertical" ? 
        alert("Sorry, Modern sidebar layout dosen't support fixed position!") :
        body.attr('data-sidebar-position', value);
		setCookie('sidebarPosition', value);
    });
	
    // Change the header position controller
    headerPositionSelect.on('change', function() {
		const value = this.checked === false ?  'fixed' : 'static';
		
		body.attr('data-header-position', value);
		setCookie('headerPosition', value);
	});
	
    // Change the theme direction (rtl, ltr) controller
    themeDirectionSelect.on('change', function() {
		const value = this.checked === false ?  'ltr' : 'rtl';
		
		html.attr('dir', this.value);
        html.attr('class', '');
        html.addClass(this.value);
		
        if(html.attr('dir') === "rtl"){
			jQuery('.main-css').attr('href','/vendor_files/assets/css/style-rtl.css');
			jQuery('.main-plugins').attr('href','/vendor_files/assets/css/plugins-rtl.css');
		}else{
			jQuery('.main-css').attr('href','/vendor_files/assets/css/style.css')
			jQuery('.main-plugins').attr('href','/vendor_files/assets/css/plugins.css')
		}
		
		
		alert(value);

        body.attr('direction', this.value);
		setCookie('direction', this.value);
    });

    // Change the theme layout controller
    layoutSelect.on('change', function() {
        if(body.attr('data-sidebar-style') === 'overlay') {
            body.attr('data-sidebar-style', 'full');
            body.attr('data-layout', this.value);
            return;
        }
		
		const value = this.checked === true ?  'horizontal' : 'vertical';
        body.attr('data-layout', value);
		setCookie('layout', value);
    });
	
	// Change the theme layout controller
    containerWidth.on('change', function() {
        const value = this.checked === true ?  'wide' : 'boxed';
        body.attr('data-container-width', value);
		setCookie('containerWidth', value);
    });
	
    // Change the sidebar style controller
    sidebarStyleSelect.on('change', function() {
        if(body.attr('data-layout') === "horizontal") {
            if(this.value === "overlay") {
                alert("Sorry! Overlay is not possible in Horizontal layout.");
                return;
            }
        }

        if(body.attr('data-layout') === "vertical") {
            if(body.attr('data-container') === "boxed" && this.value === "full") {
                alert("Sorry! Full menu is not available in Vertical Boxed layout.");
                return;
            }
        }
		if (this.value === "modern" || this.value === "mini") {
			$('body').attr('data-sidebar-position', 'static');
		} else {
			$('body').attr('data-sidebar-position', 'fixed');
		}
		
		
        body.attr('data-sidebar-style', this.value);

        if(body.attr('data-sidebar-style') === 'icon-hover') {
            $('.icnav').hover(function() {
                $('#main-wrapper').addClass('iconhover-toggle');
            }, function() {
                $('#main-wrapper').removeClass('iconhover-toggle');
            }); 
        } 
		
		setCookie('sidebarStyle', this.value);
		
        setTimeout(function(){
            $(window).trigger('resize');
        },200);
		
	});
	
	// Change the nav-header background controller
	$('input[name="navigation_header"]').on('click', function () {
		var value = this.value;
		body.attr('data-nav-headerbg', value);
		setCookie('navheaderBg', value);

		if (body.attr('data-layout') === 'horizontal') {
			$('input[name="header_bg"][value="' + value + '"]').prop('checked', true);
			body.attr('data-headerbg', value);
			setCookie('headerBg', value);
		}
	});
	
	$('input[name="header_bg"]').on('click', function () {
		var value = this.value;
		body.attr('data-headerbg', value);
		setCookie('headerBg', value);

		
		if (body.attr('data-layout') === 'horizontal') {
			$('input[name="navigation_header"][value="' + value + '"]').prop('checked', true);
			body.attr('data-nav-headerbg', value);
			setCookie('navheaderBg', value);
		}
	});
	
    // Change the sidebar background controller
    $('input[name="sidebar_bg"]').on('click', function() {
        body.attr('data-sidebarbg', this.value);
		setCookie('sidebarBg', this.value);
    });
	
	// Change the primary color controller
    $('input[name="primary_bg"]').on('click', function() {
        body.attr('data-primary', this.value);
		setCookie('primary', this.value);
    });
	
})(jQuery);