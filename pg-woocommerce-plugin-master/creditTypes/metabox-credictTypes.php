<?php
function creditTypes_page_handler()
{
    global $wpdb;

    $table = new CreditTypes_Table_List_Table();
    $table->prepare_items();

    $message = '';
    if ('delete' === $table->current_action()) {
        $message = '<div class="updated below-h2" id="message"><p>' . __('Registros borrados') . '</p></div>';
    }
    ?>
<div class="wrap">

    <div class="icon32 icon32-posts-post" id="icon-edit"><br></div>
    <h2><?php _e('Tipos de Credito')?> <a class="add-new-h2"
                                 href="<?php echo get_admin_url(get_current_blog_id(), 'admin.php?page=creditTypes_form');?>"><?php _e('Nuevo')?></a>
    </h2>
    <?php echo $message; ?>

    <form id="creditTypes-table" method="POST">
        <input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>"/>
        <?php $table->display() ?>
    </form>

</div>
<?php
}

function validate_creditTypes($item)
{
    $messages = array();

    if (empty($item['name'])) $messages[] = __('Ingresa un nombre');
    if (empty($item['id_termtype'])) $messages[] = __('Selecciona un credito'); 
    

    if (empty($messages)) return true;
    return implode('<br />', $messages);
}
function creditTypes_form_page_handler()
{
    global $wpdb;
    $table_name = $wpdb->base_prefix . 'datafast_installments'; 

    $message = '';
    $notice = '';


    $default = array(
        'id_installment' => null,
        'name'      => '',
        'id_termtype'  => 0, 
        'installments'      => 0,
        'active'  => 0, 
    );


    if ( isset($_REQUEST['nonce']) && wp_verify_nonce($_REQUEST['nonce'], basename(__FILE__))) {
        
        $item = shortcode_atts($default, $_REQUEST);     
        $item_valid = validate_creditTypes($item);
        if ($item_valid === true) {
            if ($item['id_installment'] == 0) {
                $result = $wpdb->insert($table_name, $item);
                $item['id_installment'] = $wpdb->insert_id;
                if ($result) {
                    $message = __('Se guardo con exito.');
                } else {
                    $notice = __('Ocurrio un error al tratar de guardar.');
                }
            } else {
                $result = $wpdb->update($table_name, $item, array('id_installment' => $item['id_installment'])); 
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
        if (isset($_REQUEST['id_installment'])) {
            $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id_installment = %d", $_REQUEST['id_installment']), ARRAY_A);
            if (!$item) {
                $item = $default;
                $notice = __('No se encontro el registro');
            }
        }
    }

    
    add_meta_box('creditTypes_form_meta_box', __('Datos'), 'creditTypes_form_meta_box_handler', 'creditTypes', 'normal', 'default');

    ?>
<div class="wrap">
    <div class="icon32 icon32-posts-post" id="icon-edit"><br></div>
    <h2><?php _e('Tipos de credito')?> <a class="add-new-h2"
                                href="<?php echo get_admin_url(get_current_blog_id(), 'admin.php?page=creditTypes');?>"><?php _e('atras')?></a>
    </h2>

    <?php if (!empty($notice)): ?>
    <div id="notice" class="error"><p><?php echo $notice ?></p></div>
    <?php endif;?>
    <?php if (!empty($message)): ?>
    <div id="message" class="updated"><p><?php echo $message ?></p></div>
    <?php endif;?>

    <form id="form" method="POST">
        <input type="hidden" name="nonce" value="<?php echo wp_create_nonce(basename(__FILE__))?>"/>
        
        <input type="hidden" name="id" value="<?php echo $item['id_installment'] ?>"/>

        <div class="metabox-holder" id="poststuff">
            <div id="post-body">
                <div id="post-body-content">
                    
                    <?php do_meta_boxes('creditTypes', 'normal', $item); ?>
                    <input type="submit" value="<?php _e('Save')?>" id="submit" class="button-primary" name="submit">
                </div>
            </div>
        </div>
    </form>
</div>
<?php
}

function creditTypes_form_meta_box_handler($item)
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
            <div class="row">
                <div class="col-md-4">
                    <label for="name"><?php _e('Nombre:')?></label>  
                </div>
                <div class="col-md-8"> 
                    <input id="name" name="name" type="text" value="<?php echo esc_attr($item['name'])?>"
                            required>
                </div>
            </div><br>
            <div class="row">
                <div class="col-md-4">
                    <label for="id_termtype"><?php _e('Tipo de Credito:')?></label> 
                </div>
                <div class="col-md-8"> 
                    <?php 
                        global $wpdb;
                        $table_name = $wpdb->base_prefix . 'datafast_termtype'; 
                        $termtypes= $wpdb->prepare("SELECT * FROM $table_name WHERE active=%d",1);
                    ?>
                    <select name="id_termtype"  id="id_termtype" value="<?php echo esc_attr($item['id_termtype'])?>" required>
                        <?php  
                            foreach ($wpdb->get_results($termtypes) as $key => $value) {
                                echo "<option value='".$value->id."'".($item['id_termtype']==$value->id?' selected="selected" ':'').">".$value->name."</option>";
                            } 
                        ?>
                    </select> 
                </div>
            </div><br>
            <div class="row">
                <div class="col-md-4">
                    <label for="installments"><?php _e('Meses:')?></label> 
                </div>
                <div class="col-md-8">  
                    <input id="installments" min="0"  name="installments" type="number" value="<?php echo esc_attr($item['installments'])?>" <?php if($item['id_termtype']=='1' || $item['id_termtype']==0){echo 'readonly';}else{echo'';}?>  required> 
                </div>
            </div><br>
            <div class="row">
                <div class="col-md-4">
                    <label for="active"><?php _e('Activo:')?></label> 
                </div>
                <div class="col-md-8"> 
                    <input id="active" name="active" type="checkbox" 
                    value="<?php echo $item['active']?1:0?>" 
                    <?php echo $item['active']?'checked':''?> 
                    onclick="document.getElementById('active').value=(document.getElementById('active').checked?1:0)">
                </div>
            </div> 
		</form>
		</div>
</tbody>
<script type="text/javascript">
    jQuery('#id_termtype').on('change', function() {
        if(this.value=='1'){
            jQuery("#installments").attr("readonly", true); 
        }
        else{ 
            jQuery("#installments").attr("readonly", false); 
        }
    });
</script>
<?php
}
