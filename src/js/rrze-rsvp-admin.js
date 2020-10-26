"use strict";

jQuery(document).ready(function ($) {
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

    $('.rrze-rsvp-confirm').click(function(e) {
        e.preventDefault();
        bookingAction('confirm', jQuery(this));
        return false;
    });

    $('.rrze-rsvp-cancel').click(function(e) {
        e.preventDefault();
        if (confirm(TEXT_CANCEL)) {
            bookingAction('cancel', jQuery(this));
        }
        return false;
    });


    /*
     * CPT Booking Backend
     */

    // Set search string in result
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('s')){
        $('.subtitle').html( $('.subtitle').text().substring(0, $('.subtitle').text().length - 1) + urlParams.get('s') + '&rdquo;');
    }

	// Hide booking mode info text on loading, insert booking mode info icon
	$('select#rrze-rsvp-room-bookingmode').after('<a><span class="dashicons dashicons-editor-help info-bookingmode" title="Informationen zum Buchungsmodus anzeigen" aria-hidden="true"></span><span class="screen-reader-text">Informationen zum Buchungsmodus anzeigen</span></a>');
	$('.cmb2-id-rrze-rsvp-room-bookingmode .cmb2-metabox-description').hide();
	$('.cmb2-id-rrze-rsvp-room-bookingmode').find('.info-bookingmode').click(function() {
		$('.cmb2-id-rrze-rsvp-room-bookingmode .cmb2-metabox-description').slideToggle();
	});

	// CPT Room: Set timeslot remove buttons to disabled if timeslot bookings for this timeslot exist
	var timeslotgroup = $('body.wp-admin.post-type-room #rrze-rsvp-room-timeslots_repeat div.cmb-repeatable-grouping');
	$(timeslotgroup).each(function (index) {
		// console.log($(this).find("input[id$='rrze-rsvp-room-starttime']").prop('disabled'));
		if ($(this).find("input[id$='rrze-rsvp-room-starttime']").prop('disabled') == true) {
			$(this).find('button.cmb-remove-group-row').prop({
				disabled: true
			});
		}
	});

	// Remove disabled attribute from protected timeslot fields before submit
	$('body.wp-admin.post-type-room form, body.wp-admin.post-type-booking form').submit(function(e) {
		$('#postbox-container-2 :disabled').each(function(e) {
			$(this).removeAttr('disabled');
		})
	});

	// Fill date/time inputs with timeslot selector
	var details = jQuery('div#rrze-rsvp-booking-details'),
		seat = details.find('select#rrze-rsvp-booking-seat'),
		startdate = details.find('input#rrze-rsvp-booking-start_date'),
		starttime = details.find('input#rrze-rsvp-booking-start_time'),
		enddate = details.find('input#rrze-rsvp-booking-end_date'),
		endtime = details.find('input#rrze-rsvp-booking-end_time');

	starttime.attr('disabled', 'disabled');

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
			startdate.val('');
			enddate.val('');
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

	$('div.cmb2-id-rrze-rsvp-booking-start').on('change', 'select.select_timeslot', (function() {
		var select_start = jQuery(this).val();
		var select_end   = jQuery(this).find(':selected').data('end');
		//console.log(select_start);
		//console.log(select_end);
		starttime.val(select_start);
		endtime.val(select_end);
	}));


    /* trigger reservations' details - see https://github.com/RRZE-Webteam/rrze-rsvp/issues/92 */
    function triggerAdditionals(t){
		if (($('select#rrze-rsvp-room-bookingmode option:checked').val() == 'reservation') 
		|| ($('select#rrze-rsvp-room-bookingmode option:checked').val() == 'consultation')) {
            $('div#rrze-rsvp-additionals').slideDown(t);
        }else{
            $('div#rrze-rsvp-additionals').slideUp(t);
		}
		if ($('select#rrze-rsvp-room-bookingmode option:checked').val() == 'reservation') {
			$('div#rrze-rsvp-consultation').slideDown(t);
		}
		if ($('select#rrze-rsvp-room-bookingmode option:checked').val() == 'consultation') {
			$('div#rrze-rsvp-consultation').slideUp(t);
		}		
    }

    function triggerInstant(t){
        if ($('#rrze-rsvp-room-auto-confirmation').is(':checked')) {
            $('div.cmb2-id-rrze-rsvp-room-instant-check-in').slideDown(t);
        } else {
            $('div.cmb2-id-rrze-rsvp-room-instant-check-in').slideUp(t);
            if( $('#rrze-rsvp-room-instant-check-in').is(':checked')) {
                $('#rrze-rsvp-room-instant-check-in').prop('checked', false)
            }
        }
    }

    triggerAdditionals(0);
    triggerInstant(0);

    $('select#rrze-rsvp-room-bookingmode').on('change', function() {        
        triggerAdditionals(100);
    });

    $('#rrze-rsvp-room-auto-confirmation').click(function() {
        triggerInstant(100);
    }); 
    
});
