<?php
class WC_Datafast_Database_Helper {
  public static function create_database() {
    global $wpdb;
    $table_name = $wpdb->base_prefix . 'datafast_plugin';

    $table_name = $wpdb->base_prefix . 'datafast_transactions';
    if ($wpdb->get_var('SHOW TABLES LIKES ' . $table_name) != $table_name) {
        $sqlTrx = 'CREATE TABLE ' . $table_name . ' (
          `id` INTEGER(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
          `cart_id` INTEGER(11) DEFAULT NULL,
          `customer_id` VARCHAR(100) DEFAULT NULL,          
          `checkout_id` VARCHAR (100) DEFAULT NULL,
          `result_description` VARCHAR (255) DEFAULT NULL,
          `transaction_id` VARCHAR (100) DEFAULT NULL,
          `payment_type` VARCHAR (100) DEFAULT NULL,
          `payment_brand` VARCHAR (100) DEFAULT NULL,
          `amount` FLOAT(11) DEFAULT NULL,
          `merchant_transactionId` VARCHAR (100) DEFAULT NULL,
          `result_code` VARCHAR (100) DEFAULT NULL,
          `extended_description` VARCHAR (500) DEFAULT NULL,
          `acquirer_response` VARCHAR (5) DEFAULT NULL,
          `auth_code` VARCHAR (20) DEFAULT NULL,
          `response` VARCHAR (5) DEFAULT NULL,
          `acquirer_code` VARCHAR (20) DEFAULT NULL,
          `batch_no` VARCHAR (20) DEFAULT NULL,
          `interest` FLOAT(11) DEFAULT NULL,
          `total_amount` FLOAT(11) DEFAULT NULL,
          `reference_no` VARCHAR (20) DEFAULT NULL,
          `bin` VARCHAR (20) DEFAULT NULL,
          `last_4_Digits` VARCHAR (4) DEFAULT NULL,
          `email` VARCHAR (200) DEFAULT NULL,
          `shopper_mid` VARCHAR (200) DEFAULT NULL,
          `shopper_tid` VARCHAR (200) DEFAULT NULL,
          `timestamp` VARCHAR (200) DEFAULT NULL,
          `request_json` TEXT DEFAULT NULL,
          `response_json` TEXT DEFAULT NULL,
          `status` TINYINT DEFAULT NULL,
          `updated_at` DATETIME DEFAULT NULL);';
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sqlTrx);
      }else{
        $sqlTrx = 'ALTER TABLE ' . $table_name . ' 
          MODIFY COLUMN `request_json` TEXT DEFAULT NULL,
          MODIFY COLUMN `customer_id` VARCHAR(100) DEFAULT NULL,
          MODIFY COLUMN `extended_description` VARCHAR(500) DEFAULT NULL,
          MODIFY COLUMN `response_json` TEXT DEFAULT NULL;';
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sqlTrx);
      }
 

    $table_name = $wpdb->base_prefix . 'datafast_installments';
    if ($wpdb->get_var('SHOW TABLES LIKES ' . $table_name) != $table_name) {
        $sqlInstall = 'CREATE TABLE ' . $table_name . ' (
            `id_installment` INTEGER(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
            `name` VARCHAR (200) ,
            `id_termtype` INTEGER(11) DEFAULT NULL,
            `installments` INTEGER(11) DEFAULT NULL,
            `active` TINYINT,
            `deleted` TINYINT,
            `updated_at` DATETIME DEFAULT NULL);';
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sqlInstall);
      }

    $table_name = $wpdb->base_prefix . 'datafast_termtype';
    if ($wpdb->get_var('SHOW TABLES LIKES ' . $table_name) != $table_name) {
        $sqlTerm = 'CREATE TABLE ' . $table_name . ' (
          `id` INTEGER(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
          `name` VARCHAR (200) ,
          `code` VARCHAR (100) ,
          `active` TINYINT);';
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sqlTerm);
    }

    $table_name = $wpdb->base_prefix . 'datafast_termtype';
    if ($wpdb->get_var('SHOW TABLES LIKES ' . $table_name) != $table_name) {
        $sqlTerm = 'INSERT INTO ' . $table_name . ' (`id`,`name`,`code`,`active`)
        VALUES  (1,\'Corriente\',\'00\',1),
                (2,\'Diferido corriente\',\'01\',1),
                (3,\'Diferido con Interés\',\'02\',1),
                (4,\'Diferido sin Interés\',\'03\',1),
                (5,\'Diferido con Interés + Meses de Gracia\',\'07\',1),
                (6,\'Diferido sin Interés + Meses de Gracia\',\'09\',1),
                (7,\'Diferido Plus\',\'21\',1),
                (8,\'Diferido\',\'22\',1);';
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sqlTerm);
    }


    $table_name = $wpdb->base_prefix . 'datafast_customertoken';
    if ($wpdb->get_var('SHOW TABLES LIKES ' . $table_name) != $table_name) {
      $sqlTerm = 'CREATE TABLE ' . $table_name . ' (
          `id` INTEGER(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
          `customer_id` VARCHAR(100) DEFAULT NULL,
          `token` VARCHAR (200) ,
          `status` VARCHAR (100),
          `updated_at` DATETIME DEFAULT NULL);';
      require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
      dbDelta($sqlTerm);
    }  else{
      $sqlTrx = 'ALTER TABLE ' . $table_name . '  
        MODIFY COLUMN `customer_id` VARCHAR(100) DEFAULT NULL ;';
      require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
      dbDelta($sqlTrx);
    }
  }

  public static function delete_database() {
    global $wpdb;
    $table_name = $wpdb->base_prefix . 'datafast_plugin';
    $sql = "DROP TABLE IF EXISTS $table_name";
    require_once( ABSPATH . '/wp-admin/includes/upgrade.php' );
    $wpdb->query($sql);
  }

  public static function insert_data($status, $comments, $description, $dev_reference, $transaction_id) {
    $statusfinal = $status;
    $commentsfinal = $comments;
    $guardar = $description;
    $dev_reference = $dev_reference;
    $transaction_id = $transaction_id;

    global $wpdb;
    $table_name = $wpdb->base_prefix . 'datafast_plugin';

    $wpdb->insert($table_name, array(
        'id' => $id,
        'Status' => $statusfinal,
        'Comments' => $commentsfinal,
        'description' => $guardar,
        'OrdenId' => $dev_reference,
        'Transaction_Code' => $transaction_id
    ), array(
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s'
          )
    );
  }

  public static function select_order($order_id) {
    global $wpdb;
    $table_name = $wpdb->base_prefix . 'datafast_plugin';
    $myrows = $wpdb->get_results("SELECT * FROM $table_name where OrdenId = '$order_id' ", OBJECT);

    foreach ($myrows as $campos) {
      $transactionCode = $campos->Transaction_Code;
    }
    return $transactionCode;
  }
}
