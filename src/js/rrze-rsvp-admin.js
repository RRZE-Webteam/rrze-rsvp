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

    /*
     * CPT Booking Backend
     */
	var details = jQuery('div#rrze-rsvp-booking-details'),
		seat = details.find('select#rrze-rsvp-booking-seat'),
		startdate = details.find('input#rrze-rsvp-booking-start_date'),
		starttime = details.find('input#rrze-rsvp-booking-start_time'),
		enddate = details.find('input#rrze-rsvp-booking-end_date'),
		endtime = details.find('input#rrze-rsvp-booking-end_time');

	starttime.attr('readonly', 'readonly');

	seat.change(function() {
		startdate.val('');
		starttime.val('');
		enddate.val('');
		endtime.val('');
		jQuery('div.select_timeslot_container').remove();
	});

	startdate.change(function() {
		enddate.val(startdate.val());
		starttime.val('');
		endtime.val('');
		if (seat.val() == '' || startdate.val() == '') {
			alert(rrze_rsvp_admin.alert_no_seat_date);
		} else {
			jQuery('div.select_timeslot_container').remove();
			jQuery.post(API_AJAXURL, {         //POST request
				action: "ShowTimeslots",            //action
				seat: seat.val(),                  //data
				date: startdate.val(),          //data
			}, function (result) {                 //callback
				// console.log(result);
				if (result != false){
					jQuery('input#rrze-rsvp-booking-start_time').after(result);
				}
			});
		}
	});

	jQuery('div.cmb2-id-rrze-rsvp-booking-start').on('change', 'select.select_timeslot', (function() {
		var select_start = jQuery(this).val();
		var select_end   = jQuery(this).find(':selected').data('end');
		console.log(select_start);
		console.log(select_end);
		starttime.val(select_start);
		endtime.val(select_end);
	}));

});

