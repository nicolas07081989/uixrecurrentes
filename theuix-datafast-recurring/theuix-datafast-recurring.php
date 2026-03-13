<?php
/**
 * Plugin Name: TheUIX Datafast Recurring
 * Description: Suscripciones y cobros recurrentes con Datafast para TheUIXstudio.
 * Version: 0.1.0
 * Author: TheUIXstudio
 */

if (!defined('ABSPATH')) {
    exit;
}

if (defined('UIX_DF_REC_PLUGIN_LOADED')) {
    return;
}

define('UIX_DF_REC_PLUGIN_LOADED', true);

define('UIX_DF_REC_PLUGIN_FILE', __FILE__);
define('UIX_DF_REC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('UIX_DF_REC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('UIX_DF_REC_PLUGIN_VERSION', '0.1.1');
define('UIX_DF_REC_PLUGIN_BUILD', 'includes');

require_once UIX_DF_REC_PLUGIN_DIR . 'includes/class-uix-df-rec-db.php';
require_once UIX_DF_REC_PLUGIN_DIR . 'includes/class-uix-df-rec-result-codes.php';
require_once UIX_DF_REC_PLUGIN_DIR . 'includes/class-uix-df-rec-logger.php';
require_once UIX_DF_REC_PLUGIN_DIR . 'includes/class-uix-df-rec-datafast-client.php';
require_once UIX_DF_REC_PLUGIN_DIR . 'includes/class-uix-df-rec-subscription-repo.php';
require_once UIX_DF_REC_PLUGIN_DIR . 'includes/class-uix-df-rec-plugin.php';

register_activation_hook(__FILE__, ['UIX_DF_Rec_DB', 'activate']);
register_deactivation_hook(__FILE__, ['UIX_DF_Rec_DB', 'deactivate']);

add_action('plugins_loaded', function () {
    UIX_DF_Rec_Logger::info('UIX Datafast plugin bootstrap', [
        'version' => UIX_DF_REC_PLUGIN_VERSION,
        'main_file' => UIX_DF_REC_PLUGIN_FILE,
        'build' => UIX_DF_REC_PLUGIN_BUILD,
    ]);
    UIX_DF_Rec_Plugin::instance()->init();
});
