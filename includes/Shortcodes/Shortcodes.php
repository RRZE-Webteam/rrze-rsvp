<?php

namespace RRZE\RSVP\Shortcodes;

defined('ABSPATH') || exit;

use RRZE\RSVP\Main;
use RRZE\RSVP\Shortcodes\Bookings;
use RRZE\RSVP\Shortcodes\Availability;
use function RRZE\RSVP\Config\getShortcodeSettings;

/**
 * Laden und definieren der Shortcodes
 */
class Shortcodes {
    protected $pluginFile;
    private $settings = '';
    private $shortcodesettings = 'X';

    public function __construct($pluginFile, $settings) {
        $this->pluginFile = $pluginFile;
        $this->settings = $settings;
        $this->shortcodesettings = getShortcodeSettings();
        //var_dump($this->shortcodesettings);

        $this->tmp_availability = [
            '2225840' => [
                'availablity' => [
                    '2020-07-19' => ['09:00','14:30'],
                    '2020-07-20' => ['09:00','14:30'],
                    '2020-07-21' => ['09:00','14:30'],
                    '2020-07-22' => ['09:00','14:30'],
                    '2020-07-23' => ['09:00','14:30'],
                    '2020-07-24' => ['09:00','14:30'],
                    '2020-07-26' => ['09:00','14:30'],
                    '2020-07-27' => ['09:00'],
                    '2020-07-28' => ['09:00','14:30'],
                    '2020-07-29' => ['09:00','14:30'],
                    '2020-07-30' => ['09:00','14:30'],
                ],
                'room' => '244'
            ],
            '2225856' => [
                'availablity' => [
                    '2020-07-23' => ['14:30'],
                    '2020-07-24' => ['09:00','14:30'],
                    '2020-07-26' => ['09:00'],
                    '2020-07-27' => ['09:00'],
                    '2020-07-28' => ['09:00','14:30'],
                    '2020-07-29' => ['09:00'],
                    '2020-07-30' => ['14:30'],
                ],
                'room' => '3'
            ],
            '2225858' => [
                'availablity' => [
                    '2020-07-19' => ['09:00'],
                    '2020-07-20' => ['09:00','14:30'],
                    '2020-07-23' => ['09:00','14:30'],
                    '2020-07-24' => ['14:30'],
                    '2020-07-26' => ['09:00','14:30'],
                    '2020-07-28' => ['09:00','14:30'],
                    '2020-07-29' => ['14:30'],
                    '2020-07-30' => ['09:00','14:30'],
                ],
                'room' => '3'
            ],
            '2225860' => [
                'availablity' => [
                    '2020-07-19' => ['09:00','14:30'],
                    '2020-07-20' => ['09:00'],
                    '2020-07-21' => ['09:00'],
                    '2020-07-22' => ['09:00','14:30'],
                    '2020-07-23' => ['14:30'],
                    '2020-07-24' => ['09:00','14:30'],
                    '2020-07-27' => ['09:00'],
                    '2020-07-30' => ['14:30'],
                ],
                'room' => '3'
            ],
            '2225864' => [
                'availablity' => [
                    '2020-07-19' => ['14:30'],
                    '2020-07-21' => ['14:30'],
                    '2020-07-22' => ['09:00','14:30'],
                    '2020-07-23' => ['09:00'],
                    '2020-07-24' => ['09:00','14:30'],
                    '2020-07-28' => ['09:00'],
                ],
                'room' => '3'
            ],
        ];
    }

    public function onLoaded() {
        add_action('wp_enqueue_scripts', [$this, 'enqueueScripts']);
        add_action( 'wp_ajax_UpdateCalendar', [$this, 'ajaxUpdateCalendar'] );
        add_action( 'wp_ajax_UpdateForm', [$this, 'ajaxUpdateForm'] );
        add_action( 'wp_ajax_ShowItemInfo', [$this, 'ajaxShowItemInfo'] );

        $bookings_shortcode = new Bookings($this->pluginFile,  $this->settings);
        $bookings_shortcode->onLoaded();

        $availability_shortcode = new Availability($this->pluginFile,  $this->settings);
        $availability_shortcode->onLoaded();
    }
    /**
     * Enqueue der Skripte.
     */
    public function enqueueScripts()
    {
        wp_register_style('rrze-rsvp-shortcode', plugins_url('assets/css/shortcode.css', plugin_basename($this->pluginFile)));
        wp_register_script('rrze-rsvp-shortcode', plugins_url('assets/js/shortcode.js', plugin_basename($this->pluginFile)));
        $nonce = wp_create_nonce( 'rsvp-ajax-nonce' );
        wp_localize_script('rrze-rsvp-shortcode', 'rsvp_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => $nonce,
        ]);
    }


    public function gutenberg_init() {
        // Skip block registration if Gutenberg is not enabled/merged.
        if ( ! function_exists( 'register_block_type' ) ) {
            return;
        }

        $js = '../assets/js/gutenberg.js';
        $editor_script = $this->settings['block']['blockname'] . '-blockJS';

        wp_register_script(
            $editor_script,
            plugins_url( $js, __FILE__ ),
            array(
                'wp-blocks',
                'wp-i18n',
                'wp-element',
                'wp-components',
                'wp-editor'
            ),
            filemtime( dirname( __FILE__ ) . '/' . $js )
        );

        wp_localize_script( $editor_script, 'blockname', $this->settings['block']['blockname'] );

        register_block_type( $this->settings['block']['blocktype'], array(
                'editor_script' => $editor_script,
                'render_callback' => [$this, 'shortcodeOutput'],
                'attributes' => $this->settings
            )
        );

        wp_localize_script( $editor_script, $this->settings['block']['blockname'] . 'Config', $this->settings );
    }

    public function shortcodeAtts($atts, $tag, $shortcodesettings) {
        // merge given attributes with default ones
        $atts_default = array();
        //var_dump($this->shortcodesettings);
        foreach( $shortcodesettings as $tagname => $settings) {
            foreach( $settings as $k => $v ) {
                if ($k != 'block') {
                    $atts_default[$tagname][$k] = $v['default'];
                }
            }
        }
        return shortcode_atts( $atts_default[$tag], $atts );
    }
}
