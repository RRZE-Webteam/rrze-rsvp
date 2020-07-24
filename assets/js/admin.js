"use strict";

jQuery(document).ready(function () {

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

});

