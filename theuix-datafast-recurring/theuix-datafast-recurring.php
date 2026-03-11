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

define('UIX_DF_REC_PLUGIN_FILE', __FILE__);
define('UIX_DF_REC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('UIX_DF_REC_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once UIX_DF_REC_PLUGIN_DIR . 'includes/class-uix-df-rec-db.php';
require_once UIX_DF_REC_PLUGIN_DIR . 'includes/class-uix-df-rec-result-codes.php';
require_once UIX_DF_REC_PLUGIN_DIR . 'includes/class-uix-df-rec-logger.php';
require_once UIX_DF_REC_PLUGIN_DIR . 'includes/class-uix-df-rec-datafast-client.php';
require_once UIX_DF_REC_PLUGIN_DIR . 'includes/class-uix-df-rec-subscription-repo.php';
require_once UIX_DF_REC_PLUGIN_DIR . 'includes/class-uix-df-rec-wc-gateway.php';
require_once UIX_DF_REC_PLUGIN_DIR . 'includes/class-uix-df-rec-plugin.php';

register_activation_hook(__FILE__, ['UIX_DF_Rec_DB', 'activate']);
register_deactivation_hook(__FILE__, ['UIX_DF_Rec_DB', 'deactivate']);

add_action('plugins_loaded', function () {
    UIX_DF_Rec_Plugin::instance()->init();
});
