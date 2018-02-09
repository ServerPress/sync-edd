<?php

if (!class_exists('SyncEDDSourceApi', FALSE)) {
	class SyncEDDSourceApi
	{
		const HEADER_EDD_VERSION = 'x-edd-version';

		public function __construct()
		{
			add_filter('spectrom_sync_api_arguments', array($this, 'filter_api_arguments'), 10, 2);
			add_action('spectrom_sync_api_response', array($this, 'check_api_response'), 10, 2);
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
				$remote_args['headers'][self::HEADER_EDD_VERSION] = EDD_VERSION;
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
					if (!class_exists('SyncEDDApiRequest', FALSE))
						require_once(dirname(__FILE__) . '/eddapirequest.php');
					$response->response->error_code = SyncEDDApiRequest::ERROR_EDD_NOT_ON_TARGET;
					$response->response->has_errors = 1;
				}
			}
		}
	}
}

// EOF
