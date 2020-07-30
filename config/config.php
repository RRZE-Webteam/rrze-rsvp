<?php

namespace RRZE\RSVP\Config;

defined('ABSPATH') || exit;

/**
 * Gibt der Name der Option zurück.
 * @return array [description]
 */
function getOptionName() {
    return 'rrze_rsvp';
}

/**
 * Fixe und nicht aenderbare Plugin-Optionen
 * @return array 
 */
function getConstants() {
        $options = array(
	    
	    'fauthemes' => [
		'FAU-Einrichtungen', 
		'FAU-Philfak',
		'FAU-Natfak', 
		'FAU-RWFak', 
		'FAU-Medfak', 
		'FAU-Techfak',
		'FAU-Jobs'
		],


        );               
        // für ergänzende Optionen aus anderen Plugins
        $options = apply_filters('rrze_rsvp_constants', $options);
        return $options; // Standard-Array für zukünftige Optionen
    }

    
 function  defaultOptions()  {
        return [
            'notification_email' => '',
            'notification_if_new' => 1,
            'notification_if_cancel' => 1,
            'sender_name' => '',
            'sender_email' => '',
            'received_subject' => __('Thank you for booking', 'rrze-rsvp'),
            'received_text' => __('We received your booking and we will notify you once it has been confirmed.', 'rrze-rsvp'),
            'confirm_subject' => __('Your booking has been confirmed', 'rrze-rsvp'),
            'confirm_text' => __('We are happy to inform you that your booking has been confirmed.', 'rrze-rsvp'),
            'cancel_subject' => __('Your booking has been cancelled', 'rrze-rsvp'),
            'cancel_text' => __('Unfortunately we have to cancel your booking on {{date}} at {{time}}.', 'rrze-rsvp')
        ];
    }

 function defaultServiceOptions() {
        return [
            'weeks_in_advance' => 4,
            'auto_confirmation' => 1,
            'event_duration' => '01:00',
            'event_gap' => '01:00',
            'weekdays_timeslots' => [
                [
                    'start' => '09:00',
                    'end' => '18:00'
                ],
                [
                    'start' => '09:00',
                    'end' => '18:00'
                ],
                [
                    'start' => '09:00',
                    'end' => '18:00'
                ],
                [
                    'start' => '09:00',
                    'end' => '18:00'
                ],
                [
                    'start' => '09:00',
                    'end' => '18:00'
                ],
                [
                    'start' => '09:00',
                    'end' => '18:00'
                ],
                [
                    'start' => '00:00',
                    'end' => '00:00'
                ]
            ]
        ];
    }
    
/**
 * Gibt die Einstellungen des Menus zurück.
 * @return array [description]
 */
function getMenuSettings()
{
    return [
        'page_title'    => __('RRZE RSVP', 'rrze-rsvp'),
        'menu_title'    => __('RRZE RSVP', 'rrze-rsvp'),
        'capability'    => 'manage_options',
        'menu_slug'     => 'rrze-rsvp',
        'title'         => __('RRZE RSVP Settings', 'rrze-rsvp'),
    ];
}

/**
 * Gibt die Einstellungen der Inhaltshilfe zurück.
 * @return array [description]
 */
function getHelpTab()
{
    return [
        [
            'id'        => 'rrze-rsvp-help',
            'content'   => [
                '<p>' . __('Here comes the Context Help content.', 'rrze-rsvp') . '</p>'
            ],
            'title'     => __('Overview', 'rrze-rsvp'),
            'sidebar'   => sprintf('<p><strong>%1$s:</strong></p><p><a href="https://blogs.fau.de/webworking">RRZE Webworking</a></p><p><a href="https://github.com/RRZE Webteam">%2$s</a></p>', __('For more information', 'rrze-rsvp'), __('RRZE Webteam on Github', 'rrze-rsvp'))
        ]
    ];
}

/**
 * Gibt die Einstellungen der Optionsbereiche zurück.
 * @return array [description]
 */
function getSections()
{
    return [
/*       [
            'id'    => 'basic',
            'title' => __('Basic Settings', 'rrze-rsvp')
        ], */
        [
            'id'    => 'email',
            'title' => __('E-Mail Settings', 'rrze-rsvp')
        ]
    ];
}

/**
 * Gibt die Einstellungen der Optionsfelder zurück.
 * @return array [description]
 */
function getFields(){
    $defaults = defaultOptions(); 
    
    return [
/*	'basic' => [
            [
                'name'              => 'text_input',
                'label'             => __('Text Input', 'rrze-rsvp'),
                'desc'              => __('Text input description.', 'rrze-rsvp'),
                'placeholder'       => __('Text Input placeholder', 'rrze-rsvp'),
                'type'              => 'text',
                'default'           => 'Title',
                'sanitize_callback' => 'sanitize_text_field'
            ],
            [
                'name'              => 'number_input',
                'label'             => __('Number Input', 'rrze-rsvp'),
                'desc'              => __('Number input description.', 'rrze-rsvp'),
                'placeholder'       => '5',
                'min'               => 0,
                'max'               => 100,
                'step'              => '1',
                'type'              => 'number',
                'default'           => 'Title',
                'sanitize_callback' => 'floatval'
            ],
            [
                'name'        => 'textarea',
                'label'       => __('Textarea Input', 'rrze-rsvp'),
                'desc'        => __('Textarea description', 'rrze-rsvp'),
                'placeholder' => __('Textarea placeholder', 'rrze-rsvp'),
                'type'        => 'textarea'
            ],
            [
                'name'  => 'checkbox',
                'label' => __('Checkbox', 'rrze-rsvp'),
                'desc'  => __('Checkbox description', 'rrze-rsvp'),
                'type'  => 'checkbox'
            ],
            [
                'name'    => 'multicheck',
                'label'   => __('Multiple checkbox', 'rrze-rsvp'),
                'desc'    => __('Multiple checkbox description.', 'rrze-rsvp'),
                'type'    => 'multicheck',
                'default' => [
                    'one' => 'one',
                    'two' => 'two'
                ],
                'options'   => [
                    'one'   => __('One', 'rrze-rsvp'),
                    'two'   => __('Two', 'rrze-rsvp'),
                    'three' => __('Three', 'rrze-rsvp'),
                    'four'  => __('Four', 'rrze-rsvp')
                ]
            ],
            [
                'name'    => 'radio',
                'label'   => __('Radio Button', 'rrze-rsvp'),
                'desc'    => __('Radio button description.', 'rrze-rsvp'),
                'type'    => 'radio',
                'options' => [
                    'yes' => __('Yes', 'rrze-rsvp'),
                    'no'  => __('No', 'rrze-rsvp')
                ]
            ],
            [
                'name'    => 'selectbox',
                'label'   => __('Dropdown', 'rrze-rsvp'),
                'desc'    => __('Dropdown description.', 'rrze-rsvp'),
                'type'    => 'select',
                'default' => 'no',
                'options' => [
                    'yes' => __('Yes', 'rrze-rsvp'),
                    'no'  => __('No', 'rrze-rsvp')
                ]
            ]
        ],
 */
        'email' => [
            [
                'name'    => 'notification_email',
                'label'   => __('Notification email', 'rrze-rsvp'),
                'desc'    => __('Email address for notifications.', 'rrze-rsvp'),
                'type'    => 'email',
                'default' => $defaults['notification_email'],
		'sanitize_callback' => 'sanitize_email'
            ],
            [
                'name'    => 'notification_if_new',
                'label'   => __('Booking Notification', 'rrze-rsvp'),
                'desc'    => __('New booking notification.', 'rrze-rsvp'),
                'type'    => 'radio',
                'options' => [
                    'yes' => __('Yes', 'rrze-rsvp'),
                    'no'  => __('No', 'rrze-rsvp')
                ],
		'default'   => $defaults['notification_if_new'],
            ],
	    [
                'name'    => 'notification_if_cancel',
                'label'   => __('Cancel Notification', 'rrze-rsvp'),
                'desc'    => __('Notification of booking cancellation.', 'rrze-rsvp'),
                'type'    => 'radio',
                'options' => [
                    'yes' => __('Yes', 'rrze-rsvp'),
                    'no'  => __('No', 'rrze-rsvp')
                ],
		'default'   => $defaults['notification_if_cancel'],
            ],
	    [
                'name'              => 'sender_name',
                'label'             => __('Sender name', 'rrze-rsvp'),
                'desc'              => __('Name for Sender for the booking system.', 'rrze-rsvp'),
                'placeholder'       => __('Sender name', 'rrze-rsvp'),
                'type'              => 'text',
                'default'           =>  $defaults['sender_name'],
                'sanitize_callback' => 'sanitize_text_field'
            ],
	    [
                'name'    => 'sender_email',
                'label'   => __('Sender email', 'rrze-rsvp'),
                'desc'    => __('Email address of sender.', 'rrze-rsvp'),
                'type'    => 'email',
                'default' =>  $defaults['sender_email'],
		'sanitize_callback' => 'sanitize_email'
            ],
	     [
                'name'              => 'received_subject',
                'label'             => __('Subject', 'rrze-rsvp'),
                'desc'              => __('Subject for mails', 'rrze-rsvp'),
                'type'              => 'text',
                'default'           =>  $defaults['received_subject'],
                'sanitize_callback' => 'sanitize_text_field'
            ],
	     [
                'name'              => 'received_text',
                'label'             => __('Text', 'rrze-rsvp'),
                'type'              => 'textarea',
                'default'           => $defaults['received_text'],
            ],
	      [
                'name'              => 'confirm_subject',
                'label'             => __('Subject Confirmation', 'rrze-rsvp'),
                'desc'              => __('Subject for confirmation mails', 'rrze-rsvp'),
                'type'              => 'text',
                'default'           => $defaults['confirm_subject'],
                'sanitize_callback' => 'sanitize_text_field'
            ],
	     [
                'name'              => 'confirm_text',
                'label'             => __('Confirmation Text', 'rrze-rsvp'),
                'type'              => 'textarea',
                'default'           => $defaults['confirm_text'],
            ],
	        [
                'name'              => 'cancel_subject',
                'label'             => __('Subject Canceling', 'rrze-rsvp'),
                'desc'              => __('Subject for canceling mails', 'rrze-rsvp'),
                'type'              => 'text',
                'default'           =>  $defaults['cancel_subject'],
                'sanitize_callback' => 'sanitize_text_field'
            ],
	     [
                'name'              => 'cancel_text',
                'label'             => __('Cancel Text', 'rrze-rsvp'),
                'type'              => 'textarea',
                'default'           => $defaults['cancel_text'],
            ]
           
        ]
    ];
}


/**
 * Gibt die Einstellungen der Parameter für Shortcode für den klassischen Editor und für Gutenberg zurück.
 * @return array [description]
 */

function getShortcodeSettings(){
	return [
        'rsvp-booking' => [ // Key muss mit dem dazugehörigen Shortcode identisch sein
            'block' => [
                'blocktype' => 'rrze-rsvp/rsvp-booking', // dieser Wert muss angepasst werden
                'blockname' => 'rsvp_booking', // dieser Wert muss angepasst werden
                'title' => 'RSVP Booking', // Der Titel, der in der Blockauswahl im Gutenberg Editor angezeigt wird
                'category' => 'widgets', // Die Kategorie, in der der Block im Gutenberg Editor angezeigt wird
                'icon' => 'admin-users',  // Das Icon des Blocks
                'show_block' => 'content', // 'right' or 'content' : Anzeige des Blocks im Content-Bereich oder in der rechten Spalte
                'message' => __( 'Find the settings on the right side', 'rrze-rsvp' ) // erscheint bei Auswahl des Blocks, wenn "show_block" auf 'right' gesetzt ist
            ],
            'days' => [
                'default' => 14,
                'field_type' => 'text', // Art des Feldes im Gutenberg Editor
                'label' => __( 'Days in advance', 'rrze-rsvp' ),
                'type' => 'number' // Variablentyp der Eingabe
            ],
            'multiple' => [
                'field_type' => 'toggle',
                'label' => __( 'Multiple choice available', 'rrze-rsvp' ),
                'type' => 'boolean',
                'default'   => false // Vorauswahl: ausgewählt
            ],
            'date-select' => [
                'values' => [
                    'calendar' => __( 'Kalender', 'rrze-rsvp' ),
                    'boxes' => __( 'Boxen', 'rrze-rsvp' )
                ],
                'default' => 'calendar', // vorausgewählter Wert: Achtung: string, kein array!
                'field_type' => 'select',
                'label' => __( 'Datumsauswahl', 'rrze-rsvp' ),
                'type' => 'string' // Variablentyp des auswählbaren Werts
            ],
            'room' => [
                'default' => '',
                'field_type' => 'text', // Art des Feldes im Gutenberg Editor
                'label' => __( 'Room', 'rrze-rsvp' ),
                'type' => 'text' // Variablentyp der Eingabe
            ],
        ],
        'rsvp-availability' => [
            'block' => [
                'blocktype' => 'rrze-rsvp/rsvp-availability', // dieser Wert muss angepasst werden
                'blockname' => 'rsvp-availability', // dieser Wert muss angepasst werden
                'title' => 'RSVP Availability', // Der Titel, der in der Blockauswahl im Gutenberg Editor angezeigt wird
                'category' => 'widgets', // Die Kategorie, in der der Block im Gutenberg Editor angezeigt wird
                'icon' => 'admin-users',  // Das Icon des Blocks
                'show_block' => 'content', // 'right' or 'content' : Anzeige des Blocks im Content-Bereich oder in der rechten Spalte
                'message' => __( 'Find the settings on the right side', 'rrze-rsvp' ) // erscheint bei Auswahl des Blocks, wenn "show_block" auf 'right' gesetzt ist
            ],
            'days' => [
                'default' => 14,
                'field_type' => 'text', // Art des Feldes im Gutenberg Editor
                'label' => __( 'Days in advance', 'rrze-rsvp' ),
                'type' => 'number' // Variablentyp der Eingabe
            ],
            'seat' => [
                'default' => '',
                'field_type' => 'text', // Art des Feldes im Gutenberg Editor
                'label' => __( 'Seat(s)', 'rrze-rsvp' ),
                'type' => 'text' // Variablentyp der Eingabe
            ],
            'service' => [
                'default' => '',
                'field_type' => 'text', // Art des Feldes im Gutenberg Editor
                'label' => __( 'Service', 'rrze-rsvp' ),
                'type' => 'text' // Variablentyp der Eingabe
            ],
        ],

    ];
}

