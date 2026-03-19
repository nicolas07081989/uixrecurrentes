<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WC_Gateway_UIX_Datafast')) {
    class WC_Gateway_UIX_Datafast extends WC_Payment_Gateway
    {
        private $repo;

        public function __construct()
        {
            $this->id = 'uix_datafast';
            $this->method_title = 'Datafast UIX';
            $this->method_description = 'Gateway custom Datafast (checkout + tokenización + recurrencia).';
            $this->has_fields = false;
            $this->supports = ['products'];

            $this->init_form_fields();
            $this->init_settings();

            $this->enabled = $this->get_option('enabled', 'yes');
            $this->title = $this->get_option('title', 'Tarjeta de crédito/débito (Datafast)');
            $this->description = $this->get_option('description', 'Paga de forma segura con Datafast.');
            $this->repo = new UIX_DF_Rec_Subscription_Repo();

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);
        }

        public function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title' => 'Activar/Desactivar',
                    'type' => 'checkbox',
                    'label' => 'Activar Datafast UIX',
                    'default' => 'yes',
                ],
                'title' => [
                    'title' => 'Título en checkout',
                    'type' => 'text',
                    'default' => 'Tarjeta de crédito/débito (Datafast)',
                ],
                'description' => [
                    'title' => 'Descripción',
                    'type' => 'textarea',
                    'default' => 'Paga con tarjeta usando Datafast.',
                ],
                'initial_section' => [
                    'title' => 'Checkout inicial / Fase 2',
                    'type' => 'title',
                    'description' => 'Credenciales y parámetros del checkout inicial enriquecido.',
                ],
                'initial_base_url' => [
                    'title' => 'Base URL inicial',
                    'type' => 'text',
                    'default' => 'https://eu-test.oppwa.com',
                ],
                'initial_entity_id' => [
                    'title' => 'Entity ID inicial',
                    'type' => 'text',
                    'default' => '8ac7a4c89cfeec32019d02d5930909e6',
                ],
                'initial_bearer_token' => [
                    'title' => 'Access Token inicial (sin Bearer)',
                    'type' => 'password',
                    'default' => 'OGE4Mjk0MTg1YTY1YmY1ZTAxNWE2YzhjNzI4YzBkOTV8YmZxR3F3UTMyWA==',
                ],
                'initial_mid' => [
                    'title' => 'MID inicial',
                    'type' => 'text',
                    'default' => '1000000505',
                ],
                'initial_tid' => [
                    'title' => 'TID inicial',
                    'type' => 'text',
                    'default' => 'PD100406',
                ],
                'initial_commerce_name' => [
                    'title' => 'Nombre comercio (USER_DATA2)',
                    'type' => 'text',
                    'default' => 'THEUIXSTUDIO',
                ],
                'initial_test_mode_enabled' => [
                    'title' => 'Test mode inicial',
                    'label' => 'Enviar testMode=EXTERNAL',
                    'type' => 'checkbox',
                    'default' => 'yes',
                ],
                'recurring_section' => [
                    'title' => 'Cobro recurrente',
                    'type' => 'title',
                    'description' => 'Credenciales y parámetros para /v1/registrations/{token}/payments.',
                ],
                'recurring_base_url' => [
                    'title' => 'Base URL recurrente',
                    'type' => 'text',
                    'default' => 'https://test.oppwa.com',
                ],
                'recurring_entity_id' => [
                    'title' => 'Entity ID recurrente',
                    'type' => 'text',
                    'default' => '8ac7a4c89cfeec32019d02d5930909e6',
                ],
                'recurring_bearer_token' => [
                    'title' => 'Access Token recurrente (sin Bearer)',
                    'type' => 'password',
                    'default' => 'OGE4Mjk0MTg1YTY1YmY1ZTAxNWE2YzhjNzI4YzBkOTV8YmZxR3F3UTMyWA==',
                ],
                'recurring_mid' => [
                    'title' => 'MID recurrente',
                    'type' => 'text',
                    'default' => '1000000505',
                ],
                'recurring_tid' => [
                    'title' => 'TID recurrente',
                    'type' => 'text',
                    'default' => 'PD100406',
                ],
                'recurring_commerce_name' => [
                    'title' => 'Canal recurrente (USER_DATA2)',
                    'type' => 'text',
                    'default' => 'THEUIXSTUDIO',
                ],
                'recurring_test_mode_enabled' => [
                    'title' => 'Test mode recurrente',
                    'label' => 'Enviar testMode=EXTERNAL',
                    'type' => 'checkbox',
                    'default' => 'yes',
                ],
                'default_max_retries' => [
                    'title' => 'Máximo reintentos',
                    'type' => 'number',
                    'default' => 3,
                    'custom_attributes' => ['min' => 1, 'max' => 10],
                ],
            ];
        }

        public function process_admin_options()
        {
            parent::process_admin_options();
            $map = [
                'uix_df_initial_base_url' => 'initial_base_url',
                'uix_df_initial_entity_id' => 'initial_entity_id',
                'uix_df_initial_bearer_token' => 'initial_bearer_token',
                'uix_df_initial_test_mode_enabled' => 'initial_test_mode_enabled',
                'uix_df_initial_mid' => 'initial_mid',
                'uix_df_initial_tid' => 'initial_tid',
                'uix_df_initial_commerce_name' => 'initial_commerce_name',
                'uix_df_recurring_base_url' => 'recurring_base_url',
                'uix_df_recurring_entity_id' => 'recurring_entity_id',
                'uix_df_recurring_bearer_token' => 'recurring_bearer_token',
                'uix_df_recurring_test_mode_enabled' => 'recurring_test_mode_enabled',
                'uix_df_recurring_mid' => 'recurring_mid',
                'uix_df_recurring_tid' => 'recurring_tid',
                'uix_df_recurring_commerce_name' => 'recurring_commerce_name',
                'uix_df_default_max_retries' => 'default_max_retries',
            ];

            foreach ($map as $optionKey => $gatewayKey) {
                update_option($optionKey, $this->get_option($gatewayKey));
            }
        }

        public function is_available()
        {
            if (!parent::is_available()) {
                return false;
            }

            return !empty($this->get_option('initial_entity_id')) && !empty($this->get_option('initial_bearer_token'));
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            if (!$order) {
                wc_add_notice('No se encontró la orden para procesar Datafast.', 'error');
                return ['result' => 'fail'];
            }

            $order->update_status('pending', 'Pendiente de pago en Datafast UIX.');

            return [
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true),
            ];
        }

        public function receipt_page($order_id)
        {
            $order = wc_get_order($order_id);
            if (!$order) {
                echo '<p>No se encontró la orden.</p>';
                return;
            }

            $shippingAddress = (string) $order->get_shipping_address_1();
            $shippingCountry = (string) $order->get_shipping_country();
            if ($shippingAddress === '') {
                $shippingAddress = (string) $order->get_billing_address_1();
            }
            if ($shippingCountry === '') {
                $shippingCountry = (string) $order->get_billing_country();
            }

            $required = [
                'billing_first_name' => (string) $order->get_billing_first_name(),
                'billing_last_name' => (string) $order->get_billing_last_name(),
                'billing_email' => (string) $order->get_billing_email(),
                'billing_phone' => (string) $order->get_billing_phone(),
                'billing_address_1' => (string) $order->get_billing_address_1(),
                'shipping_address_1' => $shippingAddress,
                'billing_country' => (string) $order->get_billing_country(),
                'shipping_country' => $shippingCountry,
            ];
            foreach ($required as $field => $value) {
                if ($value === '') {
                    wc_print_notice('Falta el campo obligatorio para Datafast: ' . esc_html($field) . '.', 'error');
                    return;
                }
            }

            $subscriptionId = (int) $order->get_meta('_uix_df_subscription_id');
            if ($subscriptionId <= 0) {
                $orderIdentificationDoc = UIX_DF_Rec_Plugin::read_order_identification_doc($order);
                $subscriptionId = $this->repo->create_pending([
                    'full_name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                    'email' => $order->get_billing_email(),
                    'cedula_ruc' => $orderIdentificationDoc,
                    'plan_slug' => 'wc-order-' . $order->get_id(),
                    'plan_title' => 'Orden WooCommerce #' . $order->get_id(),
                    'amount' => number_format((float) $order->get_total(), 2, '.', ''),
                    'max_retries' => (int) get_option('uix_df_default_max_retries', 3),
                ]);
                $order->update_meta_data('_uix_df_subscription_id', $subscriptionId);
                $order->save();
            }

            $settings = UIX_DF_Rec_Plugin::instance()->settings();
            $client = new UIX_DF_Rec_Datafast_Client($settings);
            $cedulaRaw = UIX_DF_Rec_Plugin::read_order_identification_doc($order);
            $docId = UIX_DF_Rec_Plugin::normalize_identification($cedulaRaw);
            if ($docId === '') {
                UIX_DF_Rec_Logger::info('Errores de validación checkout', [
                    'order_id' => $order->get_id(),
                    'field' => 'billing_identification_doc_id',
                    'reason' => 'missing_or_invalid',
                ]);
                wc_print_notice('La cédula/RUC es obligatoria para Datafast (10 dígitos para customer.identificationDocId).', 'error');
                return;
            }

            UIX_DF_Rec_Logger::info('Valor normalizado identificación', [
                'order_id' => $order->get_id(),
                'raw' => UIX_DF_Rec_Plugin::mask_document($cedulaRaw),
                'normalized' => UIX_DF_Rec_Plugin::mask_document($docId),
            ]);

            $payload = UIX_DF_Rec_Plugin::build_initial_payload_from_order($order, $subscriptionId, $docId, $settings);

            UIX_DF_Rec_Logger::info('Inclusión SHOPPER_MID/TID', [
                'order_id' => $order->get_id(),
                'SHOPPER_MID' => $payload['customParameters[SHOPPER_MID]'] ?? null,
                'SHOPPER_TID' => $payload['customParameters[SHOPPER_TID]'] ?? null,
            ]);

            $payloadForLog = $payload;
            $payloadForLog['customer.identificationDocId'] = UIX_DF_Rec_Plugin::mask_document($payload['customer.identificationDocId'] ?? '');
            UIX_DF_Rec_Logger::info('Initial checkout request', [
                'order_id' => $order->get_id(),
                'subscription_id' => $subscriptionId,
                'payload' => $payloadForLog,
            ]);

            $response = $client->create_checkout($payload);
            UIX_DF_Rec_Logger::info('Initial checkout response', [
                'order_id' => $order->get_id(),
                'subscription_id' => $subscriptionId,
                'status' => $response['status'] ?? 0,
                'ok' => $response['ok'] ?? false,
                'body' => $response['body'] ?? [],
                'error' => $response['error'] ?? null,
            ]);

            if (!$response['ok'] || empty($response['body']['id'])) {
                wc_print_notice('No se pudo crear checkout Datafast. Revisa configuración y logs.', 'error');
                return;
            }

            $checkoutId = (string) $response['body']['id'];
            $this->repo->update_checkout($subscriptionId, $checkoutId);
            $order->update_meta_data('_uix_df_checkout_id', $checkoutId);
            $order->save();

            $returnUrl = add_query_arg([
                'uix_df_return' => 1,
                'subscription_id' => $subscriptionId,
                'order_id' => $order->get_id(),
                'key' => $order->get_order_key(),
            ], home_url('/'));
            $widgetJs = rtrim($settings['initial_base_url'], '/') . '/v1/paymentWidgets.js?checkoutId=' . rawurlencode($checkoutId);

            echo '<h3>Finaliza tu pago</h3>';
            echo '<script src="' . esc_url($widgetJs) . '"></script>';
            echo '<form action="' . esc_url($returnUrl) . '" class="paymentWidgets" data-brands="VISA MASTER AMEX DINERS DISCOVER"></form>';
            echo '<script src="https://www.datafast.com.ec/js/dfAdditionalValidations1.js"></script>';
        }
    }
}
