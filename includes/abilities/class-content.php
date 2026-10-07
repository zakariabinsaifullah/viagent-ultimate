<?php
/**
 * Content abilities: posts, pages and custom post types.
 *
 * @package ViagentProduction
 */

namespace ViagentProduction\Abilities;

use ViagentProduction\Log\Activity_Log;
use ViagentProduction\Security\Policy;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Content abilities.
 */
class Content {

	const MAX_BULK = 25;

	/**
	 * Longest regular expression replace_in_post accepts, to keep patterns cheap to run.
	 */
	const MAX_REGEX_LENGTH = 200;

	/**
	 * Registers abilities.
	 */
	public static function register() {
		wp_register_ability(
			'viagent-production/list-post-types',
			array(
				'label'               => __( 'List post types', 'viagent-production' ),
				'description'         => __( 'Lists content types you can work with (posts, pages and custom post types) with their taxonomies and whether they support the block editor.', 'viagent-production' ),
				'category'            => 'viagent-production-content',
				'input_schema'        => array(),
				'execute_callback'    => array( self::class, 'list_post_types' ),
				'permission_callback' => static function () {
					return current_user_can( 'read' );
				},
				'meta'                => Abilities::read_meta(),
			)
		);

		wp_register_ability(
			'viagent-production/list-posts',
			array(
				'label'               => __( 'List posts', 'viagent-production' ),
				'description'         => __( 'Lists posts, pages or items of any post type, newest first. Filter by status, search words, author or category/tag. Returns summaries; use get_post for full content.', 'viagent-production' ),
				'category'            => 'viagent-production-content',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_type'    => array(
							'type'        => 'string',
							'default'     => 'post',
							'description' => __( 'Post type name, e.g. "post" or "page". See list_post_types.', 'viagent-production' ),
						),
						'status'       => array(
							'type'        => 'string',
							'enum'        => array( 'any', 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
							'default'     => 'any',
							'description' => __( '"any" returns every status except trash.', 'viagent-production' ),
						),
						'search'       => array( 'type' => 'string' ),
						'author'       => array(
							'type'        => 'integer',
							'description' => __( 'Author user ID.', 'viagent-production' ),
						),
						'category'     => array(
							'type'        => 'string',
							'description' => __( 'Category slug (posts only).', 'viagent-production' ),
						),
						'tag'          => array(
							'type'        => 'string',
							'description' => __( 'Tag slug (posts only).', 'viagent-production' ),
						),
						'taxonomy'     => array(
							'type'        => 'string',
							'description' => __( 'Any taxonomy to filter by (use with terms), e.g. "product_cat" or a custom taxonomy.', 'viagent-production' ),
						),
						'terms'        => array(
							'type'        => 'array',
							'items'       => array( 'type' => array( 'string', 'integer' ) ),
							'description' => __( 'Term slugs or IDs in that taxonomy.', 'viagent-production' ),
						),
						'meta_key'     => array( 'type' => 'string' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- input schema, not a query.
						'meta_value'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- input schema, not a query.
							'type'        => array( 'string', 'number' ),
							'description' => __( 'Custom field value to match (with meta_key).', 'viagent-production' ),
						),
						'meta_compare' => array(
							'type'    => 'string',
							'enum'    => array( '=', '!=', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'EXISTS', 'NOT EXISTS' ),
							'default' => '=',
						),
						'parent'       => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'Only children of this ID (0 = top level).', 'viagent-production' ),
						),
						'include'      => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'date_after'   => array(
							'type'        => 'string',
							'description' => __( 'Published after this date, e.g. "2026-01-01".', 'viagent-production' ),
						),
						'date_before'  => array( 'type' => 'string' ),
						'with_content' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Include full content, excerpt and custom fields (use small per_page).', 'viagent-production' ),
						),
						'orderby'      => array(
							'type'    => 'string',
							'enum'    => array( 'date', 'modified', 'title', 'menu_order', 'ID', 'author', 'parent', 'comment_count', 'rand', 'meta_value', 'meta_value_num', 'relevance' ),
							'default' => 'date',
						),
						'order'        => array(
							'type'    => 'string',
							'enum'    => array( 'asc', 'desc' ),
							'default' => 'desc',
						),
						'per_page'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'page'         => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( self::class, 'list_posts' ),
				'permission_callback' => static function () {
					return current_user_can( 'read' );
				},
				'meta'                => Abilities::read_meta(),
			)
		);

		wp_register_ability(
			'viagent-production/get-post',
			array(
				'label'               => __( 'Get post', 'viagent-production' ),
				'description'         => __( 'Gets one post, page or custom post type item by ID, including its full block content, excerpt, categories/tags/terms, featured image and links.', 'viagent-production' ),
				'category'            => 'viagent-production-content',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'           => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'include_meta' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Also return the custom fields.', 'viagent-production' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( self::class, 'get_post' ),
				'permission_callback' => static function ( $input ) {
					return self::can_read( (int) ( $input['id'] ?? 0 ) );
				},
				'meta'                => Abilities::read_meta(),
			)
		);

		self::register_writes();
		self::register_power_tools();
	}

	/**
	 * Registers write abilities.
	 */
	private static function register_writes() {
		$fields = self::field_schema();

		Abilities::add(
			'create-post',
			array(
				'category'    => 'viagent-production-content',
				'label'       => __( 'Create post', 'viagent-production' ),
				'description' => __( 'Creates a post, page or custom post type item. Content should be block markup (<!-- wp:paragraph --><p>…</p><!-- /wp:paragraph -->). Status defaults to "draft". Terms can be given as names (created if missing) or IDs.', 'viagent-production' ),
				'input'       => array_merge(
					array(
						'post_type' => array(
							'type'    => 'string',
							'default' => 'post',
						),
					),
					$fields
				),
				'required'    => array( 'title' ),
				'execute'     => array( self::class, 'create_post' ),
				'permission'  => static function ( $input ) {
					$type = get_post_type_object( $input['post_type'] ?? 'post' );
					return $type ? current_user_can( $type->cap->create_posts ) : current_user_can( 'edit_posts' );
				},
				'meta'        => Abilities::write_meta( Policy::CONTENT, array( 'draft_safe' => true ) ),
			)
		);

		Abilities::add(
			'update-post',
			array(
				'category'    => 'viagent-production-content',
				'label'       => __( 'Update post', 'viagent-production' ),
				'description' => __( 'Updates a post, page or custom post type item. Only the fields you pass are changed. To edit content, first read it with get_post, then send the full new block markup. The previous version is kept so the site owner can undo the change.', 'viagent-production' ),
				'input'       => array_merge(
					array(
						'id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					$fields
				),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'update_post' ),
				'permission'  => static function ( $input ) {
					return self::can( 'edit_post', (int) ( $input['id'] ?? 0 ) );
				},
				'meta'        => Abilities::write_meta( Policy::CONTENT, array( 'draft_safe' => true ) ),
			)
		);

		Abilities::add(
			'delete-post',
			array(
				'category'    => 'viagent-production-content',
				'label'       => __( 'Delete post', 'viagent-production' ),
				'description' => __( 'Moves a post, page or custom post type item to the trash (it can be restored with restore_post). Permanent deletion with force=true only works if the site owner allowed it.', 'viagent-production' ),
				'input'       => array(
					'id'    => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'force' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Skip the trash and delete permanently.', 'viagent-production' ),
					),
				),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'delete_post' ),
				'permission'  => static function ( $input ) {
					return self::can( 'delete_post', (int) ( $input['id'] ?? 0 ) );
				},
				'meta'        => Abilities::write_meta( Policy::CONTENT, array( 'destructive' => true ) ),
			)
		);

		Abilities::add(
			'restore-post',
			array(
				'category'    => 'viagent-production-content',
				'label'       => __( 'Restore post from trash', 'viagent-production' ),
				'description' => __( 'Restores a trashed post, page or custom post type item. It comes back as a draft.', 'viagent-production' ),
				'input'       => array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'restore_post' ),
				'permission'  => static function ( $input ) {
					return self::can( 'delete_post', (int) ( $input['id'] ?? 0 ) );
				},
				'meta'        => Abilities::write_meta(
					Policy::CONTENT,
					array(
						'idempotent' => true,
						'draft_safe' => true,
					)
				),
			)
		);

		Abilities::add(
			'list-revisions',
			array(
				'category'    => 'viagent-production-content',
				'label'       => __( 'List revisions', 'viagent-production' ),
				'description' => __( 'Lists saved revisions (earlier versions) of a post or page, newest first.', 'viagent-production' ),
				'input'       => array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'list_revisions' ),
				'permission'  => static function ( $input ) {
					return self::can( 'edit_post', (int) ( $input['id'] ?? 0 ) );
				},
				'meta'        => Abilities::read_meta(),
			)
		);

		Abilities::add(
			'restore-revision',
			array(
				'category'    => 'viagent-production-content',
				'label'       => __( 'Restore revision', 'viagent-production' ),
				'description' => __( 'Rolls a post or page back to an earlier revision (see list_revisions).', 'viagent-production' ),
				'input'       => array(
					'revision_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'    => array( 'revision_id' ),
				'execute'     => array( self::class, 'restore_revision' ),
				'permission'  => static function ( $input ) {
					$revision = wp_get_post_revision( (int) ( $input['revision_id'] ?? 0 ) );
					return $revision ? current_user_can( 'edit_post', $revision->post_parent ) : current_user_can( 'edit_posts' );
				},
				'meta'        => Abilities::write_meta( Policy::CONTENT, array( 'draft_safe' => true ) ),
			)
		);
	}

	/**
	 * Registers bulk, duplicate and find-and-replace abilities.
	 */
	private static function register_power_tools() {
		$fields = self::field_schema();
		$id     = array(
			'type'    => 'integer',
			'minimum' => 1,
		);

		Abilities::add(
			'bulk-create-posts',
			array(
				'category'    => 'viagent-production-content',
				'label'       => __( 'Bulk create posts', 'viagent-production' ),
				'description' => __( 'Creates up to 25 posts, pages or custom post type items in one call (same fields as create_post, each with its own post_type). Items that fail are reported; the rest are saved. Undo trashes all of them.', 'viagent-production' ),
				'input'       => array(
					'items' => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => self::MAX_BULK,
						'items'    => array(
							'type'       => 'object',
							'properties' => array_merge(
								array(
									'post_type' => array(
										'type'    => 'string',
										'default' => 'post',
									),
								),
								$fields
							),
							'required'   => array( 'title' ),
						),
					),
				),
				'required'    => array( 'items' ),
				'execute'     => array( self::class, 'bulk_create' ),
				'permission'  => 'edit_posts',
				'meta'        => Abilities::write_meta( Policy::CONTENT, array( 'draft_safe' => true ) ),
			)
		);

		Abilities::add(
			'bulk-update-posts',
			array(
				'category'    => 'viagent-production-content',
				'label'       => __( 'Bulk update posts', 'viagent-production' ),
				'description' => __( 'Updates up to 25 posts, pages or items in one call (same fields as update_post, each with its id), e.g. to change status, terms, custom fields or author of many items. Undo restores all of them.', 'viagent-production' ),
				'input'       => array(
					'items' => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => self::MAX_BULK,
						'items'    => array(
							'type'       => 'object',
							'properties' => array_merge( array( 'id' => $id ), $fields ),
							'required'   => array( 'id' ),
						),
					),
				),
				'required'    => array( 'items' ),
				'execute'     => array( self::class, 'bulk_update' ),
				'permission'  => 'edit_posts',
				'meta'        => Abilities::write_meta( Policy::CONTENT, array( 'draft_safe' => true ) ),
			)
		);

		Abilities::add(
			'duplicate-post',
			array(
				'category'    => 'viagent-production-content',
				'label'       => __( 'Duplicate post', 'viagent-production' ),
				'description' => __( 'Copies a post, page or item — content, excerpt, custom fields (including page builder data), categories/terms, featured image, template and parent — into a new draft.', 'viagent-production' ),
				'input'       => array(
					'id'     => $id,
					'title'  => array(
						'type'        => 'string',
						'description' => __( 'Title of the copy (defaults to the original title + " (copy)").', 'viagent-production' ),
					),
					'status' => array(
						'type'    => 'string',
						'enum'    => array( 'draft', 'pending', 'publish', 'private' ),
						'default' => 'draft',
					),
				),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'duplicate_post' ),
				'permission'  => static function ( $input ) {
					return self::can( 'edit_post', (int) ( $input['id'] ?? 0 ) );
				},
				'meta'        => Abilities::write_meta( Policy::CONTENT, array( 'draft_safe' => true ) ),
			)
		);

		Abilities::add(
			'replace-in-post',
			array(
				'category'    => 'viagent-production-content',
				'label'       => __( 'Find and replace in post', 'viagent-production' ),
				'description' => __( 'Finds text in the raw content (block markup) of a post, page or item and replaces it, without resending the whole content. Good for fixing a phrase, a link or a price in a long page. Use regex for patterns. Fails if nothing matches.', 'viagent-production' ),
				'input'       => array(
					'id'         => $id,
					'find'       => array(
						'type'      => 'string',
						'minLength' => 1,
					),
					'replace'    => array( 'type' => 'string' ),
					'regex'      => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Treat find as a PCRE pattern without delimiters; $1 in replace inserts a group.', 'viagent-production' ),
					),
					'limit'      => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
						'description' => __( 'Maximum replacements; 0 replaces all.', 'viagent-production' ),
					),
					'in_title'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'in_excerpt' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'required'    => array( 'id', 'find', 'replace' ),
				'execute'     => array( self::class, 'replace_in_post' ),
				'permission'  => static function ( $input ) {
					return self::can( 'edit_post', (int) ( $input['id'] ?? 0 ) );
				},
				'meta'        => Abilities::write_meta( Policy::CONTENT, array( 'draft_safe' => true ) ),
			)
		);
	}

	/**
	 * Runs a callback for each bulk item and collects results and undo steps.
	 *
	 * @param array    $items    Items.
	 * @param callable $callback Single-item callback.
	 * @return array
	 */
	private static function run_bulk( array $items, callable $callback ) {
		$saved  = array();
		$failed = array();
		$undo   = array();
		foreach ( array_slice( $items, 0, self::MAX_BULK ) as $index => $item ) {
			$result = call_user_func( $callback, (array) $item );
			$step   = Activity_Log::take_undo();
			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'index'   => $index,
					'title'   => $item['title'] ?? null,
					'id'      => $item['id'] ?? null,
					'message' => $result->get_error_message(),
				);
				continue;
			}
			if ( $step ) {
				$undo[] = $step;
			}
			$saved[] = $result;
		}
		if ( $undo ) {
			Activity_Log::set_undo(
				array(
					'action' => 'batch',
					'items'  => $undo,
				)
			);
		}
		Activity_Log::set_object( 'post', (int) ( $saved[0]['id'] ?? 0 ) );
		return array(
			'saved'  => $saved,
			'failed' => $failed,
		);
	}

	/**
	 * Creates many posts.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function bulk_create( $input ) {
		return self::run_bulk(
			(array) $input['items'],
			static function ( $item ) {
				$type = get_post_type_object( $item['post_type'] ?? 'post' );
				if ( $type && ! current_user_can( $type->cap->create_posts ) ) {
					return new WP_Error( 'viagent_production_forbidden', __( 'You are not allowed to create this type of content.', 'viagent-production' ) );
				}
				return self::create_post( $item );
			}
		);
	}

	/**
	 * Updates many posts.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function bulk_update( $input ) {
		return self::run_bulk(
			(array) $input['items'],
			static function ( $item ) {
				if ( ! current_user_can( 'edit_post', (int) ( $item['id'] ?? 0 ) ) ) {
					return new WP_Error( 'viagent_production_forbidden', __( 'You are not allowed to edit this item.', 'viagent-production' ) );
				}
				return self::update_post( $item );
			}
		);
	}

	/**
	 * Duplicates a post.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function duplicate_post( $input ) {
		$source = self::find( (int) $input['id'] );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$type   = get_post_type_object( $source->post_type );
		$status = $input['status'] ?? 'draft';
		if ( ! current_user_can( $type->cap->create_posts ) ) {
			return new WP_Error( 'viagent_production_forbidden', __( 'You are not allowed to create this type of content.', 'viagent-production' ) );
		}
		$allowed = self::check_status( $status, $type );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'      => $source->post_type,
					'post_title'     => $input['title'] ?? $source->post_title . ' ' . __( '(copy)', 'viagent-production' ),
					'post_content'   => $source->post_content,
					'post_excerpt'   => $source->post_excerpt,
					'post_status'    => $status,
					'post_parent'    => $source->post_parent,
					'menu_order'     => $source->menu_order,
					'comment_status' => $source->comment_status,
					'ping_status'    => $source->ping_status,
					'post_password'  => $source->post_password,
					'post_author'    => get_current_user_id(),
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		// Only copy custom fields this connection could set itself, and only
		// terms of taxonomies it may assign.
		$copy = get_post( $id );
		$skip = array_merge( Meta::INTERNAL_KEYS, array( '_wp_old_slug' ) );
		foreach ( get_post_meta( $source->ID ) as $key => $values ) {
			if ( in_array( $key, $skip, true ) || ! Meta::is_allowed_key( $copy, (string) $key ) ) {
				continue;
			}
			foreach ( $values as $value ) {
				add_post_meta( $id, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
		foreach ( get_object_taxonomies( $source->post_type, 'objects' ) as $taxonomy ) {
			if ( ! current_user_can( $taxonomy->cap->assign_terms ) ) {
				continue;
			}
			$terms = wp_get_object_terms( $source->ID, $taxonomy->name, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) && $terms ) {
				wp_set_object_terms( $id, $terms, $taxonomy->name );
			}
		}

		Activity_Log::set_object( 'post', $id );
		Activity_Log::set_undo(
			array(
				'action'  => 'trash_post',
				'post_id' => $id,
			)
		);
		return array_merge( self::saved_response( get_post( $id ) ), array( 'copied_from' => $source->ID ) );
	}

	/**
	 * Finds and replaces text in a post.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function replace_in_post( $input ) {
		$post = self::find( (int) $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$editable = Policy::check_post_editable( $post );
		if ( is_wp_error( $editable ) ) {
			return $editable;
		}

		$limit = (int) ( $input['limit'] ?? 0 );
		$regex = ! empty( $input['regex'] );
		if ( $regex && strlen( $input['find'] ) > self::MAX_REGEX_LENGTH ) {
			/* translators: %d: maximum pattern length */
			return new WP_Error( 'viagent_production_invalid_regex', sprintf( __( 'find is too long for a regular expression (maximum %d characters). Use a shorter pattern, or a plain-text search.', 'viagent-production' ), self::MAX_REGEX_LENGTH ) );
		}
		$pattern = $regex ? '~' . str_replace( '~', '\\~', $input['find'] ) . '~u' : '~' . preg_quote( $input['find'], '~' ) . '~u';
		if ( $regex && false === @preg_match( $pattern, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- testing whether the pattern compiles.
			return new WP_Error( 'viagent_production_invalid_regex', __( 'find is not a valid regular expression.', 'viagent-production' ) );
		}
		$replace = $regex ? $input['replace'] : str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $input['replace'] );

		$fields = array( 'post_content' );
		if ( ! empty( $input['in_title'] ) ) {
			$fields[] = 'post_title';
		}
		if ( ! empty( $input['in_excerpt'] ) ) {
			$fields[] = 'post_excerpt';
		}

		$data  = array( 'ID' => $post->ID );
		$total = 0;
		foreach ( $fields as $field ) {
			$count    = 0;
			$replaced = preg_replace( $pattern, $replace, $post->$field, $limit > 0 ? $limit - $total : -1, $count );
			if ( null === $replaced || PREG_NO_ERROR !== preg_last_error() ) {
				// A failed match (e.g. backtracking limit) must never save an empty field.
				/* translators: %s: PCRE error message */
				return new WP_Error( 'viagent_production_invalid_regex', sprintf( __( 'The regular expression could not be run against this content (%s). Nothing changed. Use a simpler pattern.', 'viagent-production' ), preg_last_error_msg() ) );
			}
			$data[ $field ] = $replaced;
			$total         += $count;
		}
		if ( 0 === $total ) {
			return new WP_Error( 'viagent_production_no_match', __( 'The text was not found, so nothing changed. Read the content with get_post to check the exact markup.', 'viagent-production' ) );
		}

		Activity_Log::set_object( 'post', $post->ID );
		Activity_Log::set_undo( self::snapshot( $post ) );
		$result = wp_update_post( wp_slash( $data ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array_merge( self::saved_response( get_post( $post->ID ) ), array( 'replacements' => $total ) );
	}

	/**
	 * Input schema for editable post fields shared by create and update.
	 *
	 * @return array
	 */
	private static function field_schema() {
		return array(
			'title'             => array( 'type' => 'string' ),
			'content'           => array(
				'type'        => 'string',
				'description' => __( 'Content as block markup, HTML or Markdown (see content_format).', 'viagent-production' ),
			),
			'content_format'    => array(
				'type'        => 'string',
				'enum'        => Content_Format::FORMATS,
				'default'     => 'auto',
				'description' => __( '"auto" (default) detects it: block markup is kept, HTML and Markdown are converted to core blocks (headings, paragraphs, lists, quotes, code, images; tables and embeds become HTML blocks). Post types without the block editor keep HTML.', 'viagent-production' ),
			),
			'excerpt'           => array( 'type' => 'string' ),
			'status'            => array(
				'type'        => 'string',
				'enum'        => array( 'draft', 'pending', 'publish', 'future', 'private' ),
				'description' => __( 'Use "future" with a date to schedule.', 'viagent-production' ),
			),
			'slug'              => array( 'type' => 'string' ),
			'date'              => array(
				'type'        => 'string',
				'description' => __( 'Publish date in site time, "YYYY-MM-DD HH:MM:SS".', 'viagent-production' ),
			),
			'parent'            => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Parent ID for hierarchical types such as pages.', 'viagent-production' ),
			),
			'menu_order'        => array( 'type' => 'integer' ),
			'template'          => array(
				'type'        => 'string',
				'description' => __( 'Page template slug; empty string for the default.', 'viagent-production' ),
			),
			'comment_status'    => array(
				'type' => 'string',
				'enum' => array( 'open', 'closed' ),
			),
			'terms'             => array(
				'type'                 => 'object',
				'description'          => __( 'Terms per taxonomy, replacing existing ones, e.g. {"category": ["News"], "post_tag": ["ai", "wordpress"]}.', 'viagent-production' ),
				'additionalProperties' => array(
					'type'  => 'array',
					'items' => array( 'type' => array( 'string', 'integer' ) ),
				),
			),
			'featured_image_id' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Media library ID; 0 removes the featured image.', 'viagent-production' ),
			),
			'meta'              => array(
				'type'                 => 'object',
				'description'          => __( 'Custom fields to set, e.g. {"price": "19.99", "event_date": "2026-05-01"}. null deletes a field. Fields private to plugins (starting with "_") need the Full control level.', 'viagent-production' ),
				'additionalProperties' => true,
			),
			'author'            => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Author user ID (see list_users).', 'viagent-production' ),
			),
			'password'          => array(
				'type'        => 'string',
				'description' => __( 'Password-protects the item; empty string removes the password.', 'viagent-production' ),
			),
			'sticky'            => array(
				'type'        => 'boolean',
				'description' => __( 'Pin a post to the top of the blog (posts only).', 'viagent-production' ),
			),
			'format'            => array(
				'type'        => 'string',
				'enum'        => array( 'standard', 'aside', 'chat', 'gallery', 'link', 'image', 'quote', 'status', 'video', 'audio' ),
				'description' => __( 'Post format, when the theme supports it.', 'viagent-production' ),
			),
			'ping_status'       => array(
				'type' => 'string',
				'enum' => array( 'open', 'closed' ),
			),
		);
	}

	/**
	 * Capability check on a post; missing posts pass so the tool can say "not found".
	 *
	 * @param string $capability Meta capability, e.g. "edit_post".
	 * @param int    $id         Post ID.
	 * @return bool
	 */
	private static function can( $capability, $id ) {
		$post = self::find( $id );
		return is_wp_error( $post ) ? current_user_can( 'edit_posts' ) : current_user_can( $capability, $post->ID );
	}

	/**
	 * Checks the requested status against draft-only mode and publishing rights.
	 *
	 * @param string        $status    Status.
	 * @param \WP_Post_Type $type      Post type.
	 * @return true|WP_Error
	 */
	private static function check_status( $status, $type ) {
		$allowed = Policy::check_status( $status );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( in_array( $status, array( 'publish', 'future', 'private' ), true ) && ! current_user_can( $type->cap->publish_posts ) ) {
			return new WP_Error( 'viagent_production_cannot_publish', __( 'You are not allowed to publish this type of content. Save it as "pending" for review instead.', 'viagent-production' ) );
		}
		return true;
	}

	/**
	 * Maps tool input to wp_insert_post() fields.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	private static function post_data( array $input ) {
		$map  = array(
			'title'          => 'post_title',
			'content'        => 'post_content',
			'excerpt'        => 'post_excerpt',
			'status'         => 'post_status',
			'slug'           => 'post_name',
			'parent'         => 'post_parent',
			'menu_order'     => 'menu_order',
			'template'       => 'page_template',
			'comment_status' => 'comment_status',
			'ping_status'    => 'ping_status',
			'password'       => 'post_password',
			'author'         => 'post_author',
		);
		$data = array();
		foreach ( $map as $from => $to ) {
			if ( array_key_exists( $from, $input ) ) {
				$data[ $to ] = $input[ $from ];
			}
		}
		if ( ! empty( $input['date'] ) ) {
			$data['post_date']     = $input['date'];
			$data['post_date_gmt'] = get_gmt_from_date( $input['date'] );
			$data['edit_date']     = true;
		}
		return $data;
	}

	/**
	 * Sets terms (names, slugs or IDs) and the featured image after a save.
	 *
	 * @param WP_Post $post  Post.
	 * @param array   $input Input.
	 * @return true|WP_Error
	 */
	private static function apply_relations( WP_Post $post, array $input ) {
		foreach ( (array) ( $input['terms'] ?? array() ) as $taxonomy => $values ) {
			$result = Taxonomies::set_post_terms( $post, $taxonomy, (array) $values, false );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( isset( $input['meta'] ) && is_array( $input['meta'] ) ) {
			$result = Meta::set_values( $post, $input['meta'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		if ( isset( $input['sticky'] ) ) {
			if ( 'post' !== $post->post_type ) {
				return new WP_Error( 'viagent_production_unsupported_field', __( 'Only posts can be sticky.', 'viagent-production' ) );
			}
			// Sticky posts are pinned on the site's front page, so like core's REST API this
			// needs the right to edit others' posts, not just this one.
			if ( ! current_user_can( get_post_type_object( $post->post_type )->cap->edit_others_posts ) ) {
				return new WP_Error( 'viagent_production_forbidden', __( 'You are not allowed to make posts sticky. The rest of the changes were saved.', 'viagent-production' ) );
			}
			$input['sticky'] ? stick_post( $post->ID ) : unstick_post( $post->ID );
		}
		if ( isset( $input['format'] ) ) {
			if ( ! post_type_supports( $post->post_type, 'post-formats' ) ) {
				return new WP_Error( 'viagent_production_unsupported_field', __( 'This content type does not support post formats.', 'viagent-production' ) );
			}
			set_post_format( $post, 'standard' === $input['format'] ? '' : $input['format'] );
		}

		if ( isset( $input['featured_image_id'] ) ) {
			$image_id = (int) $input['featured_image_id'];
			if ( 0 === $image_id ) {
				delete_post_thumbnail( $post );
			} elseif ( ! wp_attachment_is_image( $image_id ) ) {
				return new WP_Error( 'viagent_production_invalid_image', __( 'featured_image_id is not an image in the media library.', 'viagent-production' ) );
			} else {
				set_post_thumbnail( $post, $image_id );
			}
		}
		return true;
	}

	/**
	 * Converts content to the right format and checks author changes.
	 *
	 * @param array         $input Input (content is replaced by the prepared version).
	 * @param \WP_Post_Type $type  Post type.
	 * @param int           $current_author Current author ID (0 for new posts).
	 * @return array|WP_Error Input, or an error.
	 */
	private static function prepare_input( array $input, $type, $current_author = 0 ) {
		if ( isset( $input['meta'] ) && is_array( $input['meta'] ) ) {
			$stub = new WP_Post( (object) array( 'post_type' => $type->name ) );
			foreach ( array_keys( $input['meta'] ) as $key ) {
				if ( ! Meta::is_allowed_key( $stub, (string) $key ) ) {
					/* translators: %s: meta key */
					return new WP_Error( 'viagent_production_protected_meta', sprintf( __( 'The custom field "%s" is private to a plugin. It can only be changed with the Full control level. Nothing was saved.', 'viagent-production' ), $key ) );
				}
			}
		}
		if ( isset( $input['content'] ) ) {
			$input['content'] = Content_Format::prepare( $input['content'], $input['content_format'] ?? 'auto', use_block_editor_for_post_type( $type->name ) );
		}
		$owner = $current_author ? $current_author : get_current_user_id();
		if ( isset( $input['author'] ) && $owner !== (int) $input['author'] ) {
			$author = get_userdata( (int) $input['author'] );
			if ( ! $author ) {
				return new WP_Error( 'viagent_production_invalid_user', __( 'author must be the ID of an existing user.', 'viagent-production' ) );
			}
			if ( ! current_user_can( $type->cap->edit_others_posts ) ) {
				return new WP_Error( 'viagent_production_forbidden', __( 'You are not allowed to give content to another author.', 'viagent-production' ) );
			}
		}
		return $input;
	}

	/**
	 * Warnings about block markup problems in saved content.
	 *
	 * @param WP_Post $post Post.
	 * @return string[]
	 */
	public static function content_warnings( WP_Post $post ) {
		if ( ! use_block_editor_for_post_type( $post->post_type ) || false === strpos( $post->post_content, '<!-- wp:' ) ) {
			return array();
		}
		return array_column( Content_Format::validate( $post->post_content ), 'message' );
	}

	/**
	 * Creates a post.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function create_post( $input ) {
		$post_type = $input['post_type'] ?? 'post';
		$types     = self::post_types();
		if ( ! isset( $types[ $post_type ] ) ) {
			return new WP_Error( 'viagent_production_invalid_post_type', sprintf( /* translators: %s: post type */ __( 'Unknown post type "%s". Use list_post_types to see available types.', 'viagent-production' ), $post_type ) );
		}

		$input['status'] = $input['status'] ?? 'draft';
		$allowed         = self::check_status( $input['status'], $types[ $post_type ] );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$input = self::prepare_input( $input, $types[ $post_type ] );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		$data = array_merge(
			array( 'post_author' => get_current_user_id() ),
			self::post_data( $input ),
			array( 'post_type' => $post_type )
		);

		$id = wp_insert_post( wp_slash( $data ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		Activity_Log::set_object( 'post', $id );
		Activity_Log::set_undo(
			array(
				'action'  => 'trash_post',
				'post_id' => $id,
			)
		);

		$post     = get_post( $id );
		$relation = self::apply_relations( $post, $input );

		return self::saved_response( $post, $relation );
	}

	/**
	 * Updates a post.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_post( $input ) {
		$post = self::find( (int) $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$editable = Policy::check_post_editable( $post );
		if ( is_wp_error( $editable ) ) {
			return $editable;
		}
		if ( isset( $input['status'] ) && $input['status'] !== $post->post_status ) {
			$allowed = self::check_status( $input['status'], get_post_type_object( $post->post_type ) );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}

		$input = self::prepare_input( $input, get_post_type_object( $post->post_type ), (int) $post->post_author );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		$data = self::post_data( $input );
		if ( empty( $data ) && ! array_intersect( array( 'terms', 'featured_image_id', 'meta', 'sticky', 'format' ), array_keys( $input ) ) ) {
			return new WP_Error( 'viagent_production_nothing_to_update', __( 'Pass at least one field to change.', 'viagent-production' ) );
		}

		Activity_Log::set_object( 'post', $post->ID );
		Activity_Log::set_undo( self::snapshot( $post, $input ) );

		if ( $data ) {
			$data['ID'] = $post->ID;
			$result     = wp_update_post( wp_slash( $data ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$post     = get_post( $post->ID );
		$relation = self::apply_relations( $post, $input );

		return self::saved_response( $post, $relation );
	}

	/**
	 * Undo instructions that restore a post to its current state.
	 *
	 * @param WP_Post $post  Post before the change.
	 * @param array   $input Change being made (decides which relations to snapshot).
	 * @return array
	 */
	public static function snapshot( WP_Post $post, array $input = array() ) {
		$undo = array(
			'action'  => 'restore_post_fields',
			'post_id' => $post->ID,
			'fields'  => array(
				'post_title'     => $post->post_title,
				'post_content'   => $post->post_content,
				'post_excerpt'   => $post->post_excerpt,
				'post_status'    => $post->post_status,
				'post_name'      => $post->post_name,
				'post_parent'    => $post->post_parent,
				'menu_order'     => $post->menu_order,
				'comment_status' => $post->comment_status,
				'ping_status'    => $post->ping_status,
				'post_password'  => $post->post_password,
				'post_author'    => $post->post_author,
				'post_date'      => $post->post_date,
				'post_date_gmt'  => $post->post_date_gmt,
				'edit_date'      => true,
			),
		);

		if ( isset( $input['terms'] ) ) {
			$undo['terms'] = array();
			foreach ( array_keys( (array) $input['terms'] ) as $taxonomy ) {
				$ids                        = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
				$undo['terms'][ $taxonomy ] = is_wp_error( $ids ) ? array() : $ids;
			}
		}
		if ( isset( $input['featured_image_id'] ) ) {
			$undo['thumbnail_id'] = (int) get_post_thumbnail_id( $post );
		}
		if ( isset( $input['meta'] ) && is_array( $input['meta'] ) ) {
			$undo['meta'] = array();
			foreach ( array_keys( $input['meta'] ) as $key ) {
				$undo['meta'][ $key ] = metadata_exists( 'post', $post->ID, $key ) ? get_post_meta( $post->ID, $key, true ) : null;
			}
		}
		if ( isset( $input['sticky'] ) ) {
			$undo['sticky'] = is_sticky( $post->ID );
		}
		if ( isset( $input['format'] ) ) {
			$format         = get_post_format( $post );
			$undo['format'] = $format ? $format : '';
		}

		return $undo;
	}

	/**
	 * Response after saving a post; relation errors are reported but the save stands.
	 *
	 * @param WP_Post       $post     Saved post.
	 * @param true|WP_Error $relation Result of applying terms/featured image.
	 * @return array
	 */
	public static function saved_response( WP_Post $post, $relation = true ) {
		$response = self::summary( $post );
		if ( 'publish' !== $post->post_status ) {
			$response['preview_link'] = get_preview_post_link( $post );
		}
		if ( is_wp_error( $relation ) ) {
			$response['warning'] = $relation->get_error_message();
		}
		$warnings = self::content_warnings( $post );
		if ( $warnings ) {
			$response['content_warnings'] = $warnings;
		}
		return $response;
	}

	/**
	 * Trashes or permanently deletes a post.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function delete_post( $input ) {
		$post = self::find( (int) $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		Activity_Log::set_object( 'post', $post->ID );

		if ( ! empty( $input['force'] ) ) {
			if ( ! Policy::allows_permanent_delete() ) {
				return new WP_Error( 'viagent_production_permanent_delete_disabled', __( 'Permanent deletion is turned off on this site. Move the item to the trash instead (omit force).', 'viagent-production' ) );
			}
			$title = $post->post_title;
			if ( ! wp_delete_post( $post->ID, true ) ) {
				return new WP_Error( 'viagent_production_delete_failed', __( 'Could not delete the item.', 'viagent-production' ) );
			}
			return array(
				'id'      => $post->ID,
				'title'   => $title,
				'deleted' => true,
			);
		}

		if ( 'trash' === $post->post_status ) {
			return new WP_Error( 'viagent_production_already_trashed', __( 'This item is already in the trash.', 'viagent-production' ) );
		}
		if ( ! wp_trash_post( $post->ID ) ) {
			return new WP_Error( 'viagent_production_delete_failed', __( 'Could not move the item to the trash.', 'viagent-production' ) );
		}

		Activity_Log::set_undo(
			array(
				'action'  => 'untrash_post',
				'post_id' => $post->ID,
			)
		);

		return array(
			'id'      => $post->ID,
			'title'   => $post->post_title,
			'trashed' => true,
		);
	}

	/**
	 * Restores a post from the trash.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function restore_post( $input ) {
		$post = self::find( (int) $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( 'trash' !== $post->post_status ) {
			return new WP_Error( 'viagent_production_not_trashed', __( 'This item is not in the trash.', 'viagent-production' ) );
		}

		// In draft-only mode a restored item always comes back as a draft, even on
		// sites that restore the previous (possibly published) status.
		$draft_only = Policy::is_draft_only();
		$as_draft   = static function () {
			return 'draft';
		};
		if ( $draft_only ) {
			add_filter( 'wp_untrash_post_status', $as_draft, PHP_INT_MAX );
		}
		$restored = wp_untrash_post( $post->ID );
		if ( $draft_only ) {
			remove_filter( 'wp_untrash_post_status', $as_draft, PHP_INT_MAX );
		}
		if ( ! $restored ) {
			return new WP_Error( 'viagent_production_restore_failed', __( 'Could not restore the item.', 'viagent-production' ) );
		}

		Activity_Log::set_object( 'post', $post->ID );
		Activity_Log::set_undo(
			array(
				'action'  => 'trash_post',
				'post_id' => $post->ID,
			)
		);

		return self::summary( get_post( $post->ID ) );
	}

	/**
	 * Lists revisions of a post.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function list_revisions( $input ) {
		$post = self::find( (int) $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$items = array();
		foreach ( wp_get_post_revisions( $post->ID, array( 'posts_per_page' => 50 ) ) as $revision ) {
			$items[] = array(
				'revision_id' => $revision->ID,
				'date'        => $revision->post_modified,
				'author'      => get_the_author_meta( 'display_name', (int) $revision->post_author ),
				'title'       => $revision->post_title,
				'preview'     => wp_trim_words( wp_strip_all_tags( $revision->post_content ), 30 ),
			);
		}
		return $items;
	}

	/**
	 * Restores a revision.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function restore_revision( $input ) {
		$revision = wp_get_post_revision( (int) $input['revision_id'] );
		$post     = $revision ? self::find( $revision->post_parent ) : null;
		if ( ! $revision || is_wp_error( $post ) ) {
			return new WP_Error( 'viagent_production_not_found', __( 'No revision found with that ID.', 'viagent-production' ) );
		}

		$editable = Policy::check_post_editable( $post );
		if ( is_wp_error( $editable ) ) {
			return $editable;
		}

		Activity_Log::set_object( 'post', $post->ID );
		Activity_Log::set_undo( self::snapshot( $post ) );

		if ( ! wp_restore_post_revision( $revision->ID ) ) {
			return new WP_Error( 'viagent_production_restore_failed', __( 'Could not restore the revision.', 'viagent-production' ) );
		}
		return self::summary( get_post( $post->ID ) );
	}

	/**
	 * Post types the AI may work with: public ones plus any shown in the admin UI,
	 * excluding attachments (handled by media tools).
	 *
	 * @return \WP_Post_Type[]
	 */
	public static function post_types() {
		$types = get_post_types( array( 'show_ui' => true ), 'objects' );
		unset( $types['attachment'], $types['wp_template'], $types['wp_template_part'], $types['wp_navigation'], $types['wp_font_family'], $types['wp_font_face'], $types['wp_global_styles'] );

		/**
		 * Filters post types exposed to AI agents.
		 *
		 * @param \WP_Post_Type[] $types Post type objects keyed by name.
		 */
		return apply_filters( 'viagent_production_post_types', $types );
	}

	/**
	 * Exposed post types the current user may browse: publicly viewable types,
	 * plus non-public types only for users who can edit them.
	 *
	 * @return \WP_Post_Type[] Post type objects keyed by name.
	 */
	public static function readable_post_types() {
		return array_filter(
			self::post_types(),
			static function ( $type ) {
				return is_post_type_viewable( $type ) || current_user_can( $type->cap->edit_posts );
			}
		);
	}

	/**
	 * Statuses the current user may list for a post type. Anything beyond
	 * "publish" requires the type's own edit capability.
	 *
	 * @param string $post_type Post type.
	 * @return string[]
	 */
	public static function readable_statuses( $post_type ) {
		$type = get_post_type_object( $post_type );
		if ( $type && current_user_can( $type->cap->edit_posts ) ) {
			return array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );
		}
		return array( 'publish' );
	}

	/**
	 * Whether the current user may read a post. Missing posts pass so that the
	 * execute callback can report a helpful "not found" error.
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	public static function can_read( $id ) {
		$post = self::find( $id );
		if ( is_wp_error( $post ) ) {
			return current_user_can( 'read' );
		}
		if ( 'publish' === $post->post_status && is_post_type_viewable( $post->post_type ) ) {
			return current_user_can( 'read' );
		}
		return current_user_can( 'read_post', $post->ID ) && current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Gets a post of an exposed post type.
	 *
	 * @param int $id Post ID.
	 * @return WP_Post|WP_Error
	 */
	public static function find( $id ) {
		$post = get_post( $id );
		if ( ! $post || ! isset( self::post_types()[ $post->post_type ] ) ) {
			return new WP_Error( 'viagent_production_not_found', __( 'No post found with that ID.', 'viagent-production' ) );
		}
		return $post;
	}

	/**
	 * Lists post types.
	 *
	 * @return array
	 */
	public static function list_post_types() {
		$result = array();
		foreach ( self::readable_post_types() as $type ) {
			$result[] = array(
				'name'         => $type->name,
				'label'        => $type->label,
				'description'  => $type->description,
				'hierarchical' => (bool) $type->hierarchical,
				'public'       => (bool) $type->public,
				'block_editor' => use_block_editor_for_post_type( $type->name ),
				'taxonomies'   => array_values( get_object_taxonomies( $type->name ) ),
				'can_create'   => current_user_can( $type->cap->create_posts ),
			);
		}
		return $result;
	}

	/**
	 * Lists posts.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function list_posts( $input ) {
		$post_type = $input['post_type'] ?? 'post';
		if ( ! isset( self::readable_post_types()[ $post_type ] ) ) {
			return new WP_Error( 'viagent_production_invalid_post_type', sprintf( /* translators: %s: post type */ __( 'Unknown post type "%s". Use list_post_types to see available types.', 'viagent-production' ), $post_type ) );
		}

		$allowed = self::readable_statuses( $post_type );
		$status  = $input['status'] ?? 'any';
		if ( 'any' !== $status && ! in_array( $status, $allowed, true ) ) {
			return new WP_Error( 'viagent_production_forbidden_status', __( 'You are not allowed to list items with that status.', 'viagent-production' ) );
		}
		// "any" never includes the trash.
		$any = array_values( array_diff( $allowed, array( 'trash' ) ) );

		$args = array(
			'post_type'           => $post_type,
			'post_status'         => 'any' === $status ? $any : $status,
			'posts_per_page'      => (int) ( $input['per_page'] ?? 20 ),
			'paged'               => (int) ( $input['page'] ?? 1 ),
			'orderby'             => $input['orderby'] ?? 'date',
			'order'               => strtoupper( $input['order'] ?? 'desc' ),
			'ignore_sticky_posts' => true,
			'perm'                => 'readable',
		);
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = $input['search'];
		}
		if ( ! empty( $input['author'] ) ) {
			$args['author'] = (int) $input['author'];
		}
		if ( ! empty( $input['category'] ) ) {
			$args['category_name'] = sanitize_title( $input['category'] );
		}
		if ( ! empty( $input['tag'] ) ) {
			$args['tag'] = sanitize_title( $input['tag'] );
		}
		if ( ! empty( $input['taxonomy'] ) && ! empty( $input['terms'] ) ) {
			if ( ! taxonomy_exists( $input['taxonomy'] ) ) {
				/* translators: %s: taxonomy */
				return new WP_Error( 'viagent_production_invalid_taxonomy', sprintf( __( 'Unknown taxonomy "%s". See list_taxonomies.', 'viagent-production' ), $input['taxonomy'] ) );
			}
			$numeric           = array_filter( (array) $input['terms'], 'is_numeric' ) === (array) $input['terms'];
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- filtering is what was asked for.
				array(
					'taxonomy' => $input['taxonomy'],
					'field'    => $numeric ? 'term_id' : 'slug',
					'terms'    => $numeric ? array_map( 'intval', $input['terms'] ) : array_map( 'sanitize_title', $input['terms'] ),
				),
			);
		}
		if ( ! empty( $input['meta_key'] ) ) {
			$post_stub = new WP_Post( (object) array( 'post_type' => $post_type ) );
			if ( ! Meta::is_allowed_key( $post_stub, $input['meta_key'] ) ) {
				return new WP_Error( 'viagent_production_protected_meta', __( 'This custom field is private to a plugin and cannot be used.', 'viagent-production' ) );
			}
			$clause = array(
				'key'     => $input['meta_key'],
				'compare' => $input['meta_compare'] ?? '=',
			);
			if ( isset( $input['meta_value'] ) && ! in_array( $clause['compare'], array( 'EXISTS', 'NOT EXISTS' ), true ) ) {
				$clause['value'] = $input['meta_value'];
				if ( is_numeric( $input['meta_value'] ) && in_array( $clause['compare'], array( '>', '>=', '<', '<=' ), true ) ) {
					$clause['type'] = 'NUMERIC';
				}
			}
			$args['meta_query'] = array( $clause ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- filtering is what was asked for.
			if ( in_array( $args['orderby'], array( 'meta_value', 'meta_value_num' ), true ) ) {
				$args['meta_key'] = $input['meta_key']; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- ordering by the field that was asked for.
			}
		}
		if ( isset( $input['parent'] ) ) {
			$args['post_parent'] = (int) $input['parent'];
		}
		if ( ! empty( $input['include'] ) ) {
			$args['post__in'] = array_map( 'intval', $input['include'] );
		}
		if ( ! empty( $input['date_after'] ) || ! empty( $input['date_before'] ) ) {
			$args['date_query'] = array(
				array_filter(
					array(
						'after'     => $input['date_after'] ?? null,
						'before'    => $input['date_before'] ?? null,
						'inclusive' => true,
					)
				),
			);
		}

		// Unpublished items of other authors are only visible to users who may edit
		// others' posts; WP_Query's "readable" permission only covers private ones.
		// With "any" the query stays open (so others' published items still show) and the
		// per-item can_read() filter below hides the rest.
		$type = get_post_type_object( $post_type );
		if ( 'any' !== $status && array_diff( (array) $args['post_status'], array( 'publish' ) ) && ! current_user_can( $type->cap->edit_others_posts ) ) {
			if ( ! empty( $args['author'] ) && get_current_user_id() !== (int) $args['author'] ) {
				return new WP_Error( 'viagent_production_forbidden_status', __( 'You are not allowed to list unpublished items of other authors.', 'viagent-production' ) );
			}
			$args['author'] = get_current_user_id();
		}

		$query = new \WP_Query( $args );
		$full  = ! empty( $input['with_content'] );
		$posts = array_values(
			array_filter(
				$query->posts,
				static function ( $post ) {
					return self::can_read( $post->ID );
				}
			)
		);

		return array(
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $args['paged'],
			'items'       => array_map(
				static function ( $post ) use ( $full ) {
					if ( ! $full ) {
						return self::summary( $post );
					}
					return self::get_post(
						array(
							'id'           => $post->ID,
							'include_meta' => true,
						)
					);
				},
				$posts
			),
		);
	}

	/**
	 * Gets one post with full content.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_post( $input ) {
		$post = self::find( (int) $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! self::can_read( $post->ID ) ) {
			return new WP_Error( 'viagent_production_forbidden', __( 'You are not allowed to read this item.', 'viagent-production' ) );
		}

		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->show_ui ) {
				continue;
			}
			$assigned = get_the_terms( $post, $taxonomy->name );
			if ( is_array( $assigned ) ) {
				$terms[ $taxonomy->name ] = array_map(
					static function ( $term ) {
						return array(
							'id'   => $term->term_id,
							'name' => $term->name,
							'slug' => $term->slug,
						);
					},
					$assigned
				);
			}
		}

		$thumbnail_id = (int) get_post_thumbnail_id( $post );
		$extra        = array();
		if ( ! empty( $input['include_meta'] ) && current_user_can( 'edit_post', $post->ID ) ) {
			$extra['meta'] = Meta::get_values( $post );
		}
		$warnings = self::content_warnings( $post );
		if ( $warnings ) {
			$extra['content_warnings'] = $warnings;
		}

		$result = array_merge(
			self::summary( $post ),
			array(
				'content'        => $post->post_content,
				'excerpt'        => $post->post_excerpt,
				'parent'         => (int) $post->post_parent,
				'menu_order'     => (int) $post->menu_order,
				'template'       => get_page_template_slug( $post ),
				'comment_status' => $post->comment_status,
				'terms'          => $terms,
				'featured_image' => $thumbnail_id ? array(
					'id'  => $thumbnail_id,
					'url' => wp_get_attachment_url( $thumbnail_id ),
				) : null,
				'author_id'      => (int) $post->post_author,
				'sticky'         => 'post' === $post->post_type ? is_sticky( $post->ID ) : null,
				'format'         => post_type_supports( $post->post_type, 'post-formats' ) ? ( get_post_format( $post ) ? get_post_format( $post ) : 'standard' ) : null,
				'has_password'   => '' !== $post->post_password,
				'block_editor'   => use_block_editor_for_post_type( $post->post_type ),
			),
			$extra
		);
		if ( current_user_can( 'edit_post', $post->ID ) && '' !== $post->post_password ) {
			$result['password'] = $post->post_password;
		}
		return $result;
	}

	/**
	 * Compact representation of a post.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public static function summary( WP_Post $post ) {
		return array(
			'id'        => $post->ID,
			'type'      => $post->post_type,
			'title'     => $post->post_title,
			'status'    => $post->post_status,
			'slug'      => $post->post_name,
			'author'    => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'date'      => $post->post_date,
			'modified'  => $post->post_modified,
			'link'      => get_permalink( $post ),
			'edit_link' => current_user_can( 'edit_post', $post->ID ) ? get_edit_post_link( $post->ID, 'raw' ) : null,
		);
	}
}
