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

    jQuery('.rrze-rsvp-bookings .rrze-rsvp-confirm').click(function(e) {
        e.preventDefault();
        bookingAction('confirm', jQuery(this));
        return false;
    });

    jQuery('.rrze-rsvp-bookings .rrze-rsvp-cancel').click(function(e) {
        e.preventDefault();
        if (confirm(TEXT_CANCEL)) {
            bookingAction('cancel', jQuery(this));
        }
        return false;
    });

    jQuery('.rrze_rsvp_datepicker').each(function (i, v) {
        var el = jQuery(v);
        el.datepicker({
            altField: '#' + el.attr('data-target'),
            altFormat: 'yy-mm-dd'
        });
    });

    jQuery('.rrze_rsvp_datepicker[name=exception_start_datepicker]').change(function () {
        if (!jQuery('#rrze_rsvp_exception_end').val()) {
            jQuery('#rrze_rsvp_exception_end').val(
                jQuery('#rrze_rsvp_exception_start').val()
            );
            jQuery('.rrze_rsvp_datepicker[name=rrze_rsvp_exception_end_datepicker]').val(
                jQuery('.rrze_rsvp_datepicker[name=exception_start_datepicker]').val()
            );
        }
    });

    jQuery('.exception_hide').hide();
    jQuery('#rrze_rsvp_exception_allday').click(function () {
        jQuery('.exception_hide').toggle();
    });

    jQuery('.rrze_rsvp_check_weekdays_timeslots').click(function () {
        var el = jQuery(this);
        var i = el.attr("data-target");
        var inputs = el.parent().parent().find('input[type=time]');

        if (el.attr("checked")) {
            inputs.attr('readonly', false);
            inputs[0].value = '09:00';
            inputs[1].value = '18:00';
        } else {
            inputs.attr('readonly', 'readonly');
            inputs.attr('value', '00:00');
        }
    });

});

