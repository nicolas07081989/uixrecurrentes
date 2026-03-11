<?php

if (!defined('ABSPATH')) {
    exit;
}

class UIX_DF_Rec_Logger
{
    private static function enabled()
    {
        return (bool) get_option('uix_df_debug_enabled', 1);
    }

    private static function redact(array $context)
    {
        $sensitive = [
            'initial_bearer_token',
            'recurring_bearer_token',
            'Authorization',
            'authorization',
        ];

        array_walk_recursive($context, function (&$value, $key) use ($sensitive) {
            if (in_array((string) $key, $sensitive, true)) {
                $value = '***redacted***';
            }
        });

        return $context;
    }

    public static function log($level, $message, array $context = [])
    {
        if (!self::enabled()) {
            return;
        }

        $context = self::redact($context);

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->log($level, $message . ' ' . wp_json_encode($context), ['source' => 'uix-df-recurring']);
            return;
        }

        error_log('[UIX-DF-REC][' . strtoupper($level) . '] ' . $message . ' ' . wp_json_encode($context));
    }

    public static function info($message, array $context = [])
    {
        self::log('info', $message, $context);
    }

    public static function error($message, array $context = [])
    {
        self::log('error', $message, $context);
    }
}
