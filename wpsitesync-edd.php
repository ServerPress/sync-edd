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

		private function __construct()
		{
			add_action('spectrom_sync_init', array($this, 'init'));
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
			if (version_compare(EDD_VERSION, self::REQUIRED_EDD_VERSION, 'lt')) {
				if (is_admin() && current_user_can('install_plugins')) {
					add_action('admin_notices', array($this, 'notice_minimum_edd_version'));
					add_action('admin_init', array($this, 'disable_plugin'));
					return;
				}
				return;
			}

			// hooks for adjusting Push content
			add_filter('spectrom_sync_api_push_content', array($this, 'filter_push_content'), 10, 2);
			add_action('spectrom_sync_push_content', array($this, 'handle_push'), 10, 3);
			// error/notice code translations
			add_filter('spectrom_sync_error_code_to_text', array($this, 'filter_error_codes'), 10, 2);
			add_filter('spectrom_sync_notice_code_to_text', array($this, 'filter_notice_codes'), 10, 2);
		}

		/**
		 * Display admin notice to upgrade EDD
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
		 * Callback for filtering the post data before it's sent to the Target. Here we check for image references within the meta data.
		 * @param array $data The data being Pushed to the Target machine
		 * @param SyncApiRequest $apirequest Instance of the API Request object
		 * @return array The modified data
		 */
		public function filter_push_content($data, $apirequest)
		{
			return $data;
		}

		/**
		 * Handles fixup of data on the Target after SyncApiController has finished processing Content.
		 * @param int $target_post_id The post ID being created/updated via API call
		 * @param array $post_data Post data sent via API call
		 * @param SyncApiResponse $response Response instance
		 */
		public function handle_push($target_post_id, $post_data, $response)
		{
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
	}
} // class_exists

// Initialize the extension
WPSiteSync_EDD::get_instance();

// EOF
