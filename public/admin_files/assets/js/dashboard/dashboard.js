(function($) {
	
    /* "use strict" */
	var icDashboard = function(){
		
		var screenWidth = $(window).width();
		
		var handleWorldMap = function(trigger = 'load'){
			
			if(jQuery("#worldMap").length>0) {
				
				const map = new jsVectorMap({
					selector: "#worldMap",
					map: "world",

					// Markers (Pins/Points on countries)
					markers: [
						{ name: "Brazil", coords: [-14.2350, -51.9253] },
						{ name: "Canada", coords: [56.1304, -106.3468] },
						{ name: "Mauritania", coords: [20.2540, -10.5800] },
						{ name: "Greenland", coords: [71.7069, -42.6043] },
						{ name: "Libya", coords: [26.3351, 17.2283] },
						{ name: "Congo", coords: [-0.2280, 15.8277] },
						{ name: "Russia", coords: [61.5240, 105.3188] },
						{ name: "Australia", coords: [-25.2744, 133.7751] },
					],

					// Marker style
					markerStyle: {
						initial: {
							r: 5,
							fill: 'var(--bs-primary)',
							stroke: 'var(--bs-primary)',
							strokeWidth: 14,
							strokeOpacity: 0.2,
							fillOpacity: 1
						},
						hover: {
							fill: 'var(--bs-primary)',
						}
					},

					// Region color styling
					regionStyle: {
						initial: {
							fill: 'rgba(var(--bs-primary-rgb), 0.2)'
						},
						hover: {
							fill: 'var(--bs-primary)'
						},
						selected: {
							fill: 'var(--bs-primary)'
						}
					},

					// Selected regions
					selectedRegions: ['CN'], // Default selected country (China)

					// Zoom & pan control
					zoomButtons: false,
					zoomOnScroll: false,
					zoomMax: 12,
					zoomMin: 1,

					// Tooltip settings
					tooltip: {
						show: true,
						render: (marker, index) => `<strong>${marker.name}</strong>`
					},

					// Event hooks
					onRegionClick(event, code) {
						alert(`Region clicked: ${code.toUpperCase()}`);
					},

					onMarkerClick(event, index) {
						const marker = map.markers[index];
						alert(`Marker clicked: ${marker.name}`);
					}
				});
			}
		}
		
		var chartSpendingStatistic  = function(){
			if(jQuery("#chartSpendingStatistic").length>0) {
				var options = {
					series: [
						{
							name: 'Income',
							type: 'column',
							data: [80, 45, 55, 30, 76, 45, 55]
						},
						{
							name: 'Today',
							type: 'column',
							data: [60, 52, 70, 45, 105, 50, 70]
						}
					],
					chart: {
						height: 200,
						type: 'bar',
						stacked: false,
						toolbar: {
							show: false
						}
					},
					grid: {
						show: true,
						strokeDashArray: 3,
						borderColor: 'var(--bs-border-color)',
					},
					dataLabels: {
						enabled: false,
					},
					legend: {
						show: false
					},
					plotOptions: {
						bar: {
							horizontal: false,
							endingShape:'rounded',
							columnWidth: '60%',
							borderRadius: 2,
						},
					},
					fill: {
						type : 'solid',
						gradient: {
							inverseColors: false,
							shade: 'light',
							type: "vertical",
						}
					},
					colors:[
						"var(--bs-secondary)", "var(--bs-primary)"
					],
					labels: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
					markers: {
						size: 0
					},
					stroke: {
						show: true,
						width: 6,
						colors: ['transparent']
					},
					xaxis: {
						type: 'month',
						labels: {
							style: {
								fontSize: '13px',
								colors: 'var(--bs-gray-300)',
							},
						},
						axisBorder: {
							show: true,
							color: 'transparent',
							height: 1,
							width: '100%',
							offsetX: 0,
							offsetY: 0
						},
						axisTicks: {
							show: false,
						},
					},
					yaxis: {
						min: 0,
						max: 100,
						tickAmount: 4,
						labels: {
							show: false
						},
					},
					tooltip: {
						enabled: true,
						shared: false,
						intersect: false,
						
						y: {
							formatter: function (val) {
								return "$ " + val
							}
						}
					},
				};

				var chart = new ApexCharts(document.querySelector("#chartSpendingStatistic"), options);
				chart.render();
			}
		}
		
		var chartTotalSales  = function(){
			if(jQuery("#chartTotalSales").length>0) {
				var options = {
					series: [{
						name: 'Total Sales',
						type: 'column',
						data: [50, 60, 80, 45]
					}],
					chart: {
						height: 100,
						width: 150,
						type: 'bar',
						toolbar: {
						  show: false
						},
						sparkline: {
							enabled: true
						}
					},
					grid: {
						show: false
					},
					dataLabels: {
						enabled: false
					},
					legend: {
						show: false
					},
					plotOptions: {
						bar: {
							horizontal: false,
							columnWidth: '85%',
							borderRadius: 2,
							endingShape: 'rounded',
						}
					},
					fill: {
						type: 'solid',
						colors: undefined
					},
					colors: [
						function({ dataPointIndex }) {
							return dataPointIndex === 1 ? 'var(--bs-secondary)' : 'rgba(var(--bs-secondary-rgb), 0.15)';
						}
					],
					stroke: {
						show: false
					},
					xaxis: {
						labels: {
							show: false
						},
						axisBorder: {
							show: false
						},
						axisTicks: {
							show: false
						},
						tooltip: {
							enabled: false
						}
					},
					yaxis: {
						show: false
					},
					tooltip: {
						enabled: false
					}
				};

				var chart = new ApexCharts(document.querySelector("#chartTotalSales"), options);
				chart.render();
			}
		}
		
		var chartRevenue  = function(){
			if(jQuery("#chartRevenue").length>0) {
				var options = {
					series: [{
						name: 'Total Sales',
						type: 'column',
						data: [50, 60, 80, 45]
					}],
					chart: {
						height: 100,
						width: 150,
						type: 'bar',
						toolbar: {
						  show: false
						},
						sparkline: {
							enabled: true
						}
					},
					grid: {
						show: false
					},
					dataLabels: {
						enabled: false
					},
					legend: {
						show: false
					},
					plotOptions: {
						bar: {
							horizontal: false,
							columnWidth: '85%',
							borderRadius: 2,
							endingShape: 'rounded'
						}
					},
					fill: {
						type: 'solid',
						colors: undefined
					},
					colors: [
						function({ dataPointIndex }) {
							return dataPointIndex === 1 ? '#fff' : 'rgba(255, 255, 255, 0.30)';
						}
					],
					stroke: {
						show: false
					},
					xaxis: {
						labels: {
							show: false
						},
						axisBorder: {
							show: false
						},
						axisTicks: {
							show: false
						},
						tooltip: {
							enabled: false
						}
					},
					yaxis: {
						show: false
					},
					tooltip: {
						enabled: false
					}
				};
				
				var chart = new ApexCharts(document.querySelector("#chartRevenue"), options);
				chart.render();
			}

		}
		
		var chartSalesAnalytics  = function(){
			var options = {
				series: [
					{
						name: 'Earning',
						data: [45, 49, 50, 62, 65, 52, 80, 60, 40, 38, 73, 75]
					},
					{
						name: 'Outgoing',
						data: [25, 24, 12, 20, 21, 32, 33, 38, 20, 25, 24, 20]
					}
				],
				chart: {
					height: 280,
					type: 'area',
					toolbar:{
						show: false
					},
				},
				colors:[
					"var(--bs-primary)",
					"var(--bs-info)"
				],
				dataLabels: {
					enabled: false
				},
				stroke: {
					curve: 'smooth',
					width: 2,
					lineCap: 'square',
				},
				legend:{
					show: false,
				},
				grid:{
					show: true,
					strokeSolidArray: 3,
					borderColor: 'var(--bs-border-color)',
				},
				yaxis: {
					min: 0,
					max: 100,
					tickAmount: 4,
					labels: {
						style: {
							colors: 'var(--bs-gray-300)',
							fontSize: '13px',
						},
						formatter: function (value) {
							return value;
						}
					},
				},
				xaxis: {
					categories: ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
					enabled: true,
					labels:{
						style: {
							colors: 'var(--bs-gray-300)',
							fontSize: '13px',
						},
					},
					axisTicks : {
						show : false
					},
					axisBorder : {
						show : false
					},
				},
				fill:{
					type:'gradient',
					gradient: {
						colorStops:[ 
							[
								{
									offset: 0,
									color: 'var(--bs-primary)',
									opacity: 0.2
								},
								{
									offset: 50,
									color: 'var(--bs-primary)',
									opacity: 0.1
								},
								{
									offset: 80,
									color: 'var(--bs-primary)',
									opacity: 0
								}
							],
							[
								{
									offset: 0,
									color: 'var(--bs-info)',
									opacity: 0.2
								},
								{
									offset: 0.4,
									color: 'var(--bs-info)',
									opacity: 0.2
								},
								{
									offset: 100,
									color: 'var(--bs-info)',
									opacity: 0.2
								}
							],
						]
					},				
				},
				tooltip: {
					x: {
						format: 'dd/MM/yy HH:mm'
					},
				},
				responsive: [{
					breakpoint: 575,
					options: {
						chart : {
							height: 200,
						},
						stroke :{
							width : 3,
						},
						yaxis: {
							labels:{
								style: {
									fontSize: '11px',
								},
							},
						},
						xaxis: {
							labels:{
								style: {
									fontSize: '11px',
								},
							},
						},
					},
				}]
			};
	  
			if(jQuery("#chartSalesAnalytics").length > 0){
				var handleTasksOverTime = new ApexCharts(document.querySelector("#chartSalesAnalytics"), options);
				handleTasksOverTime.render();
			}
		}
		
		var overviewChart = function(){
			if(jQuery("#overiewChart").length>0) {
				var options = {
					series: [
						{
							name: 'Number of Projects',
							type: 'column',
							data: [75, 85, 72, 100, 50, 100, 80, 75, 95, 35, 75,100]
						},
						{
							name: 'Revenue',
							type: 'area',
							data: [44, 65, 55, 75, 45, 55, 40, 60, 75, 45, 50,42]
						},
						{
							name: 'Active Projects',
							type: 'line',
							data: [30, 25, 45, 30, 25, 35, 20, 45, 35, 20, 35,20]
						}
					],
					chart: {
						height: 300,
						type: 'line',
						stacked: false,
						toolbar: {
							show: false,
						},
					},
					grid: {
						borderColor: 'var(--bs-border-color)',
					},
					stroke: {
						width: [0, 1, 1],
						curve: 'straight',
						dashArray: [0, 0, 5],
						color: 'var(--bs-border-color)',
					},
					legend: {
						fontSize: '13px',
						fontFamily: 'poppins',
						labels: {
							colors: 'var(--bs-body-color)',
						}
					},
					plotOptions: {
						bar: {
							columnWidth: '18%',
							borderRadius: 6,
						}
					},
					fill: {
						type : 'gradient',
						gradient: {
							inverseColors: false,
							shade: 'light',
							type: "vertical",
							colorStops : [[
								{
									offset: 0,
									color: 'var(--bs-primary)',
									opacity: 1
								},{
									offset: 100,
									color: 'var(--bs-primary)',
									opacity: 1
								}],
								[{
									offset: 0,
									color: '#3AC977',
									opacity: 1
								},{
									offset: 0.4,
									color: '#3AC977',
									opacity: .15
								},{
									offset: 100,
									color: '#3AC977',
									opacity: 0
								}],
								[{
									offset: 0,
									color: '#FF5E5E',
									opacity: 1
								},{
									offset: 100,
									color: '#FF5E5E',
									opacity: 1
								}],
							],
							stops: [0, 100, 100, 100]
						}
					},
					colors:[
						"var(--bs-primary)", "var(--bs-success)", "var(--bs-danger)"
					],
					labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
					markers: {
						size: 0
					},
					xaxis: {
						type: 'month',
						labels: {
							style: {
							   fontSize: '13px',
							   colors: 'var(--bs-body-color)',
							},
						},
					},
					yaxis: {
						min: 0,
						max: 100,
						tickAmount: 4,
						labels: {
							style: {
								fontSize: '13px',
								colors: 'var(--bs-body-color)',
							},
						},
					},
					tooltip: {
						shared: true,
						intersect: false,
						y: {
							formatter: function (y) {
								if (typeof y !== "undefined") {
									return y.toFixed(0) + " points";
								}
								return y;
							}
						}
					}
				};

				var chart = new ApexCharts(document.querySelector("#overiewChart"), options);
				chart.render();
				
				$(".mix-chart-tab .nav-link").on('click',function(){
					var seriesType = $(this).attr('data-series');
					var columnData = [];
					var areaData = [];
					var lineData = [];
					switch(seriesType) {
						case "week":
							columnData = [75, 85, 72, 100, 50, 100, 80, 75, 95, 35, 75,100];
							areaData = [44, 65, 55, 75, 45, 55, 40, 60, 75, 45, 50,42];
							lineData = [30, 25, 45, 30, 25, 35, 20, 45, 35, 20, 35,20];
							break;
						case "month":
							columnData = [20, 50, 80, 52, 10, 80, 50, 30, 95, 10, 60,85];
							areaData = [40, 25, 85, 45, 85, 25, 95, 65, 45, 45, 20,12];
							lineData = [65, 45, 25, 65, 45, 25, 75, 35, 65, 75, 15,65];
							
							break;
						case "year":
							columnData = [30, 20, 80, 52, 10, 90, 50, 30, 95, 20, 60,85];
							areaData = [40, 25, 40, 45, 85, 25, 50, 65, 45, 60, 20,12];
							lineData = [65, 45, 30, 65, 45, 25, 75, 40, 65, 50, 15,65];
							break;
						case "all":
							columnData = [20, 50, 80, 60, 10, 80, 50, 40, 95, 20, 60,85];
							areaData = [40, 25, 30, 45, 85, 25, 95, 65, 50, 45, 20,12];
							lineData = [65, 45, 25, 65, 45, 25, 30, 35, 65, 75, 15,65];
							break;
						default:
							columnData = [75, 80, 72, 100, 50, 100, 80, 30, 95, 35, 75,100];
							areaData = [44, 65, 55, 75, 45, 55, 40, 60, 75, 45, 50,42];
							lineData = [30, 25, 45, 30, 25, 35, 20, 45, 35, 30, 35,20];
					}
					chart.updateSeries([
						{
							name: "Number of Projects",
							type: 'column',
							data: columnData
						},{
							name: 'Revenue',
							type: 'area',
							data: areaData
						},{
							name: 'Active Projects',
							type: 'line',
							data: lineData
						}
					]);
				})
			}
		}
	
		var earningChart = function(){
			if(jQuery("#earningChart").length>0) {
				var chartWidth = $("#earningChart").width();
			
				var options = {
					series: [
						{
							name: 'Net Profit',
							data: [700, 650, 680, 600, 700, 610, 710, 620],
						},
					],
					chart: {
						type: 'area',
						height: 350,
						width: chartWidth + 60,
						toolbar: {
							show: false,
						},
						offsetX: -50,
						zoom: {
							enabled: false
						},
					},
					colors: ['var(--bs-primary)'],
					dataLabels: {
						enabled: false,
					},
					legend: {
						show: false,
					},
					stroke: {
						show: true,
						width: 2,
						curve: 'straight',
						colors: ['var(--bs-primary)'],
					},
					grid: {
						show: true,
						borderColor: 'var(--bs-border-color)',

						xaxis: {
							lines: {
								show: true
							}
						},
						yaxis: {
							lines: {
								show: false
							}
						},
					},
					yaxis: {
						show: true,
						tickAmount: 4,
						min: 0,
						max: 800,
						labels: {
							offsetX: 50,
							style: {
								colors: 'var(--bs-body-color)',
								fontSize: '12px'
							}
						}
					},
					xaxis: {
						categories: ['', 'May', 'June', 'July', 'Aug', 'Sep', 'Oct'],
						overwriteCategories: undefined,
						
						axisBorder: {
							show: false,
						},
						axisTicks: {
							show: false
						},
						labels: {
							show: true,
							offsetX: 5,
							style: {
								fontSize: '12px',
								colors: 'var(--bs-body-color)' // X-axis label color
							}
						},
					},
					fill: {
						opacity: 0.5,
						colors: 'var(--bs-primary)',
						type: 'gradient',
						gradient: {
							colorStops: [
								{
									offset: 0.6,
									color: 'var(--bs-primary)',
									opacity: 0.2
								},
								{
									offset: 0.6,
									color: 'var(--bs-primary)',
									opacity: 0.15
								},
								{
									offset: 100,
									color: 'white',
									opacity: 0
								}
							],
						}
					},
					tooltip: {
						enabled: true,
						style: {
							fontSize: '12px',
						},
						y: {
							formatter: function (val) {
								return "$" + val + ""
							}
						}
					}
				};

				var chartBar1 = new ApexCharts(document.querySelector("#earningChart"), options);
				chartBar1.render();
				
				$(".earning-chart .nav-link").on('click',function(){
					var seriesType = $(this).attr('data-series');
					var columnData = [];
					switch(seriesType) {
						case "day":
							columnData = [700,650, 680, 650, 700, 610,710,620];
							break;
						case "week":
							columnData = [700,700, 680, 600, 700, 620,710,620];
							break;
						case "month":
							columnData = [700,650, 690, 640, 700, 670,710,620];
							break;
						case "year":
							columnData = [700,650, 590, 650, 700, 610,710,630];
							break;
						default:
							columnData = [700,650, 680, 650, 700, 610,710,620];
					}
					chartBar1.updateSeries([
						{
							name: "Net Profit",
							data: columnData
						}
					]);
				})
			}
		}
		
		var projectChart = function(){
			if(jQuery("#projectChart").length>0) {
				var options = {
					series: [30, 40, 20, 10],
					chart: {
						type: 'donut',
						width: 250,
					},
					plotOptions: {
						pie: {
							donut: {
								size: '90%',
								labels: {
									show: true,
									name: {
										show: true,
										offsetY: 12,
										color: 'var(--bs-body-color)',
									},
									value: {
										show: true,
										fontSize: '24px',
										fontFamily:'Arial',
										fontWeight:'500',
										offsetY: -17,
										color: 'var(--bs-heading-color)',
									},
									total: {
										show: true,
										fontSize: '11px',
										fontWeight:'500',
										fontFamily:'Arial',
										label: 'Total projects',
										color: 'var(--bs-body-color)',
									   
										formatter: function (w) {
											return w.globals.seriesTotals.reduce((a, b) => {
												return a + b
											}, 0)
										}
									}
								}
							}
						}
					},
					stroke: {
						show: true,
						width: 2,
						colors: ['var(--bs-body-bg)'],
					},
					legend: {
						show: false,
					},
					colors: ['var(--bs-warning)', 'var(--bs-primary)', 'var(--bs-success)', 'var(--bs-danger)'],
					labels: ["Pending", "Completed", "Progress", "Cancelled"],
					dataLabels: {
						enabled: false,
					},
				};
				var chartBar1 = new ApexCharts(document.querySelector("#projectChart"), options);
				chartBar1.render();
			}
		}
		
		var swiperReviews = function(){
			if(jQuery(".swiper-reviews").length>0) {
				var swiper = new Swiper(".swiper-reviews", {
					effect: "fade",
					autoplay: {
						delay: 3000,
					},
					pagination: {
						el: ".reviews-swiper-pagination",
						clickable: true, 
						type: "fraction",
					},
				});
			}
		}
		
		var handleCustomerTable = function(){
			if(jQuery('#customerTable').length > 0){
				var table = $('#customerTable').DataTable({
					'dom': 'ZBfrltip',
					buttons: [{
						extend: 'excel',
						text: '<i class="fa-solid fa-file-excel"></i> Export Report',
						className: 'btn btn-primary light btn-sm'
					}],
					searching: true,
					select: false,   
					pageLength:9,			
					lengthChange:false ,
					language: {
						paginate: {
							next: '<i class="fa-solid fa-angle-right"></i>',
							previous: '<i class="fa-solid fa-angle-left"></i>' 
						},
						'search' : '',
						searchPlaceholder: "Search..."
					},
					initComplete: function() {
						var btns = $('#customerTable_wrapper .dt-buttons').detach();
						var filter = $('#customerTable_wrapper .dataTables_filter').detach();
						$('#tableCustomerExcelBTN').append(btns);
						$('#tableCustomerFilter').append(filter);
						
						$('#tableCustomerFilter label').addClass('mb-0');
					}
				});
			}
		}
		
		var handleReportsTable = function(){
			if(jQuery("#reportsTable").length>0) {				
				var reportsTable = $('#reportsTable').DataTable({
					'dom': 'frtip',
					buttons: false,
					searching: false,
					select: false,   
					lengthChange: false,
					language: {
						paginate: {
							next: '<i class="fa-solid fa-angle-right"></i>',
							previous: '<i class="fa-solid fa-angle-left"></i>' 
						}
					},
				});
			}
		}
		
		var handleManageClientTable = function(){
			if(jQuery('#manageClientTable').length > 0){
				var reportsTable = $('#manageClientTable').DataTable({
					'dom': 'ZBfrltip',
					buttons: [{
						extend: 'excel', text: '<i class="fa-solid fa-file-excel"></i> Export Report',
						className: 'btn btn-primary light btn-sm'
					}],
					searching: true,
					select: false,
					pageLength: 8,
					lengthChange: false,
					language: {
						paginate: {
							next: '<i class="fa-solid fa-angle-right"></i>',
							previous: '<i class="fa-solid fa-angle-left"></i>' 
						}
					},
					initComplete: function() {
						var btns = $('#manageClientTable_wrapper .dt-buttons').detach();
						$('#tableManageClientExcelBTN').append(btns);
					}
				});
				
				$('#manageClientTable_filter').hide();
				
				// apply filters when button is clicked
				$('#applyFilter').on('click', function() {
					var status = $('#statusFilter').val().trim();
					var searchFilter = $('#searchFilter').val().trim();
					var gender = $('#genderFilter').val().trim();
					var location = $('#locationFilter').val().trim();

					// Apply filters only if values exist
					reportsTable.search(searchFilter || '', false, false);
					reportsTable.column(3).search(gender ? '^' + gender + '$' : '', true, false);
					reportsTable.column(4).search(location ? '^' + location + '$' : '', true, false);
					reportsTable.column(5).search(status ? '^' + status + '$' : '', true, false);
					reportsTable.draw();
				});
				$('#resetFilter').on('click', function() {
					$('#statusFilter, #searchFilter, #genderFilter, #locationFilter').val('');
					reportsTable.search('').columns().search('').draw();
				});
			}
		}
		
		var handleTaskTable = function(){
			if(jQuery('#taskTable').length > 0){
				var table = $('#taskTable').DataTable({
					'dom': 'ZBfrltip',
					buttons: [{
						extend: 'excel',
						text: '<i class="fa-solid fa-file-excel"></i> Export Report',
						className: 'btn btn-primary light btn-sm'
					}],
					searching: false,
					select: false,   
					lengthChange: false,
					language: {
						paginate: {
							next: '<i class="fa-solid fa-angle-right"></i>',
							previous: '<i class="fa-solid fa-angle-left"></i>' 
						}
					},
					initComplete: function() {
						var btns = $('#taskTable_wrapper .dt-buttons').detach();
						$('#tableEmpoloyeesTBL1ExcelBTN').append(btns);
					}
				});
			}
		}
		
		var handleManagementTable = function(){
			if(jQuery('#managementTable').length > 0){
				var empoloyeesTable = $('#managementTable').DataTable({
					'dom': 'frtip',
					buttons: false,
					searching: false,
					pageLength:5,
					select: false,            
					lengthChange:false ,
					language: {
						paginate: {
							next: '<i class="fa-solid fa-angle-right"></i>',
							previous: '<i class="fa-solid fa-angle-left"></i>' 
						}			
					},
				});
			}
		}
		
		var handleRejectionsTable = function(){
			if(jQuery('#rejectionsTable').length > 0){
				var empoloyeesTable = $('#rejectionsTable').DataTable({
					'dom': 'frtip',
					buttons: false,
					searching: false,
					pageLength:5,
					select: false,            
					lengthChange:false ,
					language: {
						paginate: {
							next: '<i class="fa-solid fa-angle-right"></i>',
							previous: '<i class="fa-solid fa-angle-left"></i>' 
						}			
					},
				});
			}
		}
		
		var handleFinanceTable = function(){
			if(jQuery('#financeTable').length > 0){				
				var table = $('#financeTable').DataTable({
					'dom': 'ZBfrltip',
					buttons: [{
						extend: 'excel',
						text: '<i class="fa-solid fa-file-excel"></i> Export Report',
						className: 'btn btn-primary light btn-sm'
					}],
					searching: false,
					select: false,   
					pageLength: 10,
					lengthChange:false ,
					language: {
						paginate: {
							next: '<i class="fa-solid fa-angle-right"></i>',
							previous: '<i class="fa-solid fa-angle-left"></i>' 
						}
						
					},
					initComplete: function() {
						var btns = $('#financeTable_wrapper .dt-buttons').detach();
						$('#financeTableExcelBTN').append(btns);
					}
				});
			}
		}
		
		var handleEmployeesTable = function(){
			if(jQuery('#employeesTable').length > 0){
				var empoloyeesTable = $('#employeesTable').DataTable({
					dom: 'Bfrtip',
					buttons: [{
						extend: 'excel', text: '<i class="fa-solid fa-file-excel"></i> Export Report',
							className: 'btn btn-primary light btn-sm'
						}
					],
					searching: true,
					select: false,
					lengthChange:false ,
					language: {
						paginate: {
							next: '<i class="fa-solid fa-angle-right"></i>',
							previous: '<i class="fa-solid fa-angle-left"></i>' 
						}
					},
					initComplete: function() {
						var btns = $('#employeesTable_wrapper .dt-buttons').detach();
						$('#employeesTableExcelBTN').append(btns);
					}
				});
				$('#employeesTable_filter').hide();
				
				// apply filters when button is clicked
				$('#applyFilter').on('click', function() {
					var status = $('#statusFilter').val().trim();
					var searchFilter = $('#searchFilter').val().trim();
					var gender = $('#genderFilter').val().trim();
					var department = $('#departmentFilter').val().trim();
					var location = $('#locationFilter').val().trim();
				
					// Apply filters only if values exist
					empoloyeesTable.column(7).search(status ? '^' + status + '$' : '', true, false);
					empoloyeesTable.search(searchFilter || '', false, false);
					empoloyeesTable.column(5).search(gender ? '^' + gender + '$' : '', true, false);
					empoloyeesTable.column(6).search(location ? '^' + location + '$' : '', true, false);
					empoloyeesTable.column(2).search(department || '', false, false);
					empoloyeesTable.draw();
				});
				$('#resetFilter').on('click', function() {
					$('#statusFilter, #searchFilter, #genderFilter, #departmentFilter, #locationFilter').val('');
					empoloyeesTable.search('').columns().search('').draw();
				});
			}
		}
		
		var handleDateTimePicker = function(){
			if(jQuery("#datetimepicker").length>0) {
				$('#datetimepicker').datetimepicker({
					inline: true,
				});
			}
		}
		
		var handlePaymentHistoryTable = function(){
			if(jQuery('#paymentHistoryTable').length > 0){
				var table = $('#paymentHistoryTable').DataTable({
					'dom': 'frtip',
					buttons: false,
					searching: false,
					select: false,   
					pageLength: 6,			
					lengthChange: false,
					language: {
						paginate: {
							next: '<i class="fa-solid fa-angle-right"></i>',
							previous: '<i class="fa-solid fa-angle-left"></i>' 
						}
					},
				});
			}
		}
		
		/* Function ============ */
		return {
			init:function(){
				chartSalesAnalytics();
				chartSpendingStatistic();
				chartTotalSales();
				chartRevenue();
				overviewChart();
				projectChart();
				earningChart();
				swiperReviews();
				handleCustomerTable();
				handleReportsTable();
				handleManageClientTable();
				handleTaskTable();
				handleManagementTable();
				handleRejectionsTable();
				handleFinanceTable();
				handleEmployeesTable();
				handleDateTimePicker();
				handlePaymentHistoryTable();
			},
			
			load:function(){
				handleWorldMap();
			},
			
			resize:function(){
				
			}
		}
	
	}();
	
	jQuery(document).ready(function() {
		icDashboard.init();
	});

	jQuery(window).on('load',function(){
		setTimeout(function(){
			icDashboard.load();
		}, 1000);
	});
	
	jQuery(window).on('resize',function () {
		icDashboard.resize();
	});
	
})(jQuery);