<?php
/**
 * @wordpress-plugin
 * Plugin Name:       IU Pressbooks LTI
 * Description:       LTI Integration for Pressbooks at IU. Based on the Candela LTI integration from Lumen Learning, but looks for a specified custom LTI parameter to use for the WordPress login id (instead of using the generated LTI user id)
 * Version:           0.1
 * Author:            UITS eLearning Design and Services
 * Author URI:        http://teachingonline.iu.edu
 * Text Domain:       lti
 * License:           MIT
 * GitHub Plugin URI: 
 */

// If file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) exit;

// Do our necessary plugin setup and add_action routines.
IUPB_LTI::init();

class IUPB_LTI {
  /**
   * Takes care of registering our hooks and setting constants.
   */
  public static function init() {
    // Table name is always root (site)
    define('IUPB_LTI_TABLE', 'wp_iupblti');
    define('IUPB_LTI_DB_VERSION', '1.2');
    define('IUPB_LTI_CAP_LINK_LTI', 'iupb link lti launch');
    define('IUPB_LTI_USERMETA_LASTLINK', 'iupblti_lastkey');
    define('IUPB_LTI_USERMETA_ENROLLMENT', 'iupblti_enrollment_record');
    define('IUPB_LTI_PASSWORD_LENGTH', 32);

    //E. Scull: Add new constants ======================================
    define('IU_LTI_LOGIN_ID_POST_PARAM', 'custom_canvas_user_login_id');
    define('IU_DEFAULT_EMAIL_DOMAIN', 'iu.edu');
    // =================================================================

    register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
    register_uninstall_hook(__FILE__, array( __CLASS__, 'deactivate') );

    add_action( 'init', array( __CLASS__, 'update_db' ) );
    add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ) );
    add_action( 'init', array( __CLASS__, 'setup_capabilities' ) );
    add_action( 'query_vars', array( __CLASS__, 'query_vars' ) );
    add_action( 'parse_request', array( __CLASS__, 'parse_request' ) );

    // Respond to LTI launches
    add_action( 'lti_setup', array( __CLASS__, 'lti_setup' ) );
    add_action( 'lti_launch', array( __CLASS__, 'lti_launch') );

    add_action('admin_menu', array( __CLASS__, 'admin_menu'));

    define('IUPB_LTI_TEACHERS_ONLY', 'iupb_lti_teachers_only');
    add_option( IUPB_LTI_TEACHERS_ONLY, false );
	}

  /**
   * Ensure all dependencies are set and available.
   */
  public static function activate() {
    // Require lti plugin
    if ( ! is_plugin_active( 'lti/lti.php' ) and current_user_can( 'activate_plugins' ) ) {
      wp_die('This plugin requires the LTI plugin to be installed and active. <br /><a href="' . admin_url( 'plugins.php' ) . '">&laquo; Return to Plugins</a>');' )';
    }

    IUPB_LTI::create_db_table();
  }

  // Comment out the code that adds the deprecated "LTI Maps" functionality to the admin nav.
  // TODO: Clean out this Mapping functionality from this entire plugin; it is vestigial and should be removed.

  // public static function admin_menu() {
  //   add_menu_page(
  //     __('LTI maps', 'candela_lti'),
  //     __('LTI maps', 'candela_lti'),
  //     CANDELA_LTI_CAP_LINK_LTI,
  //     'lti-maps',
  //     array(__CLASS__, 'lti_maps_page_handler')
  //   );
  // }

  // public static function lti_maps_page_handler() {
  //   global $wpdb;

  //   include_once(__DIR__ . '/candela-lti-table.php');
  //   $table = new Candela_LTI_Table;
  //   $table->prepare_items();

  //   $message = '';

  //   if ( 'delete' === $table->current_action() ) {
  //     $message = '<div class="updated below-h2" id="message"><p>' . sprintf(__('Maps deleted: %d', 'candela_lti'), count($_REQUEST['ID'])) . '</p></div>';
  //   }

  //   print '<div class="wrap">';
  //   print $message;
  //   print '<form id="candela-lti-maps" method="GET">';
  //   print '<input type="hidden" name="page" value="' . $_REQUEST['page'] . '" />';
  //   $table->display();
  //   print '</form>';
  //   print '</div>';
  // }

  /**
   * Do any necessary cleanup.
   */
  public static function deactivate() {
    IUPB_LTI::remove_db_table();
  }

  /**
   * Responder for action lti_launch.
   */
  public static function lti_launch() {
    global $wp;

    // allows deep links with an LTI launch urls like:
    // <iupb>/api/lti/BLOGID?page_title=page_name
    // <iupb>/api/lti/BLOGID?page_title=section_name%2Fpage_name
    if ( ! empty($wp->query_vars['page_title'] ) ) {
      switch_to_blog((int)$wp->query_vars['blog']);
      $page = $wp->query_vars['page_title'];
      if ( $page[0] ==  '/' ){
        $slash = '';
      } else {
        $slash = '/';
      }

      // todo make all the hide_* parameters copy over?
      // If it's a deep LTI link default to showing content_only
      wp_redirect( get_bloginfo('wpurl') . $slash . $page . "?content_only" );
      exit;
    }

    // allows deep links with an LTI launch urls like:
    // <iupb>/api/lti/BLOGID?page_id=10
    if ( ! empty($wp->query_vars['page_id'] ) && is_numeric($wp->query_vars['page_id']) ) {
      switch_to_blog((int)$wp->query_vars['blog']);
      $url = get_bloginfo('wpurl') . "?p=" . $wp->query_vars['page_id'] . "&content_only&lti_context_id=" . $wp->query_vars['context_id'];
      if (! empty($wp->query_vars['ext_post_message_navigation'] )){
        $url = $url . "&lti_nav";
      }
      wp_redirect( $url );
      exit;
    }

    // allows deep links with an LTI custom parameter like:
    // custom_page_id=10
    if ( ! empty($wp->query_vars['custom_page_id'] ) && is_numeric($wp->query_vars['custom_page_id']) ) {
      switch_to_blog((int)$wp->query_vars['blog']);
      wp_redirect( get_bloginfo('wpurl') . "?p=" . $wp->query_vars['custom_page_id'] . "&content_only&lti_context_id=" . $wp->query_vars['context_id'] );
      exit;
    }

    if ( ! empty($wp->query_vars['resource_link_id'] ) ) {
      $map = IUPB_LTI::get_lti_map($wp->query_vars['resource_link_id']);
      if ( ! empty( $map->target_action ) ) {
        wp_redirect( $map->target_action );
        exit;
      }
    }
    // Currently just redirect to the blog/site homepage.
    if ( ! ( empty( $wp->query_vars['blog'] ) ) ){
      switch_to_blog((int)$wp->query_vars['blog']);
      wp_redirect( get_bloginfo('wpurl') . '/?content_only' );
      exit;
    }

    // redirect to primary site
    wp_redirect( get_site_url( 1 ) );
    exit;
  }

  /**
   * Do any setup necessary to manage LTI launches.
   */
  public static function lti_setup() {
    // Manage authentication and account creation.
    IUPB_LTI::lti_accounts();

    // If this is a valid user store the resource_link_id so we have it later.
    if ( IUPB_LTI::user_can_map_lti_links() ) {
      $current_user = wp_get_current_user();
      update_user_meta( $current_user->ID, IUPB_LTI_USERMETA_LASTLINK, $_POST['resource_link_id'] );
    }
  }

  /**
   * Take care of authenticating the incoming user and creating an account if
   * required.
   */
  public static function lti_accounts() {

    global $wp;

    // Used to track if we call wp_logout() since is_user_logged_in() will still
    // report true after our call to that.
    // @see http://wordpress.stackexchange.com/questions/13087/wp-logout-not-logging-me-out
    $logged_out = FALSE;

    // if we do not have an external user_id skip account stuff.
    if ( empty($_POST[IU_LTI_LOGIN_ID_POST_PARAM]) ) {
      return;
    }

    // Find user account (if any) with matching ID
    //E. Scull: Use login (username) instead of external id
    //$user = IUPB_LTI::find_user_by_external_id( $_POST['user_id'] );
    $user = IUPB_LTI::find_user_by_login( $_POST[IU_LTI_LOGIN_ID_POST_PARAM] );

    //E. Scull: Moved here, was below the following is_user_logged_in() if block
    if ( empty($user) ) {
      // Create a user account if we do not have a matching account
      $user = IUPB_LTI::create_user_account( $_POST[IU_LTI_LOGIN_ID_POST_PARAM] );
    }

    //E. Scull - update user if any role. TODO: Go ahead and add first/last name when creating user. Why is this a separate function? Why only teachers?
    //IUPB_LTI::update_user_if_teacher( $user );
    IUPB_LTI::update_user( $user );



    if ( is_user_logged_in() ) {
      //E. Scull: If the logged in user's login name doesn't match what's passed from LTI, log them out.
      $current_user = wp_get_current_user();
      if($current_user->ID !== $user->ID) {
        wp_logout();
        $logged_out = TRUE;
      }
      else {
        $user = $current_user;
      }
    }

    

    // If the user is not currently logged in... authenticate as the matched account.
    if ( ! is_user_logged_in() || $logged_out ) {
      IUPB_LTI::login_user_no_password( $user->ID );
    }

    // Associate the user with this blog as a subscriber if not already associated.
    $blog = (int)$wp->query_vars['blog'];
    if ( ! empty( $blog ) && ! is_user_member_of_blog( $user->ID, $blog ) ) {
      if( IUPB_LTI::is_lti_user_allowed_to_subscribe($blog)){
        add_user_to_blog( $blog, $user->ID, 'subscriber');
        IUPB_LTI::record_new_register($user, $blog);
      }
    }
  }

  /**
   * Checks if the settings of the book allow this user to subscribe
   * That means that either all LTI users are, or only teachers/admins
   *
   * If the blog's IUPB_LTI_TEACHERS_ONLY option is 1 then only teachers
   * are allowed
   *
   * @param $blog
   */
  public static function is_lti_user_allowed_to_subscribe($blog){
    $role = IUPB_LTI::highest_lti_context_role();
    if( $role == 'admin' || $role == 'teacher' ) {
      return true;
    } else {
      // Switch to the target blog to get the correct option value
      $curr = get_current_blog_id();
      switch_to_blog($blog);
      $teacher_only = get_option(IUPB_LTI_TEACHERS_ONLY);
      switch_to_blog($curr);

      return $teacher_only != 1;
    }
  }

  /**
   * Create a user account corresponding to the current incoming LTI request.
   *
   * @param string $username
   *   The username of the new account to create. If this username already exists
   *   we return the corresponding user account.
   *
   * @todo consider using 'lis_person_contact_email_primary' if passed as email.
   * @return the newly created user account.
   */
  public static function create_user_account( $username ) {
    $existing_user = get_user_by('login', $username);
    if ( ! empty($existing_user) ) {
      return $existing_user;
    }
    else {
      $password = wp_generate_password( IUPB_LTI_PASSWORD_LENGTH, true);

      //E. Scull: TODO, add user's name and other details here too. 
      $user_id = wp_create_user( $username, $password, IUPB_LTI::default_lti_email($username) );

      $user = new WP_User( $user_id );
      $user->set_role( 'subscriber' );

      return $user;
    }
  }

  public static function record_new_register($user, $blog){
    //E. Scull: Comment out role-related filtering; we want names and default email for everyone (including students) 

    $roles = '';
    if (isset($_POST['ext_roles'])) {
      // Canvas' more correct roles values are here
      $roles = $_POST['ext_roles'];
    } else if (isset($_POST['roles'])) {
      $roles = $_POST['roles'];
    }

    $data = array(
        "lti_user_id"=>$_POST[IU_LTI_LOGIN_ID_POST_PARAM],
        "lti_context_id"=>$_POST['context_id'],
        "lti_context_name"=>$_POST['context_title'],
        "lti_school_id"=>$_POST['tool_consumer_instance_guid'],
        "lti_school_name"=>$_POST['tool_consumer_instance_name'],
        "lti_role"=>$roles,
        "timestamp"=>time(),
    );

    //$role = IUPB_LTI::highest_lti_context_role();

    //if ( $role == 'admin' || $role == 'teacher' ) {
      if ( !empty( $_POST['lis_person_name_given'] ) ) {
        $data['lti_first_name'] = $_POST['lis_person_name_given'];
      }
      if ( !empty( $_POST['lis_person_name_family'] ) ) {
        $data['lti_last_name'] = $_POST['lis_person_name_family'];
      }
      if ( !empty( $_POST['lis_person_contact_email_primary'] ) ) {
        $data['lti_email'] = $_POST['lis_person_contact_email_primary'];
      }
    //}

    $curr = get_current_blog_id();
    switch_to_blog($blog);
    update_user_option( $user->ID, IUPB_LTI_USERMETA_ENROLLMENT, $data );
    switch_to_blog($curr);
  }



  // E. Scull: TODO, if we are allowing IU guest accounts, we need to do some more work here 
  // since the numeric user ID won't work for the email (i.e. 80000945605@iu.edu)
  // Should use email from LTI instead?
  public static function default_lti_email( $username ) {
    return $username . '@' . IU_DEFAULT_EMAIL_DOMAIN;
  }

  /**
   * Update user's first/last names
   * If their name wasn't sent, set their name as their role
   *
   * @param $user
   *
   */
  public static function update_user( $user ) {
    $userdata = ['ID' => $user->ID];
    if( !empty($_POST['lis_person_name_family']) || !empty($_POST['lis_person_name_given']) ){
      $userdata['last_name'] = $_POST['lis_person_name_family'];
      $userdata['first_name'] = $_POST['lis_person_name_given'];
    }

    if( !empty($userdata['last_name']) || !empty($userdata['first_name']) ) {
      wp_update_user($userdata);
    }
  }

  /**
   * Parses the LTI roles into an array
   *
   * @return array
   */
  public static function get_current_launch_roles(){
    $roles = [];
    if( isset($_POST['ext_roles']) ) {
      // Canvas' more correct roles values are here
      $roles = $_POST['ext_roles'];
    } elseif (isset($_POST['roles'])){
      $roles = $_POST['roles'];
    } else {
      return $roles;
    }

    $roles = explode(",", $roles);
    return array_filter(array_map('trim', $roles));
  }

  /**
   * Returns the user's highest role, which in this context is defined by this order:
   *
   * Admin
   * Teacher
   * Designer
   * TA
   * Student
   * Other
   *
   * @return string admin|teacher|designer|ta|learner|other
   */
  public static function highest_lti_context_role(){
    $roles = IUPB_LTI::get_current_launch_roles();
    if (in_array('urn:lti:instrole:ims/lis/Administrator', $roles) || in_array('Administrator', $roles)):
      return "admin";
    elseif (in_array('urn:lti:role:ims/lis/Instructor', $roles) || in_array('Instructor', $roles)):
      return "teacher";
    elseif (in_array('urn:lti:role:ims/lis/ContentDeveloper', $roles) || in_array('ContentDeveloper', $roles)):
      return "designer";
    elseif (in_array('urn:lti:role:ims/lis/TeachingAssistant', $roles) || in_array('TeachingAssistant', $roles)):
      return "ta";
    elseif (in_array('urn:lti:role:ims/lis/Learner', $roles) || in_array('Learner', $roles)):
      return "learner";
    else:
      return "other";
    endif;
  }


  //E. Scull - Add function to find user by login name (username) instead of external_id meta field
  public static function find_user_by_login( $login ) {
    switch_to_blog(1);
    $user = get_user_by( 'login', $login );
    restore_current_blog();

    return $user;
  }

  /**
   * login the current user (if not logged in) as the user matching $user_id
   *
   * @see http://wordpress.stackexchange.com/questions/53503/can-i-programmatically-login-a-user-without-a-password
   */
  public static function login_user_no_password( $user_id ) {
    //E. Scull: Finding that user is still considered logged in even after wp_logout() is run above.
    //Remove is_user_logged_in() check to force user switch if this function is run.
    //if ( ! is_user_logged_in() ) {
      wp_clear_auth_cookie();
      wp_set_current_user( $user_id );
      wp_set_auth_cookie( $user_id );
    //}
  }

  /**
   * Add our LTI api endpoint vars so that wordpress "understands" them.
   */
  public static function query_vars( $query_vars ) {
    $query_vars[] = '__iupblti';
    $query_vars[] = 'resource_link_id';
    $query_vars[] = 'target_action';
    $query_vars[] = 'page_title';
    $query_vars[] = 'page_id';
    $query_vars[] = 'action';
    $query_vars[] = 'ID';
    $query_vars[] = 'context_id';
    $query_vars[] = 'iupb-lti-nonce';
    $query_vars[] = 'custom_page_id';
    $query_vars[] = 'ext_post_message_navigation';

    return $query_vars;
  }


  /**
   * Update the database
   */
  public static function update_db() {
    switch_to_blog(1);
    $version = get_option( 'iupb_lti_db_version', '');
    restore_current_blog();

    if (empty($version) || $version == '1.0') {
      $meta_type = 'user';
      $user_id = 0; // ignored since delete all = TRUE
      $meta_key = 'iupblti_lti_info';
      $meta_value = ''; // ignored
      $delete_all = TRUE;
      delete_metadata( $meta_type, $user_id, $meta_key, $meta_value, $delete_all );

      switch_to_blog(1);
      update_option( 'iupb_lti_db_version', IUPB_LTI_DB_VERSION );
      restore_current_blog();
    }
    if ( $version == '1.1' ) {
      // This also updates the table.
      IUPB_LTI::create_db_table();
    }
  }

  /**
   * Add our LTI resource_link_id mapping api endpoint
   */
  public static function add_rewrite_rule() {
    add_rewrite_rule( '^api/iupblti?(.*)', 'index.php?__iupblti=1&$matches[1]', 'top');
  }

  /**
   * Setup our new capabilities.
   */
  public static function setup_capabilities() {
    global $wp_roles;

    $wp_roles->add_cap('administrator', IUPB_LTI_CAP_LINK_LTI);
    $wp_roles->add_cap('editor', IUPB_LTI_CAP_LINK_LTI);
  }

  /**
   * Implementation of action 'parse_request'.
   *
   * @see http://codex.wordpress.org/Plugin_API/Action_Reference/parse_request
   */
  public static function parse_request() {
    global $wp, $wpdb;

    if ( IUPB_LTI::user_can_map_lti_links() && isset( $wp->query_vars['__iupblti'] ) && !empty($wp->query_vars['__iupblti'] ) ) {
      // Process adding link associations
      if ( wp_verify_nonce($wp->query_vars['iupb-lti-nonce'], 'mapping-lti-link') &&
           ! empty( $wp->query_vars['resource_link_id']) &&
           ! empty( $wp->query_vars['target_action'] ) ) {
        // Update db record everything is valid
        $map = IUPB_LTI::get_lti_map($wp->query_vars['resource_link_id'] );

        $current_user = wp_get_current_user();
        $values = array(
          'resource_link_id' => $wp->query_vars['resource_link_id'],
          'target_action' => $wp->query_vars['target_action'],
          'user_id' => $current_user->ID,
          'blog_id' => $wp->query_vars['blog'],
        );
        $value_format = array(
          '%s',
          '%s',
          '%d',
          '%d',
        );

        if ( ! empty( $map->target_action ) ) {
          // update the existing map.
          $where = array( 'resource_link_id' => $wp->query_vars['resource_link_id'] );
          $where_format = array( '%s' );
          $result = $wpdb->update(IUPB_LTI_TABLE, $values, $where, $value_format, $where_format );
        }
        else {
          // map was empty... insert the new map.
          $result = $wpdb->insert(IUPB_LTI_TABLE, $values, $value_format );
        }

        if ( $result === FALSE ) {
          // die with error error
          wp_die('Failed to map resource_link_id(' . $wp->query_vars['resource_link_id'] . ') to url(' . $wp->query_vars['target_action']) . ')';
        }
      }

      // Process action items.
      if ( wp_verify_nonce($wp->query_vars['iupb-lti-nonce'], 'unmapping-lti-link') && ! empty( $wp->query_vars['action'] ) ) {
        switch ( $wp->query_vars['action'] ) {
          case 'delete':
            if ( !empty($wp->query_vars['ID'] && is_numeric($wp->query_vars['ID']))) {
              $wpdb->delete( IUPB_LTI_TABLE, array( 'ID' => $wp->query_vars['ID'] ) );
            }
            break;
        }
      }

      // If we have a target_action, redirect to it, otherwise redirect back to home.
      if ( ! empty( $wp->query_vars['target_action'] ) ) {
        wp_redirect( $wp->query_vars['target_action'] );
      }
      else if ( ! empty($_SERVER['HTTP_REFERER'] ) ) {
        wp_redirect( $_SERVER['HTTP_REFERER'] );
      }
      else {
        wp_redirect( home_url() );
      }
      exit();
    }

  }

  /**
   * Given a resource_link_id return the mapping row for that resource_link_id.
   *
   * @param string resource_link_id
   *   The resource_link_id to get the row for. If empty the last LTI launch link
   *   for the user if user is logged in will be used.
   *
   * @return object
   *  Either the matching row or an object with just the resource_link_id set.
   */
  public static function get_lti_map( $resource_link_id = '' ) {
    global $wpdb;

    if ( empty( $resource_link_id ) && is_user_logged_in() ) {
      $current_user = wp_get_current_user();
      // Make sure query is ran against primary site since usermeta was set via
      // lti_setup action.
      switch_to_blog(1);
      $resource_link_id = get_user_meta( $current_user->ID, IUPB_LTI_USERMETA_LASTLINK, TRUE );
      restore_current_blog();
    }

    $table_name = IUPB_LTI_TABLE;
    $sql = $wpdb->prepare("SELECT * FROM $table_name WHERE resource_link_id  = %s", $resource_link_id);

    $map = $wpdb->get_row( $sql );

    if ( empty( $map ) ) {
      $map = new stdClass;
      $map->resource_link_id = $resource_link_id;
    }

    return $map;

  }

  public static function get_maps_by_target_action( $target_action = '' ) {
    global $wpdb;

    if ( empty( $target_action ) && is_single() ) {
      $target_action = get_permalink();
    }

    $table_name = IUPB_LTI_TABLE;
    $sql = $wpdb->prepare("SELECT * FROM $table_name WHERE target_action = %s", $target_action);
    return $wpdb->get_results($sql);
  }

  /**
   * If we have an authenticated user and unmapped LTI launch add a link to
   * associate current page with the LTI launch.
   */
  public static function content_map_lti_launch( $content ) {
    if ( is_single()
        && IUPB_LTI::user_can_map_lti_links()
        && empty($wp->query_vars['page_title'])
        && ! isset($_GET['content_only']) ) {

      $map = IUPB_LTI::get_lti_map();
      $target_action = get_permalink();
      $resource_link_id = '';
      $links = array();

      if ( empty( $map ) || ( empty( $map->target_action ) && ! empty( $map->resource_link_id ) ) ) {
        $resource_link_id = $map->resource_link_id;
        // Map is either not set at all or needs to be set, inject content to do so.
        $text = __('Add LTI link');
        $hover = __('resource_link_id(##RES##)');
        $url = get_site_url(1) . '/api/iupblti';
        $url = wp_nonce_url($url, 'mapping-lti-link', 'iupb-lti-nonce');
        $url .= '&resource_link_id=' . urlencode($map->resource_link_id) . '&target_action=' . urlencode( $target_action ) . '&blog=' . get_current_blog_id();
        $links['add'] = '<div class="lti addmap"><a class="btn blue" href="' . $url . '" title="' . esc_attr( str_replace('##RES##', $map->resource_link_id, $hover) ) . '">' . $text . '</a></div>';
      }

      $maps = IUPB_LTI::get_maps_by_target_action();
      if ( ! empty( $maps ) ) {
        $base_url = get_site_url(1) . '/api/iupblti';
        $base_url = wp_nonce_url($base_url, 'unmapping-lti-link', 'iupb-lti-nonce');
        $text = __('Remove LTI link');
        $hover = __('resource_link_id(##RES##)');
        foreach ( $maps as $map ) {
          if ($map->resource_link_id == $resource_link_id ) {
            // don't include add and delete link
            unset($links['add']);
          }
          $url = $base_url . '&action=delete&ID=' . $map->ID . '&blog=' . get_current_blog_id();
          $links[] = '<a class="btn red" href="' . $url . '"title="' . esc_attr( str_replace('##RES##', $map->resource_link_id, $hover) ) . '">' . $text . '</a>';
        }
      }

      if ( ! empty( $links ) ) {
        $content .= '<div class="lti-mapping"><ul class="lti-mapping"><li>' . implode('</li><li>', $links) . '</li></ul></div>';
      }
    }
    return $content;
  }

  /**
   * See if the current user (if any) can map LTI launch links to destinations.
   *
   * @todo add proper checks, currently this just checks if the user is logged in.
   */
  public static function user_can_map_lti_links() {
    global $wp;
    $switched = FALSE;
    if ( ! ( empty( $wp->query_vars['blog'] ) ) ){
      switch_to_blog( (int) $wp->query_vars['blog'] );
      $switched = TRUE;
    }

    if ( is_user_logged_in() ) {
      $current_user = wp_get_current_user();
      if ( $current_user->has_cap(IUPB_LTI_CAP_LINK_LTI) ) {
        if ( $switched ) {
          restore_current_blog();
        }
        return TRUE;
      }
    }
    if ( $switched ) {
      restore_current_blog();
    }
    return FALSE;
  }

  /**
   * Create a database table for storing LTI maps, this is a global table.
   */
  public static function create_db_table() {
    $table_name = IUPB_LTI_TABLE;

    $sql = "CREATE TABLE $table_name (
      ID mediumint(9) NOT NULL AUTO_INCREMENT,
      resource_link_id TINYTEXT,
      target_action TINYTEXT,
      user_id mediumint(9),
      blog_id mediumint(9),
      PRIMARY KEY  (id),
      UNIQUE KEY resource_link_id (resource_link_id(32))
    );";
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta( $sql );

    switch_to_blog(1);
    update_option( 'iupb_lti_db_version', IUPB_LTI_DB_VERSION );
    restore_current_blog();
  }

  /**
   * Remove database table.
   */
  public static function remove_db_table() {
    global $wpdb;
    $table_name = IUPB_LTI_TABLE;
    $wpdb->query("DROP TABLE IF EXISTS $table_name");
    delete_option('iupb_lti_db_version');
  }

}
