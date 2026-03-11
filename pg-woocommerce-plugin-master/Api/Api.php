<?php 
use Datafast\Payment\Model\Environment;
use Datafast\Payment\Model\Routes;

//borrar token
function deleteCard($data)
{  
    if (!isset($data['token'])) {
        return false;
    }
    global $wpdb;
    $table_name = $wpdb->base_prefix . 'datafast_customertoken';
    $tokens = $wpdb->prepare("SELECT * FROM $table_name WHERE status=%s and token=%s", '1', $data['token']);
    $i = 0;
    foreach ($wpdb->get_results($tokens) as $key => $value) {
        $responseJson = deleteCardRequest($data['token']);
        $response =  json_decode($responseJson, true); 
        if ((isset($response['result']['code']) &&
            ($response['result']['code'] == "000.000.000" ||
                $response['result']['code'] == "000.200.100" ||
                $response['result']['code'] == "000.100.112" ||
                $response['result']['code'] == "000.100.110"))) {
            $wpdb->query("UPDATE $table_name SET status=2 WHERE status='1' and token='" .
                $data['token'] . "'");
            $i++;
        } else
            return false;
    }
    return $i > 0;
}

function testConection($data)
{ 
    if (!isset($data['pro'])) {
        return false;
    } 
    $pro = ($data['pro']=='1' || $data['pro']=='2');
    switch ($data['pro']) {
      case '0':
        $url = Environment::test.Routes::getCheckoutId; 
      break; 
      case '1':
        $url = Environment::test_2.Routes::getCheckoutId; 
      break; 
      case '2':
        $url = Environment::production.Routes::getCheckoutId; 
      break; 
      case '3':
        $url = Environment::production_2.Routes::getCheckoutId; 
      break; 
      default:
        return false;
      break;
    } 
    $data = "entityId=8a829418533cf31d01533d06f2ee06fa" .
        "&amount=1.00" .
        "&currency=USD" .
        "&paymentType=DB";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization:Bearer OGE4Mjk0MTg1MzNjZjMxZDAxNTMzZDA2ZmQwNDA3NDh8WHQ3RjIyUUVOWA=='
    ));
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $pro);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $responseData = curl_exec($ch);
    if (curl_errno($ch)) {
        return json_encode(['error'=>curl_error($ch)],true);
    }
    curl_close($ch);
    return $responseData;
}  

function deleteCardRequest($token)
{  
  $options = get_option('woocommerce_pg_woocommerce_settings'); 
  $ambiente = $options['DATAFAST_DEV'] ; 
  $urlProd =$options['DATAFAST_URL_PROD'];
  $urlTest = $options['DATAFAST_URL_TEST'];
  $instance = new Environment();
  $arrayUrl = $instance->Url($ambiente,$urlTest,$urlProd);
  $url = $arrayUrl[0].Routes::deleteToken."/".$token; 
  $verifyPeer = $arrayUrl[1]; 
  $url .= "?entityId=".$options['DATAFAST_ENTITY_ID'];  
  if($ambiente == "yes")
      $url .= "&testMode=EXTERNAL"; 
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization:Bearer 	'.$options['DATAFAST_BEARER_TOKEN']));
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');  
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifyPeer); // this should be set to true in production
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $responseData = curl_exec($ch); 
  if (curl_errno($ch)) {
    return curl_error($ch);
  }
  curl_close($ch);
  return  $responseData;
}