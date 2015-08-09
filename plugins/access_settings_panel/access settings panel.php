<?php
/*
Plugin Name: Access settings panel
Description: Панель настроек прав доступа
Version: 20150621
*/
add_action('admin_enqueue_scripts','load_jquery_plugins');
add_filter('posts_where','acl_filter_where', 10, 1);
add_action('post_submitbox_misc_actions','add_field_to_submitbox');
add_action('save_post','save_acl_fields');
add_action('wp_ajax_delete_acl_user','delete_acl_user_callback');
add_action('wp_ajax_get_users', 'get_users_for_autocomplete');

function load_jquery_plugins(){
    //DataTable
    wp_enqueue_style( 'DataTable_css', plugin_dir_url(__FILE__).'DataTables-1.10.7/media/css/jquery.dataTables.css' );
    wp_enqueue_script( 'DataTable_js', plugin_dir_url(__FILE__).'DataTables-1.10.7/media/js/jquery.dataTables.js' );

    //autocomplete
    wp_enqueue_script('jquery-ui-autocomplete');
}

function acl_filter_where($where){     
    global $wpdb;
        
    $current_user_id = get_current_user_id();
    //Если это администратор, редактор или кто то с правом доступа, то отменяем контроль
    if (user_can($current_user_id, 'full_access_to_posts') or user_can($current_user_id, 'editor') or user_can($current_user_id, 'administrator')) return $where;    
    $where .= " AND 
        if(" . $wpdb->posts . ".post_type = 'post',
            if(" . $wpdb->posts . ".ID IN (
                    SELECT post_id 
                    FROM " . $wpdb->postmeta ." 
                    WHERE 
                        " . $wpdb->postmeta .".meta_key = 'acl_s_true' 
                        AND " . $wpdb->postmeta .".post_id = " . $wpdb->posts . ".ID
                ),
            if(" . $wpdb->posts . ".ID IN (
                    SELECT post_id 
                    FROM " . $wpdb->postmeta ." 
                    WHERE 
                        " . $wpdb->postmeta .".meta_key = 'acl_users_s' 
                        AND " . $wpdb->postmeta .".post_id = " . $wpdb->posts . ".ID
                        AND " . $wpdb->postmeta .".meta_value = " . $current_user_id ."
                )
            ,1,0),1),
        1)=1";

        return $where;
}

function add_field_to_submitbox(){
    global $post;
     ?>
     <style>
     .ui-autocomplete{z-index:1000000;}
     #add_users{text-decoration:none;}
     </style>
     <script>
     jQuery(document).ready(function($){
      //autocomplete
      $.ajax({
        data: ({
          action:'get_users',
        }),
        url: "<?php echo admin_url('admin-ajax.php') ?>",
        success: function(data){
    function split( val ) {
      return val.split( /,\s*/ );
    }
    function extractLast( term ) {
      return split( term ).pop();
    }
 
    $( "#acl_users_s" )
      .bind( "keydown", function( event ) {
        if ( event.keyCode === $.ui.keyCode.TAB &&
            $( this ).autocomplete( "instance" ).menu.active ) {
          event.preventDefault();
        }
      })
      .autocomplete({
        minLength: 0,
        source: function( request, response ) {
          response( $.ui.autocomplete.filter(
            JSON.parse(data), extractLast( request.term ) ) );
        },
        focus: function() {
          return false;
        },
        select: function( event, ui ) {
          var terms = split( this.value );
          terms.pop();
          terms.push( ui.item.value );
          terms.push( "" );
          this.value = terms.join( ", " );
          return false;
        }
      });
        }
      });
      
      //Обработка удаления пользователя из списка
      $('.delete_acl_user').click(function(){
      var userID= $(this).parent().siblings('.user_id').text();
      var tr= $(this).closest('tr');
      $.ajax({
      data: ({
          action: 'delete_acl_user',
          post_id: <?php echo $post->ID ?>,
          user_id: userID,
          }),
      url: "<?php echo admin_url('admin-ajax.php') ?>",
      success: function(){
          tr.remove();                  
      }           
  });        
  });
      $('#users_table').DataTable();
      
  });
        </script>
        <div class='misc-pub-section'>
            <span id="acl">Доступ: </span>
            <a href='#TB_inline?width=750&height=350&inlineId=acl_form' class="thickbox" id="options" title="Настройка доступа">Настройка</a>
        </div>
        <div id='acl_form' style='display:none;'>
        <br/>
        <input type="checkbox" name="acl_s_true" <?php echo get_post_meta($post->ID,'acl_s_true',true)?>>
        <label for="acl_s_true">Ограничить доступ по списку</label>
        <br/><br/>
        <label for="acl_users_s">Пользователи:</label>
        <br/>
        <input id="acl_users_s" name="acl_users_s">
        <br/><br/>
        <?php
        $acl_users_s=get_post_meta($post->ID,'acl_users_s');
        if(!empty($acl_users_s)){?>
        <table id="users_table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Имя пользователя</th>
                <th>Действие</th>
            </tr>
        </thead>
        
        <tbody>
            <?php            
                foreach ($acl_users_s as $acl_user) {
                    $user_data=get_user_by('id',$acl_user);
                    ?>
                    <tr>
                        <td class="user_id"><?php echo $acl_user; ?></td>
                        <td><?php echo $user_data->user_nicename; ?></td>
                        <td><a href="#" class="delete_acl_user">удалить</a></td>
                    </tr><?php
                }
            }?>
        </tbody>
        </table>
        </div>
            <?php
}

function save_acl_fields($post_id){
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return; 
    if (wp_is_post_revision($post_id)) return;
    
    if($_REQUEST['acl_s_true']){
        update_post_meta($post_id, 'acl_s_true', 'checked');
    }
    else{
        //Если мета существует, а галочка уже убрана, удаляем мету
        $meta_value=get_post_meta($post_id,'acl_s_true',true);
        if(!empty($meta_value)){
            delete_post_meta($post_id,'acl_s_true');
        } 
    }

    $acl_users = explode(',', trim($_REQUEST['acl_users_s']));
    $acl_users = array_unique($acl_users, SORT_STRING);
    $old_acl_users = get_post_meta($post_id, 'acl_users_s');
    foreach ( $acl_users as $user_nicename ) {
      $user_data=get_user_by('slug', $user_nicename);
      if (!(in_array($user_data->ID, $old_acl_users)) && !empty($user_nicename)){
        add_post_meta($post_id, 'acl_users_s', $user_data->ID);
        }
    }
}

function delete_acl_user_callback(){
    delete_post_meta($_REQUEST['post_id'],'acl_users_s',$_REQUEST['user_id']);
    exit;
}

function get_users_for_autocomplete(){
  global $wpdb;
    $users = $wpdb->get_results(
  "
  SELECT user_nicename
  FROM $wpdb->users
  ");
  if( $users ) {
  foreach ( $users as $user ) {
    $user_data[]=$user->user_nicename;
  }
  echo json_encode($user_data);;
}
  exit;
}