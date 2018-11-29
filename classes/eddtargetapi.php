<?php

if (!class_exists('SyncEDDTargetApi', FALSE)) {
	class SyncEDDTargetApi extends SyncInput
	{
		private $_source_urls = array();
		private $_target_urls = array();

		/**
		 * Handles fixup of data on the Target after SyncApiController has finished processing Content.
		 * @param int $target_post_id The post ID being created/updated via API call
		 * @param array $post_data Post data sent via API call
		 * @param SyncApiResponse $response Response instance
		 */
		public function handle_push($target_post_id, $post_data, $response)
		{
SyncDebug::log(__METHOD__.'(' . $target_post_id. '):' . __LINE__);
			if (!class_exists('SyncEDDApiRequest', FALSE))
				require_once(dirname(__FILE__) . '/eddapirequest.php');

			$api_controller = SyncApiController::get_instance();

			// check versions to make sure we have EDD 3.0+ and if in Strict Mode, Source and Target versions match
			$edd_version = $api_controller->get_header(SyncEDDApiRequest::HEADER_EDD_VERSION);
			if (empty($edd_version)) {
				$response->error_code(SyncEDDApiRequest::ERROR_BAD_EDD_VERSION);
				return;
			}
			// remove minimum EDD version checking #17
//			if (version_compare($edd_version, WPSiteSync_EDD::REQUIRED_EDD_VERSION, 'lt')) {
//				$response->error_code(SyncEDDApiRequest::ERROR_MINIMUM_VERSION, WPSiteSync_EDD::REQUIRED_EDD_VERSION);
//				return;
//			}
			// require EDD versions on Source and Target to match, regardless of strict more setting #18
			if (/*1 === SyncOptions::get_int('strict') && */ !version_compare($edd_version, EDD_VERSION, 'eq')) {
				$response->error_code(SyncEDDApiRequest::ERROR_EDD_VERSION_MISMATCH);
				return;
			}

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post data=' . var_export($post_data, TRUE));
			$post_meta = $this->post_raw('post_meta');

			// check that bundled products have been sync'd
			$post_id = abs($post_data['ID']);
			$prod_type = 'sync_standard';		// default to this. non-bundles have no '_edd_product_type' postmeta
			if (isset($post_meta['_edd_product_type'][0]))
				$prod_type = $post_meta['_edd_product_type'][0];
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post id=' . $post_id . ' type=' . $prod_type);

			// handle the different EDD Download product types
			switch ($prod_type) {
			case 'bundle':
				// it's a bundle - make sure all bundled products have been sync'd
				$site_key = $api_controller->source_site_key;
				$products = $post_meta['_edd_bundled_products'][0];
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' products=' . var_export($products, TRUE));
				$products = stripslashes($products);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' products=' . var_export($products, TRUE));
				$products = maybe_unserialize($products);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' products=' . var_export($products, TRUE));
				$sync_model = new SyncModel();
				foreach ($products as $download_product_id) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' looking up Source ID ' . $download_product_id);
					$sync_data = $sync_model->get_sync_data($download_product_id, $site_key);
					if (NULL === $sync_data) {
						$response->error_code(SyncEDDApiRequest::ERROR_MISSING_PRODUCT_FROM_BUNDLE, $download_product_id);
						return;
					}
				}
				break;

			case 'sync_standard':
				// process 'edd_download_files' postmeta data
				$api_controller->get_fixup_domains($this->_source_urls, $this->_target_urls);

				$download_files = $post_meta['edd_download_files'][0];
				$download_files = stripslashes($download_files);
				$ser = new SyncSerialize();
				$download_data = $ser->parse_data($download_files, array($this, 'fixup_url_references'));
				$meta_object = maybe_unserialize($download_data);
				// write the modified meta data to the postmeta table
				update_post_meta($target_post_id, 'edd_download_files', $meta_object);
				break;
			}

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' continue processing data');
		}

		/**
		 * Callback for SyncSerialize->parse_data() when parsing the serialized data. Change old Source domain to Target domain.
		 * @param SyncSerializeEntry $entry The data representing the current node processed by Serialization parser.
		 */
		public function fixup_url_references($entry)
		{
			$entry->content = str_ireplace($this->_source_urls, $this->_target_urls, $entry->content);
		}
	}
}

// EOF
