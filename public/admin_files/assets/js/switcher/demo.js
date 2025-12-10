"use strict"
var themeOptionArr = {
	language: '',
	typography: '',
	version: '',
	layout: '',
	primary: '',
	headerBg: '',
	navheaderBg: '',
	sidebarBg: '',
	sidebarStyle: '',
	sidebarPosition: '',
	headerPosition: '',
	containerWidth: '',
	direction: '',
};

(function($) {	
	"use strict"
	
	var direction =  getUrlParams('dir');
	var theme =  getUrlParams('theme');
	
	/* IC Theme Demo Settings  */
	var icThemeSet0 = { /* Default Theme */
		language: "en_GB",
		typography: "inter",
		version: "light",
		layout: "vertical",
		primary: "color_1",
		headerBg: "color_1",
		navheaderBg: "color_5",
		sidebarBg: "color_5",
		sidebarStyle: "full",
		sidebarPosition: "fixed",
		headerPosition: "fixed",
		containerWidth: "boxed",
		direction: 'ltr',
	};
	
	var icThemeSet1 = {
		language: "en_GB",
		typography: "inter",
		version: "light",
		layout: "vertical",
		primary: "color_3",
		headerBg: "color_1",
		navheaderBg: "color_5",
		sidebarBg: "color_5",
		sidebarStyle: "modern",
		sidebarPosition: "fixed",
		headerPosition: "fixed",
		containerWidth: "boxed",
		direction: 'ltr',
	};
	
	var icThemeSet2 = {
		language: "en_GB",
		typography: "poppins",
		version: "light",
		layout: "vertical",
		primary: "color_4",
		headerBg: "color_1",
		navheaderBg: "color_5",
		sidebarBg: "color_5",
		sidebarStyle: "mini",
		sidebarPosition: "fixed",
		headerPosition: "fixed",
		containerWidth: "boxed",
		direction: 'ltr',
	};
	
	var icThemeSet3 = {
		language: "en_GB",
		typography: "poppins",
		version: "light",
		layout: "horizontal",
		primary: "color_1",
		headerBg: "color_5",
		navheaderBg: "color_5",
		sidebarBg: "color_6",
		sidebarStyle: "full",
		sidebarPosition: "fixed",
		headerPosition: "fixed",
		containerWidth: "boxed",
		direction: 'ltr',
	};
	
	function themeChange(theme, direction){
		var themeSettings = {};
		themeSettings = eval('icThemeSet'+theme);
		themeSettings.direction = direction;
		icSettingsOptions = themeSettings; /* For Screen Resize */
		new icSettings(themeSettings);
		
		setThemeInCookie(themeSettings);
	}
	
	function setThemeInCookie(themeSettings){
		jQuery.each(themeSettings, function(optionKey, optionValue) {
			setCookie(optionKey, optionValue);
		});
	}

	function setThemeLogo() {
		var logo = getCookie('logo_src');
		
		var logo2 = getCookie('logo_src2');
		
		if(logo != ''){
			jQuery('.nav-header .logo-abbr').attr("src", logo);
		}
		
		if(logo2 != ''){
			jQuery('.nav-header .logo-compact, .nav-header .brand-title').attr("src", logo2);
		}
	}
	
	function setThemeOptionOnPage(){
		if(getCookie('version') != ''){
			jQuery.each(themeOptionArr, function(optionKey, optionValue) {
				var optionData = getCookie(optionKey);
				themeOptionArr[optionKey] = (optionData != '')?optionData:icSettingsOptions[optionKey];
			});
			
			icSettingsOptions = themeOptionArr;
			new icSettings(icSettingsOptions);
			
			setThemeLogo();
		}
	}
	
	/*  set switcher option start  */
	function getElementAttrs(el) {
		return [].slice.call(el.attributes).map((attr) => {
			return {
				name: attr.name,
				value: attr.value
			}
		});
	}
	
	function handleSetThemeOption(item, index, arr) {
		
		var attrName = item.name.replace('data-','').replace('-','_');
		
		if(attrName === "sidebarbg" || attrName === "primary" || attrName === "headerbg" || attrName === "nav_headerbg" ){
			var attrNameColor = attrName.replace("bg","")
			var itemValue = attrNameColor + "_" + item.value;
			var targetItemValue = document.getElementById(itemValue);
			
			if(item.value === "color_1"){
				if (targetItemValue) {
					targetItemValue.checked = true;
				} else {
					console.warn("Default element not found for ID:", itemValue);
				}
				return false;
			}
			
			if (targetItemValue) {
				targetItemValue.checked = true;
			} else{
				console.warn("Element not found for ID:", itemValue);
			}
		}else if(attrName === "navigationbarimg"){
			document.getElementById("sidebar_img_"+item.value.split('sidebar-img/')[1].split('.')[0]).checked = true;
			
		}else if(attrName === "direction" || attrName === "nav_headerbg" || attrName === "headerbg"){
			document.getElementById("theme_direction").value = item.value;
			
		}else if(attrName === "sidebar_style" || attrName === "theme_version" ){
			const radios = document.querySelectorAll(`input[name="${attrName}"]`);
			
			radios.forEach(radio => {
				if (radio.value === item.value) {
					radio.checked = true;
				}
			});
			
		}else if(attrName === "sidebar_position"){
			if(document.body.getAttribute('data-sidebar-position') === "static") {
				const inputs = document.querySelectorAll(`input[name="${attrName}"]`);
				inputs.forEach(input => {
					if (input.value === item.value) {
						input.checked = true;
					}
				});
			}			
		}else if(attrName === "header_position"){
			if(document.body.getAttribute('data-header-position') === "static") {
				const inputs = document.querySelectorAll(`input[name="${attrName}"]`);
				inputs.forEach(input => {
					if (input.value === item.value) {
						input.checked = true;
					}
				});
			}
		}else if(attrName === "container_width"){
			if(document.body.getAttribute('data-container-width') === "wide") {
				const inputs = document.querySelectorAll(`input[name="${attrName}"]`);
				inputs.forEach(input => {
					if (input.value === item.value) {
						input.checked = true;
					}
				});
			}
		}else if(attrName === "layout"){
			if (document.body.getAttribute("data-layout") === "horizontal") {
				const inputs = document.querySelectorAll(`input[name="${attrName}"]`);
				
				inputs.forEach((input) => {
					if (input.value === item.value) {
						input.checked = true;
					}
				});
			}
		}else if(attrName === "typography"){
			document.getElementById(attrName).value = item.value;
		}
	}
	/* / set switcher option end / */
	
	jQuery(document).on('click', '.ic_theme_demo', function(){

		setTimeout(() => {
			var allAttrs = getElementAttrs(document.querySelector('body'));
			allAttrs.forEach(handleSetThemeOption);
			$('.form-select').selectpicker('refresh')
		},1500);
		
		setTimeout(function(){
            $(window).trigger('resize');
        },200);
		
		var demoTheme = jQuery(this).data('theme');
		themeChange(demoTheme, 'ltr');
		$('.ic-demo-panel').removeClass('show');
		jQuery('.main-switcher').attr('href','/vendor_files/assets/css/switcher.css');
		jQuery('.main-plugins').attr('href','/vendor_files/assets/css/plugins.css');
		jQuery('.main-css').attr('href','/vendor_files/assets/css/style.css');
	});
	
	jQuery(document).on('click', '.ic_theme_demo_rtl', function(){
		var demoTheme = jQuery(this).data('theme');
		themeChange(demoTheme, 'rtl');
		$('.ic-demo-panel').removeClass('show');
		jQuery('.main-switcher').attr('href','/vendor_files/assets/css/switcher-rtl.css');
		jQuery('.main-plugins').attr('href','/vendor_files/assets/css/plugins-rtl.css');
		jQuery('.main-css').attr('href','/vendor_files/assets/css/style-rtl.css');
	});
	
	jQuery(window).on('load', function(){
		direction = (direction != undefined) ? direction : 'ltr';

		if(getCookie('direction') == 'rtl'){
			jQuery('.main-switcher').attr('href','/vendor_files/assets/css/switcher-rtl.css');
			jQuery('.main-plugins').attr('href','/vendor_files/assets/css/plugins-rtl.css');
			jQuery('.main-css').attr('href','/vendor_files/assets/css/style-rtl.css');
		}

		if(theme != undefined){
			if(theme == 'rtl'){
				themeChange(0, 'rtl');
				jQuery('.main-switcher').attr('href','/vendor_files/assets/css/switcher-rtl.css');
				jQuery('.main-plugins').attr('href','/vendor_files/assets/css/plugins-rtl.css');
				jQuery('.main-css').attr('href','/vendor_files/assets/css/style-rtl.css');
			}else {
				themeChange(theme, direction);
			}
		}
		else if(direction != undefined){
			if(getCookie('version') == ''){	
				themeChange(0, direction);
			}
		}
		
		setTimeout(() => {
			var allAttrs = getElementAttrs(document.querySelector('body'));
			allAttrs.forEach(handleSetThemeOption);
			$('.form-select').selectpicker('refresh')
		}, 100);

		/* Set Theme On Page From Cookie */
		setThemeOptionOnPage();
	});
	
	jQuery(window).on('resize', function(){
		setThemeOptionOnPage();
	});
	
})(jQuery);