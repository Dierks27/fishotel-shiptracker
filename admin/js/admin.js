/**
 * FisHotel ShipTracker - Admin JavaScript
 */

(function ($) {
	'use strict';

	var FST_Admin = {
		/**
		 * Initialize admin functionality
		 */
		init: function () {
			this.bindEvents();
		},

		/**
		 * Bind event handlers
		 */
		bindEvents: function () {
			// AJAX add tracking
			$(document).on('click', '.fst-add-tracking-btn', $.proxy(this.addTracking, this));

			// AJAX remove tracking
			$(document).on('click', '.fst-remove-tracking-btn', $.proxy(this.removeTracking, this));

			// AJAX recheck tracking
			$(document).on('click', '.fst-recheck-btn', $.proxy(this.recheckTracking, this));

			// Auto-detect carrier on tracking number input
			$(document).on('change', '.fst-tracking-number-input', $.proxy(this.detectCarrier, this));

			// Tab switching on settings page
			$(document).on('click', '.fst-tabs .nav-tab', $.proxy(this.switchTab, this));
		},

		/**
		 * Add new tracking via AJAX
		 */
		addTracking: function (e) {
			e.preventDefault();

			var button = $(e.currentTarget);
			var container = button.closest('.fst-shipment-form');

			var orderId = container.find('.fst-order-id').val();
			var trackingNumber = container.find('.fst-tracking-number-input').val();
			var carrier = container.find('.fst-carrier-select').val();

			if (!orderId || !trackingNumber || !carrier) {
				alert('Please fill in all fields');
				return;
			}

			button.prop('disabled', true).text('Adding...');

			$.ajax({
				url: fst_admin.ajax_url,
				type: 'POST',
				data: {
					action: 'fst_add_tracking',
					nonce: fst_admin.nonce,
					order_id: orderId,
					tracking_number: trackingNumber,
					carrier: carrier,
				},
				success: $.proxy(function (response) {
					if (response.success) {
						// Show success message
						container.before(
							'<div class="notice notice-success"><p>' +
								response.data.message +
							'</p></div>'
						);

						// Refresh shipment area
						this.refreshShipments(orderId);

						// Clear form
						container.find('input, select').val('');
					} else {
						alert('Error: ' + response.data);
					}
				}, this),
				error: function () {
					alert('AJAX error occurred');
				},
				complete: function () {
					button.prop('disabled', false).text('Add Tracking');
				},
			});
		},

		/**
		 * Remove tracking via AJAX
		 */
		removeTracking: function (e) {
			e.preventDefault();

			if (!confirm('Are you sure you want to remove this tracking number?')) {
				return;
			}

			var button = $(e.currentTarget);
			var shipmentId = button.data('shipment-id');

			button.prop('disabled', true);

			$.ajax({
				url: fst_admin.ajax_url,
				type: 'POST',
				data: {
					action: 'fst_remove_tracking',
					nonce: fst_admin.nonce,
					shipment_id: shipmentId,
				},
				success: $.proxy(function (response) {
					if (response.success) {
						// Remove shipment item
						button.closest('.fst-shipment-item').fadeOut(300, function () {
							$(this).remove();
						});
					} else {
						alert('Error: ' + response.data);
					}
				}, this),
				error: function () {
					alert('AJAX error occurred');
				},
				complete: function () {
					button.prop('disabled', false);
				},
			});
		},

		/**
		 * Recheck tracking status via AJAX
		 */
		recheckTracking: function (e) {
			e.preventDefault();

			var button = $(e.currentTarget);
			var shipmentId = button.data('shipment-id');
			var badgeSelector = '.fst-status-badge[data-shipment-id="' + shipmentId + '"]';

			button.prop('disabled', true).text('Checking...');

			$.ajax({
				url: fst_admin.ajax_url,
				type: 'POST',
				data: {
					action: 'fst_recheck_tracking',
					nonce: fst_admin.nonce,
					shipment_id: shipmentId,
				},
				success: $.proxy(function (response) {
					if (response.success) {
						var data = response.data;

						// Update status badge
						var badge = $(badgeSelector);
						badge.removeClass('pending shipped in-transit out-for-delivery delivered exception returned');
						badge.addClass(data.status);
						badge.text(this.formatStatus(data.status));

						// Show success message
						button.closest('.fst-shipment-item').before(
							'<div class="notice notice-success is-dismissible"><p>Tracking updated successfully</p></div>'
						);
					} else {
						alert('Error: ' + response.data);
					}
				}, this),
				error: function () {
					alert('AJAX error occurred');
				},
				complete: function () {
					button.prop('disabled', false).text('Recheck');
				},
			});
		},

		/**
		 * Auto-detect carrier from tracking number
		 */
		detectCarrier: function (e) {
			var trackingNumber = $(e.currentTarget).val().trim();
			var carrierSelect = $(e.currentTarget).closest('form').find('.fst-carrier-select');

			if (!trackingNumber) {
				return;
			}

			// UPS pattern: 1Z followed by 16 characters
			if (/^1Z[A-Z0-9]{16}$/.test(trackingNumber)) {
				carrierSelect.val('ups');
				return;
			}

			// USPS pattern: 9400 or 9200 followed by numbers
			if (/^(9400|9200)[0-9]{16}$/.test(trackingNumber)) {
				carrierSelect.val('usps');
				return;
			}

			// FedEx pattern: starts with digits, contains specific patterns
			if (/^[0-9]{12,14}$/.test(trackingNumber)) {
				carrierSelect.val('usps'); // Default to USPS for numeric patterns
				return;
			}
		},

		/**
		 * Switch settings tabs
		 */
		switchTab: function (e) {
			e.preventDefault();

			var tab = $(e.currentTarget).attr('href').substring(1);
			var form = $(e.currentTarget).closest('.wrap').find('form');

			// Update active tab styling
			$(e.currentTarget).siblings('.nav-tab').removeClass('nav-tab-active');
			$(e.currentTarget).addClass('nav-tab-active');

			// Show/hide tab content
			form.find('.fst-tab-content').removeClass('active');
			form.find('#' + tab).addClass('active');

			// Update hidden field
			form.find('input[name="fst_tab"]').val(tab);

			// Scroll to form
			$('html, body').animate(
				{
					scrollTop: form.offset().top - 100,
				},
				300
			);
		},

		/**
		 * Refresh shipments display
		 */
		refreshShipments: function (orderId) {
			// This would typically fetch and update the shipment list
			// For now, we'll just reload the page to show updates
			location.reload();
		},

		/**
		 * Format status for display
		 */
		formatStatus: function (status) {
			var statusMap = {
				pending: 'Pending',
				shipped: 'Shipped',
				in_transit: 'In Transit',
				out_for_delivery: 'Out for Delivery',
				delivered: 'Delivered',
				exception: 'Exception',
				returned: 'Returned',
			};

			return statusMap[status] || status;
		},
	};

	// Initialize on document ready
	$(document).ready(function () {
		FST_Admin.init();
	});
})(jQuery);
