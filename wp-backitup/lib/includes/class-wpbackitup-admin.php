<?php if (!defined ('ABSPATH')) die('No direct access allowed');

/**
 * WP BackItUp  - Admin Class
 *
 * @package WP BackItUp
 * @author  Chris Simmons <chris.simmons@wpbackitup.com>
 * @link    http://www.wpbackitup.com
 *
 */


class WPBackitup_Admin {

    public $namespace = WPBACKITUP__NAMESPACE;
    public $friendly_name = WPBACKITUP__FRIENDLY_NAME;
    public $version = WPBACKITUP__VERSION;

	const  DB_VERSION = WPBACKITUP__DB_VERSION;

    private static $instance = false;

    private $backup_retained_number; //Getter will load

	public $backup_type;

    /**
     * @var WPBackItUp_Cleanup_Processor
     */
    protected $cleanup_processor;

    /**
     * @var WPBackItUp_File_Cleanup_Processor
     */
    protected $file_cleanup_processor;

    /**
     * @var WPBackItUp_Directory_Cleanup_Processor
     */
    protected $dir_cleanup_processor;

    // Default plugin options
    public $defaults = array(
        'logging' => 0,
        'notification_email' => "",
        'backup_retained_number' => "3",
        'lite_backup_retained_number' => "1",
        'backup_count'=>0,
        'successful_backup_count'=>0,
        'stats_last_check_date'=> "1970-01-01 00:00:00",
        'backup_lastrun_date'=>"-2147483648",
        'cleanup_lastrun_date'=>"-2147483648",
        'delete_all' => 0,
        'safe_sync' => 0,
        'beta_updates' => 0,
        'rversion_compare' => 0,
        'backup_dbtables_batch_size'=> WPBACKITUP__DATABASE_BATCH_SIZE,
        'backup_sql_merge_batch_size' => WPBACKITUP__SQL_MERGE_BATCH_SIZE,
        'backup_sql_batch_size' => WPBACKITUP__SQL_BATCH_SIZE,
	    'backup_plugins_batch_size'=>WPBACKITUP__PLUGINS_BATCH_SIZE,
        'backup_themes_batch_size'=>WPBACKITUP__THEMES_BATCH_SIZE,
        'backup_uploads_batch_size'=>WPBACKITUP__UPLOADS_BATCH_SIZE,
        'backup_others_batch_size'=>WPBACKITUP__OTHERS_BATCH_SIZE,
        'backup_zip_max_size'=>WPBACKITUP__ZIP_MAX_FILE_SIZE,
        'backup_max_timeout'=>WPBACKITUP__TASK_TIMEOUT_SECONDS,
        'backup_plugins_filter'=> '',
        'backup_themes_filter' => '',
        'backup_uploads_filter' => '',
        'backup_others_filter' => '',
        'backup_dbtables_filter_list'=>'',
	    'support_email' => "",
        'single_file_backupset'=>0,
        'single_file_db'=>0,
        'encrypt_files'=>0,
        'remove_supporting_zip_files'=>0,
        'restore_count'=>0,
        'successful_restore_count'=>0,
    );


     /**
     * Retrieve the current WP backItUp instance.
     */
    public static function get_instance() {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Instantiation construction
     *
     */
    public function __construct() {

    	//Load Dependencies
	    $this->load_dependencies();

        // Add all action, filter and shortcode hooks
        $this->_add_hooks();

//      $this->load_constants();
//      $this->set_locale();

    }

	/**
	 * Load the required dependencies for this plugin.
	 * - This is new method that will be used as part of a
	 * larger refactor
	 *
	 */
	private function load_dependencies() {

		//Scheduler Class
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-job-scheduler.php';

		//Utility
		//require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-utility.php';

		//API Class
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-api.php';

		//License Class
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-license.php';

	}

    /**
     * Add in various hooks
     */
    private function _add_hooks() {

	    $ajax_prefix='wp_ajax_'.WPBACKITUP__NAMESPACE.'_';

        // Options page for configuration
        if( is_multisite() ) {
            add_action( 'network_admin_menu', array( &$this, 'admin_menu' ) );
        } else {
            add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
        }

	    // Route requests for form processing
	    add_action( 'admin_init', array( &$this, 'maybe_update' ) );
	    add_action( 'admin_init', array( &$this, 'route' ) );

        // Add a settings link next to the "Deactivate" link on the plugin listing page
        add_filter( 'plugin_action_links', array( &$this, 'plugin_action_links' ), 10, 2 );

        //Load all the resources
        add_action( 'admin_enqueue_scripts', array( &$this, 'load_resources' ) );

        // delete transient
        add_action($ajax_prefix .'delete_transient', array( &$this,'ajax_queue_delete_transient'));

        //Queue and run backup
        add_action($ajax_prefix . 'backup', array( &$this, 'ajax_queue_manual_backup' ));

        //Load the cancel backup action
        add_action($ajax_prefix .'cancel_backup', array( &$this, 'ajax_queue_cancel_backup' ));

        //Load the restore action
        add_action($ajax_prefix .'restore', array( &$this, 'ajax_queue_restore' ));

        //Load the upload action
        add_action($ajax_prefix .'plupload_action', array($this,'plupload_action'));

		//Status readers for UI
		add_action($ajax_prefix .'backup_status_reader', array( &$this,'ajax_get_backup_status'));
        add_action($ajax_prefix .'restore_status_reader', array( &$this,'ajax_get_restore_status'));

        //Run tasks using new background processing engine
        add_action('wp_ajax_nopriv_wp-backitup_run_task', array( &$this,'ajax_run_task'));
	    add_action($ajax_prefix .'run_task', array( &$this,'ajax_run_task'));

        add_action($ajax_prefix .'backup_response_reader', array( &$this,'ajax_backup_response_reader'));

        //Delete File Action
        add_action($ajax_prefix .'delete_file', array( &$this,'ajax_delete_backup'));

	    //Safe Upload Action
	    add_action($ajax_prefix .'safe_upload', array( &$this,'ajax_safe_upload'));

        //Single backup zip file list
        add_action($ajax_prefix .'backup_zip_filelist', array( &$this,'ajax_get_backup_zip_filelist'));

        //Get and Add note to backup
        add_action($ajax_prefix .'backup_get_note', array( &$this,'ajax_backup_get_note'));
        add_action($ajax_prefix .'backup_add_note', array( &$this,'ajax_backup_add_note'));

        // Get and Set all settings via Ajax
        add_action($ajax_prefix .'get_settings', array( &$this,'ajax_backup_get_settings'));
        add_action($ajax_prefix .'set_settings', array( &$this,'ajax_backup_set_settings'));

        //View Log Action
        add_action('admin_post_viewlog', array( &$this,'admin_viewlog'));

	    //Download Backup
	    add_action('admin_post_download_backup', array( &$this,'admin_download_backup'));

        //Create Daily backup action
        add_action( 'wpbackitup_queue_scheduled_jobs',  array( &$this,'wpbackitup_queue_scheduled_jobs'));

        //add scheduler actions
	    $job_scheduler = new WPBackItUp_Job_Scheduler();
	    add_action( 'wpbackitup_jobs_scheduled', array($job_scheduler, 'jobs_scheduled' ));
	    add_action( 'wpbackitup_save_schedule', array($job_scheduler, 'save_backup_schedule' ));

	    $api = new WPBackItUp_API();
	    add_action( $ajax_prefix. 'get_available_backups',array($api,'get_available_backups'));
	    add_action( $ajax_prefix. 'get_backup_schedule',array($api,'get_backup_schedule'));
	    add_action( $ajax_prefix. 'set_backup_schedule',array($api,'set_backup_schedule'));

	    //License action
	    $wpbackitup_license = new WPBackItUp_License();
	    add_action( 'wpbackitup_check_license', array($wpbackitup_license, 'check_license'));

    }

    /**
     *
     * Define the admin menu options for this plugin
     *
     */
    public  function admin_menu() {

	    add_menu_page( $this->friendly_name, $this->friendly_name, 'administrator', $this->namespace, array( &$this, 'admin_backup_page' ), 'dashicons-backup', 77);

        //Add Backup Menu Nav
        add_submenu_page( $this->namespace, __('Backup', 'wp-backitup'), __('Backup','wp-backitup'), 'administrator', $this->namespace.'-backup', array( &$this, 'admin_backup_page' ) );

	    //Add Support Menu Nav
	    add_submenu_page( $this->namespace, __('Support', 'wp-backitup'), __('Support','wp-backitup'), 'administrator', $this->namespace.'-support', array( &$this, 'admin_support_page' ) );

        //Add Settings Menu Nav
        add_submenu_page( $this->namespace, __('Settings', 'wp-backitup'), __('Settings','wp-backitup'), 'administrator', $this->namespace.'-settings', array( &$this, 'admin_settings_page' ) );

        // Add about Nav
        add_submenu_page( $this->namespace, __('About', 'wp-backitup'), __('About','wp-backitup'), 'administrator', $this->namespace.'-about', array( &$this, 'admin_about_page' ) );

	    // Add Get Premium
	    $wpb_license = new WPBackItUp_License();
	    if (! $wpb_license->is_license_active()) {
		  //add_submenu_page( $this->namespace, __('Premium', 'wp-backitup'), __('Get Premium <span class=\'dashicons dashicons-admin-network wpbackitup-get-premium\'></span>','wp-backitup'), 'administrator', $this->namespace.'-about&tab=premium', array( &$this, 'admin_about_page' ) );
	        add_submenu_page( $this->namespace, __('Premium', 'wp-backitup'), sprintf("%s <span class='dashicons dashicons-admin-network wpbackitup-get-premium'></span>",__('Get Premium','wp-backitup')), 'administrator', $this->namespace.'-about&tab=premium', array( &$this, 'admin_about_page' ) );
        }

	    //show test page when true AND localhost
        if (WPBACKITUP__DEBUG===true && ($_SERVER['HTTP_HOST']=='localhost' || '.dev'==substr($_SERVER['HTTP_HOST'],-4))){
            add_submenu_page( $this->namespace, 'Test', 'Test', 'administrator', $this->namespace.'-test', array( &$this, 'admin_test_page' ) );
        }
        // remove duplicate submenu page. wp limitations //
        // http://wordpress.stackexchange.com/questions/16401/remove-duplicate-main-submenu-in-admin
        remove_submenu_page($this->namespace,$this->namespace);

    }

    public  function load_resources() {

	    // Admin Stylesheet
	    wp_register_style( "{$this->namespace}-admin", WPBACKITUP__PLUGIN_URL . "css/wp-backitup-admin.min.css", array(), $this->version, 'screen' );
        wp_register_script( "{$this->namespace}-admin", WPBACKITUP__PLUGIN_URL . "js/wp-backitup-admin.min.js", array( 'jquery' ), $this->version, true );

	    wp_enqueue_style( "{$this->namespace}-admin" );

	    //Only load the JS and CSS when plugin is active
	    if( !empty($_REQUEST['page']) && substr($_REQUEST['page'], 0, 11) === 'wp-backitup') {

            // update body class
            // Added inline function to support PHP 5.2
            function add_admin_body_class( $classes ) {
                $classes .= ' wpb-main';
                return $classes;
            }

            add_filter( 'admin_body_class', 'add_admin_body_class');

			//JavaScript Messages
            $translation_array = array(
                'upload_file_size_exceed'  => __( 'The backup you have selected exceeds what your host allows you to upload.', 'wp-backitup' ),
                'warning' => __('Warning', 'wp-backitup'),
                'error' => __('Error', 'wp-backitup'),
                'response' => __('Response', 'wp-backitup'),
                'status' => __('Status', 'wp-backitup'),
                'download' => __('Download', 'wp-backitup'),
                'delete' => __('Delete', 'wp-backitup'),
                'restore' => __('Restore', 'wp-backitup'),
                'unexpected_err' => __('(JS997) Unexpected error', 'wp-backitup'),
                'unexpected_err2' => __('(JS998) Unexpected error', 'wp-backitup'),
                'unexpected_err3' => __('(JS999) An unexpected error has occurred', 'wp-backitup'),
                'scheduled_saved' => __('Scheduled has been saved.', 'wp-backitup'),
                'scheduled_not_saved' => __('Scheduled was not saved.', 'wp-backitup'),
                'confirm_restore' => __('Are you sure you want to restore your site?', 'wp-backitup'),
                'sure' => __('Are you sure ?', 'wp-backitup'),
                'file_not_del' => __('This file cannot be delete!', 'wp-backitup'),
                'view_log' => __('View Log', 'wp-backitup'),
                'new_backup' => __('New Backup!', 'wp-backitup'),
                'uploaded_backup' => __('Uploaded Backup', 'wp-backitup'),

                // Backup list
                'bl_backup_set' => __('WP BackItUp Backup Set:', 'wp-backitup'),
                'bl_note_save' => __('Save', 'wp-backitup'),
                'bl_note_saved_success' => __('Note Saved!', 'wp-backitup'),
                'bl_note_placeholder' => __('add notes here', 'wp-backitup'),
                'bl_backup_download_single' => __('Download a single zip file that contains all these files.', 'wp-backitup'),
                'bl_backup_download' => __('Below are the archive files included in this backup set. Click the link to download.', 'wp-backitup'),
                'bl_please_note' => __('* Please note that this is a ', 'wp-backitup'),
                'bl_may_timeout' => __(' file which may timeout on some hosts.', 'wp-backitup'),

                //Settings View
                'settings_save_awesome' => __('Awesome!', 'wp-backitup'),
                'settings_save_success_message' => __('Your settings has been successfully saved', 'wp-backitup'),
                'settings_save_oops' => __('Oops...', 'wp-backitup'),
                'settings_save_error_message' => __('Something went wrong', 'wp-backitup'),
                'settings_no_database_table_to_filter' => __('No database table to filter', 'wp-backitup'),
                'settings_select_database_table_to_filter' => __('Select Database table to filter', 'wp-backitup'),


                //Nonces
                'get_available_backups' => wp_create_nonce('get_available_backups'),
                'get_backup_schedule' => wp_create_nonce('get_backup_schedule'),
                'set_backup_schedule' => wp_create_nonce('set_backup_schedule'),
            );

		    wp_localize_script( "{$this->namespace}-admin", 'wpbackitup_local',$translation_array);

            //Admin fonts
		    wp_register_style( 'fontawesome-fonts', 'https://use.fontawesome.com/releases/v5.4.2/css/all.css' );
		    wp_enqueue_style( 'fontawesome-fonts' );

		    //Leave these for premium <2.0 customers
	        wp_register_style( 'google-fonts', 'https://maxcdn.bootstrapcdn.com/font-awesome/4.6.3/css/font-awesome.min.css' );
            wp_enqueue_style( 'google-fonts' );

            // Jquery UI
            /// wp_register_style( "{$this->namespace}-jquery-ui-css", WPBACKITUP__PLUGIN_URL . "css/jquery-ui.min.css", array(), $this->version, 'screen' );
            // wp_enqueue_style( "{$this->namespace}-jquery-ui-css" );

            // Enqueue Style for Settings page
            // Todo:: Maybe Removes these file loadings from wpbackitup-safe
            wp_enqueue_style( $this->namespace . '_vue_tab', WPBACKITUP__PLUGIN_URL . 'css/vue-tabs.min.css', array(), $this->version, false );
//          wp_enqueue_style( $this->namespace . '_sweetalert', WPBACKITUP__PLUGIN_URL . 'css/sweetalert.css', array(), $this->version, false);
            wp_enqueue_style( $this->namespace . '_vue_keen_ui', WPBACKITUP__PLUGIN_URL . 'css/keen-ui.min.css', array(), $this->version, false);
            wp_enqueue_style( $this->namespace . '_vue_pretty_checkbox_ui', WPBACKITUP__PLUGIN_URL . 'css/pretty-checkbox.min.css', array(), $this->version, false);
            wp_enqueue_style( $this->namespace . '_vue_element_ui', WPBACKITUP__PLUGIN_URL . 'css/element-ui.min.css', array(), $this->version, false);


            // Included all Jquery UI files.
            wp_enqueue_script( 'jquery-ui-core' );
            wp_enqueue_script( 'jquery-ui-widget' );
            wp_enqueue_script( 'jquery-ui-autocomplete' );
            wp_enqueue_script( 'jquery-ui-tabs' );
            wp_enqueue_script( 'jquery-ui-sortable' );
            wp_enqueue_script( 'jquery-ui-draggable' );
            wp_enqueue_script( 'jquery-ui-droppable' );

            // Loading new JS files for VueJS
            // Todo:: Maybe Removes these file loadings from wpbackitup-safe
            wp_enqueue_script($this->namespace . '_vue', WPBACKITUP__PLUGIN_URL . 'js/vue.min.js', array(), $this->version, false);
            wp_enqueue_script($this->namespace . '_vue_tabs', WPBACKITUP__PLUGIN_URL . 'js/vue-tabs.min.js', array(), $this->version, false);
            wp_enqueue_script($this->namespace . '_jquery_sweetalert', WPBACKITUP__PLUGIN_URL . 'js/sweetalert.min.js', array('jquery'), $this->version, false);
            wp_enqueue_script($this->namespace . '_vue_keen_ui', WPBACKITUP__PLUGIN_URL . 'js/keen-ui.min.js', array(), $this->version, false);
            wp_enqueue_script($this->namespace . '_vue_pretty_checkbox_ui', WPBACKITUP__PLUGIN_URL . 'js/pretty-checkbox-vue.min.js', array(), $this->version, false);
            wp_enqueue_script($this->namespace . '_vue_pretty_element_ui', WPBACKITUP__PLUGIN_URL . 'js/element-ui.min.js', array(), $this->version, false);
            wp_enqueue_script($this->namespace . '_vue_components', WPBACKITUP__PLUGIN_URL . 'js/wp-backitup-components.min.js', array(), $this->version, false);

            // Loading tagit and core JS
            wp_enqueue_script( "{$this->namespace}-admin" );


            //UPLOADS only
            if ($_REQUEST['page']=='wp-backitup-restore') {
                wp_enqueue_media();
            }
	    }

    }

    /**
     * The admin section backup page rendering method
     *
     */
    public  function admin_backup_page()
    {
      if( !current_user_can( 'manage_options' ) ) {
          wp_die( 'You do not have sufficient permissions to access this page' );
      }

      include WPBACKITUP__PLUGIN_PATH . "/views/backup.php";
    }

    /**
     * The admin section settings page rendering method
     *
     */
    public  function admin_settings_page()
    {

      if( !current_user_can( 'manage_options' ) ) {
          wp_die( 'You do not have sufficient permissions to access this page.' );
      }

      include WPBACKITUP__PLUGIN_PATH . "/views/settings.php";
    }

	/**
	 * The admin section support page rendering method
	 *
	 */
	public  function admin_support_page()
	{
		include WPBACKITUP__PLUGIN_PATH . "/views/support.php";
	}

    /**
     * The admin section about page rendering method
     *
     */
    public  function admin_about_page()
    {
        include WPBACKITUP__PLUGIN_PATH . "/views/about.php";
    }


    /**
     * The admin section backup page rendering method
     *
     */
    public  function admin_test_page()
    {
        if( !current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have sufficient permissions to access this page' );
        }

        include WPBACKITUP__PLUGIN_PATH . "/views/test.php";
    }

     /**
     * Route the user based off of environment conditions
     *
     * @uses WPBackitup::_admin_options_update()
     */
    public  function route() {

	    //check to see if this if wpbackitup was just activated
	    if ( get_transient( '_wpbackitup_activation_redirect' ) ){

		    // Delete the redirect transient
		    delete_transient( '_wpbackitup_activation_redirect' );

		    if ( ! is_network_admin() && !isset( $_GET['activate-multi'] ) ){
			    $upgrade = get_option( 'wp-backitup_new_install' );
	            if(  $upgrade == 1 ) { // First time install
		            delete_option( 'wp-backitup_new_install');
	                wp_safe_redirect( admin_url( add_query_arg( array( 'page' => 'wp-backitup-about'  ), 'admin.php' )));
	            } else { // Update
	                wp_safe_redirect( admin_url( add_query_arg( array( 'page' => 'wp-backitup-about'  ), 'admin.php' ))) ;
	            }

			    return; //dont do anything else
		    }
	    }

        $uri = $_SERVER['REQUEST_URI'];
        $protocol = isset( $_SERVER['HTTPS'] ) ? 'https' : 'http';
        $hostname = $_SERVER['HTTP_HOST'];
        $url = "{$protocol}://{$hostname}{$uri}";
        $is_post = (bool) ( strtoupper( $_SERVER['REQUEST_METHOD'] ) == "POST" );

        // Check if a nonce was passed in the request
        if( isset( $_REQUEST['_wpnonce'] ) ) {
            $nonce = $_REQUEST['_wpnonce'];

	        $wpbdebug_logname='wpb_debug';
            //WPBackItUp_Logger::log_info($wpbdebug_logname,__METHOD__,'NONCE:' .$nonce);

            // Handle POST requests
            if( $is_post ) {

                if( wp_verify_nonce( $nonce, "{$this->namespace}-update-options" ) ) {
	                WPBackItUp_Logger::log_info($wpbdebug_logname,__METHOD__,'Update Options Form Post');
	                //error_log('update options');
	                $this->_admin_register();
                }

                if( wp_verify_nonce( $nonce, "{$this->namespace}-register" ) ) {
	                //error_log('register');
	                WPBackItUp_Logger::log_info($wpbdebug_logname,__METHOD__,'Register Lite Form Post');
                    $this->_admin_register();
                }

	            if( wp_verify_nonce( $nonce, "{$this->namespace}-register-ce" ) ) {
		            //error_log('register-ce');
		            WPBackItUp_Logger::log_info($wpbdebug_logname,__METHOD__,'Register Lite Form Post');
		            $this->_admin_register();
	            }

                if( wp_verify_nonce( $nonce, "{$this->namespace}-update-schedule" ) ) {
	                WPBackItUp_Logger::log_info($wpbdebug_logname,__METHOD__,'Update Schedule Form Post');

	                $val = $_POST['days_selected'];
	                $days_selected = $this->_sanitize($val);

	                do_action('wpbackitup_save_schedule',$days_selected);

					//This was conditional but now that it is handled in an action
	                //just return true
                    $jsonResponse = new stdClass();
                    $jsonResponse->message = 'success';

                    exit(json_encode($jsonResponse));

                }

	            if( wp_verify_nonce( $nonce, "{$this->namespace}-support-form" ) ) {
		            WPBackItUp_Logger::log_info($wpbdebug_logname,__METHOD__,'Support Form Post');
		            $this->_admin_send_support_request();
	            }

            }
            // Handle GET requests
            else {

            }
        } else {

        }
    }

    public function initialize(){

        /**
         * These classes are responsible for async requests & background processing
         */

        require_once(WPBACKITUP__PLUGIN_PATH . '/lib/background-processing/class-async-request.php');
        require_once(WPBACKITUP__PLUGIN_PATH . '/lib/background-processing/class-background-process.php');
        require_once(WPBACKITUP__PLUGIN_PATH . '/lib/background-processing/class-processors.php');
        require_once(WPBACKITUP__PLUGIN_PATH . '/lib/background-processing/class-task-processor.php');
	    require_once(WPBACKITUP__PLUGIN_PATH . '/lib/background-processing/class-cleanup-processor.php');
        require_once(WPBACKITUP__PLUGIN_PATH . '/lib/background-processing/class-file-cleanup-processor.php');
        require_once(WPBACKITUP__PLUGIN_PATH . '/lib/background-processing/class-directory-cleanup-processor.php');

		require_once( WPBACKITUP__PLUGIN_PATH . '/lib/includes/class-logger.php' );
		require_once( WPBACKITUP__PLUGIN_PATH . '/lib/includes/class-loggerV2.php' );
		require_once( WPBACKITUP__PLUGIN_PATH . '/lib/includes/class-job.php' );
		require_once( WPBACKITUP__PLUGIN_PATH . '/lib/includes/class-job-task.php' );
        require_once( WPBACKITUP__PLUGIN_PATH . '/lib/includes/class-job-item.php' );

//	    require_once( WPBACKITUP__PLUGIN_PATH . '/lib/includes/class-license.php' );
        require_once( WPBACKITUP__PLUGIN_PATH . '/lib/includes/class-cleanup.php' );

	    require_once( WPBACKITUP__PLUGIN_PATH . '/lib/includes/class-cron.php' );
	    require_once( WPBACKITUP__PLUGIN_PATH . '/lib/includes/class-usage.php' );
	    require_once( WPBACKITUP__PLUGIN_PATH . '/lib/includes/admin-actions.php' );

        // This class is used for showing a review nag
        require_once( WPBACKITUP__PLUGIN_PATH . '/lib/includes/class-admin-notice.php' );
	    require_once( WPBACKITUP__PLUGIN_PATH . '/lib/includes/class-admin-notices.php' );

	    //Settings Class
	    require_once( WPBACKITUP__PLUGIN_PATH . '/lib/includes/class-settings.php' );


	    //Admin Bar
	    require_once( WPBACKITUP__PLUGIN_PATH . '/lib/includes/class-admin-bar.php' );

		$languages_path = dirname(dirname(dirname( plugin_basename( __FILE__ )))) . '/languages/';

	    load_plugin_textdomain(
            'wp-backitup',
		    false,
		    $languages_path
	    );

	    //display any active notices
	    $notices = new WPBackitup_Admin_Notices();
	    $notices->run();//display any active notices

        //Create instance to service background tasks(ajax requests)
        $task_processor = new WPBackItUp_Task_Processor();

        // new refactor
        $this->cleanup_processor = new WPBackItUp_Cleanup_Processor();
        $this->file_cleanup_processor = new WPBackItUp_File_Cleanup_Processor();
        $this->dir_cleanup_processor = new WPBackItUp_Directory_Cleanup_Processor();

        $cleanup = new WPBackItUp_Cleanup();
        $cleanup->init();
    }

    /**
     *  Dispatch Async tasks
     *
     * @param $task_type
     * @param @task_list
     */
    public function dispatch_async_tasks($task_type, $task_list){
        $processor = $this->get_async_task_processor($task_type);

        if( !is_null($processor) ){
            foreach ( $task_list as $item ) {
                $processor->push_to_queue( $item );
            }
            $processor->save()->dispatch();
        }
    }


    /**
     * Get task processor object.
     *
     * @param $task_type
     * @return object| null
     */
    private function get_async_task_processor($task_type){

        switch ($task_type){
            case Processors::CLEANUP:
                return $this->cleanup_processor;
                break;
            case Processors::FILE_CLEANUP:
                return $this->file_cleanup_processor;
                break;
            case Processors::DIRECTORY_CLEANUP:
                return $this->dir_cleanup_processor;
                break;
            case Processors::DB_CLEANUP:
                return $this->db_cleanup_processor;
                break;
            default:
                WPBackItUp_Logger::log_info('sync_task_processor',__METHOD__,'No task processor found.');
                return null;
                break;
        }
    }


	/**
	 * Queue scheduled jobs
	 */
	public function wpbackitup_queue_scheduled_jobs(){
		$scheduled_jobs_logname = 'debug_scheduled_jobs';
		WPBackItUp_Logger::log_info($scheduled_jobs_logname,__METHOD__,'Begin');

		// Check permissions
		if (! self::is_authorized()) exit('Access denied.');

		//Include Scheduler Class
		if( !class_exists( 'WPBackItUp_Scheduler' ) ) {
			include_once 'class-scheduler.php';
		}

		//Include Job class
		if( !class_exists( 'WPBackItUp_Job' ) ) {
			include_once 'class-job.php';
		}

		//Include Job class
		if( !class_exists( 'WPBackItUp_Backup' ) ) {
			include_once 'class-backup.php';
		}

		//If any restore jobs are queued or active then just exit

		//RESTORE
        $restore_job = WPBackItUp_Job::is_job_queued_active(WPBackItUp_Job::RESTORE);
		if (false!==$restore_job){
			WPBackItUp_Logger::log_info($scheduled_jobs_logname,__METHOD__,'Restore Job Queued:'. $restore_job->getJobId());
			exit;
		}


        $scheduled_jobs=array();

        //Check Scheduler
        $scheduler = new WPBackItUp_Scheduler();
		$scheduler->check_queue_jobs_schedule(); //make sure schedule is set propery


		//BACKUP
        //If no backup queued or active & its time to run on then kick it off
        if (  ! WPBackItUp_Job::is_job_queued_active(WPBackItUp_Job::BACKUP) &&
              $scheduler->isJobScheduled(WPBackItUp_Job::BACKUP) ) {

	        $job_id=$this->generate_job_id();
			$job_name = self::create_backup_job_name($job_id);

            $backup_tasks= apply_filters( 'wp-backitup_post_backup_tasks', WPBackItUp_Job::$BACKUP_TASKS );
            $backup_job = WPBackItUp_Job::queue_job($job_name,$job_id, WPBackItUp_Job::BACKUP,WPBackItUp_Job::SCHEDULED, $backup_tasks);
	        if (false===$backup_job){
		        WPBackItUp_Logger::log_error($scheduled_jobs_logname,__METHOD__,'Scheduled backup could not be queued.');
	        }else {
                //run background processor
                $background_processor  = new WPBackItUp_Task_Processor();
                $background_processor->push_to_queue( $job_name);
                $background_processor->save()->dispatch();

                WPBackItUp_Logger::log_info($scheduled_jobs_logname,__METHOD__,'Backup scheduled to run.');
	        }

        }

        //if backup job is queued or active then exit
		$backup_job = WPBackItUp_Job::is_job_queued_active(WPBackItUp_Job::BACKUP);
		if (false!==$backup_job){
			WPBackItUp_Logger::log_info($scheduled_jobs_logname,__METHOD__,'Backup Job Queued:'. $backup_job->getJobId());
			exit;
		}

        //CLEANUP
        //Run Cleanup every hour
        if ( $scheduler->isJobScheduled(WPBackItUp_Job::CLEANUP) ) {

        	//queue job
	        $cleanup_job = WPBackItUp_Cleanup::queue_job();
	        if (false===$cleanup_job) {
		        WPBackItUp_Logger::log_error( $scheduled_jobs_logname, __METHOD__, 'Cleanup could not be queued.' );
	        } else {
		        //dispatch tasks
		        $cleanup_tasks = WPBackItUp_Job_Task::get_job_tasks($cleanup_job->getJobId(),WPBackItUp_Job_Task::QUEUED);
		        if (false===$cleanup_tasks) {
			        WPBackItUp_Logger::log_error( $scheduled_jobs_logname, __METHOD__, 'Cleanup tasks could not dispatched.' );
		        }else {
			        $processor = $this->get_async_task_processor(Processors::CLEANUP);
			        if( !is_null($processor) ){
				        foreach ( $cleanup_tasks as $task ) {
				        	//add tasks queue
					        $processor->push_to_queue( $task->getTaskId());
				        }
				        $processor->save()->dispatch();
			        }
		        }
	        }
        }

        //Are there any wpbackitup add ons that need to be kicked off?
        if(has_filter('wpbackitup_scheduled_jobs')) {
            $scheduled_jobs = apply_filters('wpbackitup_scheduled_jobs', $scheduled_jobs);
        }

        foreach ( $scheduled_jobs as $job_type =>$job_tasks ) {
	        $job_id=$this->generate_job_id();
            $job_name = sprintf('%s_%s',$job_type,$job_id);

            //queue Job
            $job = WPBackItUp_Job::queue_job($job_name,$job_id, $job_type, WPBackItUp_Job::SCHEDULED,$job_tasks);
	        if (false===$job){
		        WPBackItUp_Logger::log_error($scheduled_jobs_logname,__METHOD__,'Scheduled job could not be queued:'.$job_type );
	        } else {
                //run background processor
                $background_processor  = new WPBackItUp_Task_Processor();
                $background_processor->push_to_queue( $job_name);
                $background_processor->save()->dispatch();

                WPBackItUp_Logger::log_info($scheduled_jobs_logname,__METHOD__,sprintf('Job queued to run:%s',$job->getJobName()));
            }

        }

        WPBackItUp_Logger::log_info($scheduled_jobs_logname,__METHOD__,'End check scheduled jobs.');
		exit(0); //success nothing to schedule
	}


	/**
	 * Generate job id
	 * @return string
	 *
	 */
	private  function generate_job_id(): string {
		$job_id_rnd = mt_rand(1000, 9999);
		if (PHP_INT_SIZE === 4) { // 32-bit
			$job_id_rnd = mt_rand(10, 99);
		}
		return current_time('timestamp') . $job_id_rnd;
	}
	/**
	 *  Queue backup job -  manual
	 *
	 */
	public  function ajax_queue_manual_backup() {
		// Check permissions
		if (! self::is_authorized()) exit('Access denied.');

		$events_logname='debug_events';
		WPBackItUp_Logger::log_info($events_logname,__METHOD__,'Begin');

		//Include Job class
		if( !class_exists( 'WPBackItUp_Backup' ) ) {
			include_once 'class-backup.php';
		}

		$rtnData = new stdClass();
		//If no jobs queued or active then queue one -  dont want to run backup until others are done
        $jobs = WPBackItUp_Job::get_jobs_by_status(WPBackItUp_Job::BACKUP,array(WPBackItUp_Job::ACTIVE,WPBackItUp_Job::QUEUED));
        if (false===$jobs){
			$job_id=$this->generate_job_id();
			//error_log('JobId:' .$job_id);

            $job_name = self::create_backup_job_name($job_id);

            $backup_tasks= apply_filters( 'wp-backitup_post_backup_tasks', WPBackItUp_Job::$BACKUP_TASKS );
			if (WPBackItUp_Job::queue_job($job_name,$job_id, WPBackItUp_Job::BACKUP,WPBackItUp_Job::MANUAL, $backup_tasks)){
                $rtnData->job_id = $job_id;
                $rtnData->message = __('Backup Queued', 'wp-backitup');

                //run background processor
                $background_processor  = new WPBackItUp_Task_Processor();
                $background_processor->push_to_queue( $job_name);
                $background_processor->save()->dispatch();

			}else {
				//UI need to show this message
				$rtnData->message = __('Backup could not be queued', 'wp-backitup');
			}
		}else{
		    //Scheduled job is running already so convert to manual - background processor not needed
            $current_job = current($jobs);
            //set the job type to manual so the job tasks will no longer be scheduled to run
            if (WPBackItUp_Job::SCHEDULED==$current_job->getJobRunType()){
                //wp_clear_scheduled_hook( 'wpbackitup_run_backup_tasks', array($current_job->getJobId()));
                $current_job->setJobRunType(WPBackItUp_Job::MANUAL);
            }

            $rtnData->job_id = $current_job->getJobId();
            $rtnData->message = __('Job is already in queue.', 'wp-backitup');

            //run background processor
            $background_processor  = new WPBackItUp_Task_Processor();
            $background_processor->push_to_queue( $current_job->getJobName());
            $background_processor->save()->dispatch();

		}

		WPBackItUp_Logger::log_info($events_logname,__METHOD__,'RtnData:' .$rtnData->message);
		WPBackItUp_Logger::log_info($events_logname,__METHOD__,'End');
		echo json_encode($rtnData);
		exit;
	}

	/**
	 *  Queue restore job - manual
	 *
	 */
	public  function ajax_queue_restore() {
        $rtnData = new stdClass();

        // Check permissions
        if (! self::is_authorized()) exit('Access denied.');

	    $events_logname='debug_events';
	    WPBackItUp_Logger::log_info($events_logname,__METHOD__,'Begin');

        //Include Job class
	    //Include Job class
	    if( !class_exists( 'WPBackItUp_Backup' ) ) {
		    include_once 'class-backup.php';
	    }

        $validation_error=false;
        //Get posted values
        $backup_file_name = $_POST['selected_file'];//Get the backup file name
        if( empty($backup_file_name)) {
            $rtnData->message = __('No backup file selected.', 'wp-backitup');
            $validation_error=true;
        }

        //Get user ID - GET ThIS FROM POST ID
        $user_id = $_POST['user_id'];
        if( empty($user_id)) {
            $rtnData->message = __('No user id found.', 'wp-backitup');
            $validation_error=true;
        }

        //If no job queued already then queue one
        if (! $validation_error) {

	        //Cancel other jobs if already running
	        if (WPBackItUp_Job::is_any_job_queued_active()) {
		        WPBackItUp_Job::cancel_all_jobs( WPBackItUp_Job::BACKUP );
		        WPBackItUp_Job::cancel_all_jobs( WPBackItUp_Job::RESTORE );
                WPBackItUp_Job::cancel_all_jobs( WPBackItUp_Job::CLEANUP );
	        }

	        //Check to see if restore queued
            $jobs = WPBackItUp_Job::get_jobs_by_status(WPBackItUp_Job::RESTORE,array(WPBackItUp_Job::ACTIVE,WPBackItUp_Job::QUEUED));
            if (false===$jobs){
		        $job_id   = current_time('timestamp');
		        $job_name = sprintf( 'Restore_%s', $job_id );
		        $job      = WPBackItUp_Job::queue_job( $job_name, $job_id, WPBackItUp_Job::RESTORE, WPBackItUp_Job::MANUAL,WPBackItUp_Job::$RESTORE_TASKS );
		        if ( false !== $job ) {
			        $job->setJobMetaValue( 'backup_name', $backup_file_name );
			        $job->setJobMetaValue( 'user_id', $user_id );

			        //  ** Restore Flags - DEVELOPMENT/TEST ONLY **
			        $job->setJobMetaValue( 'restore_import_snapshot', true );
			        $job->setJobMetaValue( 'restore_update_snapshot', true );
			        $job->setJobMetaValue( 'restore_database', true );

			        $job->setJobMetaValue( 'restore_plugins', true );
			        $job->setJobMetaValue( 'restore_themes', true );
			        $job->setJobMetaValue( 'restore_uploads', true );
			        $job->setJobMetaValue( 'restore_others', true );

			        $job->setJobMetaValue( 'restore_activate_plugins', true );
			        $job->setJobMetaValue( 'restore_update_permalinks', true );
			        //  **End Restore Flags **

			        $rtnData->message = __( 'Restore Queued', 'wp-backitup' );
                    $rtnData->job_id =$job_id;

                    //run background processor
                    $background_processor  = new WPBackItUp_Task_Processor();
                    $background_processor->push_to_queue( $job_name);
                    $background_processor->save()->dispatch();

		        } else {
			        $rtnData->message = __( 'Restore could not be queued', 'wp-backitup' );
                    $rtnData->job_id =0;
		        }

	        }else{
                $current_job = current($jobs);
		        $rtnData->message = __('Restore already in queue', 'wp-backitup');

                //run background processor
                $background_processor  = new WPBackItUp_Task_Processor();
                $background_processor->push_to_queue( $current_job->getJobName());
                $background_processor->save()->dispatch();
	        }
        }

	    WPBackItUp_Logger::log_info($events_logname,__METHOD__,'RtnData:' .$rtnData->message);
	    WPBackItUp_Logger::log_info($events_logname,__METHOD__,'End');
        echo json_encode($rtnData);
        exit;
    }

	//Run queue cancel backup backup
	public  function ajax_queue_cancel_backup() {

		// Check permissions
		if (! self::is_authorized()) exit('Access denied.');

		$process_id = uniqid();
		$job_type=WPBackItUp_Job::BACKUP;

		$events_logname=sprintf('debug_%s_tasks',$job_type); //Set Log name
		WPBackItUp_Logger::log_info($events_logname,$process_id, sprintf('(%s) Begin Cancel Backup',$job_type));

        $response = new stdClass();
        $response->success=false; //default to error

        $job_id = $_POST['job_id'];
        if( empty($job_id)) {
            $response->message = __('No job id posted', 'wp-backitup');
        } else {
            $job = WPBackItUp_Job::get_job_by_id($job_id);
            if (false!==$job) {
                WPBackItUp_Logger::log_info($events_logname,$process_id, sprintf('(%s) Job found:',var_export($job,true)));
                $job->setStatus(WPBackItUp_Job::CANCELLED);

                $response->success=true;
                $response->message = __('Backup Cancelled', 'wp-backitup');
            } else{
                $response->message=__('Backup job not found', 'wp-backitup');
                WPBackItUp_Logger::log_info($events_logname,$process_id, sprintf('(%s) No jobs found.',$job_type));
            }
        }

        echo(json_encode($response));
        exit;
	}


    /**
     * Run backup tasks - from no_priv ajax call
     * - This function will run tasks but not returna  response
     *
     */
    public  function ajax_run_task() {
        @session_write_close();

	    $events_logname='debug_events';
	    WPBackItUp_Logger::log_info($events_logname,__METHOD__, 'Ajax Run task Fired.');


	    $job_id = $_REQUEST['job_id'];
        if (!empty ($job_id)){
            $this->run_tasks($job_id);
        }else{
            exit('Access denied.');
        }
    }

    /**
     * Fetch backup status and return to UI
     *
     */
    public  function ajax_get_backup_status() {
        @session_write_close();
//		error_log('backup-status');
//	    error_log(print_r($_POST, true));

        // Check permissions
        if (! self::is_authorized()) exit('Access denied.');

        $events_logname='debug_events';
        WPBackItUp_Logger::log_info($events_logname,__METHOD__, 'User Permissions: ' .current_user_can( 'manage_options' ));

        //Check permissions
        if ( current_user_can( 'manage_options' ) ) {

            $job_id = $_POST['job_id'];
            if (!empty ($job_id)){
                //Could fetch status from DB at this point
                //return status
	            $log_file_name='backup_status_' .$job_id. '.log';
                $log = WPBACKITUP__PLUGIN_PATH .'logs/'.$log_file_name;
                //error_log($log );
                if(file_exists($log) ) {
                    //Probably should use the database instead now.
                    readfile($log);
                }

	            $this->run_tasks($job_id); //backup task runner for wp-remote get issue
            }
        }
    }

    /**
     * Run restore status and return to UI
     *
     */
    public  function ajax_get_restore_status() {
	    //@session_start();
	    @session_write_close();

        // Check permissions
        if (! self::is_authorized()) exit('Access denied.');

	    $events_logname='debug_events';
	    WPBackItUp_Logger::log_info($events_logname,__METHOD__, 'User Permissions: ' .current_user_can( 'manage_options' ));

        //Check permissions
        if ( current_user_can( 'manage_options' ) ) {

            $job_id = $_POST['job_id'];
            if (!empty ($job_id)) {
	            $log_file_name='restore_status_' .$job_id. '.log';
                $log = WPBACKITUP__PLUGIN_PATH . 'logs/'.$log_file_name;
                if ( file_exists( $log ) ) {
                    //Probably should use the database instead now.
                    readfile( $log );
                }

	            $this->run_tasks($job_id); //restore task runner for wp-remote get issue
            }
        }

    }

    /*
    * Get single backup zip file list using job id
    */
    public function ajax_get_backup_zip_filelist(){
        if( !class_exists( 'WPBackItUp_FileSystem' ) ) {
            include_once 'class-filesystem.php';
        }

        $job_id = $_POST['job_id'];
        if (! empty ($job_id)){
            $current_job = WPBackItUp_Job::get_job_by_id($job_id);
            $zip_files =  $current_job->getJobMetaValue('backup_zip_files');
            $backup_list = array();
            foreach ($zip_files as $key => $value) {
                array_push($backup_list,
                    array(
                        'name' => $key,
                        'size' => WPBackItUp_FileSystem::format_file_size_kb($value),
                        'file_exists'=>file_exists( $key )
                    )
                );
            }

            wp_send_json_success($backup_list);
        }else{
            wp_send_json_error('Invalid Job Id');
        }
    }

    /*
    * Get a backup note
    */
    public function ajax_backup_get_note() {
        $job = WPBackItUp_Job::get_job_by_id($_POST['job_id']);
        $note = $job->getJobMetaValue('note');
        if ($note){
            wp_send_json_success($note);
        } else {
            wp_send_json_error('');
        }
    }

    /*
    * Add note to backup
    */
    public function ajax_backup_add_note() {
        $job = WPBackItUp_Job::get_job_by_id($_POST['job_id']);
        $meta = $job->setJobMetaValue('note', $_POST['note']);

        if( !class_exists( 'WPBackItUp_FileSystem' ) ) {
            include_once 'class-filesystem.php';
        }
        $zipfiles = $job->getJobMetaValue('backup_zip_files');
        $zip = new ZipArchive;
        $res = $zip->open(key($zipfiles));
        if ($res === TRUE) {
            $zip->setArchiveComment($_POST['note']);
            $zip->close();
        } else {
            wp_send_json_error('Invalid Zip File');
        }
        if ($meta){
            wp_send_json_success($meta);
        } else {
            wp_send_json_error('Invalid Job Id');
        }
    }

    /*
    * Notification widget : delete
    */
    public function ajax_queue_delete_transient(){
        $admin_notices = get_transient( 'wpbackitup_admin_notices' );
        if( !(false === $admin_notices) ){
             array_shift($admin_notices);
             delete_transient( 'wpbackitup_admin_notices' );
             set_transient( 'wpbackitup_admin_notices', $admin_notices , DAY_IN_SECONDS);
            $admin_next_message = reset($admin_notices);
            wp_send_json($admin_next_message);
        }else{
            wp_send_json(false);
        }
    }

    public function plupload_action() {
        // Check permissions
        if (! self::is_authorized()) exit('Access denied.');

        include_once( WPBACKITUP__PLUGIN_PATH.'/lib/includes/class-filesystem.php' );
        include_once( WPBACKITUP__PLUGIN_PATH.'/lib/includes/handler_upload.php' );
    }

    public function upload_dir($uploads) {
        $upload_path = WPBACKITUP__UPLOAD_PATH;
        if (is_writable($upload_path)) $uploads['path'] = $upload_path;
        return $uploads;
    }

    public function unique_filename_callback($dir, $name, $ext) {
        return $name.$ext;
    }


    public function sanitize_file_name($filename) {
        return $filename;
    }


    public  function ajax_backup_response_reader() {
	    // Check permissions
	    if (! self::is_authorized()) exit('Access denied.');

	    $job_id = $_POST['job_id'];
		$log_file_name = 'backup_response_' .$job_id. '.log';
        $log = WPBACKITUP__PLUGIN_PATH .'logs/'. $log_file_name;
		//error_log('backupresponse' .$log);

        if(file_exists($log) ) {
            readfile($log);
        }else{
            $rtnData = new stdClass();
            $rtnData->message = __('No response log found.', 'wp-backitup');
            echo json_encode($rtnData);
        }
        exit;
    }

    public  function ajax_delete_backup()
    {
	    // Check permissions
	    if (! self::is_authorized()) exit('Access denied.');

	    $delete_logname='debug_delete';

        //$backup_folder_name = str_replace('deleteRow', '', $_POST['filed']);
        $job_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

        $job = WPBackItUp_Job::get_job_by_id($job_id);
        if (false!==$job){

        	//Cleanup will remove folders of orphaned even if folder not prefixed.
	        $job->setStatus(WPBackItUp_Job::DELETED);
	        $backup_folder_name = $job->getBackupFolderPath();
	        if (false!=$backup_folder_name){
		        $backup_folder_name = basename($backup_folder_name);
	        }

            $backup_folder_path =  WPBACKITUP__BACKUP_PATH .'/' . $backup_folder_name;
            $DLT_backup_folder_path = WPBACKITUP__BACKUP_PATH .'/DLT_' . $backup_folder_name .'_' . current_time( 'timestamp' );

            WPBackItUp_Logger::log_info($delete_logname,__METHOD__, 'From:'.$backup_folder_path );
            WPBackItUp_Logger::log_info($delete_logname,__METHOD__, 'To:'.$DLT_backup_folder_path );

            //Mark the folder deleted so cleanup will handle
            if (file_exists ($backup_folder_path)) {

                if( !class_exists( 'WPBackItUp_FileSystem' ) ) {
                    include_once 'class-filesystem.php';
                }

                $file_system = new WPBackItUp_FileSystem($delete_logname);
                if (! $file_system->rename_file($backup_folder_path,$DLT_backup_folder_path)){
                    WPBackItUp_Logger::log_error($delete_logname,__METHOD__, 'Folder was not renamed');
                    exit('Backup NOT deleted');
                }
            }else{
                WPBackItUp_Logger::log_error($delete_logname,__METHOD__, 'Folder not found:'. $backup_folder_path);
            }
        } else{
            WPBackItUp_Logger::log_error($delete_logname,__METHOD__, 'Job not found:'. $job_id);
        }

        exit('deleted successfully');
    }

	public  function ajax_safe_upload()
	{
		// Check permissions
		if (! self::is_authorized()) exit('Access denied.');

		$delete_logname='debug_upload';

		$job_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
		$job = WPBackItUp_Job::get_job_by_id($job_id);
		if (false!==$job){

			do_action( 'wpbackitup-premium_queue_job_sync', $job_id );
			//job meta will be set in sync task

			exit('success');

		} else{
			WPBackItUp_Logger::log_error($delete_logname,__METHOD__, 'Job not found:'. $job_id);
			$job->setCloudStatus(WPBackItUp_Job::CLOUD_ERROR);

			exit('error: Job not found.');
		}
	}

    function admin_viewlog(){
	    if (! self::is_authorized()) exit('Access denied.');

        include_once( WPBACKITUP__PLUGIN_PATH.'/lib/includes/handler_viewlog.php' );
    }

	function admin_download_backup(){
		if (! self::is_authorized()) exit('Access denied.');

		include_once( WPBACKITUP__PLUGIN_PATH.'/lib/includes/handler_download.php' );
	}

    /**
     * Process update page form submissions and validate license key
     *
     */
//    public  function _admin_options_update() {
//        // Verify submission for processing using wp_nonce
//        if( wp_verify_nonce( $_REQUEST['_wpnonce'], "{$this->namespace}-update-options" ) ) {
//
//            /**
//             * Loop through each POSTed value and sanitize it to protect against malicious code. Please
//             * note that rich text (or full HTML fields) should not be processed by this function and
//             * dealt with directly.
//             */
//
//	        $debug_logname='wpb_debug';
//	        WPBackItUp_Logger::log_info($debug_logname,__METHOD__, 'Posted Fields');
//	        WPBackItUp_Logger::log($debug_logname, $_POST['data']); //License will not be in this array
//
//	        $wpbackitup_license = new WPBackItUp_License();
//            foreach( $_POST['data'] as $key => $val ) {
//            	error_log('here'. $val);
//
//                $posted_value = $this->_sanitize($val);
//                //If license updated then validate
//                if (!empty($key) && $key=='license_key') {
//	                WPBackItUp_Logger::log_info($debug_logname,__METHOD__, 'License Posted:' .$posted_value);
//	                $wpbackitup_license->update_license_options($posted_value);
//                }
//                else {
//                    $data[$key] =$posted_value;
//                }
//            }
//
//            //Could have just been a license update
//            if(!empty($data)) {
//
//                // Update the options value with the data submitted
//                foreach( $data as $key => $val ) {
//                    $this->set_option($key, $val);
//	                WPBackItUp_Logger::log_info($debug_logname,__METHOD__, 'Updated Option: ' .$key .':' .$val);
//                }
//            }
//
//            // Redirect back to the options page with the message flag to show the saved message
//            wp_safe_redirect( $_REQUEST['_wp_http_referer'] . '&update=1' );
//            exit;
//        }
//    }


    /***
     * Get all settings value
     */
    public function ajax_backup_get_settings(){

    	$ut = new WPBackItUp_Usage();
    	$tracking_allowed = $ut->is_tracking_allowed();

        $settings = array(
            'logging' => $this->get_option('logging'),
            'notification_email' => $this->get_option('notification_email'),
            'backup_retained_number' => $this->get_option('backup_retained_number'),
            'delete_all' => $this->get_option('delete_all'),
            'safe_sync' => $this->get_option('safe_sync'),
            'beta_updates' => $this->get_option('beta_updates'),
            'rversion_compare' => $this->get_option('rversion_compare'),
            'backup_dbtables_batch_size'=> $this->get_option('backup_dbtables_batch_size', WPBACKITUP__DATABASE_BATCH_SIZE),
            'backup_sql_merge_batch_size' => $this->get_option('backup_sql_merge_batch_size', WPBACKITUP__SQL_MERGE_BATCH_SIZE),
            'backup_sql_batch_size' => $this->get_option('backup_sql_batch_size', WPBACKITUP__SQL_BATCH_SIZE),
            'backup_plugins_batch_size'=> $this->get_option('backup_plugins_batch_size', WPBACKITUP__PLUGINS_BATCH_SIZE),
            'backup_themes_batch_size'=> $this->get_option('backup_themes_batch_size', WPBACKITUP__THEMES_BATCH_SIZE),
            'backup_uploads_batch_size'=> $this->get_option('backup_uploads_batch_size', WPBACKITUP__UPLOADS_BATCH_SIZE),
            'backup_others_batch_size'=> $this->get_option('backup_others_batch_size', WPBACKITUP__OTHERS_BATCH_SIZE),
            'backup_zip_max_size'=> $this->get_option('backup_zip_max_size', WPBACKITUP__ZIP_MAX_FILE_SIZE),
            'backup_max_timeout'=> $this->get_option('backup_max_timeout', WPBACKITUP__TASK_TIMEOUT_SECONDS),
            'backup_plugins_filter'=> $this->get_option('backup_plugins_filter'),
            'backup_themes_filter' => $this->get_option('backup_themes_filter'),
            'backup_uploads_filter' => $this->get_option('backup_uploads_filter'),
            'backup_others_filter' => $this->get_option('backup_others_filter'),
            'backup_dbtables_filter_list'=> $this->get_option('backup_dbtables_filter_list'),
            'backup_dbtables_filterable' => $this->backup_dbtables_filterable(),
            'support_email' => $this->get_option('support_email'),
            'single_file_backupset'=> $this->get_option('single_file_backupset'),
            'single_file_db'=> $this->get_option('single_file_db'),
            'remove_supporting_zip_files'=> $this->get_option('remove_supporting_zip_files'),
            'allow_usage_tracking'=> $tracking_allowed,
        );

        wp_send_json_success($settings);
    }

    /**
     * Set all settings
     */
    public function ajax_backup_set_settings(){
        check_ajax_referer( 'wpbackitup-core-ajax-nonce', 'security' );

        $debug_logname='wpb_debug';
        WPBackItUp_Logger::log_info($debug_logname,__METHOD__, 'Posted Fields');
        WPBackItUp_Logger::log($debug_logname, $_POST['data']);

        $data = array();
        $errors = array();
        /**
         * Loop through each POSTed value and sanitize it to protect against malicious code. Please
         * note that rich text (or full HTML fields) should not be processed by this function and
         * dealt with directly.
         */
        foreach( $_POST['data'] as $key => $val ) {
            $posted_value = $this->_sanitize($val);
            $data[$key] =$posted_value;
        }

        //** VALIDATE notification_email **//
        if(!empty($data['notification_email'])){
            $notification_emails = explode(",",$data['notification_email']);
            $is_all_email_valid = true;
            foreach ($notification_emails as $email) {
                $is_all_email_valid = $is_all_email_valid && is_email($email);
            }

            if(!$is_all_email_valid){
                $data['notification_email'] = $this->defaults['notification_email'];
                $errors['notification_email'] = __('Please enter valid email', $this->namespace);
                wp_send_json_error($errors);
            }
        }


        //** VALIDATE backup_retained_number **//
        if(!empty($data['backup_retained_number']) && !is_numeric($data['backup_retained_number'])) {
            $data['backup_retained_number'] = $this->defaults['backup_retained_number'];
            $errors['backup_retained_number'] = __('Please enter a number', $this->namespace);
            wp_send_json_error($errors);
        } else{ //Empty OR not NUMERIC
            if ( empty($data['backup_retained_number']) ){
                $data['backup_retained_number'] = $this->defaults['backup_retained_number'];
                $errors['backup_retained_number'] = __('Please enter a number', $this->namespace);
                wp_send_json_error($errors);
            }
        }


        //** VALIDATE backup_plugins_filter **//
        if(empty($data['backup_plugins_filter'])) {
            $data['backup_plugins_filter'] = $this->defaults['backup_plugins_filter'];
        }

        //** VALIDATE backup_themes_filter **//
        if(empty($data['backup_themes_filter'])){
            $data['backup_themes_filter'] = $this->defaults['backup_themes_filter'];
        }

        //** VALIDATE backup_uploads_filter **//
        if(empty($data['backup_uploads_filter'])){
            $data['backup_uploads_filter'] = $this->defaults['backup_uploads_filter'];
        }

        //** VALIDATE backup_others_filter **//
        if(empty($data['backup_others_filter'])){
            $data['backup_others_filter'] = $this->defaults['backup_others_filter'];
        }

        //** VALIDATE db_table_filter **//
        if(empty($data['backup_dbtables_filter_list'])){
            $data['backup_dbtables_filter_list'] = $this->defaults['backup_dbtables_filter_list'];
        }

        //** VALIDATE backup_dbtables_batch_size **//
        if(empty($data['backup_dbtables_batch_size']) || !is_numeric($data['backup_dbtables_batch_size']))
        {
            $data['backup_dbtables_batch_size'] = $this->defaults['backup_dbtables_batch_size'];
            $errors['backup_dbtables_batch_size'] = __('Please enter a number', $this->namespace);
            wp_send_json_error($errors);
        }

        //** Validate backup_sql_merge_batch_size *//
        if(empty($data['backup_sql_merge_batch_size']) || !is_numeric($data['backup_sql_merge_batch_size']))
        {
            $data['backup_sql_merge_batch_size'] = $this->defaults['backup_sql_merge_batch_size'];
            $errors['backup_sql_merge_batch_size'] = __('Please enter a number', $this->namespace);
            wp_send_json_error($errors);
        }

        //** Validate backup_sql_batch_size *//
        if(empty($data['backup_sql_batch_size']) || !is_numeric($data['backup_sql_batch_size']))
        {
            $data['backup_sql_batch_size'] = $this->defaults['backup_sql_batch_size'];
            $errors['backup_sql_batch_size'] = __('Please enter a number', $this->namespace);
            wp_send_json_error($errors);
        }

        //** VALIDATE backup_plugins_batch_size **//
        if(empty($data['backup_plugins_batch_size']) || !is_numeric($data['backup_plugins_batch_size']))
        {
            $data['backup_plugins_batch_size'] = $this->defaults['backup_plugins_batch_size'];
            $errors['backup_plugins_batch_size'] = __('Please enter a number', $this->namespace);
            wp_send_json_error($errors);
        }

        //** VALIDATE backup_themes_batch_size **//
        if(empty($data['backup_themes_batch_size']) || !is_numeric($data['backup_themes_batch_size']))
        {
            $data['backup_themes_batch_size'] = $this->defaults['backup_themes_batch_size'];
            $errors['backup_themes_batch_size'] = __('Please enter a number', $this->namespace);
            wp_send_json_error($errors);
        }

        //** VALIDATE backup_uploads_batch_size **//
        if(empty($data['backup_uploads_batch_size']) || !is_numeric($data['backup_uploads_batch_size']))
        {
            $data['backup_uploads_batch_size'] = $this->defaults['backup_uploads_batch_size'];
            $errors['backup_uploads_batch_size'] = __('Please enter a number', $this->namespace);
            wp_send_json_error($errors);
        }

        //** VALIDATE backup_others_batch_size **//
        if(empty($data['backup_others_batch_size']) || !is_numeric($data['backup_others_batch_size']))
        {
            $data['backup_others_batch_size'] = $this->defaults['backup_others_batch_size'];
            $errors['backup_others_batch_size'] = __('Please enter a number', $this->namespace);
            wp_send_json_error($errors);
        }

        //** VALIDATE backup_dbtables_filter_list **//
        if(empty($data['backup_dbtables_filter_list']))
        {
            $data['backup_dbtables_filter_list'] = $this->defaults['backup_dbtables_filter_list'];
        }

        //** VALIDATE zip max size **//
        if(empty($data['backup_zip_max_size']))
        {
            $data['backup_zip_max_size'] = $this->defaults['backup_zip_max_size'];
        }

        //** VALIDATE max timeout **//
        if(empty($data['backup_max_timeout']))
        {
            $data['backup_max_timeout'] = $this->defaults['backup_max_timeout'];
        }

        //** VALIDATE logging setting **//
        $data['logging'] = $data['logging'] === 'true'? 1: 0;

        //** VALIDATE single file db **//
        $data['single_file_db'] = $data['single_file_db'] === 'true' ? 1: 0;

        //** VALIDATE single file backupset **//
        $data['single_file_backupset'] = $data['single_file_backupset'] === 'true' ? 1: 0;

        //** VALIDATE remove_supporting_zip_files **//
        $data['remove_supporting_zip_files'] = $data['remove_supporting_zip_files'] === 'true' ? 1: 0;

        //** VALIDATE rversion_compare **//
        $data['rversion_compare'] = $data['rversion_compare'] === 'true' ? 1: 0;

        //** VALIDATE delete_all  on uninstall **//
        $data['delete_all'] = $data['delete_all'] === 'true' ? 1: 0;

        //** Safe Sync ON/OFF **//
        $data['safe_sync'] = $data['safe_sync'] === 'true' ? 1: 0;

	    //** Beta Updates ON/OFF **//
	    $data['beta_updates'] = $data['beta_updates'] === 'true' ? 1: 0;

	    //** Usage Tracking ON/OFF **//
	    $ut = new WPBackItUp_Usage();
	    $ut->set_tracking_allowed($data['allow_usage_tracking'] === 'true' ? true: false);
	    unset($data['allow_usage_tracking']);//pop off array

        // Update the options value with the data submitted
        foreach( $data as $key => $val ) {
            $this->set_option($key, $val);
            WPBackItUp_Logger::log_info($debug_logname,__METHOD__, 'Updated Option: ' .$key .':' .$val);
        }

        wp_send_json_success();

    }

	/**
	 * Send support request Schedule
	 *
	 */
	public  function _admin_send_support_request() {
        global $wpdb;
		// Verify submission for processing using wp_nonce

		$url= str_replace('&s=1','',$_REQUEST['_wp_http_referer']);
		$support_logname='debug_support';
		WPBackItUp_Logger::log_sysinfo($support_logname);
		WPBackItUp_Logger::log_info($support_logname,__METHOD__, 'Send Support Request');

		$error=false;
		if( wp_verify_nonce( $_REQUEST['_wpnonce'], "{$this->namespace}-support-form" ) ) {

			WPBackItUp_Logger::log_info($support_logname,__METHOD__, 'Send support request');
			WPBackItUp_Logger::log($support_logname, $_POST);

			//save the email in place of transient
			$this->set_support_email($_POST['support_email']);

			// save the transients in case of error
			foreach( $_POST as $key => $val ){
				set_transient($key, __($val, $this->namespace), 60);
			}

			//validate form fields
			if(empty($_POST['support_email']) || !is_email($_POST['support_email']))
			{
				$error=true;
				set_transient('error-support-email', __('Please enter a valid email', $this->namespace), 60);
			}


            if(empty($_POST['support_ticket_id']))
            {
                $error=true;
                set_transient('error-support-ticket', __('Please enter your support ticket id', $this->namespace), 60);
            }else {
                if(!is_numeric($_POST['support_ticket_id']))
                {
                    $error=true;
                    set_transient('error-support-ticket', __('Please only enter numbers in this field', $this->namespace), 60);
                }
            }

//			if(empty($_POST['support_subject']))
//			{
//				$error=true;
//				set_transient('error-support-subject', __('Please enter a short description of your problem', $this->namespace), 60);
//			}

//			if(empty($_POST['support_body']))
//			{
//				$error=true;
//				set_transient('error-support-body', __('Please enter your problem description', $this->namespace), 60);
//			}

			$include_logs=true; //always send logs
//			if(!empty($_POST['support_include_logs']))
//			{
//				$include_logs=true;
//			}

			//Send if no errors
			if (!$error){

				if( !class_exists( 'WPBackItUp_Zip' ) ) {
					include_once 'class-zip.php';
				}

				if( !class_exists( 'WPBackItUp_Utility' ) ) {
					include_once 'class-utility.php';
				}

				$premium_installed=false;
                if( class_exists('WPBackitup_Premium') ) {
	                $premium_installed=true;
                }

				$support_request_id=current_time('timestamp');
				$logs_attachment = array(); //default to no logs
				if ($include_logs){
					$logs_path = WPBACKITUP__PLUGIN_PATH .'logs';

					//copy/replace WP debug file
					$wpdebug_file_path = WPBACKITUP__CONTENT_PATH . '/debug.log';
					WPBackItUp_Logger::log_info($support_logname,__METHOD__, 'Copy WP Debug: ' .$wpdebug_file_path);
					if (file_exists($wpdebug_file_path)) {
						copy( $wpdebug_file_path, $logs_path .'/wpdebug.log' );
					}


					$zip_file_path = $logs_path . '/logs_' . $support_request_id . '.zip';
					$zip = new WPBackItUp_Zip($support_logname,$zip_file_path);
					$zip->zip_log_files_in_folder($logs_path,$support_request_id);
					$zip->close();

					$logs_attachment = array( $zip_file_path  );

				}

				//Get registration name
				$utility = new WPBackItUp_Utility($support_logname);
                $support_to_address = WPBACKITUP__SUPPORT_EMAIL;

                $wpbackitup_license = new WPBackItUp_License();
				$from_name=$wpbackitup_license->get_customer_name();
                $support_from_email=$_POST['support_email'];
                $support_subject = '[#' .trim($_POST['support_ticket_id']) .']';

                $site_info = 'WordPress Site: <a href="'  . home_url() . '" target="_blank">' . home_url() .'</a><br/>';
				$site_info .="WordPress Version: " . get_bloginfo( 'version') .' <br />';
                $site_info .="PHP Version: " . phpversion() .' <br />';
                $site_info .="MySQL Version: " . $wpdb->db_version() .' <br />';
				$site_info .="WPBackItUp License Type: " . $wpbackitup_license->get_license_type_description() .' <br />';
				$site_info .="WPBackItUp Version: " . $this->version .' <br />';

				if ($premium_installed) {
					$site_info .="Premium Installed: yes  <br />";
	                if(defined('WPBACKITUP_PREMIUM__VERSION')){
	                    $site_info .= "WPBackItUp Premium Version: " . WPBACKITUP_PREMIUM__VERSION . '<br />';
	                }
				}

                $support_body=$site_info . '<br/><br/><b>Customer Comments:</b><br/><br/>' . $_POST['support_body'];

                if ($utility->send_email_v2($support_to_address,$support_subject,$support_body,$logs_attachment,$from_name,$support_from_email,$support_from_email)){
	                // get rid of the transients
					foreach( $_POST as $key => $val ){
						delete_transient($key);
					}

					//send safe logs
					do_action('wpbackitup-premium_upload_logs_files');

					wp_safe_redirect($url . '&s=1');
                } else{

	                //send safe logs
	                do_action('wpbackitup-premium_upload_logs_files');

	                wp_safe_redirect($url . '&s=2');
                }

				exit;
			}
		}

		wp_safe_redirect($url);
		exit;

	}

    /**
     * Process registration page form submissions
     *
     */
    public  function _admin_register() {

        $registration_logname='debug_registration';
        WPBackItUp_Logger::log_info($registration_logname,__METHOD__, 'Register WPBackItUp');
        WPBackItUp_Logger::log($registration_logname,var_export($_POST,true));

        //First lets check the license
        $license_key = $_POST['license_key'];
        $license_key = $this->_sanitize($license_key);

        $product_id = $_POST['product_id'];
        $product_id = $this->_sanitize($product_id);

        $wpbackitup_license = new WPBackItUp_License();

        //activate the license if entered
        WPBackItUp_Logger::log_info($registration_logname,__METHOD__, 'Activate License');
        $wpbackitup_license->update_license_options($license_key,$product_id);

        //CE users only
        if ('-1' == $wpbackitup_license->get_license_type()) {

            WPBackItUp_Logger::log_info($registration_logname,__METHOD__, 'Register WPBackItUp LITE');

            $val           = $_POST['license_email'];
            $license_email = $this->_sanitize( $val );
            if ( ! empty( $license_email ) && filter_var( $license_email, FILTER_VALIDATE_EMAIL ) ) {
                $urlparts = parse_url( site_url() );
                $domain   = $urlparts['host'];

                $license_name = $_POST['license_name'];

                //save options to DB
                $this->set_option( 'license_customer_email', $license_email );
                if ( ! empty( $license_name ) ) {
                    $this->set_option( 'license_customer_name', $license_name );
                }

                $form_data = array(
                    'email'     => $license_email,
                    'site'      => $domain,
                    'name'      => $license_name,
                    'time_zone' => get_option( 'timezone_string' ),
                );


                $registration_response = $wpbackitup_license->register_ce($form_data);
                if ( false===$registration_response ) {
                    WPBackItUp_Logger::log_error($registration_logname,__METHOD__, 'Unable to register using SSL - attempting NONSSL' );
                    //try without SSL
                    if ( false===$wpbackitup_license->register_ce($form_data,false) ) {
                        WPBackItUp_Logger::log_error($registration_logname,__METHOD__, 'Unable to register using SSL' );
                    }
                }
            }
        }

        // Redirect back to the options page with the message flag to show the saved message
        wp_safe_redirect( $_REQUEST['_wp_http_referer'] . '&update=1' );
        exit;

    }


	/**
	 * Hook into plugin_action_links filter
	 *
	 * @param object $links An array of the links to show, this will be the modified variable
	 * @param string $file  The name of the file being processed in the filter
	 *
	 * @return object
	 */
    public  function plugin_action_links( $links, $file ) {

        // Add links to plugin
        if ( $file == plugin_basename( WPBACKITUP__PLUGIN_PATH . '/wp-backitup.php' ) ) {
            $settings_link = '<a href="' . esc_url( self::get_settings_page_url() ) . '">'.esc_html__( 'Settings' , 'wp-backitup').'</a>';
            array_unshift($links, $settings_link);
        }

        return $links;
    }

    /**
     * @deprecated From version 1.14.8
     */
    public function notification_email(){
      return $this->get('notification_email');
    }

    public function cleanup_lastrun_date(){
        return $this->get('cleanup_lastrun_date');
    }

    public function backup_dbtables_batch_size(){
        return $this->get('backup_dbtables_batch_size');
    }

    public function backup_sql_merge_batch_size(){
        return $this->get('backup_sql_merge_batch_size');
    }

    public function backup_sql_batch_size(){
        return $this->get('backup_sql_batch_size');
    }

	public function backup_plugins_batch_size(){
		return $this->get('backup_plugins_batch_size');
	}
    public function backup_themes_batch_size(){
        return $this->get('backup_themes_batch_size');
    }
    public function backup_uploads_batch_size(){
        return $this->get('backup_uploads_batch_size');
    }
    public function backup_others_batch_size(){
        return $this->get('backup_others_batch_size');
    }

    public function backup_plugins_filter(){
        return $this->get('backup_plugins_filter');
    }
    public function backup_themes_filter(){
        return $this->get('backup_themes_filter');
    }
    public function backup_uploads_filter(){
        return $this->get('backup_uploads_filter');
    }
    public function backup_others_filter(){
        return $this->get('backup_others_filter');
    }

    public function backup_dbtables_filter_list(){
        return $this->get('backup_dbtables_filter_list');
    }


    /**
    * Getter - Database tables filterable list
    */
    public function backup_dbtables_filterable(){
        if( !class_exists( 'WPBackItUp_DataAccess' ) ) {
            include_once 'class-database.php';
        }

        $db = new WPBackItUp_DataAccess();
        $all_tables_name = $db->get_tables();

        //remove the wordpress tables
        $wp_tables_name = $db->get_wp_tables();
        $table_list = array_diff($all_tables_name,$wp_tables_name);

        //remove the job tables from the list
        $job_tables_name = WPBackItUp_DataAccess::get_jobs_tables();
        $table_list = array_diff($table_list,$job_tables_name);

        return $table_list;

    }


    /**
    * Getter - backup retained number - derived property
    */
    public function backup_retained_number(){
        if (empty($this->backup_retained_number)) {
            $this->backup_retained_number = $this->get_option('backup_retained_number');

            //If not set then use the defaults
            if (empty($this->backup_retained_number)) {
            	$wpbackitup_license = new WPBackItUp_License();

                switch ($wpbackitup_license->get_license_type()) {
	                case -1: //CE
		                $this->backup_retained_number=1;
		                break;
                	case 0: //Lite
                        $this->backup_retained_number=1;
                        break;
                    case 1: //Personal
                        $this->backup_retained_number=3;
                        break;

                    case 2: //Business
                        $this->backup_retained_number=3;
                        break;

                    case 3: //Pro
                        $this->backup_retained_number=3;
                        break;
                }

                $this->set_option('backup_retained_number',$this->backup_retained_number);
            }

        }

        return $this->backup_retained_number;

    }

    function backup_count(){
       return $this->get('backup_count');
    }

    function successful_backup_count(){
        return $this->get('successful_backup_count');
    }

    function restore_count(){
        return $this->get('restore_count');
    }

    function successful_restore_count(){
        return $this->get('successful_restore_count');
    }

    /**
     * @deprecated From version 1.14.8
     */
	public function support_email(){
		return $this->get('support_email');
	}

    //getter
    public function single_file_backupset(){
        return (bool) $this->get_option('single_file_backupset');
    }

	//getter
	public function is_remove_supporting_zip_files(){
		return (bool) $this->get_option('remove_supporting_zip_files');
	}

    //setter
    public function set_single_file_backupset($value){
	    if (true===$value) $value=1;
	    if (false===$value) $value=0;
	    if (1 !== $value && 0 !== $value)  $value=0; //default to off if invalid value

        $this->set('single_file_backupset', $value);
    }

	//setter
	public function set_remove_supporting_zip_files($value){
		if (true===$value) $value=1;
		if (false===$value) $value=0;
		if (1 !== $value && 0 !== $value)  $value=0; //default to off if invalid value

		$this->set('remove_supporting_zip_files', $value);
	}


	//getter
	public function single_file_db(){
		return (bool) $this->get_option('single_file_db');
	}

	//setter
	public function set_single_file_db($value){
		if (true===$value) $value=1;
		if (false===$value) $value=0;
		if (1 !== $value && 0 !== $value)  $value=0; //default to off if invalid value

		$this->set('single_file_db', $value);
	}

	//getter
	public function encrypt_files(){

		return $this->get_option('encrypt_files') == 1 ? true: false;
	}

	//setter
	public function set_encrypt_files($value){
		if (true===$value) $value=1;
		if (false===$value) $value=0;
		if (1 !== $value && 0 !== $value)  $value=0; //default to off if invalid value

		$this->set('encrypt_files', $value);
	}


	/**
	 * Formatted Version getter
	 *
	 * @return string
	 */
	public function formatted_version(){

		return rtrim ($this->version,'.0');
	}

	/**
	 * Getter - Max Zip Size
	 */
	public function max_zip_size(){
		return $this->get('backup_zip_max_size');
	}

	//setter
	public function set_max_zip_size($value){

		$this->set('backup_zip_max_size', $value);
	}

    /**
     * Getter - Max timeout
     */
    public function max_timeout(){
        return $this->get('backup_max_timeout');
    }

    //setter
    public function set_max_timeout($value){

        $this->set('backup_max_timeout', $value);
    }


    //getter
    public function rversion_compare(){
        return (bool) $this->get('rversion_compare');
    }

    //setter
    public function set_rversion_compare($value){
        $this->set('rversion_compare', $value);
    }

    public function get_backup_list(){

        // get retention number set
        $number_retained_archives = $this->backup_retained_number();

        //Make sure backup folder exists
        $backup_dir = WPBACKITUP__CONTENT_PATH . '/' . WPBACKITUP__BACKUP_FOLDER;

        //Create the backup list
        $folder_list = glob($backup_dir . "/*",GLOB_ONLYDIR);
        $backup_list=array();
        $i=0;
        if (is_array($folder_list) && count($folder_list)>0) {
            foreach($folder_list as $folder) {
                $backup_name = basename($folder);
                $backup_prefix = substr($backup_name,0,4);

                //Dont include incomplete backups or deleted folders
                if (    $backup_prefix!='TMP_' &&
                        $backup_prefix!='DLT_' ) {

                    $i++;

                    $logs = glob($folder . "/*.log");
                    $log_exists=false;
                    if (is_array($logs) && count($logs)>0){
                        $log_exists=true;
                    }

                    //Only get the files with the backup prefix.
                    $zip_files = glob($folder . "/" .$backup_name ."*.zip");

                    array_push($backup_list,
                        array(
                            "backup_name" => $backup_name,
                            "log_exists"=>$log_exists,
                            "date_time" => filectime($folder),
                            "zip_files"=>$zip_files,
                        ));

                }
            }

            return array_reverse($backup_list);
        }

        return false;
    }

    /**---------- END GETTERS --------------- **/

    /**---------- SETTERS --------------- **/

    function set_logging($value){
        if ($value || $value=='true')
            $this->set('logging', 'true');
        else
            $this->set('logging', 'false');
    }

    function set_backup_count($value){
        $this->set('backup_count', $value);
    }

    function set_successful_backup_count($value){
        $this->set('successful_backup_count', $value);
    }

    function set_restore_count($value){
        $this->set('restore_count', $value);
    }

    function set_successful_restore_count($value){
        $this->set('successful_restore_count', $value);
    }

    public function set_backup_lastrun_date($value){
        $this->set('backup_lastrun_date', $value);
    }

    public function set_cleanup_lastrun_date($value){
        $this->set('cleanup_lastrun_date', $value);
    }

    public function set_backup_dbtables_batch_size($value){
        $this->set('backup_dbtables_batch_size', $value);
    }

	public function set_backup_plugins_batch_size($value){
		$this->set('backup_plugins_batch_size', $value);
	}

    public function set_backup_themes_batch_size($value){
        $this->set('backup_themes_batch_size', $value);
    }

    public function set_backup_uploads_batch_size($value){
        $this->set('backup_uploads_batch_size', $value);
    }

    public function set_backup_others_batch_size($value){
        $this->set('backup_others_batch_size', $value);
    }

	function set_support_email($value){
		$this->set('support_email', $value);
	}

    public function set_delete_all($value){

        $this->set('delete_all', $value);
    }

    public function set_backup_plugins_filter($value){
        $this->set('backup_plugins_filter', $value);
    }
    public function set_backup_themes_filter($value){
        $this->set('backup_themes_filter', $value);
    }
    public function set_backup_uploads_filter($value){
        $this->set('backup_uploads_filter',$value);
    }
    public function set_backup_others_filter($value){
        $this->set('backup_others_filter',$value);
    }

    public function set_backup_dbtables_filter_list($value){
        $this->set('backup_dbtables_filter_list',$value);
    }

    /**---------- END SETTERS --------------- **/

	/**
	 * Generic Getter
	 */
	public  function get($property) {

		//fetch from options
		$property_value = $this->get_option($property);

		//If empty return default
		if (empty($property_value)) {
			$property_value=$this->defaults[$property];
		}

		return $property_value;

	}

    /**
     * Retrieve the stored plugin option or the default if no user specified value is defined
     *
     * @param string $option_name
     *
     * @uses get_option()
     *
     * @return mixed Returns the option value or false(boolean) if the option is not found
     */
    public function get_option( $option_name ) {

    	return WPBackItUp_Utility::get_option($option_name,$this->defaults[$option_name]);
    }



	/**
	 * Generic Setter
     *
     * @param $property
     * @param $value
     *
	 */
	private  function set($property,$value) {
		$this->set_option($property, $value);
	}

	/**
	 * Set option using WPBAckItUp Prefix
	 *
	 * @param $option_name
	 * @param $value
	 *
	 * @return mixed
	 */
	public function set_option($option_name, $value) {
	    return WPBackItUp_Utility::set_option($option_name,$value);
    }

    public function increment_backup_count(){
        $backup_count = $this->backup_count();
        $backup_count=$backup_count+1;
        $this->set_backup_count($backup_count);
    }

    public function increment_successful_backup_count(){
        $successful_backup_count = $this->successful_backup_count();
        $successful_backup_count=$successful_backup_count+1;
        $this->set_successful_backup_count($successful_backup_count);
    }

    public function increment_restore_count(){
        $restore_count = $this->restore_count();
        $restore_count = $restore_count+1;
        $this->set_restore_count($restore_count);
    }

    public function increment_successful_restore_count(){
        $successful_restore_count = $this->successful_restore_count();
        $successful_restore_count=$successful_restore_count+1;
        $this->set_successful_restore_count($successful_restore_count);
    }

     /**
     * Sanitize data
     *
     * @param mixed $str The data to be sanitized
     *
     * @uses wp_kses()
     *
     * @return mixed The sanitized version of the data
     */
    private function _sanitize( $str ) {
        if ( !function_exists( 'wp_kses' ) ) {
            include_once ABSPATH . 'wp-includes/kses.php';
        }
        global $allowedposttags;
        global $allowedprotocols;

        if ( is_string( $str ) ) {
            $str = wp_kses( $str, $allowedposttags, $allowedprotocols );
        } elseif( is_array( $str ) ) {
            $arr = array();
            foreach( (array) $str as $key => $val ) {
                $arr[$key] = $this->_sanitize( $val );
            }
            $str = $arr;
        }

        return $str;
    }


    /**STATIC FUNCTIONS**/

    /**
     * Create unique backup name based on user preferences &job ID
     *
     * @param $job_id
     *
     * @return string
     */
    public static function create_backup_job_name($job_id){

        $url = home_url();//fetch home URL -  "test-f�r-achtsamkeit.com"
        $url = remove_accents($url);//convert accented characters to ascii equivalent
        $url = str_replace('http://','',$url);//get rid of protocol
        $url = str_replace('https://','',$url);//get rid of protocol
        $url = str_replace('/','-',$url);//replace / with -

        $backup_job_name = sanitize_file_name(sprintf('%s_%s',$url,$job_id));

        return $backup_job_name;
    }

	public static function is_authorized(){

		$permission_logname='debug_permissions';
		WPBackItUp_Logger::log_info($permission_logname,__METHOD__, 'Begin');

		WPBackItUp_Logger::log_info($permission_logname,__METHOD__, 'User Permissions: ' .current_user_can( 'manage_options' ));

		if (defined('DOING_CRON')) {
			WPBackItUp_Logger::log_info($permission_logname,__METHOD__, 'Doing CRON Constant: ' . DOING_CRON );
 		} else {
			WPBackItUp_Logger::log_info($permission_logname,__METHOD__, 'DOING_CRON - NOT defined');
		}

		if (defined('XMLRPC_REQUEST')) {
			WPBackItUp_Logger::log_info($permission_logname,__METHOD__, 'XMLRPC_REQUEST Constant: ' .XMLRPC_REQUEST );
		} else {
			WPBackItUp_Logger::log_info($permission_logname,__METHOD__, 'XMLRPC_REQUEST  - NOT defined ');
		}

		//Check User Permissions or CRON
		if (!current_user_can( 'manage_options' )
		    && (!defined('DOING_CRON') || !DOING_CRON)){
			WPBackItUp_Logger::log_info($permission_logname,__METHOD__, 'End - NOT AUTHORIZED');
			return false;
		}

		WPBackItUp_Logger::log_info($permission_logname,__METHOD__, 'End - SUCCESS');
		return true;
	}

    private static function get_settings_page_url( $page = 'config' ) {

        $args = array( 'page' => 'wp-backitup-settings' );
        $url = add_query_arg( $args, admin_url( 'admin.php' ));

        return $url;
    }


    /**
     * Activation action -  will run ONLY on activation
     */
    public static function activate() {
       try{

	       //tells wpbackitup to redirect to getting started
	       set_transient( '_wpbackitup_activation_redirect', true, 30 );

	       //add cron task for every 5 minutes
	       wp_clear_scheduled_hook( 'wpbackitup_queue_scheduled_jobs');
	       if(!wp_next_scheduled( 'wpbackitup_queue_scheduled_jobs' ) ){
		       wp_schedule_event( time()+300, 'every_5_minutes', 'wpbackitup_queue_scheduled_jobs');
	       }

	       require_once( WPBACKITUP__PLUGIN_PATH .'/lib/includes/class-filesystem.php' );
	       $file_system = new WPBackItUp_FileSystem();

	       //Check backup folder folders
	       $backup_dir = WPBACKITUP__CONTENT_PATH . '/' . WPBACKITUP__BACKUP_FOLDER;
	       $file_system->secure_folder( $backup_dir);


           //--Check restore folder folders
           $restore_dir = WPBACKITUP__CONTENT_PATH . '/' . WPBACKITUP__RESTORE_FOLDER;
	       $file_system->secure_folder( $restore_dir);

	       $logs_dir = WPBACKITUP__PLUGIN_PATH .'/logs/';
	       $file_system->secure_folder( $logs_dir);

			//Make sure they exist now
			if( !is_dir($backup_dir) || !is_dir($restore_dir)) {
			   exit ('WPBackItUp was not able to create the required backup and restore folders.');
			}

       } catch (Exception $e) {
           exit ('WPBackItUp encountered an error during activation.</br>' .$e->getMessage());
       }
    }



	/**
     * Run update routines when DB or Plugin versions are not current
     *  -- Runs on plugin initialization
     *  -- note admin activation hook does NOT get called on plugin updates
     */
    public static function maybe_update() {

		//if the plugin version is less than current, run the update
        $current_plugin_major_version = get_option( 'wp-backitup_major_version',0 );
        $current_plugin_minor_version = get_option( 'wp-backitup_minor_version',0 );

	    //If current version = 0 then this is an install
	    if ($current_plugin_major_version==0){
		    update_option( 'wp-backitup_new_install', true );
	    }

        $update_plugin=false; //default to false

        //If the major versions are different
        if ( $current_plugin_major_version < WPBACKITUP__MAJOR_VERSION) {
            $update_plugin=true;
        }elseif( ($current_plugin_major_version == WPBACKITUP__MAJOR_VERSION) &&
                 ($current_plugin_minor_version < WPBACKITUP__MINOR_VERSION)) {
                //If MAJOR versions are the same but the MINOR is less
                $update_plugin=true;
        }

        //run the plugin update for major + minor release updates
        if ($update_plugin) {
            update_option( 'wp-backitup_logging',1 );

            require_once( WPBACKITUP__PLUGIN_PATH .'/lib/includes/update_plugin.php' );
            wpbackitup_update_plugin();

            //turn off again
            update_option( 'wp-backitup_logging',0 );
		}

		//if the DB version is less than current, run the update
        $current_database_version = get_option( 'wp-backitup_db_version',0 );
		if ($current_database_version < self::DB_VERSION ) {
            update_option( 'wp-backitup_logging',1 );

			require_once(WPBACKITUP__PLUGIN_PATH .'/lib/includes/update_database.php' );
			wpbackitup_update_database();

            //turn off again
            update_option( 'wp-backitup_logging',0 );
		}


	}

    /**
     * Deactivation action
     */
    public static function deactivate() {
        // Do deactivation actions

        wp_clear_scheduled_hook( 'wpbackitup_queue_scheduled_jobs');
    }

    /* ---------------------     PRIVATES      -----------------------------------------*/

    /**
     * Run tasks for a job type
     *  -  tasks are handled by include file like:
     *
     *  job_backup.php
     *  job_restore.php
     *
     * @param $job_id Job id
     */
	private function run_tasks($job_id){
		@session_write_close();

		global $current_job,$process_id,$events_logname;
		$process_id = uniqid();

		$events_logname=sprintf('debug_run_tasks_%s',$job_id); //Set Log name
        WPBackItUp_Logger::log_info($events_logname,$process_id, sprintf('(%s) Begin', 'DD'));

        $response = new stdClass();
        $response->success=false; //default to error

        if( ! empty($job_id)) {
            $current_job = WPBackItUp_Job::get_job_by_id($job_id);
            $job_type= $current_job->getJobType();
            $job_run_type= $current_job->getJobRunType();
            WPBackItUp_Logger::log_info($events_logname,$process_id, sprintf('(%s) Begin:%s-%s',$job_id,$job_type,$job_run_type));

            if (false!==$current_job) {
                WPBackItUp_Logger::log_info($events_logname,$process_id, sprintf('Job found:(%s)',var_export($current_job,true)));

                //IF job is active or queued then proceed
                if (WPBackItUp_Job::ACTIVE ==$current_job->getJobStatus() || WPBackItUp_Job::QUEUED==$current_job->getJobStatus() ){

                    //if job lock acquired run tasks
                    if (true===$current_job->get_lock('job_lock')) {
                        WPBackItUp_Logger::log_info( $events_logname, $process_id, sprintf('(%s) Job Lock Acquired.',$job_type));

                        //Try Run Next Task in stack
                        $current_task = $current_job->get_next_task();
                        WPBackItUp_Logger::log_info( $events_logname, $process_id, sprintf( '(%s) TASK INFO:', $job_type ) );
                        WPBackItUp_Logger::log_info( $events_logname, $process_id, var_export( $current_task, true ) );
                        if ( null != $current_task && false !== $current_task ) {
                            WPBackItUp_Logger::log_info( $events_logname, $process_id, sprintf( '(%s) Available Task Found: %s', $job_type, $current_task->getTaskId() ) );

                            $current_task->increment_retry_count();

                            //Was there an error on the previous run
                            if ( WPBackItUp_Job::ERROR == $current_task->getStatus() ) {
                                //Log error but error handling should happen in include
                                WPBackItUp_Logger::log_error( $events_logname, $process_id, sprintf( '(%s) Error Found Previous run: %s', $job_type, $current_task->getTaskId() ) );
                            }

                            //Run the task
                            WPBackItUp_Logger::log_info( $events_logname, $process_id, sprintf( '(%s) Try Run Task: %s', $job_type, $current_task->getTaskId() ));

                            $this->backup_type = $job_run_type;
                            $job_include_path  = sprintf( WPBACKITUP__PLUGIN_PATH . '/lib/includes/job_%s.php', $job_type );

                            //error_log('run script');
                            require_once( $job_include_path ); //Run tasks from job file

                            WPBackItUp_Logger::log_info( $events_logname, $process_id, sprintf( '(%s) End Try Run Task:%s', $job_type, $current_task->getTaskId() ) );
                        } else {
                            WPBackItUp_Logger::log_info( $events_logname, $process_id, sprintf( '(%s)No available tasks found.', $job_type ));
                            return;
                        }

                        $current_job->release_lock();
                        WPBackItUp_Logger::log_info($events_logname,$process_id ,sprintf('(%s)Lock Released.',$job_type));

                    } else {
                        WPBackItUp_Logger::log_info($events_logname,$process_id, sprintf('(%s) Job Lock NOT Acquired.',$job_type));
                    }
                }else {
                    WPBackItUp_Logger::log_info($events_logname,$process_id, sprintf('(%s) Job not active or queued status:',$job_type));
                }

            } else {
                WPBackItUp_Logger::log_info($events_logname,$process_id, sprintf('(%s) No jobs found.',$job_type));
            }

        }

        // $job type could cause error if there is no $job_id
		WPBackItUp_Logger::log_info($events_logname,$process_id, sprintf('(%s) End',$job_type));
	}


    /* ---------------------   END PRIVATES   -----------------------------------------*/

}
