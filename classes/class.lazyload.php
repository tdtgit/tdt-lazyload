<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( class_exists( 'simple_html_dom' ) === false ) {
	require TDT_LAZYLOAD_PLUGIN_PATH . 'classes/external/simple_html_dom.php';
}

class TDT_Lazyload {

	private $is_enable;
	private $lazyload_class;

	function __construct() {
		$this->is_enable = false;
		$this->lazyload_class = 'lozad';
	}

	/**
	 * Init function. Check conditional if need to load styles/scripts
	 */
	public function init() {
		/**
		 * Stop if on AMP page. They have built-in lazyload feature
		 */
		if ( $this->is_amp() ) {
			return;
		}

		if ( $this->is_enable_on( get_post_type() ) ) {
			add_filter( 'the_content', array( $this, 'replace' ) );
			$this->is_enable = true;
		}

		if ( $this->is_enable_for( 'widget' ) ) {
			add_filter( 'widget_text', array( $this, 'replace_widget' ) );
			$this->is_enable = true;
		}

		if ( $this->is_enable_for( 'thumbnail' ) ) {
			add_filter( 'post_thumbnail_html', array( $this, 'replace_post_thumbnail' ), 99, 5 );
			$this->is_enable = true;
		}

		if ( $this->is_enable_for( 'avatar' ) ) {
			add_filter( 'get_avatar', array( $this, 'replace_avatar' ) );
			$this->is_enable = true;
		}

		/**
		 * Even one post type or widget, thumbnail is enable for lazyload
		 * we load styles/scripts we need
		 */
		if ( $this->is_enable ) {
			/**
			 * Yeah, load styles/scripts in first order, before jQuery because we don't it anyway
			 */
			add_action( 'wp_footer', array( $this, 'load_scripts' ), 0 );
			add_filter( 'clean_url', array( $this, 'async_script' ) );
		}
	}

	/**
	 * Check if lazyload enable on post, product, page,... by get_post_type()
	 *
	 * @param string $post_type
	 * @return bool
	 */
	private function is_enable_on( $post_type ) {
		return in_array( $post_type, array_keys( get_option( 'tdt_lazyload_display_on' ) ) );
	}

	/**
	 * Check if lazyload enable for widget, thumbnail by get_post_type()
	 *
	 * @param string $region
	 * @return bool
	 */
	private function is_enable_for( $region ) {
		if ( isset( get_option( 'tdt_lazyload_enable_for' )[ $region ] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Check if on AMP page
	 * Currently check by AMP plugin's function only.
	 *
	 * @return bool
	 */
	private function is_amp() {
		/**
		 * Plugin: AMP-WP Official (https://github.com/Automattic/amp-wp)
		 */
		if ( function_exists( 'is_amp_endpoint' ) ) {
			return is_amp_endpoint();
		}
		/**
		 * Plugin: Better AMP (https://github.com/better-studio/better-amp)
		 */
		if ( function_exists( 'is_better_amp' ) ) {
			return is_better_amp();
		}
		/**
		 * Plugin: AMP for WP – Accelerated Mobile Pages (https://github.com/ahmedkaludi/Accelerated-Mobile-Pages)
		 */
		if ( function_exists( 'ampforwp_is_amp_endpoint' ) && ampforwp_is_amp_endpoint() ) {
			return ampforwp_is_amp_endpoint();
		}
	}

	/**
	 * Fallback from widget_text filter
	 */
	public function replace_widget( $text ) {
		return $this->replace( $text );
	}

	/**
	 * Fallback from post_thumbnail_html filter
	 */
	public function replace_post_thumbnail( $html ) {
		return $this->replace( $html );
	}

	/**
	 * Fallback from get_avatar filter
	 */
	public function replace_avatar( $html ) {
		return $this->replace( $html );
	}

	/**
	 * Replace using regex
	 * Exclude:
	 *      - Image/Iframe have nolazyload class.
	 *      - Or not enabled ;)
	 *
	 * @param string $str
	 * @return string
	 */
	public function replace( $str ) {
		if ( empty( $str ) ) {
			return $str;
		}

		$html = str_get_html($str, $lowercase=true, $forceTagsClosed=true, $target_charset = DEFAULT_TARGET_CHARSET, $stripRN=false, $defaultBRText=DEFAULT_BR_TEXT, $defaultSpanText=DEFAULT_SPAN_TEXT);

		if ( isset( get_option( 'tdt_lazyload_enable_for_advanced' )['image'] ) && get_option( 'tdt_lazyload_enable_for_advanced' )['image'] ) {
			$excludeImageClass = $this->get_exclude_class( 'tdt_lazyload_exclude_image_with_class' );
			$image_array = $html->find( 'img' );
			foreach ( $image_array as $image ) {
				
				if ( $this->strposa( $image->class, $excludeImageClass ) ) {
					continue;
				}
				
				/**
				 * Fallback if users not Javascript-enabled. Image without lazyload-effect will be display instead.
				 */
				$nojs = '<noscript>' . $image . '</noscript>';
				
				$image->{'data-src'} = $image->src;
				
				$image->src = 'data:image/png;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=';

				if ( isset( $image->sizes ) ) {
					$image->{'data-sizes'} = $image->sizes;
				}

				if ( isset( $image->srcset ) ) {
					$image->{'data-srcset'} = $image->srcset;
				}

				/**
				 * Add lazyload class to image
				 * Upcoming feature: Add custom class to image before/after lazyload
				 */
				$image->class = $this->lazyload_class . ' ' . $image->class;

				/**
				 * Destroy below attribute
				 * If you only destroy src attribute, browser still using srcset attribute, plugin will not work.
				 */
				// $image->src    = null;
				$image->sizes  = null;
				$image->srcset = null;

				$image->outertext = $image->outertext . $nojs;
			}
		}

		if ( isset( get_option( 'tdt_lazyload_enable_for_advanced' )['iframe'] ) && get_option( 'tdt_lazyload_enable_for_advanced' )['iframe'] ) {
			$excludeIframeClass = $this->get_exclude_class( 'tdt_lazyload_exclude_iframe_with_class' );
			foreach ( $html->find( 'iframe' ) as $iframe ) {
				if ( $this->strposa( $iframe->class, $excludeIframeClass ) ) {
					continue;
				}

				$iframe->{'data-src'} = $iframe->src;

				/**
				 * Fallback if users not Javascript-enabled. Iframe without lazyload-effect will be display instead.
				 */
				$nojs = '<noscript>' . $iframe . '</noscript>';

				/**
				 * Add lazyload class to iframe
				 * Upcoming feature: Add custom class to iframe before/after lazyload
				 */
				$iframe->class = $this->lazyload_class . ' ' . $iframe->class;

				/**
				 * Destroy below attribute
				 */
				$iframe->src = null;

				$iframe->outertext = $iframe->outertext . $nojs;
			}
		}
		return $html;
	}

	/**
	 * Return user setting (Image/iframe) exclude class
	 * class1,class2,class3
	 */
	function get_exclude_class( $option_name ) {
		$exClass = get_option( $option_name );
		$exClass = str_replace( ' ', '', $exClass );
		return preg_split( '/,/', $exClass, -1, PREG_SPLIT_NO_EMPTY );
	}

	// https://stackoverflow.com/questions/6284553/using-an-array-as-needles-in-strpos
	function strposa( $haystack, $needle, $offset = 0 ) {
		if ( ! is_array( $needle ) ) {
			$needle = array( $needle );
		}
		foreach ( $needle as $query ) {
			if ( strpos( $haystack, $query, $offset ) !== false ) {
				return true; // stop on first true result
			}
		}
		return false;
	}

	/**
	 * load_scripts: the name says it all ;)
	 */
	public function load_scripts() {
		wp_enqueue_script(
			'tdt-lazyload',
			TDT_LAZYLOAD_PLUGIN_DIR . 'assets/js/lozad.custom.js',
			'',
			null,
			false
		);
	}

	public function async_script( $url ) {
		if ( strpos( $url, 'lozad.custom.min.js' ) ) {
			return str_replace( 'lozad.custom.min.js', "lozad.custom.min.js' async='async", $url );
		}
		return $url;
	}
}

if ( get_option( 'tdt_lazyload_disable', false ) == false ) {
	$tdt_lazyload = new TDT_Lazyload();
	add_action( 'wp', array( $tdt_lazyload, 'init' ) );
}
