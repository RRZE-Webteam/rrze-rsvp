<?php

namespace RRZE\RSVP\Courses;

use RRZE\RSVP\Email;
use RRZE\RSVP\Mail\EmailLayout;
use RRZE\RSVP\Settings;
use Throwable;
use function RRZE\RSVP\plugin;

defined('ABSPATH') || exit;

/** Post-commit course messages; delivery failures never undo an accepted booking. */
final class Notifications
{
    public const ERROR_META = 'rrze-rsvp-course-mail-error';
    private const ADMIN_META = 'rrze-rsvp-course-mail-admin-sent';
    private const CALENDAR_META = 'rrze-rsvp-course-mail-calendar-sent';

    private Email $mailer;
    private array $options;
    private static array $inFlight = [];
    private static array $deferred = [];

    /**
     * @param Email|null $mailer Existing configured RSVP transport, replaceable in tests.
     * @param array|null $options Existing RSVP notification preferences.
     */
    public function __construct(?Email $mailer = null, ?array $options = null)
    {
        $this->mailer = $mailer ?? new Email();
        $this->options = $options ?? (new Settings(plugin()->getFile()))->getOptions();
    }

    /** @return void Subscribe only to events emitted after a successful course commit. */
    public function onLoaded(): void
    {
        add_action('rrze_rsvp_course_booking_event', [$this, 'notify'], 10, 3);
    }

    /**
     * Deliver the current participant message and at most one administrator event notice.
     *
     * @param string $event Committed domain event or an explicitly requested resend.
     * @param array $booking Private immutable booking snapshot plus current status.
     * @param array $tokens Plaintext purpose-bound tokens, available only during this call.
     * @return void
     */
    public function notify(string $event, array $booking, array $tokens): void
    {
        global $wpdb;
        if (!in_array($event, ['verification_required', 'approval_required', 'confirmed', 'cancelled', 'resend'], true)) {
            return;
        }
        $bookingId = (int) ($booking['id'] ?? 0);
        if ($bookingId < 1) {
            return;
        }
        $lock = $this->lockName($bookingId);
        if (isset(self::$inFlight[$lock])) {
            // MySQL named locks are reentrant on one connection. A mail filter may
            // itself cancel a booking, so defer that event until delivery finishes.
            self::$deferred[$lock][] = [$this, $event, $booking, $tokens];
            return;
        }
        $locked = false;
        $switched = false;
        try {
            $locked = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)) === '1';
            if (!$locked) {
                update_post_meta($bookingId, self::ERROR_META, __('The course notification is busy. Use Resend notification to try again.', 'rrze-rsvp'));
                return;
            }
            self::$inFlight[$lock] = true;
            wp_cache_delete($bookingId, 'post_meta');
            $repository = new Repository();
            $repository->setStrictReads(true);
            $current = $this->currentBooking($event, $booking, $tokens, $repository);
            if ($current === null) {
                return;
            }
            $booking = $current;
            $switched = switch_to_locale((string) ($booking['locale'] ?? get_locale()));
            $this->deliverCurrent($event, $booking, $tokens, $repository);
        } catch (Throwable $exception) {
            // Never expose transport diagnostics, addresses or tokens in public responses/logs.
            update_post_meta($bookingId, self::ERROR_META, __('The course notification could not be prepared. Use Resend notification to try again.', 'rrze-rsvp'));
        } finally {
            if ($switched) {
                restore_previous_locale();
            }
            if ($locked) {
                unset(self::$inFlight[$lock]);
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
                $pending = self::$deferred[$lock] ?? [];
                unset(self::$deferred[$lock]);
                foreach ($pending as [$notifier, $pendingEvent, $pendingBooking, $pendingTokens]) {
                    $notifier->notify($pendingEvent, $pendingBooking, $pendingTokens);
                }
            }
        }
    }

    /**
     * Scope the advisory lock to one database, website and booking within MySQL's limit.
     *
     * @param int $bookingId Course booking ID.
     * @return string Stable advisory-lock name shorter than 64 characters.
     */
    private function lockName(int $bookingId): string
    {
        global $wpdb;
        return 'rsvp-course-mail:' . substr(hash('sha256', DB_NAME . ':' . $wpdb->posts . ':' . $bookingId), 0, 40);
    }

    /**
     * Reject stale events and rotated tokens using fresh reads after the delivery lock.
     *
     * The core may have fetched a newer DTO for an older event before invoking hooks,
     * so both the event name and its token version must agree with current storage.
     *
     * @param string $event Committed event name.
     * @param array $original DTO originally supplied with the event.
     * @param array $tokens Ephemeral tokens originally supplied with the event.
     * @param Repository $repository Strict, uncached database reader.
     * @return array|null Current DTO, or null when the event was superseded or deleted.
     */
    private function currentBooking(string $event, array $original, array $tokens, Repository $repository): ?array
    {
        $current = $repository->getBooking((int) $original['id']);
        if (is_wp_error($current) || $current['post_status'] !== 'publish' || $current['status'] !== ($original['status'] ?? '')) {
            return null;
        }
        $expected = ['verification_required' => 'booked', 'approval_required' => 'customer-confirmed', 'confirmed' => 'confirmed', 'cancelled' => 'cancelled'];
        $status = $current['status'];
        if (($event !== 'resend' && ($expected[$event] ?? '') !== $status)
            || !in_array($status, ['booked', 'customer-confirmed', 'confirmed', 'cancelled'], true)) {
            return null;
        }
        if ($status === 'cancelled') {
            // A resend of an active booking always carries a cancel token. Its
            // stale DTO must not turn it into an extra cancellation notification.
            return $tokens === [] ? $current : null;
        }
        $data = $repository->meta($current['id'], Model::BOOKING_DATA_META, []);
        $required = $status === 'booked' ? ['verify', 'cancel'] : ['cancel'];
        foreach ($required as $purpose) {
            if (!is_string($tokens[$purpose] ?? null) || !is_string($data[$purpose . '_token_hash'] ?? null)
                || !hash_equals($data[$purpose . '_token_hash'], hash('sha256', $tokens[$purpose]))) {
                return null;
            }
        }
        if ($status === 'booked' && $current['expires_at'] <= time()) {
            return null;
        }
        return $current;
    }

    /**
     * Deliver while holding only the advisory mail lock, never an InnoDB transaction.
     *
     * @param string $event Current event name.
     * @param array $booking Fresh private booking snapshot.
     * @param array $tokens Current purpose-bound tokens.
     * @param Repository $repository Uncached database reader for delivery markers.
     * @return void
     */
    private function deliverCurrent(string $event, array $booking, array $tokens, Repository $repository): void
    {
        $errors = [];
        $status = $booking['status'];
        [$subject, $paragraphs, $links, $details] = $this->participantMessage($booking, $tokens);
        $calendarSent = $repository->meta($booking['id'], self::CALENDAR_META);
        $organizer = (string) ($this->options['email_sender_email'] ?? '');
        $booking['calendar_organizer'] = is_array($calendarSent) ? $calendarSent['organizer']
            : (is_email($organizer) ? $organizer : (string) get_option('admin_email'));
        $calendar = $status === 'confirmed' || ($status === 'cancelled' && $calendarSent);
        if (!$this->deliver((string) $booking['guest_email'], $subject, $paragraphs, $links, $details, $status, $calendar ? $booking : null)) {
            $errors[] = __('The participant email could not be sent. Use Resend notification to try again.', 'rrze-rsvp');
        } elseif ($status === 'confirmed') {
            update_post_meta($booking['id'], self::CALENDAR_META, ['organizer' => $booking['calendar_organizer']]);
        }

        $adminEvent = $status === 'cancelled' ? 'cancelled' : (in_array($status, ['customer-confirmed', 'confirmed'], true) ? 'new' : '');
        $sent = (array) $repository->meta($booking['id'], self::ADMIN_META);
        $enabled = $this->options['email_notification_if_' . ($adminEvent === 'cancelled' ? 'cancel' : 'new')] ?? 'no';
        $recipient = (string) ($this->options['email_notification_email'] ?? '');
        if ($adminEvent !== '' && $enabled === 'yes' && $recipient !== '' && empty($sent[$adminEvent])) {
            [$adminSubject, $adminParagraphs, $adminLinks, $adminDetails] = $this->administratorMessage($booking);
            if ($this->deliver($recipient, $adminSubject, $adminParagraphs, $adminLinks, $adminDetails, $status)) {
                $sent[$adminEvent] = true;
                update_post_meta($booking['id'], self::ADMIN_META, $sent);
            } else {
                $errors[] = __('The organizer notification could not be sent. Use Resend notification to try again.', 'rrze-rsvp');
            }
        }
        // A concurrent writer may commit while the transport runs. Its notification
        // will wait for this lock; do not erase a newer event's timeout/error marker.
        if ($this->currentBooking($event, $booking, $tokens, $repository) === null) {
            return;
        }
        if ($errors) {
            update_post_meta($booking['id'], self::ERROR_META, implode(' ', $errors));
        } else {
            delete_post_meta($booking['id'], self::ERROR_META);
        }
    }

    /**
     * Choose a message from current status, including resends after a previous failure.
     *
     * @param array $booking Private course booking.
     * @param array $tokens Ephemeral action tokens.
     * @return array Subject, paragraphs, labelled links and structured details.
     */
    private function participantMessage(array $booking, array $tokens): array
    {
        $status = $booking['status'];
        $links = [];
        if ($status === 'booked') {
            $subject = sprintf(__('[RSVP] Confirm your email address: %s', 'rrze-rsvp'), $booking['course_title']);
            $intro = sprintf(__('Please confirm your email address by %s. Your place is reserved until that time; after the deadline you must register again.', 'rrze-rsvp'), $this->formatTime((int) $booking['expires_at']));
            if (empty($tokens['verify'])) {
                throw new \RuntimeException('Missing verification token.');
            }
            $links[] = [__('Confirm email address', 'rrze-rsvp'), ActionPage::url('verify', $booking['id'], $tokens['verify'])];
        } elseif ($status === 'customer-confirmed') {
            $subject = sprintf(__('[RSVP] Awaiting approval: %s', 'rrze-rsvp'), $booking['course_title']);
            $intro = __('Your email address has been verified. Your place is reserved while the organizers review your registration. You will receive another email when they confirm your participation.', 'rrze-rsvp');
        } elseif ($status === 'confirmed') {
            $subject = sprintf(__('[RSVP] Participation confirmed: %s', 'rrze-rsvp'), $booking['course_title']);
            $intro = __('Your participation is confirmed. The attached calendar file contains your course date.', 'rrze-rsvp');
        } else {
            $subject = sprintf(__('[RSVP] Course booking cancelled: %s', 'rrze-rsvp'), $booking['course_title']);
            $intro = $this->cancellationReason((string) ($booking['cancel_reason'] ?? ''));
        }
        if ($status !== 'cancelled') {
            if (empty($tokens['cancel'])) {
                throw new \RuntimeException('Missing cancellation token.');
            }
            $links[] = [__('Cancel my booking', 'rrze-rsvp'), ActionPage::url('cancel', $booking['id'], $tokens['cancel'])];
        }
        $paragraphs = [$intro];
        if ($status !== 'cancelled') {
            $paragraphs[] = __('You may cancel your registration until the course begins.', 'rrze-rsvp');
        }
        return [$subject, $paragraphs, $links, $this->details($booking)];
    }

    /**
     * Create an organizer notice with a capability-protected admin link, never a guest token.
     *
     * @param array $booking Private booking.
     * @return array Subject, paragraphs, labelled links and structured details.
     */
    private function administratorMessage(array $booking): array
    {
        $cancelled = $booking['status'] === 'cancelled';
        $subject = sprintf($cancelled ? __('[RSVP] Registration cancelled: %s', 'rrze-rsvp') : __('[RSVP] New course registration: %s', 'rrze-rsvp'), $booking['course_title']);
        $intro = $cancelled ? $this->cancellationReason((string) ($booking['cancel_reason'] ?? ''))
            : ($booking['status'] === 'customer-confirmed' ? __('A verified course registration is awaiting your approval.', 'rrze-rsvp') : __('A course registration has been confirmed.', 'rrze-rsvp'));
        $details = array_merge($this->details($booking), [
            $this->detail(__('Participant', 'rrze-rsvp'), trim($booking['guest_firstname'] . ' ' . $booking['guest_lastname']), __('Participant: %s', 'rrze-rsvp')),
            $this->detail(__('Email address', 'rrze-rsvp'), $booking['guest_email'], __('Email address: %s', 'rrze-rsvp')),
        ]);
        return [$subject, [$intro], [[__('Manage booking', 'rrze-rsvp'), admin_url('post.php?post=' . (int) $booking['id'] . '&action=edit')]], $details];
    }

    /**
     * @param array $booking Private booking snapshot.
     * @return array Structured snapshot details with the existing translated plain-text sentences.
     */
    private function details(array $booking): array
    {
        return [
            $this->detail(__('Course', 'rrze-rsvp'), $booking['course_title'], __('Course: %s', 'rrze-rsvp')),
            $this->detail(__('Starts', 'rrze-rsvp'), $this->formatTime((int) $booking['start']), __('Starts: %s', 'rrze-rsvp')),
            $this->detail(__('Ends', 'rrze-rsvp'), $this->formatTime((int) $booking['end']), __('Ends: %s', 'rrze-rsvp')),
            $this->detail(__('Location', 'rrze-rsvp'), $booking['location'], __('Location: %s', 'rrze-rsvp')),
        ];
    }

    /**
     * Keep HTML labels and the original plain-text translations independent.
     * @param string $label Translated table heading.
     * @param string $value Unescaped snapshot value.
     * @param string $format Existing translated sentence containing one %s placeholder.
     * @return array{label: string, value: string, plain: string} One detail for both formats.
     */
    private function detail(string $label, string $value, string $format): array
    {
        return ['label' => $label, 'value' => $value, 'plain' => sprintf($format, $value)];
    }

    /**
     * @param int $timestamp True UTC epoch seconds.
     * @return string Localized display time with an explicit timezone abbreviation.
     */
    private function formatTime(int $timestamp): string
    {
        return wp_date(get_option('date_format') . ' ' . get_option('time_format') . ' T', $timestamp, wp_timezone());
    }

    /**
     * @param string $reason Service-owned cancellation reason.
     * @return string Translated explanation without administrative diagnostics.
     */
    private function cancellationReason(string $reason): string
    {
        switch ($reason) {
            case 'expired':
                return __('Your reservation expired because your email address was not confirmed in time. You can register again if places are still available.', 'rrze-rsvp');
            case 'rejected':
                return __('The organizers could not approve your course registration. Your reserved place has been released.', 'rrze-rsvp');
            case 'occurrence_cancelled':
                return __('The organizers have cancelled this course date. Your registration has therefore been cancelled.', 'rrze-rsvp');
            case 'customer':
                return __('Your course registration has been cancelled at your request.', 'rrze-rsvp');
            default:
                return __('Your course registration has been cancelled by the organizers.', 'rrze-rsvp');
        }
    }

    /**
     * Send through RSVP's configured transport and always remove temporary calendar files.
     *
     * @param string $recipient Recipient accepted by the existing RSVP mail settings.
     * @param string $subject Translated subject containing the course title.
     * @param array $paragraphs Plain-text paragraphs.
     * @param array $links Label/URL tuples.
     * @param array $details Structured detail rows with their original plain-text sentences.
     * @param string $status Current booking state for the visual status label.
     * @param array|null $calendarBooking Optional booking to attach as a calendar event.
     * @return bool Whether WordPress accepted the complete message for delivery.
     */
    private function deliver(string $recipient, string $subject, array $paragraphs, array $links, array $details, string $status, ?array $calendarBooking = null): bool
    {
        $attachment = '';
        try {
            $body = '';
            $plainParts = [];
            $rows = [];
            foreach ($details as $detail) {
                $rows[$detail['label']] = nl2br(esc_html($detail['value']));
            }
            foreach ($paragraphs as $index => $paragraph) {
                $body .= EmailLayout::paragraph($paragraph);
                $plainParts[] = $paragraph;
                if ($index === 0) {
                    $body .= EmailLayout::detailsTable($rows);
                    $plainParts = array_merge($plainParts, array_column($details, 'plain'));
                }
            }
            $plain = implode("\n\n", $plainParts);
            foreach ($links as [$label, $url]) {
                $body .= EmailLayout::actionButton($url, $label);
                $plain .= "\n\n" . $label . ': ' . $url;
            }
            $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            [$palette, $statusLabel] = EmailLayout::bookingStatus($status);
            $body = EmailLayout::wrap($body, $subject, $palette, $statusLabel);
            if ($calendarBooking !== null) {
                $temporary = wp_tempnam('rsvp-course.ics');
                if (!$temporary) {
                    return false;
                }
                $attachment = $temporary . '.ics';
                if (!rename($temporary, $attachment)) {
                    unlink($temporary);
                    return false;
                }
                $calendar = Calendar::generate($calendarBooking, $calendarBooking['status'] === 'cancelled');
                if (file_put_contents($attachment, $calendar) !== strlen($calendar)) {
                    return false;
                }
            }
            // Titles may contain line breaks; they are valid body text but never email headers.
            $subject = str_replace(["\r", "\n"], ' ', $subject);
            return $this->mailer->send($recipient, $subject, $body, $plain, $attachment);
        } catch (Throwable $exception) {
            return false;
        } finally {
            if ($attachment !== '' && is_file($attachment)) {
                unlink($attachment);
            }
        }
    }
}
