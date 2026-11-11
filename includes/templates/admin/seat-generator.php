<?php
/** Configuration, preview and receipt for the seat generator. */
use RRZE\RSVP\SeatGenerator;

defined('ABSPATH') || exit;
?>
<div class="wrap rsvp-generator">
    <h1><?php esc_html_e('Create seats in bulk', 'rrze-rsvp'); ?></h1>
    <hr class="wp-header-end">
    <?php if ($receipt) : ?>
        <section class="rsvp-generator-panel">
            <h2><?php esc_html_e('Batch result', 'rrze-rsvp'); ?></h2>
            <p><strong><?php echo esc_html($this->rooms[$receipt['room']] ?? ''); ?></strong></p>
            <p><?php printf(esc_html__('Created: %1$d. Already present: %2$d.', 'rrze-rsvp'), $receipt['created'], $receipt['skipped']); ?></p>
            <?php if ($receipt['failed']) : ?>
                <p class="notice notice-error"><?php printf(esc_html__('Seats that could not be saved: %d. Review the batch again to retry.', 'rrze-rsvp'), $receipt['failed']); ?></p>
            <?php endif; ?>
            <?php if ($receipt['equipment_failed']) : ?>
                <p class="notice notice-warning"><?php printf(esc_html__('Seats created without the selected equipment: %d. Check their equipment in the seat list.', 'rrze-rsvp'), $receipt['equipment_failed']); ?></p>
            <?php endif; ?>
            <div class="rsvp-generator-actions">
                <a class="button button-primary" href="<?php echo esc_url(add_query_arg(['post_type' => 'seat', 'rrze-rsvp-seat-room' => $receipt['room']], admin_url('edit.php'))); ?>"><?php esc_html_e('View seats in this room', 'rrze-rsvp'); ?></a>
                <a href="<?php echo esc_url(self::url()); ?>"><?php esc_html_e('Create another batch', 'rrze-rsvp'); ?></a>
            </div>
        </section>
    <?php elseif (!$this->rooms) : ?>
        <section class="rsvp-generator-panel">
            <h2><?php esc_html_e('No rooms available', 'rrze-rsvp'); ?></h2>
            <p><?php esc_html_e('You need a published room that you can edit before you can add seats.', 'rrze-rsvp'); ?></p>
            <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=room')); ?>"><?php esc_html_e('Manage rooms', 'rrze-rsvp'); ?></a>
        </section>
    <?php else : ?>
        <p class="rsvp-generator-intro"><?php esc_html_e('Create numbered seats or devices for a room. You can check the names before publishing them.', 'rrze-rsvp'); ?></p>
        <ol class="rsvp-generator-steps">
            <li <?php if ($plan === null) echo 'aria-current="step"'; ?>><span aria-hidden="true">1</span><?php esc_html_e('Set up seats', 'rrze-rsvp'); ?></li>
            <li <?php if ($plan !== null) echo 'aria-current="step"'; ?>><span aria-hidden="true">2</span><?php esc_html_e('Review and create', 'rrze-rsvp'); ?></li>
        </ol>
        <?php if ($this->errors) : ?>
            <div class="notice notice-error inline" role="alert">
                <?php foreach ($this->errors as $error) : ?><p><?php echo esc_html($error); ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form id="rsvp-create-seats" method="post" action="<?php echo esc_url(self::url()); ?>">
            <?php wp_nonce_field('rsvp-seat-generator'); ?>
            <?php if ($plan === null) : ?>
                <section class="rsvp-generator-panel">
                    <h2><?php esc_html_e('Room and quantity', 'rrze-rsvp'); ?></h2>
                    <div class="rsvp-generator-field">
                        <label for="room_id"><?php esc_html_e('Room', 'rrze-rsvp'); ?></label>
                        <select id="room_id" name="room_id" required>
                            <option value=""><?php esc_html_e('Select a room', 'rrze-rsvp'); ?></option>
                            <?php foreach ($this->rooms as $id => $title) : ?>
                                <option value="<?php echo esc_attr($id); ?>" <?php selected($s['room_id'], $id); ?>><?php echo esc_html($title); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rsvp-generator-pair">
                        <div class="rsvp-generator-field">
                            <label for="number_rows"><?php esc_html_e('Number of Rows', 'rrze-rsvp'); ?></label>
                            <input type="number" id="number_rows" name="number_rows" value="<?php echo esc_attr($s['number_rows']); ?>" min="1" max="<?php echo SeatGenerator::LIMIT; ?>" required>
                        </div>
                        <div class="rsvp-generator-field">
                            <label for="number_seats"><?php esc_html_e('Number of Seats per Row', 'rrze-rsvp'); ?></label>
                            <input type="number" id="number_seats" name="number_seats" value="<?php echo esc_attr($s['number_seats']); ?>" min="1" max="<?php echo SeatGenerator::LIMIT; ?>" required>
                        </div>
                    </div>
                    <p class="description"><?php printf(esc_html__('For a simple list, use one row. You can create up to %d seats per batch.', 'rrze-rsvp'), SeatGenerator::LIMIT); ?></p>
                    <div class="rsvp-generator-field">
                        <label for="prefix"><?php esc_html_e('Shared name prefix (optional)', 'rrze-rsvp'); ?></label>
                        <input type="text" id="prefix" name="prefix" value="<?php echo esc_attr($s['prefix']); ?>" aria-describedby="prefix-help">
                        <p id="prefix-help" class="description"><?php esc_html_e('For example, enter “Microscope” for “Microscope 1”, “Microscope 2” and so on.', 'rrze-rsvp'); ?></p>
                    </div>
                    <details class="rsvp-generator-options" <?php if ($s['schema_rows'] !== 'num' || $s['schema_seats'] !== 'num' || $s['start_number'] !== '1' || $s['seat_start'] !== '1') echo 'open'; ?>>
                        <summary><?php esc_html_e('Adjust numbering', 'rrze-rsvp'); ?></summary>
                        <?php foreach (['seats' => 'seat_start', 'rows' => 'start_number'] as $axis => $start) : ?>
                            <div class="rsvp-generator-pair" data-axis="<?php echo esc_attr($axis); ?>">
                                <div class="rsvp-generator-field">
                                    <label for="schema_<?php echo esc_attr($axis); ?>"><?php echo $axis === 'rows' ? esc_html__('Rows Numbering System', 'rrze-rsvp') : esc_html__('Seats Numbering System', 'rrze-rsvp'); ?></label>
                                    <select id="schema_<?php echo esc_attr($axis); ?>" name="schema_<?php echo esc_attr($axis); ?>">
                                        <option value="num" <?php selected($s['schema_' . $axis], 'num'); ?>><?php esc_html_e('Numbers (1, 2, 3 …)', 'rrze-rsvp'); ?></option>
                                        <option value="a-z" <?php selected($s['schema_' . $axis], 'a-z'); ?>><?php esc_html_e('Letters (A, B, C …)', 'rrze-rsvp'); ?></option>
                                    </select>
                                </div>
                                <div class="rsvp-generator-field" data-start>
                                    <label for="<?php echo esc_attr($start); ?>"><?php echo $axis === 'rows' ? esc_html__('First row number', 'rrze-rsvp') : esc_html__('First seat number', 'rrze-rsvp'); ?></label>
                                    <input type="number" id="<?php echo esc_attr($start); ?>" name="<?php echo esc_attr($start); ?>" min="1" max="999999" value="<?php echo esc_attr($s[$start]); ?>">
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </details>
                </section>
                <details class="rsvp-generator-panel rsvp-generator-equipment" <?php if ($s['equipment']) echo 'open'; ?>>
                    <summary><?php esc_html_e('Shared equipment (optional)', 'rrze-rsvp'); ?></summary>
                    <?php if ($this->terms) : ?>
                        <fieldset><legend><?php esc_html_e('Select features that all new seats share.', 'rrze-rsvp'); ?></legend>
                            <?php foreach ($this->terms as $id => $label) : ?>
                                <label><input type="checkbox" name="equipment[]" value="<?php echo esc_attr($id); ?>" <?php checked(in_array($id, $s['equipment'], true)); ?>> <?php echo esc_html($label); ?></label>
                            <?php endforeach; ?>
                        </fieldset>
                    <?php else : ?>
                        <p><?php esc_html_e('No equipment features are available yet. You can also assign equipment after creating seats.', 'rrze-rsvp'); ?></p>
                    <?php endif; ?>
                    <?php if (current_user_can('edit_seats')) : ?>
                        <a href="<?php echo esc_url(admin_url('edit-tags.php?taxonomy=rrze-rsvp-equipment&post_type=seat')); ?>"><?php esc_html_e('Manage equipment', 'rrze-rsvp'); ?></a>
                    <?php endif; ?>
                </details>
                <button class="button button-primary" type="submit" name="step" value="preview"><?php esc_html_e('Preview seats', 'rrze-rsvp'); ?></button>
            <?php else : ?>
                <?php foreach ($s as $key => $value) : ?>
                    <?php foreach ((array) $value as $part) : ?>
                        <input type="hidden" name="<?php echo esc_attr($key . (is_array($value) ? '[]' : '')); ?>" value="<?php echo esc_attr($part); ?>">
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <input type="hidden" name="review" value="<?php echo esc_attr(wp_create_nonce(SeatGenerator::reviewAction($s, $plan))); ?>">
                <section class="rsvp-generator-panel">
                    <h2><?php echo esc_html($this->rooms[$s['room_id']]); ?></h2>
                    <p><?php echo $plan['new'] ? esc_html__('Check the names below. Existing seats and their equipment stay unchanged.', 'rrze-rsvp') : esc_html__('All of these seats already exist in this room. There is nothing to create.', 'rrze-rsvp'); ?></p>
                    <?php if ($s['equipment']) : ?>
                        <p><strong><?php esc_html_e('Equipment', 'rrze-rsvp'); ?>:</strong> <?php echo esc_html(implode(', ', array_intersect_key($this->terms, array_flip($s['equipment'])))); ?></p>
                    <?php endif; ?>
                    <?php foreach ($plan as $group => $names) : if (!$names) continue; ?>
                        <div class="rsvp-generator-group">
                            <h3><?php echo $group === 'new' ? esc_html__('Will be created', 'rrze-rsvp') : esc_html__('Already in this room', 'rrze-rsvp'); ?> <span><?php echo count($names); ?></span></h3>
                            <ul><?php foreach (array_slice($names, 0, 6) as $name) : ?><li><?php echo esc_html($name); ?></li><?php endforeach; ?></ul>
                            <?php if (count($names) > 6) : ?>
                                <details><summary><?php printf(esc_html__('Show remaining names (%d)', 'rrze-rsvp'), count($names) - 6); ?></summary>
                                    <ul><?php foreach (array_slice($names, 6) as $name) : ?><li><?php echo esc_html($name); ?></li><?php endforeach; ?></ul>
                                </details>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </section>
                <div class="rsvp-generator-actions">
                    <?php if ($plan['new']) : ?>
                        <button class="button button-primary" type="submit" name="step" value="create"><?php printf(esc_html(_n('Create %d seat', 'Create %d seats', count($plan['new']), 'rrze-rsvp')), count($plan['new'])); ?></button>
                    <?php endif; ?>
                    <button class="button" type="submit" name="step" value="edit"><?php esc_html_e('Change settings', 'rrze-rsvp'); ?></button>
                </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</div>
