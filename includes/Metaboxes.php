<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

require_once(plugin()->getPath('vendor/cmb2') . 'init.php');

class Metaboxes
{
    public function __construct()
    {
        //
    }

    public function onLoaded()
    {
        add_action('cmb2_admin_init', [$this, 'booking']);
        add_action('cmb2_admin_init', [$this, 'room']);
        add_action('cmb2_admin_init', [$this, 'seat']);
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
        ));

        $cmb->add_field(array(
            'name'    => __('Last name', 'rrze-rsvp'),
            'id'      => 'rrze-rsvp-booking-guest-lastname',
            'type'    => 'text',
        ));

        $cmb->add_field(array(
            'name'    => __('First name', 'rrze-rsvp'),
            'id'      => 'rrze-rsvp-booking-guest-firstname',
            'type'    => 'text',
        ));

        $cmb->add_field(array(
            'name'    => __('Email', 'rrze-rsvp'),
            'id'      => 'rrze-rsvp-booking-guest-email',
            'type'    => 'text_email',
        ));

        $cmb->add_field(array(
            'name'    => __('Phone', 'rrze-rsvp'),
            'id'      => 'rrze-rsvp-booking-guest-phone',
            'type'    => 'text_medium',
            'attributes' => array(
                'type' => 'tel',
            ),
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
            'options' => array(
                1 => __('Monday', 'rrze-rsvp'),
                2 => __('Tuesday', 'rrze-rsvp'),
                3 => __('Wednesday', 'rrze-rsvp'),
                4 => __('Thursday', 'rrze-rsvp'),
                5 => __('Friday', 'rrze-rsvp'),
                6 => __('Saturday', 'rrze-rsvp'),
                7 => __('Sunday', 'rrze-rsvp')
            ),
        ));

        $cmb_timeslots->add_group_field($group_field_id,  array(
            'name' => __('Start time', 'rrze-rsvp'),
            'id' => 'rrze-rsvp-room-starttime',
            'type' => 'text_time',
            'time_format' => 'H:i',
        ));

        $cmb_timeslots->add_group_field($group_field_id,  array(
            'name' => __('End time', 'rrze-rsvp'),
            'id' => 'rrze-rsvp-room-endtime',
            'type' => 'text_time',
            'time_format' => 'H:i',
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
            'name'    => __('Street', 'rrze-rsvp'),
            'id'      => 'rrze-rsvp-room-street',
            'type'    => 'text',
        ));

        $cmb_general->add_field(array(
            'name'    => __('ZIP', 'rrze-rsvp'),
            'id'      => 'rrze-rsvp-room-zip',
            'type'    => 'text',
        ));

        $cmb_general->add_field(array(
            'name'    => __('City', 'rrze-rsvp'),
            'id'      => 'rrze-rsvp-room-city',
            'type'    => 'text',
        ));

        $cmb_general->add_field(array(
            'name'             => __('Booking Form Page', 'rrze-rsvp'),
            'desc'             => __('Select a current page to display the booking form. Please note that the current content of the page will be replaced by the booking form.', 'rrze-rsvp'),
            'id'               => 'rrze-rsvp-room-form-page',
            'type'             => 'select',
            'show_option_none' => '&mdash; ' . __('Please select', 'rrze-rsvp') . ' &mdash;',
            'default'          => '-1',
            'options'          => $this->getPosts('page')
        ));

        $cmb_general->add_field(array(
            'name' => __('SSO is required', 'rrze-rsvp'),
            'desc' => __('If SSO is enabled then the customer must log in through SSO in order to use the booking form.', 'rrze-rsvp'),
            'id'   => 'rrze-rsvp-room-sso-required',
            'type' => 'checkbox',
            'default' => '',
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
            'desc' => __('If the automatic confirmation is not activated, the booking must be confirmed manually.', 'rrze-rsvp'),
            'id'   => 'rrze-rsvp-room-auto-confirmation',
            'type' => 'checkbox',
            'default' => 'on',
        ));

        $cmb_general->add_field(array(
            'name' => __('Allow Instant Check-In', 'rrze-rsvp'),
            'desc' => __('Seats can be booked and checked-in in one step. This only works if automatic confirmation activated!', 'rrze-rsvp'),
            'id'   => 'rrze-rsvp-room-instant-check-in',
            'type' => 'checkbox',
            'default' => '',
        ));

        $cmb_general->add_field(array(
            'name' => __('Force to confirm', 'rrze-rsvp'),
            'desc' => __('The customer is forced to confirm his booking within a period of one hour. Otherwise the system will cancel the booking.', 'rrze-rsvp'),
            'id'   => 'rrze-rsvp-room-force-to-confirm',
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
            'desc' => 'If not checked, the comment text input will still be visible in the backend for booking admins for internal notes.',
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
            'options'          => $this->getPosts('room')
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
