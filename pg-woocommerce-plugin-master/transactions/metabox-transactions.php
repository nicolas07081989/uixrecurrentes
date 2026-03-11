<?php
function transactions_page_handler()
{
    global $wpdb;

    $table = new Transactions_Table_List_Table();
    $table->prepare_items();

    $_SESSION['message'] = '';
    if ('refund' === $table->current_action()) {
        $_SESSION['message'] = '<div class="updated below-h2" id="message"><p>Registros Reversados</p></div>';
        header('Location: '.get_site_url().'/wp-admin/admin.php?page=transactions');
    }
    ?>
<div class="wrap">

    <div class="icon32 icon32-posts-post" id="icon-edit"><br></div>
    <h2><?php _e('Transacciones')?></h2>
    <?php echo $_SESSION['message']; ?> 
    <form id="transactions-table" method="POST">  
        <table >
            <tr> 
                <th>
                    <label>Id:</label><br>
                </th>
                <th>
                    <label>Tipos:</label><br>
                </th>
                <th>
                    <label>Estados:</label><br>
                </th>
                <th>
                    <label>Orden:</label><br>
                </th>
                <th>
                    <label>Id TRX:</label><br>
                </th>
            </tr>
            <tr>
                <th>
                    <input name="searchId" id="searchId" type="number" placeholder="Id" value="<?php echo(isset($_REQUEST['searchId'])?$_REQUEST['searchId']:'');?>"> 
                </th>
                <th>
                    <select id="searchTipo" name="searchTipo" placeholder="Tipos"> 
                        <option value="">Todos(Tipos)</option> 
                        <option value="RF">RF</option> 
                        <option value="DB">DB</option> 
                    </select>
                </th>
                <th>
                    <select id="searchEstados" name="searchEstados" placeholder="Estados"> 
                        <option value="">Todos(Estados)</option> 
                        <option value="1">Procesado</option>  
                        <option value="2">Reversado</option> 
                        <option value="0">Erroneo</option> 
                    </select>
                </th>
                <th>
                    <input name="searchOrden" id="searchOrden" type="number" placeholder="Orden" value="<?php echo $_REQUEST['searchOrden']??'';?>"> 
                </th>
                <th>
                    <input name="searchTransaction_id" id="searchTransaction_id" type="text" placeholder="Id TRX" value="<?php echo $_REQUEST['searchTransaction_id']??'';?>">  
                </th>
            </tr> 
        </table>  
        <script type="text/javascript"> 
            jQuery('#searchTipo option[value="<?php echo $_REQUEST['searchTipo'];?>"]').prop('selected', true);
            jQuery('#searchEstados option[value="<?php echo $_REQUEST['searchEstados'];?>"]').prop('selected', true);
            jQuery("#searchTransaction_id").on('keyup', function (e) {
                if (e.key === 'Enter' || e.keyCode === 13) {
                    document.getElementById("transactions-table").submit();
                }
            });
            jQuery("#searchOrden").on('keyup', function (e) {
                if (e.key === 'Enter' || e.keyCode === 13) {
                    document.getElementById("transactions-table").submit();
                }
            });
            jQuery("#searchId").on('keyup', function (e) {
                if (e.key === 'Enter' || e.keyCode === 13) {
                    document.getElementById("transactions-table").submit();
                }
            });
            jQuery("#searchTipo").on('change', function (e) { 
                document.getElementById("transactions-table").submit(); 
            });
            jQuery("#searchEstados").on('change', function (e) { 
                document.getElementById("transactions-table").submit(); 
            });
        </script>
        <input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>"/>
        <?php $table->display() ?>
    </form>

</div>
<?php
}
function transactions_form_page_handler()
{ 
    global $wpdb;
    $table_name = $wpdb->base_prefix . 'datafast_transactions'; 

    $message = '';
    $notice = '';


    $default = array(
        'id' => 0,
        'payment_type'      => '',
        'status'     => '',
        'cart_id'  => '',
        'timestamp'     =>'',
        'transaction_id'      => '',
        'result_code'  =>'',
        'acquirer_response'     => '',
        'extended_description'     => '',
        'batch_no'      =>'',
        'reference_no'  => '',
        'acquirer_code'     => '',
        'auth_code'     => '',
        'amount'      => '',
        'interest'  => '',
        'total_amount'     => '',
    ); 

    if ( isset($_REQUEST['nonce']) && wp_verify_nonce($_REQUEST['nonce'], basename(__FILE__))) {
        
        $item = shortcode_atts($default, $_REQUEST);     
        $item_valid = validate_creditTypes($item);
        if ($item_valid === true) {
            if ($item['id'] == 0) {
                $result = $wpdb->insert($table_name, $item);
                $item['id'] = $wpdb->insert_id;
                if ($result) {
                    $message = __('Se guardo con exito.');
                } else {
                    $notice = __('Ocurrio un error al tratar de guardar.');
                }
            } else {
                $result = $wpdb->update($table_name, $item, array('id' => $item['id'])); 
                if ($result) {
                    $message = __('Se guardo con exito.');
                } else {
                    $notice = __('Ocurrio un error al tratar de editar.');
                }
            }
        } else {
            
            $notice = $item_valid;
        }
    }
    else {
        
        $item = $default;
        if (isset($_REQUEST['id'])) {
            $item = $wpdb->get_row($wpdb->prepare(
                "   SELECT *,  CASE
                    WHEN status=1 THEN 'Procesado'
                    WHEN status=2 THEN 'Reversado'
                    WHEN status=0 THEN 'Erroneo'
                    Else 'desconocido'
                    END AS Estado  
                    FROM $table_name WHERE id = %d", $_REQUEST['id']), ARRAY_A);
            if (!$item) {
                $item = $default;
                $notice = __('No se encontro el registro');
            }
        }
    }

    add_meta_box('transactions_form_meta_box', __('Datos'), 'transactions_form_meta_box_handler', 'transactions', 'normal', 'default');

    ?>
<div class="wrap">
    <div class="icon32 icon32-posts-post" id="icon-edit"><br></div>
    <h2><?php _e('Transacciones')?> <a class="add-new-h2"
                                href="<?php echo get_admin_url(get_current_blog_id(), 'admin.php?page=transactions');?>"><?php _e('atras')?></a>
    </h2>

    <?php if (!empty($notice)): ?>
    <div id="notice" class="error"><p><?php echo $notice ?></p></div>
    <?php endif;?>
    <?php if (!empty($message)): ?>
    <div id="message" class="updated"><p><?php echo $message ?></p></div>
    <?php endif;?>

    <form id="form" method="POST">
        <input type="hidden" name="nonce" value="<?php echo wp_create_nonce(basename(__FILE__))?>"/>
        
        <input type="hidden" name="id" value="<?php echo $item['id'] ?>"/>

        <div class="metabox-holder" id="poststuff">
            <div id="post-body">
                <div id="post-body-content">
                    
                    <?php do_meta_boxes('transactions', 'normal', $item); ?> 
                </div>
            </div>
        </div>
    </form>
</div>
<?php
}

function transactions_form_meta_box_handler($item)
{
    ?>
<tbody >
		
	<div class="formdatabc">		
	<style>
        input[type=text],input[type=number], select, textarea{
            width: 100%; 
            border: 1px solid #ccc; 
            box-sizing: border-box;
            resize: vertical;
        } 
    </style>	 
        <form >
            <div class="form2bc">
                <p>			
                    <label for="Estado"><?php _e('Estado:')?></label>
                    <input disabled id="Estado" name="Estado" type="text" value="<?php echo esc_attr($item['Estado'])?>"
                            required>
                </p>
                <p>			
                    <label for="cart_id"><?php _e('Orden:')?></label>
                    <input disabled id="cart_id" name="cart_id" type="text" value="<?php echo esc_attr($item['cart_id'])?>"
                            required>
                </p>
                <p>			
                    <label for="timestamp"><?php _e('Fecha Ejecución:')?></label>
                    <input disabled id="timestamp" name="timestamp" type="text" value="<?php echo esc_attr($item['timestamp'])?>"
                            required>
                </p>
                <p>			
                    <label for="transaction_id"><?php _e('Id Transacción:')?></label>
                    <input disabled id="transaction_id" name="transaction_id" type="text" value="<?php echo esc_attr($item['transaction_id'])?>"
                            required>
                </p>
                <p>			
                    <label for="amount"><?php _e('Monto:')?></label>
                    <input disabled id="amount" name="amount" type="text" value="<?php echo esc_attr($item['amount'])?>"
                            required>
                </p>
                <p>			
                    <label for="result_code"><?php _e('Respuesta Botón:')?></label>
                    <input disabled id="result_code" name="result_code" type="text" value="<?php echo esc_attr($item['result_code'])?>"
                            required>
                </p>
                <p>			
                    <label for="acquirer_response"><?php _e('Respuesta Banco:')?></label>
                    <input disabled id="acquirer_response" name="acquirer_response" type="text" value="<?php echo esc_attr($item['acquirer_response'])?>"
                            required>
                </p>
                <p>			
                    <label for="extended_description"><?php _e('Descripción Respuesta:')?></label>
                    <input disabled id="extended_description" name="extended_description" type="text" value="<?php echo esc_attr($item['extended_description'])?>"
                            required>
                </p>
                <p>			
                    <label for="batch_no"><?php _e('Lote:')?></label>
                    <input disabled id="batch_no" name="batch_no" type="text" value="<?php echo esc_attr($item['batch_no'])?>"
                            required>
                </p>
                <p>			
                    <label for="reference_no"><?php _e('Referencia:')?></label>
                    <input disabled id="reference_no" name="reference_no" type="text" value="<?php echo esc_attr($item['reference_no'])?>"
                            required>
                </p>
                <p>			
                    <label for="acquirer_code"><?php _e('Adquirente:')?></label>
                    <input disabled id="acquirer_code" name="acquirer_code" type="text" value="<?php echo esc_attr($item['acquirer_code'])?>"
                            required>
                </p>
                <p>			
                    <label for="auth_code"><?php _e('Autorización:')?></label>
                    <input disabled id="auth_code" name="auth_code" type="text" value="<?php echo esc_attr($item['auth_code'])?>"
                            required>
                </p>
                <p>			
                    <label for="total_amount"><?php _e('Monto Total:')?></label>
                    <input disabled id="total_amount" name="total_amount" type="text" value="<?php echo esc_attr($item['total_amount'])?>"
                            required>
                </p>
                <p>			
                    <label for="interest"><?php _e('Interes:')?></label>
                    <input disabled id="interest" name="interest" type="text" value="<?php echo esc_attr($item['interest'])?>"
                            required>
                </p>
                <p>			
                    <label for="response_json"><?php _e('JSON Respuesta:')?></label> 
                    <textarea rows="15" cols="100" disabled id="response_json" name="response_json" type="response_json" required>
                        <?php echo($item['response_json'])?>
                    </textarea>	 
                </p>
            </div>  
        </form>
    </div>
</tbody>
<?php
}
