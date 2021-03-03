<?php
/**
 * @package koko-analytics
 * @license GPL-3.0+
 * @author Danny van Kooten
 */
namespace KokoAnalytics;

use WP_User;

class Script_Loader {

	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_script' ) );
		add_action( 'amp_print_analytics', array( $this, 'print_amp_analytics_tag' ) );
	}

	/**
	 * @param bool $echo Whether to use the default WP script enqueue method or print the script tag directly
	 */
	public function maybe_enqueue_script( $echo = false ) {
		/**
		 * Allows short-circuiting this function to not load the tracking script using some custom logic.
		 * @param bool
		 */
		$load_script = apply_filters( 'koko_analytics_load_tracking_script', true );
		if ( false === $load_script ) {
			return;
		}

		// Do not load script if excluding current user by role
		$settings = get_settings();
		if ( count( $settings['exclude_user_roles'] ) > 0 ) {
			$user = wp_get_current_user();

			if ( $user->exists() && $this->user_has_roles( $user, $settings['exclude_user_roles'] ) ) {
				return;
			}
		}

		// TODO: Handle "term" requests so we track both terms and post types.
		add_filter( 'script_loader_tag', array( $this, 'add_async_attribute' ), 20, 2 );

		if ( false === $echo ) {
			// Print configuration object early on in the HTML so scripts can modify it
			if ( did_action( 'wp_head' ) ) {
				$this->print_js_object();
			} else {
				add_action( 'wp_head', array( $this, 'print_js_object' ), 1 );
			}

			// Enqueue the actual tracking script (in footer, if possible)
			wp_enqueue_script( 'koko-analytics', plugins_url( 'assets/dist/js/script.js', KOKO_ANALYTICS_PLUGIN_FILE ), array(), KOKO_ANALYTICS_VERSION, true );
		} else {
			$this->print_js_object();
			echo '<script src="', plugins_url( sprintf( 'assets/dist/js/script.js?ver=%s', KOKO_ANALYTICS_VERSION ), KOKO_ANALYTICS_PLUGIN_FILE ), '" async="async"></script>';
		}

	}

	private function get_post_id() {
		return is_singular() ? get_queried_object_id() : 0;
	}

	private function get_tracker_url() {
		// We should use site_url() here because we place the file in ABSPATH and other plugins may be filtering home_url (eg multilingual plugin)
		// In any case: what we use here should match what we test when creating the optimized endpoint file.
		return using_custom_endpoint() ? site_url( '/koko-analytics-collect.php' ) : admin_url( 'admin-ajax.php?action=koko_analytics_collect' );
	}

	private function get_cookie_path() {
		$home_url = get_home_url();
		// 8 characters for protocol
		// 1 or more characters for domain name
		// = 9 char offset
		$pos = strpos( $home_url, '/', 9 );
		return $pos !== false ? substr( $home_url, $pos ) : '/';
	}

	public function print_js_object() {
		$settings = get_settings();
		$script_config         = array(
			'tracker_url'   => $this->get_tracker_url(),
			'post_id'       => (int) $this->get_post_id(),
			'use_cookie'    => (int) $settings['use_cookie'],
			'cookie_path' => $this->get_cookie_path(),
			'honor_dnt' => apply_filters( 'koko_analytics_honor_dnt', true ),
		);
		echo '<script>window.koko_analytics = ', json_encode( $script_config ), ';</script>';
	}

	public function print_amp_analytics_tag() {
		$settings = get_settings();
		$post_id = $this->get_post_id();
		$tracker_url = $this->get_tracker_url();
		$posts_viewed = isset( $_COOKIE['_koko_analytics_pages_viewed'] ) ? explode( ',', $_COOKIE['_koko_analytics_pages_viewed'] ) : array();
		$data = array(
			'sc' => $settings['use_cookie'], // inform tracker endpoint to set cookie server-side
			'nv' => $posts_viewed === array() ? 1 : 0,
			'up' => ! in_array( $post_id, $posts_viewed ) ? 1 : 0,
			'p' => $post_id,
		);
		$url = add_query_arg( $data, $tracker_url );
		$config = array(
			'requests' => array(
				'pageview' => $url,
			),
			'triggers' => array(
				'trackPageview' => array(
					'on' => 'visible',
					'request' => 'pageview',
				),
			),
		);
		echo sprintf( '<amp-analytics><script type="application/json">%s</script></amp-analytics>', json_encode( $config ) );
	}

	public function add_async_attribute( $tag, $handle ) {
		if ( $handle !== 'koko-analytics' ) {
			return $tag;
		}

		return str_replace( ' src', ' async="async" src', $tag );
	}

	public function user_has_roles( WP_User $user, array $roles ) {
		foreach ( $user->roles as $user_role ) {
			if ( in_array( $user_role, $roles, true ) ) {
				return true;
			}
		}

		return false;
	}
}
