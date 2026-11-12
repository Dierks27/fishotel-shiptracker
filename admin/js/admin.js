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
			$(document).on('click', '#fst-add-tracking-btn', $.proxy(this.addTracking, this));

			// AJAX remove tracking
			$(document).on('click', '.fst-remove-btn', $.proxy(this.removeTracking, this));

			// AJAX recheck tracking
			$(document).on('click', '.fst-recheck-btn', $.proxy(this.recheckTracking, this));

			// "Select all unshipped items" toggle in the Add Shipment form.
			$(document).on('change', '#fst-select-all-items', function () {
				$('.fst-item-checkbox').prop('checked', this.checked);
			});

			// Keep the master checkbox in sync when individual items change.
			$(document).on('change', '.fst-item-checkbox', function () {
				var total = $('.fst-item-checkbox').length;
				var checked = $('.fst-item-checkbox:checked').length;
				$('#fst-select-all-items').prop('checked', total > 0 && total === checked);
			});

			// Intercept Enter key inside the tracking form so it triggers
			// the AJAX add instead of submitting the WooCommerce order form.
			$(document).on('keypress', '#fst-tracking-number, #fst-carrier', function (e) {
				if (e.which === 13) {
					e.preventDefault();
					$('#fst-add-tracking-btn').trigger('click');
				}
			});

			// Auto-detect carrier on tracking number input
			$(document).on('change', '#fst-tracking-number', $.proxy(this.detectCarrier, this));

			// Label cost: show the custom size box only when "Custom" is picked.
			$(document).on('change', '.fst-cost-box', function () {
				$(this).closest('.fst-cost-fields').find('.fst-cost-dims-row').toggle($(this).val() === 'custom');
			});

			// Label cost: open / close the edit form of an existing shipment.
			$(document).on('click', '.fst-cost-edit-toggle', function (e) {
				e.preventDefault();
				$(this).closest('.fst-shipment-item').find('.fst-cost-form').toggle();
			});

			// Label cost: save the edit form of an existing shipment.
			$(document).on('click', '.fst-cost-save-btn', $.proxy(this.saveLabelCost, this));

			// Enter inside a label cost field must not submit the order form.
			$(document).on('keypress', '.fst-cost-fields input, .fst-cost-fields select', function (e) {
				if (e.which === 13) {
					e.preventDefault();
					var editForm = $(this).closest('.fst-cost-form');
					if (editForm.length) {
						editForm.find('.fst-cost-save-btn').trigger('click');
					} else {
						$('#fst-add-tracking-btn').trigger('click');
					}
				}
			});

			// Enter inside a materials or "how it went out" field must not submit the order form either.
			$(document).on('keypress', '.fst-mat-fields input, .fst-ship-mode input, .fst-ship-mode select', function (e) {
				if (e.which === 13) {
					e.preventDefault();
					$(this).closest('.fst-cost-form, .fst-ship-mode').find('.fst-cost-save-btn, .fst-ship-mode-save').trigger('click');
				}
			});

			// How it went out: show the order number box / the materials for the mode picked.
			$(document).on('change', '.fst-ship-mode-select', function () {
				var box = $(this).closest('.fst-ship-mode');
				box.find('.fst-ship-with-row').toggle($(this).val() === 'with_order');
				box.find('.fst-ship-local-row').toggle($(this).val() === 'local');
			});

			// How it went out: save.
			$(document).on('click', '.fst-ship-mode-save', $.proxy(this.saveShipMode, this));

			// Settings: add / remove a row of the standard boxes, label sources and supplies lists.
			$(document).on('click', '.fst-list-add', $.proxy(this.addListRow, this));
			$(document).on('click', '.fst-list-remove', $.proxy(this.removeListRow, this));

			// Settings: a suggested foam / paper cost is only filled in when it is clicked.
			$(document).on('click', '.fst-suggestion', function (e) {
				e.preventDefault();
				$(this).closest('td').find('input').first().val($(this).data('value')).trigger('change').focus();
			});

			// Settings: the sheet size only matters for the supply used as foam.
			$(document).on('change', '.fst-supply-role', function () {
				$(this).closest('td').find('.fst-foam-sheet').toggle($(this).val() === 'foam');
			});

			// Tab switching on settings page
			$(document).on('click', '.fst-tabs .nav-tab', $.proxy(this.switchTab, this));

			// Test carrier connection buttons
			$(document).on('click', '#fst_test_ups', $.proxy(function () { this.testCarrier('ups', '#fst_test_ups'); }, this));
			$(document).on('click', '#fst_test_usps', $.proxy(function () { this.testCarrier('usps', '#fst_test_usps'); }, this));

			// Test EasyPost connection button
			$(document).on('click', '#fst_test_easypost', $.proxy(this.testEasyPost, this));

			// Generate a webhook secret client-side
			$(document).on('click', '#fst_generate_webhook_secret', $.proxy(this.generateWebhookSecret, this));

			// Copy the webhook URL to the clipboard
			$(document).on('click', '#fst_copy_webhook_url', $.proxy(this.copyWebhookUrl, this));

			// Send test email button
			$(document).on('click', '#fst-send-test-email', $.proxy(this.sendTestEmail, this));
		},

		/**
		 * Add new tracking via AJAX
		 */
		addTracking: function (e) {
			e.preventDefault();
			// Stop the outer WooCommerce order <form> from ever seeing this
			// click — nested form/JS handlers have been the culprit on this
			// button multiple times.
			e.stopPropagation();

			var button = $('#fst-add-tracking-btn');
			var messageBox = $('#fst-tracking-message');
			var orderId = $('#fst-tracking-metabox').data('order-id');
			var trackingNumber = ($('#fst-tracking-number').val() || '').trim();
			var carrier = $('#fst-carrier').val() || 'auto';
			var itemCheckboxes = $('.fst-item-checkbox');
			var checkedItems = itemCheckboxes.filter(':checked').map(function () {
				return $(this).val();
			}).get();

			var showMessage = function (type, text) {
				messageBox
					.removeClass('notice-success notice-error')
					.addClass('notice notice-' + type)
					.html('<p>' + text + '</p>')
					.show();
			};

			if (!orderId) {
				showMessage('error', 'Save the order first, then add tracking.');
				return false;
			}

			if (!trackingNumber) {
				showMessage('error', 'Please enter a tracking number.');
				$('#fst-tracking-number').focus();
				return false;
			}

			// If the per-item picker is rendered (i.e. this is a regular,
			// non-fulfillment order with line items), require at least one
			// item checked. Fulfillment orders render no picker, so the
			// length check is skipped and the server-side fan-out handles
			// the scope per source.
			if (itemCheckboxes.length > 0 && checkedItems.length === 0) {
				showMessage('error', 'Please select at least one item in this shipment.');
				return false;
			}

			button.prop('disabled', true).text('Adding...');
			messageBox.hide();

			$.ajax({
				url: fst_admin.ajax_url,
				type: 'POST',
				// The four optional cost fields ride along; empty ones record nothing.
				data: $.extend(
					{
						action: 'fst_add_tracking',
						nonce: fst_admin.nonce,
						order_id: orderId,
						tracking_number: trackingNumber,
						carrier: carrier,
						line_item_ids: checkedItems,
					},
					this.readCostFields($('#fst-add-cost-fields'))
				),
				success: function (response) {
					if (response && response.success) {
						showMessage('success', response.data.message || 'Tracking added.');
						$('#fst-tracking-number').val('');
						// Reload to show the new shipment in the list.
						// Use replace() so the reload isn't blocked by the
						// WooCommerce "unsaved changes" prompt.
						window.location.replace(window.location.href);
					} else {
						var errMsg = (response && response.data && response.data.message)
							? response.data.message
							: (response && response.data ? response.data : 'Unknown error.');
						showMessage('error', 'Error: ' + errMsg);
					}
				},
				error: function (xhr) {
					showMessage('error', 'Request failed (' + (xhr.status || 'network error') + '). Check the browser console.');
				},
				complete: function () {
					button.prop('disabled', false).text('Add Tracking Info');
				},
			});

			return false;
		},

		/**
		 * Read the label cost inputs inside a wrapper (the add form or an
		 * existing shipment's edit form).
		 */
		readCostFields: function (wrapper) {
			var boxSelect = wrapper.find('.fst-cost-box');
			var dims = (wrapper.find('.fst-cost-dims').val() || '').trim();

			// A standard box is posted as its size; "Custom" uses the text box.
			if (boxSelect.length && boxSelect.val() !== 'custom') {
				dims = boxSelect.val() || '';
			}

			return {
				label_cost: (wrapper.find('.fst-cost-amount').val() || '').trim(),
				box_weight: (wrapper.find('.fst-cost-weight').val() || '').trim(),
				box_dims: dims,
				label_source: (wrapper.find('.fst-cost-source').val() || '').trim(),
			};
		},

		/**
		 * Read the materials inputs inside a wrapper as the JSON the server
		 * takes: a quantity per supply (empty = 0, which removes the line)
		 * and the two flat amounts. Null when the wrapper has no such inputs.
		 */
		readMaterials: function (wrapper) {
			var fields = wrapper.find('.fst-mat-fields');
			if (!fields.length) {
				return null;
			}

			var lines = [];
			fields.find('.fst-mat-qty').each(function () {
				lines.push({
					supply_id: $(this).data('supply-id'),
					qty: ($(this).val() || '').trim() || '0',
				});
			});

			return JSON.stringify({
				lines: lines,
				foam_cost: (fields.find('.fst-mat-foam').val() || '').trim(),
				paper_cost: (fields.find('.fst-mat-paper').val() || '').trim(),
			});
		},

		/**
		 * Save how an order with no label of its own went out: a local
		 * pickup (with its materials), in another order's box, or neither.
		 */
		saveShipMode: function (e) {
			e.preventDefault();
			e.stopPropagation();

			var button = $(e.currentTarget);
			var box = button.closest('.fst-ship-mode');
			var message = box.find('.fst-ship-mode-message');
			var mode = box.find('.fst-ship-mode-select').val();
			var data = {
				action: 'fst_set_ship_mode',
				nonce: fst_admin.nonce,
				order_id: box.data('order-id'),
				mode: mode,
			};

			if (mode === 'with_order') {
				data.with_order_id = (box.find('.fst-ship-with-order').val() || '').trim();
			}
			if (mode === 'local') {
				var materials = this.readMaterials(box.find('.fst-ship-local-row'));
				if (materials !== null) {
					data.materials = materials;
				}
			}

			button.prop('disabled', true);
			message.css('color', '').text('Saving...');

			$.ajax({
				url: fst_admin.ajax_url,
				type: 'POST',
				data: data,
				success: function (response) {
					if (response && response.success) {
						message.css('color', '#1e7e34').text(response.data.message || 'Saved.');
						window.location.replace(window.location.href);
					} else {
						var errMsg = (response && response.data && response.data.message)
							? response.data.message
							: (response && response.data ? response.data : 'Unknown error.');
						message.css('color', '#dc3545').text(errMsg);
					}
				},
				error: function (xhr) {
					message.css('color', '#dc3545').text('Request failed (' + (xhr.status || 'network error') + ').');
				},
				complete: function () {
					button.prop('disabled', false);
				},
			});

			return false;
		},

		/**
		 * Save the label cost of an existing shipment's box via AJAX.
		 * All four fields are sent, so an emptied field is cleared; the
		 * materials ride along when the form shows them.
		 */
		saveLabelCost: function (e) {
			e.preventDefault();
			e.stopPropagation();

			var button = $(e.currentTarget);
			var form = button.closest('.fst-cost-form');
			var message = form.find('.fst-cost-message');

			var data = $.extend(
				{
					action: 'fst_set_label_cost',
					nonce: fst_admin.nonce,
					shipment_id: button.data('id'),
				},
				this.readCostFields(form)
			);
			var materials = this.readMaterials(form);
			if (materials !== null) {
				data.materials = materials;
			}

			button.prop('disabled', true);
			message.css('color', '').text('Saving...');

			$.ajax({
				url: fst_admin.ajax_url,
				type: 'POST',
				data: data,
				success: function (response) {
					if (response && response.success) {
						message.css('color', '#1e7e34').text(response.data.message || 'Saved.');
						// Reload to show the saved cost, as after adding tracking.
						window.location.replace(window.location.href);
					} else {
						var errMsg = (response && response.data && response.data.message)
							? response.data.message
							: (response && response.data ? response.data : 'Unknown error.');
						message.css('color', '#dc3545').text(errMsg);
					}
				},
				error: function (xhr) {
					message.css('color', '#dc3545').text('Request failed (' + (xhr.status || 'network error') + ').');
				},
				complete: function () {
					button.prop('disabled', false);
				},
			});

			return false;
		},

		/**
		 * Settings: append an empty row to a list table by copying its last row.
		 */
		addListRow: function (e) {
			e.preventDefault();

			var tbody = $($(e.currentTarget).data('target')).find('tbody');
			var row = tbody.find('.fst-list-row').last().clone();

			// Next free row number — rows may have been removed, so count
			// from the highest one in use, not from the number of rows.
			var index = 0;
			tbody.find('input, select').each(function () {
				var match = this.name.match(/\[(\d+)\]/);
				if (match) {
					index = Math.max(index, parseInt(match[1], 10) + 1);
				}
			});

			row.find('input, select').each(function () {
				// fst_standard_boxes[3][name] -> fst_standard_boxes[4][name]
				this.name = this.name.replace(/\[\d+\]/, '[' + index + ']');
			});
			this.clearListRow(row);

			tbody.append(row);
			row.find('input:visible').first().focus();
		},

		/**
		 * Settings: empty a list row — text, numbers and the hidden id are
		 * blanked, a checkbox is unticked (its value must stay), a dropdown
		 * goes back to its first choice, and what was only shown for the
		 * saved row (price, on hand, suggestions) is dropped.
		 */
		clearListRow: function (row) {
			row.find('input, select').each(function () {
				if (this.type === 'checkbox') {
					this.checked = false;
				} else if (this.tagName === 'SELECT') {
					this.selectedIndex = 0;
				} else {
					this.value = '';
				}
			});
			row.find('.fst-row-info').empty();
			row.find('.fst-foam-sheet').hide();
		},

		/**
		 * Settings: remove a list row. The last remaining row is emptied
		 * instead, so there is always one to type into.
		 */
		removeListRow: function (e) {
			e.preventDefault();

			var row = $(e.currentTarget).closest('.fst-list-row');
			if (row.siblings('.fst-list-row').length) {
				row.remove();
			} else {
				this.clearListRow(row);
			}
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
			var shipmentId = button.data('id');

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
						alert('Error: ' + (response.data && response.data.message ? response.data.message : response.data));
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
			var shipmentId = button.data('id');
			var badgeSelector = '.fst-status-badge[data-id="' + shipmentId + '"]';

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
						alert('Error: ' + (response.data && response.data.message ? response.data.message : response.data));
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
			var carrierSelect = $(e.currentTarget).closest('.fst-add-tracking-form').find('#fst-carrier');

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

			// USPS pattern: 20-22 digit numbers
			if (/^[0-9]{20,22}$/.test(trackingNumber)) {
				carrierSelect.val('usps');
				return;
			}
		},

		/**
		 * Test carrier API connection
		 */
		testCarrier: function (carrier, buttonSelector) {
			var button = $(buttonSelector);
			var originalText = button.text();
			var resultSpan = button.next('.fst-test-result');

			if (!resultSpan.length) {
				button.after('<span class="fst-test-result" style="margin-left:10px;font-weight:500;"></span>');
				resultSpan = button.next('.fst-test-result');
			}

			button.prop('disabled', true).text('Testing...');
			resultSpan.text('').css('color', '');

			$.ajax({
				url: fst_admin.ajax_url,
				type: 'POST',
				data: {
					action: 'fst_test_carrier',
					nonce: fst_admin.nonce,
					carrier: carrier,
				},
				success: function (response) {
					if (response.success) {
						resultSpan.text(response.data.message).css('color', '#46b450');
					} else {
						resultSpan.text(response.data.message).css('color', '#dc3232');
					}
				},
				error: function () {
					resultSpan.text('Connection failed - AJAX error').css('color', '#dc3232');
				},
				complete: function () {
					button.prop('disabled', false).text(originalText);
				},
			});
		},

		/**
		 * Test EasyPost API connection
		 */
		testEasyPost: function (e) {
			e.preventDefault();

			var button = $('#fst_test_easypost');
			var originalText = button.text();
			var resultSpan = button.next('.fst-test-result');

			if (!resultSpan.length) {
				button.after('<span class="fst-test-result" style="margin-left:10px;font-weight:500;"></span>');
				resultSpan = button.next('.fst-test-result');
			}

			button.prop('disabled', true).text('Testing...');
			resultSpan.text('').css('color', '');

			$.ajax({
				url: fst_admin.ajax_url,
				type: 'POST',
				data: {
					action: 'fst_test_easypost',
					nonce: fst_admin.nonce,
				},
				success: function (response) {
					if (response.success) {
						resultSpan.text(response.data.message).css('color', '#46b450');
					} else {
						resultSpan.text(response.data.message).css('color', '#dc3232');
					}
				},
				error: function () {
					resultSpan.text('Connection failed - AJAX error').css('color', '#dc3232');
				},
				complete: function () {
					button.prop('disabled', false).text(originalText);
				},
			});
		},

		/**
		 * Generate a random 40-character webhook secret and fill the input.
		 * The user copies this into the EasyPost dashboard; the server stores
		 * it verbatim on save.
		 */
		generateWebhookSecret: function (e) {
			e.preventDefault();

			var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
			var secret = '';
			var cryptoObj = window.crypto || window.msCrypto;

			if (cryptoObj && cryptoObj.getRandomValues) {
				var values = new Uint32Array(40);
				cryptoObj.getRandomValues(values);
				for (var i = 0; i < 40; i++) {
					secret += chars.charAt(values[i] % chars.length);
				}
			} else {
				for (var j = 0; j < 40; j++) {
					secret += chars.charAt(Math.floor(Math.random() * chars.length));
				}
			}

			$('#fst_easypost_webhook_secret').val(secret);
		},

		/**
		 * Copy the webhook URL to the clipboard.
		 */
		copyWebhookUrl: function (e) {
			e.preventDefault();

			var input = document.getElementById('fst_easypost_webhook_url');
			if (!input) {
				return;
			}

			input.select();
			input.setSelectionRange(0, 99999);

			try {
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(input.value);
				} else {
					document.execCommand('copy');
				}
			} catch (err) {
				// Ignore — the value is selected for manual copy as a fallback.
			}

			var button = $(e.currentTarget);
			var original = button.text();
			button.text('Copied!');
			setTimeout(function () {
				button.text(original);
			}, 1500);
		},

		/**
		 * Send test email
		 */
		sendTestEmail: function (e) {
			e.preventDefault();

			var button = $(e.currentTarget);
			var originalText = button.text();
			var resultSpan = button.next('.fst-test-result');

			if (!resultSpan.length) {
				button.after('<span class="fst-test-result" style="margin-left:10px;font-weight:500;"></span>');
				resultSpan = button.next('.fst-test-result');
			}

			button.prop('disabled', true).text('Sending...');
			resultSpan.text('').css('color', '');

			$.ajax({
				url: fst_admin.ajax_url,
				type: 'POST',
				data: {
					action: 'fst_send_test_email',
					nonce: fst_admin.nonce,
				},
				success: function (response) {
					if (response.success) {
						resultSpan.text(response.data.message).css('color', '#46b450');
					} else {
						resultSpan.text(response.data.message).css('color', '#dc3232');
					}
				},
				error: function () {
					resultSpan.text('Failed - AJAX error').css('color', '#dc3232');
				},
				complete: function () {
					button.prop('disabled', false).text(originalText);
				},
			});
		},

		/**
		 * Switch settings tabs
		 */
		switchTab: function (e) {
			e.preventDefault();

			var tab = $(e.currentTarget).attr('href').substring(1);
			var wrap = $(e.currentTarget).closest('.wrap');
			var form = wrap.find('form.fst-settings-form');

			// Update active tab styling
			$(e.currentTarget).siblings('.nav-tab').removeClass('nav-tab-active');
			$(e.currentTarget).addClass('nav-tab-active');

			// Show/hide tab content. Some of a tab's content sits outside the
			// settings form (the label cost import is a form of its own), so
			// look in the whole page and match on data-fst-tab as well.
			wrap.find('.fst-tab-content').removeClass('active');
			wrap.find('#' + tab + ', .fst-tab-content[data-fst-tab="' + tab + '"]').addClass('active');

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
				unknown: 'Unknown',
				label_created: 'Label Created',
				pre_transit: 'Pre-Transit',
				in_transit: 'In Transit',
				out_for_delivery: 'Out for Delivery',
				delivered: 'Delivered',
				exception: 'Exception',
				available_for_pickup: 'Available for Pickup',
				return_to_sender: 'Return to Sender',
				failure: 'Delivery Failed',
			};

			return statusMap[status] || status;
		},
	};

	// Initialize on document ready
	$(document).ready(function () {
		FST_Admin.init();
	});
})(jQuery);
