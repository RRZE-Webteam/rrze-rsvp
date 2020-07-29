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

    $('div.rsvp-datetime-container').on('click', 'input', updateForm);
    $('select#rsvp_location').change(updateForm);

    $('div.rsvp-service-container').on('click', 'input', function(){
        var id = $(this).val();
        $('div.rsvp-item-info').remove();
        $.post(rsvp_ajax.ajax_url, {         //POST request
            _ajax_nonce: rsvp_ajax.nonce,     //nonce
            action: "ShowItemInfo",            //action
            id: id,
        }, function(result) {                 //callback
            //console.log(result);
            $('div.rsvp-service-select').after(result);
        });
    });

    $('div.rsvp-date-container').on('click', 'a.cal-skip', function(e) {
        e.preventDefault();
        var monthCurrent = $('table.rsvp_calendar').data('period');
        var endDate = $('table.rsvp_calendar').data('end');
        var direction = $(this).data('direction');
        var location = $('#rsvp_location').val();
        $('table.rsvp_calendar').remove();
        $('div.rsvp-time-select').remove();
        $('div.rsvp-service-select').remove();
        $.post(rsvp_ajax.ajax_url, {         //POST request
            _ajax_nonce: rsvp_ajax.nonce,     //nonce
            action: "UpdateCalendar",            //action
            month: monthCurrent ,                  //data
            end: endDate,
            direction: direction,
            location: location,
        }, function(result) {                 //callback
            $('div.rsvp-date-container').html(result);
        });
    });

});

function updateForm() {
    var location = jQuery('#rsvp_location').val();
    var date = jQuery('table.rsvp_calendar input[name="rsvp_date"]:checked').val();
    var time = jQuery('div.rsvp-time-container input[name="rsvp_time"]:checked').val();
    //console.log(location);
    // console.log(date);
    // console.log(time);
    jQuery('div.rsvp-time-select').remove();
    jQuery('div.rsvp-service-select').remove();
    jQuery.post(rsvp_ajax.ajax_url, {         //POST request
        _ajax_nonce: rsvp_ajax.nonce,     //nonce
        action: "UpdateForm",            //action
        location: location,                  //data
        date: date,          //data
        time: time,          //data
    }, function(result) {                 //callback
        //console.log(result);
        jQuery('div.rsvp-time-container').append(result['time']);
        jQuery('div.rsvp-service-container').html(result['service']);
    });
}
