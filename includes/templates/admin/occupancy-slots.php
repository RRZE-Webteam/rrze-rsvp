<?php
/**
 * Render the per-slot totals and the seats behind each total.
 *
 * @var array $matrix Seat IDs mapped to statuses keyed by the displayed interval.
 * @var string[] $slots Intervals in chronological order.
 * @var array<int,string> $names Naturally ordered seat names.
 * @var array<string,string> $labels Translated status labels.
 * @var bool $closed Whether today is a closing day.
 * @var int $room_id Room post ID.
 */
defined('ABSPATH') || exit;

if ($closed) : ?>
    <p class="rsvp-occupancy-empty"><?php esc_html_e('This room is closed today.', 'rrze-rsvp'); ?></p>
<?php endif;
if (!$names || !$slots) :
    if (!$closed) : ?>
        <p class="rsvp-occupancy-empty">
            <?php echo !$names
                ? esc_html__('This room has no published seats.', 'rrze-rsvp')
                : esc_html__('There are no time slots scheduled for today.', 'rrze-rsvp'); ?>
            <?php if (!$names && current_user_can('edit_seats')) : ?>
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=seat')); ?>"><?php esc_html_e('Manage seats', 'rrze-rsvp'); ?></a>
            <?php elseif ($names && get_edit_post_link($room_id)) : ?>
                <a href="<?php echo esc_url(get_edit_post_link($room_id)); ?>"><?php esc_html_e('Edit room schedule', 'rrze-rsvp'); ?></a>
            <?php endif; ?>
        </p>
    <?php endif;
    return;
endif;

$now = current_time('H:i');
foreach ($slots as $slot) :
    [$start, $end] = explode(' - ', $slot);
    $current = $start <= $now && $now < $end;
    $counts = array_count_values(array_column($matrix, $slot));
    $expanded = count($names) <= 8 && ($current || count($slots) === 1);
    ?>
    <details class="rsvp-occupancy-slot"<?php echo $expanded ? ' open' : ''; ?>>
        <summary>
            <span class="rsvp-occupancy-time">
                <strong><?php echo esc_html($start . ' – ' . $end); ?></strong>
                <?php if ($current) : ?>
                    <small><?php esc_html_e('In progress', 'rrze-rsvp'); ?></small>
                <?php elseif ($end <= $now) : ?>
                    <small><?php esc_html_e('Ended', 'rrze-rsvp'); ?></small>
                <?php endif; ?>
            </span>
            <span class="rsvp-occupancy-counts">
                <?php foreach ($labels as $status => $label) :
                    $count = $counts[$status] ?? 0;
                    if (!$count) {
                        continue;
                    }
                    // Each translation includes the number so languages can choose its position.
                    $format = match ($status) {
                        'available' => _n('%s seat free', '%s seats free', $count, 'rrze-rsvp'),
                        'confirmed' => __('%s reserved', 'rrze-rsvp'),
                        'checked-in' => __('%s checked in', 'rrze-rsvp'),
                        'checked-out' => __('%s checked out', 'rrze-rsvp'),
                        'pending' => __('%s awaiting confirmation', 'rrze-rsvp'),
                        'unscheduled' => __('%s not scheduled', 'rrze-rsvp'),
                    }; ?>
                    <span class="rsvp-occupancy-status is-<?php echo esc_attr($status); ?>"><?php echo esc_html(sprintf($format, number_format_i18n($count))); ?></span>
                <?php endforeach; ?>
            </span>
        </summary>
        <ul class="rsvp-occupancy-seats">
            <?php foreach ($names as $id => $name) :
                $status = $matrix[$id][$slot]; ?>
                <li><span><?php echo esc_html($name); ?></span> <span class="rsvp-occupancy-status is-<?php echo esc_attr($status); ?>"><?php echo esc_html($labels[$status]); ?></span></li>
            <?php endforeach; ?>
        </ul>
    </details>
<?php endforeach; ?>
