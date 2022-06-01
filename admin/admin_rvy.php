<?php
/**
 * @package     PublishPress\Revisions\RevisionaryAdmin
 * @author      PublishPress <help@publishpress.com>
 * @copyright   Copyright (c) 2021 PublishPress. All rights reserved.
 * @license     GPLv2 or later
 * @since       1.0.0
 *
 * Scripts and filter / action handlers applicable for all wp-admin URLs
 *
 * Selectively load other classes based on URL
 */

if( isset($_SERVER['SCRIPT_FILENAME']) && (basename(__FILE__) == basename(esc_url_raw($_SERVER['SCRIPT_FILENAME']))) )
	die();

define ('RVY_URLPATH', plugins_url('', REVISIONARY_FILE));

class RevisionaryAdmin
{
	function __construct() {
		global $pagenow, $post;

		$script_name = (isset($_SERVER['SCRIPT_NAME'])) ? esc_url_raw($_SERVER['SCRIPT_NAME']) : '';

		add_action('admin_head', [$this, 'admin_head']);
		add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
		add_action('revisionary_admin_footer', [$this, 'publishpressFooter']);

		if ( ! defined('XMLRPC_REQUEST') && ! strpos($script_name, 'p-admin/async-upload.php' ) ) {
			if ( RVY_NETWORK && ( is_main_site() ) ) {
				require_once( dirname(__FILE__).'/admin_lib-mu_rvy.php' );
				add_action('admin_menu', 'rvy_mu_site_menu', 15 );
			}
			
			add_action('admin_menu', [$this, 'build_menu']);

			if ( strpos($script_name, 'p-admin/plugins.php') ) {
				add_filter( 'plugin_row_meta', [$this, 'flt_plugin_action_links'], 10, 2 );
			}
		}

		// ===== Special early exit if this is a plugin install script
		if ( strpos($script_name, 'p-admin/plugins.php') || strpos($script_name, 'p-admin/plugin-install.php') || strpos($script_name, 'p-admin/plugin-editor.php') ) {
			if (strpos($script_name, 'p-admin/plugin-install.php') && !empty(esc_url_raw($_SERVER['HTTP_REFERER'])) && strpos(esc_url_raw($_SERVER['HTTP_REFERER']), '=rvy')) {
				add_action('admin_print_scripts', function(){
					echo '<style type="text/css">#plugin_update_from_iframe {display:none;}</style>';
				});
			}

			return; // no further filtering on WP plugin maintenance scripts
		}

		if (in_array($pagenow, array('post.php', 'post-new.php'))) {
			if (empty($post)) {
				$post = get_post(rvy_detect_post_id());
			}

			if ($post && rvy_is_supported_post_type($post->post_type)) {
				// only apply revisionary UI for currently published or scheduled posts
				if (!rvy_in_revision_workflow($post) && (in_array($post->post_status, rvy_filtered_statuses()) || ('future' == $post->post_status))) {
					require_once( dirname(__FILE__).'/filters-admin-ui-item_rvy.php' );
					new RevisionaryPostEditorMetaboxes();

				} elseif (rvy_in_revision_workflow($post)) {
					add_action('the_post', array($this, 'limitRevisionEditorUI'));

					require_once( dirname(__FILE__).'/edit-revision-ui_rvy.php' );
					new RevisionaryEditRevisionUI();

					if (\PublishPress\Revisions\Utils::isBlockEditorActive($post->post_type)) {
						require_once( dirname(__FILE__).'/edit-revision-block-ui_rvy.php' );
						new RevisionaryEditRevisionBlockUI();
					} else {
						require_once( dirname(__FILE__).'/edit-revision-classic-ui_rvy.php' );
						new RevisionaryEditRevisionClassicUI();
					}
				}
			}
		}

		if ( ! ( defined( 'SCOPER_VERSION' ) || defined( 'PP_VERSION' ) || defined( 'PPCE_VERSION' ) ) || defined( 'USE_RVY_RIGHTNOW' ) ) {
			add_action('dashboard_glance_items', [$this, 'actDashboardGlanceItems']);
		}

		if ( rvy_get_option( 'pending_revisions' ) || rvy_get_option( 'scheduled_revisions' ) ) {
			if ('revision.php' == $pagenow) {
				require_once( dirname(__FILE__).'/history_rvy.php' );
				new RevisionaryHistory();
			}
		}

		if ( rvy_get_option( 'scheduled_revisions' ) ) {
			add_filter('dashboard_recent_posts_query_args', [$this, 'flt_dashboard_recent_posts_query_args']);
		}

		if (!empty($_REQUEST['page']) && ('cms-tpv-page-page' == $_REQUEST['page'])) {
			add_action('pre_get_posts', [$this, 'cmstpv_compat_get_posts']);
		}

		add_filter('presspermit_status_control_scripts', [$this, 'fltDisableStatusControlScripts']);

		add_filter('cme_plugin_capabilities', [$this, 'fltPublishPressCapsSection']);
	}

	function admin_scripts() {
		global $pagenow;

		if (in_array($pagenow, ['post.php', 'post-new.php', 'revision.php']) || (!empty($_REQUEST['page']) && in_array($_REQUEST['page'], ['revisionary-settings', 'rvy-net_options', 'rvy-default_options', 'revisionary-q']))) {
			wp_enqueue_style('revisionary', RVY_URLPATH . '/admin/revisionary.css', [], PUBLISHPRESS_REVISIONS_VERSION);
		}

		if (in_array($pagenow, ['post.php', 'post-new.php']) || (!empty($_REQUEST['page']) && in_array($_REQUEST['page'], ['revisionary-settings', 'rvy-net_options', 'rvy-default_options', 'revisionary-q']))) {
			wp_enqueue_style('revisionary-admin-common', RVY_URLPATH . '/common/css/pressshack-admin.css', [], PUBLISHPRESS_REVISIONS_VERSION);
		}

		if ((!empty($_REQUEST['page']) && in_array($_REQUEST['page'], ['revisionary-settings', 'rvy-net_options', 'rvy-default_options']))) {
			wp_enqueue_script('revisionary-settings', RVY_URLPATH . '/admin/settings.js', [], PUBLISHPRESS_REVISIONS_VERSION);
		}

		if (defined('PUBLISHPRESS_REVISIONS_PRO_VERSION') && ('admin.php' == $pagenow) && !empty($_REQUEST['page']) && in_array($_REQUEST['page'], ['revisionary-settings', 'rvy-net_options', 'rvy-default_options']) ) {
			wp_enqueue_style('revisionary-settings', RVY_URLPATH . '/includes-pro/settings-pro.css', [], PUBLISHPRESS_REVISIONS_VERSION);
		}
 	}

	function admin_head() {
		if ( isset($_SERVER['REQUEST_URI']) && (false !== strpos( urldecode(esc_url_raw($_SERVER['REQUEST_URI'])), 'admin.php?page=rvy-revisions' ))) {
			// legacy revision management UI for past revisions
			require_once( dirname(__FILE__).'/revision-ui_rvy.php' );
		}

		if ( ! defined('SCOPER_VERSION') ) {
			// old js for notification recipient selection UI
			wp_enqueue_script( 'rvy', RVY_URLPATH . "/admin/revisionary.js", array('jquery'), PUBLISHPRESS_REVISIONS_VERSION, true );
		}
	}

	function actDashboardGlanceItems($items) {
		require_once(dirname(__FILE__).'/admin-dashboard_rvy.php');
		RevisionaryDashboard::glancePending();
	}

	function moderation_queue() {
		require_once( dirname(__FILE__).'/revision-queue_rvy.php');
	}

	function build_menu() {
		global $current_user;

		if ( isset($_SERVER['REQUEST_URI']) && (strpos( esc_url_raw($_SERVER['REQUEST_URI']), 'wp-admin/network/' )) )
			return;

		$path = RVY_ABSPATH;

		// For Revisions Manager access, satisfy WordPress' demand that all admin links be properly defined in menu
		if (isset($_SERVER['REQUEST_URI']) && (false !== strpos( urldecode(esc_url_raw($_SERVER['REQUEST_URI'])), 'admin.php?page=rvy-revisions' )) ) {
			add_submenu_page( 'none', esc_html__('Revisions', 'revisionary'), esc_html__('Revisions', 'revisionary'), 'read', 'rvy-revisions', 'rvy_include_admin_revisions' );
		}

		if ($types = rvy_get_manageable_types()) {
			$can_edit_any = false;

			foreach ($types as $_post_type) {
				if ($type_obj = get_post_type_object($_post_type)) {
					if (!empty($current_user->allcaps[$type_obj->cap->edit_posts]) || (is_multisite() && is_super_admin())) {
						$can_edit_any = true;
						break;
					}
				}
			}

			if (apply_filters('revisionary_add_menu', $can_edit_any)) {
				$_menu_caption = ( defined( 'RVY_MODERATION_MENU_CAPTION' ) ) ? RVY_MODERATION_MENU_CAPTION : esc_html__('Revisions');
				add_menu_page( esc_html__($_menu_caption, 'pp'), esc_html__($_menu_caption, 'pp'), 'read', 'revisionary-q', array(&$this, 'moderation_queue'), 'dashicons-backup', 29 );

				add_submenu_page('revisionary-q', esc_html__('Revision Queue', 'revisionary'), esc_html__('Revision Queue', 'revisionary'), 'read', 'revisionary-q', [$this, 'moderation_queue']);
			}
		}

		if ( ! current_user_can( 'manage_options' ) )
			return;

		global $rvy_default_options, $rvy_options_sitewide;

		if ( empty($rvy_default_options) )
			rvy_refresh_default_options();

		if ( ! RVY_NETWORK || ( count($rvy_options_sitewide) != count($rvy_default_options) ) ) {
			add_submenu_page( 'revisionary-q', esc_html__('PublishPress Revisions Settings', 'revisionary'), esc_html__('Settings', 'revisionary'), 'read', 'revisionary-settings', 'rvy_omit_site_options');

			add_action('revisionary_page_revisionary-settings', 'rvy_omit_site_options' );
		}

		if (!defined('PUBLISHPRESS_REVISIONS_PRO_VERSION')) {
			add_submenu_page(
	            'revisionary-q',
	            esc_html__('Upgrade to Pro', 'revisionary'),
	            esc_html__('Upgrade to Pro', 'revisionary'),
	            'read',
	            'revisionary-pro',
	            'rvy_omit_site_options'
	        );
    	}
	}

	function limitRevisionEditorUI() {
		global $post;

		remove_post_type_support($post->post_type, 'author');
		remove_post_type_support($post->post_type, 'custom-fields'); // todo: filter post_id in query
	}

	function flt_dashboard_recent_posts_query_args($query_args) {
		if ('future' == $query_args['post_status']) {
			global $revisionary;
			$revisionary->is_revisions_query = true;

			require_once(dirname(__FILE__).'/admin-dashboard_rvy.php');
			$rvy_dash = new RevisionaryDashboard();
			$query_args = $rvy_dash->recentPostsQueryArgs($query_args);
		}

		return $query_args;
	}

	// adds a Settings link next to Deactivate, Edit in Plugins listing
	function flt_plugin_action_links($links, $file) {
		if ($file == plugin_basename(REVISIONARY_FILE)) {
			$page = ( defined('RVY_NETWORK') && RVY_NETWORK ) ? 'rvy-net_options' : 'revisionary-settings';
			$links[] = "<a href='admin.php?page=$page'>" . __awp('Settings') . "</a>";
		}

		return $links;
	}

	public function fltPublishPressCapsSection($section_caps) {
		$section_caps['PublishPress Revisions'] = ['edit_others_drafts', 'edit_others_revisions', 'list_others_revisions', 'manage_unsubmitted_revisions'];
		return $section_caps;
	}

	public function fltDisableStatusControlScripts($enable_scripts) {
		if ($post_id = rvy_detect_post_id()) {
			if ($post = get_post($post_id)) {
				if (!empty($post) && rvy_in_revision_workflow($post)) {
					$enable_scripts = false;
				}
			}
		}

		return $enable_scripts;
	}

	// Prevent PublishPress Revisions statuses from confusing the CMS Tree Page View plugin page listing
	public function cmstpv_compat_get_posts($wp_query) {
		$wp_query->query['post_mime_type'] = '';
		$wp_query->query_vars['post_mime_type'] = '';
	}

	public function publishpressFooter() {
		if (defined('PUBLISHPRESS_REVISIONS_PRO_VERSION') && !rvy_get_option('display_pp_branding')) {
			return;
		}

		?>
		<footer>

		<div class="pp-rating">
		<a href="https://wordpress.org/support/plugin/revisionary/reviews/#new-post" target="_blank" rel="noopener noreferrer">

		<?php printf(
			esc_html__('If you like %s, please leave us a %s rating. Thank you!', 'revisionary'),
			'<strong>PublishPress Revisions</strong>',
			'<span class="dashicons dashicons-star-filled"></span><span class="dashicons dashicons-star-filled"></span><span class="dashicons dashicons-star-filled"></span><span class="dashicons dashicons-star-filled"></span><span class="dashicons dashicons-star-filled"></span>'
			);
		?>
		</a>
		</div>

		<hr>
		<nav>
		<ul>
		<li><a href="https://publishpress.com/revisionary" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr('About PublishPress Revisions', 'revisionary');?>"><?php esc_html_e('About', 'revisionary');?>
		</a></li>
		<li><a href="https://publishpress.com/documentation/revisions-start" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr('PublishPress Revisions Documentation', 'revisionary');?>"><?php esc_html_e('Documentation', 'revisionary');?>
		</a></li>
		<li><a href="https://publishpress.com/contact" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr('Contact the PublishPress team', 'revisionary');?>"><?php esc_html_e('Contact', 'revisionary');?>
		</a></li>
		<li><a href="https://twitter.com/publishpresscom" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-twitter"></span>
		</a></li>
		<li><a href="https://facebook.com/publishpress" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-facebook"></span>
		</a></li>
		</ul>
		</nav>

		<div class="pp-pressshack-logo">
		<a href="https://publishpress.com" target="_blank" rel="noopener noreferrer">

		<img src="<?php echo esc_url(plugins_url('', REVISIONARY_FILE) . '/common/img/publishpress-logo.png');?>" />
		</a>
		</div>

		</footer>
		<?php
	}
} // end class RevisionaryAdmin
