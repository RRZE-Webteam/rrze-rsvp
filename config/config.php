<?php

namespace RRZE\RSVP\Config;

defined('ABSPATH') || exit;

define('CORONA_MODE', false);

/**
 * Gibt der Name der Option zurück.
 * @return array [description]
 */
function getOptionName()
{
    return 'rrze_rsvp';
}

/**
 * Fixe und nicht aenderbare Plugin-Optionen
 * @return array
 */
function getConstants()
{
    $options = array(

        'fauthemes' => [
            'FAU-Einrichtungen',
            'FAU-Philfak',
            'FAU-Natfak',
            'FAU-RWFak',
            'FAU-Medfak',
            'FAU-Techfak',
            'FAU-Jobs',
        ],

    );
    // für ergänzende Optionen aus anderen Plugins
    $options = apply_filters('rrze_rsvp_constants', $options);
    return $options; // Standard-Array für zukünftige Optionen
}

function isAllowedSearchForGuest()
{
    $allowedDomains = [
        'www.nickless.test.rrze.fau.de',
        'ub.fau.de',
    ];
    return in_array($_SERVER['SERVER_NAME'], $allowedDomains);
}

// used in wp_kses_custom() and for 'desc' of 'contact_tracking_note'
function getAllowedHTML()
{
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
function wp_kses_custom($str)
{
    $allowed_html = getAllowedHTML();

    $allowed_protocols = [
        'http',
        'https',
        'mailto',
    ];

    return wp_kses($str, $allowed_html, $allowed_protocols);
}

// sanitzes natural number (positive INT)
function sanitize_natint_field($input)
{
    return filter_var($input, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
}

/**
 * Provide localized defaults for room booking, notifications and related settings.
 *
 * @return array<string, mixed> Default values before saved site settings are applied.
 */
function defaultOptions()
{

    $sender_name = '';
    $notification_email = '';
    $sender_email = '';

    $blogAdminUsers = get_users('role=Administrator');
    if ($blogAdminUsers) {
        $sender_name = $blogAdminUsers[0]->display_name;
        $sender_email = $blogAdminUsers[0]->user_email;
        $notification_email = $blogAdminUsers[0]->user_email;
    }

    return [
        'single_room_availability_table' => 'yes_link',
        'contact_tracking_note' => '<h3>' . esc_html__('Requests for booking data', 'rrze-rsvp') . '</h3><p>'
            . esc_html__('Exports of booking data are managed centrally in this network. Contact the network administration and specify the room and time period you need.', 'rrze-rsvp') . '</p>',
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
        'dsgvo-declaration' => __('Ich bin damit einverstanden, dass meine Kontaktdaten für die Dauer des Vorganges der Platzbuchung und bis zu 4 Wochen danach zum Zwecke der Nachverfolgung gemäß der gesetzlichen Grundlagen zur Corona-Bekämpfung gespeichert werden dürfen. Ebenso wird Raumverantwortlichen und Veranstalter von Sprechstunden das Recht eingeräumt, während der Dauer des Buchungsprozesses und bis zum Ende des ausgewählten Termins Einblick in folgende Buchungsdaten zu nehmen: E-Mailadresse, Name, Vorname. Raumverantwortliche und Veranstalter von Sprechstunden erhalten diese Daten allein zum Zweck der Durchführung und Verwaltung des Termins gemäß §6 Abs1 a DSGVO. Die Telefonnummer wird nur zum Zwecke der Kontaktverfolgung aufgrund der gesetzlicher Grundlagen zur Pandemiebekämpfung für Gesundheitsbehörden erfasst.', 'rrze-rsvp'),
    ];
}

/**
 * Gibt die Einstellungen des Menus zurück.
 * @return array [description]
 */
function getMenuSettings()
{
    return [
        'page_title' => __('RRZE RSVP', 'rrze-rsvp'),
        'menu_title' => __('RRZE RSVP', 'rrze-rsvp'),
        'capability' => 'manage_options',
        'menu_slug' => 'rrze-rsvp',
        'title' => __('RRZE RSVP Settings', 'rrze-rsvp'),
    ];
}

/**
 * Define settings tabs and their introductory descriptions.
 *
 * @return array<int, array<string, mixed>> Tab identifiers, titles, descriptions and optional field groups.
 */
function getSections()
{
    return [
        [
            'id' => 'general',
            'title' => __('General Settings', 'rrze-rsvp'),
            'desc' => esc_html__('Configure availability on room pages and the default check-in deadline. Booking periods and other rules are configured for each room.', 'rrze-rsvp'),
        ],
        [
            'id' => 'email',
            'title' => __('E-Mail Settings', 'rrze-rsvp'),
            'desc' => '<p>' . esc_html__('Sender and notification settings apply to room and course bookings. The text templates below apply to room bookings; course emails use their own texts.', 'rrze-rsvp') . '</p>'
                . '<p>' . esc_html__('Room emails include an additional English section when the website language is not English. Edit both language versions together.', 'rrze-rsvp') . '</p>'
                . '<p>' . esc_html__('Booking details and action buttons are added automatically to the message.', 'rrze-rsvp') . '</p>'
                . '<details><summary>' . esc_html__('Available placeholders', 'rrze-rsvp') . '</summary><p>'
                . esc_html__('Use these placeholders in subjects and message texts. They are replaced with the booking details when the email is sent.', 'rrze-rsvp') . '</p>'
                . '<p><code>{{date}}</code>, <code>{{time}}</code>, <code>{{date_en}}</code>, <code>{{time_en}}</code>, '
                . '<code>{{room_name}}</code>, <code>{{seat_name}}</code>, <code>{{guest_name}}</code>, <code>{{guest_email}}</code>, <code>{{notes}}</code></p></details>',
            'groups' => getEmailSettingGroups(),
        ],
        [
            'id' => 'pdf',
            'title' => __('QR PDF Settings', 'rrze-rsvp'),
            'desc' => '<p>' . esc_html__('Design the printable QR-code sheets for your seats. Each sheet links to the seat page and includes the room name and seat name.', 'rrze-rsvp') . '</p>'
                . '<p>' . esc_html__('Create the PDF using “Generate PDF” in the room or seat list. Changes here apply to newly generated PDFs.', 'rrze-rsvp') . '</p>',
            'groups' => getQrSettingGroups(),
        ],
        [
            'id' => 'ldap',
            'title' => __('LDAP Settings', 'rrze-rsvp'),
            'desc' => '<p>' . esc_html__('Configure the directory used for LDAP login to room bookings. Choose which rooms require this login in the settings of each room.', 'rrze-rsvp') . '</p>'
                . '<p>' . esc_html__('If you do not use LDAP, leave the server field empty. Your IT administration can provide the connection and directory details.', 'rrze-rsvp') . '</p>',
            'groups' => getLdapSettingGroups(),
        ],
        [
            'id' => 'reset',
            'title' => __('Reset Settings', 'rrze-rsvp'),
            'desc' => '<p>' . esc_html__('Restore the default RSVP settings for this website.', 'rrze-rsvp') . '</p>',
            'groups' => [
                'defaults' => [
                    'title' => __('What will be reset?', 'rrze-rsvp'),
                    'desc' => __('General, email, QR-code and LDAP settings will be reset. Your custom email texts, QR-code instructions and LDAP connection details will be removed.', 'rrze-rsvp'),
                    'fields' => ['reset_settings'],
                    'notice' => __('Rooms, seats, courses and bookings, including their individual settings, will be kept. This action cannot be undone; copy any settings you want to keep before continuing.', 'rrze-rsvp'),
                ],
            ],
        ],
    ];
}

/**
 * Group existing email option keys by purpose without changing their storage names.
 *
 * @return array<string, array<string, mixed>> Ordered headings, help text and field keys.
 */
function getEmailSettingGroups(): array
{
    return [
        'delivery' => [
            'title' => __('Sender and notifications', 'rrze-rsvp'),
            'desc' => __('Choose the sender shown in booking emails and who receives internal notifications.', 'rrze-rsvp'),
            'fields' => ['notification_email', 'notification_if_new', 'notification_if_cancel', 'sender_name', 'sender_email'],
        ],
        'received' => [
            'title' => __('Booking received — awaiting approval', 'rrze-rsvp'),
            'desc' => __('Sent to the person booking when their request still needs approval by the room administration.', 'rrze-rsvp'),
            'bilingual' => true,
            'fields' => ['received_subject', 'received_subject_en', 'received_text', 'received_text_en'],
        ],
        'verification' => [
            'title' => __('Booking confirmation required', 'rrze-rsvp'),
            'desc' => __('Asks the person booking to confirm their reservation using the link in the email.', 'rrze-rsvp'),
            'bilingual' => true,
            'fields' => ['force_to_confirm_subject', 'force_to_confirm_subject_en', 'force_to_confirm_text', 'force_to_confirm_text_en'],
        ],
        'confirmation' => [
            'title' => __('Booking confirmed', 'rrze-rsvp'),
            'desc' => __('Sent when a booking is confirmed. These texts are also used for the check-in notification.', 'rrze-rsvp'),
            'bilingual' => true,
            'fields' => ['confirm_subject', 'confirm_subject_en', 'confirm_text', 'confirm_text_en'],
        ],
        'cancellation' => [
            'title' => __('Booking cancelled', 'rrze-rsvp'),
            'desc' => __('Sent to the person booking when their reservation is cancelled. For automatic cancellations, the matching reason is added to the message.', 'rrze-rsvp'),
            'bilingual' => true,
            'fields' => ['cancel_subject', 'cancel_subject_en', 'cancel_text', 'cancel_text_en', 'cancel_reason_notconfirmed', 'cancel_reason_notconfirmed_en', 'cancel_reason_notcheckedin', 'cancel_reason_notcheckedin_en'],
        ],
    ];
}

/**
 * Group the print options and keep both fixed QR instruction languages visible.
 *
 * @return array<string, array<string, mixed>> PDF headings, descriptions and existing option keys.
 */
function getQrSettingGroups(): array
{
    return [
        'branding' => [
            'title' => __('Header and footer', 'rrze-rsvp'),
            'desc' => __('Choose which website information appears on the printed sheets.', 'rrze-rsvp'),
            'fields' => ['fau_logo', 'website_logo', 'website_url'],
        ],
        'instructions' => [
            'title' => __('Instructions beside the QR code', 'rrze-rsvp'),
            'desc' => __('Both language versions appear beside the QR code. Leave a field empty to omit that language from the PDF.', 'rrze-rsvp'),
            'bilingual' => true,
            'always_show_languages' => true,
            'fields' => ['instructions_de', 'instructions_en'],
        ],
        'details' => [
            'title' => __('Additional room and seat details', 'rrze-rsvp'),
            'desc' => __('Add information from the room or seat. The room name, seat name, QR code and link to the seat page are always included.', 'rrze-rsvp'),
            'fields' => ['room_text', 'room_address', 'seat_equipment'],
        ],
    ];
}

/**
 * Separate LDAP transport settings from the directory settings used for login.
 *
 * @return array<string, array<string, mixed>> LDAP headings, descriptions and existing option keys.
 */
function getLdapSettingGroups(): array
{
    return [
        'connection' => [
            'title' => __('Server connection', 'rrze-rsvp'),
            'desc' => __('Enter the server address and port supplied by your IT administration.', 'rrze-rsvp'),
            'fields' => ['server', 'port'],
        ],
        'directory' => [
            'title' => __('Login and directory search', 'rrze-rsvp'),
            'desc' => __('RSVP signs in with username@domain and searches for the account by sAMAccountName. The account must contain cn and mail attributes.', 'rrze-rsvp'),
            'fields' => ['bind_base_dn', 'search_base_dn'],
        ],
    ];
}

/**
 * Define settings fields, defaults, help text and validation callbacks for each tab.
 *
 * @return array<string, array<int, array<string, mixed>>> Field definitions grouped by tab.
 */
function getFields()
{
    $defaults = defaultOptions();
    $language = str_starts_with(get_locale(), 'de_') ? __('German', 'rrze-rsvp')
        : (str_starts_with(get_locale(), 'en_') ? __('English', 'rrze-rsvp') : __('Website language', 'rrze-rsvp'));

    return [
        'general' => [
            [
                'name' => 'single_room_availability_table',
                'label' => __('Availability on room pages', 'rrze-rsvp'),
                'desc' => __('Choose whether public room pages show available times and seats with links to the booking form. This does not affect the booking calendar embedded with a shortcode.', 'rrze-rsvp'),
                'type' => 'radio',
                'default' => $defaults['single_room_availability_table'],
                'options' => [
                    'yes_link' => __('Show available times with booking links', 'rrze-rsvp'),
                    'yes' => __('Show available times without booking links', 'rrze-rsvp'),
                    'no' => __('Hide the availability list', 'rrze-rsvp'),
                ],
            ],
            [
                'name' => 'check-in-time',
                'label' => __('Check-in deadline (minutes)', 'rrze-rsvp'),
                'desc' => __('Default time allowed for check-in, starting when the booking begins. For bookings made after the start time, it starts when the booking is made. If check-in is required, bookings without a check-in are cancelled after this deadline. You can set a different deadline for each room.', 'rrze-rsvp'),
                'type' => 'number',
                'default' => $defaults['check-in-time'],
                'sanitize_callback' => 'sanitize_natint_field',
            ],
            [
                'name' => 'contact_tracking_note',
                'label' => __('Notice about central data exports', 'rrze-rsvp'),
                'desc' => __('Internal text on the contact-tracking information page when data exports are managed by the network extension. Only network administrators can edit this text.', 'rrze-rsvp')
                    . '<br>' . __('Allowed HTML-Tags are:', 'rrze-rsvp') . esc_html(' <' . implode('> <', array_keys(getAllowedHTML())) . '>'),
                'hidden' => !CORONA_MODE,
                'type' => 'textarea' . (is_super_admin() ? '' : 'readonly'),
                'default' => $defaults['contact_tracking_note'],
                'sanitize_callback' => 'wp_kses_custom',
            ],
        ],
        'email' => [
            [
                'name' => 'notification_email',
                'label' => __('Recipient of internal notifications', 'rrze-rsvp'),
                'desc' => __('New-booking and cancellation notifications are sent to this address when enabled below.', 'rrze-rsvp'),
                'type' => 'email',
                'default' => $defaults['notification_email'],
                'sanitize_callback' => 'sanitize_email',
            ],
            [
                'name' => 'notification_if_new',
                'label' => __('Booking Notification', 'rrze-rsvp'),
                'desc' => __('Notify the address above about new bookings.', 'rrze-rsvp'),
                'type' => 'radio',
                'options' => [
                    'yes' => __('Yes', 'rrze-rsvp'),
                    'no' => __('No', 'rrze-rsvp'),
                ],
                'default' => $defaults['notification_if_new'],
            ],
            [
                'name' => 'notification_if_cancel',
                'label' => __('Cancel Notification', 'rrze-rsvp'),
                'desc' => __('Notify the address above about cancelled bookings.', 'rrze-rsvp'),
                'type' => 'radio',
                'options' => [
                    'yes' => __('Yes', 'rrze-rsvp'),
                    'no' => __('No', 'rrze-rsvp'),
                ],
                'default' => $defaults['notification_if_cancel'],
            ],
            [
                'name' => 'sender_name',
                'label' => __('Sender name', 'rrze-rsvp'),
                'desc' => __('The name recipients see as the sender of RSVP emails.', 'rrze-rsvp'),
                'placeholder' => __('Sender name', 'rrze-rsvp'),
                'type' => 'text',
                'default' => $defaults['sender_name'],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            [
                'name' => 'sender_email',
                'label' => __('Sender email', 'rrze-rsvp'),
                'desc' => __('The email address RSVP uses as the sender of booking messages.', 'rrze-rsvp'),
                'type' => 'email',
                'default' => $defaults['sender_email'],
                'sanitize_callback' => 'sanitize_email',
            ],
            [
                'name' => 'received_subject',
                'label' => sprintf(__('Subject (%s)', 'rrze-rsvp'), $language),
                'type' => 'text',
                'default' => $defaults['received_subject'],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            [
                'name' => 'received_subject_en',
                'label' => sprintf(__('Subject (%s)', 'rrze-rsvp'), __('English', 'rrze-rsvp')),
                'type' => 'text',
                'default' => $defaults['received_subject_en'],
                'sanitize_callback' => 'sanitize_text_field',
                'exception' => ['locale' => 'en'],
            ],
            [
                'name' => 'received_text',
                'label' => sprintf(__('Message (%s)', 'rrze-rsvp'), $language),
                'type' => 'textarea',
                'default' => $defaults['received_text'],
            ],
            [
                'name' => 'received_text_en',
                'label' => sprintf(__('Message (%s)', 'rrze-rsvp'), __('English', 'rrze-rsvp')),
                'type' => 'textarea',
                'default' => $defaults['received_text_en'],
                'exception' => ['locale' => 'en'],
            ],
            [
                'name' => 'force_to_confirm_subject',
                'label' => sprintf(__('Subject (%s)', 'rrze-rsvp'), $language),
                'type' => 'text',
                'default' => $defaults['force_to_confirm_subject'],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            [
                'name' => 'force_to_confirm_subject_en',
                'label' => sprintf(__('Subject (%s)', 'rrze-rsvp'), __('English', 'rrze-rsvp')),
                'type' => 'text',
                'default' => $defaults['force_to_confirm_subject_en'],
                'sanitize_callback' => 'sanitize_text_field',
                'exception' => ['locale' => 'en'],
            ],
            [
                'name' => 'force_to_confirm_text',
                'label' => sprintf(__('Message (%s)', 'rrze-rsvp'), $language),
                'type' => 'textarea',
                'default' => $defaults['force_to_confirm_text'],
            ],
            [
                'name' => 'force_to_confirm_text_en',
                'label' => sprintf(__('Message (%s)', 'rrze-rsvp'), __('English', 'rrze-rsvp')),
                'type' => 'textarea',
                'default' => $defaults['force_to_confirm_text_en'],
                'exception' => ['locale' => 'en'],
            ],
            [
                'name' => 'confirm_subject',
                'label' => sprintf(__('Subject (%s)', 'rrze-rsvp'), $language),
                'type' => 'text',
                'default' => $defaults['confirm_subject'],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            [
                'name' => 'confirm_subject_en',
                'label' => sprintf(__('Subject (%s)', 'rrze-rsvp'), __('English', 'rrze-rsvp')),
                'type' => 'text',
                'default' => $defaults['confirm_subject_en'],
                'sanitize_callback' => 'sanitize_text_field',
                'exception' => ['locale' => 'en'],
            ],
            [
                'name' => 'confirm_text',
                'label' => sprintf(__('Message (%s)', 'rrze-rsvp'), $language),
                'type' => 'textarea',
                'default' => $defaults['confirm_text'],
            ],
            [
                'name' => 'confirm_text_en',
                'label' => sprintf(__('Message (%s)', 'rrze-rsvp'), __('English', 'rrze-rsvp')),
                'type' => 'textarea',
                'default' => $defaults['confirm_text_en'],
                'exception' => ['locale' => 'en'],
            ],
            [
                'name' => 'cancel_subject',
                'label' => sprintf(__('Subject (%s)', 'rrze-rsvp'), $language),
                'type' => 'text',
                'default' => $defaults['cancel_subject'],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            [
                'name' => 'cancel_subject_en',
                'label' => sprintf(__('Subject (%s)', 'rrze-rsvp'), __('English', 'rrze-rsvp')),
                'type' => 'text',
                'default' => $defaults['cancel_subject_en'],
                'sanitize_callback' => 'sanitize_text_field',
                'exception' => ['locale' => 'en'],
            ],
            [
                'name' => 'cancel_text',
                'label' => sprintf(__('Message (%s)', 'rrze-rsvp'), $language),
                'type' => 'textarea',
                'default' => $defaults['cancel_text'],
            ],
            [
                'name' => 'cancel_text_en',
                'label' => sprintf(__('Message (%s)', 'rrze-rsvp'), __('English', 'rrze-rsvp')),
                'type' => 'textarea',
                'default' => $defaults['cancel_text_en'],
                'exception' => ['locale' => 'en'],
            ],
            [
                'name' => 'cancel_reason_notconfirmed',
                'label' => sprintf(__('Reason: no confirmation (%s)', 'rrze-rsvp'), $language),
                'type' => 'textarea',
                'default' => $defaults['cancel_reason_notconfirmed'],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            [
                'name' => 'cancel_reason_notconfirmed_en',
                'label' => sprintf(__('Reason: no confirmation (%s)', 'rrze-rsvp'), __('English', 'rrze-rsvp')),
                'type' => 'textarea',
                'default' => $defaults['cancel_reason_notconfirmed_en'],
                'sanitize_callback' => 'sanitize_text_field',
                'exception' => ['locale' => 'en'],
            ],
            [
                'name' => 'cancel_reason_notcheckedin',
                'label' => sprintf(__('Reason: no check-in (%s)', 'rrze-rsvp'), $language),
                'type' => 'textarea',
                'default' => $defaults['cancel_reason_notcheckedin'],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            [
                'name' => 'cancel_reason_notcheckedin_en',
                'label' => sprintf(__('Reason: no check-in (%s)', 'rrze-rsvp'), __('English', 'rrze-rsvp')),
                'type' => 'textarea',
                'default' => $defaults['cancel_reason_notcheckedin_en'],
                'sanitize_callback' => 'sanitize_text_field',
                'exception' => ['locale' => 'en'],
            ],
        ],
        'pdf' => [
            [
                'name' => 'fau_logo',
                'label' => __('FAU logo', 'rrze-rsvp'),
                'desc' => __('Include the FAU logo in the header.', 'rrze-rsvp'),
                'default' => $defaults['fau_logo'],
                'type' => 'checkbox',
            ],
            [
                'name' => 'website_logo',
                'label' => __('Website branding', 'rrze-rsvp'),
                'desc' => __('Include the website header image. If none is configured, use the website title.', 'rrze-rsvp'),
                'default' => $defaults['website_logo'],
                'type' => 'checkbox',
            ],
            [
                'name' => 'website_url',
                'label' => __('Website address', 'rrze-rsvp'),
                'desc' => __('Include the website address in the footer.', 'rrze-rsvp'),
                'default' => $defaults['website_url'],
                'type' => 'checkbox',
            ],
            [
                'name' => 'instructions_de',
                'label' => __('Instructions in German', 'rrze-rsvp'),
                'placeholder' => __('Instructions in German', 'rrze-rsvp'),
                'type' => 'textarea',
                'default' => $defaults['instructions_de'],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            [
                'name' => 'instructions_en',
                'label' => __('Instructions in English', 'rrze-rsvp'),
                'placeholder' => __('Instructions in English', 'rrze-rsvp'),
                'type' => 'textarea',
                'default' => $defaults['instructions_en'],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            [
                'name' => 'room_text',
                'label' => __('Room description', 'rrze-rsvp'),
                'desc' => __('Include the description saved for the room.', 'rrze-rsvp'),
                'default' => $defaults['room_text'],
                'type' => 'checkbox',
            ],
            // [
            //     'name'  => 'room_image',
            //     'label' => __('Print room\'s image', 'rrze-rsvp'),
            //     'default' => $defaults['room_image'],
            //     'type'  => 'checkbox'
            // ],
            [
                'name' => 'room_address',
                'label' => __('Room address', 'rrze-rsvp'),
                'desc' => __('Include the street, postal code and city saved for the room.', 'rrze-rsvp'),
                'default' => $defaults['room_address'],
                'type' => 'checkbox',
            ],
            // [
            //     'name'  => 'room_floorplan',
            //     'label' => __('Print floor plan', 'rrze-rsvp'),
            //     'default' => $defaults['room_floorplan'],
            //     'type'  => 'checkbox'
            // ],
            [
                'name' => 'seat_equipment',
                'label' => __('Seat equipment', 'rrze-rsvp'),
                'desc' => __('Include the equipment assigned to the seat.', 'rrze-rsvp'),
                'default' => $defaults['seat_equipment'],
                'type' => 'checkbox',
            ],
        ],
        'ldap' => [
            [
                'name' => 'server',
                'label' => __('LDAP server', 'rrze-rsvp'),
                'desc' => __('Full server address, for example ldaps://ldap.example.org. An empty field disables LDAP login for room bookings.', 'rrze-rsvp'),
                'type' => 'text',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            [
                'name' => 'port',
                'label' => __('Port', 'rrze-rsvp'),
                'desc' => __('Use the port specified for your LDAP connection.', 'rrze-rsvp'),
                'type' => 'number',
                'sanitize_callback' => 'sanitize_natint_field',
            ],
            [
                'name' => 'distinguished_name',
                // Retain this legacy value in storage; LDAP login does not use it.
                'hidden' => true,
                'label' => __('DN', 'rrze-rsvp'),
                'desc' => __('LDAP Distinguished Name.', 'rrze-rsvp'),
                'type' => 'text',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            [
                'name' => 'bind_base_dn',
                'label' => __('Login domain', 'rrze-rsvp'),
                'desc' => __('Domain appended to the username for login, for example example.org. Enter the domain without @.', 'rrze-rsvp'),
                'type' => 'text',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            [
                'name' => 'search_base_dn',
                'label' => __('User search base (Base DN)', 'rrze-rsvp'),
                'desc' => __('Directory branch in which RSVP searches for user accounts, for example OU=People,DC=example,DC=org.', 'rrze-rsvp'),
                'type' => 'text',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
        'reset' => [
            [
                'name' => 'reset_settings',
                'label' => __('Confirm reset', 'rrze-rsvp'),
                'desc' => __('I want to reset all RSVP settings on this website to their defaults.', 'rrze-rsvp'),
                'type' => 'checkbox',
                'required' => true,
            ],
        ],
    ];
}

/**
 * Gibt die Einstellungen der Parameter für Shortcode für den klassischen Editor und für Gutenberg zurück.
 * @return array [description]
 */

function getShortcodeSettings()
{
    return [
        'rsvp-booking' => [ // Key muss mit dem dazugehörigen Shortcode identisch sein
            'block' => [
                'blocktype' => 'rrze-rsvp/rsvp-booking', // dieser Wert muss angepasst werden
                'blockname' => 'rsvp_booking', // dieser Wert muss angepasst werden
                'title' => __('RSVP Booking', 'rrze-rsvp'), // Der Titel, der in der Blockauswahl im Gutenberg Editor angezeigt wird
                'category' => 'widgets', // Die Kategorie, in der der Block im Gutenberg Editor angezeigt wird
                'icon' => 'admin-users', // Das Icon des Blocks
                'show_block' => 'content', // 'right' or 'content' : Anzeige des Blocks im Content-Bereich oder in der rechten Spalte
                'message' => __('Find the settings on the right side', 'rrze-rsvp'), // erscheint bei Auswahl des Blocks, wenn "show_block" auf 'right' gesetzt ist
            ],
            'days' => [
                'default' => '',
                'field_type' => 'text', // Art des Feldes im Gutenberg Editor
                'label' => __('Days in advance', 'rrze-rsvp'),
                'type' => 'number', // Variablentyp der Eingabe
            ],
            'room' => [
                'default' => '',
                'field_type' => 'text', // Art des Feldes im Gutenberg Editor
                'label' => __('Room', 'rrze-rsvp'),
                'type' => 'text', // Variablentyp der Eingabe
            ],
            'sso' => [
                'field_type' => 'toggle',
                'label' => __('Require SSO Authentication', 'rrze-rsvp'),
                'type' => 'boolean',
                'default' => false,
            ],
            'ldap' => [
                'field_type' => 'toggle',
                'label' => __('Require LDAP Authentication', 'rrze-rsvp'),
                'type' => 'boolean',
                'default' => false,
            ],
        ],
        'rsvp-availability' => [
            'block' => [
                'blocktype' => 'rrze-rsvp/rsvp-availability', // dieser Wert muss angepasst werden
                'blockname' => 'rsvp-availability', // dieser Wert muss angepasst werden
                'title' => __('RSVP Availability', 'rrze-rsvp'), // Der Titel, der in der Blockauswahl im Gutenberg Editor angezeigt wird
                'category' => 'widgets', // Die Kategorie, in der der Block im Gutenberg Editor angezeigt wird
                'icon' => 'admin-users', // Das Icon des Blocks
                'show_block' => 'content', // 'right' or 'content' : Anzeige des Blocks im Content-Bereich oder in der rechten Spalte
                'message' => __('Find the settings on the right side', 'rrze-rsvp'), // erscheint bei Auswahl des Blocks, wenn "show_block" auf 'right' gesetzt ist
            ],
            'days' => [
                'default' => '',
                'field_type' => 'text', // Art des Feldes im Gutenberg Editor
                'label' => __('Days in advance', 'rrze-rsvp'),
                'type' => 'number', // Variablentyp der Eingabe
            ],
            'room' => [
                'default' => '',
                'field_type' => 'text', // Art des Feldes im Gutenberg Editor
                'label' => __('Room(s)', 'rrze-rsvp'),
                'type' => 'text', // Variablentyp der Eingabe
            ],
            'seat' => [
                'default' => '',
                'field_type' => 'text', // Art des Feldes im Gutenberg Editor
                'label' => __('Seat(s)', 'rrze-rsvp'),
                'type' => 'text', // Variablentyp der Eingabe
            ],
            'booking_link' => [
                'field_type' => 'toggle',
                'label' => __('Show booking link', 'rrze-rsvp'),
                'type' => 'boolean',
                'default' => false,
            ],

        ],
        'rsvp-qr' => [
            'block' => [
                'blocktype' => 'rrze-rsvp/rsvp-qr', // dieser Wert muss angepasst werden
                'blockname' => 'rsvp-qr', // dieser Wert muss angepasst werden
                'title' => 'RSVP QR', // Der Titel, der in der Blockauswahl im Gutenberg Editor angezeigt wird
                'category' => 'widgets', // Die Kategorie, in der der Block im Gutenberg Editor angezeigt wird
                'icon' => 'admin-users', // Das Icon des Blocks
                'show_block' => 'content', // 'right' or 'content' : Anzeige des Blocks im Content-Bereich oder in der rechten Spalte
                'message' => __('Find the settings on the right side', 'rrze-rsvp'), // erscheint bei Auswahl des Blocks, wenn "show_block" auf 'right' gesetzt ist
            ],
            'seat' => [
                'default' => 0,
                'field_type' => 'text', // Art des Feldes im Gutenberg Editor
                'label' => __('Seat ID', 'rrze-rsvp'),
                'type' => 'number', // Variablentyp der Eingabe
            ],
        ],
    ];
}
