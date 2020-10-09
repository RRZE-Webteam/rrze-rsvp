<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

class Metaboxes
{
    public function __construct()
    {
        require_once plugin()->getPath('vendor/cmb2') . 'init.php';
    }

    public function onLoaded()
    {
        add_action('cmb2_admin_init', [$this, 'booking']);
        add_action('cmb2_admin_init', [$this, 'room']);
        add_action('cmb2_admin_init', [$this, 'seat']);
    }

    public function cb_encrypt($value){
        if ($value){
            return Functions::crypt($value, 'encrypt');
        }
    }

    public function cb_decrypt($value){
        if ($value){
            return Functions::crypt($value, 'decrypt');
        }
    }

    /*
     * Set Timeslot weekday, start time and end time to disabled if there are bookings for this timeslot.
     * Valid from/to can still be modified.
     */
    public function cbTimeslotAttributes($args, $field) {
        $seats = Functions::getAllRoomSeats($field->object_id);
        $bookings = get_posts([
            'post_type' => 'booking',
            'post_statue' => 'publish',
            'nopaging' => true,
            'orderby' => 'title',
            'order' => 'ASC',
            'meta_key' => 'rrze-rsvp-booking-seat',
            'meta_value' => $seats,
            'meta_compare' => 'IN',
        ]);
        $fieldKey = str_replace(array('+','-'), '', filter_var($args['_name'], FILTER_SANITIZE_NUMBER_INT));
        $timeslots = get_post_meta($field->object_id, 'rrze-rsvp-room-timeslots', true);
        $weekdays = isset($timeslots[$fieldKey]['rrze-rsvp-room-weekday']) ? $timeslots[$fieldKey]['rrze-rsvp-room-weekday'] : [];
        $starttime = isset($timeslots[$fieldKey]['rrze-rsvp-room-starttime']) ? $timeslots[$fieldKey]['rrze-rsvp-room-starttime'] : '';
        $endtime = isset($timeslots[$fieldKey]['rrze-rsvp-room-endtime']) ? $timeslots[$fieldKey]['rrze-rsvp-room-endtime'] : '';
        $validfrom = (isset($timeslots[$fieldKey]['rrze-rsvp-room-timeslot-valid-from']) && $timeslots[$fieldKey]['rrze-rsvp-room-timeslot-valid-from'] !== false) ? $timeslots[$fieldKey]['rrze-rsvp-room-timeslot-valid-from'] : 0;
        $validto = (isset($timeslots[$fieldKey]['rrze-rsvp-room-timeslot-valid-to']) && $timeslots[$fieldKey]['rrze-rsvp-room-timeslot-valid-to'] !== false) ? $timeslots[$fieldKey]['rrze-rsvp-room-timeslot-valid-to'] : 9999999999;
        foreach ($bookings as $booking) {
            $bookingMeta = get_post_meta($booking->ID);
            if (!isset($bookingMeta['rrze-rsvp-booking-start']) || !isset($bookingMeta['rrze-rsvp-booking-end']))
                continue;
            $startTimestamp = $bookingMeta['rrze-rsvp-booking-start'][0];
            $endTimestamp = $bookingMeta['rrze-rsvp-booking-end'][0];
            if (date('H:i', $startTimestamp) == $starttime
                && date('H:i', $endTimestamp) == $endtime
                && in_array(date('N', $startTimestamp), $weekdays)
                && $startTimestamp > $validfrom
                && $startTimestamp < $validto) {
                    $field->args['attributes']['disabled'] = 'disabled';
                    break;
            }
        }
    }


    public function booking()
    {
        $cmb = new_cmb2_box(array(
            'id'            => 'rrze-rsvp-booking-details',
            'title'         => __('Details', 'rrze-rsvp'),
            'object_types'  => array('booking'), // Post type
            'context'       => 'normal',
            'priority'      => 'high',
            'show_names'    => true, // Show field names on the left
            // 'cmb_styles' => false, // false to disable the CMB stylesheet
            // 'closed'     => true, // Keep the metabox closed by default
        ));

        $cmb->add_field(array(
            'name'             => __('Seat', 'rrze-rsvp'),
            'id'               => 'rrze-rsvp-booking-seat',
            'type'             => 'select',
            'show_option_none' => '&mdash; ' . __('Please select', 'rrze-rsvp') . ' &mdash;',
            'default'          => 'custom',
            'options'          => $this->getSeats(),
            'attributes'  =>  [
                'required' => 'required',
            ],             
        ));

        $cmb->add_field(array(
            'name'             => __('Start', 'rrze-rsvp'),
            'id'               => 'rrze-rsvp-booking-start',
            //'type' => 'text_date_timestamp',
            'type' => 'text_datetime_timestamp',
            'date_format' => 'd.m.Y',
            'time_format' => 'H:i',
            'attributes' => array(
                'data-timepicker' => json_encode(
                    array(
                        'timeFormat' => 'HH:mm',
                        'stepMinute' => 10,
                    )
                ),
                'required' => 'required',
            )
        ));

        $cmb->add_field(array(
            'name'             => __('End', 'rrze-rsvp'),
            'id'               => 'rrze-rsvp-booking-end',
            //'type' => 'text_date_timestamp',
            'type' => 'text_datetime_timestamp',
            'date_format' => 'd.m.Y',
            'time_format' => 'H:i',
            'attributes' => array(
                'data-timepicker' => json_encode(
                    array(
                        'timeFormat' => 'HH:mm',
                        'stepMinute' => 10,
                    )
                ),
                'readonly' => 'readonly',
            )
        ));

        $cmb->add_field(array(
            'name'             => __('Status', 'rrze-rsvp'),
            'id'               => 'rrze-rsvp-booking-status',
            'type'             => 'select',
            'show_option_none' => '&mdash; ' . __('Please select', 'rrze-rsvp') . ' &mdash;',
            'default'          => 'custom',
            'options'          => array(
                'booked' => __('Booked', 'rrze-rsvp'),
                'confirmed'   => __('Confirmed', 'rrze-rsvp'),
                'cancelled'     => _x('Cancelled', 'Booking', 'rrze-rsvp'),
                'checked-in'     => __('Checked In', 'rrze-rsvp'),
                'checked-out'     => __('Checked Out', 'rrze-rsvp'),
            ),
            'attributes'  =>  [
                'required' => 'required',
            ],            
        ));


        $cmb->add_field(array(
            'name'    => __('Last name', 'rrze-rsvp'),
            'id'      => 'rrze-rsvp-booking-guest-lastname',
            'type'    => 'text',
		    'sanitization_cb' => [$this, 'cb_encrypt'], // encrypt before storing
            'escape_cb'       => [$this, 'cb_decrypt'], // decrypt before displaying
            'attributes'  =>  [
                'required' => 'required',
            ],            
        ));

        $cmb->add_field(array(
            'name'    => __('First name', 'rrze-rsvp'),
            'id'      => 'rrze-rsvp-booking-guest-firstname',
            'type'    => 'text',
		    'sanitization_cb' => [$this, 'cb_encrypt'],
            'escape_cb'       => [$this, 'cb_decrypt'],
            'attributes'  =>  [
                'required' => 'required',
            ],             
        ));

        $cmb->add_field(array(
            'name'    => __('Email', 'rrze-rsvp'),
            'id'      => 'rrze-rsvp-booking-guest-email',
            'type'    => 'text_email',
		    'sanitization_cb' => [$this, 'cb_encrypt'],
            'escape_cb'       => [$this, 'cb_decrypt'],
            'attributes'  =>  [
                'required' => 'required',
            ],             
        ));

        $cmb->add_field(array(
            'name'    => __('Phone', 'rrze-rsvp'),
            'id'      => 'rrze-rsvp-booking-guest-phone',
            'type'    => 'text_medium',
            'attributes' => array(
                'type' => 'tel',
//                'required' => 'required',
            ),
		    'sanitization_cb' => [$this, 'cb_encrypt'],
            'escape_cb'       => [$this, 'cb_decrypt'],
        ));

        $cmb->add_field(array(
            'name' => __('Notes', 'rrze-rsvp'),
            'id' => 'rrze-rsvp-booking-notes',
            'type' => 'textarea',
            'desc' => __("This textarea contains the 'Additional Information' field content if this option is activated in the room settings. It can also be used for notes for internal use.", 'rrze-rsvp'),
        ));
    }

    public function room()
    {
        $cmb_timeslots = new_cmb2_box(array(
            'id'            => 'rrze-rsvp-room-timeslots_meta',
            'title'         => __('Timeslots', 'rrze-rsvp'),
            'object_types'  => array('room',), // Post type
            'context'       => 'normal',
            'priority'      => 'high',
            'show_names'    => true, // Show field names on the left
            // 'cmb_styles' => false, // false to disable the CMB stylesheet
            // 'closed'     => true, // Keep the metabox closed by default
        ));

        $group_field_id = $cmb_timeslots->add_field(array(
            'id'          => 'rrze-rsvp-room-timeslots',
            'type'        => 'group',
            'description' => __('Define bookable time slots.', 'rrze-rsvp'),
            'options'     => array(
                'group_title'       => __('Time slot {#}', 'rrze-rsvp'), // since version 1.1.4, {#} gets replaced by row number
                'add_button'        => __('Add Another Entry', 'cmb2'),
                'remove_button'     => __('Remove Entry', 'cmb2'),
                'sortable'          => false,
                // 'closed'         => true, // true to have the groups closed by default
                // 'remove_confirm' => esc_html__( 'Are you sure you want to remove?', 'cmb2' ), // Performs confirmation before removing group.
            ),
        ));

        $cmb_timeslots->add_group_field($group_field_id, array(
            'name'    => __('Week day', 'rrze-rsvp'),
            //'desc'    => 'field description (optional)',
            'id'      => 'rrze-rsvp-room-weekday',
            'type'    => 'multicheck',
            'options' => Functions::daysOfWeekAry(1),
            'before' => [$this, 'cbTimeslotAttributes'],
        ));

        $cmb_timeslots->add_group_field($group_field_id,  array(
            'name' => __('Start time', 'rrze-rsvp'),
            'id' => 'rrze-rsvp-room-starttime',
            'type' => 'text_time',
            'time_format' => 'H:i',
            'before' => [$this, 'cbTimeslotAttributes'],
        ));

        $cmb_timeslots->add_group_field($group_field_id,  array(
            'name' => __('End time', 'rrze-rsvp'),
            'id' => 'rrze-rsvp-room-endtime',
            'type' => 'text_time',
            'time_format' => 'H:i',
            'before' => [$this, 'cbTimeslotAttributes'],
        ));

        $cmb_timeslots->add_group_field($group_field_id,  array(
            'name' => __('Valid from (optional)', 'rrze-rsvp'),
            'id' => 'rrze-rsvp-room-timeslot-valid-from',
            'type' => 'text_date_timestamp',
            'date_format' => 'd.m.Y',
        ));

        $cmb_timeslots->add_group_field($group_field_id,  array(
            'name' => __('Valid until (optional)', 'rrze-rsvp'),
            'id' => 'rrze-rsvp-room-timeslot-valid-to',
            'type' => 'text_date_timestamp',
            'date_format' => 'd.m.Y',
        ));

        $cmb_timeslots->add_field(array(
            'name' => __('Days closed', 'rrze-rsvp'),
            'desc' => __('Enter days when this room is not available (format YYYY-MM-DD). One date per line. Will overwrite timeslots (e.g. Room is available every Monday, except 2020-09-21.)', 'rrze-rsvp'),
            //'default' => 'standard value (optional)',
            'id' => 'rrze-rsvp-room-days-closed',
            'type' => 'textarea_small'
        ));


        $cmb_general = new_cmb2_box(array(
            'id'            => 'rrze_rsvp_general-meta',
            'title'         => __('Details', 'rrze-rsvp'),
            'object_types'  => array('room',), // Post type
            'context'       => 'normal',
            'priority'      => 'high',
            'show_names'    => true, // Show field names on the left
            // 'cmb_styles' => false, // false to disable the CMB stylesheet
            // 'closed'     => true, // Keep the metabox closed by default
        ));

        $cmb_general->add_field(array(
            'name'             => __('Booking mode', 'rrze-rsvp'),
            // 'desc'             => __('', 'rrze-rsvp'),
            'id'               => 'rrze-rsvp-room-bookingmode',
            'type'             => 'select',
            'default'          => 'reservation',
            'options' => array(
                'reservation' => __('Reservation', 'rrze-rsvp'),
                'consultation' => __('Consultation', 'rrze-rsvp'),
                'check-only' => __('Check-in and check-out only on site', 'rrze-rsvp'), // Nur Ein- und Auschecken vor Ort
            ),
            'description' => 'Der Buchungsmodus legt den grundlegenden Workflow für eine Buchung fest:<br />
    <b>Platzreservierung:</b> Registrierung und Anmeldung für einen Termin und einen Platz + Einchecken und Auschecken am Platz beim Erreichen des Termins + Speicherung der Daten der Teilnehmer zur Kontaktverfolgung.</br />
    <b>Sprechstunde:</b> Nur Registrierung und Anmeldung für einen Termin und einen Platz. Kein Ein-/Auschecken und somit keine Kontaktverfolgung.<br />
    (Dieser Modus eignet sich insbesondere dann, wenn man Räume der sogenannten Positivliste nutzt, in der bereits die Kontaktverfolgung durch darfichrein.de erfolgt.)<br />
    <b>Nur Einchecken und Auschecken am Platz:</b> Keine Vorabreservierung von Plätzen.<br />
    (Dieser Modus eignet sich nur für die reine Kontaktverfolgung und Platzbuchung am Platze. An der FAU sollte dieser Modus nur für Räume oder Ressourcen verwendet werden, die nicht bereits durch die Anwendung darfichrein.de verwaltet werden.)',
        ));

        $cmb_general->add_field(array(
            'name' => __('SSO is required', 'rrze-rsvp'),
            'desc' => __('If SSO is enabled, the customer must log in via SSO in order to use the booking system.', 'rrze-rsvp'),
            'id'   => 'rrze-rsvp-room-sso-required',
            'type' => 'checkbox',
            'default' => '',
        ));

        $cmb_general->add_field(array(
            'name' => __('LDAP is required', 'rrze-rsvp'),
            'desc' => __('If LDAP is enabled, the customer must log in via LDAP in order to use the booking system.', 'rrze-rsvp'),
            'id'   => 'rrze-rsvp-room-ldap-required',
            'type' => 'checkbox',
            'default' => '',
            'after_row' => '<div id="rrze-rsvp-additionals">'            
        ));

        $cmb_general->add_field(array(
            'name' => __('Available days in advance', 'rrze-rsvp'),
            'desc' => __('Number of days for which bookings are available in advance.', 'rrze-rsvp'),
            'id'   => 'rrze-rsvp-room-days-in-advance',
            'type' => 'text',
            'attributes' => array(
                'type' => 'number',
                'min' => '0',
            ),
            'default' => 7
        ));

        $cmb_general->add_field(array(
            'name' => __('Automatic confirmation', 'rrze-rsvp'),
            'desc' => __('Incoming bookings do not need to be confirmed by the booking managers', 'rrze-rsvp'),
            'id'   => 'rrze-rsvp-room-auto-confirmation',
            'type' => 'checkbox',
            'default' => '',
        ));

        $cmb_general->add_field(array(
            'name' => __('Email confirmation is required', 'rrze-rsvp'),
            'desc' => __('The customer must confirm his reservation within one hour. Otherwise the system will cancel the booking.', 'rrze-rsvp'),
            'id'   => 'rrze-rsvp-room-force-to-confirm',
            'type' => 'checkbox',
            'default' => ''
        ));

        $cmb_general->add_field(array(
            'name' => __('Checkout notification', 'rrze-rsvp'),
            'desc' => __('Send an email to the booking manager when customer has checked out.', 'rrze-rsvp'),
            'id'   => 'rrze-rsvp-room-checkout-notification',
            'type' => 'checkbox',
            'default' => ''
        ));

        $cmb_general->add_field(array(
            'name' => __('Send to an email address', 'rrze-rsvp'),
            'desc' => __('A copy of the confirmed booking will be sent to the specified email address with a calendar file (.ics) as an attachment.', 'rrze-rsvp'),
            'id'   => 'rrze-rsvp-room-send-to-email',
            'type' => 'text_email',
            'default' => '',
            'after_row' => '<div id="rrze-rsvp-consultation">'
        ));        

        $cmb_general->add_field(array(
            'name' => __('Allow Instant Check-In', 'rrze-rsvp'),
            'desc' => __('Seats can be booked and checked-in in one step. This only works if automatic confirmation activated!', 'rrze-rsvp'),
            'id'   => 'rrze-rsvp-room-instant-check-in',
            'type' => 'checkbox',
            'default' => '',
        ));

        $cmb_general->add_field(array(
            'name' => __('Check-in is required', 'rrze-rsvp'),
            'desc' => __('The customer must check-in their booking within 15 minutes from the start of the event. Otherwise the system will cancel the booking.', 'rrze-rsvp'),
            'id'   => 'rrze-rsvp-room-force-to-checkin',
            'type' => 'checkbox',
            'default' => '',
        ));

        $cmb_general->add_field(array(
            'name' => __('Show notes/comment input in booking form', 'rrze-rsvp'),
            'desc' => __('If not checked, the comment text input will still be visible in the backend for booking admins for internal notes.', 'rrze-rsvp'),
            'id'   => 'rrze-rsvp-room-notes-check',
            'type' => 'checkbox',
            'default' => '',
        ));

        $cmb_general->add_field(array(
            'name' => __('Comment input label', 'rrze-rsvp'),
            'desc' => __("Choose a label for the text input on the booking form. E.g. 'Additional information' or 'Main interests'", 'rrze-rsvp'),
            'type' => 'text',
            'id'   => 'rrze-rsvp-room-notes-label',
            'default' => __('Additional information', 'rrze-rsvp'),
            'after_row' => '</div></div>' // .rrze-rsvp-consultation .additional-reservation-functions
        ));

        $cmb_general->add_field(array(
            'name'    => __('Street', 'rrze-rsvp'),
            'id'      => 'rrze-rsvp-room-street',
            'type'    => 'text',
//            'attributes'  =>  [
//                'required' => 'required',
//            ],
        ));

        $cmb_general->add_field(array(
            'name'    => __('ZIP', 'rrze-rsvp'),
            'id'      => 'rrze-rsvp-room-zip',
            'type'    => 'text',
//            'attributes'  =>  [
//                'required' => 'required',
//            ],
        ));

        $cmb_general->add_field(array(
            'name'    => __('City', 'rrze-rsvp'),
            'id'      => 'rrze-rsvp-room-city',
            'type'    => 'text',
//            'attributes'  =>  [
//                'required' => 'required',
//            ],
        ));

        $cmb_general->add_field(array(
            'name'    => __('Floor plan', 'rrze-rsvp'),
            'desc'    => 'Upload an image.',
            'id'      => 'rrze-rsvp-room-floorplan',
            'type'    => 'file',
            // Optional:
            'options' => array(
                'url' => false, // Hide the text input for the url
            ),
            //            'text'    => array(
            //                'add_upload_file_text' => 'Add File' // Change upload button text. Default: "Add or Upload File"
            //            ),
            // query_args are passed to wp.media's library query.
            'query_args' => array(
                //'type' => 'application/pdf', // Make library only display PDFs.
                // Or only allow gif, jpg, or png images
                'type' => array(
                    'image/gif',
                    'image/jpeg',
                    'image/png',
                ),
            ),
            'preview_size' => 'large', // Image size to use when previewing in the admin.
        ));
    }

    public function seat()
    {
        $cmb = new_cmb2_box(array(
            'id'            => 'rrze-rsvp-seat-details-meta',
            'title'         => __('Details', 'rrze-rsvp'),
            'object_types'  => array('seat',), // Post type
            'context'       => 'normal',
            'priority'      => 'high',
            'show_names'    => true, // Show field names on the left
            // 'cmb_styles' => false, // false to disable the CMB stylesheet
            // 'closed'     => true, // Keep the metabox closed by default
        ));

        $cmb->add_field(array(
            'name'             => __('Room', 'rrze-rsvp'),
            //'desc'             => 'Select an option',
            'id'               => 'rrze-rsvp-seat-room',
            'type'             => 'select',
            'show_option_none' => '&mdash; ' . __('Please select', 'rrze-rsvp') . ' &mdash;',
            'default'          => '-1',
            'options'          => $this->getPosts('room'),
            'attributes'  =>  [
                'required' => 'required',
            ],             
        ));
    }

    public function getSeats()
    {
        $seats = get_posts([
            'post_type' => 'seat',
            'post_statue' => 'publish',
            'nopaging' => true,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        if (empty($seats)) {
            return [];
        }        
        $result = [];
        foreach ($seats as $seat) {
            $room = get_post_meta($seat->ID, 'rrze-rsvp-seat-room', true);
            $room_title = get_the_title($room);
            $result[$seat->ID] = $room_title . ' – ' . $seat->post_title;
        }
        natsort($result);
        return $result;
    }

    public static function getPosts(string $postType): array
    {
        $posts = get_posts([
            'post_type' => $postType,
            'post_statue' => 'publish',
            'nopaging' => true,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        if (empty($posts)) {
            return [];
        }
        $result = [];
        foreach ($posts as $post) {
            $result[$post->ID] = $post->post_title;
        }
        return $result;
    }
}
