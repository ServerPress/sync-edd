<?php

/**
 * Sends requests to the API on the Target
 */
class SyncEDDApiRequest
{
	const ERROR_VERSION_NOT_SUPPORTED = 1000;
	const ERROR_EDD_VERSION_MISMATCH = 1001;
	const ERROR_BUNDLE_PRODUCT_NOT_SYNCD = 1002;
	const ERROR_EDD_NOT_ON_TARGET = 1003;
	const ERROR_BAD_EDD_VERSION = 1004;
	const ERROR_MINIMUM_VERSION = 1005;
	const ERROR_MISSING_PRODUCT_FROM_BUNDLE = 1006;
	const ERROR_SHORTCODE_REF_ID = 1007;

	const NOTICE = 1000;

	const HEADER_EDD_VERSION = 'x-edd-version';

	/**
	 * Converts an error code to a language translated string
	 * @param int $code The integer error code. One of the `ERROR_*` values.
	 * @return string The text value of the error code, translated to the current locale
	 */
	public function error_code_to_string($message, $code, $data = NULL)
	{
		switch ($code) {
		case self::ERROR_VERSION_NOT_SUPPORTED:			$message = __('This version of Easy Digital Downloads is not supported. Please upgrade to 3.0 or above.', 'wpsitesync-edd');		break;
		case self::ERROR_EDD_VERSION_MISMATCH:			$message = __('The version of Easy Digital Downloads on Source and Target sites must match. Please upgrade.', 'wpsitesync-edd');	break;
		case self::ERROR_BUNDLE_PRODUCT_NOT_SYNCD:		$message = __('One of the Products in your Bundle has not yet been Pushed to the Target site.', 'wpsitesync-edd');	break;
		case self::ERROR_EDD_NOT_ON_TARGET:				$message = __('Push failed. WPSiteSync for EDD is not active on Target.', 'wpsitesync-edd'); break;
		case self::ERROR_BAD_EDD_VERSION:				$message = __('The version of Easy Digital Downloads on the Target site cannot be detected.', 'wpsitesync-edd'); break;
		case self::ERROR_MINIMUM_VERSION:				$message = sprintf(__('The version of Easy Digital Downloads on the Target needs to be at least %1$d.', 'wpsitesync-edd'), WPSiteSync_EDD::REQUIRED_EDD_VERSION); break;
		case self::ERROR_MISSING_PRODUCT_FROM_BUNDLE:	$message = __('One of the Products in the Bundle has not yet been Syncd to the Target site.', 'wpsitesync-edd'); break;
		case self::ERROR_SHORTCODE_REF_ID:				$message = sprintf(__('One or more shortcodes on this page contain references to an EDD Download that has not yet been Pushed (ID=%d). Please Push the Download first.', 'wpsitesync-edd'), $data); break;
		}
		return $message;
	}

	/**
	 * Converts a notice code to a language translated string
	 * @param int $code The integer notice code. One of the `NOTICE_*` values.
	 * @return string The text value of the notice code, translated to the current locale
	 */
	public function notice_code_to_string($message, $code)
	{
		switch ($code) {
		case self::NOTICE:		$message = 'notice';				break;
		}
		return $message;
	}
}

// EOF