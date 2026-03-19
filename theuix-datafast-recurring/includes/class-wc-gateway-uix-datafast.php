<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WC_Gateway_UIX_Datafast')) {
    class WC_Gateway_UIX_Datafast extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = 'uix_datafast';
            $this->method_title = 'Datafast';
            $this->method_description = 'Pago con Datafast';
            $this->has_fields = false;
            $this->supports = array('products');

            $this->init_form_fields();
            $this->init_settings();

            $this->enabled = $this->get_option('enabled', 'yes');
            $this->title = $this->get_option('title', 'Datafast');
            $this->description = $this->get_option('description', 'Pago con Datafast');

            add_action(
                'woocommerce_update_options_payment_gateways_' . $this->id,
                array($this, 'process_admin_options')
            );
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Activar/Desactivar',
                    'type' => 'checkbox',
                    'label' => 'Activar Datafast',
                    'default' => 'yes',
                ),
                'title' => array(
                    'title' => 'Título',
                    'type' => 'text',
                    'default' => 'Datafast',
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => 'Descripción',
                    'type' => 'textarea',
                    'default' => 'Pago con Datafast',
                    'desc_tip' => true,
                ),
            );
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);

            return array(
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true),
            );
        }
    }
}
