"use strict";

jQuery(document).ready(function($){
    var $loading = $('#loading').hide();
    $(document)
        .ajaxStart(function () {
            $loading.show();
        })
        .ajaxStop(function () {
            $loading.hide();
        });

    $('div.rsvp-datetime-container, div.rsvp-seat-container').on('click', 'input', updateForm);

    $('div.rsvp-date-container').on('click', 'a.cal-skip', function(e) {
        e.preventDefault();
        var monthCurrent = $('table.rsvp_calendar').data('period');
        var endDate = $('table.rsvp_calendar').data('end');
        var direction = $(this).data('direction');
        var room = $('#rsvp_room').val();
        $('table.rsvp_calendar').remove();
        $('div.rsvp-time-select').remove();
        $('div.rsvp-seat-select').remove();
        $.post(rsvp_ajax.ajax_url, {         //POST request
            _ajax_nonce: rsvp_ajax.nonce,     //nonce
            action: "UpdateCalendar",            //action
            month: monthCurrent ,                  //data
            end: endDate,
            direction: direction,
            room: room,
        }, function(result) {                 //callback
            $('div.rsvp-date-container').html(result);
        });
    });

    updateForm();
});

function updateForm() {
    var room = jQuery('#rsvp_room').val();
    var date = jQuery('table.rsvp_calendar input[name="rsvp_date"]:checked').val();
    var time = jQuery('div.rsvp-time-container input[name="rsvp_time"]:checked').val();
    var seat = jQuery('div.rsvp-seat-container input[name="rsvp_seat"]:checked').val();
    // console.log(room);
    // console.log(date);
    // console.log(time);
    jQuery('div.rsvp-time-select').remove();
    jQuery('div.rsvp-seat-select').remove();
    jQuery.post(rsvp_ajax.ajax_url, {         //POST request
        _ajax_nonce: rsvp_ajax.nonce,     //nonce
        action: "UpdateForm",            //action
        room: room,                  //data
        date: date,          //data
        time: time,          //data
        seat: seat,          //data
    }, function(result) {                 //callback
        //console.log(result);
        jQuery('div.rsvp-time-container').append(result['time']);
        jQuery('div.rsvp-seat-container').html(result['seat']);
    });
}
