<?php

if (!defined('ABSPATH')) {
    exit;
}

class UIX_DF_Rec_Plugin
{
    private static $instance;
    private $repo;

    public static function instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function init()
    {
        $this->repo = new UIX_DF_Rec_Subscription_Repo();

        UIX_DF_Rec_Logger::info('Plugin runtime mode', [
            'mode' => 'phase1_only',
            'build' => defined('UIX_DF_REC_PLUGIN_BUILD') ? UIX_DF_REC_PLUGIN_BUILD : 'unknown',
            'version' => defined('UIX_DF_REC_PLUGIN_VERSION') ? UIX_DF_REC_PLUGIN_VERSION : 'unknown',
            'main_file' => defined('UIX_DF_REC_PLUGIN_FILE') ? UIX_DF_REC_PLUGIN_FILE : __FILE__,
        ]);

        // Fase 1 únicamente: desactivar cualquier cron legado de recurrencia.
        wp_clear_scheduled_hook('uix_df_recurring_charge_runner');

        add_action('init', [$this, 'register_shortcode']);
        add_action('admin_post_nopriv_uix_df_create_checkout', [$this, 'handle_create_checkout']);
        add_action('admin_post_uix_df_create_checkout', [$this, 'handle_create_checkout']);
        add_action('admin_post_uix_df_test_initial_credentials', [$this, 'handle_test_initial_credentials']);
        add_action('admin_post_uix_df_test_initial_verify', [$this, 'handle_test_initial_verify']);
        add_action('template_redirect', [$this, 'handle_wc_checkout']);
        add_action('template_redirect', [$this, 'handle_return']);

        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        add_action('woocommerce_loaded', [$this, 'bootstrap_wc_gateway']);
        add_action('plugins_loaded', [$this, 'maybe_show_woocommerce_notice'], 20);
        if (class_exists('WC_Payment_Gateway')) {
            $this->bootstrap_wc_gateway();
        }
    }

    public function maybe_show_woocommerce_notice()
    {
        if (class_exists('WooCommerce')) {
            return;
        }

        if (!is_admin() || !current_user_can('activate_plugins')) {
            return;
        }

        add_action('admin_notices', function () {
            echo '<div class="notice notice-warning"><p><strong>TheUIX Datafast:</strong> WooCommerce no está activo.</p></div>';
        });
    }

    public function bootstrap_wc_gateway()
    {
        if (!class_exists('WC_Payment_Gateway')) {
            return;
        }

        if (!class_exists('UIX_DF_Rec_WC_Gateway')) {
            require_once UIX_DF_REC_PLUGIN_DIR . 'includes/class-uix-df-rec-wc-gateway.php';
        }

        add_filter('woocommerce_payment_gateways', [$this, 'register_wc_gateway']);
    }

    public function register_wc_gateway($methods)
    {
        if (class_exists('UIX_DF_Rec_WC_Gateway') && !in_array('UIX_DF_Rec_WC_Gateway', $methods, true)) {
            $methods[] = 'UIX_DF_Rec_WC_Gateway';
        }

        return $methods;
    }

    private function sanitize_token_for_transport($token)
    {
        return preg_replace('/\s+/', '', trim((string) $token));
    }

    private function sanitize_entity_id_for_transport($entityId)
    {
        return preg_replace('/\s+/', '', trim((string) $entityId));
    }

    private function payment_brands_attr()
    {
        $brandsRaw = trim((string) get_option('uix_df_payment_brands', 'VISA MASTER'));
        if ($brandsRaw === '') {
            $brandsRaw = 'VISA MASTER';
        }

        $brands = preg_split('/\s+/', strtoupper($brandsRaw));
        $brands = array_filter(array_unique(array_map('sanitize_text_field', $brands)));

        return implode(' ', $brands);
    }

    private function settings()
    {
        return [
            'initial_entity_id' => $this->sanitize_entity_id_for_transport(get_option('uix_df_initial_entity_id', '')),
            'initial_bearer_token' => $this->sanitize_token_for_transport(get_option('uix_df_initial_bearer_token', '')),
            'initial_base_url' => trim((string) get_option('uix_df_initial_base_url', 'https://eu-test.oppwa.com')),
            'payment_brands' => get_option('uix_df_payment_brands', 'VISA MASTER'),
            'debug_enabled' => (bool) get_option('uix_df_debug_enabled', 1),
        ];
    }

    private function validate_initial_checkout_settings(array $settings)
    {
        $required = [
            'initial_entity_id' => $settings['initial_entity_id'] ?? '',
            'initial_bearer_token' => $settings['initial_bearer_token'] ?? '',
            'initial_base_url' => $settings['initial_base_url'] ?? '',
        ];

        $missing = [];
        foreach ($required as $key => $value) {
            if (trim((string) $value) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    private function build_phase1_checkout_payload($entityId, $amount, $currency)
    {
        return [
            'entityId' => $this->sanitize_entity_id_for_transport($entityId),
            'amount' => number_format((float) $amount, 2, '.', ''),
            'currency' => strtoupper(trim((string) $currency)) ?: 'USD',
            'paymentType' => 'DB',
        ];
    }

    public function register_shortcode()
    {
        add_shortcode('uix_subscribe_form', [$this, 'render_subscribe_form']);
    }

    public function render_subscribe_form($atts)
    {
        $atts = shortcode_atts([
            'plan' => 'plan-mensual',
            'title' => 'Plan Mensual',
            'amount' => '30.00',
        ], $atts);

        ob_start();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="uix_df_create_checkout" />
            <?php wp_nonce_field('uix_df_create_checkout', 'uix_df_nonce'); ?>
            <input type="hidden" name="plan_slug" value="<?php echo esc_attr($atts['plan']); ?>" />
            <input type="hidden" name="plan_title" value="<?php echo esc_attr($atts['title']); ?>" />
            <input type="hidden" name="amount" value="<?php echo esc_attr($atts['amount']); ?>" />
            <p><strong><?php echo esc_html($atts['title']); ?></strong> — USD <?php echo esc_html($atts['amount']); ?></p>
            <p><label>Nombre completo<br><input type="text" name="full_name" required></label></p>
            <p><label>Email<br><input type="email" name="email" required></label></p>
            <p><button type="submit">Pagar con Datafast</button></p>
        </form>
        <?php
        return ob_get_clean();
    }

    public function handle_create_checkout()
    {
        if (!isset($_POST['uix_df_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['uix_df_nonce'])), 'uix_df_create_checkout')) {
            wp_die('Nonce inválido');
        }

        $fullName = sanitize_text_field(wp_unslash($_POST['full_name'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $planSlug = sanitize_text_field(wp_unslash($_POST['plan_slug'] ?? ''));
        $planTitle = sanitize_text_field(wp_unslash($_POST['plan_title'] ?? ''));
        $amount = number_format((float) ($_POST['amount'] ?? 0), 2, '.', '');

        if (!$fullName || !$email || $amount <= 0) {
            wp_die('Datos inválidos');
        }

        $subscriptionId = $this->repo->create_pending([
            'full_name' => $fullName,
            'email' => $email,
            'cedula_ruc' => '',
            'plan_slug' => $planSlug,
            'plan_title' => $planTitle,
            'amount' => $amount,
            'max_retries' => 0,
        ]);

        $settings = $this->settings();
        $missingSettings = $this->validate_initial_checkout_settings($settings);
        if (!empty($missingSettings)) {
            wp_die('Configuración incompleta: ' . esc_html(implode(', ', $missingSettings)));
        }

        $returnUrl = add_query_arg([
            'uix_df_return' => 1,
            'subscription_id' => $subscriptionId,
        ], home_url('/'));

        $payload = $this->build_phase1_checkout_payload($settings['initial_entity_id'], $amount, 'USD');
        $client = new UIX_DF_Rec_Datafast_Client($settings);
        $response = $client->create_checkout($payload);

        if (!$response['ok'] || empty($response['body']['id'])) {
            UIX_DF_Rec_Logger::error('Checkout creation failed (shortcode)', [
                'subscription_id' => $subscriptionId,
                'status' => $response['status'] ?? 0,
                'payload' => $payload,
                'parsed_body' => $response['parsed_body'] ?? null,
                'raw_body' => $response['raw_body'] ?? null,
            ]);
            wp_die('No se pudo crear checkout. Revisa logs de Datafast.');
        }

        $checkoutId = $response['body']['id'];
        $this->repo->update_checkout($subscriptionId, $checkoutId);

        $widgetJs = rtrim($settings['initial_base_url'], '/') . '/v1/paymentWidgets.js?checkoutId=' . rawurlencode($checkoutId);

        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Pagar suscripción</title></head><body>';
        echo '<!-- UIX DF PHASE1 BUILD: ' . esc_html(defined('UIX_DF_REC_PLUGIN_BUILD') ? UIX_DF_REC_PLUGIN_BUILD : 'unknown') . ' v' . esc_html(defined('UIX_DF_REC_PLUGIN_VERSION') ? UIX_DF_REC_PLUGIN_VERSION : 'unknown') . ' -->';
        echo '<h2>Finaliza tu pago</h2>';
        echo '<script src="' . esc_url($widgetJs) . '"></script>';
        echo '<form action="' . esc_url($returnUrl) . '" class="paymentWidgets" data-brands="' . esc_attr($this->payment_brands_attr()) . '"></form>';
        echo '<script src="https://www.datafast.com.ec/js/dfAdditionalValidations1.js"></script>';
        echo '</body></html>';
        exit;
    }

    private function fail_wc_checkout_and_back($order, $message, array $logContext = [])
    {
        UIX_DF_Rec_Logger::error($message, $logContext);
        if ($order && method_exists($order, 'add_order_note')) {
            $order->add_order_note('Datafast checkout error: ' . wc_clean((string) $message));
        }

        wc_add_notice(__('No se pudo inicializar el pago con Datafast.', 'uix-df-rec'), 'error');
        wp_safe_redirect($order->get_checkout_payment_url());
        exit;
    }

    public function handle_wc_checkout()
    {
        if (!isset($_GET['uix_df_wc_checkout'])) {
            return;
        }

        if (!function_exists('wc_get_order')) {
            wp_die('WooCommerce no disponible');
        }

        $orderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        $order = $orderId > 0 ? wc_get_order($orderId) : null;

        if (!$order) {
            wp_die('Orden inválida');
        }

        if ($key && $order->get_order_key() !== $key) {
            wp_die('Orden inválida (key)');
        }

        $settings = $this->settings();
        $missingSettings = $this->validate_initial_checkout_settings($settings);
        if (!empty($missingSettings)) {
            $this->fail_wc_checkout_and_back($order, 'Missing required Datafast initial settings', [
                'order_id' => $orderId,
                'missing_settings' => $missingSettings,
            ]);
        }

        $subscriptionId = (int) $order->get_meta('_uix_df_subscription_id');
        if ($subscriptionId <= 0) {
            $fullName = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            $subscriptionId = $this->repo->create_pending([
                'full_name' => $fullName ?: 'Cliente',
                'email' => $order->get_billing_email(),
                'cedula_ruc' => '',
                'plan_slug' => 'woo-order-' . $orderId,
                'plan_title' => 'Orden WooCommerce #' . $orderId,
                'amount' => number_format((float) $order->get_total(), 2, '.', ''),
                'max_retries' => 0,
            ]);
            $order->update_meta_data('_uix_df_subscription_id', $subscriptionId);
            $order->save();
        }

        $returnUrl = add_query_arg([
            'uix_df_return' => 1,
            'subscription_id' => $subscriptionId,
            'order_id' => $orderId,
            'key' => $order->get_order_key(),
        ], home_url('/'));

        $payload = $this->build_phase1_checkout_payload(
            $settings['initial_entity_id'],
            number_format((float) $order->get_total(), 2, '.', ''),
            $order->get_currency() ?: 'USD'
        );

        $client = new UIX_DF_Rec_Datafast_Client($settings);
        $response = $client->create_checkout($payload);

        if (!$response['ok'] || empty($response['body']['id'])) {
            $this->fail_wc_checkout_and_back($order, 'WC checkout creation failed', [
                'order_id' => $orderId,
                'subscription_id' => $subscriptionId,
                'status' => $response['status'] ?? 0,
                'payload' => $payload,
                'parsed_body' => $response['parsed_body'] ?? null,
                'raw_body' => $response['raw_body'] ?? null,
            ]);
        }

        $checkoutId = $response['body']['id'];
        $this->repo->update_checkout($subscriptionId, $checkoutId);

        $widgetJs = rtrim($settings['initial_base_url'], '/') . '/v1/paymentWidgets.js?checkoutId=' . rawurlencode($checkoutId);

        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Pagar orden</title></head><body>';
        echo '<!-- UIX DF PHASE1 BUILD: ' . esc_html(defined('UIX_DF_REC_PLUGIN_BUILD') ? UIX_DF_REC_PLUGIN_BUILD : 'unknown') . ' v' . esc_html(defined('UIX_DF_REC_PLUGIN_VERSION') ? UIX_DF_REC_PLUGIN_VERSION : 'unknown') . ' -->';
        echo '<h2>Finaliza tu pago</h2>';
        echo '<script src="' . esc_url($widgetJs) . '"></script>';
        echo '<form action="' . esc_url($returnUrl) . '" class="paymentWidgets" data-brands="' . esc_attr($this->payment_brands_attr()) . '"></form>';
        echo '<script src="https://www.datafast.com.ec/js/dfAdditionalValidations1.js"></script>';
        echo '</body></html>';
        exit;
    }

    public function handle_return()
    {
        if (!isset($_GET['uix_df_return'])) {
            return;
        }

        $subscriptionId = isset($_GET['subscription_id']) ? (int) $_GET['subscription_id'] : 0;
        $resourcePath = isset($_GET['resourcePath']) ? sanitize_text_field(wp_unslash($_GET['resourcePath'])) : '';

        if ($subscriptionId <= 0 || $resourcePath === '') {
            wp_die('Retorno inválido: falta subscription_id/resourcePath');
        }

        $sub = $this->repo->find($subscriptionId);
        if (!$sub) {
            wp_die('Suscripción no encontrada');
        }

        $settings = $this->settings();
        $entityId = $this->sanitize_entity_id_for_transport($settings['initial_entity_id']);
        $client = new UIX_DF_Rec_Datafast_Client($settings);

        UIX_DF_Rec_Logger::info('Verifying initial payment', [
            'subscription_id' => $subscriptionId,
            'resourcePath' => $resourcePath,
            'base_url' => $settings['initial_base_url'],
            'entity_id' => $entityId,
            'method' => 'GET',
        ]);

        $verification = $client->verify_payment($resourcePath, $entityId);

        if (!$verification['ok']) {
            UIX_DF_Rec_Logger::error('Initial verification failed transport', [
                'subscription_id' => $subscriptionId,
                'verification' => $verification,
            ]);
            wp_die('No se pudo verificar el pago');
        }

        $body = $verification['body'];
        $this->repo->mark_from_result($subscriptionId, $body);

        $isApproved = UIX_DF_Rec_Result_Codes::is_success($body['result']['code'] ?? '');

        $this->repo->add_attempt([
            'subscription_id' => $subscriptionId,
            'idempotency_key' => wp_generate_uuid4(),
            'kind' => 'initial',
            'requested_amount' => (float) $sub['amount'],
            'request_payload_redacted' => ['resourcePath' => $resourcePath, 'entityId' => $entityId],
            'response_payload_redacted' => $body,
            'http_status' => (int) $verification['status'],
            'result_code' => $body['result']['code'] ?? null,
            'result_description' => $body['result']['description'] ?? null,
            'transaction_id' => $body['id'] ?? null,
            'decision' => $isApproved ? 'approved' : 'declined',
        ]);

        UIX_DF_Rec_Logger::info('Initial payment verification result', [
            'subscription_id' => $subscriptionId,
            'approved' => $isApproved,
            'result_code' => $body['result']['code'] ?? null,
            'result_description' => $body['result']['description'] ?? null,
        ]);

        $orderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
        if ($orderId > 0 && function_exists('wc_get_order')) {
            $order = wc_get_order($orderId);
            if ($order) {
                if ($isApproved) {
                    $order->payment_complete($body['id'] ?? '');
                    $order->add_order_note(__('Pago Datafast confirmado.', 'uix-df-rec'));
                } else {
                    $order->update_status('failed', __('Pago Datafast no aprobado.', 'uix-df-rec'));
                }
            }
        }

        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Resultado pago</title></head><body>';
        if ($isApproved) {
            echo '<h2>¡Pago aprobado!</h2><p>Tu pago fue verificado correctamente.</p>';
        } else {
            echo '<h2>Pago no aprobado</h2><p>Resultado: ' . esc_html($body['result']['description'] ?? 'Error de pago') . '</p>';
        }
        echo '</body></html>';
        exit;
    }

    public function handle_test_initial_credentials()
    {
        if (!current_user_can('manage_options')) {
            wp_die('No autorizado');
        }

        check_admin_referer('uix_df_test_initial_credentials', 'uix_df_test_nonce');

        $settings = $this->settings();
        $adminUrl = admin_url('admin.php?page=uix-df-rec');
        $missingSettings = $this->validate_initial_checkout_settings($settings);

        if (!empty($missingSettings)) {
            wp_safe_redirect(add_query_arg([
                'uix_df_probe' => 1,
                'uix_df_probe_status' => 'error',
                'uix_df_probe_message' => rawurlencode('Configuración incompleta: ' . implode(', ', $missingSettings)),
            ], $adminUrl));
            exit;
        }

        $payload = $this->build_phase1_checkout_payload($settings['initial_entity_id'], '1.00', 'USD');
        $client = new UIX_DF_Rec_Datafast_Client($settings);
        $response = $client->create_checkout($payload);

        if (!empty($response['ok']) && !empty($response['body']['id'])) {
            $msg = 'OK checkoutId=' . $response['body']['id'] . ' base_url=' . $settings['initial_base_url'] . ' entityId=' . $payload['entityId'];
            wp_safe_redirect(add_query_arg([
                'uix_df_probe' => 1,
                'uix_df_probe_status' => 'success',
                'uix_df_probe_message' => rawurlencode($msg),
            ], $adminUrl));
            exit;
        }

        $reason = $response['body']['result']['description'] ?? ($response['error'] ?? 'No se pudo validar credenciales.');
        $msg = 'ERROR status=' . (int) ($response['status'] ?? 0) . ' base_url=' . $settings['initial_base_url'] . ' entityId=' . $payload['entityId'] . ' reason=' . $reason;
        wp_safe_redirect(add_query_arg([
            'uix_df_probe' => 1,
            'uix_df_probe_status' => 'error',
            'uix_df_probe_message' => rawurlencode($msg),
        ], $adminUrl));
        exit;
    }

    public function handle_test_initial_verify()
    {
        if (!current_user_can('manage_options')) {
            wp_die('No autorizado');
        }

        check_admin_referer('uix_df_test_initial_verify', 'uix_df_test_verify_nonce');

        $settings = $this->settings();
        $adminUrl = admin_url('admin.php?page=uix-df-rec');
        $resourcePath = isset($_POST['resource_path']) ? sanitize_text_field(wp_unslash($_POST['resource_path'])) : '';

        if ($resourcePath === '') {
            wp_safe_redirect(add_query_arg([
                'uix_df_probe' => 1,
                'uix_df_probe_status' => 'error',
                'uix_df_probe_message' => rawurlencode('Debes ingresar resourcePath para probar verify.'),
            ], $adminUrl));
            exit;
        }

        $entityId = $this->sanitize_entity_id_for_transport($settings['initial_entity_id']);
        $client = new UIX_DF_Rec_Datafast_Client($settings);
        $response = $client->verify_payment($resourcePath, $entityId);

        $status = (int) ($response['status'] ?? 0);
        $msg = 'verify status=' . $status . ' base_url=' . $settings['initial_base_url'] . ' entityId=' . $entityId;

        if ($status < 400 && !empty($response['body']['id'])) {
            $msg .= ' tx=' . $response['body']['id'] . ' result=' . ($response['body']['result']['code'] ?? 'n/a');
            wp_safe_redirect(add_query_arg([
                'uix_df_probe' => 1,
                'uix_df_probe_status' => 'success',
                'uix_df_probe_message' => rawurlencode($msg),
            ], $adminUrl));
            exit;
        }

        $msg .= ' reason=' . ($response['body']['result']['description'] ?? ($response['error'] ?? 'error desconocido'));
        wp_safe_redirect(add_query_arg([
            'uix_df_probe' => 1,
            'uix_df_probe_status' => 'error',
            'uix_df_probe_message' => rawurlencode($msg),
        ], $adminUrl));
        exit;
    }

    public function register_admin_menu()
    {
        add_menu_page('UIX Datafast', 'UIX Datafast', 'manage_options', 'uix-df-rec', [$this, 'render_settings_page']);
    }

    public function register_settings()
    {
        $keys = [
            'uix_df_initial_entity_id',
            'uix_df_initial_bearer_token',
            'uix_df_initial_base_url',
            'uix_df_payment_brands',
            'uix_df_debug_enabled',
        ];

        foreach ($keys as $key) {
            register_setting('uix_df_rec_settings', $key);
        }
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $probeStatus = isset($_GET['uix_df_probe_status']) ? sanitize_text_field(wp_unslash($_GET['uix_df_probe_status'])) : '';
        $probeMessage = isset($_GET['uix_df_probe_message']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['uix_df_probe_message']))) : '';

        ?>
        <div class="wrap">
            <h1>UIX Datafast - FASE 1</h1>
            <div class="notice notice-info"><p><strong>BUILD ACTIVO:</strong> <?php echo esc_html(defined('UIX_DF_REC_PLUGIN_BUILD') ? UIX_DF_REC_PLUGIN_BUILD : 'unknown'); ?> | <strong>VERSIÓN:</strong> <?php echo esc_html(defined('UIX_DF_REC_PLUGIN_VERSION') ? UIX_DF_REC_PLUGIN_VERSION : 'unknown'); ?> | <strong>MAIN FILE:</strong> <code><?php echo esc_html(defined('UIX_DF_REC_PLUGIN_FILE') ? UIX_DF_REC_PLUGIN_FILE : 'unknown'); ?></code></p></div>
            <div class="notice notice-warning"><p><strong>Modo activo:</strong> FASE 1 estricta (payload mínimo de checkout; sin phase2 ni recurrencia).</p></div>

            <?php if ($probeStatus === 'success' && $probeMessage !== '') : ?>
                <div class="notice notice-success"><p><?php echo esc_html($probeMessage); ?></p></div>
            <?php elseif ($probeStatus === 'error' && $probeMessage !== '') : ?>
                <div class="notice notice-error"><p><?php echo esc_html($probeMessage); ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('uix_df_rec_settings'); ?>
                <h2>Primer pago (Fase 1)</h2>
                <table class="form-table">
                    <tr><th>Entity ID</th><td><input class="regular-text" name="uix_df_initial_entity_id" value="<?php echo esc_attr(get_option('uix_df_initial_entity_id', '')); ?>"></td></tr>
                    <tr><th>Bearer Token</th><td><input class="regular-text" name="uix_df_initial_bearer_token" value="<?php echo esc_attr(get_option('uix_df_initial_bearer_token', '')); ?>"></td></tr>
                    <tr><th>Base URL</th><td><input class="regular-text" name="uix_df_initial_base_url" value="<?php echo esc_attr(get_option('uix_df_initial_base_url', 'https://eu-test.oppwa.com')); ?>"></td></tr>
                    <tr><th>Marcas permitidas</th><td><input class="regular-text" name="uix_df_payment_brands" value="<?php echo esc_attr(get_option('uix_df_payment_brands', 'VISA MASTER')); ?>"></td></tr>
                    <tr><th>Debug logs</th><td><label><input type="checkbox" name="uix_df_debug_enabled" value="1" <?php checked(get_option('uix_df_debug_enabled', 1), 1); ?>> Habilitar logs detallados</label></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2>Probar credenciales ahora</h2>
            <p>Prueba Fase 1: <code>POST /v1/checkouts</code> con payload mínimo.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="uix_df_test_initial_credentials">
                <?php wp_nonce_field('uix_df_test_initial_credentials', 'uix_df_test_nonce'); ?>
                <?php submit_button('Probar credenciales ahora', 'secondary', 'submit', false); ?>
            </form>

            <h2>Probar verify con resourcePath</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="uix_df_test_initial_verify">
                <?php wp_nonce_field('uix_df_test_initial_verify', 'uix_df_test_verify_nonce'); ?>
                <p><input class="large-text" name="resource_path" placeholder="/v1/checkouts/{id}/payment?..." required></p>
                <?php submit_button('Probar verify ahora', 'secondary', 'submit', false); ?>
            </form>

            <p>Shortcode: <code>[uix_subscribe_form plan="plan-pro" title="Plan Pro" amount="30.00"]</code></p>
        </div>
        <?php
    }
}
