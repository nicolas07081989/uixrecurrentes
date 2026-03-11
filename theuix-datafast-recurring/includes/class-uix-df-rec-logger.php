<?php

if (!defined('ABSPATH')) {
    exit;
}

class UIX_DF_Rec_Logger
{
    public static function info($message, array $context = [])
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[UIX-DF-REC] ' . $message . ' ' . wp_json_encode($context));
        }
    }
}
