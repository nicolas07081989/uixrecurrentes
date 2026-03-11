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

        add_filter('woocommerce_payment_gateways', [$this, 'register_wc_gateway']);

        UIX_DF_Rec_DB::schedule_events();
    }


    public function register_wc_gateway($methods)
    {
        if (class_exists('UIX_DF_Rec_WC_Gateway')) {
            $methods[] = 'UIX_DF_Rec_WC_Gateway';
        }

        return $methods;
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
            'shopperResultURL' => $returnUrl,
            'customer.givenName' => $nameParts[0] ?? $fullName,
            'customer.surname' => $nameParts[1] ?? 'Cliente',
            'customer.email' => $email,
            'customer.identificationDocType' => 'IDCARD',
            'customer.identificationDocId' => $cedula,
            'customParameters[SHOPPER_VERSIONDF]' => '2',
            'cart.items[0].name' => $planTitle,
            'cart.items[0].price' => $amount,
            'cart.items[0].quantity' => '1',
            'cart.items[0].tax' => '0.00',
        ];

        if (!empty($settings['initial_test_mode_enabled'])) {
            $payload['testMode'] = 'EXTERNAL';
        }

        $response = $client->create_checkout($payload);
        if (!$response['ok'] || empty($response['body']['id'])) {
            UIX_DF_Rec_Logger::info('Checkout creation failed', $response);
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

        $subscriptionId = (int) $order->get_meta('_uix_df_subscription_id');
        if ($subscriptionId <= 0) {
            $fullName = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            $subscriptionId = $this->repo->create_pending([
                'full_name' => $fullName ?: 'Cliente',
                'email' => $order->get_billing_email(),
                'cedula_ruc' => (string) $order->get_meta('df_cedula') ?: '9999999999',
                'plan_slug' => 'woo-order-' . $orderId,
                'plan_title' => 'Orden WooCommerce #' . $orderId,
                'amount' => number_format((float) $order->get_total(), 2, '.', ''),
                'max_retries' => (int) get_option('uix_df_default_max_retries', 3),
            ]);
            $order->update_meta_data('_uix_df_subscription_id', $subscriptionId);
            $order->save();
        }

        $settings = $this->settings();
        $client = new UIX_DF_Rec_Datafast_Client($settings);

        $returnUrl = add_query_arg([
            'uix_df_return' => 1,
            'subscription_id' => $subscriptionId,
            'order_id' => $orderId,
            'key' => $order->get_order_key(),
        ], home_url('/'));

        $payload = [
            'entityId' => $settings['initial_entity_id'],
            'amount' => number_format((float) $order->get_total(), 2, '.', ''),
            'currency' => $order->get_currency() ?: 'USD',
            'paymentType' => 'DB',
            'createRegistration' => 'true',
            'shopperResultURL' => $returnUrl,
            'customer.givenName' => $order->get_billing_first_name() ?: 'Cliente',
            'customer.surname' => $order->get_billing_last_name() ?: 'Woo',
            'customer.email' => $order->get_billing_email(),
            'customer.identificationDocType' => 'IDCARD',
            'customer.identificationDocId' => (string) $order->get_meta('df_cedula') ?: '9999999999',
            'merchantTransactionId' => 'uixdf_' . $orderId . '_' . gmdate('YmdHis'),
            'customParameters[SHOPPER_VERSIONDF]' => '2',
            'cart.items[0].name' => 'Orden WooCommerce #' . $orderId,
            'cart.items[0].price' => number_format((float) $order->get_total(), 2, '.', ''),
            'cart.items[0].quantity' => '1',
            'cart.items[0].tax' => number_format((float) $order->get_total_tax(), 2, '.', ''),
        ];

        if (!empty($settings['initial_test_mode_enabled'])) {
            $payload['testMode'] = 'EXTERNAL';
        }

        $response = $client->create_checkout($payload);
        if (!$response['ok'] || empty($response['body']['id'])) {
            UIX_DF_Rec_Logger::info('WC checkout creation failed', $response);
            wc_add_notice(__('No se pudo inicializar el pago con Datafast.', 'uix-df-rec'), 'error');
            wp_safe_redirect($order->get_checkout_payment_url());
            exit;
        }

        $checkoutId = $response['body']['id'];
        $this->repo->update_checkout($subscriptionId, $checkoutId);

        $widgetJs = rtrim($settings['initial_base_url'], '/') . '/v1/paymentWidgets.js?checkoutId=' . rawurlencode($checkoutId);

        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Pagar orden</title></head><body>';
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

        if ($subscriptionId <= 0 || !$resourcePath) {
            wp_die('Retorno inválido');
        }

        $sub = $this->repo->find($subscriptionId);
        if (!$sub) {
            wp_die('Suscripción no encontrada');
        }

        $settings = $this->settings();
        $client = new UIX_DF_Rec_Datafast_Client($settings);
        $verification = $client->verify_payment($resourcePath, $settings['initial_entity_id']);

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
            return;
        }

        $client = new UIX_DF_Rec_Datafast_Client($settings);
        $subs = $this->repo->due_for_recurring(25);

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
        ];
    }
}
