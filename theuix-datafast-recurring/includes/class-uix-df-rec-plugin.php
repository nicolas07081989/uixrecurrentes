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

        add_action('init', [$this, 'register_shortcode']);
        add_action('admin_post_nopriv_uix_df_create_checkout', [$this, 'handle_create_checkout']);
        add_action('admin_post_uix_df_create_checkout', [$this, 'handle_create_checkout']);
        add_action('template_redirect', [$this, 'handle_wc_checkout']);
        add_action('template_redirect', [$this, 'handle_return']);

        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        add_action('uix_df_recurring_charge_runner', [$this, 'run_recurring_runner']);

        add_action('woocommerce_loaded', [$this, 'bootstrap_wc_gateway']);
        add_action('plugins_loaded', [$this, 'maybe_show_woocommerce_notice'], 20);
        if (class_exists('WC_Payment_Gateway')) {
            $this->bootstrap_wc_gateway();
        }

        add_filter('woocommerce_checkout_fields', [$this, 'add_checkout_identification_field']);
        add_action('woocommerce_checkout_create_order', [$this, 'save_checkout_identification_field'], 20, 2);

        UIX_DF_Rec_DB::schedule_events();
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
            echo '<div class="notice notice-warning"><p><strong>TheUIX Datafast Recurring:</strong> WooCommerce no está activo. Actívalo para usar la pasarela de pago.</p></div>';
        });
    }

    public function bootstrap_wc_gateway()
    {
        if (!class_exists('WC_Payment_Gateway')) {
            return;
        }

        $this->load_wc_gateway_class();
        add_filter('woocommerce_payment_gateways', [$this, 'register_wc_gateway']);
    }

    private function load_wc_gateway_class()
    {
        if (!class_exists('UIX_DF_Rec_WC_Gateway')) {
            require_once UIX_DF_REC_PLUGIN_DIR . 'includes/class-uix-df-rec-wc-gateway.php';
        }
    }

    private function payment_brands_attr()
    {
        $brandsRaw = trim((string) get_option('uix_df_payment_brands', 'VISA MASTER AMEX DINERS DISCOVER'));
        if ($brandsRaw === '') {
            $brandsRaw = 'VISA MASTER AMEX DINERS DISCOVER';
        }

        $brands = preg_split('/\s+/', strtoupper($brandsRaw));
        $brands = array_filter(array_unique(array_map('sanitize_text_field', $brands)));

        return implode(' ', $brands);
    }

    public function register_wc_gateway($methods)
    {
        if (!class_exists('WC_Payment_Gateway')) {
            return $methods;
        }

        $this->load_wc_gateway_class();

        if (class_exists('UIX_DF_Rec_WC_Gateway') && !in_array('UIX_DF_Rec_WC_Gateway', $methods, true)) {
            $methods[] = 'UIX_DF_Rec_WC_Gateway';
        }

        return $methods;
    }

    public function add_checkout_identification_field($fields)
    {
        if (!isset($fields['billing'])) {
            return $fields;
        }

        $fields['billing']['uix_df_cedula_ruc'] = [
            'type' => 'text',
            'label' => __('Cédula / RUC', 'uix-df-rec'),
            'required' => true,
            'class' => ['form-row-wide'],
            'priority' => 125,
            'clear' => true,
        ];

        return $fields;
    }

    public function save_checkout_identification_field($order, $data)
    {
        $cedula = '';

        if (isset($data['uix_df_cedula_ruc'])) {
            $cedula = sanitize_text_field(wp_unslash($data['uix_df_cedula_ruc']));
        }

        if ($cedula === '' && isset($_POST['uix_df_cedula_ruc'])) {
            $cedula = sanitize_text_field(wp_unslash($_POST['uix_df_cedula_ruc']));
        }

        if ($cedula !== '') {
            $order->update_meta_data('_uix_df_cedula_ruc', $cedula);
        }
    }

    private function get_order_identification($order)
    {
        $cedula = trim((string) $order->get_meta('_uix_df_cedula_ruc'));
        if ($cedula === '') {
            $cedula = trim((string) $order->get_meta('df_cedula'));
        }

        return $cedula;
    }

    private function should_allow_test_identification_fallback(array $settings)
    {
        return !empty($settings['initial_test_mode_enabled']) && !empty($settings['allow_test_placeholders']);
    }

    private function should_enforce_strict_phase2_required_fields(array $settings)
    {
        return !empty($settings['strict_phase2_required']);
    }

    private function normalize_identification_doc_id($value)
    {
        $digitsOnly = preg_replace('/\D+/', '', (string) $value);
        $digitsOnly = (string) $digitsOnly;
        if ($digitsOnly === '') {
            return '';
        }

        if (strlen($digitsOnly) > 10) {
            return substr($digitsOnly, 0, 10);
        }

        return str_pad($digitsOnly, 10, '0', STR_PAD_LEFT);
    }

    private function validate_required_payload_fields(array $payload, array $requiredKeys)
    {
        $missing = [];
        foreach ($requiredKeys as $requiredKey) {
            if (!array_key_exists($requiredKey, $payload) || trim((string) $payload[$requiredKey]) === '') {
                $missing[] = $requiredKey;
            }
        }

        return $missing;
    }

    private function resolve_customer_name_parts($firstNameRaw, $lastNameRaw)
    {
        $firstNameRaw = trim((string) $firstNameRaw);
        $lastNameRaw = trim((string) $lastNameRaw);

        $firstParts = preg_split('/\s+/', $firstNameRaw);
        $firstParts = array_values(array_filter($firstParts));

        $givenName = isset($firstParts[0]) ? $firstParts[0] : $firstNameRaw;
        $middleName = isset($firstParts[1]) ? $firstParts[1] : $givenName;

        return [
            'given' => $givenName,
            'middle' => $middleName,
            'surname' => $lastNameRaw,
        ];
    }

    private function remove_empty_payload_fields(array $payload)
    {
        $clean = [];
        foreach ($payload as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === '' || $value === null) {
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    private function fail_wc_checkout_and_back($order, $message, array $logContext = [])
    {
        UIX_DF_Rec_Logger::error($message, $logContext);

        if ($order && method_exists($order, 'add_order_note')) {
            $note = isset($logContext['reason']) ? $logContext['reason'] : $message;
            $order->add_order_note('Datafast checkout error: ' . wc_clean((string) $note));
        }

        wc_add_notice(__('No se pudo inicializar el pago con Datafast.', 'uix-df-rec'), 'error');
        wp_safe_redirect($order->get_checkout_payment_url());
        exit;
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
            'amount' => '10.00',
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
            <p><label>Cédula/RUC<br><input type="text" name="cedula_ruc" required></label></p>
            <p><button type="submit">Suscribirme y pagar</button></p>
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
        $cedula = sanitize_text_field(wp_unslash($_POST['cedula_ruc'] ?? ''));
        $planSlug = sanitize_text_field(wp_unslash($_POST['plan_slug'] ?? ''));
        $planTitle = sanitize_text_field(wp_unslash($_POST['plan_title'] ?? ''));
        $amount = number_format((float) ($_POST['amount'] ?? 0), 2, '.', '');

        if (!$fullName || !$email || !$cedula || $amount <= 0) {
            wp_die('Datos inválidos');
        }

        $subscriptionId = $this->repo->create_pending([
            'full_name' => $fullName,
            'email' => $email,
            'cedula_ruc' => $cedula,
            'plan_slug' => $planSlug,
            'plan_title' => $planTitle,
            'amount' => $amount,
            'max_retries' => (int) get_option('uix_df_default_max_retries', 3),
        ]);

        $settings = $this->settings();
        UIX_DF_Rec_Logger::info('Initial shortcode checkout requested', ['plan' => $planSlug, 'amount' => $amount, 'email' => $email]);
        $client = new UIX_DF_Rec_Datafast_Client($settings);
        $returnUrl = add_query_arg([
            'uix_df_return' => 1,
            'subscription_id' => $subscriptionId,
        ], home_url('/'));

        $nameParts = preg_split('/\s+/', trim($fullName), 2);
        $payload = [
            'entityId' => $settings['initial_entity_id'],
            'amount' => $amount,
            'currency' => 'USD',
            'paymentType' => 'DB',
            'createRegistration' => 'true',
            'customer.givenName' => $nameParts[0] ?? $fullName,
            'customer.surname' => $nameParts[1] ?? 'Cliente',
            'customer.email' => $email,
            'customer.identificationDocType' => 'IDCARD',
            'customer.identificationDocId' => $cedula,
            'merchantTransactionId' => 'uixshort_' . $subscriptionId . '_' . gmdate('YmdHis'),
            'customParameters[SHOPPER_VERSIONDF]' => '2',
            'cart.items[0].name' => $planTitle,
            'cart.items[0].price' => $amount,
            'cart.items[0].quantity' => '1',
            'cart.items[0].tax' => '0.00',
        ];

        if (!empty($settings['initial_test_mode_enabled'])) {
            $payload['testMode'] = 'EXTERNAL';
        }

        UIX_DF_Rec_Logger::info('Creating initial checkout (shortcode)', ['subscription_id' => $subscriptionId, 'payload' => $payload]);
        $response = $client->create_checkout($payload);
        if (!$response['ok'] || empty($response['body']['id'])) {
            UIX_DF_Rec_Logger::error('Checkout creation failed (shortcode)', [
                'subscription_id' => $subscriptionId,
                'status' => $response['status'] ?? 0,
                'raw_body' => $response['raw_body'] ?? null,
                'parsed_body' => $response['parsed_body'] ?? null,
                'payload' => $payload,
            ]);
            wp_die('No se pudo crear checkout. Revisa configuración Datafast.');
        }

        $checkoutId = $response['body']['id'];
        $this->repo->update_checkout($subscriptionId, $checkoutId);

        $widgetJs = rtrim($settings['initial_base_url'], '/') . '/v1/paymentWidgets.js?checkoutId=' . rawurlencode($checkoutId);

        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Pagar suscripción</title></head><body>';
        echo '<h2>Finaliza tu pago</h2>';
        echo '<script src="' . esc_url($widgetJs) . '"></script>';
        echo '<form action="' . esc_url($returnUrl) . '" class="paymentWidgets" data-brands="' . esc_attr($this->payment_brands_attr()) . '" data-create-registration="true"></form>';
        echo '<script src="https://www.datafast.com.ec/js/dfAdditionalValidations1.js"></script>';
        echo '</body></html>';
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

        $cedulaRaw = $this->get_order_identification($order);
        $cedula = $this->normalize_identification_doc_id($cedulaRaw);
        if ($cedula === '') {
            if ($this->should_allow_test_identification_fallback($settings)) {
                $cedula = '9999999999';
                $order->add_order_note('Datafast test mode: usando cédula de fallback 9999999999 por falta de dato en orden.');
                UIX_DF_Rec_Logger::info('Using test identification fallback', ['order_id' => $orderId]);
            } else {
                $this->fail_wc_checkout_and_back($order, 'Missing order identification', [
                    'order_id' => $orderId,
                    'reason' => 'Falta Cédula/RUC en la orden (_uix_df_cedula_ruc).',
                ]);
            }
        }

        $subscriptionId = (int) $order->get_meta('_uix_df_subscription_id');
        if ($subscriptionId <= 0) {
            $fullName = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            $subscriptionId = $this->repo->create_pending([
                'full_name' => $fullName ?: 'Cliente',
                'email' => $order->get_billing_email(),
                'cedula_ruc' => $cedula,
                'plan_slug' => 'woo-order-' . $orderId,
                'plan_title' => 'Orden WooCommerce #' . $orderId,
                'amount' => number_format((float) $order->get_total(), 2, '.', ''),
                'max_retries' => (int) get_option('uix_df_default_max_retries', 3),
            ]);
            $order->update_meta_data('_uix_df_subscription_id', $subscriptionId);
            $order->save();
        }

        UIX_DF_Rec_Logger::info('WC order checkout requested', ['order_id' => $orderId, 'order_total' => $order->get_total(), 'subscription_id' => $subscriptionId]);

        $client = new UIX_DF_Rec_Datafast_Client($settings);

        $returnUrl = add_query_arg([
            'uix_df_return' => 1,
            'subscription_id' => $subscriptionId,
            'order_id' => $orderId,
            'key' => $order->get_order_key(),
        ], home_url('/'));

        $isTestMode = !empty($settings['initial_test_mode_enabled']);

        $billingState = $order->get_billing_state() ?: $order->get_shipping_state();
        $billingCountry = $order->get_billing_country() ?: $order->get_shipping_country();
        $billingCity = $order->get_billing_city() ?: $order->get_shipping_city();
        $billingStreet = $order->get_billing_address_1();
        $billingPostcode = $order->get_billing_postcode();
        $shippingStreet = $order->get_shipping_address_1() ?: $billingStreet;
        $shippingCountry = $order->get_shipping_country() ?: $billingCountry;
        $customerPhone = $order->get_billing_phone();
        $customerIp = $order->get_customer_ip_address();
        if (trim((string) $customerIp) === '' && isset($_SERVER['REMOTE_ADDR'])) {
            $customerIp = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        if ($this->should_allow_test_identification_fallback($settings)) {
            $customerPhone = trim((string) $customerPhone) !== '' ? $customerPhone : '0999999999';
            $billingStreet = trim((string) $billingStreet) !== '' ? $billingStreet : 'N/A';
            $billingCity = trim((string) $billingCity) !== '' ? $billingCity : 'Quito';
            $billingState = trim((string) $billingState) !== '' ? $billingState : 'Pichincha';
            $billingCountry = trim((string) $billingCountry) !== '' ? $billingCountry : 'EC';
            $billingPostcode = trim((string) $billingPostcode) !== '' ? $billingPostcode : '170150';
            $shippingStreet = trim((string) $shippingStreet) !== '' ? $shippingStreet : 'N/A';
            $shippingCountry = trim((string) $shippingCountry) !== '' ? $shippingCountry : 'EC';
        }

        $amount = number_format((float) $order->get_total(), 2, '.', '');
        $baseImp = number_format((float) ($order->get_total() - $order->get_total_tax()), 2, '.', '');
        $tax = number_format((float) $order->get_total_tax(), 2, '.', '');
        $merchantCustomerId = (string) ($order->get_customer_id() ?: $order->get_billing_email() ?: ('guest-' . $orderId));
        $names = $this->resolve_customer_name_parts($order->get_billing_first_name(), $order->get_billing_last_name());

        $payload = [
            'entityId' => $settings['initial_entity_id'],
            'amount' => $amount,
            'currency' => $order->get_currency() ?: 'USD',
            'paymentType' => 'DB',
            'createRegistration' => 'true',
            'customer.givenName' => $names['given'],
            'customer.middleName' => $names['middle'],
            'customer.surname' => $names['surname'],
            'customer.ip' => $customerIp,
            'customer.merchantCustomerId' => $merchantCustomerId,
            'merchantTransactionId' => 'uixdf_' . $orderId . '_' . gmdate('YmdHis'),
            'customer.email' => $order->get_billing_email(),
            'customer.identificationDocType' => 'IDCARD',
            'customer.identificationDocId' => $cedula,
            'customer.phone' => $customerPhone,
            'shipping.street1' => $shippingStreet,
            'billing.street1' => $billingStreet,
            'shipping.country' => $shippingCountry,
            'billing.country' => $billingCountry,
            'billing.city' => $billingCity,
            'billing.state' => $billingState,
            'billing.postcode' => $billingPostcode,
            'customParameters[SHOPPER_VAL_BASE0]' => '0.00',
            'customParameters[SHOPPER_VAL_BASEIMP]' => $baseImp,
            'customParameters[SHOPPER_VAL_IVA]' => $tax,
            'customParameters[SHOPPER_MID]' => $settings['shopper_mid'],
            'customParameters[SHOPPER_TID]' => $settings['shopper_tid'],
            'customParameters[SHOPPER_ECI]' => $settings['shopper_eci'],
            'customParameters[SHOPPER_PSERV]' => $settings['shopper_pserv'],
            'customParameters[SHOPPER_VERSIONDF]' => '2',
            'customParameters[SHOPPER_CI]' => $cedula,
            'risk.parameters[USER_DATA2]' => 'TheUIXstudio',
            'cart.items[0].name' => 'Orden WooCommerce #' . $orderId,
            'cart.items[0].price' => $amount,
            'cart.items[0].quantity' => '1',
            'cart.items[0].tax' => $tax,
        ];

        $payload = $this->remove_empty_payload_fields($payload);

        $requiredKeys = [
            'entityId',
            'amount',
            'currency',
            'paymentType',
            'createRegistration',
            'customer.givenName',
            'customer.middleName',
            'customer.surname',
            'customer.ip',
            'customer.merchantCustomerId',
            'merchantTransactionId',
            'customer.email',
            'customer.identificationDocType',
            'customer.identificationDocId',
            'customer.phone',
            'shipping.street1',
            'billing.street1',
            'shipping.country',
            'billing.country',
            'customParameters[SHOPPER_VAL_BASE0]',
            'customParameters[SHOPPER_VAL_BASEIMP]',
            'customParameters[SHOPPER_VAL_IVA]',
            'customParameters[SHOPPER_VERSIONDF]',
            'customParameters[SHOPPER_CI]',
            'risk.parameters[USER_DATA2]',
        ];

        if ($this->should_enforce_strict_phase2_required_fields($settings)) {
            $requiredKeys = array_merge($requiredKeys, [
                'customParameters[SHOPPER_MID]',
                'customParameters[SHOPPER_TID]',
                'customParameters[SHOPPER_ECI]',
                'customParameters[SHOPPER_PSERV]',
            ]);
        }

        $missing = $this->validate_required_payload_fields($payload, $requiredKeys);
        if (!empty($missing)) {
            $this->fail_wc_checkout_and_back($order, 'Missing required fields for Datafast phase 2 checkout', [
                'order_id' => $orderId,
                'reason' => 'Campos faltantes: ' . implode(', ', $missing),
                'missing_fields' => $missing,
                'identification_raw' => $cedulaRaw,
                'identification_normalized' => $cedula,
                'payload' => $payload,
            ]);
        }

        $optionalShopperKeys = ['customParameters[SHOPPER_MID]', 'customParameters[SHOPPER_TID]', 'customParameters[SHOPPER_ECI]', 'customParameters[SHOPPER_PSERV]'];
        $missingOptionalShopper = $this->validate_required_payload_fields($payload, $optionalShopperKeys);
        if (!empty($missingOptionalShopper) && !$this->should_enforce_strict_phase2_required_fields($settings)) {
            UIX_DF_Rec_Logger::info('Datafast checkout continuing without optional SHOPPER_* parameters', [
                'order_id' => $orderId,
                'missing_optional_shopper' => $missingOptionalShopper,
            ]);
            $order->add_order_note('Datafast: checkout enviado sin algunos SHOPPER_* opcionales: ' . implode(', ', $missingOptionalShopper));
        }

        if ($isTestMode) {
            $payload['testMode'] = 'EXTERNAL';
        }

        UIX_DF_Rec_Logger::info('Creating initial checkout (Woo order)', ['order_id' => $orderId, 'subscription_id' => $subscriptionId, 'payload' => $payload]);
        $response = $client->create_checkout($payload);
        if (!$response['ok'] || empty($response['body']['id'])) {
            $reason = $response['body']['result']['description'] ?? ($response['error'] ?? 'Error desconocido');
            $this->fail_wc_checkout_and_back($order, 'WC checkout creation failed', [
                'order_id' => $orderId,
                'subscription_id' => $subscriptionId,
                'status' => $response['status'] ?? 0,
                'reason' => $reason,
                'raw_body' => $response['raw_body'] ?? null,
                'parsed_body' => $response['parsed_body'] ?? null,
                'payload' => $payload,
            ]);
        }

        $checkoutId = $response['body']['id'];
        $this->repo->update_checkout($subscriptionId, $checkoutId);

        $widgetJs = rtrim($settings['initial_base_url'], '/') . '/v1/paymentWidgets.js?checkoutId=' . rawurlencode($checkoutId);

        UIX_DF_Rec_Logger::info('Rendering Datafast hosted widget', [
            'order_id' => $orderId,
            'subscription_id' => $subscriptionId,
            'checkout_id' => $checkoutId,
            'widget_js' => $widgetJs,
        ]);

        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Pagar orden</title></head><body>';
        echo '<h2>Finaliza tu pago</h2>';
        echo '<script src="' . esc_url($widgetJs) . '"></script>';
        echo '<form action="' . esc_url($returnUrl) . '" class="paymentWidgets" data-brands="' . esc_attr($this->payment_brands_attr()) . '" data-create-registration="true"></form>';
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

        if ($subscriptionId <= 0 || !$resourcePath) {
            wp_die('Retorno inválido');
        }

        $sub = $this->repo->find($subscriptionId);
        if (!$sub) {
            wp_die('Suscripción no encontrada');
        }

        $settings = $this->settings();
        $client = new UIX_DF_Rec_Datafast_Client($settings);
        UIX_DF_Rec_Logger::info('Verifying initial payment', ['subscription_id' => $subscriptionId, 'resourcePath' => $resourcePath]);
        $verification = $client->verify_payment($resourcePath, $settings['initial_entity_id']);

        if (!$verification['ok']) {
            UIX_DF_Rec_Logger::error('Initial verification failed', ['subscription_id' => $subscriptionId, 'verification' => $verification]);
            wp_die('No se pudo verificar el pago');
        }

        $body = $verification['body'];
        $this->repo->mark_from_result($subscriptionId, $body);

        $this->repo->add_attempt([
            'subscription_id' => $subscriptionId,
            'idempotency_key' => wp_generate_uuid4(),
            'kind' => 'initial',
            'requested_amount' => (float) $sub['amount'],
            'request_payload_redacted' => ['resourcePath' => $resourcePath],
            'response_payload_redacted' => $body,
            'http_status' => (int) $verification['status'],
            'result_code' => $body['result']['code'] ?? null,
            'result_description' => $body['result']['description'] ?? null,
            'transaction_id' => $body['id'] ?? null,
            'decision' => UIX_DF_Rec_Result_Codes::is_success($body['result']['code'] ?? '') ? 'approved' : 'declined',
        ]);

        $ok = UIX_DF_Rec_Result_Codes::is_success($body['result']['code'] ?? '') && !empty($body['registrationId']);
        UIX_DF_Rec_Logger::info('Initial payment verification result', ['subscription_id' => $subscriptionId, 'ok' => $ok, 'result_code' => $body['result']['code'] ?? null, 'has_registration' => !empty($body['registrationId'])]);

        $orderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
        if ($orderId > 0 && function_exists('wc_get_order')) {
            $order = wc_get_order($orderId);
            if ($order) {
                if ($ok) {
                    $order->payment_complete($body['id'] ?? '');
                    $order->add_order_note(__('Pago Datafast confirmado y tokenizado para recurrencia.', 'uix-df-rec'));
                } else {
                    $order->update_status('failed', __('Pago Datafast no aprobado.', 'uix-df-rec'));
                }
            }
        }

        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Resultado pago</title></head><body>';
        if ($ok) {
            echo '<h2>¡Suscripción activada!</h2><p>Tu pago inicial fue exitoso.</p>';
        } else {
            echo '<h2>No se pudo activar la suscripción</h2><p>Resultado: ' . esc_html($body['result']['description'] ?? 'Error de pago') . '</p>';
        }
        echo '</body></html>';
        exit;
    }

    public function run_recurring_runner()
    {
        $settings = $this->settings();
        if (empty($settings['recurring_entity_id']) || empty($settings['recurring_bearer_token'])) {
            UIX_DF_Rec_Logger::info('Recurring runner skipped: missing recurring credentials');
            return;
        }

        $client = new UIX_DF_Rec_Datafast_Client($settings);
        $subs = $this->repo->due_for_recurring(25);
        UIX_DF_Rec_Logger::info('Recurring runner started', ['due_count' => count($subs)]);

        foreach ($subs as $sub) {
            $payload = [
                'entityId' => $settings['recurring_entity_id'],
                'amount' => number_format((float) $sub['amount'], 2, '.', ''),
                'currency' => 'USD',
                'paymentType' => 'DB',
                'risk.parameters[USER_DATA1]' => 'REPEATED',
            ];

            if (!empty($settings['recurring_test_mode_enabled'])) {
                $payload['testMode'] = 'EXTERNAL';
            }

            UIX_DF_Rec_Logger::info('Recurring charge attempt', ['subscription_id' => (int) $sub['id'], 'status' => $sub['status'], 'next_charge_at' => $sub['next_charge_at']]);
            $response = $client->recurring_payment($sub['registration_id'], $payload);
            $body = $response['body'] ?? [];

            $this->repo->add_attempt([
                'subscription_id' => (int) $sub['id'],
                'idempotency_key' => 'rec-' . $sub['id'] . '-' . gmdate('YmdHis'),
                'kind' => 'recurring',
                'requested_amount' => (float) $sub['amount'],
                'request_payload_redacted' => $payload,
                'response_payload_redacted' => $body,
                'http_status' => (int) ($response['status'] ?? 0),
                'result_code' => $body['result']['code'] ?? null,
                'result_description' => $body['result']['description'] ?? null,
                'transaction_id' => $body['id'] ?? null,
                'decision' => UIX_DF_Rec_Result_Codes::is_success($body['result']['code'] ?? '') ? 'approved' : 'declined',
            ]);

            $this->repo->apply_recurring_result($sub, $body);
        }
    }

    public function register_admin_menu()
    {
        add_menu_page('UIX Recurrentes', 'UIX Recurrentes', 'manage_options', 'uix-df-rec', [$this, 'render_settings_page']);
        add_submenu_page('uix-df-rec', 'Suscripciones', 'Suscripciones', 'manage_options', 'uix-df-rec-subs', [$this, 'render_subscriptions_page']);
    }

    public function register_settings()
    {
        $keys = [
            'uix_df_initial_entity_id',
            'uix_df_initial_bearer_token',
            'uix_df_initial_base_url',
            'uix_df_initial_test_mode_enabled',
            'uix_df_recurring_entity_id',
            'uix_df_recurring_bearer_token',
            'uix_df_recurring_base_url',
            'uix_df_recurring_test_mode_enabled',
            'uix_df_default_max_retries',
            'uix_df_payment_brands',
            'uix_df_debug_enabled',
            'uix_df_allow_test_placeholders',
            'uix_df_shopper_mid',
            'uix_df_shopper_tid',
            'uix_df_shopper_eci',
            'uix_df_shopper_pserv',
            'uix_df_strict_phase2_required',
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

        ?>
        <div class="wrap">
            <h1>UIX Datafast Recurrentes</h1>
            <form method="post" action="options.php">
                <?php settings_fields('uix_df_rec_settings'); ?>
                <h2>Primer pago</h2>
                <table class="form-table">
                    <tr><th>Entity ID</th><td><input class="regular-text" name="uix_df_initial_entity_id" value="<?php echo esc_attr(get_option('uix_df_initial_entity_id', '')); ?>"></td></tr>
                    <tr><th>Bearer Token</th><td><input class="regular-text" name="uix_df_initial_bearer_token" value="<?php echo esc_attr(get_option('uix_df_initial_bearer_token', '')); ?>"></td></tr>
                    <tr><th>Base URL</th><td><input class="regular-text" name="uix_df_initial_base_url" value="<?php echo esc_attr(get_option('uix_df_initial_base_url', 'https://eu-test.oppwa.com')); ?>"></td></tr>
                    <tr><th>Test mode</th><td><label><input type="checkbox" name="uix_df_initial_test_mode_enabled" value="1" <?php checked(get_option('uix_df_initial_test_mode_enabled', 1), 1); ?>> EXTERNAL</label></td></tr>
                    <tr><th>Permitir placeholders test</th><td><label><input type="checkbox" name="uix_df_allow_test_placeholders" value="1" <?php checked(get_option('uix_df_allow_test_placeholders', 0), 1); ?>> Permite valores de relleno (solo para depuración)</label></td></tr>
                    <tr><th>SHOPPER_MID</th><td><input class="regular-text" name="uix_df_shopper_mid" value="<?php echo esc_attr(get_option('uix_df_shopper_mid', '')); ?>"></td></tr>
                    <tr><th>SHOPPER_TID</th><td><input class="regular-text" name="uix_df_shopper_tid" value="<?php echo esc_attr(get_option('uix_df_shopper_tid', '')); ?>"></td></tr>
                    <tr><th>SHOPPER_ECI</th><td><input class="regular-text" name="uix_df_shopper_eci" value="<?php echo esc_attr(get_option('uix_df_shopper_eci', '')); ?>"></td></tr>
                    <tr><th>SHOPPER_PSERV</th><td><input class="regular-text" name="uix_df_shopper_pserv" value="<?php echo esc_attr(get_option('uix_df_shopper_pserv', '')); ?>"></td></tr>
                    <tr><th>Validación estricta phase-2</th><td><label><input type="checkbox" name="uix_df_strict_phase2_required" value="1" <?php checked(get_option('uix_df_strict_phase2_required', 0), 1); ?>> Exigir SHOPPER_MID/TID/ECI/PSERV como obligatorios</label></td></tr>
                    <tr><th>Marcas permitidas</th><td><input class="regular-text" name="uix_df_payment_brands" value="<?php echo esc_attr(get_option('uix_df_payment_brands', 'VISA MASTER AMEX DINERS DISCOVER')); ?>"><p class="description">Separadas por espacio. Default sin ALIA por compatibilidad general.</p></td></tr>
                    <tr><th>Debug logs</th><td><label><input type="checkbox" name="uix_df_debug_enabled" value="1" <?php checked(get_option('uix_df_debug_enabled', 1), 1); ?>> Habilitar logs detallados</label></td></tr>
                </table>

                <h2>Cobro recurrente</h2>
                <table class="form-table">
                    <tr><th>Entity ID</th><td><input class="regular-text" name="uix_df_recurring_entity_id" value="<?php echo esc_attr(get_option('uix_df_recurring_entity_id', '')); ?>"></td></tr>
                    <tr><th>Bearer Token</th><td><input class="regular-text" name="uix_df_recurring_bearer_token" value="<?php echo esc_attr(get_option('uix_df_recurring_bearer_token', '')); ?>"></td></tr>
                    <tr><th>Base URL</th><td><input class="regular-text" name="uix_df_recurring_base_url" value="<?php echo esc_attr(get_option('uix_df_recurring_base_url', 'https://eu-test.oppwa.com')); ?>"></td></tr>
                    <tr><th>Test mode</th><td><label><input type="checkbox" name="uix_df_recurring_test_mode_enabled" value="1" <?php checked(get_option('uix_df_recurring_test_mode_enabled', 1), 1); ?>> EXTERNAL</label></td></tr>
                    <tr><th>Max retries</th><td><input type="number" min="1" max="10" name="uix_df_default_max_retries" value="<?php echo esc_attr(get_option('uix_df_default_max_retries', 3)); ?>"></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <p>Shortcode: <code>[uix_subscribe_form plan="plan-pro" title="Plan Pro" amount="49.00"]</code></p>
        </div>
        <?php
    }

    public function render_subscriptions_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $items = $this->repo->all(200);
        ?>
        <div class="wrap">
            <h1>Suscripciones</h1>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th><th>Cliente</th><th>Email</th><th>Plan</th><th>Monto</th><th>Estado</th><th>Próximo cobro</th><th>Último código</th><th>Token</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item) : ?>
                    <tr>
                        <td><?php echo esc_html($item['id']); ?></td>
                        <td><?php echo esc_html($item['full_name']); ?></td>
                        <td><?php echo esc_html($item['email']); ?></td>
                        <td><?php echo esc_html($item['plan_title']); ?></td>
                        <td><?php echo esc_html($item['amount']); ?></td>
                        <td><?php echo esc_html($item['status']); ?></td>
                        <td><?php echo esc_html($item['next_charge_at']); ?></td>
                        <td><?php echo esc_html($item['last_result_code']); ?></td>
                        <td><?php echo esc_html($item['registration_id']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function settings()
    {
        return [
            'initial_entity_id' => get_option('uix_df_initial_entity_id', ''),
            'initial_bearer_token' => get_option('uix_df_initial_bearer_token', ''),
            'initial_base_url' => get_option('uix_df_initial_base_url', 'https://eu-test.oppwa.com'),
            'initial_test_mode_enabled' => (bool) get_option('uix_df_initial_test_mode_enabled', 1),
            'recurring_entity_id' => get_option('uix_df_recurring_entity_id', ''),
            'recurring_bearer_token' => get_option('uix_df_recurring_bearer_token', ''),
            'recurring_base_url' => get_option('uix_df_recurring_base_url', 'https://eu-test.oppwa.com'),
            'recurring_test_mode_enabled' => (bool) get_option('uix_df_recurring_test_mode_enabled', 1),
            'payment_brands' => get_option('uix_df_payment_brands', 'VISA MASTER AMEX DINERS DISCOVER'),
            'debug_enabled' => (bool) get_option('uix_df_debug_enabled', 1),
            'allow_test_placeholders' => (bool) get_option('uix_df_allow_test_placeholders', 0),
            'shopper_mid' => trim((string) get_option('uix_df_shopper_mid', '')),
            'shopper_tid' => trim((string) get_option('uix_df_shopper_tid', '')),
            'shopper_eci' => trim((string) get_option('uix_df_shopper_eci', '')),
            'shopper_pserv' => trim((string) get_option('uix_df_shopper_pserv', '')),
            'strict_phase2_required' => (bool) get_option('uix_df_strict_phase2_required', 0),
        ];
    }
}
