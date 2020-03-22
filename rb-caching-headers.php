<?php
/**
 * Plugin Name: RB Caching headers
 * Plugin URI: https://roybongers.nl
 * Description: Add some optimalisations for caching get a higher hit rate for Varnish.
 * Version: 0.1
 * Author: Roy Bongers
 * Author URI: https://roybongers.nl
 */

class RB_Caching_Headers {

	/**
	 * RB_Caching constructor.
	 */
	public function __construct() {
		// add Cache-Control header.
		add_action( 'template_redirect', array( $this, 'add_cache_control_header' ) );

		// enable Etag header.
		if ( get_option( 'enable_etag', false ) ) {
			// set output buffing to capture all HTML.
			add_action( 'template_redirect', array( $this, 'start_etag_ob' ) );
			add_action( 'shutdown', array( $this, 'end_etag_ob' ), 0 );
		}

		// enable Last-Modified header.
		if ( get_option( 'enable_last_modified', false ) ) {
			add_action( 'template_redirect', array( $this, 'add_last_modified_header' ) );
		}

		// remove emoji's.
		if ( ! get_option( 'enable_emojis', true ) ) {
			add_action( 'init', array( $this, 'disable_emojis' ) );
		}

		// add plugin settings and admin menu's.
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );

	}

	/**
	 * Add Cache-Control header.
	 */
	public function add_cache_control_header() {
		if ( headers_sent() ) {
			return;
		}

		if ( is_user_logged_in() ) {
			$cache_control = 'no-cache, must-revalidate, max-age=0';
		} else {
			if ( is_home() || is_front_page() ) {
				// cache for max one hour.
				$expires = get_option( 'cache_control_homepage', 300 );
			} else if ( is_single() || is_page() ) {
				// single posts are almost static.
				$expires = get_option( 'cache_control_single', 300 );
			} elseif ( is_archive() ) {
				$expires = get_option( 'cache_control_archive', 300 );
			}
			else {
				$expires = get_option( 'cache_control_default', 300 );
			}
			$cache_control = 's-maxage=' . $expires;
		}
		header( 'Cache-Control: ' . $cache_control, true );
	}

	/**
	 * Start output buffing for Etag.
	 */
	public function start_etag_ob() {
		ob_start();
	}

	/**
	 * End output buffing for Etag and send header.
	 */
	public function end_etag_ob() {
		if ( headers_sent() ) {
			return;
		}

		$content = ob_get_clean();
		header( 'Etag: ' . md5($content), true );
		echo $content;
	}

	/**
	 * Sets the Last-Modified HTTP header if possible.
	 */
	public function add_last_modified_header() {
		if ( headers_sent() ) {
			return;
		}

		if ( is_single() || is_page() ) {
			$object = get_queried_object();
			if ( property_exists( $object, 'post_date_gmt' ) ) {
				header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s \G\M\T', strtotime( $object->post_date_gmt . 'UTC' ) ), true );
				return;
			}
		}
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s \G\M\T' ), true );
	}

	/**
	 * Remove all emoji actions.
	 */
	public function disable_emojis() {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'tiny_mce_plugins', array( $this, 'disable_emojis_tinymce' ) );
	}

	/**
	 * Filter function used to remove the tinymce emoji plugin.
	 *
	 * @param    array  $plugins
	 * @return   array             Difference betwen the two arrays
	 */
	public function disable_emojis_tinymce( $plugins ) {
		if ( is_array( $plugins ) ) {
			return array_diff( $plugins, array( 'wpemoji' ) );
		} else {
			return array();
		}
	}

	public function admin_menu() {
		add_options_page( 'Caching settings', 'Caching settings', 'manage_options', 'caching-optimalisations', array( $this, 'settings_page' ) );
	}

	public function settings_page() {
		?>
		<div class="wrap">
            <div id="icon-options-general" class="icon32"></div>
            <h1>Caching settings</h1>
            <form method="post" action="options.php">
				<?php
				do_settings_sections( 'caching-optimalisations' );
				settings_fields( 'caching-settings' );
				submit_button();
                ?>
			</form>
		</div>
		<?php
	}

	public function settings() {
		$settings = array(
			array(
				'section' => 'cache_control_section',
				'label' => 'Cache-Control settings',
				'settings' => array(
					'cache_control_homepage' => 'Home page',
					'cache_control_single' => 'Pages and single posts',
					'cache_control_archive' => 'Archives',
					'cache_control_default' => 'Default',
				)
			),
			array(
				'section' => 'others_section',
				'label' => 'Other settings',
				'settings' => array(
					'enable_etag' => 'Enable Etag header',
					'enable_last_modified' => 'Enable Last-Modified header',
					'enable_emojis' => 'Enable emoji\'s',
				)
			),
		);

		foreach ( $settings as $setting ) {
			//register our settings
			add_settings_section( $setting['section'], $setting['label'], array( $this, $setting['section'] ), 'caching-optimalisations' );

			foreach ( $setting['settings'] as $field => $label ) {
				add_settings_field( $field, $label, array( $this, $field ), 'caching-optimalisations', $setting['section'] );
				register_setting( 'caching-settings', $field );
			}
		}
	}

	public function cache_control_section() {
		echo 'This controls how long a caching service may cache the pages before it is considered stale. Sets a Cache-Control: s-maxage= header.';
	}

	public function __call( $method, $args ) {
		if ( false !== strpos( $method, 'cache_control_' ) || false !== strpos( $method, 'expires_' ) ) {
			$this->render_cache_control_option( $method );
		}
		if ( false !== strpos( $method, 'enable_' ) ) {
			$this->render_checkbox( $method );
		}
	}

	public function render_cache_control_option( $option_name ) {
		$options = array(
			0 => 'Never',
			300 => '5 minutes',
			600 => '10 minutes',
			1800 => '30 minutes',
			3600 => '1 hour',
			3600 * 4 => '4 hours',
			3600 * 12 => '12 hours',
			3600 * 24 => '24 hours',
		);
		?>
		<select name="<?php echo esc_attr( $option_name ); ?>" id="<?php echo esc_attr( $option_name ); ?>">
			<?php
			foreach ( $options as $option => $label ) {
				$selected = '';
				if ( $option == get_option( $option_name ) ) {
					$selected = ' selected="selected"';
				}
				?><option value="<?php echo esc_attr( $option ); ?>" <?php echo $selected; ?>><?php echo esc_html( $label ); ?></option><?php
			}
			?>
		</select>
		<?php
	}

	public function render_checkbox( $option_name ) {
		$checked = get_option( $option_name ) ? 'checked="checked"' : '';
		?>
		<label><input type="checkbox" name="<?php echo esc_attr( $option_name ); ?>" <?php echo $checked; ?> value="1"><?php echo esc_html( $label ); ?></label>
		<?php
	}
}
new RB_Caching_Headers();
