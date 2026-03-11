<?php  
    use Datafast\Payment\Model\Environment;
    use Datafast\Payment\Model\Routes; 
    function searchTransactionByPaymentId($data)
    { 
        if (!isset($data)) {
            return false;
        } 
        $options = get_option('woocommerce_pg_woocommerce_settings'); 
        $ambiente = $options['DATAFAST_DEV'] ; 
        $urlProd =$options['DATAFAST_URL_PROD'];
        $urlTest = $options['DATAFAST_URL_TEST'];
        $instance = new Environment();
        $arrayUrl = $instance->Url($ambiente,$urlTest,$urlProd); 
        $url = $arrayUrl[0].Routes::searchTransactionByPaymentId."/".$data; 
        $verifyPeer = $arrayUrl[1]; 
        $url .= "?entityId=".$options['DATAFAST_ENTITY_ID']; 
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization:Bearer 	'.$options['DATAFAST_BEARER_TOKEN']));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');  
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifyPeer); // this should be set to true in production
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $responseData = curl_exec($ch); 
        if (curl_errno($ch)) {
          return curl_error($ch);
        }
        curl_close($ch);
        return  json_decode($responseData,true);
    }
    function getTransactions_handler()
    {
        if(isset($_POST['trxs'])){ 
            $trxs = explode(';',$_POST['trxs']); 
            foreach ($trxs as $key => $value) { 
                global $wpdb;
                $table_name = $wpdb->base_prefix . 'datafast_transactions'; 
                $exist = $wpdb->get_row($wpdb->prepare(
                  " SELECT 	id
                    FROM $table_name
                    WHERE transaction_id =%s",$value));  
                if(isset($exist))
                    $duplicates[] = $value;
                else{
                    $response = searchTransactionByPaymentId($value); 
                    if(!isset($response['id']))
                        $errorsTrxs[] = $value;
                    else{
                        $success[] = $value;
                        $data = array(
                            'cart_id' => null,
                            'customer_id' => isset($response['customer']['merchantCustomerId']) ? $response['customer']['merchantCustomerId'] : null,
                            'checkout_id' => null,
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
                            'response_json' => json_encode($response,true),
                            'request_json' => null,
                            'updated_at' => null,
                            'status' => ((isset($response['result']['code']) &&
                                ($response['result']['code'] == "000.000.000" ||
                                $response['result']['code'] == "000.200.100" ||
                                $response['result']['code'] == "000.100.112" ||
                                $response['result']['code'] == "000.100.110") ? 1 : 0))
                        );   
                        $resultdb = $wpdb->insert($table_name, $data); 
                        if (!$resultdb) {
                        echo "Error al guardar la transacción .<br>".$wpdb->last_error." <br>";
                        } 
                    }
                }
            }  
        }
    ?> 
    <tbody > 
	<div class="formdatabc">		
	<style>
        input[type=text],input[type=number], select, textarea{
            width: 60%; 
            border: 1px solid #ccc; 
            box-sizing: border-box;
            resize: vertical;
            margin: 5px 15px 2px;
            padding: 1px 12px;
        } 
        .dfa{
            width: 40%; 
            margin: 5px 15px 2px !important;
            padding: 1px 12px !important;
        }
        .dflb{
            margin: 5px 15px 2px;
            padding: 1px 12px;
        }
    </style>	
        <h3><strong>Obtener Transacciones</strong></h3>  
        <form method="post" >
            <div class="form2bc">
                <p>			
                    <label class="dflb" for="trxs">Transacciones: </label>
                    <br>
                    <input id="trxs" name="trxs" type="text" placeholder="Id de Transacciones" autocomplete="off"
                            required>
                    <button type="submit" class="button button-primary" name="sbmttrxs">Guardar</button>
                </p> 
                <p> 
                    <?php if(isset($errorsTrxs)){ 
                            foreach ($errorsTrxs as $key => $value) { ?>
                                <div class="notice notice-error is-dismissible">
                                    <p>
                                        <strong>Error la transacción <?php echo $value; ?> no se pudo encontrar.</strong> 
                                    </p> 
                                </div>  
                    <?php }} if(isset($duplicates)){ 
                            foreach ($duplicates as $key => $value) { ?>
                                <div class="notice notice-error is-dismissible">
                                    <p>
                                        <strong>Error transacción <?php echo $value; ?> repetida. La transacción ya existe en los registros de plugin.</strong> 
                                    </p> 
                                </div>  
                    <?php }} if(isset($success)){ 
                            foreach ($success as $key => $value) { ?>
                                <div class="notice notice-success is-dismissible">
                                    <p>
                                        <strong>Transacción <?php echo $value; ?> recuperada correctamente.</strong> 
                                    </p> 
                                </div>   
                    <?php }} ?>
                </p>
            </div>   
        </form>  
    </div>
</tbody> 
<?php
}