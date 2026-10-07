<?php
/**
 * Front-end SEO output and site-wide SEO settings.
 *
 * Always: search engine verification tags, custom per-post JSON-LD schema and
 * a custom robots.txt. Only without an SEO plugin (built-in mode): document
 * titles, meta descriptions, robots, canonical URLs, Open Graph and Twitter
 * tags, structured data, and keeping noindex content out of the sitemap.
 *
 * @package ViagentProduction
 */

namespace ViagentProduction\SEO;

use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * SEO head output and settings.
 */
class Head {

	const SETTINGS_OPTION = 'viagent_production_seo_settings';
	const ROBOTS_OPTION   = 'viagent_production_seo_robots_txt';

	/**
	 * Verification meta tag names.
	 */
	const VERIFICATION = array(
		'google'    => 'google-site-verification',
		'bing'      => 'msvalidate.01',
		'yandex'    => 'yandex-verification',
		'pinterest' => 'p:domain_verify',
		'baidu'     => 'baidu-site-verification',
	);

	/**
	 * Default site-wide settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'home_title'              => '',
			'home_description'        => '',
			'title_separator'         => '–',
			'default_social_image'    => '',
			'twitter_username'        => '',
			'organization_type'       => 'Organization',
			'organization_name'       => '',
			'organization_logo'       => '',
			'social_profiles'         => array(),
			'verification'            => array_fill_keys( array_keys( self::VERIFICATION ), '' ),
			'noindex_author_archives' => false,
			'noindex_date_archives'   => true,
			'log_404'                 => true,
		);
	}

	/**
	 * Current site-wide settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved                    = (array) get_option( self::SETTINGS_OPTION, array() );
		$settings                 = array_merge( self::defaults(), $saved );
		$settings['verification'] = array_merge( self::defaults()['verification'], (array) ( $saved['verification'] ?? array() ) );
		return $settings;
	}

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'wp_head', array( self::class, 'verification_tags' ), 1 );
		add_action( 'wp_head', array( self::class, 'custom_schema' ), 99 );
		add_filter( 'robots_txt', array( self::class, 'robots_txt' ), PHP_INT_MAX, 2 );

		if ( 'native' !== Store::provider() ) {
			return;
		}
		add_filter( 'document_title_separator', array( self::class, 'separator' ) );
		add_filter( 'pre_get_document_title', array( self::class, 'document_title' ), 20 );
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
		add_filter( 'get_canonical_url', array( self::class, 'canonical' ), 10, 2 );
		add_action( 'wp_head', array( self::class, 'meta_tags' ), 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( self::class, 'sitemap_posts' ) );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( self::class, 'sitemap_terms' ) );
		add_filter( 'wp_sitemaps_add_provider', array( self::class, 'sitemap_providers' ), 10, 2 );
	}

	/**
	 * Replaces title variables such as %%title%% and %%sitename%%.
	 *
	 * @param string $text  Text with variables.
	 * @param string $title Title of the current object.
	 * @return string
	 */
	public static function replace_vars( $text, $title = '' ) {
		$values = array(
			'title'     => $title,
			'sitename'  => get_bloginfo( 'name', 'display' ),
			'sitedesc'  => get_bloginfo( 'description', 'display' ),
			'tagline'   => get_bloginfo( 'description', 'display' ),
			'sep'       => self::settings()['title_separator'],
			'separator' => self::settings()['title_separator'],
			'year'      => wp_date( 'Y' ),
			'page'      => '',
		);
		// Yoast and SEOPress use %%name%%, Rank Math %name% and AIOSEO #name.
		$replacements = array(
			'#site_title'   => $values['sitename'],
			'#post_title'   => $title,
			'#separator_sa' => $values['sep'],
		);
		foreach ( $values as $name => $value ) {
			$replacements[ '%%' . $name . '%%' ] = $value;
			$replacements[ '%' . $name . '%' ]   = $value;
		}
		return trim( preg_replace( '/\s+/', ' ', strtr( $text, $replacements ) ) );
	}

	/**
	 * Prints search engine verification tags.
	 */
	public static function verification_tags() {
		foreach ( self::settings()['verification'] as $engine => $code ) {
			if ( '' !== (string) $code && isset( self::VERIFICATION[ $engine ] ) ) {
				printf( '<meta name="%s" content="%s" />' . "\n", esc_attr( self::VERIFICATION[ $engine ] ), esc_attr( $code ) );
			}
		}
	}

	/**
	 * Prints custom JSON-LD saved for the current post or page.
	 */
	public static function custom_schema() {
		if ( ! is_singular() ) {
			return;
		}
		$schema = (string) get_post_meta( get_queried_object_id(), Store::native_key( 'schema' ), true );
		if ( '' !== $schema && is_array( json_decode( $schema, true ) ) ) {
			echo '<script type="application/ld+json">' . wp_json_encode( json_decode( $schema, true ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n";
		}
	}

	/**
	 * Serves the custom robots.txt, when one is saved.
	 *
	 * @param string $output Default output.
	 * @param bool   $is_public Whether the site is public.
	 * @return string
	 */
	public static function robots_txt( $output, $is_public ) {
		$custom = (string) get_option( self::ROBOTS_OPTION, '' );
		if ( '' === trim( $custom ) || ! $is_public ) {
			return $output;
		}
		return $custom;
	}

	/**
	 * Title separator.
	 *
	 * @return string
	 */
	public static function separator() {
		return self::settings()['title_separator'];
	}

	/**
	 * Custom document title.
	 *
	 * @param string $title Title so far.
	 * @return string
	 */
	public static function document_title( $title ) {
		if ( is_front_page() && is_home() ) {
			$custom = self::settings()['home_title'];
			return '' !== $custom ? self::finish_title( self::replace_vars( $custom, get_bloginfo( 'name', 'display' ) ) ) : $title;
		}
		$object = get_queried_object();
		if ( $object instanceof WP_Post && is_singular() ) {
			$custom = Store::read( 'post', $object->ID )['title'];
			return '' !== $custom ? self::finish_title( self::replace_vars( $custom, get_the_title( $object ) ) ) : $title;
		}
		if ( $object instanceof WP_Term ) {
			$custom = Store::read( 'term', $object->term_id )['title'];
			return '' !== $custom ? self::finish_title( self::replace_vars( $custom, $object->name ) ) : $title;
		}
		return $title;
	}

	/**
	 * Applies the same filtering core's wp_get_document_title() applies to its
	 * own titles. Core echoes the pre_get_document_title value as-is, so the
	 * custom title must be escaped here.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	private static function finish_title( $title ) {
		return capital_P_dangit( esc_html( convert_chars( wptexturize( $title ) ) ) );
	}

	/**
	 * Meta description for the current page.
	 *
	 * @return string
	 */
	public static function description() {
		if ( is_front_page() && is_home() ) {
			$custom = self::settings()['home_description'];
			return '' !== $custom ? $custom : get_bloginfo( 'description' );
		}
		$object = get_queried_object();
		if ( $object instanceof WP_Post && is_singular() ) {
			$custom = Store::read( 'post', $object->ID )['description'];
			if ( '' !== $custom ) {
				return self::replace_vars( $custom, get_the_title( $object ) );
			}
			$text = has_excerpt( $object ) ? $object->post_excerpt : wp_strip_all_tags( strip_shortcodes( excerpt_remove_blocks( $object->post_content ) ) );
			return self::trim_text( $text, 155 );
		}
		if ( $object instanceof WP_Term ) {
			$custom = Store::read( 'term', $object->term_id )['description'];
			return '' !== $custom ? $custom : self::trim_text( wp_strip_all_tags( term_description( $object ) ), 155 );
		}
		return '';
	}

	/**
	 * Shortens text to a length at a word boundary.
	 *
	 * @param string $text   Text.
	 * @param int    $length Max characters.
	 * @return string
	 */
	public static function trim_text( $text, $length ) {
		$text = trim( preg_replace( '/\s+/', ' ', html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) ) );
		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $length - 1 );
		$pos = mb_strrpos( $cut, ' ' );
		return rtrim( false !== $pos ? mb_substr( $cut, 0, $pos ) : $cut, ' ,.;:' ) . '…';
	}

	/**
	 * Robots directives.
	 *
	 * @param array $robots Directives.
	 * @return array
	 */
	public static function robots( $robots ) {
		$settings = self::settings();
		$object   = get_queried_object();
		$fields   = null;
		if ( $object instanceof WP_Post && is_singular() ) {
			$fields = Store::read( 'post', $object->ID );
		} elseif ( $object instanceof WP_Term ) {
			$fields = Store::read( 'term', $object->term_id );
		}
		$noindex  = ! empty( $fields['noindex'] )
			|| ( is_author() && $settings['noindex_author_archives'] )
			|| ( is_date() && $settings['noindex_date_archives'] );
		$nofollow = ! empty( $fields['nofollow'] );

		if ( $noindex ) {
			$robots['noindex'] = true;
			unset( $robots['max-image-preview'] );
		}
		if ( $nofollow ) {
			$robots['nofollow'] = true;
		}
		if ( ! $noindex && get_option( 'blog_public' ) ) {
			$robots['max-image-preview'] = 'large';
		}
		return $robots;
	}

	/**
	 * Custom canonical URL for posts and pages.
	 *
	 * @param string  $url  Canonical URL.
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function canonical( $url, $post ) {
		$custom = Store::read( 'post', $post->ID )['canonical'];
		return '' !== $custom ? $custom : $url;
	}

	/**
	 * Prints description, canonical (archives), Open Graph, Twitter and JSON-LD.
	 */
	public static function meta_tags() {
		$settings    = self::settings();
		$object      = get_queried_object();
		$description = self::description();
		$fields      = array();
		$url         = '';
		$type        = 'website';
		$image       = '';

		if ( $object instanceof WP_Post && is_singular() ) {
			$fields = Store::read( 'post', $object->ID );
			$url    = wp_get_canonical_url( $object );
			$type   = 'post' === $object->post_type ? 'article' : 'website';
			$image  = $fields['social_image'] ? $fields['social_image'] : (string) get_the_post_thumbnail_url( $object, 'full' );
		} elseif ( $object instanceof WP_Term ) {
			$fields = Store::read( 'term', $object->term_id );
			$url    = $fields['canonical'] ? $fields['canonical'] : get_term_link( $object );
			if ( ! is_wp_error( $url ) && ! is_paged() ) {
				printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $url ) );
			}
		} elseif ( is_front_page() ) {
			$url = home_url( '/' );
		}
		if ( '' === $image ) {
			$image = $settings['default_social_image'] ? $settings['default_social_image'] : (string) get_site_icon_url( 512 );
		}

		if ( '' !== $description ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
		}

		$title   = ! empty( $fields['social_title'] ) ? $fields['social_title'] : wp_get_document_title();
		$og_desc = ! empty( $fields['social_description'] ) ? $fields['social_description'] : $description;
		$og_tags = array(
			'og:locale'      => get_locale(),
			'og:type'        => $type,
			'og:title'       => $title,
			'og:description' => $og_desc,
			'og:url'         => is_wp_error( $url ) ? '' : $url,
			'og:site_name'   => get_bloginfo( 'name' ),
			'og:image'       => $image,
		);
		if ( 'article' === $type ) {
			$og_tags['article:published_time'] = get_the_date( 'c', $object );
			$og_tags['article:modified_time']  = get_the_modified_date( 'c', $object );
		}
		foreach ( $og_tags as $property => $content ) {
			if ( '' !== (string) $content ) {
				printf( '<meta property="%s" content="%s" />' . "\n", esc_attr( $property ), esc_attr( $content ) );
			}
		}

		$twitter = array(
			'twitter:card'        => $image ? 'summary_large_image' : 'summary',
			'twitter:title'       => $title,
			'twitter:description' => $og_desc,
			'twitter:image'       => $image,
			'twitter:site'        => $settings['twitter_username'] ? '@' . ltrim( $settings['twitter_username'], '@' ) : '',
		);
		foreach ( $twitter as $name => $content ) {
			if ( '' !== (string) $content ) {
				printf( '<meta name="%s" content="%s" />' . "\n", esc_attr( $name ), esc_attr( $content ) );
			}
		}

		echo '<script type="application/ld+json">' . wp_json_encode( self::graph( $object, $description, $image ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n";
	}

	/**
	 * Structured data graph for the current page.
	 *
	 * @param mixed  $queried       Queried object.
	 * @param string $description Description.
	 * @param string $image       Image URL.
	 * @return array
	 */
	private static function graph( $queried, $description, $image ) {
		$settings  = self::settings();
		$home      = home_url( '/' );
		$publisher = array(
			'@type' => 'Person' === $settings['organization_type'] ? 'Person' : 'Organization',
			'@id'   => $home . '#publisher',
			'name'  => $settings['organization_name'] ? $settings['organization_name'] : get_bloginfo( 'name' ),
			'url'   => $home,
		);
		$logo      = $settings['organization_logo'] ? $settings['organization_logo'] : (string) get_site_icon_url( 512 );
		if ( $logo ) {
			$publisher[ 'Person' === $settings['organization_type'] ? 'image' : 'logo' ] = $logo;
		}
		if ( $settings['social_profiles'] ) {
			$publisher['sameAs'] = array_values( (array) $settings['social_profiles'] );
		}

		$graph = array(
			array(
				'@type'           => 'WebSite',
				'@id'             => $home . '#website',
				'url'             => $home,
				'name'            => get_bloginfo( 'name' ),
				'description'     => get_bloginfo( 'description' ),
				'publisher'       => array( '@id' => $home . '#publisher' ),
				'potentialAction' => array(
					'@type'       => 'SearchAction',
					'target'      => home_url( '/?s={search_term_string}' ),
					'query-input' => 'required name=search_term_string',
				),
			),
			$publisher,
		);

		if ( $queried instanceof WP_Post && is_singular() ) {
			$url  = wp_get_canonical_url( $queried );
			$page = array(
				'@type'         => 'post' === $queried->post_type ? 'BlogPosting' : 'WebPage',
				'@id'           => $url . '#main',
				'url'           => $url,
				'headline'      => get_the_title( $queried ),
				'description'   => $description,
				'datePublished' => get_the_date( 'c', $queried ),
				'dateModified'  => get_the_modified_date( 'c', $queried ),
				'isPartOf'      => array( '@id' => $home . '#website' ),
				'publisher'     => array( '@id' => $home . '#publisher' ),
				'inLanguage'    => get_bloginfo( 'language' ),
			);
			if ( 'post' === $queried->post_type ) {
				$page['author']           = array(
					'@type' => 'Person',
					'name'  => get_the_author_meta( 'display_name', $queried->post_author ),
					'url'   => get_author_posts_url( $queried->post_author ),
				);
				$page['mainEntityOfPage'] = $url;
			}
			if ( $image ) {
				$page['image'] = $image;
			}
			$graph[] = $page;
		}

		return array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);
	}

	/**
	 * Leaves noindex posts out of the core sitemap.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public static function sitemap_posts( $args ) {
		$args['meta_query'] = isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- sitemap pages are cached and paged.
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- sitemap pages are cached and paged.
		$args['meta_query'][] = array(
			'key'     => Store::native_key( 'noindex' ),
			'compare' => 'NOT EXISTS',
		);
		return $args;
	}

	/**
	 * Leaves noindex terms out of the core sitemap.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public static function sitemap_terms( $args ) {
		$args['meta_query'] = isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- sitemap pages are cached and paged.
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- sitemap pages are cached and paged.
		$args['meta_query'][] = array(
			'key'     => Store::native_key( 'noindex' ),
			'compare' => 'NOT EXISTS',
		);
		return $args;
	}

	/**
	 * Drops the user sitemap when author archives are noindex.
	 *
	 * @param mixed  $provider Sitemap provider.
	 * @param string $name     Provider name.
	 * @return mixed
	 */
	public static function sitemap_providers( $provider, $name ) {
		return 'users' === $name && self::settings()['noindex_author_archives'] ? false : $provider;
	}

	/**
	 * Address of the XML sitemap for the active provider.
	 *
	 * @return string|null
	 */
	public static function sitemap_url() {
		switch ( Store::provider() ) {
			case 'yoast':
			case 'rankmath':
				return home_url( '/sitemap_index.xml' );
			case 'seopress':
				return home_url( '/sitemaps.xml' );
			case 'aioseo':
			case 'tsf':
				return home_url( '/sitemap.xml' );
		}
		return wp_sitemaps_get_server()->sitemaps_enabled() ? home_url( '/wp-sitemap.xml' ) : null;
	}
}
