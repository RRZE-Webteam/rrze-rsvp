<?php
/**
 * Standalone public occupancy screen for a room or an embedded signage player.
 *
 * @var int $roomId Room post ID.
 * @var string $displayMode Validated occupancy display mode.
 * @var int $daysInAdvance Configured booking horizon.
 * @var object $options Site settings.
 * @var array $meta Room metadata.
 */
namespace RRZE\RSVP;

defined('ABSPATH') || exit;
nocache_headers();
if (!defined('DONOTCACHEPAGE')) {
    define('DONOTCACHEPAGE', true);
}
$now = current_datetime();
$protected = post_password_required($roomId);
$requestedPage = filter_input(INPUT_GET, 'display_page', FILTER_VALIDATE_INT);
$board = $protected ? [] : PublicDisplay::snapshot($roomId, $displayMode, $now, $daysInAdvance, (int) $requestedPage);
$page = $board['page'] ?? 1;
$pages = $board['pages'] ?? 1;
$next = $page % $pages + 1;
$previous = $page === 1 ? $pages : $page - 1;
$paused = ($_GET['display_pause'] ?? '') === '1';
$interval = filter_input(INPUT_GET, 'reload', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$interval = is_int($interval) ? $interval : ($pages > 1 ? 15 : 60);
$width = filter_input(INPUT_GET, 'width', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$height = filter_input(INPUT_GET, 'height', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$size = ($width ? '--display-width:' . $width . 'px;' : '') . ($height ? '--display-height:' . $height . 'px;' : '');
$styleFile = 'build/public-display.css';
$styleUrl = add_query_arg('ver', filemtime(plugin()->getPath() . $styleFile), plugins_url($styleFile, plugin()->getFile()));
$dateFormat = _x('l, F j, Y', 'room occupancy date format', 'rrze-rsvp');
$futureDay = $board && $board['date'] > $now->format('Y-m-d');
$roomName = get_the_title($roomId);
$consultation = get_post_meta($roomId, 'rrze-rsvp-room-bookingmode', true) === 'consultation';
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,follow">
    <title><?php echo esc_html($roomName . ' – ' . __('Room occupancy', 'rrze-rsvp')); ?></title>
    <link rel="stylesheet" href="<?php echo esc_url($styleUrl); ?>">
    <?php if (!$protected && !$paused && $interval > 0) : ?>
        <meta http-equiv="refresh" content="<?php echo esc_attr($interval); if ($pages > 1) { echo ';url=' . esc_url(add_query_arg('display_page', $next)); } ?>">
    <?php endif; ?>
</head>
<body>
<main class="display" style="<?php echo esc_attr($size); ?>">
    <header class="masthead">
        <div><p class="kicker"><?php esc_html_e('Room occupancy', 'rrze-rsvp'); ?></p><h1><?php echo esc_html($roomName); ?></h1></div>
        <p class="updated"><?php esc_html_e('Last updated', 'rrze-rsvp'); ?>
            <time datetime="<?php echo esc_attr($now->format(DATE_ATOM)); ?>"><?php echo esc_html($now->format('H:i')); ?></time>
        </p>
    </header>
    <div class="content">
        <?php if ($protected) : ?>
            <?php echo get_the_password_form($roomId); ?>
        <?php else : ?>
            <?php foreach ([$options->general_pdtxt ?? '', $meta['rrze-rsvp-room-pdtxt'][0] ?? ''] as $notice) :
                if (trim($notice) !== '') : ?>
                    <div class="notice"><?php echo wp_kses_post(wpautop($notice)); ?></div>
                <?php endif;
            endforeach; ?>
            <?php if ($board) :
                $timeRange = $board['start'] . ' – ' . $board['end']; ?>
                <section class="window" aria-labelledby="display-time">
                    <div class="when">
                        <?php if ($futureDay) :
                            $date = new \DateTimeImmutable($board['date'], $now->getTimezone());
                            $format = $date->format('Y') === $now->format('Y')
                                ? _x('l, F j', 'public display date without year', 'rrze-rsvp')
                                : $dateFormat;
                            ?>
                            <p class="date"><?php esc_html_e('No more seats available today.', 'rrze-rsvp'); ?></p>
                            <h2 class="next-date" id="display-time"><time datetime="<?php echo esc_attr($board['date']); ?>"><?php
                                /* translators: %s: first date with an available seat, including its weekday. */
                                echo esc_html(sprintf(__('From %s', 'rrze-rsvp'), wp_date($format, $date->getTimestamp())));
                            ?></time></h2>
                            <p class="slot-time"><?php
                                /* translators: %s: time range on the displayed date. */
                                echo esc_html(sprintf(__('Time slot: %s', 'rrze-rsvp'), $timeRange));
                            ?></p>
                        <?php else :
                            $clock = $now->format('H:i');
                            if ($board['end'] <= $clock) {
                                $context = __('Ended', 'rrze-rsvp');
                            } elseif ($board['start'] <= $clock) {
                                $context = __('In progress', 'rrze-rsvp');
                            } else {
                                $context = __('Later today', 'rrze-rsvp');
                            }
                            ?>
                            <p class="date"><strong><?php echo esc_html($context); ?></strong> <time datetime="<?php echo esc_attr($board['date']); ?>"><?php echo esc_html(wp_date($dateFormat, $now->getTimestamp())); ?></time></p>
                            <h2 id="display-time"><?php echo esc_html($timeRange); ?></h2>
                        <?php endif; ?>
                    </div>
                    <p class="capacity<?php echo $board['free'] ? '' : ' full'; ?>">
                        <?php if ($board['total'] === 1) : ?>
                            <strong><?php echo $board['free'] ? esc_html__('Free', 'rrze-rsvp') : esc_html__('Occupied', 'rrze-rsvp'); ?></strong>
                            <span><?php echo $consultation ? esc_html__('Consultation', 'rrze-rsvp') : esc_html__('Seat', 'rrze-rsvp'); ?></span>
                        <?php else : ?>
                            <strong><?php echo esc_html(number_format_i18n($board['free'])); ?></strong>
                            <span><?php /* translators: %s: total number of seats (at least two). */
                            echo esc_html(sprintf(__('of %s seats free', 'rrze-rsvp'), number_format_i18n($board['total']))); ?></span>
                        <?php endif; ?>
                    </p>
                </section>
                <?php if (!$consultation) : ?>
                    <section class="seats" aria-labelledby="display-seats">
                        <h2 id="display-seats"><?php echo esc_html_x('Seats', 'Post type general name', 'rrze-rsvp'); ?></h2>
                        <ul>
                            <?php foreach ($board['seats'] as $seat) : ?>
                                <li><strong><?php echo esc_html($seat['name']); ?></strong>
                                    <span class="status<?php echo $seat['free'] ? ' free' : ''; ?>"><?php echo $seat['free'] ? esc_html__('Free', 'rrze-rsvp') : esc_html__('Unavailable', 'rrze-rsvp'); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endif; ?>
            <?php else : ?>
                <section class="empty">
                    <p class="date"><?php echo esc_html(wp_date($dateFormat, $now->getTimestamp())); ?></p>
                    <h2><?php echo $displayMode === 'occupancy_nextavailable' ? esc_html__('No free seats', 'rrze-rsvp') : esc_html__('No time slots to display', 'rrze-rsvp'); ?></h2>
                    <?php if ($displayMode === 'occupancy_nextavailable' && $daysInAdvance > 0) : ?>
                        <p><?php /* translators: %s: last date in the room's booking period. */
                        echo esc_html(sprintf(__('There are no free seats through %s.', 'rrze-rsvp'), wp_date($dateFormat, $now->modify('+' . $daysInAdvance . ' days')->getTimestamp()))); ?></p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php if ($pages > 1) : ?>
        <nav class="pagination" aria-label="<?php esc_attr_e('Display pages', 'rrze-rsvp'); ?>">
            <span><?php /* translators: 1: current display page, 2: total display pages. */
            echo esc_html(sprintf(__('Page %1$s of %2$s', 'rrze-rsvp'), number_format_i18n($page), number_format_i18n($pages))); ?></span>
            <a href="<?php echo esc_url(add_query_arg('display_page', $previous)); ?>" aria-label="<?php esc_attr_e('Previous page'); ?>">←</a>
            <a href="<?php echo esc_url(add_query_arg('display_page', $next)); ?>" aria-label="<?php esc_attr_e('Next page'); ?>">→</a>
            <?php if ($interval > 0) : ?>
                <a href="<?php echo esc_url($paused ? remove_query_arg('display_pause') : add_query_arg('display_pause', 1)); ?>"><?php echo $paused ? esc_html__('Resume rotation', 'rrze-rsvp') : esc_html__('Pause rotation', 'rrze-rsvp'); ?></a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</main>
</body>
</html>
