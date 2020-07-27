<?php

namespace RRZE\RSVP\Seats;

defined('ABSPATH') || exit;

use RRZE\RSVP\CPT;
use RRZE\RSVP\Settings;
use RRZE\RSVP\Functions;
use WP_Term_Query;

class EditSettings extends Settings
{
    protected $optionName = 'rrze_rsvp_seats';

    protected $wpPost;

    protected $wpTerms;

    public function __construct()
    {
        $this->settingsErrorTransient = 'rrze-rsvp-seats-settings-edit-error-';
        $this->noticeTransient = 'rrze-rsvp-seats-settings-edit-notice-';
    }

    public function onLoaded()
    {
        add_action('admin_init', [$this, 'validateOptions']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_notices', [$this, 'adminNotices']);
    }

    public function validateOptions()
    {
        $optionPage = Functions::requestVar('option_page');

        if ($optionPage == 'rrze-rsvp-seats-edit') {
            $this->validate();
        }
    }

    protected function validate()
    {
        $input = (array) Functions::requestVar($this->optionName);
        $nonce = Functions::requestVar('_wpnonce');

        if (!wp_verify_nonce($nonce, 'rrze-rsvp-seats-edit-options')) {
            wp_die(__('Something went wrong.', 'rrze-rsvp'));
        }

        $item = absint(Functions::requestVar('item'));
        $this->wpPost = get_post($item);
        if (is_null($this->wpPost)) {
            wp_die(__('Something went wrong.', 'rrze-rsvp'));
        }
        $this->wpTerms = wp_get_post_terms($this->wpPost->ID, CPT::getTaxonomyServiceName());
        if (is_wp_error($this->wpTerms)) {
            wp_die(__('Something went wrong.', 'rrze-rsvp'));
        }

        $number = isset($input['seat_number']) ? trim($input['seat_number']) : '';
        if (empty($number)) {
            $this->addSettingsError('seat_number', '', __('The number is required.', 'rrze-rsvp'));
        } else {
            $this->addSettingsError('seat_number', $number, '', false);
        }

        $equipmentNameNew = isset($input['equipment_name_new']) ? sanitize_text_field($input['equipment_name_new']) : '';
        $this->addSettingsError('equipment_name_new', $equipmentNameNew, '', false);

        $equipmentActives = (isset($input['equipment_active']) && is_array($input['equipment_active'])) ? array_keys($input['equipment_active']) : [];      
        $args = [
			'taxonomy' => CPT::getTaxonomySeatEquipmentName(),
			'hide_empty' => false
		];
		$query = new WP_Term_Query();
        $terms = $query->query($args);
        $allTermsIds = wp_list_pluck($terms, 'term_id');
        foreach ($allTermsIds as $termId) {
            if (! in_array($termId, $equipmentActives)) {
                wp_remove_object_terms($this->wpPost->ID, $termId, CPT::getTaxonomySeatEquipmentName());
            } else {
                wp_set_object_terms($this->wpPost->ID, $termId, CPT::getTaxonomySeatEquipmentName(), true);
            }
        }

        $service = isset($input['seat_service']) ? absint($input['seat_service']) : '';
        if (!get_term_by('id', $service, CPT::getTaxonomyServiceName())) {
            $this->addSettingsError('seat_service', '', __('The service is required.', 'rrze-rsvp'));
        } else {
            $this->addSettingsError('seat_service', $service, '', false);
        }

        if (!$this->settingsErrors()) {
            $post = wp_update_post(
                [
                    'ID' => $this->wpPost->ID,
                    'post_title' => $number,
                    'post_content' => '',
                    'tax_input' => [
                        'rrze_rsvp_service' => [$service]
                    ]
                ],
                true
            );

            if (is_wp_error($post)) {
                $this->addAdminNotice(__('The seat could not be updated.', 'rrze-rsvp'), 'error');
                wp_redirect(Functions::actionUrl(['page' => 'rrze-rsvp-seats', 'action' => 'edit', 'item' => $this->wpPost->ID]));
                exit();
            }

            wp_insert_term(
                $equipmentNameNew,
                CPT::getTaxonomySeatEquipmentName(),
                [
                    'description' => ''
                ]
            );
        }

        if ($this->settingsErrors()) {
            foreach ($this->settingsErrors() as $error) {
                if ($error['message']) {
                    $this->addAdminNotice($error['message'], 'error');
                }
            }
            wp_redirect(Functions::actionUrl(['page' => 'rrze-rsvp-seats', 'action' => 'edit', 'item' => $this->wpPost->ID]));
            exit();
        }

        $this->addAdminNotice(__('The seat has been updated.', 'rrze-rspv'));
        wp_redirect(Functions::actionUrl(['page' => 'rrze-rsvp-seats', 'action' => 'edit', 'item' => $this->wpPost->ID]));
        exit();
    }

    public function settings()
    {
        $item = absint(Functions::requestVar('item'));
        $this->wpPost = get_post($item);
        if (is_null($this->wpPost)) {
            return;
        }
        $this->wpTerms = wp_get_post_terms($this->wpPost->ID, CPT::getTaxonomyServiceName());
        if (is_wp_error($this->wpTerms)) {
            return;
        }

        add_settings_section(
            'rrze-rsvp-seats-edit-section',
            false,
            '__return_false',
            'rrze-rsvp-seats-edit'
        );

        add_settings_field(
            'seat_number',
            __('Number', 'rrze-rsvp'),
            [$this, 'numberField'],
            'rrze-rsvp-seats-edit',
            'rrze-rsvp-seats-edit-section'
        );

        add_settings_field(
            'seat_service',
            __('Service', 'rrze-rsvp'),
            [$this, 'serviceField'],
            'rrze-rsvp-seats-edit',
            'rrze-rsvp-seats-edit-section'
        );

        add_settings_field(
            'equipment',
            __('Equipment', 'rrze-rsvp'),
            [$this, 'equipmentField'],
            'rrze-rsvp-seats-edit',
            'rrze-rsvp-seats-edit-section'
        );        
    }

    public function numberField()
    {
        $settingsErrors = $this->settingsErrors();
        $number = isset($settingsErrors['seat_number']['value']) ? esc_html($settingsErrors['seat_number']['value']) : $this->wpPost->post_title;
        ?>
        <input type="text" value="<?php echo $number; ?>" name="<?php printf('%s[seat_number]', $this->optionName); ?>" class="regular-text">
        <?php
    }

    public function serviceField()
    {
        $dataService = $this->wpTerms[0];
        $services = Functions::getServices();
        $settingsErrors = $this->settingsErrors();
        $settingsService = isset($settingsErrors['seat_service']['value']) ? esc_textarea($settingsErrors['seat_service']['value']) : $dataService->term_id;
        ?>
        <?php if ($services) : ?>
            <select name="<?php printf('%s[seat_service]', $this->optionName); ?>">
                <option value="0"><?php _e('&mdash; Please select &mdash;', 'rrze-rsvp'); ?></option>
                <?php foreach ($services as $service) : ?>
                    <option value="<?php echo $service->term_id; ?>" <?php $settingsService ? selected($service->term_id, $settingsService) : ''; ?>><?php echo $service->name; ?></option>
                <?php endforeach; ?>
            </select>
        <?php else : ?>
            <p><?php _e('No services found.', 'rrze-rsvp'); ?></p>
        <?php endif; ?>
        <?php
    }

    function equipmentField()
    {
		$args = [
			'taxonomy' => CPT::getTaxonomySeatEquipmentName(),
			'hide_empty' => false
		];
		$query = new WP_Term_Query();
        $terms = $query->query($args);
        ?>
        <form action="<?php echo Functions::actionUrl(['page' => 'rrze-rsvp-seats', 'action' => 'edit', 'item' => $this->wpPost->ID]); ?>" method="post">
        <div class="rrze_rsvp_seat_equipment">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Name', 'rrze-rsvp'); ?></th>
                        <th><?php _e('Slug', 'rrze-rsvp'); ?></th>
                        <th><?php _e('Active', 'rrze-rsvp'); ?></th>
                        <th></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="rrze_rsvp_seat_equipment_items">
                    <?php
                    foreach ($terms as $term) {
                        $checked = (is_object_in_term($this->wpPost->ID, CPT::getTaxonomySeatEquipmentName(), $term->term_id) === true) ? true : false;
                        ?>
                        <tr>
                            <td>
                                <?php echo $term->name; ?>
                            </td>
                            <td>
                                <?php echo $term->slug; ?>
                            </td>                            
                            <td>
                                <input type="checkbox" name="<?php printf('%s[equipment_active][%s]', $this->optionName, $term->term_id); ?>" <?php checked($checked); ?>>
                            </td>
                            <td>
                                <button id="rrze_rsvp_seat_equipment_button_edit" class="button" type="button"><?php _e('Edit', 'rrze-rsvp'); ?></button>
                            </td>
                            <td>
                                <button id="rrze_rsvp_seat_equipment_button_delete" class="button" type="button"><?php _e('Delete', 'rrze-rsvp'); ?></button>
                            </td>                                                      
                        </tr>
                        <?php
                    }
                    ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>
                            <input type="text" value="" name="<?php printf('%s[equipment_name_new]', $this->optionName); ?>" class="regular-text">
                        </td>
                        <td></td>               
                        <td></td>
                        <td>
                            <button id="rrze_rsvp_equipment_add" class="button" type="submit"><?php _e('Add', 'rrze-rsvp'); ?></button>
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        </form>
        <?php
    }
}
