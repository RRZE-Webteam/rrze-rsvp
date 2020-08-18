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

        $('select#rsvp_room_id').change(updateTable);
        $('select#rsvp_room_id').change(updateLink);
});


function updateTable() {
    var roomId = jQuery('#rsvp_room_id').val();

    jQuery.post(rsvp_ajax.ajax_url, { //POST request
        _ajax_nonce: rsvp_ajax.nonce, //nonce
        action: "ShowOccupancy",      //action
        roomId: roomId,               //data
    }, function(result) {             //callback
        jQuery('div.rsvp-occupancy-container').html(result);
    });
}

function updateLink(){
    var roomId = jQuery('#rsvp_room_id').val();

    jQuery.post(rsvp_ajax.ajax_url, { //POST request
        _ajax_nonce: rsvp_ajax.nonce, //nonce
        action: "ShowOccupancyLinks",      //action
        roomId: roomId,               //data
    }, function(result) {             //callback
        jQuery('div.rsvp-occupancy-links').html(result);
    });
}