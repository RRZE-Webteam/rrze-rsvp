<?php


namespace RRZE\RSVP;


class Tools
{

    function __construct() {

    }

    public function onLoaded() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
    }

    public function add_admin_menu() {
        $menu_id = add_management_page(
            _x( 'Create Seats', 'admin page title', 'rrze-rsvp' ),
            _x( 'RSVP Create Seats', 'admin menu entry title', 'rrze-rsvp' ),
            'manage_options',
            'rrze-rsvp',
            array( $this, 'admin_page' )
        );
    }

    function admin_page() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html_x( 'Create Seats', 'admin page title', 'rrze-rsvp' ) . '</h1>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="select_room">' . __('Room','rrze-rsvp') . '</label></th>';
        echo '<td>'
            . '<select id="select_room" name="select_room">'
            . '<option>&mdash; ' . __('Please select', 'rrze-rsvp') . ' &mdash;</option>';
        $rooms = get_posts([
            'post_type' => 'room',
            'post_statue' => 'publish',
            'nopaging' => true,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        foreach ($rooms as $room) {
            echo '<option value="' . $room->ID . '">' . $room->post_title . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr>'
            . '<th scope="row"><label for="number_rows">' . __('Number of Table Rows', 'rrze-rsvp') . '</label></th>'
            . '<td><input type="number" id="number_rows" name="number_rows" min="1"></td>'
            . '</tr>';

        echo '<tr>'
            . '<th scope="row"><strong>' . __('Rows Numbering System', 'rrze-rsvp') . '</strong></th>'
            . '<td><input type="radio" id="schema_rows_numeric" name="schema_rows"> <label  for="schema_rows">' . __('Numeric (1 - 999)', 'rrze-rsvp') . '</label><br />'
            . '<input type="radio" id="schema_rows_a-z" name="schema_rows"> <label  for="schema_rows">' . __('A-Z', 'rrze-rsvp') . '</label></td>'
            . '</tr>';

        echo '<tr>'
            . '<th scope="row"><label for="number_seats">' . __('Number of Seats per Row', 'rrze-rsvp') . '</label></th>'
            . '<td><input type="number" id="number_seats" name="number_seats" min="1"></td>'
            . '</tr>';

        echo '<tr>'
             . '<th scope="row"><strong>' . __('Seats Numbering System', 'rrze-rsvp') . '</strong></th>'
             . '<td><input type="radio" id="schema_seats_numeric" name="schema_seats"> <label  for="schema_seats">' . __('Numeric (1 - 999)', 'rrze-rsvp') . '</label><br />'
             . '<input type="radio" id="schema_seats_a-z" name="schema_seats"> <label  for="schema_seats">' . __('A-Z', 'rrze-rsvp') . '</label></td>'
             . '</tr>';

        echo '<tr><th scope="row"><strong>' . __('Equipment','rrze-rsvp') . '</strong></th>';
        echo '<td> [...]';

        echo '<p class="description">' . __('Preselect equipment that is available on all seats. You can modify the equipment later by editing the seats individually or by bulk editing several seats in the seats table.', 'rrze-rsvp') . '</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
        echo '<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="' . __('Generate Seats', 'rrze-rsvp') . '"></p>';

        echo '</div>';
    }
}