# RSVP course module contract

All classes below use `RRZE\RSVP\Courses`. Root owns existing RSVP classes and bootstrap. Core is package A; administration, frontend and notifications consume this contract rather than writing status/meta directly.

## Storage and readers

`Model::COURSE_TYPE = rsvp_course`, `Model::DATE_TYPE = rsvp_course_date` (internal child post, `post_parent` is course ID), `Model::BOOKING_DATE_META = rrze-rsvp-booking-course-date`, `Model::BOOKING_COURSE_META = rrze-rsvp-booking-course`, `Model::COURSE_SETTINGS_META = rrze-rsvp-course-settings`, `Model::OCCURRENCE_META = rrze-rsvp-course-occurrence`, `Model::BOOKING_DATA_META = rrze-rsvp-course-booking-data`.

`new Repository()`:
- `getCourse(int): array|WP_Error`: id, title, description, post_status, settings {access: sso_or_email|sso, auto_confirm: bool, retention_days: int}, published_at (UTC).
- `getOccurrence(int): array|WP_Error`: id, course_id, start, end, room_id, location (display snapshot), capacity, registration_start (0 means course publication), registration_end (0 means start), state (open|cancelled), post_status. All times are true UTC epoch seconds.
- `getOccurrences(int $courseId, bool $futureOnly = false): array`: occurrence DTOs ordered by start, then ID.
- `getBooking(int): array|WP_Error`: private admin DTO, id, type=course, course_id, occurrence_id, status, start/end (UTC), course_title, location, room_id, guest_firstname/lastname/email, notes, created_at, expires_at, cancel_reason, settings snapshot, ics_uid, locale, post_status, booking_date_timestamp (alias of created_at). Never return this DTO from a public REST endpoint.
- `isCourseBooking(int): bool`, `hasBookings(int $postId): bool` for course/date deletion guards, `hasActiveRoomBookings(int $roomId): bool`.

Course participant names/email use existing encrypted booking metadata. The standard booking start/end metadata contains UTC only for course bookings: legacy readers MUST branch on course type, not apply legacy local-epoch conversion.

## Services

`new BookingService(?Repository $repository = null)`; every command returns `array|WP_Error` with codes `course_*` and error data containing HTTP `status`. `supportsTransactions(): bool` checks InnoDB for current-site posts and postmeta.

- `availability(int $occurrenceId): array|WP_Error`: occurrence_id, course_id, capacity, confirmed, pending, held (confirmed + pending), free, bookable, reason (available|full|closed|cancelled|unpublished|unsupported), start, end, location. Read-only; expired verification holds do not count even without cron.
- `saveCourseSettings(int $courseId, array $settings): array|WP_Error`: validates and saves only access, auto_confirm, retention_days. Requires edit_post(course).
- `saveOccurrence(int $courseId, array $data, int $occurrenceId=0): array|WP_Error`: full occurrence fields above, except state may only be set via cancelOccurrence. Requires edit_post(course). Start/end/location/room_id protected while active bookings exist, capacity cannot fall below held, range end>start, date times valid and registration end<=start. New default capacity20, open, registration bounds0. Referenced room must exist and be published; location is its title plus address, otherwise nonempty free text.
- `book(int $occurrenceId, array $participant, string $requestId, bool $trustedIdentity=false): array|WP_Error`: participant firstname,lastname,email,notes optional, locale optional, consent=true required. Trusted identity is set ONLY by server-side SSO adapter. Request IDs 16–128 ASCII alphanumeric/hyphen/underscore; persist client request ID for retry. Return public result {id,status,course_id,occurrence_id,expires_at,created:bool}. Replay returns same result without a new notification. Active email duplicate uses lowercased trimmed address. Guest starts booked, expires min(time()+3600,start). Trusted starts confirmed or customer-confirmed based on snapshotted auto_confirm. Required SSO rejects untrusted identity.
- `verify(int $bookingId,string $token): array|WP_Error`: purpose-scoped token; booked -> confirmed/customer-confirmed, repeated valid verification is a no-op; expiry cannot reacquire a seat.
- `approve(int $bookingId): array|WP_Error`: requires edit_post(booking); only customer-confirmed -> confirmed before start; no guest-email bypass.
- `cancel(int $bookingId,string $reason='admin',bool $manager=true,string $token=''): array|WP_Error`: managers require edit_post(booking) and may cancel before end; self-cancellation uses manager=false, reason=customer, cancel token and deadline start. Terminal status is idempotent. Allowed reasons admin,rejected,customer. Expiry/occurrence cancellation reasons are internal.
- `cancelOccurrence(int $occurrenceId): array|WP_Error`: requires edit_post(course); closes occurrence and cancels all active bookings under its lock; emits per-booking cancellation events after commit.
- `resend(int $bookingId): array|WP_Error`: requires edit_post(booking); refreshes required tokens and emits a resend event after commit. Rotates verify token only if still booked; keeps hold deadline unchanged. May resend terminal notification.
- `expireDue(): array|WP_Error`, `purgeExpired(): array|WP_Error`: system/cron methods; process under occurrence locks. Purge at current course retention_days after booking snapshot end. Exclude course bookings from all legacy cron queries.
- `canDeleteBooking(int): bool`: only after the configured retention deadline. Root may always hide manual deletion for course bookings.
- `deleteBooking(int): array|WP_Error`: manager capability delete_post; reject any booking still within its retention period. Cron purge uses private removal path.
- `deleteCourse(int,bool force=false)` and `deleteOccurrence(int,bool force=false)`: require delete_post(course), lock parent then child, refuse any retained booking, return {id,deleted:true}; moving a course to trash preserves dates, permanent deletion removes all empty child dates.
- `isWriting(): bool`: true only while this service is doing guarded core writes; root can let service writes pass metadata/delete protections.

A read-only public availability response must never contain attendee details or tokens. All administrative capability checks remain required at UI endpoints as well as service boundaries.

## Notifications and tokens

After commit only: `do_action('rrze_rsvp_course_booking_event', string $event, array $booking, array $tokens)`.
Events: `verification_required`, `approval_required`, `confirmed`, `cancelled`, `resend`. `tokens` contains `verify` only when needed, `cancel` for active bookings. Token hashes are stored; plaintext exists only in this callback. Event callbacks must not throw away a successful booking on a delivery failure. They own mail error metadata and rendering.

Booking `ics_uid` remains stable from creation through cancellation. `resend` receives current status so it can select the right mail. There are no new notifications for idempotent replays. Root must prevent old mail callbacks from running for course bookings.

## Post types / permissions

`PostTypes::register()` registers both types; course uses capability_type [rsvp_course,rsvp_courses], map_meta_cap=true, show_ui=true, show_in_menu=false, public=false, show_in_rest=false, supports title/editor/author. Date type has no direct UI or REST edit endpoint. Root wires registration, admin menu and adds all course primitive capabilities to administrator/editor/booking roles in an idempotent site-version upgrade.

## Operational integration and rollout

`Module::onLoaded()` registers the new modules from `Main`. Capability upgrades run per website, initialize newly created multisite websites, and repair a booking role recreated by deactivation/reactivation. Both course and booking capabilities are needed to manage registrations. Existing editor/booking-role restrictions on telephone data remain unchanged.

`Guards` protects normal metadata APIs, metadata-by-ID APIs, native post updates, trash, deletion and relationship changes. Legacy public room receipts and seat pages reject course IDs before reading participant details. `Functions::getBooking()` returns private course data only for users allowed to edit that booking. Public course endpoints use a separate explicit field allowlist.

Legacy booking date filters use canonical values even when labels use 12-hour time. Sorting the combined administration list normalizes UTC course times to the website's local display time without rewriting old room values.

Course pages and action pages bypass caching. E-mail links show a GET confirmation form; the service runs only on POST. Transport failures leave committed bookings intact and expose a resend action in administration. A pending browser request stores only a random request ID, occurrence ID and identity fingerprint in session storage. After an uncertain network response, the same attempt can be retried even if it reserved the last place.

### Creating and publishing a course

1. Open **RSVP → Kurse**, enter title/description and save a draft.
2. Add concrete dates with start/end, location and capacity. Each date has its own save button and stable ID. Times use the website timezone.
3. Choose SSO access or verified guest email, automatic or manual approval, and retention days. Save/publish the course.
4. Insert the **Kursbuchung / Course booking** block in a page and select the course. Each date is booked independently.
5. Manage registrations from the date's link or the course/date filters in **RSVP → Buchungen**. New registrations are created through the public form.

Publishing a course does not reserve its room. There is no automatic conversion of existing rooms/bookings, no waiting list, and no shared registration for several dates.

### Validation commands

Run from the plugin directory:

```sh
composer test
npm test
npm run build
msgfmt --check languages/rrze-rsvp-de_DE.po -o /tmp/rrze-rsvp-de_DE.mo
```

The following commands require a disposable local WordPress test website with InnoDB and an administrator with user ID 1. Use its own URL. They bootstrap this plugin explicitly to avoid unrelated plugin startup dependencies, intercept mail, and clean up their tagged fixtures. Legacy maintenance is restricted to test IDs.

```sh
wp --skip-plugins --skip-themes --url=SITE eval-file tests/course-core-integration.php
wp --skip-plugins --skip-themes --url=SITE eval-file tests/course-integration.php
wp --skip-plugins --skip-themes --url=SITE eval-file tests/course-notifications-integration.php
```

For a real two-process race, run `course-core-integration.php setup`, retain the returned course and occurrence IDs, then launch `worker OCCURRENCE_ID one` and `worker OCCURRENCE_ID two` concurrently using the same WP command prefix. The workers register the integration guards and deliberately overlap transactions. Expect one successful active booking and one `course_full` result. Finish with `cleanup COURSE_ID`.

The core integration suite injects real SQL errors on insert, SELECT and deletion, checks rollback/cache consistency, and covers expiry without cron, duplicate requests, status transitions, retained snapshots, protected dates, retention and venue changes. Administration tests also cover DST gaps and winter/summer UTC conversion. Frontend tests cover SSO spoofing, missing SSO, nonce/origin checks, public response privacy, and replay after a lost response/reload. Notification tests intercept the complete mail payload, verify calendar UID continuity, locale restoration and retry after delivery failure.

Live identity-provider login and real SMTP delivery require the deployment environment's credentials/transport. Local acceptance deliberately uses intercepted mail and validates the SSO adapter's trusted/untrusted identity boundaries without signing in to a real identity provider. No merge to `main` is part of this implementation.

Notifications serialize delivery per booking with a MySQL named lock after the booking transaction has committed. Fresh status and token hashes suppress outdated events; a cancellation concurrent with a confirmation either wins before delivery or follows the sent invitation with the same calendar UID. Same-process reentrant events queue until the outer delivery finishes. `tests/course-notifications-race.php` covers this interleaving and a real second-connection lock timeout; run it with the same isolated WP command prefix above.
