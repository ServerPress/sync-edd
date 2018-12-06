<?php

/**
 * Centralized location for shortcode handling methods. Used by both the SyncEDDSourceApi and SyncEDDTargetApi classes.
 */
class SyncEDDShortcodes
{
	/**
	 * Get a list of known EDD shortcodes that can contain an ID reference to a Download product
	 * @return array An array containing all known EDD shortcodes that can reference an ID
	 */
	public function get_shortcodes()
	{
		// only include the shortcodes that have references to ids
		$shortcodes = array(
			'purchase_link',			// [purchase_link id="{download id}" style="plain" color="" text="download here" price_id="" form_id=""]
//			'download_history',
//			'purchase_history',
//			'download_checkout',
//			'download_cart',
//			'edd_login',
//			'edd_register',
//			'download_discounts',
//			'purchase_collection',
			'downloads',				// [downloads ids=""]
			'edd_downloads',			// [downloads ids=""]
//			'edd_price',
//			'edd_receipt',
//			'edd_profile_editor',
		);
		return $shortcodes;
	}

	/**
	 * Performes a search for EDD specific shortcodes within the Content provided
	 * @param string $content A String representing the Content to search for shortcodes within.
	 * @return array|boolean An array containing the RegEx matches of shortcodes, if found; otherwise FALSE for no shortcodes found.
	 */
	public function search($content)
	{
		$shortcodes = $this->get_shortcodes();
		$tagregexp = implode('|', $shortcodes);

		// taken from get_shortcode_regex()
		$pattern =
			'\\['                              // Opening bracket
		  . '(\\[?)'                           // 1: Optional second opening bracket for escaping shortcodes: [[tag]]
		  . "($tagregexp)"                     // 2: Shortcode name
		  . '(?![\\w-])'                       // Not followed by word character or hyphen
		  . '('                                // 3: Unroll the loop: Inside the opening shortcode tag
		  .     '[^\\]\\/]*'                   // Not a closing bracket or forward slash
		  .     '(?:'
		  .         '\\/(?!\\])'               // A forward slash not followed by a closing bracket
		  .         '[^\\]\\/]*'               // Not a closing bracket or forward slash
		  .     ')*?'
		  . ')'
		  . '(?:'
		  .     '(\\/)'                        // 4: Self closing tag ...
		  .     '\\]'                          // ... and closing bracket
		  . '|'
		  .     '\\]'                          // Closing bracket
		  .     '(?:'
		  .         '('                        // 5: Unroll the loop: Optionally, anything between the opening and closing shortcode tags
		  .             '[^\\[]*+'             // Not an opening bracket
		  .             '(?:'
		  .                 '\\[(?!\\/\\2\\])' // An opening bracket not followed by the closing shortcode tag
		  .                 '[^\\[]*+'         // Not an opening bracket
		  .             ')*+'
		  .         ')'
		  .         '\\[\\/\\2\\]'             // Closing shortcode tag
		  .     ')?'
		  . ')'
		  . '(\\]?)';                          // 6: Optional second closing bracket for escaping shortcodes: [[tag]]

		if (preg_match_all('/' . $pattern . '/s', $content, $matches)
			&& array_key_exists(2, $matches))
			return $matches;

		return FALSE;
	}

	/**
	 * Uses RegEx to pull attributes and their values from the Shortcode string
	 * @param string $input The Shortcode string to search for attributes within
	 * @return array|boolean Array of shortcode information; FALSE on error or missing attributes
	 */
	public function extract_attributes($input)
	{
		// https://stackoverflow.com/questions/317053/regular-expression-for-extracting-tag-attributes/319378
		// based on https://github.com/mecha-cms/cms/blob/master/system/kernel/converter.php
		if (!preg_match('#^(\[)([a-z0-9\-._:]+)((\s)+(.*?))?((\])([\s\S]*?)((\[)\/\2(\]))|(\s)*\/?(\]))$#im', $input, $matches))
			return FALSE;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found matches: ' . var_export($matches, TRUE));

		$matches[5] = preg_replace('#(^|(\s)+)([a-z0-9\-]+)(=)(")(")#i', '$1$2$3$4$5<attr:value>$6', $matches[5]);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' matches[5]=' . var_export($matches[5], TRUE));
		$results = array(
			'element' => $matches[2],
			'attributes' => NULL,
		);

		$code = preg_replace('/\s*=\s*/', '=', $matches[5]);			// remove any spaces around '=' char
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' extracting attributes from ' . $code);
		if (preg_match_all('#([a-z0-9\-]+)((=)("|\')(.*?)("|\'))?(?:(\s)|$)#i', $code, $attrs)) {
			$results['attributes'] = array();
			foreach ($attrs[1] as $i => $attr) {
				$results['attributes'][$attr] = isset($attrs[5][$i]) && ! empty($attrs[5][$i])
					? ($attrs[5][$i] != '<attr:value>' ? $attrs[5][$i] : '') : $attr;
			}
		}

		return $results;
	}

	/**
	 * Generate a replacement shortcode, altering the original with the modified attribute value
	 * @param string $shortcode The original shortcode string
	 * @param string $attr The attribute who's value is to be replaced. Ex 'id' to replace the id="" attribute
	 * @param string $attr_value The original attribute value. If id="21" then pass '21' as the value
	 * @param string $new_value The replacement attribute value. If id="21" to ba changed to '99' then pass '99' as the value
	 * @return string A string containing the modified shortcode; replacing the attribute value with the new attribute value
	 */
	function replacement_shortcode($shortcode, $attr, $attr_value, $new_value)
	{
SyncDebug::log(__METHOD__."('{$shortcode}', '{$attr}'= , '{$attr_value}', '{$new_value}'):" . __LINE__);
		$new_shortcode = $shortcode;				// initialize the replacement shortcode string
		$len = strlen($shortcode);					// length of original shortcode string
		$offset = 0;								// search offset starting at beginning of string
		for (;;) {
			$pos_attr = stripos($new_shortcode, $attr, $offset);
			if (FALSE === $pos_attr) {
				break;
			}

			// move $idx past any spaces after the attr
			for ($idx = $pos_attr + strlen($attr); ' ' === substr($new_shortcode, $idx, 1) && $idx < $len; ++$idx)
				;
			// $idx now points to the '='
			if ('=' !== substr($new_shortcode, $idx, 1)) {
				$offset = $idx;
				continue;						// search again
			}
			++$idx;								// move past the equal sign

			// move $idx past any spaces after the '='
			for (; ' ' === substr($new_shortcode, $idx, 1) && $idx < $len; ++$idx)
				;

			// next char is either a ' or a "
			$quote = substr($new_shortcode, $idx, 1);
			if ('\'' !== $quote && '"' !== $quote) {
				$offset = $idx;
				continue;						// search again
			}
			++$idx;

			// this position should hold the original shortcode value
			if (substr($new_shortcode, $idx, strlen($attr_value)) !== $attr_value) {
				$offset = $idx;
				continue;						// search again
			}

			// ensure there's a closing quote found after the value
			if (substr($new_shortcode, $idx + strlen($attr_value), 1) !== $quote) {
				$offset = $idx;
				continue;						// search again
			}

			// we have the index for the original shortcode attribute value - change it
			$new_shortcode = substr($new_shortcode, 0, $idx) . $new_value . substr($new_shortcode, $idx + strlen($attr_value));
			break;								// done
		}

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' replacement shortcode: ' . $new_shortcode);
		return $new_shortcode;
	}
}

// EOF
