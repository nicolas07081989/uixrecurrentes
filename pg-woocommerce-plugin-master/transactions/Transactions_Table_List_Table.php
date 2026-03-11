<?php  
use Datafast\Payment\Model\Environment;
use Datafast\Payment\Model\Routes;
class Transactions_Table_List_Table extends WP_List_Table
{
  function __construct()
  {
    global $status, $page;

    parent::__construct(array(
      'singular' => 'Transacción',
      'plural'   => 'Transacciones',   
      'ajax'      => false      
    ));
  }
  protected function get_views() { 
      $status_links = array(
          "all"       => __("<a href='#'>Todos</a>",'my-plugin-slug'),
          "RF" => __("<a href='#'>RF</a>",'my-plugin-slug'),
          "DB"   => __("<a href='#'>DB</a>",'my-plugin-slug')
      );
      return $status_links;
  }


  function column_default($item, $column_payment_type)
  {
    return $item[$column_payment_type];
  }

  function column_id($item)
  {

    $actions = array(
      'show' => sprintf('<a href="?page=transactions_form&id=%s">%s</a>', $item['id'], __('Ver')),
      'refund' => ($item['status']=='1'?sprintf('<a href="?page=%s&action=refund&id=%s">%s</a>', $_REQUEST['page'], $item['id'], __('Reversar')):null),
    );

    return sprintf(
      '%s %s',
      $item['id'],
      $this->row_actions($actions)
    );
  }


  function column_cb($item)
  {
    return ($item['status']=='1'?sprintf(
      '<input type="checkbox" name="id[]" value="%s" />',
      $item['id']
    ):null);
  }

  function get_columns()
  {
    $columns = array(
      'cb' => '<input type="checkbox" />',
      'id'      => __('Id'),
      'payment_type'      => __('Tipo'),
      'Estado'     => __('Estado'),
      'cart_id'  => __('Orden'),
      'timestamp'     => __('Fecha(Ejecución)'),
      'transaction_id'      => __('Id Trx'),
      'result_code'  => __('Resp. Botón'),
      'acquirer_response'     => __('Resp. Banco'),
      'extended_description'     => __('Descripción de respuesta'),
      'batch_no'      => __('Lote'),
      'reference_no'  => __('Referencia'),
      'acquirer_code'     => __('Adq.'),
      'auth_code'     => __('Auth.'),
      'amount'      => __('Monto'),
      'interest'  => __('Interes'),
      'total_amount'     => __('Monto Total')
    );
    return $columns;
  }

  function get_sortable_columns()
  {
    $sortable_columns = array(
      'id'      => array('id', true),
      'payment_type'      => array('Tipo', true),
      'status'     => array('Estado', true),
      'Estado'     => array('Estado', true),
      'cart_id'  => array('Orden', true),
      'timestamp'     => array('Fecha(Ejecución)', true),
      'transaction_id'      => array('Id Trx', true),
      'result_code'  => array('Resp. Botón', true),
      'acquirer_response'     => array('Resp. Banco', true),
      'extended_description'     => array('Descripción de respuesta', true),
      'batch_no'      => array('Lote', true),
      'reference_no'  => array('Referencia', true),
      'acquirer_code'     => array('Adq.', true),
      'auth_code'     => array('Auth.', true),
      'amount'      => array('Monto', true),
      'interest'  => array('Interes', true),
      'total_amount'     => array('Monto Total', true)
    );
    return $sortable_columns;
  }

  function get_bulk_actions()
  {
    $actions = array(
      'refund' => 'Reversar'
    );
    return $actions;
  }

  function process_bulk_action()
  {
    global $wpdb;
    $table_name = $wpdb->base_prefix . 'datafast_transactions';

    if ('refund' === $this->current_action()) {
      $ids = isset($_REQUEST['id']) ? $_REQUEST['id'] : array();
      if (is_array($ids)) $ids = implode(',', $ids);

      if (!empty($ids)) {
        $items = $wpdb->get_results($wpdb->prepare(
          " SELECT 	id,cart_id,checkout_id,transaction_id,amount
            FROM $table_name
            WHERE id IN($ids)
            "));
        foreach ($items as $key => $value) 
        if(isset($value->transaction_id)){
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
            echo "Error al reversar la orden"; 
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
      }
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

  function prepare_items()
  {
    global $wpdb;
    $table_name = $wpdb->base_prefix . 'datafast_transactions'; 
    $per_page = 10;

    $columns = $this->get_columns();
    $hidden = array();
    $sortable = $this->get_sortable_columns();

    $this->_column_headers = array($columns, $hidden, $sortable);

    $this->process_bulk_action();



    $paged = isset($_REQUEST['paged']) ? max(0, intval($_REQUEST['paged']) - 1) : 0;
    $orderby = (isset($_REQUEST['orderby']) && in_array($_REQUEST['orderby'], array_keys($this->get_sortable_columns()))) ? $_REQUEST['orderby'] : 'id';
    $order = (isset($_REQUEST['order']) && in_array($_REQUEST['order'], array('asc', 'desc'))) ? $_REQUEST['order'] : 'desc'; 
    $whereFlag=false; 
    $where = "WHERE ";
    if(isset($_REQUEST['searchId'])&&strlen($_REQUEST['searchId'])>0){ 
      $searchId=$_REQUEST['searchId'];
      $where .= "id like '%$searchId%' "; 
      $whereFlag=true;
    }
    if(isset($_REQUEST['searchOrden'])&&strlen($_REQUEST['searchOrden'])>0){ 
      $searchOrden=$_REQUEST['searchOrden'];
      $where .= ($whereFlag?' AND ':'')."cart_id like '%$searchOrden%' " ; 
      $whereFlag=true;
    } 
    if(isset($_REQUEST['searchTipo'])&&strlen($_REQUEST['searchTipo'])>0){ 
      $searchTipo=$_REQUEST['searchTipo'];
      $where .= ($whereFlag?' AND ':'')."payment_type = '$searchTipo' "; 
      $whereFlag=true;
    } 
    if(isset($_REQUEST['searchEstados'])&&strlen($_REQUEST['searchEstados'])>0){ 
      $searchEstados=$_REQUEST['searchEstados'];
      $where .= ($whereFlag?' AND ':'')."status = $searchEstados" ; 
      $whereFlag=true;
    } 
    if(isset($_REQUEST['searchTransaction_id'])&&strlen($_REQUEST['searchTransaction_id'])>0){ 
      $searchTransaction_id=$_REQUEST['searchTransaction_id'];
      $where .= ($whereFlag?' AND ':'')."transaction_id like '%$searchTransaction_id%' " ; 
      $whereFlag=true;
    }  
    $this->items = $wpdb->get_results($wpdb->prepare(
      "SELECT *,  CASE
                  WHEN status=1 THEN 'Procesado'
                  WHEN status=2 THEN 'Reversado'
                  WHEN status=0 THEN 'Erroneo'
                  Else 'desconocido'
                  END AS Estado  
        FROM $table_name ".
        ($whereFlag?$where:'')."
        ORDER BY $orderby $order 
        LIMIT %d OFFSET %d",
      $per_page,
      $paged*$per_page
    ), ARRAY_A); 

    $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name ".($whereFlag?$where:'')); 
    $this->set_pagination_args(array(
      'total_items' => $total_items,
      'per_page' => $per_page,
      'total_pages' => ceil($total_items / $per_page) 
    ));
  }
}