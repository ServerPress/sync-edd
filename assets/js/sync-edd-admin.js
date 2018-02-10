/*
 * @copyright Copyright (C) 2015-2018 SpectrOMtech.com. - All Rights Reserved.
 * @license GNU General Public License, version 2 (http://www.gnu.org/licenses/gpl-2.0.html)
 * @author SpectrOMtech.com <hello@SpectrOMtech.com>
 * @url https://www.SpectrOMtech.com/products/
 * The PHP code portions are distributed under the GPL license. If not otherwise stated, all images, manuals, cascading style sheets, and included JavaScript *are NOT GPL, and are released under the SpectrOMtech Proprietary Use License v1.0
 * More info at https://SpectrOMtech.com/products/
 */

console.log('sync-edd-admin.js');

function WPSiteSyncContent_EDD()
{
	this.inited = false;
	this.$push_button = null;
	this.disable = false;
	this.content_dirty = false;
}

/**
 * Init
 */
WPSiteSyncContent_EDD.prototype.init = function()
{
	this.$push_button = jQuery('#sync-content');
	this.$pull_button = jQuery('#sync-pull-content');

	jQuery('input, textarea, select', 'form#post').on('change', function(e) { wpsitesynccontent.edd.form_change(e); } );
	this.inited = true;
};

/**
 * Callback used to detect content changes on the page
 */
WPSiteSyncContent_EDD.prototype.form_change = function()
{
console.log('form_change()');
	wpsitesynccontent.set_message(jQuery('#sync-msg-update-changes').html(), false, false, 'sync-error');
};

/**
 * Disables the Sync Push and Pull buttons after Content is edited
 */
WPSiteSyncContent_EDD.disable_sync = function()
{
console.log('disable_sync() - turning off the button');
	WPSiteSyncContent_EDD.content_dirty = true;
	$this.$push_button.addClass('sync-button-disable');
	$this.$pull_button.addClass('sync-button-disable');
};

/**
 * Enable the Sync Push and Pull buttons after Content changes are abandoned
 */
WPSiteSyncContent_EDD.enable_sync = function()
{
console.log('enable_sync() - turning on the button');
	WPSiteSyncContent_EDD.content_dirty = false;
	$this.$push_button.removeClass('sync-button-disable');
	$this.$pull_button.removeClass('sync-button-disable');
};

// create the instance of the Beaver Builder class
wpsitesynccontent.edd = new WPSiteSyncContent_EDD();

// initialize the WPSiteSync operation on page load
jQuery(document).ready(function()
{
	wpsitesynccontent.edd.init();
});

// EOF