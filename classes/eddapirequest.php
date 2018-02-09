<?php

/**
 * Sends requests to the API on the Target
 */
class SyncEDDApiRequest
{
	const ERROR_VERSION_NOT_SUPPORTED = 1000;
	const ERROR_EDD_VERSION_MISMATCH = 1001;
	const ERROR_BUNDLE_PRODUCT_NOT_SYNCD = 1002;

	const NOTICE = 1000;

	/**
	 * Converts an error code to a language translated string
	 * @param int $code The integer error code. One of the `ERROR_*` values.
	 * @return string The text value of the error code, translated to the current locale
	 */
	public function error_code_to_string($message, $code)
	{
		switch ($code) {
		case self::ERROR_VERSION_NOT_SUPPORTED:		$message = __('This version of Easy Digital Downloads is not supported. Please upgrade to 3.0 or above.', 'wpsitesync-edd');		break;
		case self::ERROR_EDD_VERSION_MISMATCH:		$message = __('The version of Easy Digital Downloads on Source and Target sites must match. Please upgrade.', 'wpsitesync-edd');	break;
		case self::ERROR_BUNDLE_PRODUCT_NOT_SYNCD:	$message = __('One of the Products in your Bundle has not yet been Pushed to the Target site.', 'wpsitesync-edd');	break;
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