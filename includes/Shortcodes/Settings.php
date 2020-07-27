<?php

namespace RRZE\RSVP\Shortcodes;

defined('ABSPATH') || exit;

class Settings
{
    public function __construct()
    {
        //
    }

    public function getFields(){
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
                'location' => [
                    'default' => '',
                    'field_type' => 'text', // Art des Feldes im Gutenberg Editor
                    'label' => __( 'Location', 'rrze-rsvp' ),
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
            'examples' => [
                'block' => [
                    'blocktype' => 'rrze-rsvp/rsvp-availability', // dieser Wert muss angepasst werden
                    'blockname' => 'rsvp-availability', // dieser Wert muss angepasst werden
                    'title' => 'RSVP Availability', // Der Titel, der in der Blockauswahl im Gutenberg Editor angezeigt wird
                    'category' => 'widgets', // Die Kategorie, in der der Block im Gutenberg Editor angezeigt wird
                    'icon' => 'admin-users',  // Das Icon des Blocks
                    'show_block' => 'content', // 'right' or 'content' : Anzeige des Blocks im Content-Bereich oder in der rechten Spalte
                    'message' => __( 'Find the settings on the right side', 'rrze-rsvp' ) // erscheint bei Auswahl des Blocks, wenn "show_block" auf 'right' gesetzt ist
                ],
                'Beispiel-Textfeld-Text' => [
                    'default' => 'ein Beispiel-Wert',
                    'field_type' => 'text', // Art des Feldes im Gutenberg Editor
                    'label' => __( 'Beschriftung', 'rrze-rsvp' ),
                    'type' => 'text' // Variablentyp der Eingabe
                ],
                'Beispiel-Textfeld-Number' => [
                    'default' => 0,
                    'field_type' => 'text', // Art des Feldes im Gutenberg Editor
                    'label' => __( 'Beschriftung', 'rrze-rsvp' ),
                    'type' => 'number' // Variablentyp der Eingabe
                ],
                'Beispiel-Textarea-String' => [
                    'default' => 'ein Beispiel-Wert',
                    'field_type' => 'textarea',
                    'label' => __( 'Beschriftung', 'rrze-rsvp' ),
                    'type' => 'string',
                    'rows' => 5 // Anzahl der Zeilen
                ],
                'Beispiel-Radiobutton' => [
                    'values' => [
                        'wert1' => __( 'Wert 1', 'rrze-rsvp' ), // wert1 mit Beschriftung
                        'wert2' => __( 'Wert 2', 'rrze-rsvp' )
                    ],
                    'default' => 'DESC', // vorausgewählter Wert
                    'field_type' => 'radio',
                    'label' => __( 'Order', 'rrze-rsvp' ), // Beschriftung der Radiobutton-Gruppe
                    'type' => 'string' // Variablentyp des auswählbaren Werts
                ],
                'Beispiel-Checkbox' => [
                    'field_type' => 'checkbox',
                    'label' => __( 'Beschriftung', 'rrze-rsvp' ),
                    'type' => 'boolean',
                    'default'   => true // Vorauswahl: Haken gesetzt
                ],
                'Beispiel-Toggle' => [
                    'field_type' => 'toggle',
                    'label' => __( 'Beschriftung', 'rrze-rsvp' ),
                    'type' => 'boolean',
                    'default'   => true // Vorauswahl: ausgewählt
                ],
                'Beispiel-Select' => [
                    'values' => [
                        'wert1' => __( 'Wert 1', 'rrze-rsvp' ),
                        'wert2' => __( 'Wert 2', 'rrze-rsvp' )
                    ],
                    'default' => 'wert1', // vorausgewählter Wert: Achtung: string, kein array!
                    'field_type' => 'select',
                    'label' => __( 'Beschrifung', 'rrze-rsvp' ),
                    'type' => 'string' // Variablentyp des auswählbaren Werts
                ],
                'Beispiel-Multi-Select' => [
                    'values' => [
                        'wert1' => __( 'Wert 1', 'rrze-rsvp' ),
                        'wert2' => __( 'Wert 2', 'rrze-rsvp' ),
                        'wert3' => __( 'Wert 2', 'rrze-rsvp' )
                    ],
                    'default' => ['wert1','wert3'], // vorausgewählte(r) Wert(e): Achtung: array, kein string!
                    'field_type' => 'multi_select',
                    'label' => __( 'Beschrifung', 'rrze-rsvp' ),
                    'type' => 'array',
                    'items'   => [
                        'type' => 'string' // Variablentyp der auswählbaren Werte
                    ]
                ]
            ],
        ];
    }
}
