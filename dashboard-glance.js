/**
 * Dashboard Glance JavaScript
 *
 * Handles dashboard widget interaction, sorting, settings controls,
 * AJAX requests, and Dashicons picker.
 */
jQuery(document).ready(function($) {
	'use strict';

	if (typeof DashboardGlance === 'undefined') {
		return;
	}

	var $widget = $('#dashboard_right_now');
	if (!$widget.length) {
		return;
	}

	function getNonce(actionKey) {
		if (DashboardGlance.nonces && DashboardGlance.nonces[actionKey]) {
			return DashboardGlance.nonces[actionKey];
		}
		return DashboardGlance.nonce || '';
	}

	// Remove WP default native counters if Dashboard Glance replaces them
	$widget.find('.post-count, .page-count, .comment-count, .comment-mod-count').remove();

	// Inject Settings Action Buttons into Dashboard Widget Header
	function injectSettingsButtons() {
		if (!DashboardGlance.showSettings) {
			return;
		}

		var $settingsWrapper = $('<div id="gt-settings-wrapper" class="dg-settings-wrapper"></div>');

		if (DashboardGlance.showAllStatus) {
			var isStatusVisible = (DashboardGlance.statusVisibility === 'visible');
			var $btnStatus = $('<button id="gt-toggle-status" type="button" class="button-link gt-settings dg-settings"></button>')
				.attr('data-action', isStatusVisible ? 'hide' : 'show');
			
			var $iconVis = $('<span class="dashicons dashicons-visibility"></span>').attr('title', DashboardGlance.i18n.hideStatuses);
			var $iconHid = $('<span class="dashicons dashicons-hidden"></span>').attr('title', DashboardGlance.i18n.revealStatuses);
			if (!isStatusVisible) {
				$iconVis.hide();
			} else {
				$iconHid.hide();
			}
			$btnStatus.append($iconVis).append($iconHid);
			$settingsWrapper.append($btnStatus);
		}

		if (DashboardGlance.editable || DashboardGlance.adminable) {
			var $btnFilter = $('<button id="gt-toggle-form" type="button" class="button-link gt-settings dg-settings"></button>')
				.attr('title', DashboardGlance.i18n.filterGlances)
				.append('<span class="dashicons dashicons-filter"></span>');
			$settingsWrapper.append($btnFilter);
		}

		if (DashboardGlance.adminable) {
			var $btnDefaults = $('<button id="gt-save-defaults" type="button" class="button-link gt-settings dg-settings"></button>')
				.attr('title', DashboardGlance.i18n.saveDefaults)
				.append('<span class="dashicons dashicons-migrate"></span>');
			$settingsWrapper.append($btnDefaults);
		}

		var isInfoVisible = (DashboardGlance.infoVisibility === 'visible');
		var $btnInfo = $('<button id="gt-toggle-info" type="button" class="button-link gt-settings dg-settings"></button>')
			.attr('data-action', isInfoVisible ? 'hide' : 'show');
		var $iconWp = $('<span class="dashicons dashicons-wordpress"></span>').attr('title', DashboardGlance.i18n.toggleWpInfo);
		var $iconWpAlt = $('<span class="dashicons dashicons-wordpress-alt"></span>').attr('title', DashboardGlance.i18n.toggleWpInfo);
		if (!isInfoVisible) {
			$iconWp.hide();
		} else {
			$iconWpAlt.hide();
		}
		$btnInfo.append($iconWp).append($iconWpAlt);
		$settingsWrapper.append($btnInfo);

		// Trigger toggle button
		var $toggleBtn = $('<button id="gt-show-settings" type="button" class="button-link gt-settings dg-settings"></button>')
			.attr('title', DashboardGlance.i18n.revealActions)
			.append('<span class="dashicons dashicons-admin-settings"></span>');

		var $target = $widget.find('.handle-actions');
		if (!$target.length) {
			$target = $widget.find('.handlediv');
		}

		if ($target.length) {
			$target.before($toggleBtn);
			$target.before($settingsWrapper);
		} else {
			$widget.find('h2.hndle, .postbox-header').append($toggleBtn).append($settingsWrapper);
		}

		// Defaults prompt container
		if (!$('#gt-defaults').length) {
			var $defaultsPrompt = $('<div id="gt-defaults" class="notice notice-info is-dismissible dg-defaults-notice" style="display:none;"><p></p></div>');
			var $p = $defaultsPrompt.find('p');
			$p.text(DashboardGlance.i18n.applyPrompt + ' ');

			var $btnAll = $('<button id="gt-save-defaults-all" type="button" class="button-link" data-action="all"><span class="dashicons dashicons-groups"></span> </button>')
				.append(document.createTextNode(DashboardGlance.i18n.allUsers));
			var $btnNew = $('<button id="gt-save-defaults-new" type="button" class="button-link" data-action="new"><span class="dashicons dashicons-admin-users"></span> </button>')
				.append(document.createTextNode(DashboardGlance.i18n.newUsers));

			$p.append($btnAll).append(' ').append($btnNew);
			$('#wpbody-content .wrap > h1, #wpbody-content .wrap > hr').first().after($defaultsPrompt);
		}
	}

	injectSettingsButtons();

	// Hover or click to reveal settings wrapper
	$(document).on('click mouseenter', '#gt-show-settings', function() {
		$(this).hide();
		$('#gt-settings-wrapper').show();
	});

	// Toggle status badges
	$(document).on('click', '#gt-toggle-status', function(e) {
		e.preventDefault();
		var $btn = $(this);
		var action = $btn.data('action');

		$.post(DashboardGlance.ajaxurl, {
			action: 'dg_toggle_status_visibility',
			nonce: getNonce('toggleStatus'),
			gt_action: action
		}, function(res) {
			if (res && res.success) {
				$('.gt-statuses').toggle();
				$btn.find('.dashicons').toggle();
				$btn.data('action', (action === 'show' ? 'hide' : 'show'));
			}
		});
	});

	// Toggle WordPress version and sub info
	$(document).on('click', '#gt-toggle-info', function(e) {
		e.preventDefault();
		var $btn = $(this);
		var action = $btn.data('action');

		$.post(DashboardGlance.ajaxurl, {
			action: 'dg_toggle_info_visibility',
			nonce: getNonce('toggleInfo'),
			gt_action: action
		}, function(res) {
			if (res && res.success) {
				$('#wp-version-message, #wp-version-message + p').toggle();
				$widget.find('.inside .sub > *:not(#gt-form)').toggle();
				$btn.find('.dashicons').toggle();
				$btn.data('action', (action === 'show' ? 'hide' : 'show'));
			}
		});
	});

	// Toggle add/remove form
	$(document).on('click', '#gt-toggle-form', function(e) {
		e.preventDefault();
		$('#gt-form').toggle();
	});

	// Show defaults prompt
	$(document).on('click', '#gt-save-defaults', function(e) {
		e.preventDefault();
		$('.gt-message').remove();
		$('#gt-defaults').show();
	});

	// Save defaults action
	$(document).on('click', 'button[id^="gt-save-defaults-"]', function(e) {
		e.preventDefault();
		var action = $(this).data('action');

		$.post(DashboardGlance.ajaxurl, {
			action: 'dg_default_glances',
			nonce: getNonce('defaultGlances'),
			gt_action: action
		}, function(res) {
			if (res && res.success) {
				$('#gt-defaults').hide();
				if (res.notice) {
					$('#wpbody-content .wrap > h1, #wpbody-content .wrap > hr').first().after(res.notice);
				}
			}
		});
	});

	// Initialize sortable list
	function initSortable() {
		var $list = $widget.find('.inside .main ul');
		if (!$list.length) {
			return;
		}

		// Apply IDs for items without them
		$list.find('li').each(function() {
			var $item = $(this).find('.gt-item');
			if ($item.length && $item.hasClass('unordered')) {
				var order = $item.attr('data-order');
				if (order) {
					$(this).attr('id', order);
					$item.removeClass('unordered');
				}
			}
		});

		if (DashboardGlance.editable || DashboardGlance.adminable) {
			$list.sortable({
				placeholder: 'element-holder',
				forcePlaceholderSize: true,
				containment: 'parent',
				cursor: 'move',
				cursorAt: { top: 15, left: 15 },
				update: function() {
					var sortOrder = $list.sortable('toArray');
					$.post(DashboardGlance.ajaxurl, {
						action: 'dg_sort_glances',
						nonce: getNonce('sortGlances'),
						gt_sort: sortOrder
					});
				}
			}).disableSelection();
		}
	}

	initSortable();

	// Handle add/remove glance form submission
	$('#gt-form').on('submit', function(e) {
		e.preventDefault();

		var glanceItem = $('#gt-item').val();
		var glanceIcon = $('#gt-item-icon').val();
		var btnVal = $('#submit-gt-item').val();
		var formAction = (btnVal === 'Remove') ? 'remove_gt_item' : 'add_gt_item';

		if (!glanceItem) {
			return;
		}

		$.post(DashboardGlance.ajaxurl, {
			action: 'dg_add_remove_glance',
			nonce: getNonce('processForm'),
			gt_action: formAction,
			gt_item: glanceItem,
			gt_item_icon: glanceIcon
		}, function(res) {
			if (res && res.success) {
				var $glanceList = $widget.find('ul.ui-sortable, .inside .main ul').first();
				$glanceList.empty();

				if (res.elements && res.elements.length) {
					$.each(res.elements, function(idx, el) {
						$glanceList.append($('<li class="ui-sortable-handle">' + el + '</li>'));
					});
				}
				$glanceList.append($('<li style="display:none;"></li>'));

				// Refresh sortable IDs
				initSortable();

				// Toggle data-glancing on option
				var $option = $('#gt-item').find('option[value="' + res.glance + '"]');
				if ($option.attr('data-glancing') === 'shown') {
					$option.attr('data-glancing', 'hidden');
				} else {
					$option.attr('data-glancing', 'shown');
				}

				// Reset form
				$('#gt-item').val('');
				$('#visible-icon').attr('alt', 'f159').attr('class', 'dashicon dashicons-marker');
				$('input[data-dashicon="selected"]').val('f159');
				$('#submit-gt-item').hide();

				// Display notice
				$('.gt-message').remove();
				if (res.notice) {
					$('#wpbody-content .wrap > h1, #wpbody-content .wrap > hr').first().after(res.notice);
				}
			}
		});
	});

	// Dropdown item change
	$('#gt-item').on('change', function() {
		var $selected = $(this).find(':selected');
		var iconCode = $selected.attr('data-dashicon') || 'f159';
		var glancing = $selected.attr('data-glancing');

		if (!$(this).val()) {
			$('#submit-gt-item').hide();
			return;
		}

		var customIcons = ['formidableform', 'gravityform', 'gravityview', 'give', 'sliced'];
		if (customIcons.indexOf(iconCode) !== -1) {
			$('#visible-icon').attr('alt', iconCode).attr('class', iconCode);
			$('input[data-dashicon="selected"]').val(iconCode);
		} else if (DashboardGlance.icons && DashboardGlance.icons[iconCode]) {
			var iconSlug = DashboardGlance.icons[iconCode];
			$('#visible-icon').attr('alt', iconCode).attr('class', 'dashicon dashicons-' + iconSlug);
			$('input[data-dashicon="selected"]').val(iconCode);
		}

		$('#submit-gt-item').show();
		if (glancing === 'shown') {
			$('#submit-gt-item').val('Remove');
		} else {
			$('#submit-gt-item').val('Add');
		}
	});

	// Dashicons Picker plugin
	$.fn.dashiconsPicker = function() {
		return this.each(function() {
			var $button = $(this);

			$button.off('click.dashiconsPicker').on('click.dashiconsPicker', function() {
				var currentVal = $('#gt-item').val();
				var nonDashiconItems = ['formidableform', 'gravityform', 'gravityview', 'give_forms', 'sliced_quote', 'sliced_invoice'];
				if (nonDashiconItems.indexOf(currentVal) === -1) {
					createPopup($button);
				}
			});

			function createPopup($btn) {
				removePopup();

				var $target = $('#gt-item-icon');
				var currentCode = $target.val();

				var $popup = $('<div class="dashicon-picker-container">' +
					'<div class="dashicon-picker-control"></div>' +
					'<ul class="dashicon-picker-list"></ul>' +
					'</div>').css({
					top: $btn.offset().top + 32,
					left: $btn.offset().left
				});

				var $list = $popup.find('.dashicon-picker-list');
				var icons = DashboardGlance.icons || {};

				$.each(icons, function(code, slug) {
					var activeClass = (code === currentCode) ? ' active' : '';
					$list.append('<li data-icon="' + slug + '" class="icon' + activeClass + '">' +
						'<a href="#" title="' + slug + '" data-code="' + code + '">' +
						'<span class="dashicons dashicons-' + slug + '"></span>' +
						'</a></li>');
				});

				$list.on('click', 'a', function(e) {
					e.preventDefault();
					var code = $(this).data('code');
					var title = $(this).attr('title');
					$target.val(code);
					$('#visible-icon').attr('alt', code).attr('class', 'dashicon dashicons-' + title + ' dashicons-picker');
					removePopup();
				});

				var $control = $popup.find('.dashicon-picker-control');
				$control.html('<input type="text" placeholder="' + DashboardGlance.i18n.searchIcons + '" class="dg-icon-search" />');

				$control.find('input').on('keyup', function() {
					var term = $(this).val().toLowerCase();
					if (term === '') {
						$list.find('li').show();
					} else {
						$list.find('li').each(function() {
							var slug = $(this).data('icon') || '';
							if (slug.toLowerCase().indexOf(term) !== -1) {
								$(this).show();
							} else {
								$(this).hide();
							}
						});
					}
				});

				$popup.appendTo('body').show();
				$control.find('input').focus();

				$(document).on('mouseup.dgPicker', function(e) {
					if (!$popup.is(e.target) && $popup.has(e.target).length === 0) {
						removePopup();
					}
				});
			}

			function removePopup() {
				$('.dashicon-picker-container').remove();
				$(document).off('mouseup.dgPicker');
			}
		});
	};

	$('.dashicons-picker').dashiconsPicker();
});
