<?php

namespace RRZE\RSVP\Config;

use RRZE\RSVP\Functions;

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
            'received_subject_en' => 'Thank you for booking',
            'received_text' => __('We received your booking and we will notify you once it has been confirmed.', 'rrze-rsvp'),
            'received_text_en' => 'We received your booking and we will notify you once it has been confirmed.',
            'force_to_confirm_subject' => __('Please confirm your booking', 'rrze-rsvp'),
            'force_to_confirm_subject_en' => 'Please confirm your booking',
            'force_to_confirm_text' => __('You are required to confirm the booking now. Please note that unconfirmed bookings automatically expire after one hour.', 'rrze-rsvp'),
            'force_to_confirm_text_en' => 'You are required to confirm the booking now. Please note that unconfirmed bookings automatically expire after one hour.',                         
            'confirm_subject' => __('Your booking has been confirmed', 'rrze-rsvp'),
            'confirm_subject_en' => 'Your booking has been confirmed',            
            'confirm_text' => __('We are happy to inform you that your booking has been confirmed.', 'rrze-rsvp'),
            'confirm_text_en' => 'We are happy to inform you that your booking has been confirmed.',
            'cancel_subject' => __('Your booking has been cancelled', 'rrze-rsvp'),
            'cancel_subject_en' => 'Your booking has been cancelled',
            'cancel_text' => __('Unfortunately we have to cancel your booking on {{date}} at {{time}}.', 'rrze-rsvp'),
            'cancel_text_en' => 'Unfortunately we have to cancel your booking on {{date_en}} at {{time_en}}.',
            'single_room_availability_table' => 'yes_link',
            'fau_logo' => 'on',
            'website_logo' => 'off',
            'website_url' => 'on',
            'instructions_de' => 'Bitte lesen Sie den QR Code ein, um auf diesem Platz einzuchecken oder diesen Platz für einen späteren Zeitpunkt zu reservieren.',
            'instructions_en' => 'Please scan the QR code to check in at this place or to reserve this place for a later date.',
            'room_text' => 'off',
            // 'room_image' => 'off',
            'room_address' => 'off',
            // 'room_floorplan' => 'off',
            'seat_equipment' => 'off',
            'room-notes-label' => __('Additional informations', 'rrze-rsvp'),
            'dsgvo-declaration' => __('Ich bin damit einverstanden, dass meine Kontaktdaten für die Dauer des Vorganges der Platzbuchung und bis zu 4 Wochen danach zum Zwecke der Nachverfolgung gemäß der gesetzlichen Grundlagen zur Corona-Bekämpfung gespeichert werden dürfen. Ebenso wird Raumverantwortlichen und Veranstalter von Sprechstunden das Recht eingeräumt, während der Dauer des Buchungsprozesses und bis zum Ende des ausgewählten Termins Einblick in folgende Buchungsdaten zu nehmen: E-Mailadresse, Name, Vorname. Raumverantwortliche und Veranstalter von Sprechstunden erhalten diese Daten allein zum Zweck der Durchführung und Verwaltung des Termins gemäß §6 Abs1 a DSGVO.', 'rrze-rsvp'),
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
            'id'    => 'general',
            'title' => __('General Settings', 'rrze-rsvp')
        ],
        [
            'id'    => 'email',
            'title' => __('E-Mail Settings', 'rrze-rsvp')
        ],
        [
            'id'    => 'pdf',
            'title' => __('QR PDF Settings', 'rrze-rsvp')
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
        'general' => [
            [
                'name'    => 'booking_page',
                'label'   => __('Booking Page', 'rrze-rsvp'),
                'desc'    => __('Select the page that contains your booking form shortcode. You can find a shortcode hint on each room edit page.', 'rrze-rsvp'),
                'type'    => 'select',
                'options' => Functions::getPagesDropdownOptions(['show_option_none'=> '&mdash; ' . __('Please select', 'rrze-rsvp') . ' &mdash;']),
            ],
            [
                'name'    => 'single_room_availability_table',
                'label'   => __('Show Availability table on Room page.', 'rrze-rsvp'),
                'desc'    => __('If \'Yes (with link)\' you need to specify the booking page (see above).', 'rrze-rsvp'),
                'type'    => 'radio',
                'options' => [
                    'yes_link' => __('Yes (with seats linked to booking form)', 'rrze-rsvp'),
                    'yes' => __('Yes (no link)', 'rrze-rsvp'),
                    'no'  => __('No', 'rrze-rsvp')
                ]
            ],
        ],
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
                'label'             => __('Subject of the received booking', 'rrze-rsvp'),
                'desc'              => __('Subject of the email replying to a booking received.', 'rrze-rsvp'),
                'type'              => 'text',
                'default'           =>  $defaults['received_subject'],
                'sanitize_callback' => 'sanitize_text_field'
            ],
            [
                'name'              => 'received_subject_en',
                'label'             => __('Subject of the received booking (english)', 'rrze-rsvp'),
                'desc'              => __('Subject of the email replying to a booking received.', 'rrze-rsvp'),
                'type'              => 'text',
                'default'           =>  $defaults['received_subject_en'],
                'sanitize_callback' => 'sanitize_text_field',
                'exception'         => ['locale' => 'en']
            ],            
	     [
                'name'              => 'received_text',
                'label'             => __('Text of the received booking', 'rrze-rsvp'),
                'type'              => 'textarea',
                'default'           => $defaults['received_text']
            ],
            [
                'name'              => 'received_text_en',
                'label'             => __('Text of the received booking (english)', 'rrze-rsvp'),
                'type'              => 'textarea',
                'default'           => $defaults['received_text_en'],
                'exception'         => ['locale' => 'en']
            ],  
            [
                'name'              => 'force_to_confirm_subject',
                'label'             => __('Subject for confirmation required.', 'rrze-rsvp'),
                'desc'              => __('Subject of the email where confirmation of the booking by the customer is required.', 'rrze-rsvp'),
                'type'              => 'text',
                'default'           => $defaults['force_to_confirm_subject'],
                'sanitize_callback' => 'sanitize_text_field'
            ],  
            [
                'name'              => 'force_to_confirm_subject_en',
                'label'             => __('Subject for confirmation required (english)', 'rrze-rsvp'),
                'desc'              => __('Subject of the email where confirmation of the booking by the customer is required.', 'rrze-rsvp'),
                'type'              => 'text',
                'default'           => $defaults['force_to_confirm_subject_en'],
                'sanitize_callback' => 'sanitize_text_field',
                'exception'         => ['locale' => 'en']
            ], 
            [
                'name'              => 'force_to_confirm_text',
                'label'             => __('Text for confirmation required', 'rrze-rsvp'),
                'type'              => 'textarea',
                'default'           => $defaults['force_to_confirm_text']
            ],   
            [
                'name'              => 'force_to_confirm_text_en',
                'label'             => __('Text for confirmation required (english)', 'rrze-rsvp'),
                'type'              => 'textarea',
                'default'           => $defaults['force_to_confirm_text_en'],
                'exception'         => ['locale' => 'en']
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
                'name'              => 'confirm_subject_en',
                'label'             => __('Subject Confirmation (english)', 'rrze-rsvp'),
                'desc'              => __('Subject for confirmation mails', 'rrze-rsvp'),
                'type'              => 'text',
                'default'           => $defaults['confirm_subject_en'],
                'sanitize_callback' => 'sanitize_text_field',
                'exception'         => ['locale' => 'en']
            ],            
	     [
                'name'              => 'confirm_text',
                'label'             => __('Confirmation Text', 'rrze-rsvp'),
                'type'              => 'textarea',
                'default'           => $defaults['confirm_text']
            ],
            [
                'name'              => 'confirm_text_en',
                'label'             => __('Confirmation Text (english)', 'rrze-rsvp'),
                'type'              => 'textarea',
                'default'           => $defaults['confirm_text_en'],
                'exception'         => ['locale' => 'en']
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
                'name'              => 'cancel_subject_en',
                'label'             => __('Subject Canceling (english)', 'rrze-rsvp'),
                'desc'              => __('Subject for canceling mails', 'rrze-rsvp'),
                'type'              => 'text',
                'default'           =>  $defaults['cancel_subject_en'],
                'sanitize_callback' => 'sanitize_text_field',
                'exception'         => ['locale' => 'en']
            ],            
	     [
                'name'              => 'cancel_text',
                'label'             => __('Cancel Text', 'rrze-rsvp'),
                'type'              => 'textarea',
                'default'           => $defaults['cancel_text']
         ],
	     [
            'name'              => 'cancel_text_en',
            'label'             => __('Cancel Text (english)', 'rrze-rsvp'),
            'type'              => 'textarea',
            'default'           => $defaults['cancel_text_en'],
            'exception'         => ['locale' => 'en']
        ]         
           
        ],
        'pdf' => [
            [
                'name'  => 'fau_logo',
                'label' => __('Print FAU logo', 'rrze-rsvp'),
                'default'           => $defaults['fau_logo'],
                'type'  => 'checkbox'
            ],
            [
                'name'  => 'website_logo',
                'label' => __('Print website\'s logo or title', 'rrze-rsvp'),
                'default'           => $defaults['website_logo'],
                'type'  => 'checkbox'
            ],
            [
                'name'  => 'website_url',
                'label' => __('Print website\'s URL', 'rrze-rsvp'),
                'default'           => $defaults['website_url'],
                'type'  => 'checkbox'
            ],
            [
                'name'              => 'instructions_de',
                'label'             => __('Instructions in German', 'rrze-rsvp'),
                'desc'              => __('This text will be shown above the QR code.', 'rrze-rsvp'),
                'placeholder'       => __('Instructions in German', 'rrze-rsvp'),
                'type'              => 'textarea',
                'default'           => $defaults['instructions_de'],
                'sanitize_callback' => 'sanitize_text_field'
            ],
            [
                'name'              => 'instructions_en',
                'label'             => __('Instructions in English', 'rrze-rsvp'),
                'desc'              => __('This text will be shown above the QR code.', 'rrze-rsvp'),
                'placeholder'       => __('Instructions in English', 'rrze-rsvp'),
                'type'              => 'textarea',
                'default'           => $defaults['instructions_en'],
                'sanitize_callback' => 'sanitize_text_field'
            ],
            [
                'name'  => 'room_text',
                'label' => __('Print room\'s text', 'rrze-rsvp'),
                'default' => $defaults['room_text'],
                'type'  => 'checkbox'
            ],
            // [
            //     'name'  => 'room_image',
            //     'label' => __('Print room\'s image', 'rrze-rsvp'),
            //     'default' => $defaults['room_image'],
            //     'type'  => 'checkbox'
            // ],
            [
                'name'  => 'room_address',
                'label' => __('Print room\'s address', 'rrze-rsvp'),
                'default' => $defaults['room_address'],
                'type'  => 'checkbox'
            ],
            // [
            //     'name'  => 'room_floorplan',
            //     'label' => __('Print floor plan', 'rrze-rsvp'),
            //     'default' => $defaults['room_floorplan'],
            //     'type'  => 'checkbox'
            // ],
            [
                'name'  => 'seat_equipment',
                'label' => __('Print seats\' equipment', 'rrze-rsvp'),
                'default' => $defaults['seat_equipment'],
                'type'  => 'checkbox'
            ],
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
            'room' => [
                'default' => '',
                'field_type' => 'text', // Art des Feldes im Gutenberg Editor
                'label' => __( 'Room', 'rrze-rsvp' ),
                'type' => 'text' // Variablentyp der Eingabe
            ],
            'sso' => [
                'field_type' => 'toggle',
                'label' => __( 'Require SSO Authentication', 'rrze-rsvp' ),
                'type' => 'boolean',
                'default'   => false
            ],
//            'multiple' => [
//                'field_type' => 'toggle',
//                'label' => __( 'Multiple choice available', 'rrze-rsvp' ),
//                'type' => 'boolean',
//                'default'   => false // Vorauswahl: ausgewählt
//            ],
//            'date-select' => [
//                'values' => [
//                    'calendar' => __( 'Kalender', 'rrze-rsvp' ),
//                    'boxes' => __( 'Boxen', 'rrze-rsvp' )
//                ],
//                'default' => 'calendar', // vorausgewählter Wert: Achtung: string, kein array!
//                'field_type' => 'select',
//                'label' => __( 'Datumsauswahl', 'rrze-rsvp' ),
//                'type' => 'string' // Variablentyp des auswählbaren Werts
//            ],
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
            'room' => [
                'default' => '',
                'field_type' => 'text', // Art des Feldes im Gutenberg Editor
                'label' => __( 'Room(s)', 'rrze-rsvp' ),
                'type' => 'text' // Variablentyp der Eingabe
            ],
            'seat' => [
                'default' => '',
                'field_type' => 'text', // Art des Feldes im Gutenberg Editor
                'label' => __( 'Seat(s)', 'rrze-rsvp' ),
                'type' => 'text' // Variablentyp der Eingabe
            ],
            'booking_link' => [
                'field_type' => 'toggle',
                'label' => __( 'Show booking link', 'rrze-rsvp' ),
                'type' => 'boolean',
                'default'   => false
            ],

        ],

    ];
}

