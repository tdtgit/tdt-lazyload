<?php

if (!defined('ABSPATH')){
	exit;
}

use DiDom\Document;
use DiDom\Element;

class TDT_Lazyload{

	private $is_enabled;
	private $lazyload_class;

	function __construct(){
		$this->is_enabled     = FALSE;
		$this->lazyload_class = 'lozad';
	}

	/**
	 * Init function. Check conditional if need to load styles/scripts
	 */
	public function init(){
		/**
		 * Stop if on AMP page. They have built-in lazyload feature
		 */
		if ($this->is_amp()){
			return;
		}

		if ($this->is_enabled_on(get_post_type())){
			add_filter('the_content', [$this, 'replace']);
			$this->is_enabled = TRUE;
		}

		if ($this->is_enabled_for('widget')){
			add_filter('widget_text', [$this, 'replace_widget']);
			$this->is_enabled = TRUE;
		}

		if ($this->is_enabled_for('thumbnail')){
			add_filter('post_thumbnail_html', [$this, 'replace_post_thumbnail'], 99, 5);
			$this->is_enabled = TRUE;
		}

		if ($this->is_enabled_for('avatar')){
			add_filter('get_avatar', [$this, 'replace_avatar']);
			$this->is_enabled = TRUE;
		}

		/**
		 * Even one post type or widget, thumbnail is enable for lazyload
		 * we load styles/scripts we need
		 */
		if ($this->is_enabled){
			/**
			 * Yeah, load styles/scripts in first order, before jQuery because we don't it anyway
			 */
			add_action('wp_footer', [$this, 'load_scripts'], 0);
			add_filter('clean_url', [$this, 'async_script']);
		}
	}

	/**
	 * Check if lazyload enable on post, product, page, ... by get_post_type()
	 *
	 * @param string $post_type
	 *
	 * @return bool
	 */
	private function is_enabled_on($post_type){
		return in_array($post_type, array_keys(get_option('tdt_lazyload_display_on')));
	}

	/**
	 * Check if lazyload enable for widget, thumbnail by get_post_type()
	 *
	 * @param string $region
	 *
	 * @return bool
	 */
	private function is_enabled_for($region){
		if (isset(get_option('tdt_lazyload_enable_for')[$region])){
			return TRUE;
		}

		return FALSE;
	}

	/**
	 * Check if on AMP page
	 * Currently check by AMP plugin's function only.
	 *
	 * @return bool
	 */
	private function is_amp(){
		/**
		 * Plugin: AMP-WP Official (https://github.com/Automattic/amp-wp)
		 */
		if (function_exists('is_amp_endpoint')){
			return is_amp_endpoint();
		}
		/**
		 * Plugin: Better AMP (https://github.com/better-studio/better-amp)
		 */
		if (function_exists('is_better_amp')){
			return is_better_amp();
		}
		/**
		 * Plugin: AMP for WP – Accelerated Mobile Pages (https://github.com/ahmedkaludi/Accelerated-Mobile-Pages)
		 */
		if (function_exists('ampforwp_is_amp_endpoint') && ampforwp_is_amp_endpoint()){
			return ampforwp_is_amp_endpoint();
		}

		return FALSE;
	}

	/**
	 * Fallback from widget_text filter
	 *
	 * @param $text
	 *
	 * @return string
	 * @throws \DiDom\Exceptions\InvalidSelectorException
	 */
	public function replace_widget($text){
		return $this->replace($text);
	}

	/**
	 * Fallback from post_thumbnail_html filter
	 *
	 * @param $html
	 *
	 * @return string
	 * @throws \DiDom\Exceptions\InvalidSelectorException
	 */
	public function replace_post_thumbnail($html){
		return $this->replace($html);
	}

	/**
	 * Fallback from get_avatar filter
	 *
	 * @param $html
	 *
	 * @return string
	 * @throws \DiDom\Exceptions\InvalidSelectorException
	 */
	public function replace_avatar($html){
		return $this->replace($html);
	}

	/**
	 * Replace using regex
	 * Exclude:
	 *      - Image/Iframe have nolazyload class.
	 *      - Or not enabled ;)
	 *
	 * @param $html
	 *
	 * @return string
	 * @throws \DiDom\Exceptions\InvalidSelectorException
	 */
	public function replace($html){
		$doc = new Document($html);

		if (isset(get_option('tdt_lazyload_enable_for_advanced')['image']) && get_option('tdt_lazyload_enable_for_advanced')['image']){
			$excludeImageClass = $this->get_exclude_class('tdt_lazyload_exclude_image_with_class');

			$image_array = $doc->find('img');
			foreach ($image_array as $image){
				if ($image->classes()->contains($excludeImageClass)){
					continue;
				}

				/**
				 * Fallback if users not Javascript-enabled. Image without lazyload-effect will be display instead.
				 */
				$no_js_element = new Element('noscript', $image->outerHtml());
				$image->appendChild($no_js_element);

				$image->setAttribute('data-src', $image->getAttribute('src'));

				if ($image->hasAttribute('sizes')){
					$image->setAttribute('data-sizes', $image->getAttribute('sizes'));
				}

				if ($image->hasAttribute('srcset')){
					$image->setAttribute('data-srcset', $image->getAttribute('srcset'));
				}

				$image->classes()->add($this->lazyload_class);

				$image->removeAttribute('src');
				$image->removeAttribute('sizes');
				$image->removeAttribute('srcset');
			}
		}

		if (isset(get_option('tdt_lazyload_enable_for_advanced')['iframe']) && get_option('tdt_lazyload_enable_for_advanced')['iframe']){
			$excludeIframeClass = $this->get_exclude_class('tdt_lazyload_exclude_iframe_with_class');

			foreach ($doc->find('iframe') as $iframe){
				if ($iframe->classes()->contains($excludeIframeClass)){
					continue;
				}

				/**
				 * Fallback if users not Javascript-enabled. Iframe without lazyload-effect will be display instead.
				 */
				$no_js_element = new Element('noscript', $iframe->outerHtml());
				$iframe->appendChild($no_js_element);

				$iframe->setAttribute('data-src', $iframe->getAttribute('src'));

				$iframe->classes()->add($this->lazyload_class);

				$iframe->removeAttribute('src');
			}
		}

		return $doc->html();
	}

	/**
	 * Return user setting (Image/iframe) exclude class
	 * class1,class2,class3
	 *
	 * @param $option_name
	 *
	 * @return array|false|string[]
	 */
	function get_exclude_class($option_name){
		$exClass = get_option($option_name);
		$exClass = str_replace(' ', '', $exClass);

		return preg_split('/,/', $exClass, - 1, PREG_SPLIT_NO_EMPTY);
	}

	/**
	 * load_scripts: the name says it all ;)
	 */
	public function load_scripts(){
		wp_enqueue_script(
			'tdt-lazyload',
			TDT_LAZYLOAD_PLUGIN_DIR . 'assets/js/lozad.custom.min.js',
			'',
			NULL,
			FALSE
		);
	}

	public function async_script($url){
		if (strpos($url, 'lozad.custom.min.js')){
			return str_replace('lozad.custom.min.js', "lozad.custom.min.js' async='async", $url);
		}

		return $url;
	}
}

if (get_option('tdt_lazyload_disable', FALSE) == FALSE){
	$tdt_lazyload = new TDT_Lazyload();
	add_action('wp', [$tdt_lazyload, 'init']);
}
