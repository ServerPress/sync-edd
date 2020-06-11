<?php

if (!class_exists('SyncEddAdmin', FALSE)) {
	class SyncEddAdmin
	{
		public function __construct()
		{
			add_action('admin_init', array($this, 'admin_init'));
		}

		public function admin_init()
		{
//SyncDebug::log(__METHOD__.'():' . __LINE__);
			global $pagenow;
			if ('post.php' !== $pagenow)
				return;

			// TODO: extend SyncInput
			$input = new SyncInput();
			$post_id = $input->get_int('post', 0);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post id=' . $post_id);
			if (0 !== $post_id) {
				// TODO: license checks
				$post = get_post($post_id);
				if ('download' === $post->post_type)
					add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
			}
		}

		public function enqueue_scripts()
		{
//SyncDebug::log(__METHOD__.'():' . __LINE__);
			wp_register_script('sync-edd-admin', plugin_dir_url(dirname(__FILE__)) . '/assets/js/sync-edd-admin.js',
				array('jquery', 'sync'),
				WPSiteSync_EDD::PLUGIN_VERSION, TRUE);
			wp_enqueue_script('sync-edd-admin');
		}
	}
}
