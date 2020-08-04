<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

/**
 * Parse a HTML string with embedded interpolation expressions.
 *
 * Value interpolation: {{=value}}
 * Value to HTML entities interpolation: {{%unsafe_value}}
 * Mutidimensional value: {{=user.address.city}}
 * If blocks: {{value}} <<markup>> {{/value}}
 * If not blocks: {{!value}} <<markup>> {{/!value}}
 * If/else blocks: {{value}} <<markup>> {{:value}} <<alternate markup>> {{/value}}
 * Values iteration: {{@values}} {{=_key}}:{{=_val}} {{/@values}}
 */
class Parser
{
    /**
     * [protected description]
     * @var string
     */
    protected $blockRegex = '/\\{\\{(([@!]?)(.+?))\\}\\}(([\\s\\S]+?)(\\{\\{:\\1\\}\\}([\\s\\S]+?))?)\\{\\{\\/\\1\\}\\}/';

    /**
     * [protected description]
     * @var string
     */
    protected $valRegex = '/\\{\\{([=%])(.+?)\\}\\}/';

    /**
     * [protected description]
     * @var array
     */
    protected $vars;

    /**
     * [__construct description]
     * @param string $template [description]
     */
    public function __construct($template = '')
    {
        $this->vars = [];
    }

    /**
     * Convert special characters to HTML entities
     * @param  string $val [description]
     * @return string      [description]
     */
    public function convertToHtmlEntities($val)
    {
        return htmlspecialchars($val . '', ENT_QUOTES);
    }

    /**
     * [getValue description]
     * @param  string $index [description]
     * @return string        [description]
     */
    public function getValue($index)
    {
        $index = explode('.', $index);

        return $this->searchValue($index, $this->vars);
    }

    /**
     * [searchValue description]
     * @param array $index  [description]
     * @param array $value [description]
     * @return string       [description]
     */
    protected function searchValue($index, $value)
    {
        if (is_array($index) &&
           ! empty($index)) {
            $current_index = array_shift($index);
        }
        if (is_array($index) &&
           ! empty($index) &&
           isset($value[$current_index]) &&
           is_array($value[$current_index]) &&
           ! empty($value[$current_index])) {
            return $this->searchValue($index, $value[$current_index]);
        } else {
            $val = isset($value[$current_index]) ? $value[$current_index] : '';
            return str_replace('{{', "{\f{", $val);
        }
    }

    /**
     * [matchTags description]
     * @param  array $matches [description]
     * @return string         [description]
     */
    public function matchTags($matches)
    {
        if (! is_array($matches)) {
            return '';
        }

        $_key = isset($matches[0]) ? $matches[0] : '';
        $_val = isset($matches[1]) ? $matches[1] : '';
        $meta = isset($matches[2]) ? $matches[2] : '';
        $key = isset($matches[3]) ? $matches[3] : '';
        $expr = isset($matches[4]) ? $matches[4] : '';
        $ifTrue = isset($matches[5]) ? $matches[5] : '';
        $ifElse = isset($matches[6]) ? $matches[6] : '';
        $ifFalse = isset($matches[7]) ? $matches[7] : '';

        $val = $this->getValue($key);

        $temp = '';

        if (! $val) {
            // Check for if negation
            if ($meta == '!') {
                return $this->render($expr);
            }
            // Check for if else
            if ($ifElse) {
                return $this->render($ifFalse);
            }
            return '';
        }

        // Check for regular if expr
        if (! $meta) {
            return $this->render($ifTrue);
        }

        // Process array iteration
        if ($meta == '@') {
            // Store any previous vars by reusing existing vars
            $_key = $this->vars['_key'];
            $_val = $this->vars['_val'];

            foreach ($_val as $i => $v) {
                $this->vars['_key'] = $i;
                $this->vars['_val'] = $v;

                $temp .= $this->render($expr);
            }

            $this->vars['_key'] = $_key;
            $this->vars['_val'] = $_val;

            return $temp;
        }
    }

    /**
     * [replaceTags description]
     * @param  array $matches [description]
     * @return string         [description]
     */
    public function replaceTags($matches)
    {
        if (! is_array($matches)) {
            return '';
        }

        $meta = isset($matches[1]) ? $matches[1] : '';
        $key = isset($matches[2]) ? $matches[2] : '';

        $val = $this->getValue($key);

        if ($val || $val === 0) {
            return $meta == '%' ? $this->convertToHtmlEntities($val) : $val;
        }

        return '';
    }

    /**
     * [render description]
     * @param  string $fragment [description]
     * @return mixed            [description]
     */
    protected function render($fragment)
    {
        $matchTags = preg_replace_callback($this->blockRegex, [$this, 'matchTags'], $fragment);
        $replaceTags = preg_replace_callback($this->valRegex, [$this, 'replaceTags'], $matchTags);

        return $replaceTags;
    }

    /**
     * [parse description]
     * @param  string $template [description]
     * @param  array $data      [description]
     * @return string           [description]
     */
    public function parse($templateFile, $data)
    {
        if (! is_readable($templateFile)) {
            return '';
        }
        ob_start();
        include($templateFile);
        $content = ob_get_contents();
        @ob_end_clean();
        $this->vars = (array) $data;
        return $this->render($content);
    }
}
