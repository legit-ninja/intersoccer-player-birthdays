(function ($) {
	'use strict';

	function status(message, isError) {
		var $el = $('#intersoccer-pb-ajax-status');
		$el.text(message);
		$el.toggleClass('notice notice-error', !!isError);
		$el.toggleClass('notice notice-success', !isError && !!message);
	}

	function post(action, data) {
		data = data || {};
		data.action = action;
		data.nonce = intersoccerPlayerBirthdays.nonce;
		return $.post(intersoccerPlayerBirthdays.ajaxUrl, data);
	}

	function i18n(key, fallback) {
		var pack = intersoccerPlayerBirthdays.i18n || {};
		return pack[key] || fallback;
	}

	function rowsForUser(userId) {
		return $('#intersoccer-pb-upcoming-table tbody tr').filter(function () {
			return String($(this).attr('data-user-id')) === String(userId);
		});
	}

	function sendStateLabel($row) {
		return $row.attr('data-sent') === '1'
			? i18n('sentThisYear', 'Sent this year')
			: i18n('notSent', 'Not sent');
	}

	function applyUserOptOut(userId, opted) {
		rowsForUser(userId).each(function () {
			var $row = $(this);
			$row.attr('data-opted', opted ? '1' : '0');
			$row.find('.intersoccer-pb-opt-out').prop('checked', opted);
			$row.find('.intersoccer-pb-row-status').text(
				opted ? i18n('optedOut', 'Opted out') : sendStateLabel($row)
			);
			$row.find('.intersoccer-pb-manual-send').prop('disabled', opted);
		});
	}

	function bindUpcomingFilters() {
		var $search = $('#intersoccer-pb-search');
		var $minDays = $('#intersoccer-pb-min-days');
		var $rows = $('#intersoccer-pb-upcoming-table tbody tr');
		var $empty = $('#intersoccer-pb-empty');
		var $table = $('#intersoccer-pb-upcoming-table');
		if (!$rows.length) {
			return;
		}

		function applyFilters() {
			var q = ($search.val() || '').toString().toLowerCase().trim();
			var min = parseInt($minDays.val(), 10);
			if (isNaN(min) || min < 0) {
				min = 0;
			}
			var visible = 0;
			$rows.each(function () {
				var $row = $(this);
				var days = parseInt($row.attr('data-days'), 10) || 0;
				var hay = ($row.attr('data-search') || '').toString();
				var okDays = days >= min;
				var okSearch = !q || hay.indexOf(q) !== -1;
				var show = okDays && okSearch;
				$row.toggle(show);
				if (show) {
					visible += 1;
				}
			});
			$table.toggle(visible > 0);
			$empty.toggleClass('intersoccer-pb-empty-hidden', visible > 0);
		}

		$search.on('input', applyFilters);
		$minDays.on('input change', applyFilters);
		applyFilters();
	}

	$(function () {
		bindUpcomingFilters();

		$('#intersoccer-pb-test-send').on('click', function () {
			var $btn = $(this);
			$btn.prop('disabled', true);
			post('intersoccer_pb_test_send')
				.done(function (res) {
					status((res.data && res.data.message) || '', !res.success);
				})
				.fail(function () {
					status('Request failed.', true);
				})
				.always(function () {
					$btn.prop('disabled', false);
				});
		});

		$(document).on('click', '.intersoccer-pb-manual-send', function () {
			var $btn = $(this);
			if ($btn.prop('disabled')) {
				return;
			}
			$btn.prop('disabled', true);
			post('intersoccer_pb_manual_send', {
				user_id: $btn.data('user-id'),
				player_id: $btn.data('player-id')
			})
				.done(function (res) {
					status((res.data && res.data.message) || '', !res.success);
					if (res.success) {
						window.location.reload();
					} else if ($btn.closest('tr').attr('data-opted') !== '1') {
						$btn.prop('disabled', false);
					}
				})
				.fail(function () {
					status('Request failed.', true);
					if ($btn.closest('tr').attr('data-opted') !== '1') {
						$btn.prop('disabled', false);
					}
				});
		});

		$(document).on('change', '.intersoccer-pb-opt-out', function () {
			var $box = $(this);
			var userId = $box.data('user-id');
			var opted = $box.prop('checked');
			var $boxes = rowsForUser(userId).find('.intersoccer-pb-opt-out');
			applyUserOptOut(userId, opted);
			$boxes.prop('disabled', true);
			post('intersoccer_pb_set_opt_out', {
				user_id: userId,
				opted: opted ? 1 : 0
			})
				.done(function (res) {
					if (!res.success) {
						applyUserOptOut(userId, !opted);
						status((res.data && res.data.message) || '', true);
					}
				})
				.fail(function () {
					applyUserOptOut(userId, !opted);
					status('Request failed.', true);
				})
				.always(function () {
					$boxes.prop('disabled', false);
				});
		});
	});
})(jQuery);
