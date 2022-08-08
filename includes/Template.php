<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

/**
 * [Template description]
 */
class Template
{
    /**
     * [__construct description]
     */
    public function __construct()
    {
        //
    }

    /**
     * [getContent description]
     * @param  string $template [description]
     * @param  array  $data     [description]
     * @return string           [description]
     */
    public function getContent($template = '', $data = [])
    {
        return $this->parseContent($template, $data);
    }

    /**
     * [parseContent description]
     * @param  string $template [description]
     * @param  array  $data     [description]
     * @return string           [description]
     */
    protected function parseContent($template, $data)
    {
        $templateFile = $this->getTemplate($template);
        if (!$templateFile || empty($data)) {
            return '';
        }
        $parser = new Parser();
        return $parser->parse($templateFile, $data);
    }

    /**
     * [getTemplate description]
     * @param  string $template [description]
     * @return string           [description]
     */
    protected function getTemplate($template)
    {
        $extension = pathinfo($template, PATHINFO_EXTENSION) ? '' : '.html';
        $templateFile = sprintf('%sincludes/templates/%s%s', plugin()->getDirectory(), $template, $extension);
        return is_readable($templateFile) ? $templateFile : '';
    }
}
