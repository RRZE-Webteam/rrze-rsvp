<?php

namespace RRZE\RSVP\Blocks;

use WP_Error;

defined('ABSPATH') || exit;

/** Immutable resource binding shared by a block's form, AJAX calls and return URL. */
final class Context
{
    private static array $occurrences = [];

    /**
     * Store a validated block identity; an instance key is routing data, not authorization.
     *
     * @param int $sourcePostId Page containing the block.
     * @param string $instanceKey UUID with an optional repeated-render suffix.
     * @param string $mode Booking variation.
     * @param int $resourceId Selected course or room.
     */
    public function __construct(public readonly int $sourcePostId, public readonly string $instanceKey,
        public readonly string $mode, public readonly int $resourceId)
    {
    }

    /**
     * Allocate distinct DOM identities even when a synced block occurs twice.
     *
     * @param array<string, mixed> $attributes Saved block attributes.
     * @param int $sourcePostId Current page ID.
     * @return self Per-render identity.
     */
    public static function create(array $attributes, int $sourcePostId): self
    {
        $uuid = (string) ($attributes['instanceId'] ?? '');
        if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $uuid)) {
            // Unsaved/programmatically authored blocks still get deterministic render identities.
            $hash = md5($sourcePostId . ':' . wp_json_encode($attributes));
            $uuid = substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-' . substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20);
        }
        $key = $sourcePostId . ':' . $uuid;
        $count = self::$occurrences[$key] = (self::$occurrences[$key] ?? 0) + 1;
        return new self($sourcePostId, $uuid . ($count > 1 ? '-' . $count : ''),
            (string) ($attributes['mode'] ?? ''), absint($attributes['resourceId'] ?? 0));
    }

    /**
     * Authenticate untrusted context fields before they influence any room operation.
     *
     * @param array<string, mixed> $input Unslashed GET/POST fields.
     * @return self|WP_Error|null Valid context, rejection, or an ordinary legacy request.
     */
    public static function fromRequest(array $input)
    {
        if (!array_intersect(['rsvp_source', 'rsvp_instance', 'rsvp_mode', 'rsvp_resource', 'rsvp_context_nonce'], array_keys($input))) {
            return null;
        }
        foreach (['rsvp_source', 'rsvp_instance', 'rsvp_mode', 'rsvp_resource', 'rsvp_context_nonce'] as $field) {
            if (!isset($input[$field]) || !is_scalar($input[$field])) {
                return self::invalid();
            }
        }
        $instance = (string) $input['rsvp_instance'];
        if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}(?:-[2-9][0-9]*|-[1-9][0-9]+)?$/D', $instance)) {
            return self::invalid();
        }
        $context = new self(absint($input['rsvp_source']), $instance, (string) $input['rsvp_mode'], absint($input['rsvp_resource']));
        $source = get_post($context->sourcePostId);
        if (!$source || !get_permalink($source) || in_array($source->post_status, ['trash', 'auto-draft'], true)
            || !wp_verify_nonce((string) $input['rsvp_context_nonce'], $context->action())
            || !Resources::matches($context->resourceId, $context->mode)) {
            return self::invalid();
        }
        return $context;
    }

    /** @return WP_Error Uniform error without leaking resource existence. */
    private static function invalid(): WP_Error
    {
        return new WP_Error('rsvp_invalid_context', __('This booking form has expired or changed. Please reload the page.', 'rrze-rsvp'), ['status' => 403]);
    }

    /** @return string Nonce action binding every part of the resource context. */
    public function action(): string
    {
        return 'rrze-rsvp-block:' . $this->sourcePostId . ':' . $this->instanceKey . ':' . $this->mode . ':' . $this->resourceId;
    }

    /** @param self|null $other Request context. @return bool Whether it targets exactly this rendered block. */
    public function matches(?self $other): bool
    {
        return $other !== null && $this->action() === $other->action();
    }

    /** @return string Safe, page-unique DOM prefix and fragment. */
    public function prefix(): string
    {
        return 'rsvp-block-' . $this->sourcePostId . '-' . $this->instanceKey;
    }

    /** @return array<string, int|string> Context fields carried through forms and requests. */
    public function fields(): array
    {
        return ['rsvp_source' => $this->sourcePostId, 'rsvp_instance' => $this->instanceKey,
            'rsvp_mode' => $this->mode, 'rsvp_resource' => $this->resourceId,
            'rsvp_context_nonce' => wp_create_nonce($this->action())];
    }

    /** @return string Hidden, escaped form controls without duplicate DOM IDs. */
    public function hiddenFields(): string
    {
        $html = '';
        foreach ($this->fields() as $key => $value) {
            $html .= '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
        }
        return $html;
    }

    /**
     * Build a local return URL from the trusted source page, never a posted redirect target.
     *
     * @param array<string, mixed> $parameters Result or selection fields.
     * @return string Addressed local URL.
     */
    public function url(array $parameters = []): string
    {
        return add_query_arg(array_merge($parameters, $this->fields()), get_permalink($this->sourcePostId)) . '#' . $this->prefix();
    }

    /** @param int $bookingId Booking receipt ID. @return string Receipt nonce binding booking and instance. */
    public function receiptAction(int $bookingId): string
    {
        return $this->action() . ':receipt:' . $bookingId;
    }

    /**
     * Namespace markup IDs and all corresponding references without touching field names.
     *
     * @param string $html Trusted plugin-rendered form fragment.
     * @return string Namespaced HTML for this block, including AJAX fragments.
     */
    public function scopeHtml(string $html): string
    {
        $processor = new \WP_HTML_Tag_Processor($html);
        while ($processor->next_tag()) {
            $id = $processor->get_attribute('id');
            if (is_string($id) && $id !== '' && !str_starts_with($id, $this->prefix() . '-')) {
                $processor->set_attribute('id', $this->prefix() . '-' . $id);
            }
            foreach (['for', 'aria-labelledby', 'aria-describedby', 'aria-controls'] as $attribute) {
                $value = $processor->get_attribute($attribute);
                if (is_string($value) && $value !== '') {
                    $references = [];
                    foreach (preg_split('/\s+/', trim($value)) as $reference) {
                        $references[] = str_starts_with($reference, $this->prefix() . '-') ? $reference : $this->prefix() . '-' . $reference;
                    }
                    $processor->set_attribute($attribute, implode(' ', $references));
                }
            }
        }
        return $processor->get_updated_html();
    }
}
