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
        add_action('admin_post_uix_df_test_initial_credentials_curl', [$this, 'handle_test_initial_credentials_curl']);
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
        $token = (string) $token;
        $token = preg_replace('/\x{FEFF}|\x{200B}|\x{200C}|\x{200D}|\x{00A0}/u', '', $token);
        $token = str_replace(["\r", "\n", "\t"], '', $token);
        $token = trim($token);
        $token = preg_replace('/^Bearer\s+/i', '', $token);

        return $token;
    }

    private function token_tail_masked($token)
    {
        $token = (string) $token;
        if ($token === '') {
            return '';
        }

        $tail = substr($token, -12);
        if ($tail === false) {
            $tail = '';
        }

        $len = strlen($tail);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', $len - 4) . substr($tail, -4);
    }

    public function sanitize_bearer_token_setting($value)
    {
        $sanitized = $this->sanitize_token_for_transport($value);

        UIX_DF_Rec_Logger::info('Initial bearer token sanitized on save', [
            'saved_length' => strlen($sanitized),
            'saved_tail_masked' => $this->token_tail_masked($sanitized),
        ]);

        return $sanitized;
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
            'testMode' => 'EXTERNAL',
        ];
    }

    private function is_verify_auth_error(array $response)
    {
        $status = (int) ($response['status'] ?? 0);
        if ($status === 401 || $status === 403) {
            return true;
        }

        $description = '';
        if (!empty($response['body']['result']['description'])) {
            $description = (string) $response['body']['result']['description'];
        } elseif (!empty($response['raw_body'])) {
            $description = (string) $response['raw_body'];
        }

        $description = strtolower($description);
        return strpos($description, 'authorization failed') !== false || strpos($description, 'invalid authentication information') !== false;
    }

    private function alternate_test_base_url($baseUrl)
    {
        $baseUrl = trim((string) $baseUrl);
        if (stripos($baseUrl, 'eu-test.oppwa.com') !== false) {
            return 'https://test.oppwa.com';
        }
        if (stripos($baseUrl, 'test.oppwa.com') !== false) {
            return 'https://eu-test.oppwa.com';
        }

        return '';
    }

    private function verify_with_all_test_hosts($resourcePath, $entityId, $preferredBaseUrl, array $settings)
    {
        $client = new UIX_DF_Rec_Datafast_Client($settings);
        $hosts = [];
        $preferredBaseUrl = trim((string) $preferredBaseUrl);
        if ($preferredBaseUrl !== '') {
            $hosts[] = rtrim($preferredBaseUrl, '/');
        }

        $configuredBaseUrl = rtrim((string) ($settings['initial_base_url'] ?? ''), '/');
        if ($configuredBaseUrl !== '' && !in_array($configuredBaseUrl, $hosts, true)) {
            $hosts[] = $configuredBaseUrl;
        }

        $altFromPreferred = $this->alternate_test_base_url($preferredBaseUrl);
        if ($altFromPreferred !== '' && !in_array($altFromPreferred, $hosts, true)) {
            $hosts[] = $altFromPreferred;
        }

        $altFromConfigured = $this->alternate_test_base_url($configuredBaseUrl);
        if ($altFromConfigured !== '' && !in_array($altFromConfigured, $hosts, true)) {
            $hosts[] = $altFromConfigured;
        }

        $attempts = [];
        $best = null;

        foreach ($hosts as $host) {
            $response = $client->verify_payment_with_base_url($host, $resourcePath, $entityId);
            $attempt = [
                'host' => $host,
                'response' => $response,
                'ok' => !empty($response['ok']) && (int) ($response['status'] ?? 0) === 200 && !empty($response['body']),
            ];
            $attempts[] = $attempt;

            if ($attempt['ok']) {
                $best = $attempt;
                break;
            }

            if ($best === null) {
                $best = $attempt;
            } elseif ($this->is_verify_auth_error($best['response']) && !$this->is_verify_auth_error($response)) {
                $best = $attempt;
            }
        }

        return [
            'attempts' => $attempts,
            'best' => $best,
        ];
    }

    private function resolve_verify_resource_path_from_return(array $query, $entityId)
    {
        $resourcePath = isset($query['resourcePath']) ? sanitize_text_field(wp_unslash($query['resourcePath'])) : '';
        $id = isset($query['id']) ? sanitize_text_field(wp_unslash($query['id'])) : '';

        if ($resourcePath !== '') {
            return [
                'source' => 'resourcePath',
                'resource_path' => $resourcePath,
                'id' => $id,
            ];
        }

        if ($id !== '') {
            return [
                'source' => 'id',
                'resource_path' => '/v1/checkouts/' . rawurlencode($id) . '/payment',
                'id' => $id,
            ];
        }

        return [
            'source' => 'none',
            'resource_path' => '',
            'id' => '',
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
        UIX_DF_Rec_Logger::info('checkout_created', [
            'flow' => 'shortcode',
            'subscription_id' => $subscriptionId,
            'checkout_id' => $checkoutId,
            'base_url' => $settings['initial_base_url'],
            'entity_id' => $payload['entityId'],
        ]);
        $this->repo->update_checkout($subscriptionId, $checkoutId, $settings['initial_base_url']);

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
        UIX_DF_Rec_Logger::info('checkout_created', [
            'flow' => 'wc',
            'order_id' => $orderId,
            'subscription_id' => $subscriptionId,
            'checkout_id' => $checkoutId,
            'base_url' => $settings['initial_base_url'],
            'entity_id' => $payload['entityId'],
        ]);
        $this->repo->update_checkout($subscriptionId, $checkoutId, $settings['initial_base_url']);
        $order->update_meta_data('_uix_df_checkout_base_url', $settings['initial_base_url']);
        $order->save();

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
        $orderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;

        if ($subscriptionId <= 0) {
            wp_die('Retorno inválido: falta subscription_id');
        }

        $sub = $this->repo->find($subscriptionId);
        if (!$sub) {
            wp_die('Suscripción no encontrada');
        }

        $settings = $this->settings();
        $entityId = $this->sanitize_entity_id_for_transport($settings['initial_entity_id']);
        $verifyTarget = $this->resolve_verify_resource_path_from_return($_GET, $entityId);

        UIX_DF_Rec_Logger::info('return_received', [
            'subscription_id' => $subscriptionId,
            'order_id' => $orderId,
            'return_params' => [
                'resourcePath' => isset($_GET['resourcePath']) ? sanitize_text_field(wp_unslash($_GET['resourcePath'])) : '',
                'id' => isset($_GET['id']) ? sanitize_text_field(wp_unslash($_GET['id'])) : '',
                'key' => isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '',
            ],
            'verify_param_source' => $verifyTarget['source'],
        ]);

        if ($verifyTarget['resource_path'] === '') {
            UIX_DF_Rec_Logger::error('verification_error', [
                'subscription_id' => $subscriptionId,
                'order_id' => $orderId,
                'reason' => 'No resourcePath ni id en retorno',
            ]);
            wp_die('Retorno inválido: falta resourcePath o id.');
        }

        $preferredBaseUrl = '';
        if (!empty($sub['checkout_resource_path'])) {
            $preferredBaseUrl = (string) $sub['checkout_resource_path'];
        }
        if ($preferredBaseUrl === '' && $orderId > 0 && function_exists('wc_get_order')) {
            $orderMeta = wc_get_order($orderId);
            if ($orderMeta) {
                $preferredBaseUrl = (string) $orderMeta->get_meta('_uix_df_checkout_base_url');
            }
        }
        if ($preferredBaseUrl === '') {
            $preferredBaseUrl = (string) $settings['initial_base_url'];
        }

        $verifyFullUrl = rtrim($preferredBaseUrl, '/') . $verifyTarget['resource_path'];
        UIX_DF_Rec_Logger::info('verify_request', [
            'subscription_id' => $subscriptionId,
            'order_id' => $orderId,
            'verify_method' => 'GET',
            'verify_base_url' => $preferredBaseUrl,
            'verify_full_url' => $verifyFullUrl,
            'verify_entityId' => $entityId,
            'verify_param_source' => $verifyTarget['source'],
            'return_id' => $verifyTarget['id'],
            'return_resourcePath' => isset($_GET['resourcePath']) ? sanitize_text_field(wp_unslash($_GET['resourcePath'])) : '',
        ]);

        $verifyRun = $this->verify_with_all_test_hosts($verifyTarget['resource_path'], $entityId, $preferredBaseUrl, $settings);
        $bestAttempt = $verifyRun['best'];
        $verification = is_array($bestAttempt) ? $bestAttempt['response'] : ['ok' => false, 'status' => 0, 'body' => [], 'raw_body' => '', 'error' => 'No verify host attempted'];
        $usedVerifyHost = is_array($bestAttempt) ? (string) $bestAttempt['host'] : '';

        UIX_DF_Rec_Logger::info('verify_response', [
            'subscription_id' => $subscriptionId,
            'order_id' => $orderId,
            'verify_host_used' => $usedVerifyHost,
            'verify_attempts' => $verifyRun['attempts'],
            'verify_status' => (int) ($verification['status'] ?? 0),
            'verify_body' => $verification['body'] ?? null,
            'verify_raw_body' => $verification['raw_body'] ?? null,
            'verify_error' => $verification['error'] ?? '',
        ]);

        if (!$verification['ok']) {
            UIX_DF_Rec_Logger::error('verification_error', [
                'subscription_id' => $subscriptionId,
                'order_id' => $orderId,
                'verify_host_used' => $usedVerifyHost,
                'verification' => $verification,
            ]);

            if ($orderId > 0 && function_exists('wc_get_order')) {
                $order = wc_get_order($orderId);
                if ($order) {
                    $order->update_status('on-hold', __('Pago enviado. Falló la verificación del resultado en backend.', 'uix-df-rec'));
                    $order->add_order_note(__('Datafast verify backend falló (transporte/auth). Revisar logs.', 'uix-df-rec'));
                }
            }

            wp_die('No se pudo verificar el pago');
        }

        $body = $verification['body'];
        $this->repo->mark_from_result($subscriptionId, $body);

        $resultCode = $body['result']['code'] ?? '';
        $resultDescription = $body['result']['description'] ?? '';
        $verifyStatus = (int) ($verification['status'] ?? 0);
        $isVerifyBackendError = ($verifyStatus === 403 && stripos((string) $resultDescription, 'user authorization failed') !== false);
        $isApproved = UIX_DF_Rec_Result_Codes::is_success($resultCode);

        $this->repo->add_attempt([
            'subscription_id' => $subscriptionId,
            'idempotency_key' => wp_generate_uuid4(),
            'kind' => 'initial',
            'requested_amount' => (float) $sub['amount'],
            'request_payload_redacted' => ['resourcePath' => $verifyTarget['resource_path'], 'entityId' => $entityId],
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
            'result_code' => $resultCode,
            'result_description' => $resultDescription,
            'verify_backend_error' => $isVerifyBackendError,
            'verify_host_used' => $usedVerifyHost,
        ]);

        if ($orderId > 0 && function_exists('wc_get_order')) {
            $order = wc_get_order($orderId);
            if ($order) {
                if ($isApproved) {
                    $order->payment_complete($body['id'] ?? '');
                    $order->add_order_note(__('Pago Datafast confirmado.', 'uix-df-rec'));
                    UIX_DF_Rec_Logger::info('payment_marked_success', [
                        'subscription_id' => $subscriptionId,
                        'order_id' => $orderId,
                        'result_code' => $resultCode,
                    ]);
                } elseif ($isVerifyBackendError) {
                    $order->update_status('on-hold', __('Pago enviado. Falló la verificación del resultado en backend.', 'uix-df-rec'));
                    $order->add_order_note(__('Datafast verify respondió 403 user authorization failed. Revisar credenciales de verify.', 'uix-df-rec'));
                    UIX_DF_Rec_Logger::error('verification_error', [
                        'subscription_id' => $subscriptionId,
                        'order_id' => $orderId,
                        'verify_host_used' => $usedVerifyHost,
                        'result_code' => $resultCode,
                        'result_description' => $resultDescription,
                    ]);
                } else {
                    $order->update_status('failed', __('Pago Datafast no aprobado.', 'uix-df-rec'));
                    UIX_DF_Rec_Logger::info('payment_marked_failed', [
                        'subscription_id' => $subscriptionId,
                        'order_id' => $orderId,
                        'result_code' => $resultCode,
                        'result_description' => $resultDescription,
                    ]);
                }
            }
        }

        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Resultado pago</title></head><body>';
        if ($isApproved) {
            echo '<h2>¡Pago aprobado!</h2><p>Tu pago fue verificado correctamente.</p>';
        } elseif ($isVerifyBackendError) {
            echo '<h2>Verificación pendiente</h2><p>El pago fue enviado, pero la verificación del resultado falló en el backend. Host: ' . esc_html($usedVerifyHost) . ' | HTTP: ' . esc_html((string) $verifyStatus) . ' | Detalle: ' . esc_html($resultDescription) . '</p>';
        } else {
            echo '<h2>Pago no aprobado</h2><p>Host: ' . esc_html($usedVerifyHost) . ' | HTTP: ' . esc_html((string) $verifyStatus) . ' | Resultado: ' . esc_html($body['result']['description'] ?? 'Error de pago') . '</p>';
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

    private function run_phase1_auth_diagnostic_request($baseUrl, array $settings)
    {
        $entityId = $this->sanitize_entity_id_for_transport($settings['initial_entity_id']);
        $token = $this->sanitize_token_for_transport($settings['initial_bearer_token']);
        $endpoint = rtrim((string) $baseUrl, '/') . '/v1/checkouts';
        $merchantTransactionId = 'uix_diag_' . gmdate('YmdHis') . '_' . wp_generate_password(6, false, false);
        $payload = [
            'entityId' => $entityId,
            'amount' => '1.00',
            'currency' => 'USD',
            'paymentType' => 'DB',
            'testMode' => 'EXTERNAL',
            'merchantTransactionId' => $merchantTransactionId,
        ];

        $body = http_build_query($payload);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/x-www-form-urlencoded',
        ];

        $transport = 'wp_remote_post';
        $status = 0;
        $rawBody = '';
        $error = '';
        $startedAt = microtime(true);

        if (function_exists('curl_init')) {
            $transport = 'curl';
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $raw = curl_exec($ch);
            if ($raw === false) {
                $error = (string) curl_error($ch);
                $rawBody = '';
            } else {
                $rawBody = (string) $raw;
            }
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $response = wp_remote_post($endpoint, [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => $body,
            ]);
            if (is_wp_error($response)) {
                $error = $response->get_error_message();
            } else {
                $status = (int) wp_remote_retrieve_response_code($response);
                $rawBody = (string) wp_remote_retrieve_body($response);
            }
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $parsed = json_decode($rawBody, true);
        $resultCode = is_array($parsed) ? ($parsed['result']['code'] ?? '') : '';
        $resultDescription = is_array($parsed) ? ($parsed['result']['description'] ?? '') : '';
        $tokenHash8 = $token === '' ? '' : substr(hash('sha256', $token), 0, 8);

        $curlCommandRedacted = sprintf(
            "curl -X POST '%s' -H 'Authorization: Bearer <redacted len=%d hash8=%s>' -H 'Content-Type: application/x-www-form-urlencoded' --data '%s'",
            $endpoint,
            strlen($token),
            $tokenHash8,
            $body
        );

        return [
            'endpoint' => $endpoint,
            'base_url' => rtrim((string) $baseUrl, '/'),
            'entity_id' => $entityId,
            'token_length' => strlen($token),
            'token_hash8' => $tokenHash8,
            'merchantTransactionId' => $merchantTransactionId,
            'curl_command_redacted' => $curlCommandRedacted,
            'status' => $status,
            'raw_body' => $rawBody,
            'result_code' => $resultCode,
            'result_description' => $resultDescription,
            'transport' => $transport,
            'error' => $error,
            'duration_ms' => $durationMs,
        ];
    }

    public function handle_test_initial_credentials_curl()
    {
        if (!current_user_can('manage_options')) {
            wp_die('No autorizado');
        }

        check_admin_referer('uix_df_test_initial_credentials_curl', 'uix_df_test_curl_nonce');

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

        $euResult = $this->run_phase1_auth_diagnostic_request('https://eu-test.oppwa.com', $settings);
        $testResult = $this->run_phase1_auth_diagnostic_request('https://test.oppwa.com', $settings);

        UIX_DF_Rec_Logger::info('Phase1 auth diagnostic result', [
            'eu_test' => $euResult,
            'test' => $testResult,
        ]);

        $bothAuthRejected = (
            (int) $euResult['status'] === 401 && stripos((string) $euResult['raw_body'], 'invalid authentication information') !== false &&
            (int) $testResult['status'] === 401 && stripos((string) $testResult['raw_body'], 'invalid authentication information') !== false
        );

        $selectedBaseUrl = '';
        if ((int) $euResult['status'] < 400 && strpos((string) $euResult['raw_body'], '"id"') !== false) {
            $selectedBaseUrl = 'https://eu-test.oppwa.com';
        } elseif ((int) $testResult['status'] < 400 && strpos((string) $testResult['raw_body'], '"id"') !== false) {
            $selectedBaseUrl = 'https://test.oppwa.com';
        }

        if ($selectedBaseUrl !== '' && $selectedBaseUrl !== (string) get_option('uix_df_initial_base_url', '')) {
            update_option('uix_df_initial_base_url', $selectedBaseUrl);
        }

        $resultPayload = [
            'eu_test' => $euResult,
            'test' => $testResult,
            'selected_base_url' => $selectedBaseUrl,
            'both_auth_rejected_message' => $bothAuthRejected ? 'El plugin está enviando correctamente la solicitud, pero Datafast está rechazando las credenciales. El problema ya no parece ser de Fase 2 ni del widget.' : '',
        ];

        set_transient('uix_df_diag_result_' . get_current_user_id(), $resultPayload, 300);

        $summary = $selectedBaseUrl !== ''
            ? ('Diagnóstico cURL: endpoint funcional detectado y configurado automáticamente: ' . $selectedBaseUrl)
            : 'Diagnóstico cURL: ningún endpoint devolvió éxito.';

        wp_safe_redirect(add_query_arg([
            'uix_df_diag' => 1,
            'uix_df_probe_status' => $selectedBaseUrl !== '' ? 'success' : 'error',
            'uix_df_probe_message' => rawurlencode($summary),
        ], $adminUrl));
        exit;
    }

    public function register_admin_menu()
    {
        add_menu_page('UIX Datafast', 'UIX Datafast', 'manage_options', 'uix-df-rec', [$this, 'render_settings_page']);
    }

    public function register_settings()
    {
        register_setting('uix_df_rec_settings', 'uix_df_initial_entity_id');
        register_setting('uix_df_rec_settings', 'uix_df_initial_bearer_token', [
            'sanitize_callback' => [$this, 'sanitize_bearer_token_setting'],
        ]);
        register_setting('uix_df_rec_settings', 'uix_df_initial_base_url');
        register_setting('uix_df_rec_settings', 'uix_df_payment_brands');
        register_setting('uix_df_rec_settings', 'uix_df_debug_enabled');
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

            <?php
            $diag = get_transient('uix_df_diag_result_' . get_current_user_id());
            if (is_array($diag)) :
            ?>
                <h2>Diagnóstico: Probar credenciales con cURL real</h2>
                <?php if (!empty($diag['both_auth_rejected_message'])) : ?>
                    <div class="notice notice-error"><p><strong><?php echo esc_html($diag['both_auth_rejected_message']); ?></strong></p></div>
                <?php endif; ?>
                <?php if (!empty($diag['selected_base_url'])) : ?>
                    <div class="notice notice-success"><p>Se configuró automáticamente <code>initial_base_url</code> en: <code><?php echo esc_html($diag['selected_base_url']); ?></code></p></div>
                <?php endif; ?>
                <h3>Resultado EU TEST</h3>
                <table class="widefat striped">
                    <tbody>
                        <tr><th>Endpoint probado</th><td><code><?php echo esc_html($diag['eu_test']['endpoint'] ?? ''); ?></code></td></tr>
                        <tr><th>Comando cURL ejecutado (redactado)</th><td><code><?php echo esc_html($diag['eu_test']['curl_command_redacted'] ?? ''); ?></code></td></tr>
                        <tr><th>HTTP status</th><td><?php echo esc_html((string) ($diag['eu_test']['status'] ?? '')); ?></td></tr>
                        <tr><th>result.code</th><td><?php echo esc_html((string) ($diag['eu_test']['result_code'] ?? '')); ?></td></tr>
                        <tr><th>result.description</th><td><?php echo esc_html((string) ($diag['eu_test']['result_description'] ?? '')); ?></td></tr>
                        <tr><th>Error cURL / transporte</th><td><?php echo esc_html((string) ($diag['eu_test']['error'] ?? '')); ?></td></tr>
                        <tr><th>Tiempo ejecución (ms)</th><td><?php echo esc_html((string) ($diag['eu_test']['duration_ms'] ?? '')); ?></td></tr>
                        <tr><th>Transporte</th><td><?php echo esc_html((string) ($diag['eu_test']['transport'] ?? '')); ?></td></tr>
                        <tr><th>Entity ID</th><td><code><?php echo esc_html((string) ($diag['eu_test']['entity_id'] ?? '')); ?></code></td></tr>
                        <tr><th>Token len/hash8</th><td><?php echo esc_html((string) ($diag['eu_test']['token_length'] ?? '0')); ?> / <?php echo esc_html((string) ($diag['eu_test']['token_hash8'] ?? '')); ?></td></tr>
                        <tr><th>Raw body completo</th><td><pre><?php echo esc_html((string) ($diag['eu_test']['raw_body'] ?? '')); ?></pre></td></tr>
                    </tbody>
                </table>

                <h3>Resultado TEST</h3>
                <table class="widefat striped">
                    <tbody>
                        <tr><th>Endpoint probado</th><td><code><?php echo esc_html($diag['test']['endpoint'] ?? ''); ?></code></td></tr>
                        <tr><th>Comando cURL ejecutado (redactado)</th><td><code><?php echo esc_html($diag['test']['curl_command_redacted'] ?? ''); ?></code></td></tr>
                        <tr><th>HTTP status</th><td><?php echo esc_html((string) ($diag['test']['status'] ?? '')); ?></td></tr>
                        <tr><th>result.code</th><td><?php echo esc_html((string) ($diag['test']['result_code'] ?? '')); ?></td></tr>
                        <tr><th>result.description</th><td><?php echo esc_html((string) ($diag['test']['result_description'] ?? '')); ?></td></tr>
                        <tr><th>Error cURL / transporte</th><td><?php echo esc_html((string) ($diag['test']['error'] ?? '')); ?></td></tr>
                        <tr><th>Tiempo ejecución (ms)</th><td><?php echo esc_html((string) ($diag['test']['duration_ms'] ?? '')); ?></td></tr>
                        <tr><th>Transporte</th><td><?php echo esc_html((string) ($diag['test']['transport'] ?? '')); ?></td></tr>
                        <tr><th>Entity ID</th><td><code><?php echo esc_html((string) ($diag['test']['entity_id'] ?? '')); ?></code></td></tr>
                        <tr><th>Token len/hash8</th><td><?php echo esc_html((string) ($diag['test']['token_length'] ?? '0')); ?> / <?php echo esc_html((string) ($diag['test']['token_hash8'] ?? '')); ?></td></tr>
                        <tr><th>Raw body completo</th><td><pre><?php echo esc_html((string) ($diag['test']['raw_body'] ?? '')); ?></pre></td></tr>
                    </tbody>
                </table>

                <h3>Resumen final</h3>
                <p>
                    <?php
                    $euOk = (int) ($diag['eu_test']['status'] ?? 0) < 400 && !empty($diag['eu_test']['result_code']);
                    $testOk = (int) ($diag['test']['status'] ?? 0) < 400 && !empty($diag['test']['result_code']);
                    if ($euOk && !$testOk) {
                        echo 'Funciona: EU TEST. Falla: TEST.';
                    } elseif (!$euOk && $testOk) {
                        echo 'Funciona: TEST. Falla: EU TEST.';
                    } elseif ($euOk && $testOk) {
                        echo 'Funcionan ambos endpoints.';
                    } else {
                        echo 'Fallan ambos endpoints. Revisa status/body/result.description de cada uno.';
                    }
                    ?>
                </p>
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

            <h2>Probar credenciales con cURL real</h2>
            <p>Ejecuta dos pruebas server-to-server con payload mínimo + testMode=EXTERNAL: primero <code>eu-test</code> y luego <code>test</code>.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="uix_df_test_initial_credentials_curl">
                <?php wp_nonce_field('uix_df_test_initial_credentials_curl', 'uix_df_test_curl_nonce'); ?>
                <?php submit_button('Probar credenciales con cURL real', 'secondary', 'submit', false); ?>
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
