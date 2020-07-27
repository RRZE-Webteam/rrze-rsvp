<?php

namespace RRZE\RSVP\Bookings;

defined('ABSPATH') || exit;

use RRZE\RSVP\Functions;

class Actions
{
    public function __construct()
    {
        add_action('admin_init', [$this, 'handleActions']);
    }

    public function handleActions()
    {
		if (strpos(Functions::requestVar('page'), 'rrze-rsvp') === false) {
			return;
		}

        // CONFIRM AND DELETE ACTIONS
        if (isset($_GET['action']) && isset($_GET['id']) && wp_verify_nonce($_REQUEST['_wpnonce'], 'action')) {
            $id = intval($_GET['id']);
            if ($_GET['action'] == "acc") {
                $wpdb->update(
                    ATOM_AAM_TABLE_ENTRIES, 		// table name
                    array( 				// data
                        'confirmed'	=> 1
                    ),
                    array( 				// where clause
                        'id'	=> $id
                    )
                );
            } else if ($_GET['action'] == "del") {
                $wpdb->update(
                    ATOM_AAM_TABLE_ENTRIES, 		// table name
                    array( 				// data
                        'confirmed'	=> -1
                    ),
                    array( 				// where clause
                        'id'	=> $id
                    )
                );
            } else if ($_GET['action'] == "del_permanent") {
                $wpdb->delete(
                    ATOM_AAM_TABLE_ENTRIES, 	// table name
                    array( 				// where clause
                        'id'	=> $id
                    )
                );
            } else if ($_GET['action'] == "rec") {
                $wpdb->update(
                    ATOM_AAM_TABLE_ENTRIES, 		// table name
                    array( 				// data
                        'confirmed'	=> 0
                    ),
                    array( 				// where clause
                        'id'	=> $id
                    )
                );
            } else if ($_GET['action'] == "del_excpt") {
                $wpdb->delete(
                    ATOM_AAM_TABLE_EXCEPTIONS, 	// table name
                    array( 				// where clause
                        'id'	=> $id
                    )
                );
            } else if ($_GET['action'] == "del_slot") {
                $wpdb->delete(
                    ATOM_AAM_TABLE_SLOTS, 	// table name
                    array( 				// where clause
                        'id'	=> $id
                    )
                );
            }
            wp_redirect(get_admin_url() . 'admin.php?page=' . sanitize_text_field($_GET['page']));
        }

        // ADD EXCEPTION ACTION
        if (isset($_POST['atom_submit_exception'])) {

            $excpt_begin = $this->validate_date($_POST['atom_exception_begin'], 'Y-m-d');
            $excpt_end = $this->validate_date($_POST['atom_exception_end'], 'Y-m-d');
            $excpt_category = (isset($_POST['atom_exception_category'])) ? intval($_POST['atom_exception_category']) : -1;
            $excpt_description = (isset($_POST['atom_exception_description'])) ? sanitize_text_field($_POST['atom_exception_description']) : '';

            if (!isset($_POST['atom_exception_fullday'])) {
                $excpt_begin .= ' ' . $this->validate_time($_POST['atom_exception_begin_time']);
                $excpt_end .= ' ' . $this->validate_time($_POST['atom_exception_end_time']);
            } else {
                $excpt_begin .= ' 00:00';
                $excpt_end .= ' 00:00';
            }

            $wpdb->insert(
                ATOM_AAM_TABLE_EXCEPTIONS, 	// table name
                array( 				// data
                    'excpt_begin'		=> $excpt_begin,
                    'excpt_end'			=> $excpt_end,
                    'excpt_category'	=> $excpt_category,
                    'excpt_description'	=> $excpt_description
                )
            );

            wp_redirect(get_admin_url() . 'admin.php?page=' . sanitize_text_field($_GET['page']));
        }
    }    
}