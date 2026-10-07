<?php
/**
 * Custom content types: lets AI agents register custom post types,
 * taxonomies and custom fields (e.g. a "Recipe" type with a "Cuisine"
 * taxonomy and "cooking_time" field) without code. Definitions are stored in
 * an option and registered on every request; content created with them stays
 * in the database even if a definition is removed.
 *
 * @package ViagentProduction
 */

namespace ViagentProduction\Abilities;

use ViagentProduction\Log\Activity_Log;
use ViagentProduction\Security\Policy;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Custom post types, taxonomies and fields.
 */
class Content_Types {

	const OPTION = 'viagent_production_content_types';

	/**
	 * Names WordPress reserves for its own use.
	 */
	const RESERVED = array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'action', 'author', 'order', 'theme', 'category', 'post_tag', 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category', 'type', 'name', 'term', 'taxonomy', 'year', 'day', 'month', 'page', 'paged', 'search', 's', 'p' );

	/**
	 * URL bases that must not be taken by a custom post type or taxonomy.
	 */
	const RESERVED_SLUGS = array( 'wp-admin', 'wp-json', 'wp-content', 'wp-includes', 'wp-login', 'wp-login.php', 'feed', 'embed', 'comments', 'trackback', 'xmlrpc', 'xmlrpc.php', 'search', 'author', 'page', 'attachment', 'category', 'tag', 'post', 'robots.txt', 'sitemap.xml', 'wp-sitemap.xml', 'favicon.ico', '.well-known' );

	/**
	 * Field value types.
	 */
	const FIELD_TYPES = array( 'string', 'integer', 'number', 'boolean', 'array', 'object' );

	/**
	 * Limits for stored block templates and field defaults.
	 */
	const MAX_TEMPLATE_BYTES = 65536;
	const MAX_TEMPLATE_DEPTH = 5;
	const MAX_DEFAULT_LENGTH = 1000;

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'init', array( self::class, 'register_all' ), 5 );
		add_filter( 'viagent_production_apply_undo', array( self::class, 'apply_undo' ), 10, 2 );
		add_filter( 'viagent_production_undo_label', array( self::class, 'undo_label' ), 10, 2 );
	}

	/**
	 * Stored definitions.
	 *
	 * @return array{post_types:array,taxonomies:array}
	 */
	public static function definitions() {
		$saved = (array) get_option( self::OPTION, array() );
		return array(
			'post_types' => (array) ( $saved['post_types'] ?? array() ),
			'taxonomies' => (array) ( $saved['taxonomies'] ?? array() ),
		);
	}

	/**
	 * Registers every stored definition.
	 */
	public static function register_all() {
		$definitions = self::definitions();
		foreach ( $definitions['taxonomies'] as $name => $definition ) {
			self::register_taxonomy_definition( $name, $definition );
		}
		foreach ( $definitions['post_types'] as $name => $definition ) {
			self::register_post_type_definition( $name, $definition );
		}
	}

	/**
	 * Builds labels from singular and plural names.
	 *
	 * @param string $singular Singular.
	 * @param string $plural   Plural.
	 * @return array
	 */
	private static function labels( $singular, $plural ) {
		return array(
			'name'          => $plural,
			'singular_name' => $singular,
			'menu_name'     => $plural,
			/* translators: %s: singular name */
			'add_new_item'  => sprintf( __( 'Add New %s', 'viagent-production' ), $singular ),
			/* translators: %s: singular name */
			'edit_item'     => sprintf( __( 'Edit %s', 'viagent-production' ), $singular ),
			/* translators: %s: singular name */
			'new_item'      => sprintf( __( 'New %s', 'viagent-production' ), $singular ),
			/* translators: %s: singular name */
			'view_item'     => sprintf( __( 'View %s', 'viagent-production' ), $singular ),
			/* translators: %s: plural name */
			'search_items'  => sprintf( __( 'Search %s', 'viagent-production' ), $plural ),
			/* translators: %s: plural name */
			'all_items'     => sprintf( __( 'All %s', 'viagent-production' ), $plural ),
			/* translators: %s: plural name */
			'not_found'     => sprintf( __( 'No %s found.', 'viagent-production' ), strtolower( $plural ) ),
		);
	}

	/**
	 * Registers one post type with its fields.
	 *
	 * @param string $name       Post type.
	 * @param array  $definition Definition.
	 */
	private static function register_post_type_definition( $name, array $definition ) {
		if ( post_type_exists( $name ) ) {
			return;
		}
		$public = (bool) ( $definition['public'] ?? true );
		register_post_type(
			$name,
			array(
				'labels'        => self::labels( $definition['singular'], $definition['plural'] ),
				'description'   => $definition['description'] ?? '',
				'public'        => $public,
				'show_ui'       => true,
				'show_in_rest'  => true,
				'hierarchical'  => (bool) ( $definition['hierarchical'] ?? false ),
				'has_archive'   => $public && ( $definition['has_archive'] ?? true ) ? ( $definition['archive_slug'] ?? true ) : false,
				'rewrite'       => array( 'slug' => $definition['slug'] ?? $name ),
				'menu_icon'     => $definition['menu_icon'] ?? 'dashicons-admin-post',
				'menu_position' => isset( $definition['menu_position'] ) ? (int) $definition['menu_position'] : null,
				'supports'      => array_values( array_unique( array_merge( (array) ( $definition['supports'] ?? array( 'title', 'editor', 'thumbnail', 'excerpt' ) ), array( 'custom-fields' ) ) ) ),
				'taxonomies'    => (array) ( $definition['taxonomies'] ?? array() ),
				'template'      => $definition['block_template'] ?? array(),
			)
		);
		foreach ( (array) ( $definition['taxonomies'] ?? array() ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				register_taxonomy_for_object_type( $taxonomy, $name );
			}
		}
		foreach ( (array) ( $definition['fields'] ?? array() ) as $field ) {
			$type = in_array( $field['type'] ?? 'string', self::FIELD_TYPES, true ) ? $field['type'] : 'string';
			$args = array(
				'type'          => $type,
				'description'   => $field['label'] ?? $field['key'],
				'single'        => true,
				'show_in_rest'  => self::rest_schema( $type ),
				'auth_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
			);
			if ( isset( $field['default'] ) ) {
				$args['default'] = $field['default'];
			}
			register_post_meta( $name, $field['key'], $args );
		}
	}

	/**
	 * REST settings for a field type. Arrays hold scalar values; objects any keys.
	 *
	 * @param string $type Field type.
	 * @return array|bool
	 */
	private static function rest_schema( $type ) {
		if ( 'array' === $type ) {
			return array(
				'schema' => array(
					'type'  => 'array',
					'items' => array( 'type' => array( 'string', 'number', 'integer', 'boolean' ) ),
				),
			);
		}
		if ( 'object' === $type ) {
			return array(
				'schema' => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => true,
				),
			);
		}
		return true;
	}

	/**
	 * Registers one taxonomy.
	 *
	 * @param string $name       Taxonomy.
	 * @param array  $definition Definition.
	 */
	private static function register_taxonomy_definition( $name, array $definition ) {
		if ( taxonomy_exists( $name ) ) {
			return;
		}
		register_taxonomy(
			$name,
			(array) ( $definition['post_types'] ?? array() ),
			array(
				'labels'            => self::labels( $definition['singular'], $definition['plural'] ),
				'description'       => $definition['description'] ?? '',
				'public'            => (bool) ( $definition['public'] ?? true ),
				'hierarchical'      => (bool) ( $definition['hierarchical'] ?? true ),
				'show_ui'           => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => $definition['slug'] ?? $name ),
			)
		);
	}

	/**
	 * Registers abilities.
	 */
	public static function register() {
		$name = array(
			'type'        => 'string',
			'pattern'     => '^[a-z][a-z0-9_]{0,19}$',
			'description' => __( 'Internal name: lowercase letters, numbers and underscores, max 20 characters, e.g. "recipe".', 'viagent-production' ),
		);

		Abilities::add(
			'list-content-types',
			array(
				'category'    => 'viagent-production-content',
				'label'       => __( 'List custom content types', 'viagent-production' ),
				'description' => __( 'Lists the custom post types and taxonomies created through Viagent Production, with their fields. (list_post_types and list_taxonomies show every type on the site.)', 'viagent-production' ),
				'execute'     => array( self::class, 'list_definitions' ),
				'permission'  => 'manage_options',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'register-post-type',
			array(
				'category'    => 'viagent-production-content',
				'label'       => __( 'Create custom post type', 'viagent-production' ),
				'description' => __( 'Creates (or updates) a custom post type such as Recipes, Events, Portfolio or Testimonials, with optional custom fields and taxonomies. It works in the block editor and REST API right away; then add items with create_post (fields go in meta). Calling it again with the same name updates the definition.', 'viagent-production' ),
				'input'       => array(
					'name'           => $name,
					'singular'       => array( 'type' => 'string' ),
					'plural'         => array( 'type' => 'string' ),
					'description'    => array( 'type' => 'string' ),
					'public'         => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => __( 'false for internal types with no front-end pages.', 'viagent-production' ),
					),
					'hierarchical'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'has_archive'    => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'slug'           => array(
						'type'        => 'string',
						'description' => __( 'URL base, e.g. "recipes" (defaults to the name).', 'viagent-production' ),
					),
					'menu_icon'      => array(
						'type'        => 'string',
						'description' => __( 'Dashicon, e.g. "dashicons-carrot".', 'viagent-production' ),
					),
					'supports'       => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'string',
							'enum' => array( 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments', 'revisions', 'page-attributes', 'post-formats', 'trackbacks' ),
						),
					),
					'taxonomies'     => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Existing taxonomies to attach, e.g. ["category", "post_tag"].', 'viagent-production' ),
					),
					'fields'         => array(
						'type'        => 'array',
						'description' => __( 'Custom fields, e.g. [{"key":"price","type":"number","label":"Price"}].', 'viagent-production' ),
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'key'     => array(
									'type'    => 'string',
									'pattern' => '^[a-z][a-z0-9_]*$',
								),
								'type'    => array(
									'type'    => 'string',
									'enum'    => self::FIELD_TYPES,
									'default' => 'string',
								),
								'label'   => array( 'type' => 'string' ),
								'default' => array(
									'type'        => array( 'string', 'integer', 'number', 'boolean', 'null' ),
									'maxLength'   => self::MAX_DEFAULT_LENGTH,
									'description' => __( 'Default value (a string, number or boolean matching the field type).', 'viagent-production' ),
								),
							),
							'required'   => array( 'key' ),
						),
					),
					'block_template' => array(
						'type'        => 'array',
						'maxItems'    => 200,
						'description' => __( 'Optional starting blocks for new items in the editor, e.g. [["core/heading",{"content":"Ingredients"}],["core/list"]]. Each block is [name, attributes, inner blocks].', 'viagent-production' ),
						'items'       => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => 3,
							'items'    => array( 'type' => array( 'string', 'object', 'array' ) ),
						),
					),
				),
				'required'    => array( 'name', 'singular', 'plural' ),
				'execute'     => array( self::class, 'save_post_type' ),
				'permission'  => 'manage_options',
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'idempotent' => true ) ),
			)
		);

		Abilities::add(
			'register-taxonomy',
			array(
				'category'    => 'viagent-production-content',
				'label'       => __( 'Create custom taxonomy', 'viagent-production' ),
				'description' => __( 'Creates (or updates) a taxonomy such as Cuisine, Genre or Location for one or more post types. hierarchical true works like categories, false like tags. Then add terms with create_term.', 'viagent-production' ),
				'input'       => array(
					'name'         => $name,
					'singular'     => array( 'type' => 'string' ),
					'plural'       => array( 'type' => 'string' ),
					'description'  => array( 'type' => 'string' ),
					'post_types'   => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'string' ),
						'minItems' => 1,
					),
					'hierarchical' => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'public'       => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'slug'         => array( 'type' => 'string' ),
				),
				'required'    => array( 'name', 'singular', 'plural', 'post_types' ),
				'execute'     => array( self::class, 'save_taxonomy' ),
				'permission'  => 'manage_options',
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'idempotent' => true ) ),
			)
		);

		Abilities::add(
			'delete-content-type',
			array(
				'category'    => 'viagent-production-content',
				'label'       => __( 'Remove custom content type', 'viagent-production' ),
				'description' => __( 'Removes a custom post type or taxonomy created through Viagent Production. Its items and terms stay in the database (and come back if it is created again). Can be undone.', 'viagent-production' ),
				'input'       => array(
					'kind' => array(
						'type' => 'string',
						'enum' => array( 'post_type', 'taxonomy' ),
					),
					'name' => $name,
				),
				'required'    => array( 'kind', 'name' ),
				'execute'     => array( self::class, 'delete_definition' ),
				'permission'  => 'manage_options',
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'destructive' => true ) ),
			)
		);
	}

	/**
	 * Lists stored definitions.
	 *
	 * @return array
	 */
	public static function list_definitions() {
		$definitions = self::definitions();
		$result      = array(
			'post_types' => array(),
			'taxonomies' => array(),
		);
		foreach ( $definitions['post_types'] as $name => $definition ) {
			$result['post_types'][] = array_merge(
				array(
					'name'  => $name,
					'items' => array_sum( (array) wp_count_posts( $name ) ),
				),
				$definition
			);
		}
		foreach ( $definitions['taxonomies'] as $name => $definition ) {
			$result['taxonomies'][] = array_merge(
				array(
					'name'  => $name,
					'terms' => (int) wp_count_terms( array( 'taxonomy' => $name ) ),
				),
				$definition
			);
		}
		return $result;
	}

	/**
	 * Checks a name is free (or already ours).
	 *
	 * @param string $kind post_types or taxonomies.
	 * @param string $name Name.
	 * @return true|WP_Error
	 */
	private static function check_name( $kind, $name ) {
		$ours = isset( self::definitions()[ $kind ][ $name ] );
		if ( in_array( $name, self::RESERVED, true ) ) {
			/* translators: %s: name */
			return new WP_Error( 'viagent_production_reserved_name', sprintf( __( '"%s" is reserved by WordPress. Choose another name.', 'viagent-production' ), $name ) );
		}
		if ( ! $ours && ( post_type_exists( $name ) || taxonomy_exists( $name ) ) ) {
			/* translators: %s: name */
			return new WP_Error( 'viagent_production_name_taken', sprintf( __( '"%s" is already registered by the theme or another plugin. Choose another name.', 'viagent-production' ), $name ) );
		}
		return true;
	}

	/**
	 * Checks a URL base is not reserved by WordPress, a theme or another
	 * plugin: core paths, the category and tag bases, and the rewrite slug of
	 * every registered post type and taxonomy (other than the one being saved).
	 *
	 * @param string $slug Sanitized slug.
	 * @param string $kind post_types or taxonomies.
	 * @param string $name Name of the type being saved (its own slug is allowed).
	 * @return true|WP_Error
	 */
	private static function check_slug( $slug, $kind, $name ) {
		$taken = array_merge( self::RESERVED, self::RESERVED_SLUGS );
		foreach ( array( 'category_base', 'tag_base' ) as $option ) {
			$base = trim( (string) get_option( $option ), '/' );
			if ( '' !== $base ) {
				$taken[] = $base;
			}
		}
		foreach ( get_post_types( array(), 'objects' ) as $type ) {
			if ( ( 'post_types' !== $kind || $type->name !== $name ) && ! empty( $type->rewrite['slug'] ) ) {
				$taken[] = $type->rewrite['slug'];
			}
		}
		foreach ( get_taxonomies( array(), 'objects' ) as $taxonomy ) {
			if ( ( 'taxonomies' !== $kind || $taxonomy->name !== $name ) && ! empty( $taxonomy->rewrite['slug'] ) ) {
				$taken[] = $taxonomy->rewrite['slug'];
			}
		}
		$taken = array_map(
			static function ( $base ) {
				return trim( (string) $base, '/' );
			},
			$taken
		);
		if ( '' === $slug || in_array( $slug, $taken, true ) ) {
			/* translators: %s: URL slug */
			return new WP_Error( 'viagent_production_reserved_slug', sprintf( __( 'The URL base "%s" is reserved or already used by another content type. Choose another slug.', 'viagent-production' ), $slug ) );
		}
		return true;
	}

	/**
	 * Checks a block template: a list of [block name, attributes, inner blocks]
	 * items, nested at most MAX_TEMPLATE_DEPTH levels deep.
	 *
	 * @param array $template Template.
	 * @param int   $depth    Current nesting level.
	 * @return true|WP_Error
	 */
	private static function check_block_template( array $template, $depth = 0 ) {
		if ( $depth >= self::MAX_TEMPLATE_DEPTH ) {
			/* translators: %d: nesting depth */
			return new WP_Error( 'viagent_production_invalid_template', sprintf( __( 'Block templates can be nested at most %d levels deep.', 'viagent-production' ), self::MAX_TEMPLATE_DEPTH ) );
		}
		if ( ! wp_is_numeric_array( $template ) ) {
			return new WP_Error( 'viagent_production_invalid_template', __( 'A block template must be a list of blocks, e.g. [["core/paragraph"]].', 'viagent-production' ) );
		}
		foreach ( $template as $block ) {
			if ( ! is_array( $block ) || ! wp_is_numeric_array( $block ) || empty( $block ) || count( $block ) > 3 || ! is_string( $block[0] ) || ! preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $block[0] ) ) {
				return new WP_Error( 'viagent_production_invalid_template', __( 'Each template block must be [block name, attributes, inner blocks], with a name like "core/paragraph".', 'viagent-production' ) );
			}
			if ( isset( $block[1] ) && ( ! is_array( $block[1] ) || ( ! empty( $block[1] ) && wp_is_numeric_array( $block[1] ) ) ) ) {
				return new WP_Error( 'viagent_production_invalid_template', __( 'Block attributes must be an object, e.g. {"content":"Ingredients"}.', 'viagent-production' ) );
			}
			if ( isset( $block[2] ) ) {
				if ( ! is_array( $block[2] ) ) {
					return new WP_Error( 'viagent_production_invalid_template', __( 'Inner blocks must be a list of blocks.', 'viagent-production' ) );
				}
				$inner = self::check_block_template( $block[2], $depth + 1 );
				if ( is_wp_error( $inner ) ) {
					return $inner;
				}
			}
		}
		return true;
	}

	/**
	 * Checks a field default is a scalar that matches the field type.
	 *
	 * @param string $key   Field key.
	 * @param string $type  Field type.
	 * @param mixed  $value Default value.
	 * @return true|WP_Error
	 */
	private static function check_field_default( $key, $type, $value ) {
		if ( null === $value ) {
			return true;
		}
		$valid = false;
		switch ( $type ) {
			case 'string':
				$valid = is_string( $value ) && strlen( $value ) <= self::MAX_DEFAULT_LENGTH;
				break;
			case 'integer':
				$valid = is_int( $value );
				break;
			case 'number':
				$valid = is_int( $value ) || is_float( $value );
				break;
			case 'boolean':
				$valid = is_bool( $value );
				break;
		}
		if ( ! $valid ) {
			return new WP_Error(
				'viagent_production_invalid_field',
				sprintf(
					/* translators: 1: field key, 2: field type */
					__( 'The default for field "%1$s" must be a %2$s value (strings up to 1000 characters; array and object fields take no default).', 'viagent-production' ),
					$key,
					$type
				)
			);
		}
		return true;
	}

	/**
	 * Saves the definitions, logs undo, and refreshes permalinks.
	 *
	 * @param array $definitions New definitions.
	 * @param array $before      Previous option value.
	 */
	private static function store( array $definitions, $before ) {
		update_option( self::OPTION, $definitions );
		Activity_Log::set_object( 'content_type', 0 );
		Activity_Log::set_undo(
			array(
				'action'      => 'restore_content_types',
				'definitions' => $before,
			)
		);
		// Register immediately so the new type works in this request, then rebuild permalinks.
		self::register_all();
		flush_rewrite_rules( false );
	}

	/**
	 * Creates or updates a post type.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function save_post_type( $input ) {
		$name  = $input['name'];
		$check = self::check_name( 'post_types', $name );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		foreach ( (array) ( $input['taxonomies'] ?? array() ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				/* translators: %s: taxonomy */
				return new WP_Error( 'viagent_production_invalid_taxonomy', sprintf( __( 'The taxonomy "%s" does not exist. Create it with register_taxonomy after the post type instead.', 'viagent-production' ), $taxonomy ) );
			}
		}
		$fields = array();
		foreach ( (array) ( $input['fields'] ?? array() ) as $field ) {
			$type  = in_array( $field['type'] ?? 'string', self::FIELD_TYPES, true ) ? $field['type'] : 'string';
			$check = self::check_field_default( sanitize_key( $field['key'] ), $type, $field['default'] ?? null );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			$fields[] = array_filter(
				array(
					'key'     => sanitize_key( $field['key'] ),
					'type'    => $type,
					'label'   => sanitize_text_field( $field['label'] ?? '' ),
					'default' => $field['default'] ?? null,
				),
				static function ( $value ) {
					return null !== $value && '' !== $value;
				}
			);
		}
		$slug  = sanitize_title( $input['slug'] ?? str_replace( '_', '-', $name ) );
		$check = self::check_slug( $slug, 'post_types', $name );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$block_template = (array) ( $input['block_template'] ?? array() );
		if ( ! empty( $block_template ) ) {
			$check = self::check_block_template( $block_template );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			if ( strlen( (string) wp_json_encode( $block_template ) ) > self::MAX_TEMPLATE_BYTES ) {
				return new WP_Error( 'viagent_production_invalid_template', __( 'The block template is too large (64 KB maximum).', 'viagent-production' ) );
			}
		}

		$before                             = get_option( self::OPTION, null );
		$definitions                        = self::definitions();
		$existed                            = isset( $definitions['post_types'][ $name ] );
		$definitions['post_types'][ $name ] = array_filter(
			array(
				'singular'       => sanitize_text_field( $input['singular'] ),
				'plural'         => sanitize_text_field( $input['plural'] ),
				'description'    => sanitize_text_field( $input['description'] ?? '' ),
				'public'         => (bool) ( $input['public'] ?? true ),
				'hierarchical'   => (bool) ( $input['hierarchical'] ?? false ),
				'has_archive'    => (bool) ( $input['has_archive'] ?? true ),
				'slug'           => $slug,
				'menu_icon'      => sanitize_html_class( $input['menu_icon'] ?? 'dashicons-admin-post' ),
				'supports'       => array_values( (array) ( $input['supports'] ?? array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ) ) ),
				'taxonomies'     => array_values( array_map( 'sanitize_key', (array) ( $input['taxonomies'] ?? array() ) ) ),
				'fields'         => $fields,
				'block_template' => $block_template,
			),
			static function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);
		if ( $existed && post_type_exists( $name ) ) {
			unregister_post_type( $name );
		}
		self::store( $definitions, $before );

		return array(
			'name'      => $name,
			'created'   => ! $existed,
			'updated'   => $existed,
			'admin_url' => admin_url( 'edit.php?post_type=' . $name ),
			'archive'   => ! empty( $definitions['post_types'][ $name ]['public'] ) && ! empty( $definitions['post_types'][ $name ]['has_archive'] ) ? get_post_type_archive_link( $name ) : null,
			'fields'    => array_column( $fields, 'key' ),
			'next'      => sprintf( 'Create items with create_post (post_type "%s"); set fields through meta.', $name ),
		);
	}

	/**
	 * Creates or updates a taxonomy.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function save_taxonomy( $input ) {
		$name  = $input['name'];
		$check = self::check_name( 'taxonomies', $name );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$definitions = self::definitions();
		foreach ( (array) $input['post_types'] as $post_type ) {
			if ( ! post_type_exists( $post_type ) && ! isset( $definitions['post_types'][ $post_type ] ) ) {
				/* translators: %s: post type */
				return new WP_Error( 'viagent_production_invalid_post_type', sprintf( __( 'The post type "%s" does not exist.', 'viagent-production' ), $post_type ) );
			}
		}

		$slug  = sanitize_title( $input['slug'] ?? str_replace( '_', '-', $name ) );
		$check = self::check_slug( $slug, 'taxonomies', $name );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$before                             = get_option( self::OPTION, null );
		$existed                            = isset( $definitions['taxonomies'][ $name ] );
		$definitions['taxonomies'][ $name ] = array(
			'singular'     => sanitize_text_field( $input['singular'] ),
			'plural'       => sanitize_text_field( $input['plural'] ),
			'description'  => sanitize_text_field( $input['description'] ?? '' ),
			'post_types'   => array_values( array_map( 'sanitize_key', (array) $input['post_types'] ) ),
			'hierarchical' => (bool) ( $input['hierarchical'] ?? true ),
			'public'       => (bool) ( $input['public'] ?? true ),
			'slug'         => $slug,
		);
		if ( $existed && taxonomy_exists( $name ) ) {
			unregister_taxonomy( $name );
		}
		self::store( $definitions, $before );

		return array(
			'name'      => $name,
			'created'   => ! $existed,
			'updated'   => $existed,
			'admin_url' => admin_url( 'edit-tags.php?taxonomy=' . $name ),
			'next'      => sprintf( 'Add terms with create_term (taxonomy "%s") or pass them in terms when creating posts.', $name ),
		);
	}

	/**
	 * Removes a definition.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function delete_definition( $input ) {
		$kind        = 'taxonomy' === $input['kind'] ? 'taxonomies' : 'post_types';
		$definitions = self::definitions();
		if ( ! isset( $definitions[ $kind ][ $input['name'] ] ) ) {
			return new WP_Error( 'viagent_production_not_found', __( 'No custom content type with that name was created through Viagent Production.', 'viagent-production' ) );
		}
		$before = get_option( self::OPTION, null );
		unset( $definitions[ $kind ][ $input['name'] ] );
		'taxonomies' === $kind ? unregister_taxonomy( $input['name'] ) : unregister_post_type( $input['name'] );
		self::store( $definitions, $before );
		return array(
			'name'    => $input['name'],
			'removed' => true,
			'note'    => 'Existing items and terms were kept in the database.',
		);
	}

	/**
	 * Undo handler.
	 *
	 * @param mixed $result Result from earlier handlers.
	 * @param array $undo   Undo instructions.
	 * @return mixed
	 */
	public static function apply_undo( $result, $undo ) {
		if ( null !== $result || 'restore_content_types' !== ( $undo['action'] ?? '' ) ) {
			return $result;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'viagent_production_forbidden', __( 'You are not allowed to change content types.', 'viagent-production' ) );
		}
		null === $undo['definitions'] ? delete_option( self::OPTION ) : update_option( self::OPTION, $undo['definitions'] );
		// Rewrite rules are rebuilt on the next request, once the restored types are registered.
		delete_option( 'rewrite_rules' );
		return true;
	}

	/**
	 * Undo description.
	 *
	 * @param string|null $label Label.
	 * @param array       $undo  Undo instructions.
	 * @return string|null
	 */
	public static function undo_label( $label, $undo ) {
		return 'restore_content_types' === ( $undo['action'] ?? '' ) ? __( 'The custom post types and taxonomies will go back to how they were (content is kept).', 'viagent-production' ) : $label;
	}
}
