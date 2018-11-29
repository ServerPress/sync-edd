<?php
/*
Plugin Name: WPSiteSync for EDD
Plugin URI: http://wpsitesync.com
Description: Allow EDD Content to be Synced to the Target site
Author: WPSiteSync
Author URI: http://wpsitesync.com
Version: 1.0 Beta
Text Domain: wpsitesync-edd

The PHP code portions are distributed under the GPL license. If not otherwise stated, all
images, manuals, cascading stylesheets and included JavaScript are NOT GPL.
 */

if (!class_exists('WPSiteSync_EDD')) {
	/*
	 * @package WPSiteSync_EDD
	 * @author Dave Jesch
	 */
	class WPSiteSync_EDD
	{
		private static $_instance = NULL;

		const PLUGIN_NAME = 'WPSiteSync for EDD';
		const PLUGIN_VERSION = '1.0';
		const PLUGIN_KEY = '1249a07a842b9deacc1dbe511836db9c';
		const REQUIRED_VERSION = '1.3.3';		// minimum version of WPSiteSync required for this add-on to initialize
		const REQUIRED_EDD_VERSION = '3.0';		// minimum version of EDD required for this add-on to initialize

		private $_init = FALSE;
		private $_source_api = NULL;
		private $_post_types = array('download');	// list of currently supported post types

		private function __construct()
		{
			add_action('spectrom_sync_init', array($this, 'init'));
			add_action('init', array($this, 'check_wpss_active'));
			add_filter('upload_dir', array($this, 'filter_upload_dir'), 50);
			add_filter('spectrom_sync_allowed_post_types', array($this, 'allow_custom_post_types'));
			add_filter('spectrom_sync_tax_list', array($this, 'filter_taxonomies'), 10, 1);
			add_filter('spectrom_sync_upload_media_allowed_mime_type', array($this, 'filter_allowed_mime_types'), 10, 2);
		}

		/*
		 * retrieve singleton class instance
		 * @return instance reference to plugin
		 */
		public static function get_instance()
		{
			if (NULL === self::$_instance)
				self::$_instance = new self();
			return self::$_instance;
		}

		/**
		 * Callback for the 'spectrom_sync_init' action. Used to initialize this plugin knowing the WPSiteSync exists
		 */
		public function init()
		{
			// enforce minimum EDD version
			if (!defined('EDD_VERSION')) {
				if (is_admin() && current_user_can('install_plugins')) {
					add_action('admin_notices', array($this, 'notice_requires_edd'));
					add_action('admin_init', array($this, 'disable_plugin'));
					return;
				}
				return;
			}
			// remove minimum EDD version checking #17
//			if (version_compare(EDD_VERSION, self::REQUIRED_EDD_VERSION, 'lt')) {
//				if (is_admin() && current_user_can('install_plugins')) {
//					add_action('admin_notices', array($this, 'notice_minimum_edd_version'));
//					add_action('admin_init', array($this, 'disable_plugin'));
//					return;
//				}
//				return;
//			}

			add_filter('spectrom_sync_active_extensions', array($this, 'filter_active_extensions'), 10, 2);
#			if (!WPSiteSyncContent::get_instance()->get_license()->check_license('sync_edd', self::PLUGIN_KEY, self::PLUGIN_NAME)) {
#SyncDebug::log(__METHOD__ . '() no license');
#				return;
#			}

			if (is_admin())
				add_action('wp_loaded', array($this, 'wp_loaded'));

			// hooks for adjusting Push content
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking for AJAX');
			if (defined('DOING_AJAX') && DOING_AJAX) {
				// we only need to load the Source API implementation when doing AJAX calls
				$this->_load_class('eddsourceapi');
				$this->_edd_api = new SyncEDDSourceApi();
			}
			add_action('spectrom_sync_push_content', array($this, 'handle_push'), 10, 3);

			// error/notice code translations
			add_filter('spectrom_sync_error_code_to_text', array($this, 'filter_error_codes'), 10, 2);
			add_filter('spectrom_sync_notice_code_to_text', array($this, 'filter_notice_codes'), 10, 2);
			$this->_init = TRUE;
		}

		/**
		 * Callback for 'init' action. Checks to ensure WPSiteSync is active and displays message if not
		 */
		public function check_wpss_active()
		{
			if (!class_exists('WPSiteSyncContent', FALSE)) {
				if (is_admin() && current_user_can('install_plugins')) {
					add_action('admin_notices', array($this, 'notice_requires_wpss'));
					add_action('admin_init', array($this, 'disable_plugin'));
				}
				return;
			}
		}

		/**
		 * Display admin notice to install/activate WPSiteSync for Content
		 */
		public function notice_requires_wpss()
		{
			$this->_show_notice(sprintf(__('WPSiteSync for EDD requires the main <em>WPSiteSync for Content</em> plugin to be installed and activated. Please <a href="%1$s">click here</a> to install or <a href="%2$s">click here</a> to activate.', 'wpsitesync-edd'),
				admin_url('plugin-install.php?tab=search&s=wpsitesync'),
				admin_url('plugins.php')), 'notice-warning');
		}

		/**
		 * Display admin notice to install/activate EDD
		 */
		public function notice_requires_edd()
		{
			$this->_show_notice(sprintf(__('WPSiteSync for EDD requires <em>Easy Digital Downloads</em> to be installed and activated.', 'wpsitesync-edd'), self::REQUIRED_EDD_VERSION), 'notice-warning');
		}

		/**
		 * Display admin notice to upgrade EDD
		 */
		public function notice_minimum_edd_version()
		{
			$this->_show_notice(sprintf(__('WPSiteSync for EDD requires <em>Easy Digital Downloads</em> version %1$s or greater to be installed.', 'wpsitesync-edd'), self::REQUIRED_EDD_VERSION), 'notice-warning');
		}

		/**
		 * Helper method to display notices
		 * @param string $msg Message to display within notice
		 * @param string $class The CSS class used on the <div> wrapping the notice
		 * @param boolean $dismissable TRUE if message is to be dismissable; otherwise FALSE.
		 */
		private function _show_notice($msg, $class = 'notice-success', $dismissable = FALSE)
		{
			echo '<div class="notice ', $class, ' ', ($dismissable ? 'is-dismissible' : ''), '">';
			echo '<p>', $msg, '</p>';
			echo '</div>';
		}

		/**
		 * Callback for 'wp_loaded' action when all plugins have been initialized
		 */
		public function wp_loaded()
		{
			$this->_load_class('eddadmin');
			new SyncEDDAdmin();
		}

		/**
		 * Disables the plugin if EDD is not active or too old
		 */
		public function disable_plugin()
		{
			deactivate_plugins(plugin_basename(__FILE__));
		}

		/**
		 * Filters the errors list, adding Sync EDD specific code-to-string values
		 * @param string $message The error string message to be returned
		 * @param int $code The error code being evaluated
		 * @return string The modified $message string, with EDD specific errors added to it
		 */
		public function filter_error_codes($message, $code)
		{
			$this->_load_class('eddapirequest');
			$api = new SyncEDDApiRequest();
			$message = $api->error_code_to_string($message, $code);
			return $message;
		}

		/**
		 * Filters the notices list, adding EDD specific code-to-string values
		 * @param string $message The notice string message to be returned
		 * @param int $code The notice code being evaluated
		 * @return string The modified $message string, with EDD specific notices added to it
		 */
		public function filter_notice_codes($message, $code)
		{
			$this->_load_class('eddapirequest');
			$api = new SyncEDDApiRequest();
			$message = $api->notice_code_to_string($message, $code);
			return $message;
		}

		/**
		 * Adds EDD custom post types to the list of `spectrom_sync_allowed_post_types`
		 * @param array $post_types The post types to allow
		 * @return array The allowed post types, with the EDD types added
		 */
		public function allow_custom_post_types($post_types)
		{
			if ($this->_init ||
				(isset($_POST['img_path']) && isset($_POST['edd_download']) && '1' === $_POST['edd_download'])) {
#				if (WPSiteSyncContent::get_instance()->get_license()->check_license('sync_edd', self::PLUGIN_KEY, self::PLUGIN_NAME)) {
					// either WPSiteSync for EDD has been initialized for an Admin page request
					// or it's a EDD Download product file upload request
					$post_types = array_merge($post_types, $this->_post_types);
#				}
			}

			return $post_types;
		}

		/**
		 * Adds EDD specific taxonomies to the list of available taxonomies for Syncing
		 * @param array $tax Array of taxonomy information to filter
		 * @return array The taxonomy list, with all EDD taxonomies added to it
		 */
		public function filter_taxonomies($taxonomies)
		{
			$all_tax = get_taxonomies(array(), 'objects');
			foreach ($all_tax as $tax_name => $tax_info) {
				$diff = array_diff($tax_info->object_type, $this->_post_types);
				if (0 === count($diff)) {
					$taxonomies[$tax_name] = $tax_info;
				}
			}
			return $taxonomies;
		}

		/**
		 * Callback for filtering the allowed mime types for uploads. Needed to allow any file types when processing EDD attachment files.
		 * @param boolean $allow TRUE to allow the type; otherwise FALSE
		 * @param array $type Array of File Type information returned from wp_check_filetype()
		 * @return TRUE to indicate that all file types are allowed for EDD download attachments
		 */
		public function filter_allowed_mime_types($allowed, $type)
		{
			if (isset($_POST['img_path']) && isset($_POST['edd_download']) && '1' === $_POST['edd_download']) {
				// it's an EDD Download product file attachment- allow anything to be uploaded
				$allowed = TRUE;
			}
			return $allowed;
		}

		/**
		 * Handles fixup of data on the Target after SyncApiController has finished processing Content.
		 * @param int $target_post_id The post ID being created/updated via API call
		 * @param array $post_data Post data sent via API call
		 * @param SyncApiResponse $response Response instance
		 */
		public function handle_push($target_post_id, $post_data, $response)
		{
			$this->_load_class('eddtargetapi');
			$target = new SyncEDDTargetApi();
			$target->handle_push($target_post_id, $post_data, $response);
		}

		/**
		 * Filters the location for the uploaded files.
		 * @param array $upload Array of information about upload location
		 * @return array Modified location information if it's an EDD file upload
		 */
		public function filter_upload_dir($upload)
		{
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post=' . var_export($_POST, TRUE));
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' files=' . var_export($_FILES, TRUE));
			if (isset($_POST['edd_download']) && '1' === $_POST['edd_download']) {
				if (function_exists('edd_set_upload_dir')) {
					// use the EDD function if it exists
					$upload = edd_set_upload_dir($upload);
				} else {
					// as a last resort, force an /edd prefix
					$upload['subdir'] = '/edd' . $upload['subdir'];
				}
			}
			return $upload;
		}

		/**
		 * Helper method to load class files when needed
		 * @param string $class Name of class file to load
		 */
		private function _load_class($class)
		{
			$file = dirname(__FILE__) . '/classes/' . $class . '.php';
			require_once($file);
		}

		/**
		 * Add the EDD add-on to the list of known WPSiteSync extensions
		 * @param array $extensions The list to add to
		 * @param boolean $set
		 * @return array The list of extensions, with the WPSiteSync for EDD add-on included
		 */
		public function filter_active_extensions($extensions, $set = FALSE)
		{
#			if ($set || WPSiteSyncContent::get_instance()->get_license()->check_license('sync_edd', self::PLUGIN_KEY, self::PLUGIN_NAME))
				$extensions['sync_edd'] = array(
					'name' => self::PLUGIN_NAME,
					'version' => self::PLUGIN_VERSION,
					'file' => __FILE__,
				);
			return $extensions;
		}
	}
} // class_exists

// Initialize the extension
WPSiteSync_EDD::get_instance();

// EOF
