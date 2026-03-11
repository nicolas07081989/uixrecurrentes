<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WC_Payment_Gateway')) {
    return;
}

class UIX_DF_Rec_WC_Gateway extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = 'uix_df_recurring';
        $this->method_title = 'UIX Datafast Recurrentes';
        $this->method_description = __('Cobros iniciales con tokenización para suscripciones recurrentes Datafast.', 'uix-df-rec');
        $this->has_fields = false;
        $this->supports = ['products'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title', 'Tarjeta de crédito / débito (Datafast)');
        $this->description = $this->get_option('description', 'Paga de forma segura con Datafast.');
        $this->enabled = $this->get_option('enabled', 'no');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Habilitar/Deshabilitar', 'uix-df-rec'),
                'type' => 'checkbox',
                'label' => __('Habilitar UIX Datafast Recurrentes', 'uix-df-rec'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Título', 'uix-df-rec'),
                'type' => 'text',
                'default' => __('Tarjeta de crédito / débito (Datafast)', 'uix-df-rec'),
            ],
            'description' => [
                'title' => __('Descripción', 'uix-df-rec'),
                'type' => 'textarea',
                'default' => __('Paga de forma segura con Datafast.', 'uix-df-rec'),
            ],
        ];
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        return [
            'result' => 'success',
            'redirect' => add_query_arg([
                'uix_df_wc_checkout' => 1,
                'order_id' => $order_id,
                'key' => $order ? $order->get_order_key() : '',
            ], home_url('/')),
        ];
    }
}
