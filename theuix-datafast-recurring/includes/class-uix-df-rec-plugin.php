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
        add_action('template_redirect', [$this, 'handle_return']);

        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        add_action('uix_df_recurring_charge_runner', [$this, 'run_recurring_runner']);

        UIX_DF_Rec_DB::schedule_events();
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
            <p><label>Teléfono<br><input type="text" name="phone" required></label></p>
            <p><label>Dirección facturación<br><input type="text" name="billing_street1" required></label></p>
            <p><label>Dirección envío<br><input type="text" name="shipping_street1" required></label></p>
            <p><label>País facturación (ISO2, ej. EC)<br><input type="text" name="billing_country" required maxlength="2"></label></p>
            <p><label>País envío (ISO2, ej. EC)<br><input type="text" name="shipping_country" required maxlength="2"></label></p>
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
        $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $billingStreet = sanitize_text_field(wp_unslash($_POST['billing_street1'] ?? ''));
        $shippingStreet = sanitize_text_field(wp_unslash($_POST['shipping_street1'] ?? ''));
        $billingCountry = strtoupper(sanitize_text_field(wp_unslash($_POST['billing_country'] ?? '')));
        $shippingCountry = strtoupper(sanitize_text_field(wp_unslash($_POST['shipping_country'] ?? '')));
        $amount = number_format((float) ($_POST['amount'] ?? 0), 2, '.', '');

        if (!$fullName || !$email || !$cedula || !$phone || !$billingStreet || !$shippingStreet || strlen($billingCountry) !== 2 || strlen($shippingCountry) !== 2 || $amount <= 0) {
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
        $client = new UIX_DF_Rec_Datafast_Client($settings);
        $returnUrl = add_query_arg([
            'uix_df_return' => 1,
            'subscription_id' => $subscriptionId,
        ], home_url('/'));

        $nameParts = self::split_name($fullName);
        $payload = [
            'entityId' => $settings['initial_entity_id'],
            'amount' => $amount,
            'currency' => 'USD',
            'paymentType' => 'DB',
            'createRegistration' => 'true',
            'shopperResultURL' => $returnUrl,
            'customer.givenName' => $nameParts['given'],
            'customer.middleName' => $nameParts['middle'],
            'customer.surname' => $nameParts['surname'],
            'customer.email' => $email,
            'customer.identificationDocType' => 'IDCARD',
            'customer.identificationDocId' => self::normalize_identification($cedula),
            'customParameters[SHOPPER_VERSIONDF]' => '2',
            'cart.items[0].name' => $planTitle,
            'cart.items[0].description' => $planTitle,
            'cart.items[0].price' => $amount,
            'cart.items[0].quantity' => '1',
            'customer.ip' => WC_Geolocation::get_ip_address(),
            'customer.phone' => $phone,
            'shipping.street1' => $shippingStreet,
            'billing.street1' => $billingStreet,
            'shipping.country' => $shippingCountry,
            'billing.country' => $billingCountry,
            'customer.merchantCustomerId' => 'SUB-' . $subscriptionId,
            'merchantTransactionId' => 'SUB-' . $subscriptionId . '-' . gmdate('YmdHis') . '-' . wp_rand(1000, 9999),
            'customParameters[SHOPPER_VAL_BASE0]' => '0.00',
            'customParameters[SHOPPER_VAL_BASEIMP]' => $amount,
            'customParameters[SHOPPER_VAL_IVA]' => '0.00',
            'customParameters[SHOPPER_MID]' => $settings['initial_mid'],
            'customParameters[SHOPPER_TID]' => $settings['initial_tid'],
            'customParameters[SHOPPER_ECI]' => '0103910',
            'customParameters[SHOPPER_PSERV]' => '17913101',
            'risk.parameters[USER_DATA2]' => $settings['initial_commerce_name'],
        ];

        if (!empty($settings['initial_test_mode_enabled'])) {
            $payload['testMode'] = 'EXTERNAL';
        }

        UIX_DF_Rec_Logger::info('Initial checkout request', ['subscription_id' => $subscriptionId, 'payload' => $payload]);
        $response = $client->create_checkout($payload);
        UIX_DF_Rec_Logger::info('Initial checkout response', ['subscription_id' => $subscriptionId, 'response' => $response]);

        if (!$response['ok'] || empty($response['body']['id'])) {
            wp_die('No se pudo crear checkout. Revisa configuración Datafast.');
        }

        $checkoutId = $response['body']['id'];
        $this->repo->update_checkout($subscriptionId, $checkoutId);

        $widgetJs = rtrim($settings['initial_base_url'], '/') . '/v1/paymentWidgets.js?checkoutId=' . rawurlencode($checkoutId);

        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Pagar suscripción</title></head><body>';
        echo '<h2>Finaliza tu pago</h2>';
        echo '<script src="' . esc_url($widgetJs) . '"></script>';
        echo '<form action="' . esc_url($returnUrl) . '" class="paymentWidgets" data-brands="VISA MASTER AMEX DINERS DISCOVER ALIA"></form>';
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
        $orderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
        $orderKey = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';

        if ($subscriptionId <= 0 || !$resourcePath) {
            wp_die('Retorno inválido');
        }

        $sub = $this->repo->find($subscriptionId);
        if (!$sub) {
            wp_die('Suscripción no encontrada');
        }

        $settings = $this->settings();
        $verifyBaseUrl = rtrim($settings['initial_base_url'], '/') . $resourcePath;
        $verifyFinalUrl = add_query_arg([
            'entityId' => $settings['initial_entity_id'],
        ], $verifyBaseUrl);
        UIX_DF_Rec_Logger::info('Verify return request', [
            'subscription_id' => $subscriptionId,
            'checkout_id' => $sub['checkout_id'] ?? null,
            'resourcePath' => $resourcePath,
            'verify_url' => $verifyFinalUrl,
        ]);

        $client = new UIX_DF_Rec_Datafast_Client($settings);
        $verification = $client->verify_payment($resourcePath, $settings['initial_entity_id']);

        UIX_DF_Rec_Logger::info('Verify return response', [
            'subscription_id' => $subscriptionId,
            'checkout_id' => $sub['checkout_id'] ?? null,
            'resourcePath' => $resourcePath,
            'verify_url' => $verifyFinalUrl,
            'http_status' => (int) ($verification['status'] ?? 0),
            'body' => $verification['body'] ?? null,
            'wp_error' => $verification['error'] ?? null,
        ]);

        if (!$verification['ok']) {
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

        if ($orderId > 0) {
            $order = wc_get_order($orderId);
            if ($order && hash_equals((string) $order->get_order_key(), $orderKey)) {
                if ($ok) {
                    $order->payment_complete((string) ($body['id'] ?? ''));
                    $order->add_order_note('Pago Datafast verificado. registrationId guardado en subscripción ' . $subscriptionId . '.');
                    wp_safe_redirect($order->get_checkout_order_received_url());
                    exit;
                }

                $order->update_status('failed', 'Pago Datafast rechazado: ' . ($body['result']['description'] ?? 'Sin detalle'));
                wp_safe_redirect($order->get_checkout_payment_url(true));
                exit;
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
            UIX_DF_Rec_Logger::info('Recurring skipped: missing recurring config');
            return;
        }

        $client = new UIX_DF_Rec_Datafast_Client($settings);
        $subs = $this->repo->due_for_recurring(25);

        foreach ($subs as $sub) {
            $amount = number_format((float) $sub['amount'], 2, '.', '');
            $payload = [
                'entityId' => $settings['recurring_entity_id'],
                'amount' => $amount,
                'currency' => 'USD',
                'paymentType' => 'DB',
                'recurringType' => 'REPEATED',
                'risk.parameters[USER_DATA1]' => 'REPEATED',
                'risk.parameters[USER_DATA2]' => $settings['recurring_commerce_name'],
                'merchantTransactionId' => 'REC-' . $sub['id'] . '-' . gmdate('YmdHis') . '-' . wp_rand(1000, 9999),
                'customParameters[SHOPPER_MID]' => $settings['recurring_mid'],
                'customParameters[SHOPPER_TID]' => $settings['recurring_tid'],
                'customParameters[SHOPPER_ECI]' => '0103910',
                'customParameters[SHOPPER_PSERV]' => '17913101',
                'customParameters[SHOPPER_VAL_BASE0]' => '0.00',
                'customParameters[SHOPPER_VAL_BASEIMP]' => $amount,
                'customParameters[SHOPPER_VAL_IVA]' => '0.00',
                'customParameters[SHOPPER_VERSIONDF]' => '2',
            ];

            if (!empty($settings['recurring_test_mode_enabled'])) {
                $payload['testMode'] = 'EXTERNAL';
            }

            UIX_DF_Rec_Logger::info('Recurring request', ['subscription_id' => (int) $sub['id'], 'payload' => $payload]);
            $response = $client->recurring_payment($sub['registration_id'], $payload);
            $body = $response['body'] ?? [];
            UIX_DF_Rec_Logger::info('Recurring response', [
                'subscription_id' => (int) $sub['id'],
                'status' => (int) ($response['status'] ?? 0),
                'ok' => (bool) ($response['ok'] ?? false),
                'body' => $body,
                'error' => $response['error'] ?? null,
            ]);

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
            'uix_df_initial_mid',
            'uix_df_initial_tid',
            'uix_df_initial_commerce_name',
            'uix_df_recurring_entity_id',
            'uix_df_recurring_bearer_token',
            'uix_df_recurring_base_url',
            'uix_df_recurring_test_mode_enabled',
            'uix_df_recurring_mid',
            'uix_df_recurring_tid',
            'uix_df_recurring_commerce_name',
            'uix_df_default_max_retries',
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
            <p>La configuración oficial para gateway WooCommerce se gestiona en WooCommerce &gt; Ajustes &gt; Pagos &gt; Datafast UIX.</p>
            <form method="post" action="options.php">
                <?php settings_fields('uix_df_rec_settings'); ?>
                <h2>Checkout inicial / Fase 2</h2>
                <table class="form-table">
                    <tr><th>Entity ID</th><td><input class="regular-text" name="uix_df_initial_entity_id" value="<?php echo esc_attr(get_option('uix_df_initial_entity_id', '')); ?>"></td></tr>
                    <tr><th>Access Token (sin Bearer)</th><td><input class="regular-text" name="uix_df_initial_bearer_token" value="<?php echo esc_attr(get_option('uix_df_initial_bearer_token', '')); ?>"></td></tr>
                    <tr><th>Base URL</th><td><input class="regular-text" name="uix_df_initial_base_url" value="<?php echo esc_attr(get_option('uix_df_initial_base_url', 'https://eu-test.oppwa.com')); ?>"></td></tr>
                </table>

                <h2>Cobro recurrente</h2>
                <table class="form-table">
                    <tr><th>Entity ID</th><td><input class="regular-text" name="uix_df_recurring_entity_id" value="<?php echo esc_attr(get_option('uix_df_recurring_entity_id', '')); ?>"></td></tr>
                    <tr><th>Access Token (sin Bearer)</th><td><input class="regular-text" name="uix_df_recurring_bearer_token" value="<?php echo esc_attr(get_option('uix_df_recurring_bearer_token', '')); ?>"></td></tr>
                    <tr><th>Base URL</th><td><input class="regular-text" name="uix_df_recurring_base_url" value="<?php echo esc_attr(get_option('uix_df_recurring_base_url', 'https://test.oppwa.com')); ?>"></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
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

    public function settings()
    {
        return [
            'initial_entity_id' => get_option('uix_df_initial_entity_id', ''),
            'initial_bearer_token' => get_option('uix_df_initial_bearer_token', ''),
            'initial_base_url' => get_option('uix_df_initial_base_url', 'https://eu-test.oppwa.com'),
            'initial_test_mode_enabled' => (bool) get_option('uix_df_initial_test_mode_enabled', 1),
            'initial_mid' => get_option('uix_df_initial_mid', '1000000505'),
            'initial_tid' => get_option('uix_df_initial_tid', 'PD100406'),
            'initial_commerce_name' => get_option('uix_df_initial_commerce_name', 'THEUIXSTUDIO'),
            'recurring_entity_id' => get_option('uix_df_recurring_entity_id', ''),
            'recurring_bearer_token' => get_option('uix_df_recurring_bearer_token', ''),
            'recurring_base_url' => get_option('uix_df_recurring_base_url', 'https://test.oppwa.com'),
            'recurring_test_mode_enabled' => (bool) get_option('uix_df_recurring_test_mode_enabled', 1),
            'recurring_mid' => get_option('uix_df_recurring_mid', '1000000505'),
            'recurring_tid' => get_option('uix_df_recurring_tid', 'PD100406'),
            'recurring_commerce_name' => get_option('uix_df_recurring_commerce_name', 'THEUIXSTUDIO'),
        ];
    }

    public static function split_name($fullName)
    {
        $parts = preg_split('/\s+/', trim((string) $fullName));
        return [
            'given' => $parts[0] ?? '',
            'middle' => $parts[1] ?? ($parts[0] ?? ''),
            'surname' => isset($parts[2]) ? implode(' ', array_slice($parts, 2)) : ($parts[1] ?? ($parts[0] ?? '')),
        ];
    }

    public static function normalize_identification($document)
    {
        $digits = preg_replace('/\D+/', '', (string) $document);
        if (strlen($digits) > 10) {
            $digits = substr($digits, 0, 10);
        }

        if ($digits === '') {
            return '';
        }

        return str_pad($digits, 10, '0', STR_PAD_LEFT);
    }

    public static function build_initial_payload_from_order(WC_Order $order, $subscriptionId, $docId, array $settings)
    {
        $total = number_format((float) $order->get_total(), 2, '.', '');
        $tax = number_format((float) $order->get_total_tax(), 2, '.', '');
        $baseImp = number_format(max(0, (float) $order->get_total() - (float) $order->get_total_tax()), 2, '.', '');
        $ip = WC_Geolocation::get_ip_address();
        $fullName = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $nameParts = self::split_name($fullName);
        $shippingStreet = $order->get_shipping_address_1() ?: $order->get_billing_address_1();
        $shippingCountry = $order->get_shipping_country() ?: $order->get_billing_country();

        return [
            'entityId' => $settings['initial_entity_id'],
            'amount' => $total,
            'currency' => 'USD',
            'paymentType' => 'DB',
            'createRegistration' => 'true',
            'shopperResultURL' => add_query_arg([
                'uix_df_return' => 1,
                'subscription_id' => (int) $subscriptionId,
                'order_id' => $order->get_id(),
                'key' => $order->get_order_key(),
            ], home_url('/')),
            'customer.givenName' => $nameParts['given'],
            'customer.middleName' => $nameParts['middle'],
            'customer.surname' => $nameParts['surname'],
            'customer.ip' => $ip,
            'customer.merchantCustomerId' => 'WC-CUST-' . ($order->get_user_id() ?: 'guest-' . $order->get_id()),
            'merchantTransactionId' => 'WC-' . $order->get_id() . '-' . gmdate('YmdHis') . '-' . wp_rand(1000, 9999),
            'customer.email' => $order->get_billing_email(),
            'customer.identificationDocType' => 'IDCARD',
            'customer.identificationDocId' => $docId,
            'customer.phone' => $order->get_billing_phone(),
            'shipping.street1' => $shippingStreet,
            'billing.street1' => $order->get_billing_address_1(),
            'shipping.country' => $shippingCountry,
            'billing.country' => $order->get_billing_country(),
            'cart.items[0].name' => 'Orden WooCommerce #' . $order->get_id(),
            'cart.items[0].description' => 'Compra en ' . get_bloginfo('name'),
            'cart.items[0].price' => $total,
            'cart.items[0].quantity' => '1',
            'testMode' => !empty($settings['initial_test_mode_enabled']) ? 'EXTERNAL' : '',
            'customParameters[SHOPPER_VAL_BASE0]' => '0.00',
            'customParameters[SHOPPER_VAL_BASEIMP]' => $baseImp,
            'customParameters[SHOPPER_VAL_IVA]' => $tax,
            'customParameters[SHOPPER_MID]' => $settings['initial_mid'],
            'customParameters[SHOPPER_TID]' => $settings['initial_tid'],
            'customParameters[SHOPPER_ECI]' => '0103910',
            'customParameters[SHOPPER_PSERV]' => '17913101',
            'customParameters[SHOPPER_VERSIONDF]' => '2',
            'risk.parameters[USER_DATA2]' => $settings['initial_commerce_name'],
        ];
    }
}
