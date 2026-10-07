<?php
/**
 * Reads and writes per-post and per-term SEO data in the format of whichever
 * SEO plugin is active (Yoast SEO, Rank Math, All in One SEO, SEOPress or The
 * SEO Framework). Without one, Viagent Production stores the data itself and
 * prints the tags (see Head).
 *
 * Every field is normalized: title, description, focus_keyword, canonical,
 * noindex, nofollow, social_title, social_description, social_image and
 * schema (custom JSON-LD, always stored by Viagent Production).
 *
 * @package ViagentProduction
 */

namespace ViagentProduction\SEO;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * SEO data store.
 */
class Store {

	const NATIVE_PREFIX = '_viagent_production_seo_';

	/**
	 * Normalized post fields.
	 */
	const POST_FIELDS = array( 'title', 'description', 'focus_keyword', 'canonical', 'noindex', 'nofollow', 'social_title', 'social_description', 'social_image', 'schema' );

	/**
	 * Normalized term fields.
	 */
	const TERM_FIELDS = array( 'title', 'description', 'focus_keyword', 'canonical', 'noindex' );

	/**
	 * Boolean fields.
	 */
	const BOOLEANS = array( 'noindex', 'nofollow' );

	/**
	 * Post meta keys per provider. Fields missing from a map (or set to null)
	 * are kept in Viagent Production's own meta.
	 */
	const POST_META = array(
		'yoast'    => array(
			'title'              => '_yoast_wpseo_title',
			'description'        => '_yoast_wpseo_metadesc',
			'focus_keyword'      => '_yoast_wpseo_focuskw',
			'canonical'          => '_yoast_wpseo_canonical',
			'noindex'            => '_yoast_wpseo_meta-robots-noindex',
			'nofollow'           => '_yoast_wpseo_meta-robots-nofollow',
			'social_title'       => '_yoast_wpseo_opengraph-title',
			'social_description' => '_yoast_wpseo_opengraph-description',
			'social_image'       => '_yoast_wpseo_opengraph-image',
		),
		'rankmath' => array(
			'title'              => 'rank_math_title',
			'description'        => 'rank_math_description',
			'focus_keyword'      => 'rank_math_focus_keyword',
			'canonical'          => 'rank_math_canonical_url',
			'noindex'            => 'rank_math_robots',
			'nofollow'           => 'rank_math_robots',
			'social_title'       => 'rank_math_facebook_title',
			'social_description' => 'rank_math_facebook_description',
			'social_image'       => 'rank_math_facebook_image',
		),
		'seopress' => array(
			'title'              => '_seopress_titles_title',
			'description'        => '_seopress_titles_desc',
			'focus_keyword'      => '_seopress_analysis_target_kw',
			'canonical'          => '_seopress_robots_canonical',
			'noindex'            => '_seopress_robots_index',
			'nofollow'           => '_seopress_robots_follow',
			'social_title'       => '_seopress_social_fb_title',
			'social_description' => '_seopress_social_fb_desc',
			'social_image'       => '_seopress_social_fb_img',
		),
		'tsf'      => array(
			'title'              => '_genesis_title',
			'description'        => '_genesis_description',
			'canonical'          => '_genesis_canonical_uri',
			'noindex'            => '_genesis_noindex',
			'nofollow'           => '_genesis_nofollow',
			'social_title'       => '_open_graph_title',
			'social_description' => '_open_graph_description',
			'social_image'       => '_social_image_url',
		),
	);

	/**
	 * Term meta keys per provider (Yoast and The SEO Framework keep term SEO
	 * in a single option or meta array and are handled separately).
	 */
	const TERM_META = array(
		'rankmath' => array(
			'title'         => 'rank_math_title',
			'description'   => 'rank_math_description',
			'focus_keyword' => 'rank_math_focus_keyword',
			'canonical'     => 'rank_math_canonical_url',
			'noindex'       => 'rank_math_robots',
		),
		'seopress' => array(
			'title'       => '_seopress_titles_title',
			'description' => '_seopress_titles_desc',
			'canonical'   => '_seopress_robots_canonical',
			'noindex'     => '_seopress_robots_index',
		),
	);

	/**
	 * Yoast taxonomy meta keys.
	 */
	const YOAST_TERM = array(
		'title'         => 'wpseo_title',
		'description'   => 'wpseo_desc',
		'focus_keyword' => 'wpseo_focuskw',
		'canonical'     => 'wpseo_canonical',
		'noindex'       => 'wpseo_noindex',
	);

	/**
	 * The SEO Framework term settings keys.
	 */
	const TSF_TERM = array(
		'title'       => 'doctitle',
		'description' => 'description',
		'canonical'   => 'canonical',
		'noindex'     => 'noindex',
	);

	/**
	 * Active SEO plugin: yoast, rankmath, aioseo, seopress, tsf or native.
	 *
	 * @return string
	 */
	public static function provider() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return 'rankmath';
		}
		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			return 'aioseo';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'seopress';
		}
		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) || function_exists( 'the_seo_framework' ) ) {
			return 'tsf';
		}
		return 'native';
	}

	/**
	 * Display name of the active provider.
	 *
	 * @return string
	 */
	public static function provider_name() {
		$names = array(
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
			'aioseo'   => 'All in One SEO',
			'seopress' => 'SEOPress',
			'tsf'      => 'The SEO Framework',
			'native'   => 'Viagent Production (built-in SEO)',
		);
		return $names[ self::provider() ];
	}

	/**
	 * Reads the normalized SEO fields of a post or term.
	 *
	 * @param string $type "post" or "term".
	 * @param int    $id   Object ID.
	 * @return array
	 */
	public static function read( $type, $id ) {
		return 'term' === $type ? self::read_term( (int) $id ) : self::read_post( (int) $id );
	}

	/**
	 * Writes normalized SEO fields. Only the given fields change; empty
	 * strings (or false for booleans) reset a field to the default.
	 *
	 * @param string $type   "post" or "term".
	 * @param int    $id     Object ID.
	 * @param array  $fields Normalized fields.
	 * @return true|WP_Error
	 */
	public static function write( $type, $id, array $fields ) {
		$result = 'term' === $type ? self::write_term( (int) $id, $fields ) : self::write_post( (int) $id, $fields );
		if ( ! is_wp_error( $result ) ) {
			self::refresh( $type, (int) $id );
		}
		return $result;
	}

	/**
	 * Cleans a single field value.
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 * @return mixed|WP_Error
	 */
	public static function sanitize( $field, $value ) {
		if ( in_array( $field, self::BOOLEANS, true ) ) {
			return (bool) $value;
		}
		if ( in_array( $field, array( 'canonical', 'social_image' ), true ) ) {
			return esc_url_raw( (string) $value );
		}
		if ( 'schema' === $field ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				return '';
			}
			$decoded = json_decode( $value, true );
			if ( ! is_array( $decoded ) ) {
				return new WP_Error( 'viagent_production_invalid_schema', __( 'schema must be valid JSON-LD (a JSON object or array), without a script tag.', 'viagent-production' ) );
			}
			return wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		return sanitize_text_field( (string) $value );
	}

	/**
	 * Native meta key for a field.
	 *
	 * @param string $field Field.
	 * @return string
	 */
	public static function native_key( $field ) {
		return self::NATIVE_PREFIX . $field;
	}

	/**
	 * Reads post fields.
	 *
	 * @param int $id Post ID.
	 * @return array
	 */
	private static function read_post( $id ) {
		$provider = self::provider();
		if ( 'aioseo' === $provider ) {
			$values = self::read_aioseo( $id );
		} else {
			$map    = self::POST_META[ $provider ] ?? array();
			$values = array();
			foreach ( self::POST_FIELDS as $field ) {
				$key              = $map[ $field ] ?? self::native_key( $field );
				$values[ $field ] = self::decode( $provider, $field, get_post_meta( $id, $key, true ), isset( $map[ $field ] ) );
			}
		}
		$values['schema'] = (string) get_post_meta( $id, self::native_key( 'schema' ), true );
		if ( ! isset( $values['focus_keyword'] ) ) {
			$values['focus_keyword'] = (string) get_post_meta( $id, self::native_key( 'focus_keyword' ), true );
		}
		return self::ordered( $values, self::POST_FIELDS );
	}

	/**
	 * Writes post fields.
	 *
	 * @param int   $id     Post ID.
	 * @param array $fields Fields.
	 * @return true|WP_Error
	 */
	private static function write_post( $id, array $fields ) {
		$provider = self::provider();

		if ( array_key_exists( 'schema', $fields ) ) {
			self::put_meta( 'post', $id, self::native_key( 'schema' ), $fields['schema'] );
			unset( $fields['schema'] );
		}

		if ( 'aioseo' === $provider ) {
			return $fields ? self::write_aioseo( $id, $fields ) : true;
		}

		$map = self::POST_META[ $provider ] ?? array();
		foreach ( $fields as $field => $value ) {
			if ( isset( $map[ $field ] ) ) {
				self::put_meta( 'post', $id, $map[ $field ], self::encode( $provider, $field, $value, 'post', $id ) );
				if ( 'social_image' === $field && in_array( $provider, array( 'yoast', 'rankmath' ), true ) ) {
					$image_key = 'yoast' === $provider ? '_yoast_wpseo_opengraph-image-id' : 'rank_math_facebook_image_id';
					self::put_meta( 'post', $id, $image_key, $value ? (string) attachment_url_to_postid( $value ) : '' );
				}
			} else {
				self::put_meta( 'post', $id, self::native_key( $field ), is_bool( $value ) ? ( $value ? '1' : '' ) : $value );
			}
		}
		return true;
	}

	/**
	 * Reads term fields.
	 *
	 * @param int $id Term ID.
	 * @return array
	 */
	private static function read_term( $id ) {
		$provider = self::provider();
		$values   = array();
		$term     = get_term( $id );

		if ( 'yoast' === $provider && $term && ! is_wp_error( $term ) ) {
			$all  = (array) get_option( 'wpseo_taxonomy_meta', array() );
			$meta = (array) ( $all[ $term->taxonomy ][ $id ] ?? array() );
			foreach ( self::YOAST_TERM as $field => $key ) {
				$value            = $meta[ $key ] ?? '';
				$values[ $field ] = 'noindex' === $field ? 'noindex' === $value : (string) $value;
			}
		} elseif ( 'tsf' === $provider ) {
			$meta = (array) get_term_meta( $id, 'autodescription-term-settings', true );
			foreach ( self::TSF_TERM as $field => $key ) {
				$values[ $field ] = 'noindex' === $field ? ! empty( $meta[ $key ] ) : (string) ( $meta[ $key ] ?? '' );
			}
		} elseif ( isset( self::TERM_META[ $provider ] ) ) {
			foreach ( self::TERM_META[ $provider ] as $field => $key ) {
				$values[ $field ] = self::decode( $provider, $field, get_term_meta( $id, $key, true ), true );
			}
		}

		foreach ( self::TERM_FIELDS as $field ) {
			if ( ! isset( $values[ $field ] ) ) {
				$raw              = get_term_meta( $id, self::native_key( $field ), true );
				$values[ $field ] = in_array( $field, self::BOOLEANS, true ) ? '1' === (string) $raw : (string) $raw;
			}
		}
		return self::ordered( $values, self::TERM_FIELDS );
	}

	/**
	 * Writes term fields.
	 *
	 * @param int   $id     Term ID.
	 * @param array $fields Fields.
	 * @return true|WP_Error
	 */
	private static function write_term( $id, array $fields ) {
		$provider = self::provider();
		$term     = get_term( $id );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'viagent_production_not_found', __( 'No category or tag found with that ID.', 'viagent-production' ) );
		}
		if ( 'aioseo' === $provider ) {
			return new WP_Error( 'viagent_production_unsupported', __( 'All in One SEO only stores category and tag SEO in its Pro version, so it cannot be changed here.', 'viagent-production' ) );
		}

		if ( 'yoast' === $provider ) {
			$all  = (array) get_option( 'wpseo_taxonomy_meta', array() );
			$meta = (array) ( $all[ $term->taxonomy ][ $id ] ?? array() );
			foreach ( $fields as $field => $value ) {
				if ( isset( self::YOAST_TERM[ $field ] ) ) {
					$meta[ self::YOAST_TERM[ $field ] ] = 'noindex' === $field ? ( $value ? 'noindex' : 'default' ) : $value;
				}
			}
			$all[ $term->taxonomy ][ $id ] = array_filter(
				$meta,
				static function ( $v ) {
					return '' !== $v && 'default' !== $v;
				}
			);
			update_option( 'wpseo_taxonomy_meta', $all );
			return true;
		}

		if ( 'tsf' === $provider ) {
			$meta = (array) get_term_meta( $id, 'autodescription-term-settings', true );
			foreach ( $fields as $field => $value ) {
				if ( isset( self::TSF_TERM[ $field ] ) ) {
					$meta[ self::TSF_TERM[ $field ] ] = 'noindex' === $field ? ( $value ? 1 : 0 ) : $value;
					unset( $fields[ $field ] );
				}
			}
			update_term_meta( $id, 'autodescription-term-settings', $meta );
		}

		$map = self::TERM_META[ $provider ] ?? array();
		foreach ( $fields as $field => $value ) {
			if ( isset( $map[ $field ] ) ) {
				self::put_meta( 'term', $id, $map[ $field ], self::encode( $provider, $field, $value, 'term', $id ) );
			} else {
				self::put_meta( 'term', $id, self::native_key( $field ), is_bool( $value ) ? ( $value ? '1' : '' ) : $value );
			}
		}
		return true;
	}

	/**
	 * Converts a stored provider value to its normalized form.
	 *
	 * @param string $provider Provider.
	 * @param string $field    Field.
	 * @param mixed  $raw      Stored value.
	 * @param bool   $mapped   Whether the provider stores this field.
	 * @return mixed
	 */
	private static function decode( $provider, $field, $raw, $mapped ) {
		if ( ! in_array( $field, self::BOOLEANS, true ) ) {
			if ( 'focus_keyword' === $field && 'rankmath' === $provider ) {
				return trim( (string) strtok( (string) $raw, ',' ) );
			}
			return is_scalar( $raw ) ? (string) $raw : '';
		}
		if ( ! $mapped || 'native' === $provider ) {
			return '1' === (string) $raw;
		}
		switch ( $provider ) {
			case 'rankmath':
				return in_array( $field, (array) $raw, true );
			case 'seopress':
				return 'yes' === $raw;
			default:
				return '1' === (string) $raw;
		}
	}

	/**
	 * Converts a normalized value to the provider's stored format.
	 *
	 * @param string $provider Provider.
	 * @param string $field    Field.
	 * @param mixed  $value    Normalized value.
	 * @param string $type     "post" or "term".
	 * @param int    $id       Object ID.
	 * @return mixed
	 */
	private static function encode( $provider, $field, $value, $type, $id ) {
		if ( 'focus_keyword' === $field && 'rankmath' === $provider ) {
			// Keep Rank Math's secondary keywords, replace the primary one.
			$existing = array_filter( array_map( 'trim', explode( ',', (string) get_metadata( $type, $id, 'rank_math_focus_keyword', true ) ) ) );
			array_shift( $existing );
			return implode( ',', array_filter( array_merge( array( $value ), $existing ) ) );
		}
		if ( ! in_array( $field, self::BOOLEANS, true ) ) {
			return $value;
		}
		switch ( $provider ) {
			case 'rankmath':
				$robots = array_values( array_diff( (array) get_metadata( $type, $id, 'rank_math_robots', true ), array( $field, '' ) ) );
				if ( 'noindex' === $field ) {
					$robots = array_values( array_diff( $robots, array( 'index' ) ) );
					array_unshift( $robots, $value ? 'noindex' : 'index' );
				} elseif ( $value ) {
					$robots[] = 'nofollow';
				}
				return $robots;
			case 'seopress':
				return $value ? 'yes' : '';
			default:
				return $value ? '1' : '';
		}
	}

	/**
	 * Writes or deletes a meta value.
	 *
	 * @param string $type  "post" or "term".
	 * @param int    $id    Object ID.
	 * @param string $key   Meta key.
	 * @param mixed  $value Value; '' or an empty array deletes.
	 */
	private static function put_meta( $type, $id, $key, $value ) {
		if ( '' === $value || null === $value || array() === $value || false === $value ) {
			delete_metadata( $type, $id, $key );
		} else {
			update_metadata( $type, $id, $key, wp_slash( $value ) );
		}
	}

	/**
	 * AIOSEO post model, or null when its classes are unavailable.
	 *
	 * @param int $id Post ID.
	 * @return object|null
	 */
	private static function aioseo_model( $id ) {
		$class = '\AIOSEO\Plugin\Common\Models\Post';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'getPost' ) ) {
			return null;
		}
		$model = $class::getPost( $id );
		if ( empty( $model->post_id ) ) {
			$model->post_id = $id;
		}
		return $model;
	}

	/**
	 * Reads AIOSEO post fields.
	 *
	 * @param int $id Post ID.
	 * @return array
	 */
	private static function read_aioseo( $id ) {
		$model = self::aioseo_model( $id );
		if ( ! $model ) {
			return array();
		}
		$keyphrases = is_string( $model->keyphrases ) ? json_decode( $model->keyphrases, true ) : json_decode( wp_json_encode( $model->keyphrases ), true );
		return array(
			'title'              => (string) $model->title,
			'description'        => (string) $model->description,
			'focus_keyword'      => (string) ( $keyphrases['focus']['keyphrase'] ?? '' ),
			'canonical'          => (string) $model->canonical_url,
			'noindex'            => ! $model->robots_default && (bool) $model->robots_noindex,
			'nofollow'           => ! $model->robots_default && (bool) $model->robots_nofollow,
			'social_title'       => (string) $model->og_title,
			'social_description' => (string) $model->og_description,
			'social_image'       => 'custom_image' === $model->og_image_type ? (string) $model->og_image_custom_url : '',
		);
	}

	/**
	 * Writes AIOSEO post fields through its model.
	 *
	 * @param int   $id     Post ID.
	 * @param array $fields Fields.
	 * @return true|WP_Error
	 */
	private static function write_aioseo( $id, array $fields ) {
		$model = self::aioseo_model( $id );
		if ( ! $model ) {
			return new WP_Error( 'viagent_production_unsupported', __( 'All in One SEO is active but its data classes could not be loaded.', 'viagent-production' ) );
		}
		$columns = array(
			'title'              => 'title',
			'description'        => 'description',
			'canonical'          => 'canonical_url',
			'social_title'       => 'og_title',
			'social_description' => 'og_description',
		);
		foreach ( $columns as $field => $column ) {
			if ( array_key_exists( $field, $fields ) ) {
				$model->$column = '' === $fields[ $field ] ? null : $fields[ $field ];
			}
		}
		if ( array_key_exists( 'focus_keyword', $fields ) ) {
			$keyphrases          = is_string( $model->keyphrases ) ? json_decode( $model->keyphrases, true ) : json_decode( wp_json_encode( $model->keyphrases ), true );
			$keyphrases          = is_array( $keyphrases ) ? $keyphrases : array(
				'focus'      => array(),
				'additional' => array(),
			);
			$keyphrases['focus'] = array(
				'keyphrase' => $fields['focus_keyword'],
				'score'     => 0,
				'analysis'  => new \stdClass(),
			);
			$model->keyphrases   = wp_json_encode( $keyphrases );
		}
		if ( array_key_exists( 'noindex', $fields ) || array_key_exists( 'nofollow', $fields ) ) {
			$noindex                = array_key_exists( 'noindex', $fields ) ? $fields['noindex'] : ( ! $model->robots_default && $model->robots_noindex );
			$nofollow               = array_key_exists( 'nofollow', $fields ) ? $fields['nofollow'] : ( ! $model->robots_default && $model->robots_nofollow );
			$model->robots_default  = ! $noindex && ! $nofollow;
			$model->robots_noindex  = (bool) $noindex;
			$model->robots_nofollow = (bool) $nofollow;
		}
		if ( array_key_exists( 'social_image', $fields ) ) {
			$model->og_image_type       = '' === $fields['social_image'] ? 'default' : 'custom_image';
			$model->og_image_custom_url = '' === $fields['social_image'] ? null : $fields['social_image'];
		}
		$model->updated = gmdate( 'Y-m-d H:i:s' );
		if ( empty( $model->created ) ) {
			$model->created = $model->updated;
		}
		$model->save();
		return true;
	}

	/**
	 * Makes the SEO plugin rebuild cached data (Yoast indexables) after a change.
	 *
	 * @param string $type "post" or "term".
	 * @param int    $id   Object ID.
	 */
	private static function refresh( $type, $id ) {
		'term' === $type ? clean_term_cache( $id ) : clean_post_cache( $id );
		if ( 'yoast' === self::provider() && function_exists( 'YoastSEO' ) ) {
			try {
				$repository = YoastSEO()->classes->get( \Yoast\WP\SEO\Repositories\Indexable_Repository::class );
				$builder    = YoastSEO()->classes->get( \Yoast\WP\SEO\Builders\Indexable_Builder::class );
				$indexable  = $repository->find_by_id_and_type( $id, $type, false );
				$builder->build_for_id_and_type( $id, $type, $indexable ? $indexable : false );
			} catch ( \Throwable $e ) {
				// Yoast rebuilds the indexable itself on the next save.
				unset( $e );
			}
		}
	}

	/**
	 * Orders values by the field list.
	 *
	 * @param array    $values Values.
	 * @param string[] $fields Field order.
	 * @return array
	 */
	private static function ordered( array $values, array $fields ) {
		$result = array();
		foreach ( $fields as $field ) {
			$result[ $field ] = $values[ $field ] ?? ( in_array( $field, self::BOOLEANS, true ) ? false : '' );
		}
		return $result;
	}
}
