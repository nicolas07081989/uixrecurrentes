<?php

return array(
    'enabled'                => array(
        'title'   => __( 'Activo / Desactivo', 'datafast-gateway' ),
        'label'   => __( 'Habilita el botón en la pasarela de pago', 'datafast-gateway' ),
        'type'    => 'checkbox',
        'default' => 'no',
    ),
    'DATAFAST_DEV'            => array(
        'title'       => __( 'Test Mode', 'datafast-gateway' ),
        'label'       => __( 'Enable Test Mode', 'datafast-gateway' ),
        'type'        => 'checkbox',
        'description' => __( 'Usar el módulo en ambiente de pruebas' ),
        'default'     => 'yes',
    ),
    'DATAFAST_TITLE'                  => array(
        'title'    => __( 'Title', 'datafast-gateway' ),
        'type'     => 'text',
        'desc_tip' => __( 'Payment title the customer will see during the checkout process.', 'datafast-gateway' ),
        'default'  => __( 'Datafast', 'datafast-gateway' ),
    ),
    'DATAFAST_DESCRIPTION'            => array(
        'title'    => __( 'Descripción del botón', 'datafast-gateway' ),
        'type'     => 'textarea',
        'desc_tip' => __( 'Payment description the customer will see during the checkout process.', 'datafast-gateway' ),
        'default'  => __( 'Pay securely using your credit card.', 'datafast-gateway' ),
        'css'      => 'max-width:350px;'
    ),
    'DATAFAST_INSTRUCTIONS_SUCCESS'       => array(
        'title'       => __( 'Instrucciones en transacción exitosa', 'woocommerce' ),
        'type'        => 'textarea',
        'description' => __( 'Mensaje que se muestra al finalizar la transacción de forma exitosa.', 'woocommerce' ),
        'default'     => __( 'Su pago se ha realizado con éxito. Gracias por su compra.', 'woocommerce' ),
        'desc_tip'    => true,
        'css'      => 'max-width:350px;'
    ),
    'DATAFAST_INSTRUCTIONS_FAILED'       => array(
        'title'       => __( 'Instrucciones en transacción erronea', 'woocommerce' ),
        'type'        => 'textarea',
        'description' => __( 'Mensaje que se muestra al finalizar la transacción de forma erronea.', 'woocommerce' ),
        'default'     => __( 'Ocurrió un error al procesar el pago.', 'woocommerce' ),
        'desc_tip'    => true,
        'css'      => 'max-width:350px;'
    ),
    'DATAFAST_ENTITY_ID'              => array(
        'title'    => __( 'Entity ID', 'datafast-gateway' ),
        'type'     => 'text',
        'desc_tip' => __( 'Campo Entity ID otorgado por Datafast.', 'datafast-gateway' ),
    ),
    'DATAFAST_BEARER_TOKEN'             => array(
        'title'    => __( 'Authorization', 'datafast-gateway' ),
        'type'     => 'text',
        'desc_tip' => __( 'Bearer otorgado por Datafast.', 'datafast-gateway' ),
    ),
    'DATAFAST_MID'             => array(
        'title'    => __( 'MID', 'datafast-gateway' ),
        'type'     => 'text',
        'desc_tip' => __( 'MID otorgado por Datafast.', 'datafast-gateway' ),
    ),
    'DATAFAST_TID'             => array(
        'title'    => __( 'TID', 'datafast-gateway' ),
        'type'     => 'text',
        'desc_tip' => __( 'TID otorgado por Datafast.', 'datafast-gateway' ),
    ),
    'DATAFAST_RISK'             => array(
        'title'    => __( 'Risk', 'datafast-gateway' ),
        'type'     => 'text',
        'desc_tip' => __( 'Risk otorgado por Datafast.', 'datafast-gateway' ),
    ),
    'DATAFAST_PROVEEDOR'             => array(
        'title'    => __( 'Proveedor', 'datafast-gateway' ),
        'type'     => 'text',
        'desc_tip' => __( 'Código de proveedor otorgado por Datafast.', 'datafast-gateway' ),
    ),
    'DATAFAST_PREFIJOTRX'             => array(
        'title'    => __( 'Prefijo Trx', 'datafast-gateway' ),
        'type'     => 'text',
        'desc_tip' => __( 'Prefijo de las transacciones.', 'datafast-gateway' ),
    ),
    'DATAFAST_CUSTOMERTOKEN'                => array(
        'title'   => __( 'Tokeniza Tarjetas', 'datafast-gateway' ),
        'label'   => __( 'Habilita el check para tokenizar tarjetas', 'datafast-gateway' ),
        'type'    => 'checkbox',
        'default' => 'yes',
    ),
    'DATAFAST_STYLE'                => array(
        'title'   => __( 'Diseño Default', 'datafast-gateway' ),
        'label'   => __( 'Habilita el diseño default o plain del botón', 'datafast-gateway' ),
        'type'    => 'checkbox',
        'default' => 'yes',
    ),
    'DATAFAST_REQUIRECVV'                => array(
        'title'   => __( 'Pedir CVV', 'datafast-gateway' ),
        'label'   => __( 'Pedir CVV en tarjetas tokenizadas', 'datafast-gateway' ),
        'type'    => 'checkbox',
        'default' => 'yes',
    ),
    'DATAFAST_URL_TEST'                => array(
        'title'   => __( 'Url Desarrollo', 'datafast-gateway' ),
        'label'   => __( 'Configuración de url para Desarrollo', 'datafast-gateway' ),
        'type'    => 'select',
        'default' => 'test_2', 
        "std" => "",
        "options" => array( 'test_2' => __( 'eu-test.oppwa.com', 'datafast-gateway'),'test' => __( 'test.oppwa.com', 'datafast-gateway' )),
         
    ), 
    'DATAFAST_URL_PROD'                => array(
        'title'   => __( 'Url Producción', 'datafast-gateway' ),
        'label'   => __( 'Configuración de url para salida a Producción', 'datafast-gateway' ),
        'type'    => 'select',
        'default' => 'production_2', 
        "std" => "",
        "options" => array('production_2' => __( 'eu-prod.oppwa.com', 'datafast-gateway'), 'production' => __( 'oppwa.com', 'datafast-gateway' )),
         
    ), 
    'DATAFAST_VERSION'                  => array( 
        'title'    => __( 'Version', 'datafast-gateway' ),
        'type'     => 'text', 
        'default'  => __( '1.4.0', 'datafast-gateway' ), 
        'custom_attributes' => array('readonly'=>'readonly'),
    ),
    'DATAFAST_CUSTOM_DF_CEDULA'                  => array( 
        'title'    => __( 'Campo de Identificación', 'datafast-gateway' ), 
        'desc_tip'       => __( 'Este campo es para cambiar el input de la identificación. Solo cambiar valores si el comercio usa otro input(Comercio se responsabiliza en la captura y validación de este dato).', 'datafast-gateway' ),
        'type'     => 'text', 
        'default'  => __( 'df_cedula', 'datafast-gateway' ),  
        'required'     => true, 
    ),
    'DATAFAST_TWODECIMALS'            => array(
        'title'       => __( 'Forzar 2 decimales', 'datafast-gateway' ),
        'label'       => __( 'Forzar 2 decimales', 'datafast-gateway' ),
        'type'        => 'checkbox',
        'description' => __( 'Forzar calculo en 2 decimales' ),
        'default'     => 'no',
    )
);
