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

function isAllowedSearchForGuest(){
    $allowedDomains = [
        'www.nickless.test.rrze.fau.de',
        'ub.fau.de'
    ];
    return in_array($_SERVER['SERVER_NAME'], $allowedDomains);
}

// used in wp_kses_custom() and for 'desc' of 'contact_tracking_note'  
function getAllowedHTML(){
    return [
        'a' => [
            'href' => [],
        ],
        'br' => [],
        'h3' => [],
        'li' => [],
        'p' => [],
        'ul' => [],
    ];
}    

// sanitizes but allows defined tags and protocols
function wp_kses_custom($str){
    $allowed_html = getAllowedHTML();

    $allowed_protocols = [
        'http' => [],
        'https' => [],
        'mailto' => [],
    ];

    return wp_kses( $str, $allowed_html, $allowed_protocols );
}

// sanitzes natural number (positive INT)
function sanitize_natint_field( $input ) {
    return filter_var($input, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
}

    
function defaultOptions()  {

    $sender_name = '';
    $notification_email = '';
    $sender_email = '';

    $blogAdminUsers = get_users( 'role=Administrator' );
    if ($blogAdminUsers){
        $sender_name = $blogAdminUsers[0]->display_name;
        $sender_email = $blogAdminUsers[0]->user_email;
        $notification_email = $blogAdminUsers[0]->user_email;
    }

        return [
            'single_room_availability_table' => 'yes_link',
            'contact_tracking_note' => '<h3>Hinweis</h3><p>Bei Anfragen des Gesundheitsamtes oder anderer Behörden ist auf die Adresse <a href="mailto:kanzler@fau.de">kanzler@fau.de</a> zu verweisen. Eine Abfrage der Daten zur Kontaktverfolgung wird auf Anforderung und Freigabe des Kanzlerbüros zentral durch das RRZE vorgenommen. Bei technischen Fragen hierzu wenden Sie sich an <a href="mailto:webmaster@fau.de">webmaster@fau.de</a>.</p><p>Weitergehende Informationen finden sie hier:</p><ul><li><a href="https://www.verwaltung.zuv.fau.de/arbeitssicherheit/gefaehrungen-am-arbeitsplatz/biologische-arbeitsstoffe/#sprungmarke2">Empfehlungen zu Hygienemaßnahmen des Referats Arbeitssicherheit</a></li><li><a href="https://www.verwaltung.zuv.fau.de/arbeitssicherheit/dokumentation-im-arbeitsschutz/gefaehrdungsbeurteilung/#sprungmarke7">Handlungshilfen des Referats Arbeitssicherheit</a></li><li><a href="https://www.wordpress.rrze.fau.de/plugins/fau-und-rrze-plugins/rsvp/hilfsmittel-und-hinweise-zur-nutzung/">Hilfsmittel und Hinweise zur Nutzung der Platzbuchungssystems</a></li></ul>', 
            'notification_email' => $notification_email,
            'notification_if_new' => 'yes',
            'notification_if_cancel' => 'yes',
            'sender_name' => $sender_name,
            'sender_email' => $sender_email,
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
            'cancel_reason_notconfirmed' => __('You haven\'t confirmed your reservation.', 'rrze-rsvp'),
            'cancel_reason_notconfirmed_en' => 'You haven\'t confirmed your reservation.',
            'cancel_reason_notcheckedin' => __('You haven\'t checked in.', 'rrze-rsvp'),
            'cancel_reason_notcheckedin_en' => 'You haven\'t  checked in.',
            'cancel_text' => __('Unfortunately we have to cancel your booking on {{date}} at {{time}}.', 'rrze-rsvp'),
            'cancel_text_en' => 'Unfortunately we have to cancel your booking on {{date_en}} at {{time_en}}.',
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
            'check-in-time' => '15',
            'dsgvo-declaration' => __('I agree that my contact details may be stored for the duration of the booking process and for up to 4 weeks afterwards for the purpose of tracking in accordance with the legal basis for combating corona. Room managers and organizers of office hours are also given the right to inspect the following booking data for the duration of the booking process and until the end of the selected appointment: e-mail address, surname, first name. Room managers and organizers of office hours receive this data solely for the purpose of carrying out and managing the appointment in accordance with Section 6 (1) a GDPR. The telephone number is only recorded for the purpose of contact tracking due to the legal basis for fighting pandemics for health authorities.', 'rrze-rsvp'),
            'show_dsgvo_supplement' => 'on',
            'dsgvo_supplement_de' => '<h4>Ergänzende Auflagen zur 3G-Regelung</h4><p>Ich bestätige mit dieser freiwilligen Selbstauskunft die jeweils aktuell gültigen Auflagen zur 3G-Regelung zum Besuch der Einrichtung zu erfüllen und kann dies nachweisen:</p><ul><li>Vollständig gegen COVID-19 geimpft und im Besitz eines Impfnachweises im Sinne der <abbr title="COVID-19-Schutzmaßnahmen-Ausnahmenverordnung ">SchAusnahmV</abbr>.</li><li>Genesen und im Besitz eines Genesenennachweises im Sinne der <abbr title="COVID-19-Schutzmaßnahmen-Ausnahmenverordnung ">SchAusnahmV</abbr>.</li><li> Getestet und im Besitz eines negativen Testnachweises (schriftlich/ elektronisch), der von der <abbr title="Bayerische Infektionsschutzmaßnahmenverordnung">BayIfSMV</abbr> als Nachweismöglichkeit aufgeführt wird (vgl. <a href="https://www.stmgp.bayern.de/coronavirus/rechtsgrundlagen/">https://www.stmgp.bayern.de/coronavirus/rechtsgrundlagen/</a>)</li></ul><p><strong>Hinweis:</strong> Wenn Sie vorsätzlich oder fahrlässig gegen die 3G-Regel der jeweils geltenden BayIfSMV verstoßen, handeln Sie ordnungswidrig und es kann gegen Sie ein Bußgeldverfahren eingeleitet werden.</p>',
            'dsgvo_supplement_en' => '<h4>Additional Requirements regarding Covid-19</h4><p>I hereby confirm with this voluntary disclosure that I comply with the currently valid requirements regarding Covid-19 vaccination, testing or recovery (‘3G-Regelung’) to enter this institution and can prove this fact with one of the following:</p><ul><li>I am fully vaccinated against Covid-19 and am in possession of proof of vaccination as defined by the Covid-19 Protective Measures Exemption Directive  <abbr title="COVID-19-Schutzmaßnahmen-Ausnahmenverordnung ">SchAusnahmV</abbr>.</li><li>I have recovered from an infection with Covid-19 and am in possession of proof of recovery as defined by the Covid-19 Protective Measures Exemption Directive <abbr title="COVID-19-Schutzmaßnahmen-Ausnahmenverordnung ">SchAusnahmV</abbr>.</li><li> I have been tested and am in possession of proof of a negative test result (on paper/electronic) listed as a valid type of proof in the Bavarian Regulation on Infection Prevention and Control Measures  (please refer to  <a href="https://www.stmgp.bayern.de/coronavirus/rechtsgrundlagen/">https://www.stmgp.bayern.de/coronavirus/rechtsgrundlagen/</a>)</li></ul><p><strong>Please note:</strong> You are committing an offence and could be liable for a fine if you wilfully or negligently fail to comply with the rules requiring proof of Covid-19 vaccination, testing or recovery (3G-Regelung) as set out in the current version of the Regulation on Infection Prevention and Control Measures (BayIfSMV).</p>',
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
 * Gibt die Einstellungen der Optionsbereiche zurück.
 * @return array [description]
 */
function getSections()
{
    return [
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
        ],
        [
             'id'    => 'ldap',
             'title' => __('LDAP Settings', 'rrze-rsvp')
        ],
        [
            'id'    => 'reset',
            'title' => __('Reset Settings', 'rrze-rsvp')
        ],
    ];
}

/**
 * Gibt die Einstellungen der Optionsfelder zurück.
 * @return array [description]
 */
function getFields(){
    $defaults = defaultOptions();
    
    return [
        'general' => [
            [
                'name'    => 'check-in-time',
                'label'   => __('Allowed Check-In Time.', 'rrze-rsvp'),
                'type'    => 'number',
                'default' => $defaults['check-in-time'],
                'sanitize_callback' => 'sanitize_natint_field',
            ],
            [
                'name'    => 'single_room_availability_table',
                'label'   => __('Show Availability table on Room page.', 'rrze-rsvp'),
                'type'    => 'radio',
                'default' => $defaults['single_room_availability_table'],
                'options' => [
                    'yes_link' => __('Yes (with seats linked to booking form)', 'rrze-rsvp'),
                    'yes' => __('Yes (no link)', 'rrze-rsvp'),
                    'no'  => __('No', 'rrze-rsvp')
                ]
            ],
            [
                'name'    => 'contact_tracking_note',
                'label'   => __('Note for admins', 'rrze-rsvp'),
                'desc'    => __('Allowed HTML-Tags are:', 'rrze-rsvp') . esc_html(' <' . implode('> <', array_keys(getAllowedHTML())) . '>'),
                'type'    => 'textarea' . (is_super_admin() ? '' : 'readonly'),
                'default' =>  $defaults['contact_tracking_note'],
                'sanitize_callback' => 'wp_kses_custom'                
            ],
            [
                'name'    => 'pdtxt',
                'label'   => __('Note for Public Displays', 'rrze-rsvp'),
                'desc'    => __('Allowed HTML-Tags are:', 'rrze-rsvp') . esc_html(' <' . implode('> <', array_keys(getAllowedHTML())) . '>'),
                'type'    => 'textarea',
                'default' =>  '',
                'sanitize_callback' => 'wp_kses_custom'                
            ],
            [
                'name'    => 'show_dsgvo_supplement',
                'label'   => __('Show Additional Note on Registration Form', 'rrze-rsvp'),
                'desc'    => __('Show an additional text at the bottom of the registration form, just before the submit button. Please enter a german and, if applicable, an english text in the fields below. ', 'rrze-rsvp'),
                'default' => $defaults['show_dsgvo_supplement'],
                'type'    => 'checkbox'
            ],
            [
                'name'    => 'dsgvo_supplement_de',
                'label'   => __('Additional Registration Note Text (German)', 'rrze-rsvp'),
                'desc'    => __('Allowed HTML-Tags are:', 'rrze-rsvp') . esc_html(' <' . implode('> <', array_keys(getAllowedHTML())) . '>'),
                'type'    => 'textarea',
                'default' =>  $defaults['dsgvo_supplement_de'],
                'sanitize_callback' => 'wp_kses_custom'
            ],
            [
                'name'    => 'dsgvo_supplement_en',
                'label'   => __('Additional Registration Note Text (English)', 'rrze-rsvp'),
                'desc'    => __('Allowed HTML-Tags are:', 'rrze-rsvp') . esc_html(' <' . implode('> <', array_keys(getAllowedHTML())) . '>'),
                'type'    => 'textarea',
                'default' =>  $defaults['dsgvo_supplement_en'],
                'sanitize_callback' => 'wp_kses_custom'
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
                'label'             => __('Subject Cancelling', 'rrze-rsvp'),
                'desc'              => __('Subject for cancelling mails', 'rrze-rsvp'),
                'type'              => 'text',
                'default'           =>  $defaults['cancel_subject'],
                'sanitize_callback' => 'sanitize_text_field'
            ],
	        [
                'name'              => 'cancel_subject_en',
                'label'             => __('Subject Cancelling (english)', 'rrze-rsvp'),
                'desc'              => __('Subject for cancelling mails', 'rrze-rsvp'),
                'type'              => 'text',
                'default'           =>  $defaults['cancel_subject_en'],
                'sanitize_callback' => 'sanitize_text_field',
                'exception'         => ['locale' => 'en']
            ],            
	        [
                'name'              => 'cancel_reason_notconfirmed',
                'label'             => __('Reason Cancelling Not Confirmed', 'rrze-rsvp'),
                'desc'              => __('Reason for cancelling mails because there is no confirmation', 'rrze-rsvp'),
                'type'              => 'textarea',
                'default'           =>  $defaults['cancel_reason_notconfirmed'],
                'sanitize_callback' => 'sanitize_text_field'
            ],
	        [
                'name'              => 'cancel_reason_notconfirmed_en',
                'label'             => __('Reason Cancelling Not Confirmed (english)', 'rrze-rsvp'),
                'desc'              => __('Reason for cancelling mails because there is no confirmation', 'rrze-rsvp'),
                'type'              => 'textarea',
                'default'           =>  $defaults['cancel_reason_notconfirmed_en'],
                'sanitize_callback' => 'sanitize_text_field',
                'exception'         => ['locale' => 'en']
            ],
	        [
                'name'              => 'cancel_reason_notcheckedin',
                'label'             => __('Reason Cancelling Not Checked In', 'rrze-rsvp'),
                'desc'              => __('Reason for cancelling mails because there is no check-in', 'rrze-rsvp'),
                'type'              => 'textarea',
                'default'           =>  $defaults['cancel_reason_notcheckedin'],
                'sanitize_callback' => 'sanitize_text_field'
            ],
	        [
                'name'              => 'cancel_reason_notcheckedin_en',
                'label'             => __('Reason Cancelling Not Checked In (english)', 'rrze-rsvp'),
                'desc'              => __('Reason for cancelling mails because there is no check-in', 'rrze-rsvp'),
                'type'              => 'textarea',
                'default'           =>  $defaults['cancel_reason_notcheckedin_en'],
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
        ],
        'ldap' => [
            [
                'name'    => 'server',
                'label'   => __('Server', 'rrze-rsvp'),
                'desc'   => __('LDAP server URL. Leave blank if you are not using LDAP as an authentication system.', 'rrze-rsvp'),
                'type'    => 'text',
		        'sanitize_callback' => 'sanitize_text_field'
            ],
            [
                'name'    => 'port',
                'label'   => __('Port', 'rrze-rsvp'),
                'desc'   => __('LDAP server port.', 'rrze-rsvp'),
                'type'    => 'number',
		        'sanitize_callback' => 'sanitize_natint_field'
            ],
            [
                'name'    => 'distinguished_name',
                'label'   => __('DN', 'rrze-rsvp'),
                'desc'   => __('LDAP Distinguished Name.', 'rrze-rsvp'),
                'type'    => 'text',
		        'sanitize_callback' => 'sanitize_text_field'
            ],
            [
                'name'    => 'bind_base_dn',
                'label'   => __('Bind Base DN', 'rrze-rsvp'),
                'desc'   => __('DN to bind on.', 'rrze-rsvp'),
                'type'    => 'text',
		        'sanitize_callback' => 'sanitize_text_field'
            ],
            [
                'name'    => 'search_base_dn',
                'label'   => __('Search Base DN', 'rrze-rsvp'),
                'desc'   => __('DN to search in.', 'rrze-rsvp'),
                'type'    => 'text',
		        'sanitize_callback' => 'sanitize_text_field'
            ],
        ],
        'reset' => [
            [
                'name'  => 'reset_settings',
                'label'   => '',
                'desc'   => __('Yes, I want to reset <strong>all</strong> settings.', 'rrze-rsvp'),
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
                'title' => __('RSVP Booking', 'rrze-rsvp'), // Der Titel, der in der Blockauswahl im Gutenberg Editor angezeigt wird
                'category' => 'widgets', // Die Kategorie, in der der Block im Gutenberg Editor angezeigt wird
                'icon' => 'admin-users',  // Das Icon des Blocks
                'show_block' => 'content', // 'right' or 'content' : Anzeige des Blocks im Content-Bereich oder in der rechten Spalte
                'message' => __( 'Find the settings on the right side', 'rrze-rsvp' ) // erscheint bei Auswahl des Blocks, wenn "show_block" auf 'right' gesetzt ist
            ],
            'days' => [
                'default' => '',
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
            'ldap' => [
                'field_type' => 'toggle',
                'label' => __( 'Require LDAP Authentication', 'rrze-rsvp' ),
                'type' => 'boolean',
                'default'   => false
            ],
        ],
        'rsvp-availability' => [
            'block' => [
                'blocktype' => 'rrze-rsvp/rsvp-availability', // dieser Wert muss angepasst werden
                'blockname' => 'rsvp-availability', // dieser Wert muss angepasst werden
                'title' => __('RSVP Availability', 'rrze-rsvp'), // Der Titel, der in der Blockauswahl im Gutenberg Editor angezeigt wird
                'category' => 'widgets', // Die Kategorie, in der der Block im Gutenberg Editor angezeigt wird
                'icon' => 'admin-users',  // Das Icon des Blocks
                'show_block' => 'content', // 'right' or 'content' : Anzeige des Blocks im Content-Bereich oder in der rechten Spalte
                'message' => __( 'Find the settings on the right side', 'rrze-rsvp' ) // erscheint bei Auswahl des Blocks, wenn "show_block" auf 'right' gesetzt ist
            ],
            'days' => [
                'default' => '',
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
        'rsvp-qr' => [
            'block' => [
                'blocktype' => 'rrze-rsvp/rsvp-qr', // dieser Wert muss angepasst werden
                'blockname' => 'rsvp-qr', // dieser Wert muss angepasst werden
                'title' => 'RSVP QR', // Der Titel, der in der Blockauswahl im Gutenberg Editor angezeigt wird
                'category' => 'widgets', // Die Kategorie, in der der Block im Gutenberg Editor angezeigt wird
                'icon' => 'admin-users',  // Das Icon des Blocks
                'show_block' => 'content', // 'right' or 'content' : Anzeige des Blocks im Content-Bereich oder in der rechten Spalte
                'message' => __( 'Find the settings on the right side', 'rrze-rsvp' ) // erscheint bei Auswahl des Blocks, wenn "show_block" auf 'right' gesetzt ist
            ],
            'seat' => [
                'default' => 0,
                'field_type' => 'text', // Art des Feldes im Gutenberg Editor
                'label' => __( 'Seat ID', 'rrze-rsvp' ),
                'type' => 'number' // Variablentyp der Eingabe
            ],
        ]
    ];
}

