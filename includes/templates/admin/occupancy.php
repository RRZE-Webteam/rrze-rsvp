<?php
/**
 * Today's room occupancy. WordPress checks the menu capability before rendering.
 *
 * @var array<int,string> $rooms Published room titles indexed by post ID.
 * @var int $roomId Requested room or the first room in alphabetical order.
 */
use RRZE\RSVP\Functions;

defined('ABSPATH') || exit;
?>
<div class="wrap rsvp-occupancy-admin">
    <h1><?php echo esc_html_x('Room occupancy for today', 'admin page title', 'rrze-rsvp'); ?></h1>
    <hr class="wp-header-end">
    <?php if (!$rooms) : ?>
        <section class="rsvp-occupancy-card">
            <h2><?php esc_html_e('No published rooms yet', 'rrze-rsvp'); ?></h2>
            <p><?php esc_html_e('Publish a room with seats and time slots to see its occupancy here.', 'rrze-rsvp'); ?></p>
            <?php if (current_user_can('edit_rooms')) : ?>
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=room')); ?>"><?php esc_html_e('Manage rooms', 'rrze-rsvp'); ?></a>
            <?php endif; ?>
        </section>
    <?php else : ?>
        <p class="description"><?php esc_html_e('See how many seats are free or booked in each time slot. Open a time slot to see the status of individual seats.', 'rrze-rsvp'); ?></p>
        <?php if (count($rooms) > 1 || !isset($rooms[$roomId])) : ?>
            <form class="rsvp-occupancy-filter" method="get" action="<?php echo esc_url(admin_url('edit.php')); ?>">
                <input type="hidden" name="post_type" value="booking">
                <input type="hidden" name="page" value="occupancy">
                <label for="rsvp_room_id"><?php esc_html_e('Room', 'rrze-rsvp'); ?></label>
                <select id="rsvp_room_id" name="rsvp_room_id">
                    <?php foreach ($rooms as $id => $title) : ?>
                        <option value="<?php echo esc_attr($id); ?>" <?php selected($roomId, $id); ?>><?php echo esc_html($title); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button"><?php esc_html_e('Show occupancy', 'rrze-rsvp'); ?></button>
            </form>
        <?php endif; ?>
        <?php if (isset($rooms[$roomId])) : ?>
            <section class="rsvp-occupancy-card" aria-labelledby="rsvp-occupancy-room">
                <header class="rsvp-occupancy-heading">
                    <h2 id="rsvp-occupancy-room"><?php echo esc_html($rooms[$roomId]); ?></h2>
                    <?php /* translators: PHP date format for the weekday and full date in the room occupancy view. */ ?>
                    <time datetime="<?php echo esc_attr(current_time('Y-m-d')); ?>"><?php echo esc_html(wp_date(_x('l, F j, Y', 'room occupancy date format', 'rrze-rsvp'))); ?></time>
                </header>
                <?php echo Functions::getOccupancyByRoomIdHTMLAdmin($roomId); ?>
                <footer><?php echo Functions::getOccupancyLinks($roomId); ?></footer>
            </section>
        <?php else : ?>
            <p><?php esc_html_e('This room is no longer available. Select a published room to view its occupancy.', 'rrze-rsvp'); ?></p>
        <?php endif; ?>
    <?php endif; ?>
</div>
