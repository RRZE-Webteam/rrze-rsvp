<?php

/** WordPress filesystem fixture loaded on demand by the standalone mail test. */
declare(strict_types=1);

/**
 * Reserve a temporary file for a calendar attachment.
 *
 * @param string $filename Suggested filename.
 * @return string|false Path to the reserved file, or false on failure.
 */
function wp_tempnam(string $filename)
{
    return tempnam(sys_get_temp_dir(), $filename);
}
