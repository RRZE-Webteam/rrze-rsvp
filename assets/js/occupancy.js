"use strict";

jQuery(document).ready(function($){
    // var $loading = $('#loading').hide();
    // $(document)
    //     .ajaxStart(function () {
    //         $loading.show();
    //     })
    //     .ajaxStop(function () {
    //         $loading.hide();
    //     });

    $('select#rsvp_room_id').change(updateTable);
});

function updateTable() {
    var roomId = jQuery('#rsvp_room_id').val();
    // console.log('roomId = ' + roomId);

    jQuery.post(rsvp_ajax.ajax_url, {         //POST request
        _ajax_nonce: rsvp_ajax.nonce,     //nonce
        action: "ShowOccupancy",            //action
        roomId: roomId,                  //data
    }, function(result) {            //callback
        // console.log('in callback result = ' + result);
        jQuery('div.rsvp-occupancy-container').html(result);
    });
}
