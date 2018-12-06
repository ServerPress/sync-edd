<?php

if (!class_exists('SyncEDDSourceApi', FALSE)) {
	class SyncEDDSourceApi
	{
		private $_api_request = NULL;					// instance of the SyncApiRequest object conducting the current API request

		public function __construct()
		{
//SyncDebug::log(__METHOD__.'():' . __LINE__);
			if (!class_exists('SyncEDDApiRequest', FALSE))
				require_once(dirname(__FILE__) . '/eddapirequest.php');

			add_filter('spectrom_sync_api_arguments', array($this, 'filter_api_arguments'), 10, 2);
			add_filter('spectrom_sync_api_push_content', array($this, 'filter_push_content'), 10, 2);
			add_action('spectrom_sync_api_response', array($this, 'check_api_response'), 10, 3);
		}

		/**
		 * Filters the WPSiteSync API arguments. Add the EDD version to the HTTP headers so Target knows what version is sending request
		 * @param array $remote_args Array of arguments used in the wp_remote_post() call
		 * @param string $action API action generating the call
		 * @return array The API arguments with the EDD version added to the headers
		 */
		public function filter_api_arguments($remote_args, $action)
		{
			if ('push' === $action || 'pull' === $action)
				$remote_args['headers'][SyncEDDApiRequest::HEADER_EDD_VERSION] = EDD_VERSION;
			return $remote_args;
		}

		/**
		 * Callback for 'spectrom_sync_api_response' action. Used to check API return code and give more descriptive error message.
		 * @param SyncApiResponse $response The response object from the Target site
		 * @param string $action The action that generated the API call, such as 'push' or 'pull'
		 * @param array The data sent via the API call
		 */
		public function check_api_response($response, $action, $data)
		{
			if (SyncApiRequest::ERROR_INVALID_POST_TYPE === $response->error_code && 'push' === $action) {
				if (isset($data['post_data']['post_type']) && 'download' === $data['post_data']['post_type']) {
					// only update the error code if it's a 'download' post type, otherwise it's a real invalid post type error
					$response->response->error_code = SyncEDDApiRequest::ERROR_EDD_NOT_ON_TARGET;
					$response->response->has_errors = 1;
				}
			}
		}

		/**
		 * Callback for 'spectrom_sync_api_push_content' filter. Modifies content specific to EDD 'push' requests
		 * @param array $data The data being constructed for the current API call
		 * @param SyncApiRequest $apirequest The API Request instance for the current API call
		 */
		public function filter_push_content($data, $apirequest)
		{
SyncDebug::log(__METHOD__.'():' . __LINE__);
			$this->_api_request = $apirequest;			// save this for use in _send_download_files()

			// If we're not pushing content ['post_data'] will be empty. And if it's not a 'download' post type, we can ignore
			if (!isset($data['post_data']) || 'download' !== $data['post_data']['post_type']) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' not a "download" post type');
				// if it's not a 'download' post type, do not process. It's not an EDD 'Push' operation
				return $data;
			}
			// Check for usage of EDD shortcodes. This will return 1 if there's a shortcode referring to a Download that hasn't been pushed.
			if (1 === $this->_check_shortcodes($data, $apirequest)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' using EDD shortcodes');
				return $data;
			}


			$post_id = abs($data['post_id']);
			$prod_type = 'sync_standard';		// default to this. non-bundles have no '_edd_product_type' postmeta
			if (isset($data['post_meta']['_edd_product_type']) && isset($data['post_meta']['_edd_product_type'][0]))
				$prod_type = $data['post_meta']['_edd_product_type'][0];
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post id=' . $post_id . ' type=' . $prod_type);

			// set up filters needed for send_media() calls
			add_filter('spectrom_sync_upload_media_fields', array($this, 'filter_media_fields'));

			// handle the different EDD product types separately
			switch ($prod_type) {
			case 'bundle':
				// it's a bundle product- get download files for all included files
				$meta = $data['post_meta']['_edd_bundled_products'][0];
				$products = maybe_unserialize($meta);
				// iterate through all Download products included in the Bundle
				foreach ($products as $download_product_id) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' processing download product id ' . $download_product_id);
					// get the Download product's download file information
					$download_files = get_post_meta($download_product_id, 'edd_download_files', TRUE);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' file information: ' . var_export($download_files, TRUE));
					$this->_send_download_files($download_files, $download_product_id);
				}
				break;

			case 'sync_standard':
				$download_files = maybe_unserialize($data['post_meta']['edd_download_files'][0]);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' file information: ' . var_export($download_files, TRUE));
				$this->_send_download_files($download_files, $post_id);
				break;
			}

			// do this just in case someone else wants to use send_media()
			remove_filter('spectrom_sync_upload_media_fields', array($this, 'filter_media_fields'));

			// remove specific post meta values #16
			if (isset($data['post_meta'])) {
				unset($data['post_meta']['_edd_download_sales']);
				unset($data['post_meta']['_edd_download_earnings']);
			}

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' done processing');
			return $data;
		}

		/**
		 * Checks content to see if any shortcodes containing IDs are being used
		 * @param array $data The data being Pushed to the Target, includes post data
		 * @param SyncApiRequest $apirequest Instance of the API request object
		 * @return int 0=no shortcode use; 1=shortcode use
		 */
		private function _check_shortcodes($data, $apirequest)
		{
SyncDebug::log(__METHOD__.'():' . __LINE__);
			$sc = new SyncEDDShortcodes();

			if (FALSE !== ($matches = $sc->search($data['post_data']['post_content'])))
			{
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found shortcode content: ' . $data['post_data']['post_content']);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' matches: ' . var_export($matches, TRUE));
				// shortcode is being used
				$sync_model = new SyncModel();						// used for content lookups

				$idx = 0;
				foreach ($matches[2] as $match) {
					// get the attributes found within the shortcode
					$shortcode = $matches[0][$idx];					// contains the full shortcode
					$res = $sc->extract_attributes($shortcode);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' res=' . var_export($res, TRUE));

					switch (strtolower($match)) {
					case 'purchase_link':
						if (isset($res['attributes']['id'])) {
							$download_id = abs($res['attributes']['id']);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' download id=' . $download_id);
							$sync_data = $sync_model->get_sync_data($download_id, NULL, 'post');
							if (NULL === $sync_data) {
								$apirequest->get_response()->error_code(SyncEDDApiRequest::ERROR_SHORTCODE_REF_ID, $download_id);
								return 1;
							}
						}
						break;

					case 'downloads':
					case 'edd_downloads':
						if (isset($res['attributes']['ids'])) {
							$ids_value = $res['attributes']['ids'];
							$ids = explode(',', $ids_value);
							// check each ID in the list and make sure they've all been Pushed
							foreach ($ids as $download_id) {
								$sync_data = $sync_model->get_sync_data($download_id, NULL, 'post');
								if (NULL === $sync_data) {
									$apirequest->get_response()->error_code(SyncEDDApiRequest::ERROR_SHORTCODE_REF_ID, $download_id);
									return 1;
								}
							}
						}
						break;

					default:
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found shortcode "' . $match . '" but no handler for it');
					}
					++$idx;
				}
			}
			return 0;
		}

		/**
		 * Sends all files associated with the EDD Download product
		 * @param array $files Assocative array of data describing EDD Download product
		 * @param int $post_id The post ID that 'owns' this attachment
		 */
		private function _send_download_files($files, $post_id)
		{
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' files=' . var_export($files, TRUE));
			// Note: The $files parameter is taken from the 'edd_download_files' postmeta entry for the Download product

			// single array entry looks like:
			foreach ($files as $idx => $file) {
/**
array (
  1 =>
  array (
    'index' => '0',
    'attachment_id' => '1814',
    'thumbnail_size' => 'false',
    'name' => 'wpsitesync-edd',
    'file' => 'http://sync.loc/wp-content/uploads/edd/2018/02/wpsitesync-edd.zip',
    'condition' => 'all',
  ),
)
 */

				$download_id = abs($file['attachment_id']);
SyncDebug::log(__METHOD__.'():' . __LINE__ . " calling send_media('{$file['file']}', {$post_id}, 0, {$download_id})");
				// send_media() handles duplicate download files being send- no need to check for that
				$this->_api_request->send_media($file['file'], $post_id, 0, $download_id);
			}
		}

		/**
		 * Filter to add an identifier so the Target knows it's an EDD download file and handle it differently.
		 * @param array $fields The post fields for the media upload items
		 * @return array The modified array with an 'edd_download' field added
		 */
		public function filter_media_fields($fields)
		{
			// TODO: change this to a site key?
			$fields['edd_download'] = '1';
			return $fields;
		}
	}
}

// EOF
