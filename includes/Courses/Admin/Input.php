<?php

namespace RRZE\RSVP\Courses\Admin;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;

defined('ABSPATH') || exit;

/** Converts editor values to the service's UTC and integer contract. */
final class Input
{
    /**
     * Parse a local datetime without accepting date rollover or nonexistent DST times.
     *
     * @param string $value Native datetime-local input value.
     * @param DateTimeZone $timezone Website timezone.
     * @param bool $optional Whether an empty value means the service default.
     * @return int|WP_Error UTC epoch seconds or a field validation error.
     */
    public static function timestamp(string $value, DateTimeZone $timezone, bool $optional = false)
    {
        if ($optional && $value === '') {
            return 0;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))
            || $date->format('Y-m-d\TH:i') !== $value) {
            return new WP_Error('course_invalid_local_time', __('Enter a valid date and time in the website timezone.', 'rrze-rsvp'), ['status' => 422]);
        }
        return $date->getTimestamp();
    }

    /**
     * Validate editor input before passing a complete occurrence to the service.
     *
     * @param array $input Unslashed submitted fields.
     * @param DateTimeZone $timezone Website timezone.
     * @return array|WP_Error Service values or the first field-specific error.
     */
    public static function occurrence(array $input, DateTimeZone $timezone)
    {
        $values = [];
        foreach (['start', 'end', 'registration_start', 'registration_end'] as $field) {
            $value = is_scalar($input[$field] ?? '') ? (string) ($input[$field] ?? '') : '';
            $parsed = self::timestamp($value, $timezone, str_starts_with($field, 'registration_'));
            if (is_wp_error($parsed)) {
                $parsed->add_data(['status' => 422, 'field' => $field]);
                return $parsed;
            }
            $values[$field] = $parsed;
        }
        foreach (['capacity', 'room_id'] as $field) {
            $number = filter_var($input[$field] ?? ($field === 'capacity' ? 20 : 0), FILTER_VALIDATE_INT);
            if ($number === false || $number < ($field === 'capacity' ? 1 : 0)) {
                return new WP_Error('course_invalid_number', __('Enter a valid participant limit and location.', 'rrze-rsvp'), ['status' => 422, 'field' => $field]);
            }
            $values[$field] = $number;
        }
        $values['location'] = sanitize_text_field(is_scalar($input['location'] ?? '') ? (string) ($input['location'] ?? '') : '');
        return $values;
    }
}
