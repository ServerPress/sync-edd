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
		public function handle_push($target_post_id, $post_data, SyncApiResponse $response)
		{
SyncDebug::log(__METHOD__.'(' . $target_post_id. '):' . __LINE__);
			if (!class_exists('SyncEDDApiRequest', FALSE))
				require_once(dirname(__FILE__) . '/eddapirequest.php');

			$api_controller = SyncApiController::get_instance();

			// check versions to make sure we have EDD 3.0+ and if in Strict Mode, Source and Target versions match
			$edd_version = $api_controller->get_header(SyncEDDApiRequest::HEADER_EDD_VERSION);
			if (empty($edd_version) && 'download' === $post_data['post_type']) {
				$response->error_code(SyncEDDApiRequest::ERROR_BAD_EDD_VERSION);
				return;
			}
			// remove minimum EDD version checking #17
//			if (version_compare($edd_version, WPSiteSync_EDD::REQUIRED_EDD_VERSION, 'lt')) {
//				$response->error_code(SyncEDDApiRequest::ERROR_MINIMUM_VERSION, WPSiteSync_EDD::REQUIRED_EDD_VERSION);
//				return;
//			}
			// require EDD versions on Source and Target to match, regardless of strict more setting #18
			if ((/*1 === SyncOptions::get_int('strict') && */ !version_compare($edd_version, EDD_VERSION, 'eq')) && 'download' === $post_data['post_type']) {
				$response->error_code(SyncEDDApiRequest::ERROR_EDD_VERSION_MISMATCH);
				return;
			}

			// only perform this processing if it's an EDD download post type
			if ('download' === $post_data['post_type']) {
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
			} // 'download' === post_type

			// the following processing is done on all post types

			// update any shortcode content... but only for downloads
			$content = stripslashes($post_data['post_content']);
			if ($this->_update_shortcodes($content, $response) > 0) {
				// had 1 or more shortcodes that were updated- we need to update the post_content with the changes
				global $wpdb;
				$sql = "UPDATE `{$wpdb->posts}`
						SET `post_content`=%s
						WHERE `ID`=%d ";
				$wpdb->query($sql2 = $wpdb->prepare($sql, $content, $target_post_id));
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' updating content ' . $sql2);
			}
else SyncDebug::log(__METHOD__.'():' . __LINE__ . ' no EDD shortcodes in content');

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' continue processing data');
			return $target_post_id;
		}

		/**
		 * Callback for SyncSerialize->parse_data() when parsing the serialized data. Change old Source domain to Target domain.
		 * @param SyncSerializeEntry $entry The data representing the current node processed by Serialization parser.
		 */
		public function fixup_url_references($entry)
		{
			$entry->content = str_ireplace($this->_source_urls, $this->_target_urls, $entry->content);
		}

		/**
		 * This will update any id= references in EDD shortcodes with the Target's content ID values
		 * @param string $content The post Content containing the shortcodes
		 * @param SyncApiResponse $response The response instance used for returning any error codes
		 * @return int|boolean Number of modified shortcodes or FALSE if error.
		 */
		private function _update_shortcodes(&$content, $response)
		{
SyncDebug::log(__METHOD__.'():' . __LINE__);
			$sc = new SyncEDDShortcodes();
			$modified = 0;

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' data=' . var_export($content, TRUE));
			if (FALSE !== ($matches = $sc->search($content))) {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found shortcode content: ' . $content);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' matches: ' . var_export($matches, TRUE));

				$source_site_key = SyncApiController::get_instance()->source_site_key;
				$sync_model = new SyncModel();						// needed for ID lookups
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
							$sync_data = $sync_model->get_sync_data($download_id, $source_site_key, 'post');
							if (NULL === $sync_data) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' data not found; returning');
								$response->error_code(SyncEDDApiRequest::ERROR_SHORTCODE_REF_ID, $download_id);
								return FALSE;
							}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' generating replacement shortcode for ' . $shortcode);

							// replace the ID value in the shortcode with the Target's Content ID
							$new_shortcode = $sc->replacement_shortcode($shortcode, 'id', $res['attributes']['id'], strval($sync_data->target_content_id));
							if ($new_shortcode !== $shortcode) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' replacing ' . $shortcode . ' with ' . $new_shortcode);
								$content = str_replace($shortcode, $new_shortcode, $content);
								++$modified;
							}
else SyncDebug::log(__METHOD__.'():' . __LINE__ . ' shortcodes match: ' . $new_shortcode);
						}
						break;

					case 'downloads':
					case 'edd_downloads':
						$new_ids = array();
						if (isset($res['attributes']['ids'])) {
							$ids_value = $res['attributes']['ids'];
							$ids = explode(',', $ids_value);
							// look up each ID value and get it's replacement value
							foreach ($ids as $download_id) {
								$download_id = abs($download_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' download id=' . $download_id);
								$sync_data = $sync_model->get_sync_data($download_id, $source_site_key, 'post');
								if (NULL === $sync_data) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' data not found; returning');
									$response->error_code(SyncEDDApiRequest::ERROR_SHORTCODE_REF_ID, $download_id);
									return FALSE;
								}
								$new_ids[] = $sync_data->target_content_id;
							}

							// replace the IDS value in the shortcode with the list of Target Content IDs
							$new_shortcode = $sc->replacement_shortcode($shortcode, 'ids', $res['attributes']['id'], implode(',', $new_ids));
							if ($new_shortcode !== $shortcode) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' replacing ' . $shortcode . ' with ' . $new_shortcode);
								$content = str_replace($shortcode, $new_shortcode, $content);
								++$modified;
							}
else SyncDebug::log(__METHOD__.'():' . __LINE__ . ' shortcodes match: ' . $new_shortcode);
						}
						break;

					case 'edd_price':
						if (isset($res['attributes']['id'])) {
							$download_id = abs($res['attributes']['id']);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' download id=' . $download_id);
							$sync_data = $sync_model->get_sync_data($download_id, $source_site_key, 'post');
							if (NULL === $sync_data) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' data not found; returning');
								$response->error_code(SyncEDDApiRequest::ERROR_SHORTCODE_REF_ID, $download_id);
								return FALSE;
							}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' generating replacement shortcode for ' . $shortcode);

							// replace the ID value in the shortcode with the Target's Content ID
							$new_shortcode = $sc->replacement_shortcode($shortcode, 'id', $res['attributes']['id'], strval($sync_data->target_content_id));
							if ($new_shortcode !== $shortcode) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' replacing ' . $shortcode . ' with ' . $new_shortcode);
								$content = str_replace($shortcode, $new_shortcode, $content);
								++$modified;
							}
else SyncDebug::log(__METHOD__.'():' . __LINE__ . ' shortcodes match: ' . $new_shortcode);
						}
						break;

					default:
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found shortcode "' . $match . '" but no handler for it');
					} // switch
					++$idx;					// increment index
				} // foreach (matches)
			} // FALSE !== sc->matches - checks to see if any shortcodes exist within the content

			// remove the temporary markers that prevent modifying already updated attributes
			$content = str_replace(SyncEDDShortcodes::MARKER, '', $content);

			return $modified;				// number of modified shortcodes
		}
	}
}

// EOF
