<?php 
class CreditTypes_Table_List_Table extends WP_List_Table
{
  function __construct()
  {
    global $status, $page;

    parent::__construct(array(
      'singular' => 'Tipo de credito habilitado',
      'plural'   => 'Tipos de credito habilitados',
    ));
  }


  function column_default($item, $column_name)
  {
    return $item[$column_name];
  }

  function column_name($item)
  {

    $actions = array(
      'edit' => sprintf('<a href="?page=creditTypes_form&id_installment=%s">%s</a>', $item['id_installment'], __('Editar')),
      'delete' => sprintf('<a href="?page=%s&action=delete&id_installment=%s">%s</a>', $_REQUEST['page'], $item['id_installment'], __('Eliminar')),
    );

    return sprintf(
      '%s %s',
      $item['name'],
      $this->row_actions($actions)
    );
  }


  function column_cb($item)
  {
    return sprintf(
      '<input type="checkbox" name="id_installment[]" value="%s" />',
      $item['id_installment']
    );
  }

  function get_columns()
  {
    $columns = array(
      'cb' => '<input type="checkbox" />',
      'name'      => __('Nombre'),
      'creditName'  => __('Tipo de credito'),
      'installments'     => __('Meses'),
      'status'     => __('Activo')
    );
    return $columns;
  }

  function get_sortable_columns()
  {
    $sortable_columns = array(
      'name'      => array('name', true),
      'creditName' => array('id_termtype', true),
      'id_termtype'  => array('id_termtype', true),
      'installments'     => array('installments', true),
      'active'     => array('active', true),
      'status' => array('active', true),
    );
    return $sortable_columns;
  }

  function get_bulk_actions()
  {
    $actions = array(
      'delete' => 'Borrar'
    );
    return $actions;
  }

  function process_bulk_action()
  {
    global $wpdb;
    $table_name = $wpdb->base_prefix . 'datafast_installments';

    if ('delete' === $this->current_action()) {
      $ids = isset($_REQUEST['id_installment']) ? $_REQUEST['id_installment'] : array();
      if (is_array($ids)) $ids = implode(',', $ids);

      if (!empty($ids)) {
        $wpdb->query("UPDATE $table_name SET deleted=1 WHERE id_installment IN($ids)");
      }
    }
  }

  function prepare_items()
  {
    global $wpdb;
    $table_name = $wpdb->base_prefix . 'datafast_installments';
    $join_table_name = $wpdb->base_prefix . 'datafast_termtype';

    $per_page = 10;

    $columns = $this->get_columns();
    $hidden = array();
    $sortable = $this->get_sortable_columns();

    $this->_column_headers = array($columns, $hidden, $sortable);

    $this->process_bulk_action();

    $total_items = $wpdb->get_var("SELECT COUNT(id_installment) FROM $table_name");


    $paged = isset($_REQUEST['paged']) ? max(0, intval($_REQUEST['paged']) - 1) : 0;
    $orderby = (isset($_REQUEST['orderby']) && in_array($_REQUEST['orderby'], array_keys($this->get_sortable_columns()))) ? $_REQUEST['orderby'] : 'id_installment';
    $order = (isset($_REQUEST['order']) && in_array($_REQUEST['order'], array('asc', 'desc'))) ? $_REQUEST['order'] : 'asc';


    $this->items = $wpdb->get_results($wpdb->prepare(
      "SELECT $table_name.*, $join_table_name.name as 'creditName', IF($table_name.active>0,'Activo','Inactivo') as 'status'

        FROM $table_name
        INNER JOIN $join_table_name ON $join_table_name.id=$table_name.id_termtype
        WHERE $table_name.deleted is null or $table_name.deleted =0
        ORDER BY $orderby $order 
        LIMIT %d OFFSET %d",
      $per_page,
      $paged
    ), ARRAY_A);


    $this->set_pagination_args(array(
      'total_items' => $total_items,
      'per_page' => $per_page,
      'total_pages' => ceil($total_items / $per_page)
    ));
  }
}