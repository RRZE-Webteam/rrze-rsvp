<?php

namespace RRZE\RSVP\Mail;

defined('ABSPATH') || exit;

/**
 * Renders the shared HTML email layout and reusable email components.
 */
final class EmailLayout
{
    private const LAYOUT_PATH = 'build/email/layout.html';
    private const REQUIRED_LAYOUT_MARKERS = [
        '___RRZE_EMAIL_LANG___',
        '___RRZE_EMAIL_DIR___',
        '___RRZE_EMAIL_SUBJECT___',
        '___RRZE_EMAIL_SITE_URL___',
        '___RRZE_EMAIL_SITE_NAME___',
        '___RRZE_EMAIL_LOGO___',
        '___RRZE_EMAIL_CONTENT___',
        '___RRZE_EMAIL_FOOTER_LINKS___',
        '___RRZE_EMAIL_STATUS_ACCENT___',
        '___RRZE_EMAIL_STATUS_SURFACE___',
        '___RRZE_EMAIL_STATUS_TEXT___',
        '___RRZE_EMAIL_STATUS_LABEL___',
    ];

    public const STATUS_NEUTRAL = 'neutral';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_WARNING = 'warning';
    public const STATUS_DANGER = 'danger';

    /**
     * Map domain states to visible, translated labels without inspecting mail subjects.
     * @param string $status Current booking state or the explicit mail action.
     * @return array{0: string, 1: string} Palette and human-readable status.
     */
    public static function bookingStatus(string $status): array
    {
        return match ($status) {
            'booked' => [self::STATUS_WARNING, __('Confirmation required', 'rrze-rsvp')],
            'customer-confirmed' => [self::STATUS_WARNING, __('Awaiting approval', 'rrze-rsvp')],
            'confirmed' => [self::STATUS_SUCCESS, __('Booking confirmed', 'rrze-rsvp')],
            'checked-in' => [self::STATUS_SUCCESS, __('Checked in', 'rrze-rsvp')],
            'cancelled' => [self::STATUS_DANGER, __('Booking cancelled', 'rrze-rsvp')],
            'checked-out' => [self::STATUS_NEUTRAL, __('Checked out', 'rrze-rsvp')],
            default => [self::STATUS_NEUTRAL, __('Booking update', 'rrze-rsvp')],
        };
    }

    /**
     * Format an ordinary text paragraph without interpreting participant input as HTML.
     * @param string $text Plain, translated message text.
     * @return string HTML paragraph with explicit typography and preserved newlines.
     */
    public static function paragraph(string $text): string
    {
        return '<p style="margin:0 0 16px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;overflow-wrap:anywhere;">'
            . nl2br(esc_html($text)) . '</p>';
    }

    /**
     * Convert configured rich text to a readable alternative without leaking HTML entities.
     * @param string $html Rich text with dynamic values already escaped for HTML.
     * @return string Plain-text message preserving paragraph and line boundaries.
     */
    public static function plainText(string $html): string
    {
        $html = preg_replace_callback('~<a\b[^>]*\bhref\s*=\s*([\'"])(.*?)\1[^>]*>(.*?)</a>~is',
            /**
             * @param array $match Configured HTML link.
             * @return string Label plus a readable safe URL.
             */
            static function (array $match): string {
                $url = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $label = html_entity_decode(wp_strip_all_tags($match[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $safe = esc_url($url);
                return $match[3] . ($safe !== '' && $label !== $url ? ' (' . $safe . ')' : '');
            }, $html);
        $html = preg_replace('~<br\s*/?>|</(?:p|div|li|tr|h[1-6])>~i', "\n", $html);
        return trim(html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Wraps trusted template HTML in the shared email layout.
     *
     * The content is deliberately not escaped because it is produced from the
     * plugin's email templates. User-provided values must be escaped before
     * they are inserted into that HTML.
     *
     * @param string $content Trusted, escaped message fragment.
     * @param string $subject Plain-text subject.
     * @param string $status Semantic palette name.
     * @param string $statusLabel Context-specific translated status, or the palette default.
     * @return string Complete HTML message in the active locale.
     */
    public static function wrap(#[\SensitiveParameter] string $content, #[\SensitiveParameter] string $subject = '', string $status = self::STATUS_NEUTRAL, string $statusLabel = ''): string
    {
        $siteName = (string) get_bloginfo('name');
        $siteUrl = (string) home_url('/');
        $language = str_replace('_', '-', (string) get_locale()) ?: 'de';
        $statusStyle = self::getStatusStyle($status);

        return strtr(self::getLayout(), [
            '___RRZE_EMAIL_LANG___' => esc_attr($language),
            '___RRZE_EMAIL_DIR___' => is_rtl() ? 'rtl' : 'ltr',
            '___RRZE_EMAIL_SUBJECT___' => esc_html($subject),
            '___RRZE_EMAIL_SITE_URL___' => esc_url($siteUrl),
            '___RRZE_EMAIL_SITE_NAME___' => esc_html($siteName),
            '___RRZE_EMAIL_LOGO___' => self::getLogoHtml($siteName),
            '___RRZE_EMAIL_CONTENT___' => $content,
            '___RRZE_EMAIL_FOOTER_LINKS___' => self::getFooterLinksHtml(),
            '___RRZE_EMAIL_STATUS_ACCENT___' => esc_attr($statusStyle['accent']),
            '___RRZE_EMAIL_STATUS_SURFACE___' => esc_attr($statusStyle['surface']),
            '___RRZE_EMAIL_STATUS_TEXT___' => esc_attr($statusStyle['text']),
            '___RRZE_EMAIL_STATUS_LABEL___' => esc_html($statusLabel !== '' ? $statusLabel : $statusStyle['label']),
        ]);
    }

    /**
     * Creates an appointment details table that also works in classic Outlook.
     *
     * @param array<string, string> $rows Label/value pairs. Values may contain
     *                                   trusted template HTML or placeholders;
     *                                   labels are always escaped.
     * @return string Responsive data table with Outlook-safe spacing.
     */
    public static function detailsTable(array $rows): string
    {
        $body = '';
        foreach ($rows as $label => $value) {
            $body .= '<tr>'
                . '<th scope="row" style="width:34%;padding:10px 12px;border-bottom:1px solid #e5e9ef;color:#5f6b7a;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:600;line-height:20px;text-align:left;vertical-align:top;overflow-wrap:anywhere;word-break:break-word;">'
                . esc_html($label)
                . '</th>'
                . '<td style="padding:10px 12px;border-bottom:1px solid #e5e9ef;color:#1f2937;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:22px;text-align:left;vertical-align:top;overflow-wrap:anywhere;word-break:break-word;">'
                . $value
                . '</td>'
                . '</tr>';
        }

        // Word handles table margins inconsistently. Keep the spacing outside
        // the data table, on a presentation cell instead.
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt;">'
            . '<tr><td style="padding:20px 0;">'
            . '<table class="rrze-email-details" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt;">'
            . $body
            . '</table></td></tr></table>';
    }

    /**
     * Creates an Outlook-compatible primary action button.
     *
     * @param string $url Concrete action URL, restricted to WordPress-safe protocols.
     * @param string $label Plain-text action label.
     * @return string Presentation table containing a fully clickable button.
     */
    public static function actionButton(string $url, string $label): string
    {
        // The layout's Outlook-only CSS moves padding to the cell. Other
        // clients keep it on the link so the full button remains clickable.
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt;">'
            . '<tr><td style="padding:24px 0 16px;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;mso-table-lspace:0pt;mso-table-rspace:0pt;">'
            . '<tr><td class="rrze-email-button-cell" bgcolor="#04316a" style="border-radius:4px;background-color:#04316a;mso-padding-alt:12px 20px;">'
            . '<a class="rrze-email-button-link" href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:12px 20px;mso-padding-alt:0;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;line-height:20px;text-decoration:none;">'
            . esc_html($label)
            . '</a></td></tr></table></td></tr></table>';
    }

    /** @return string Compiled layout or the self-contained fallback when assets are unavailable. */
    private static function getLayout(): string
    {
        $path = dirname(__DIR__, 2) . '/' . self::LAYOUT_PATH;
        if (is_readable($path)) {
            $layout = file_get_contents($path);
            if (is_string($layout) && self::hasRequiredLayoutMarkers($layout)) {
                return $layout;
            }
        }

        return self::getFallbackLayout();
    }

    /**
     * Verifies that a compiled layout contains every runtime marker.
     * @param string $layout Candidate compiled HTML.
     * @return bool Whether the layout can be rendered safely.
     */
    private static function hasRequiredLayoutMarkers(string $layout): bool
    {
        foreach (self::REQUIRED_LAYOUT_MARKERS as $marker) {
            if (strpos($layout, $marker) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $siteName Accessible logo text.
     * @return string Bounded logo or text branding.
     */
    private static function getLogoHtml(string $siteName): string
    {
        if (has_custom_logo()) {
            $logoId = (int) get_theme_mod('custom_logo');
            $logo = wp_get_attachment_image_src($logoId, 'medium');
            if (is_array($logo) && !empty($logo[0]) && $logo[1] > 0 && $logo[2] > 0) {
                // Outlook needs explicit dimensions; max-width/max-height
                // alone do not reliably constrain an image there.
                $scale = min(1, 200 / $logo[1], 64 / $logo[2]);
                $width = max(1, (int) round($logo[1] * $scale));
                $height = max(1, (int) round($logo[2] * $scale));

                return '<img src="' . esc_url($logo[0]) . '" width="' . $width . '" height="' . $height . '" alt="' . esc_attr($siteName) . '" style="display:block;width:' . $width . 'px;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;">';
            }
        }

        return '<span style="display:inline-block;color:#1f2937;font-size:20px;font-weight:700;line-height:28px;">'
            . esc_html($siteName)
            . '</span>';
    }

    /** @return string Available site policy links, without guessing missing pages. */
    private static function getFooterLinksHtml(): string
    {
        $links = [];
        $linkStyle = 'color:#04316a;font-weight:600;text-decoration:none;';

        $privacyUrl = get_privacy_policy_url();
        if ($privacyUrl) {
            $links[] = '<a href="' . esc_url($privacyUrl) . '" target="_blank" rel="noopener noreferrer" style="' . $linkStyle . '">'
                . esc_html__('Privacy Policy', 'rrze-rsvp')
                . '</a>';
        }

        $imprintUrl = self::imprintUrl();
        if ($imprintUrl) {
            $links[] = '<a href="' . esc_url($imprintUrl) . '" target="_blank" rel="noopener noreferrer" style="' . $linkStyle . '">'
                . esc_html__('Legal Notice', 'rrze-rsvp')
                . '</a>';
        }

        return implode(' &nbsp;&middot;&nbsp; ', $links);
    }

    /** @return string Existing legal endpoint or published local imprint page; empty when unavailable. */
    private static function imprintUrl(): string
    {
        if (class_exists('\\RRZE\\Legal\\TOS\\Endpoint')) {
            try {
                $url = \RRZE\Legal\TOS\Endpoint::endpointUrl('imprint');
                if (is_string($url) && $url !== '') {
                    return $url;
                }
            } catch (\Throwable $exception) {
                // An optional legal integration must not prevent booking mail delivery.
            }
        }
        $page = get_page_by_path('impressum');
        return $page && $page->post_status === 'publish' ? (string) get_permalink($page->ID) : '';
    }

    /**
     * Material palette colors paired with high-contrast text colors.
     *
     * @param string $status Semantic palette name.
     * @return array{accent: string, surface: string, text: string, label: string}
     */
    private static function getStatusStyle(string $status): array
    {
        $styles = [
            self::STATUS_SUCCESS => [
                'accent' => '#2e7d32',
                'surface' => '#e8f5e9',
                'text' => '#1b5e20',
                'label' => __('Booking confirmed', 'rrze-rsvp'),
            ],
            self::STATUS_WARNING => [
                'accent' => '#ff8f00',
                'surface' => '#fff8e1',
                'text' => '#5d4037',
                'label' => __('Confirmation required', 'rrze-rsvp'),
            ],
            self::STATUS_DANGER => [
                'accent' => '#c62828',
                'surface' => '#ffebee',
                'text' => '#b71c1c',
                'label' => __('Booking cancelled', 'rrze-rsvp'),
            ],
            self::STATUS_NEUTRAL => [
                'accent' => '#04316a',
                'surface' => '#e9f2fb',
                'text' => '#04316a',
                'label' => __('Appointment update', 'rrze-rsvp'),
            ],
        ];

        return $styles[$status] ?? $styles[self::STATUS_NEUTRAL];
    }

    /** @return string Standalone HTML fallback retaining every runtime marker and Outlook button rules. */
    private static function getFallbackLayout(): string
    {
        return '<!DOCTYPE html><html lang="___RRZE_EMAIL_LANG___" dir="___RRZE_EMAIL_DIR___"><head>'
            . '<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>___RRZE_EMAIL_SUBJECT___</title>'
            . '<!--[if mso]><style>.rrze-email-button-cell{padding:12px 20px!important;}.rrze-email-button-link{padding:0!important;}body,table,td,th,p,a,h1{font-family:Arial,Helvetica,sans-serif!important;}</style><![endif]--></head>'
            . '<body style="margin:0;padding:0;background:#f3f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;background:#f3f5f7;">'
            . '<tr><td align="center" style="padding:32px 16px;"><table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:640px;">'
            . '<tr><td style="padding:28px 32px 24px;border:1px solid #d7dde5;border-bottom:0;background:#ffffff;">'
            . '<a href="___RRZE_EMAIL_SITE_URL___" style="text-decoration:none;">___RRZE_EMAIL_LOGO___</a></td></tr>'
            . '<tr><td style="padding:28px 32px 32px;border:1px solid #d7dde5;border-top:4px solid ___RRZE_EMAIL_STATUS_ACCENT___;background:#ffffff;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;"><tr><td style="padding:0 0 24px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;"><tr><td bgcolor="___RRZE_EMAIL_STATUS_SURFACE___" style="padding:10px 14px;border-left:4px solid ___RRZE_EMAIL_STATUS_ACCENT___;background-color:___RRZE_EMAIL_STATUS_SURFACE___;color:___RRZE_EMAIL_STATUS_TEXT___;font-size:13px;font-weight:700;line-height:20px;">'
            . '___RRZE_EMAIL_STATUS_LABEL___</td></tr></table></td></tr></table>'
            . '<p style="margin:0 0 8px;color:#04316a;font-size:12px;font-weight:700;text-transform:uppercase;">___RRZE_EMAIL_SITE_NAME___</p>'
            . '<h1 style="margin:0 0 24px;color:#1f2937;font-size:28px;line-height:36px;">___RRZE_EMAIL_SUBJECT___</h1>'
            . '<div style="font-size:15px;line-height:24px;">___RRZE_EMAIL_CONTENT___</div></td></tr>'
            . '<tr><td style="padding:20px 32px;border:1px solid #d7dde5;border-top:0;background:___RRZE_EMAIL_STATUS_SURFACE___;color:#5f6b7a;font-size:12px;line-height:20px;">'
            . '<p style="margin:0 0 4px;"><a href="___RRZE_EMAIL_SITE_URL___" style="color:#04316a;font-weight:600;text-decoration:none;">___RRZE_EMAIL_SITE_NAME___</a></p>'
            . '___RRZE_EMAIL_FOOTER_LINKS___</td></tr></table></td></tr></table></body></html>';
    }
}
