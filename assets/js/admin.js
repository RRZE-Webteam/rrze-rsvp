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

