<?php

declare(strict_types=1);

namespace RRZE\RSVP\Export;

use WP_Error;

defined('ABSPATH') || exit;

/** Serializes a fully prepared export in memory without creating temporary files. */
final class CsvWriter
{
    /**
     * Build UTF-8 CSV with a BOM, semicolon separators, explicit quoting and CRLF records.
     *
     * @param array{headers:array,rows:array} $document Validated export document.
     * @return string|WP_Error Complete file bytes, never a partially emitted download.
     */
    public static function write(#[\SensitiveParameter] array $document): string|WP_Error
    {
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) return self::failed();
        try {
            if (fwrite($stream, "\xEF\xBB\xBF") !== 3) return self::failed();
            foreach ([$document['headers'], ...$document['rows']] as $row) {
                if (count($row) !== count($document['headers'])) return self::failed();
                $cells = [];
                foreach ($row as $value) {
                    if (!is_scalar($value)) return self::failed();
                    $value = (string) $value;
                    if (preg_match('//u', $value) !== 1 || str_contains($value, "\0")) return self::failed();
                    // Quote as text instead of stripping meaningful characters from names or titles.
                    if (preg_match('/\A(?:[\p{Z}\p{C}]*[=+@-]|[\t\r\n])/u', $value)) $value = "'" . $value;
                    $cells[] = $value;
                }
                if (fputcsv($stream, $cells, ';', '"', '', "\r\n") === false) return self::failed();
            }
            if (!rewind($stream)) return self::failed();
            $csv = stream_get_contents($stream);
            return is_string($csv) ? $csv : self::failed();
        } finally {
            fclose($stream);
        }
    }

    /** @return WP_Error Safe serialization failure without exposing a row. */
    private static function failed(): WP_Error
    {
        return new WP_Error('booking_export_csv', __('The CSV could not be created completely. No file was downloaded.', 'rrze-rsvp'));
    }
}
