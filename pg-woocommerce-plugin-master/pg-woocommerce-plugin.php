<?php

/*
  Plugin Name: Datafast WooCommerce Plugin
  Plugin URI: https://www.datafast.com.ec/
  Description: Modulo de pagos - Datafast
  Version: 1.4.0
  Author: Datafast
  Author URI: https://www.datafast.com.ec/
  Text Domain: pg_woocommerce
  Domain Path: /languages
  License: GPLv3
  License URI: https://www.gnu.org/licenses/gpl-3.0.html
  */

include(dirname(__FILE__) . '/includes/Environment.php');
use Datafast\Payment\Model\Environment;
use Datafast\Payment\Model\Routes;
add_action('plugins_loaded', 'pg_woocommerce_plugin'); 

include(dirname(__FILE__) . '/includes/pg-woocommerce-helper.php');
register_activation_hook(__FILE__, array('WC_Datafast_Database_Helper', 'create_database'));
register_deactivation_hook(__FILE__, array('WC_Datafast_Database_Helper', 'delete_database'));

require(dirname(__FILE__) . '/includes/pg-woocommerce-refund.php');

load_plugin_textdomain('pg_woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages/');

define("PG_DOMAIN", "https://www.datafast.com.ec/");
define("PG_REFUND", "/v2/transaction/refund/");

// TODO: Mover la function datafast_woocommerce_order_refunded
// define the woocommerce_order_refunded callback
function datafast_woocommerce_order_refunded($order_id, $refund_id)
{
  $refund = new WC_Datafast_Refund();
  $refund->refund($order_id);
}

// add the action
add_action('woocommerce_order_refunded', 'datafast_woocommerce_order_refunded', 10, 2); 

if (!function_exists('pg_woocommerce_plugin')) {
  function pg_woocommerce_plugin()
  {
    if (!class_exists('WC_Payment_Gateway')) {
      return;
    }

    class WC_Gateway_Datafast extends WC_Payment_Gateway
    { 
      public function __construct()
      {
        # $this->has_fields = true;
        $this->id = 'pg_woocommerce';
        $this->has_fields = false;
        $this->supports = array('products');
        $this->icon = apply_filters('woocomerce_datafast_icon', plugins_url('/assets/imgs/datafastcheck.png', __FILE__));
        $this->method_title = 'Datafast Plugin';
        $this->method_description = __('Modulo de pagos - Datafast', 'pg_woocommerce');

        $this->init_settings();
        $this->init_form_fields();

        $this->enabled = $this->get_option('enabled', 'no');
        $this->title = $this->get_option('DATAFAST_TITLE');
        $this->description        = $this->get_option( 'DATAFAST_DESCRIPTION' ); 
        $this->instructions_success       = $this->get_option( 'DATAFAST_INSTRUCTIONS_SUCCESS' );
        $this->instructions_failed       = $this->get_option( 'DATAFAST_INSTRUCTIONS_FAILED' );
        $this->datafast_version ='1.4.0'; 

        $this->checkout_language = $this->get_option('checkout_language');
        $this->enviroment = $this->get_option('staging'); 

        // Para guardar sus opciones, simplemente tiene que conectar la función process_admin_options en su constructor.
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));

        add_action('woocommerce_receipt_pg_woocommerce', array(&$this, 'receipt_page')); 
      }

      public function init_form_fields()
      {
        $this->form_fields = require(dirname(__FILE__) . '/includes/admin/datafast-settings.php');
      }

      function admin_options()
      {
        $logo = plugins_url('/assets/imgs/datafast.png', __FILE__);
?>
        <p>
          <img style='width: 30%;position: relative;display: inherit;' src='<?php echo $logo; ?>'>
        </p>
        <h2><?php _e('Datafast Gateway', 'pg_woocommerce'); ?></h2>
        <table class="form-table">
          <?php $this->generate_settings_html(); ?>
        </table>
        <?php
      }

      public function validateTransaction(string $resultCode): bool
      { 
        return $resultCode == "000.000.000" ||
        $resultCode == "000.200.100" ||
        $resultCode == "000.100.112" ||
        $resultCode == "000.100.110";
      }  
      function receipt_page($order)
      {
        $paymentDatafast = $_GET["paymentDatafast"]??'';
        if ($paymentDatafast == "confirm") {
          $_id = $_GET["id"];
          $_resourcePath = $_GET["resourcePath"];
          $paymentResp = $this->processPayment($_resourcePath); 
          $response =  json_decode($paymentResp, true);
          $resultCode = $response["result"]["code"];
          $data = array(
            'cart_id' => $order,
            'customer_id' => isset($response['customer']['merchantCustomerId']) ? $response['customer']['merchantCustomerId'] : null,
            'checkout_id' => $_id,
            'result_description' => isset($response['result']['description']) ? $response['result']['description'] : null,
            'transaction_id' => isset($response['id']) ? $response['id'] : null,
            'payment_type' => isset($response['paymentType']) ? $response['paymentType'] : null,
            'payment_brand' => isset($response['paymentBrand']) ? $response['paymentBrand'] : null,
            'amount' => isset($response['amount']) ? str_replace(',','',$response['amount'] ) : null,
            'merchant_transactionId' => isset($response['merchantTransactionId']) ? $response['merchantTransactionId'] : null,
            'result_code' => isset($response['result']['code']) ? $response['result']['code'] : null,
            'extended_description' => isset($response['resultDetails']['ExtendedDescription']) ? $response['resultDetails']['ExtendedDescription'] : null,
            'acquirer_response' => isset($response['resultDetails']['Response']) ? $response['resultDetails']['Response'] : null,
            'auth_code' => isset($response['resultDetails']['AuthCode']) ? $response['resultDetails']['AuthCode'] : null,
            'response' => isset($response['resultDetails']['response']) ? $response['resultDetails']['response'] : null,
            'acquirer_code' => isset($response['resultDetails']['AcquirerCode']) ? $response['resultDetails']['AcquirerCode'] : null,
            'batch_no' => isset($response['resultDetails']['BatchNo']) ? $response['resultDetails']['BatchNo'] : null,
            'interest' => isset($response['resultDetails']['Interest']) ? str_replace(',','',$response['resultDetails']['Interest']) : null,
            'total_amount' => isset($response['resultDetails']['TotalAmount']) ? str_replace(',','',$response['resultDetails']['TotalAmount']) : null,
            'reference_no' => isset($response['resultDetails']['ReferenceNo']) ? $response['resultDetails']['ReferenceNo'] : null,
            'bin' => isset($response['card']['bin']) ? $response['card']['bin'] : null,
            'last_4_digits' => isset($response['card']['last4Digits']) ? $response['card']['last4Digits'] : null,
            'email' => isset($response['customer']['email']) ? $response['customer']['email'] : null,
            'shopper_mid' => isset($response['customParameters']['SHOPPER_MID']) ? $response['customParameters']['SHOPPER_MID'] : null,
            'shopper_tid' => isset($response['customParameters']['SHOPPER_TID']) ? $response['customParameters']['SHOPPER_TID'] : null,
            'timestamp' => isset($response['timestamp']) ? $response['timestamp'] : null,
            'response_json' => isset($paymentResp) ? $paymentResp : null,
            'request_json' => null,
            'updated_at' => null,
            'status' => ((isset($response['result']['code']) &&
              ($response['result']['code'] == "000.000.000" ||
                $response['result']['code'] == "000.200.100" ||
                $response['result']['code'] == "000.100.112" ||
                $response['result']['code'] == "000.100.110") ? 1 : 0))
          );  
          if (!$data['status']) {
            echo "Error en la transaccion - ";
          }
          global $wpdb;
          $table_name = $wpdb->base_prefix . 'datafast_transactions';
          
          $resultdb = $wpdb->insert($table_name, $data); 
          if (!$resultdb) {
            echo "Error al guardar la transacción .<br>".$wpdb->last_error." <br>";
          }
          $orderObj = new WC_Order($order);
          if ($data['amount']!=null && $data['amount'] != number_format($orderObj->get_total(), 2,'.','')) {
            $refundObj = new WC_Datafast_Refund();
            $refundObj->refund($order);
            echo "Los valores del carrito de compra fueron cambiados. Se procederá a anular la transacción para que pueda repetir su pago.";
            return ;
          }
          if (
            $this->get_option('DATAFAST_CUSTOMERTOKEN') == 'yes' &&
            isset($response['registrationId']) &&
            isset($response['customer']['merchantCustomerId']) &&
            $data['status']
          ) {
            $table_name = $wpdb->base_prefix . 'datafast_customertoken';
            $tokens = $wpdb->prepare(
              "SELECT count(token) as 'count' 
                FROM $table_name 
                WHERE status=%s and 
                customer_id=%s and token=%s",'1',$data["customer_id"],$response['registrationId']
            );
            if ($wpdb->get_results($tokens)[0]->count == '0') {
              $data = array(
                'customer_id' => isset($response['customer']['merchantCustomerId']) ? $response['customer']['merchantCustomerId'] : null,
                'token' => isset($response['registrationId']) ? $response['registrationId'] : null,
                'status' => 1,
                'updated_at' => null
              ); 
              $resultdb = $wpdb->insert($table_name, $data);
              if (!$resultdb) {
                echo "Error al guardar gatos de tarjeta."; 
              } 
            }
          }
          echo ((isset($response['resultDetails']['ExtendedDescription']) ? $response['resultDetails']['ExtendedDescription']:''));
          $accepted = $this->validateTransaction($resultCode); 
          if ($accepted == true) {
            global $woocommerce; 
            $orderObj->update_status('completed');
            wc_reduce_stock_levels($orderObj->get_id());
            $woocommerce->cart->empty_cart();
            $orderObj->add_order_note( __('Your payment has been made successfully. Transaction Code: ') . 
            $response['id']. __(' and its Authorization Code is: ') . $response['resultDetails']['AuthCode']); 
            WC()->cart->empty_cart();
        ?>
            <div id="mensajeSucccess">
              <p ><?php _e($this->instructions_success, 'pg_woocommerce'); ?></p>
            </div>
          <?php
          } else {  
            $orderObj->update_status('failed');
            $orderObj->add_order_note( 
              __('Your payment has failed. Transaction Code: ') . 
              $response['id'] . __(' the reason is: ') . $response['result']['description']);

          ?>
            <div id="mensajeFailed">
              <p ><?php _e($this->instructions_failed, 'pg_woocommerce'); ?></p>
            </div>
          <?php
          }
          ?>
          <div id="buttonreturn" class="hide">
            <p>
              <a class="btn-tienda" href="<?php echo get_permalink(wc_get_page_id('shop')); ?>"><?php _e('Regresar a la tienda', 'woocommerce') ?></a>
            </p>
          </div>
        <?php  
        } else {
          echo $this->generate_datafast_form($order);
        }
      }


      function processPayment($resourcePath)
      {
        $ambiente = $this->get_option('DATAFAST_DEV');
        $urlProd = $this->get_option('DATAFAST_URL_PROD');
        $urlTest = $this->get_option('DATAFAST_URL_TEST');
        $instance = new Environment();
        $arrayUrl = $instance->Url($ambiente,$urlTest,$urlProd);
        $url = $arrayUrl[0]. $resourcePath; 
        $verifyPeer = $arrayUrl[1];
        $url .= "?entityId=" . $this->get_option('DATAFAST_ENTITY_ID');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization:Bearer 	' . $this->get_option('DATAFAST_BEARER_TOKEN')));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifyPeer); // this should be set to true in production
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $responseData = curl_exec($ch);
        if (curl_errno($ch)) {
          return curl_error($ch);
        }
        curl_close($ch);
        return $responseData;
      } 
      public function generate_datafast_form($orderId)
      {
        $ambiente = $this->get_option('DATAFAST_DEV');
        $urlProd = $this->get_option('DATAFAST_URL_PROD');
        $urlTest = $this->get_option('DATAFAST_URL_TEST');
        $instance = new Environment();
        $arrayUrl = $instance->Url($ambiente,$urlTest,$urlProd);
        $url = $arrayUrl[0].Routes::getCheckoutId;
        $verifyPeer = $arrayUrl[1]; 
        $order = new WC_Order($orderId);
        $urlreturn = $order->get_checkout_payment_url(true) . "&paymentDatafast=confirm";
 
        $data = $this->buildInitialBody($order);
        
        $body = http_build_query($data);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization:Bearer 	' . $this->get_option('DATAFAST_BEARER_TOKEN')));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifyPeer); // this should be set to true in production
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $responseData = curl_exec($ch);
        if (curl_errno($ch)) {
          return curl_error($ch);
        }
        curl_close($ch);
        $objRequest =  json_decode($responseData, true);
        $resultCode = $objRequest["result"]["code"];

        if ($resultCode == "000.200.100") {
          $checkoutId = $objRequest["id"];
        }
        //echo '>>>>>>>>>>>>>>>>> '.__($checkoutId).' <<<<<<<<<<<<<<<<<<<<<<';
        global $wpdb;
        $table_name = $wpdb->base_prefix . 'datafast_installments';
        $join_table_name = $wpdb->base_prefix . 'datafast_termtype';
        $termtypes = $wpdb->prepare(
          "SELECT $table_name.* , $join_table_name.code
            FROM $table_name 
            INNER JOIN $join_table_name ON $join_table_name.id=$table_name.id_termtype
            WHERE $table_name.active=%d and ($table_name.deleted is null or $table_name.deleted =%d)",1,0
        );
        $options = '';
        $defaultcode='00';
        $defaultInstallments='00';
        $i=0;
        foreach ($wpdb->get_results($termtypes) as $key => $value) {
          $options .= "<option value='" . $value->code . "|" . $value->installments . "'>" . $value->name . "</option>";
          if($i==0){
            $defaultcode=$value->code;
            $defaultInstallments=$value->installments;
          }
          $i++;
        }
        ?>
        <style>
          .wpwl-icon
          {
              top:0.25em !important;
          }
          /*Borrar token*/
            .wpwl-wrapper-registration-registrationId{
              width: 8.33333333%;
            }
            .wpwl-wrapper-registration-brand{
              width: 14.66666667%;
            }
            .wpwl-wrapper-registration-details{
              width: 56.33333333%;
            }
            #deleteButton{ 
              float: right;   
              background-color: #d44950;
            } 
          /* */
        </style>
        <script type="text/javascript" src="https://code.jquery.com/jquery-3.2.1.js" defer></script>
        <script src="<?php    
          echo $arrayUrl[0].Routes::paymentWidget.'?checkoutId='.$checkoutId; 
        ?>" defer></script>

        <script type="text/javascript" defer>
          //Borrar token
            function deleteToken(obj){
              if(confirm("¿Deseas eliminar esta tarjeta?")){
                let token =$(obj).parent().find('label .wpwl-wrapper-registration-registrationId input');
                let isChecked = token.checked;
                var templateUrl = '<?= get_site_url(); ?>'; 
                logFetch(templateUrl+'/wp-json/datafast/deleteCard?token='+token.val()).then(response=>{
                  if(response=='true'){
                    alert('Tarjeta eliminada.');
                    $(obj).parent().remove();
                    if($('input[name="registrationId"]').length==0){
                      $('button[data-action="show-initial-forms"]').click();
                    }else{
                      $('label .wpwl-wrapper-registration-registrationId input')[0].click()
                    }
                  }else
                    alert('No se pudo eliminar la tarjeta.');
                });
              }
            }
            async function logFetch(url) {
              try {
                const response = await fetch(url, {
                    method: 'DELETE' 
                });
                return await response.text();
              }
              catch (err) {
                alert('Ocurrio un error cuando se intento elminar la tarjeta.');
                console.log('error', err);
              }
            }
          //
          function setInstallment(selObj) {
            var isRegistration = (selObj.parentElement.parentElement.parentElement.parentElement.className+"").includes('wpwl-form-registrations');
            var form=isRegistration?'Registration':'Card';
            var objNumInstall = document.getElementById("numinstall"+form);
            var objCreditType = document.getElementById("termtype"+form);
            var res = selObj.value.split("|");
            objCreditType.value = res[0];
            objNumInstall.value = res[1]; 
          }
          var wpwlOptions = {
            onReady: function(onReady) { 
              if ("<?php echo $this->get_option('DATAFAST_CUSTOMERTOKEN'); ?>" == "yes" && "<?php echo $order->get_customer_id(); ?>"  !='0') {
                var createRegistrationHtml = '<div class="customLabel">Desea guardar de manera segura sus datos?</div><div class="customInput">' +
                  '<input type="checkbox" name="createRegistration" /></div>';
                $('form.wpwl-form-card').find('.wpwl-button').before(createRegistrationHtml);
              }  
              var tipocredito = '<div class="wpwl-group installments-group  wpwl-clearfix">' +
                '<div class="wpwl-label ">' +
                '   Tipo de Crédito' +
                '</div>' +
                '<select id="cboInstallments" class="wpwl-control" onChange="javascript:setInstallment(this);">' +
                "<?php echo $options; ?>" + 
                '</div></div>';
              $('form.wpwl-form-card').find('.wpwl-button').before(tipocredito);
              $('form.wpwl-form-registrations').find('.wpwl-button').before(tipocredito);
              var termtype=(form)=> '<input type="hidden" id="termtype'+form+'" name="customParameters[SHOPPER_TIPOCREDITO]" value="<?php echo $defaultcode; ?>">';
              $('form.wpwl-form-card').find('.wpwl-button').before(termtype('Card'));
              $('form.wpwl-form-registrations').find('.wpwl-button').before(termtype('Registration'));

              var datafast = '<br/><br/><img src=' + '"https://www.datafast.com.ec/images/verified.png" style=' + '"display:block;margin:0 auto; width:100%;">';
              $('form.wpwl-form-card').find('.wpwl-button').before(datafast);


              var installs =(form)=>  '<input type="hidden" id="numinstall'+form+'" name="recurring.numberOfInstallments" value="<?php echo $defaultInstallments; ?>">';
              $('form.wpwl-form-card').find('.wpwl-button').before(installs('Card'));
              $('form.wpwl-form-registrations').find('.wpwl-button').before(installs('Registration'));
 
              $(".wpwl-button").on("click", function() {
                var attr = $(this).attr("data-action");
                if (attr == 'show-initial-forms') {
                  $('.wpwl-form-registrations').fadeOut('slow');
                }  
              }); 
              //Borrar token
                var deleteButton =`
                <div id="deleteButton" onClick='deleteToken(this)' class="wpwl-icon ui-state-default ui-corner-all delete" type="button">
                  <span class="ui-icon ui-icon-close"></span>
                </div>`;
                $('form.wpwl-form-registrations').find('.wpwl-registration').after(deleteButton);
              //
            },
            style: ("<?php echo $this->get_option('DATAFAST_STYLE'); ?>" == "yes" ? "card" : "plain"), 
            onBeforeSubmitCard: function(e) { 
              const holder = $('.wpwl-control-cardHolder').val();
              if (holder.trim().length < 2) {
                $('.wpwl-control-cardHolder').addClass('wpwl-has-error').after('<div class="wpwl-hint wpwl-hint-cardHolderError">Nombre del titular de la tarjeta no válido</div>');
                $(".wpwl-button-pay").addClass('wpwl-button-error').attr('disabled','disabled');
                return false;
              } 
              return true;
            },
            locale: "es",
            maskCvv: true,
            brandDetection: true,
            brandDetectionPriority: ["VISA","ALIA","MASTER","AMEX","DINERS","DISCOVER"], 
            labels: {
              cvv: "CVV",
              cardHolder: "Nombre(Igual que en la tarjeta)"
            },
            registrations: {
              requireCvv:("<?php echo $this->get_option('DATAFAST_REQUIRECVV'); ?>" == "yes"),
              hideInitialPaymentForms: true
            }
          }
        </script>

        <form action="<?php echo ($urlreturn); ?>" class="paymentWidgets" id="datafastPaymentForm" data-brands="VISA MASTER DINERS DISCOVER AMEX ALIA">
        </form>

<?php 
      }
      public function buildInitialBody($order)
      {
        $DATAFAST_TWODECIMALS = $this->get_option('DATAFAST_TWODECIMALS');
        foreach ($order->get_meta_data() as $key => $value)
          if (isset($value->get_data()['key'])&&$value->get_data()['key']==trim($this->get_option('DATAFAST_CUSTOM_DF_CEDULA')))
            $df_cedulaArray[]=$value->get_data()['value'];
        $orderJson = json_decode($order);  
        if(!isset($df_cedulaArray) || !isset($df_cedulaArray[sizeof($df_cedulaArray)-1])){
          echo"No tienes una identificación configurada en tu cuenta ó es erronea.";die;
        }
        $df_cedula=$df_cedulaArray[sizeof($df_cedulaArray)-1];
 
        $billing = $orderJson->billing;
        $shipping = $orderJson->shipping;
        $BASE0=0.00;
        $BASEIMP=0.00;  
        foreach ($order->get_items() as $item) {
          if(!($item['total_tax']>0))
              $BASE0=$BASE0+(($DATAFAST_TWODECIMALS == "yes")?number_format($item['total'],2,'.',''):$item['total']);
          else
              $BASEIMP=$BASEIMP+(($DATAFAST_TWODECIMALS == "yes")?number_format($item['total'],2,'.',''):$item['total']);  
        }  
        if(!($order->get_shipping_tax()>0)){
          $BASE0=$BASE0+(($DATAFAST_TWODECIMALS == "yes")?number_format($order->get_shipping_total(),2,'.',''):$order->get_shipping_total());
        }
        else{
          $BASEIMP=$BASEIMP+(($DATAFAST_TWODECIMALS == "yes")?number_format($order->get_shipping_total(),2,'.',''):$order->get_shipping_total());
        }
        $BASE0=number_format($BASE0,2,'.','');
        $BASEIMP=number_format($BASEIMP,2,'.',''); 
        global $wpdb;
        $table_name = $wpdb->base_prefix . 'datafast_customertoken';
        $tokens = $wpdb->prepare("SELECT token FROM $table_name WHERE status=%s and customer_id=%s",'1',$order->get_customer_id());

        $return = [
          'entityId' => $this->get_option('DATAFAST_ENTITY_ID'),
          'amount' => number_format($order->get_total(), 2,'.',''),
          'currency' => 'USD',
          'paymentType' => 'DB',
          'customer.givenName' => $billing->first_name,
          'customer.identificationDocId' => $df_cedula,
          'customer.middleName' => '',
          'customer.surname' => $billing->last_name,
          'customer.ip' => $order->get_customer_ip_address(),
          'customer.merchantCustomerId' => ($order->get_customer_id()==0)?($this->get_option('DATAFAST_PREFIJOTRX') . date("dmYHis")):$order->get_customer_id(),
          'merchantTransactionId' => $this->get_option('DATAFAST_PREFIJOTRX') . $order->get_data()['id'].'_'.date("dmYHis"),
          'customer.email' => $billing->email,
          "customer.identificationDocType"=>"IDCARD",
          'customer.phone' => $billing->phone,

          'billing.street1' => $billing->address_1,
          'billing.country' => $billing->country,
          'billing.postcode' => $billing->postcode,

          'shipping.street1' => $shipping->address_1,
          'shipping.country' => $shipping->country,

          'risk.parameters[USER_DATA2]' => $this->get_option('DATAFAST_RISK'),

          'customParameters[SHOPPER_MID]' => $this->get_option('DATAFAST_MID'),
          'customParameters[SHOPPER_TID]' => $this->get_option('DATAFAST_TID'),
          'customParameters[SHOPPER_PSERV]' => $this->get_option('DATAFAST_PROVEEDOR'),

          'customParameters[SHOPPER_VAL_BASE0]' => number_format($BASE0,2,'.',''),
          'customParameters[SHOPPER_VAL_BASEIMP]' => number_format($BASEIMP,2,'.',''),
          'customParameters[SHOPPER_VAL_IVA]' => number_format(($order->get_total_tax()), 2,'.',''),

          'customParameters[SHOPPER_VERSIONDF]' => '2' 
          //,'recurringType' => 'REPEATED'
        ];
        if($this->get_option('DATAFAST_DEV') == "yes")
          $return["testMode"]='EXTERNAL';  
        $i = 0;
        if($order->get_customer_id()!=0)
        foreach ($wpdb->get_results($tokens) as $key => $value) {
          $return["registrations[$i].id"] = $value->token;
          $i++;
        } 
        $i=0; 
        foreach ($order->get_items() as $item) { 
          $return["cart.items[$i].name"] = $item['name'];
          $return["cart.items[$i].description"] = 'Descripción: '.$item['name'];
          $return["cart.items[$i].price"] = number_format($item['total']/$item['quantity'],2,'.','');
          $return["cart.items[$i].quantity"] = number_format($item['quantity'],0); 
          $i++;
        }   
        if( $order->get_shipping_total()>0 ){
          $return["cart.items[$i].name"] = 'Envío';
          $return["cart.items[$i].description"] = 'Descripción: Servicio de Envío';
          $return["cart.items[$i].price"] = number_format($order->get_shipping_total(), 2,'.','');
          $return["cart.items[$i].quantity"] = 1; 
        } 
        return $return;
      }
      public function process_payment($orderId)
      {
        $order = new WC_Order($orderId);
        return array(
          'result' => 'success',
          'redirect' => $order->get_checkout_payment_url(true)
        );
      }
    }
  }
}
 
function add_pg_woocommerce_plugin($methods)
{
  $methods[] = 'WC_Gateway_Datafast';
  return $methods;
}

add_filter('woocommerce_payment_gateways', 'add_pg_woocommerce_plugin');

defined('ABSPATH') or die('¡rutas!');
require plugin_dir_path(__FILE__) . 'creditTypes/metabox-credictTypes.php';
require plugin_dir_path(__FILE__) . 'transactions/metabox-transactions.php';

if (!class_exists('WP_List_Table')) {
  require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}
require plugin_dir_path(__FILE__) . 'creditTypes/CreditTypes_Table_List_Table.php';
require plugin_dir_path(__FILE__) . 'transactions/Transactions_Table_List_Table.php';
require plugin_dir_path(__FILE__) . 'transactions/getTransactions.php';
require plugin_dir_path(__FILE__) . 'testing/TestingAndInfoPage.php';
require plugin_dir_path(__FILE__) . 'Api/Api.php';

function wpbc_admin_menu()
{
  add_menu_page(__('Datafast'), __('Datafast'), 'activate_plugins', 'transactions', 'transactions_page_handler');
  add_submenu_page('transactions', __('Transacciones'), __('Transacciones'), 'activate_plugins', 'transactions', 'transactions_page_handler');
 
  add_submenu_page('transactions', __('Tipos de Credito'), __('Tipos de Credito'), 'activate_plugins', 'creditTypes', 'creditTypes_page_handler');
  
  add_submenu_page('transactions', __('Info'), __('Info'), 'activate_plugins', 'testing', 'testing_page_handler');
  add_submenu_page('transactions', __('Recuperar transacciones'), __('Recuperar transacciones'), 'activate_plugins', 'getTransactions', 'getTransactions_handler');
  
  add_submenu_page('', '',  '', 'activate_plugins', 'creditTypes_form', 'creditTypes_form_page_handler'); 
  add_submenu_page('',  '', '', 'activate_plugins', 'transactions_form', 'transactions_form_page_handler');
}

add_action('admin_menu', 'wpbc_admin_menu');
//APIS
add_action( 'rest_api_init', function () {
  register_rest_route( 'datafast', '/deleteCard', array(
    'methods' => 'DELETE',
    'callback' => 'deleteCard',
  ) );
} );
add_action( 'rest_api_init', function () {
  register_rest_route( 'datafast', '/testConection', array(
    'methods' => 'GET',
    'callback' => 'testConection',
  ) );
} );
$options = get_option('woocommerce_pg_woocommerce_settings');   
$datafast_custom_df_cedula=$options['DATAFAST_CUSTOM_DF_CEDULA'];
if( $datafast_custom_df_cedula == null || !isset($datafast_custom_df_cedula)|| trim($datafast_custom_df_cedula)=='' || trim($datafast_custom_df_cedula)=='df_cedula'){
  include(dirname(__FILE__) . '/includes/cedulaform.php');
}
