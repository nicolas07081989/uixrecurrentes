<?php
/**
 *
 */
require_once( dirname( __DIR__ ) . '/pg-woocommerce-plugin.php' );
require_once( dirname( __FILE__ ) . '/pg-woocommerce-helper.php' ); 
use Datafast\Payment\Model\Environment;
use Datafast\Payment\Model\Routes;

class WC_Datafast_Refund
{
  function refund($order_id)
  {
    global $wpdb;
    $table_name = $wpdb->base_prefix . 'datafast_transactions'; 
    $value = $wpdb->get_row($wpdb->prepare(
      " SELECT 	id,cart_id,checkout_id,transaction_id,amount
        FROM $table_name
        WHERE cart_id =%d AND payment_type=%s AND status=%d",$order_id,'DB',1)); 
    $paymentResp=$this->processRefund($value->transaction_id,$value->amount);
    $response =  json_decode($paymentResp[0], true);  
    $data = array( 
      'cart_id'=>$value->cart_id,
      'customer_id'=>isset($response['customer']['merchantCustomerId'])?$response['customer']['merchantCustomerId']:null,
      'checkout_id'=>$value->checkout_id,
      'result_description'=>isset($response['result']['description'])?$response['result']['description']:null,
      'transaction_id'=>isset($response['id'])?$response['id']:null,
      'payment_type'=>isset($response['paymentType'])?$response['paymentType']:null,
      'payment_brand'=>isset($response['paymentBrand'])?$response['paymentBrand']:null,
      'amount'=>isset($response['amount'])?str_replace(',','',$response['amount'] ):null,
      'merchant_transactionId'=>isset($response['merchantTransactionId'])?$response['merchantTransactionId']:null,
      'result_code'=>isset($response['result']['code'])?$response['result']['code']:null,
      'extended_description'=>isset($response['resultDetails']['ExtendedDescription'])?$response['resultDetails']['ExtendedDescription']:null,
      'acquirer_response'=>isset($response['resultDetails']['Response'])?$response['resultDetails']['Response']:null,
      'auth_code'=>isset($response['resultDetails']['AuthCode'])?$response['resultDetails']['AuthCode']:null,
      'response'=>isset($response['resultDetails']['response'])?$response['resultDetails']['response']:null,
      'acquirer_code'=>isset($response['resultDetails']['AcquirerCode'])?$response['resultDetails']['AcquirerCode']:null,
      'batch_no'=>isset($response['resultDetails']['BatchNo'])?$response['resultDetails']['BatchNo']:null,
      'interest'=>isset($response['resultDetails']['Interest'])?str_replace(',','',$response['resultDetails']['Interest'] ):null,
      'total_amount'=>isset($response['resultDetails']['TotalAmount'])? str_replace(',','',$response['resultDetails']['TotalAmount'] ):null,
      'reference_no'=>isset($response['resultDetails']['ReferenceNo'])?$response['resultDetails']['ReferenceNo']:null,
      'bin'=>isset($response['card']['bin'])?$response['card']['bin']:null,
      'last_4_digits'=>isset($response['card']['last4Digits'])?$response['card']['last4Digits']:null,
      'email'=>isset($response['customer']['email'])?$response['customer']['email']:null,
      'shopper_mid'=>isset($response['customParameters']['SHOPPER_MID'])?$response['customParameters']['SHOPPER_MID']:null,
      'shopper_tid'=>isset($response['customParameters']['SHOPPER_TID'])?$response['customParameters']['SHOPPER_TID']:null,
      'timestamp'=>isset($response['timestamp'])?$response['timestamp']:null,
      'response_json'=>isset($paymentResp[0])?$paymentResp[0]:null,
      'request_json'=>isset($paymentResp[1])?$paymentResp[1]:null,
      'updated_at'=>null,
      'status'=>((isset($response['result']['code'])&&
      ($response['result']['code']=="000.000.000"||
      $response['result']['code']=="000.200.100"||
      $response['result']['code']=="000.100.112"||
      $response['result']['code']=="000.100.110")?2:0))
    ); 
    if ($data['status']==0)
    {
      echo "Error en la transaccion - "; 
    }
    global $wpdb; 
    $resultdb = $wpdb->insert($table_name, $data);
    if (!$resultdb)
    {
      echo "Error al guardar la transacción .<br>".$wpdb->last_error." <br>";
    }
    if ($data['status']==2)
    {
      $wpdb->query("UPDATE $table_name 
                    SET status=2,
                    updated_at='".
                    date("Y-m-d H:i:s")."' 
                    WHERE id =$value->id"); 
    } 
  }
  
  function processRefund($idTrx,$amount)
  { 
    $amount =  number_format($amount,2,'.','');
    $options = get_option('woocommerce_pg_woocommerce_settings'); 
    $ambiente = $options['DATAFAST_DEV'] ;
    $urlProd =$options['DATAFAST_URL_PROD'];
    $urlTest = $options['DATAFAST_URL_TEST'];
    $instance = new Environment();
    $arrayUrl = $instance->Url($ambiente,$urlTest,$urlProd);
    $url = $arrayUrl[0].Routes::refund."/".$idTrx;
    $verifyPeer = $arrayUrl[1];  
    $data = "entityId=".$options['DATAFAST_ENTITY_ID'];
    $data .= "&paymentType=RF";
    $data .= "&amount=$amount";
    $data .= "&currency=USD";
    $data .="&customParameters[SHOPPER_VERSIONDF]=2";
    if($ambiente == "yes")
        $data .= "&testMode=EXTERNAL"; 
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization:Bearer 	'.$options['DATAFAST_BEARER_TOKEN'] ));
    curl_setopt($ch, CURLOPT_POST, 1); 
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifyPeer); // this should be set to true in production
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $responseData = curl_exec($ch);
    if (curl_errno($ch)) {
      return curl_error($ch);
    }
    curl_close($ch);
    return array($responseData,$data);
  }
}
