<?php

/**
 * Stub functions to reduce path for the symbolic execution.
 */

/**
 * This function often appears in the codebase and used to sanitize
 * input, but we don't care about sanitization in symbolic execution.
 * Instead, we just return the input as is.
 */
function sanitize_text_field($str)
{
    return $str;
}

require_once 'base-wordpress-loader.php';