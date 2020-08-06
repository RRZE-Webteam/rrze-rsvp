"use strict";

jQuery(document).ready(function () {
    var DATE_FORMAT = rrze_rsvp_admin.dateformat,
        TEXT_CANCEL = rrze_rsvp_admin.text_cancel,
        TEXT_CANCELLED = rrze_rsvp_admin.text_cancelled,
        TEXT_CONFIRMED = rrze_rsvp_admin.text_confirmed,
        API_AJAXURL = rrze_rsvp_admin.ajaxurl;

	function bookingAction(type, button) {
		var id = button.attr('data-id'),
			href = button.attr('href');

		jQuery.ajax({
			type: "POST",
			url: API_AJAXURL,
			data: {
				action: 'booking_action',
				id: id,
				type: type
			}
		}).fail(function (jqXHR) {
			console.error("AJAX request failed");
		}).done(function(data) {
			data = JSON.parse(data);
			jQuery.ajax({
				type: "GET",
				url: href,
			}).fail(function (jqXHR) {
				console.error("AJAX request failed");
			}).done(function(data) {
				if (type == 'confirm') {
					button.addClass('rrze-rsvp-confirmed').attr('disabled', 'disabled').html(TEXT_CONFIRMED);
				} else {
					button.attr('disabled', 'disabled').html(TEXT_CANCELLED);
				}
			});

		});

	}

    jQuery('.rrze-rsvp-confirm').click(function(e) {
        e.preventDefault();
        bookingAction('confirm', jQuery(this));
        return false;
    });

    jQuery('.rrze-rsvp-cancel').click(function(e) {
        e.preventDefault();
        if (confirm(TEXT_CANCEL)) {
            bookingAction('cancel', jQuery(this));
        }
        return false;
    });

});

