# RSVP email templates

The shared responsive shell is adapted from RRZE Appointment and compiled with
Maizzle 6.0.13. WordPress only reads the generated HTML; Node.js is not required
on the web server. The framework and generated output are independent of an
installed Appointment plugin.

## Development

Use Node.js 26 (the tested development version) and install with `npm ci`.
The committed `package-lock.json` fixes transitive dependencies as well.

- `npm run dev:email`: local Maizzle preview of the shell, including runtime markers.
- `npm run build:email`: compile the layout and verify its markers and Outlook rules.
- `npm run build`: build frontend/admin assets, then email assets.
- `npm start`: watch frontend/admin assets without deleting `build/email/`.
- `composer test`: run PHP regressions, including email rendering tests.

Edit `mail/emails/layout.vue`, never the compiled `build/email/layout.html`.
Commit the generated HTML with source changes so plugin packages work without
development dependencies. The `___RRZE_EMAIL_*___` markers are replaced once by
`Mail\EmailLayout::wrap()` with escaped metadata and trusted content at send time.
The current WordPress locale determines language and direction, including course
notifications that temporarily switch to a booking's locale.

## Message content

`Mail\RoomMessage` renders existing room-mail settings and visibility flags.
Room mails retain the optional English section, but share one shell and footer.
`Courses\Notifications` supplies structured details alongside the original
translated plain-text sentences. The configured transport, recipients, action
URLs, delivery ordering and calendar attachments are unchanged.

No saved content is migrated. Website branding uses a custom logo with explicit,
proportional dimensions capped at 200 × 64 px, or the site name. Footer links are
limited to the configured privacy page and an existing RRZE Legal imprint endpoint
or published `impressum` page. Missing or incomplete compiled HTML uses the built-in
fallback layout rather than preventing delivery.

Status palettes include visible labels: warning for confirmation/approval pending,
success for confirmation/check-in, danger for cancellation, neutral for check-out
and other updates. Status is never inferred from user-configurable subject text.

## Outlook and verification

Keep layout spacing and colored surfaces on table cells. Runtime details and
buttons are generated **after** Maizzle runs and need their own inline styles.
The Outlook-only button selectors must stay in the CSS purge safelist. Structural
checks and browser previews do not establish rendering correctness in classic
Outlook for Windows; that requires a separate client rendering check.

The local integration tests intercept `wp_mail` before transmission. They create
tagged disposable fixtures and clean up in `finally`:

```sh
wp --skip-plugins --skip-themes --url=SITE eval-file --use-include tests/course-notifications-integration.php
wp --skip-plugins --skip-themes --url=SITE eval-file --use-include tests/course-notifications-race.php
```

Generate complete synthetic HTML previews without sending mail or creating
bookings with:

```sh
wp --skip-plugins --skip-themes --url=SITE eval-file tests/mail-previews.php /private/tmp/rsvp-mail-preview
```

Preview output is development-only and must not be shipped with the plugin.
