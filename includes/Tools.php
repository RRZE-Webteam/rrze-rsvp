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

        //var_dump($_GET);
        if ( isset( $_GET['confirm'] ) ) {
            $args = array();
            $errors = '';
            if ($_GET['room_id'] == '') {
                $errors .= __('Please select a room.');
            }
            if (($_GET['number_rows'] == '')) {
                $errors .= __('Please specify the number of rows.');
            }
            if (!isset($_GET['schema_rows'])) {
                $errors .= __('Please specify the rows numbering system.');
            }
            if (($_GET['number_seats'] == '')) {
                $errors .= __('Please specify the number of seats.');
            }
            if (!isset($_GET['schema_seats'])) {
                $errors .= __('Please specify the seats numbering system.');
            }
            if ($errors != '') {
                print $errors;
                //add_settings_error();
            } else {
                $room_id = absint($_GET['room_id']);
                $num_rows = absint($_GET['number_rows']);
                $num_seats = absint($_GET['number_seats']);
                $schema_rows = $_GET['schema_rows'] == 'a-z' ? 'a-z' : 'num';
                $schema_seats = $_GET['schema_seats'] == 'num' ? 'num' : 'a-z';
                if (isset($_GET['equipment'])) {
                    $equipment = array_map('absint', $_GET['equipment']);
                } else {
                    $equipment = false;
                }
            }
            echo '<div class="notice notice-info">';
            $num_total = $num_rows * $num_seats;
            $room_name = get_the_title($room_id);
            echo '<p>';
            printf( __('%s seats%s will be created in room %s', 'rrze-rsvp'), '<strong>' . $num_total, '</strong>', '<strong>' . $room_name . '</strong>');
            if ($equipment) {
                echo ' ' . __('with the following equipment:', 'rrze-rsvp');
                foreach ($equipment as $e) {
                    $e_term = get_term($e);
                    $equi_names[] = $e_term->name;
                }
                echo ' ' . implode(', ', $equi_names);
            }
            echo '. <br />' . __('Continue?', 'rrze-rsvp');
            echo '</p>';
            echo '<p>';
            echo '<a href="' . admin_url('/tools.php?page=rrze-rsvp') . '" class="button button-secondary" style="margin-right: 20px;">' . __('Cancel', 'rrze-rsvp') . '</a>';
            echo '<a href="&create=true" class="button button-primary">' . __('Create seats', 'rrze-rsvp') . '</a>';
            echo '<p>';
            echo '</div>';
        }


        echo '<form id="rsvp-create-seats" method="get">';
        echo '<input type="hidden" name="page" value="rrze-rsvp">';
        echo '<input type="hidden" name="confirm" value="true">';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="room_id">' . __('Room','rrze-rsvp') . '</label></th>';
        echo '<td>'
            . '<select id="room_id" name="room_id">'
            . '<option value="">&mdash; ' . __('Please select', 'rrze-rsvp') . ' &mdash;</option>';
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
            . '<td><input type="radio" id="schema_rows_numeric" name="schema_rows" value="num"> <label for="schema_rows">' . __('Numeric (1 - 999)', 'rrze-rsvp') . '</label><br />'
            . '<input type="radio" id="schema_rows_a-z" name="schema_rows" value="a-z"> <label  for="schema_rows">' . __('A-Z', 'rrze-rsvp') . '</label></td>'
            . '</tr>';

        echo '<tr>'
            . '<th scope="row"><label for="number_seats">' . __('Number of Seats per Row', 'rrze-rsvp') . '</label></th>'
            . '<td><input type="number" id="number_seats" name="number_seats" min="1"></td>'
            . '</tr>';

        echo '<tr>'
             . '<th scope="row"><strong>' . __('Seats Numbering System', 'rrze-rsvp') . '</strong></th>'
             . '<td><input type="radio" id="schema_seats_numeric" name="schema_seats" value="num"> <label  for="schema_seats">' . __('Numeric (1 - 999)', 'rrze-rsvp') . '</label><br />'
             . '<input type="radio" id="schema_seats_a-z" name="schema_seats" value="a-z> <label  for="schema_seats">' . __('A-Z', 'rrze-rsvp') . '</label></td>'
             . '</tr>';

        echo '<tr><th scope="row"><strong>' . __('Equipment','rrze-rsvp') . '</strong></th>';
        $equipments = get_terms('rrze-rsvp-equipment');
        $equi_out = '';
        if (!empty($equipments)) {
            foreach ($equipments as $equipment) {
                $equi_out .= '<p><input type="checkbox" name="equipment[]" id="equipment_' . $equipment->term_id . '" value="' . $equipment->term_id . '">'
                    . '<label for="' . $equipment->term_id . '">' . $equipment->name . '</label></p>';
            }
        } else {
            $equi_out .= '<p>' . sprintf(__('No equipment found. You can add seat equipment on the %sEquipment screen%s.'), '<a href="'.admin_url('/edit-tags.php?taxonomy=rrze-rsvp-equipment">'), '</a>') . '</p>';
        }
        echo '<td>' . $equi_out;

        echo '<p class="description">' . __('Preselect equipment that will be available on all seats. You can modify the equipment later by editing the seats individually or by bulk editing several seats in the seats table.', 'rrze-rsvp') . '</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
        echo '<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="' . __('Generate Seats', 'rrze-rsvp') . '"></p>';

        echo '</form>';
        echo '</div>';
    }
}