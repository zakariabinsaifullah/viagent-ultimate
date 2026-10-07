<?php
/**
 * Block abilities: discover block types and patterns to build layouts with.
 *
 * @package ViagentProduction
 */

namespace ViagentProduction\Abilities;

use ViagentProduction\Log\Activity_Log;
use ViagentProduction\Security\Policy;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Block abilities.
 */
class Blocks {

	/**
	 * Registers abilities.
	 */
	public static function register() {
		Abilities::add(
			'list-block-types',
			array(
				'category'    => 'viagent-production-blocks',
				'label'       => __( 'List block types', 'viagent-production' ),
				'description' => __( 'Lists blocks available on this site (core and from plugins) that can be used in content markup.', 'viagent-production' ),
				'input'       => array(
					'search'   => array( 'type' => 'string' ),
					'category' => array(
						'type'        => 'string',
						'description' => __( 'e.g. "text", "media", "design", "widgets", "theme", "embed".', 'viagent-production' ),
					),
				),
				'execute'     => array( self::class, 'list_block_types' ),
				'permission'  => 'edit_posts',
				'meta'        => Abilities::read_meta(),
			)
		);

		Abilities::add(
			'list-patterns',
			array(
				'category'    => 'viagent-production-blocks',
				'label'       => __( 'List patterns', 'viagent-production' ),
				'description' => __( 'Lists ready-made block patterns (theme, plugin and saved patterns) such as heroes, pricing tables or footers. Use get_pattern to fetch the markup and insert it into content.', 'viagent-production' ),
				'input'       => array(
					'search'   => array( 'type' => 'string' ),
					'category' => array( 'type' => 'string' ),
				),
				'execute'     => array( self::class, 'list_patterns' ),
				'permission'  => 'edit_posts',
				'meta'        => Abilities::read_meta(),
			)
		);

		Abilities::add(
			'get-pattern',
			array(
				'category'    => 'viagent-production-blocks',
				'label'       => __( 'Get pattern', 'viagent-production' ),
				'description' => __( 'Gets the block markup of a pattern by name (from list_patterns). Saved patterns use the name "saved/<id>".', 'viagent-production' ),
				'input'       => array( 'name' => array( 'type' => 'string' ) ),
				'required'    => array( 'name' ),
				'execute'     => array( self::class, 'get_pattern' ),
				'permission'  => 'edit_posts',
				'meta'        => Abilities::read_meta(),
			)
		);

		$post_id = array(
			'type'    => 'integer',
			'minimum' => 1,
		);

		Abilities::add(
			'get-post-blocks',
			array(
				'category'    => 'viagent-production-blocks',
				'label'       => __( 'Get block outline', 'viagent-production' ),
				'description' => __( 'Shows the block structure of a post or page as a tree with paths ("0", "2", "3.1" for the 2nd block inside the 4th), block names, attributes and a text preview. Pass path to get the full markup of one block. Use the paths with edit_post_blocks.', 'viagent-production' ),
				'input'       => array(
					'post_id' => $post_id,
					'path'    => array( 'type' => 'string' ),
				),
				'required'    => array( 'post_id' ),
				'execute'     => array( self::class, 'get_post_blocks' ),
				'permission'  => static function ( $input ) {
					return current_user_can( 'edit_post', (int) ( $input['post_id'] ?? 0 ) ) || ! get_post( (int) ( $input['post_id'] ?? 0 ) );
				},
				'meta'        => Abilities::read_meta(),
			)
		);

		Abilities::add(
			'edit-post-blocks',
			array(
				'category'    => 'viagent-production-blocks',
				'label'       => __( 'Edit blocks', 'viagent-production' ),
				'description' => __( 'Edits individual blocks of a post or page without resending the whole content. Operations run in order (paths refer to the content after earlier operations, so work from the last block backwards): replace (path + content), insert_before / insert_after (path + content), append / prepend (content; with path, inside that container block such as a group or column), delete (path), update_attributes (path + attributes; null removes one; only for attributes stored in the block comment). content may be block markup, HTML or Markdown.', 'viagent-production' ),
				'input'       => array(
					'post_id'    => $post_id,
					'operations' => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => 50,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'action'     => array(
									'type' => 'string',
									'enum' => array( 'replace', 'insert_before', 'insert_after', 'append', 'prepend', 'delete', 'update_attributes' ),
								),
								'path'       => array( 'type' => 'string' ),
								'content'    => array( 'type' => 'string' ),
								'attributes' => array( 'type' => 'object' ),
							),
							'required'             => array( 'action' ),
							'additionalProperties' => false,
						),
					),
				),
				'required'    => array( 'post_id', 'operations' ),
				'execute'     => array( self::class, 'edit_post_blocks' ),
				'permission'  => static function ( $input ) {
					return current_user_can( 'edit_post', (int) ( $input['post_id'] ?? 0 ) ) || ! get_post( (int) ( $input['post_id'] ?? 0 ) );
				},
				'meta'        => Abilities::write_meta( Policy::CONTENT, array( 'draft_safe' => true ) ),
			)
		);

		Abilities::add(
			'prepare-content',
			array(
				'category'    => 'viagent-production-blocks',
				'label'       => __( 'Convert and check content', 'viagent-production' ),
				'description' => __( 'Converts HTML or Markdown to block markup and checks block markup for problems (unclosed or mis-nested block comments, invalid attribute JSON, unknown block types, content outside blocks, missing synced patterns) — without saving anything.', 'viagent-production' ),
				'input'       => array(
					'content' => array( 'type' => 'string' ),
					'format'  => array(
						'type'    => 'string',
						'enum'    => Content_Format::FORMATS,
						'default' => 'auto',
					),
				),
				'required'    => array( 'content' ),
				'execute'     => array( self::class, 'prepare_content' ),
				'permission'  => 'edit_posts',
				'meta'        => Abilities::read_meta(),
			)
		);
	}

	/**
	 * Case-insensitive match on any of the given strings.
	 *
	 * @param string   $search   Search text.
	 * @param string[] $haystack Strings to search in.
	 * @return bool
	 */
	private static function matches( $search, array $haystack ) {
		return '' === $search || false !== stripos( implode( ' ', $haystack ), $search );
	}

	/**
	 * Lists block types.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function list_block_types( $input ) {
		$search   = (string) ( $input['search'] ?? '' );
		$category = (string) ( $input['category'] ?? '' );
		$result   = array();

		foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $block ) {
			if ( ! empty( $block->parent ) && empty( $search ) ) {
				continue; // Skip inner-only blocks (e.g. core/column) unless searched for.
			}
			if ( $category && $block->category !== $category ) {
				continue;
			}
			if ( ! self::matches( $search, array( $block->name, (string) $block->title, (string) $block->description, implode( ' ', (array) $block->keywords ) ) ) ) {
				continue;
			}
			$result[] = array(
				'name'        => $block->name,
				'title'       => $block->title,
				'description' => $block->description,
				'category'    => $block->category,
			);
		}
		return $result;
	}

	/**
	 * Lists patterns.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function list_patterns( $input ) {
		$search   = (string) ( $input['search'] ?? '' );
		$category = (string) ( $input['category'] ?? '' );
		$result   = array();

		foreach ( \WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			if ( ! empty( $pattern['inserter'] ) || ! isset( $pattern['inserter'] ) ) {
				$categories = (array) ( $pattern['categories'] ?? array() );
				if ( $category && ! in_array( $category, $categories, true ) ) {
					continue;
				}
				if ( ! self::matches( $search, array( $pattern['name'], $pattern['title'], $pattern['description'] ?? '', implode( ' ', (array) ( $pattern['keywords'] ?? array() ) ) ) ) ) {
					continue;
				}
				$result[] = array(
					'name'        => $pattern['name'],
					'title'       => $pattern['title'],
					'description' => $pattern['description'] ?? '',
					'categories'  => $categories,
				);
			}
		}

		foreach ( get_posts(
			array(
				'post_type'      => 'wp_block',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
			)
		) as $saved ) {
			if ( self::matches( $search, array( $saved->post_title ) ) && ! $category ) {
				$result[] = array(
					'name'        => 'saved/' . $saved->ID,
					'title'       => $saved->post_title,
					'description' => __( 'Saved pattern. Insert as <!-- wp:block {"ref":ID} /--> to keep it synced.', 'viagent-production' ),
					'categories'  => array( 'saved' ),
				);
			}
		}

		return $result;
	}

	/**
	 * Gets a pattern's markup.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_pattern( $input ) {
		$name = $input['name'];

		if ( 0 === strpos( $name, 'saved/' ) ) {
			$saved = get_post( (int) substr( $name, 6 ) );
			if ( ! $saved || 'wp_block' !== $saved->post_type || ! current_user_can( 'read_post', $saved->ID ) ) {
				return new WP_Error( 'viagent_production_not_found', __( 'No saved pattern found with that name.', 'viagent-production' ) );
			}
			return array(
				'name'         => $name,
				'title'        => $saved->post_title,
				'content'      => $saved->post_content,
				'synced_block' => sprintf( '<!-- wp:block {"ref":%d} /-->', $saved->ID ),
			);
		}

		$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( $name );
		if ( ! $pattern ) {
			return new WP_Error( 'viagent_production_not_found', __( 'No pattern found with that name. Use list_patterns.', 'viagent-production' ) );
		}
		return array(
			'name'    => $pattern['name'],
			'title'   => $pattern['title'],
			'content' => $pattern['content'],
		);
	}

	/**
	 * Converts and validates content without saving.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function prepare_content( $input ) {
		$format = $input['format'] ?? 'auto';
		$markup = Content_Format::prepare( $input['content'], $format );
		$issues = Content_Format::validate( $markup );
		$blocks = array_filter(
			parse_blocks( $markup ),
			static function ( $block ) {
				return null !== $block['blockName'];
			}
		);
		return array(
			'detected_format' => 'auto' === $format ? Content_Format::detect( $input['content'] ) : $format,
			'blocks'          => count( $blocks ),
			'valid'           => ! wp_list_filter( $issues, array( 'severity' => 'error' ) ),
			'issues'          => $issues,
			'content'         => $markup,
		);
	}

	/**
	 * A whitespace separator between top-level blocks.
	 *
	 * @return array
	 */
	private static function separator() {
		return array(
			'blockName'    => null,
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => "\n\n",
			'innerContent' => array( "\n\n" ),
		);
	}

	/**
	 * Array indexes of real (named) blocks in a list.
	 *
	 * @param array $blocks Blocks.
	 * @return int[]
	 */
	private static function real_indexes( array $blocks ) {
		$indexes = array();
		foreach ( $blocks as $index => $block ) {
			if ( null !== $block['blockName'] ) {
				$indexes[] = $index;
			}
		}
		return $indexes;
	}

	/**
	 * Builds the outline of a block list.
	 *
	 * @param array  $blocks Blocks.
	 * @param string $prefix Path prefix.
	 * @return array
	 */
	private static function outline( array $blocks, $prefix = '' ) {
		$result = array();
		foreach ( self::real_indexes( $blocks ) as $n => $index ) {
			$block = $blocks[ $index ];
			$path  = '' === $prefix ? (string) $n : $prefix . '.' . $n;
			$text  = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( implode( ' ', array_filter( $block['innerContent'], 'is_string' ) ) ) ) );
			$item  = array(
				'path'  => $path,
				'name'  => $block['blockName'],
				'attrs' => $block['attrs'] ? $block['attrs'] : new \stdClass(),
				'text'  => mb_substr( $text, 0, 100 ) . ( mb_strlen( $text ) > 100 ? '…' : '' ),
			);
			if ( $block['innerBlocks'] ) {
				$item['inner'] = self::outline( $block['innerBlocks'], $path );
			}
			$result[] = $item;
		}
		return $result;
	}

	/**
	 * Gets a post's block outline, or one block's markup.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_post_blocks( $input ) {
		$post = Content::find( (int) $input['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$blocks = parse_blocks( $post->post_content );
		if ( ! empty( $input['path'] ) ) {
			$found = self::locate( $blocks, $input['path'] );
			if ( is_wp_error( $found ) ) {
				return $found;
			}
			$list = self::get_list( $blocks, $found['parent'] );
			return array(
				'path'    => $input['path'],
				'name'    => $list[ $found['index'] ]['blockName'],
				'content' => serialize_block( $list[ $found['index'] ] ),
			);
		}
		return array(
			'post_id'  => $post->ID,
			'title'    => $post->post_title,
			'blocks'   => self::outline( $blocks ),
			'warnings' => Content::content_warnings( $post ),
		);
	}

	/**
	 * Finds a block by path.
	 *
	 * @param array  $blocks Top-level blocks.
	 * @param string $path   Path such as "3.1".
	 * @return array{parent:int[],index:int}|WP_Error Parent path as array indexes and the array index.
	 */
	private static function locate( array $blocks, $path ) {
		if ( ! preg_match( '/^\d+(\.\d+)*$/', (string) $path ) ) {
			return new WP_Error( 'viagent_production_invalid_path', __( 'A block path looks like "2" or "3.1" (see get_post_blocks).', 'viagent-production' ) );
		}
		$parent = array();
		$list   = $blocks;
		$parts  = array_map( 'intval', explode( '.', $path ) );
		foreach ( $parts as $depth => $n ) {
			$indexes = self::real_indexes( $list );
			if ( ! isset( $indexes[ $n ] ) ) {
				/* translators: %s: block path */
				return new WP_Error( 'viagent_production_invalid_path', sprintf( __( 'There is no block at path "%s". Check get_post_blocks.', 'viagent-production' ), $path ) );
			}
			if ( count( $parts ) - 1 === $depth ) {
				return array(
					'parent' => $parent,
					'index'  => $indexes[ $n ],
				);
			}
			$parent[] = $indexes[ $n ];
			$list     = $list[ $indexes[ $n ] ]['innerBlocks'];
		}
		return new WP_Error( 'viagent_production_invalid_path', __( 'Invalid block path.', 'viagent-production' ) );
	}

	/**
	 * Gets the block list at a parent path.
	 *
	 * @param array $blocks Top-level blocks.
	 * @param int[] $path Parent array indexes.
	 * @return array
	 */
	private static function get_list( array $blocks, array $path ) {
		foreach ( $path as $index ) {
			$blocks = $blocks[ $index ]['innerBlocks'];
		}
		return $blocks;
	}

	/**
	 * Splices blocks into the list at a parent path, keeping innerContent placeholders in sync.
	 *
	 * @param array $blocks  Top-level blocks (modified).
	 * @param int[] $path  Parent array indexes.
	 * @param int   $offset  Array index to splice at.
	 * @param int   $remove  Number of blocks to remove.
	 * @param array $insert  Blocks to insert.
	 * @return true|WP_Error
	 */
	private static function splice( array &$blocks, array $path, $offset, $remove, array $insert ) {
		if ( ! $path ) {
			$with_gaps = array();
			foreach ( $insert as $block ) {
				$with_gaps[] = self::separator();
				$with_gaps[] = $block;
			}
			if ( $with_gaps && 0 === $offset ) {
				$with_gaps   = array_slice( $with_gaps, 1 );
				$with_gaps[] = self::separator();
			}
			array_splice( $blocks, $offset, $remove, $with_gaps );
			return true;
		}

		$index = array_shift( $path );
		return self::splice_inner( $blocks[ $index ], $path, $offset, $remove, $insert );
	}

	/**
	 * Recursive part of splice() for inner blocks.
	 *
	 * @param array $block  Container block (modified).
	 * @param int[] $path Remaining parent indexes.
	 * @param int   $offset innerBlocks index.
	 * @param int   $remove Blocks to remove.
	 * @param array $insert Blocks to insert.
	 * @return true|WP_Error
	 */
	private static function splice_inner( array &$block, array $path, $offset, $remove, array $insert ) {
		if ( $path ) {
			$index = array_shift( $path );
			return self::splice_inner( $block['innerBlocks'][ $index ], $path, $offset, $remove, $insert );
		}

		// Positions of the inner block placeholders (null) in innerContent.
		$slots = array_keys( $block['innerContent'], null, true );
		if ( ! $slots ) {
			return new WP_Error( 'viagent_production_empty_container', __( 'This container block is empty, so the insert position is unknown. Replace the whole container block instead.', 'viagent-production' ) );
		}
		if ( $offset < count( $slots ) ) {
			$position = $slots[ $offset ];
		} else {
			$position = end( $slots ) + 1;
		}

		$placeholders = array();
		foreach ( array_keys( $insert ) as $n ) {
			if ( $remove ) {
				// Replace: new blocks separated by blank lines.
				if ( $n > 0 ) {
					$placeholders[] = "\n\n";
				}
				$placeholders[] = null;
			} elseif ( $offset < count( $slots ) ) {
				// Insert before an existing block.
				$placeholders[] = null;
				$placeholders[] = "\n\n";
			} else {
				// Append after the last block.
				$placeholders[] = "\n\n";
				$placeholders[] = null;
			}
		}
		array_splice( $block['innerContent'], $position, $remove ? 1 : 0, $placeholders );

		// After a delete, drop the blank line left between the neighbors.
		if ( $remove && ! $insert ) {
			$before = $block['innerContent'][ $position - 1 ] ?? null;
			$after  = $block['innerContent'][ $position ] ?? null;
			if ( is_string( $before ) && is_string( $after ) ) {
				if ( '' === trim( $after ) ) {
					array_splice( $block['innerContent'], $position, 1 );
				} elseif ( '' === trim( $before ) ) {
					array_splice( $block['innerContent'], $position - 1, 1 );
				}
			}
		}
		array_splice( $block['innerBlocks'], $offset, $remove, $insert );
		return true;
	}

	/**
	 * Parses content (any format) into real blocks.
	 *
	 * @param string $content Content.
	 * @return array
	 */
	private static function parse_new( $content ) {
		$markup = Content_Format::prepare( (string) $content );
		return array_values(
			array_filter(
				parse_blocks( $markup ),
				static function ( $block ) {
					return null !== $block['blockName'] || '' !== trim( $block['innerHTML'] );
				}
			)
		);
	}

	/**
	 * Applies block operations to a post.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function edit_post_blocks( $input ) {
		$post = Content::find( (int) $input['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$editable = Policy::check_post_editable( $post );
		if ( is_wp_error( $editable ) ) {
			return $editable;
		}

		$blocks = parse_blocks( $post->post_content );
		foreach ( (array) $input['operations'] as $n => $op ) {
			$action = $op['action'];
			$error  = static function ( $message ) use ( $n, $action ) {
				/* translators: 1: operation number, 2: action, 3: message */
				return new WP_Error( 'viagent_production_block_edit_failed', sprintf( __( 'Operation %1$d (%2$s) failed, nothing was saved: %3$s', 'viagent-production' ), $n + 1, $action, $message ) );
			};

			$needs_content = in_array( $action, array( 'replace', 'insert_before', 'insert_after', 'append', 'prepend' ), true );
			$new           = $needs_content ? self::parse_new( $op['content'] ?? '' ) : array();
			if ( $needs_content && ! $new ) {
				return $error( __( 'content is empty.', 'viagent-production' ) );
			}

			if ( in_array( $action, array( 'append', 'prepend' ), true ) ) {
				if ( empty( $op['path'] ) ) {
					$result = self::splice( $blocks, array(), 'append' === $action ? count( $blocks ) : 0, 0, $new );
				} else {
					$found = self::locate( $blocks, $op['path'] );
					if ( is_wp_error( $found ) ) {
						return $error( $found->get_error_message() );
					}
					$container = self::get_list( $blocks, $found['parent'] )[ $found['index'] ];
					$parent    = array_merge( $found['parent'], array( $found['index'] ) );
					$result    = self::splice( $blocks, $parent, 'append' === $action ? count( $container['innerBlocks'] ) : 0, 0, $new );
				}
				if ( is_wp_error( $result ) ) {
					return $error( $result->get_error_message() );
				}
				continue;
			}

			$found = self::locate( $blocks, $op['path'] ?? '' );
			if ( is_wp_error( $found ) ) {
				return $error( $found->get_error_message() );
			}
			$parent = $found['parent'];
			$index  = $found['index'];

			if ( 'update_attributes' === $action ) {
				$ref = &$blocks;
				foreach ( $parent as $p ) {
					$ref = &$ref[ $p ]['innerBlocks'];
				}
				foreach ( (array) ( $op['attributes'] ?? array() ) as $key => $value ) {
					if ( null === $value ) {
						unset( $ref[ $index ]['attrs'][ $key ] );
					} else {
						$ref[ $index ]['attrs'][ $key ] = $value;
					}
				}
				unset( $ref );
				continue;
			}

			// Inner blocks are addressed by their innerBlocks index; top level by array index.
			$offset = $parent ? $index : $index;
			switch ( $action ) {
				case 'replace':
					$result = $parent ? self::splice( $blocks, $parent, $offset, 1, $new ) : self::replace_top( $blocks, $offset, $new );
					break;
				case 'delete':
					$result = $parent ? self::splice( $blocks, $parent, $offset, 1, array() ) : self::replace_top( $blocks, $offset, array() );
					break;
				case 'insert_before':
					$result = $parent ? self::splice( $blocks, $parent, $offset, 0, $new ) : self::insert_top( $blocks, $offset, $new );
					break;
				default:
					$result = $parent ? self::splice( $blocks, $parent, $offset + 1, 0, $new ) : self::insert_top( $blocks, $offset + 1, $new );
			}
			if ( is_wp_error( $result ) ) {
				return $error( $result->get_error_message() );
			}
		}

		$content = serialize_blocks( $blocks );
		$issues  = wp_list_filter( Content_Format::validate( $content ), array( 'severity' => 'error' ) );
		if ( $issues ) {
			return new WP_Error( 'viagent_production_invalid_blocks', __( 'The result would have broken block markup, so nothing was saved: ', 'viagent-production' ) . implode( ' ', array_column( $issues, 'message' ) ) );
		}

		Activity_Log::set_object( 'post', $post->ID );
		Activity_Log::set_undo( Content::snapshot( $post ) );
		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => $content,
				)
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$saved = get_post( $post->ID );
		return array_merge(
			Content::saved_response( $saved ),
			array(
				'operations' => count( $input['operations'] ),
				'blocks'     => self::outline( parse_blocks( $saved->post_content ) ),
			)
		);
	}

	/**
	 * Replaces (or removes) one top-level block.
	 *
	 * @param array $blocks Blocks (modified).
	 * @param int   $index  Array index.
	 * @param array $items    New blocks.
	 * @return true
	 */
	private static function replace_top( array &$blocks, $index, array $items ) {
		$with_gaps = array();
		foreach ( $items as $n => $block ) {
			if ( $n > 0 ) {
				$with_gaps[] = self::separator();
			}
			$with_gaps[] = $block;
		}
		array_splice( $blocks, $index, 1, $with_gaps );
		return true;
	}

	/**
	 * Inserts blocks at a top-level array index.
	 *
	 * @param array $blocks Blocks (modified).
	 * @param int   $index  Array index.
	 * @param array $items    New blocks.
	 * @return true
	 */
	private static function insert_top( array &$blocks, $index, array $items ) {
		$with_gaps = array();
		foreach ( $items as $block ) {
			$with_gaps[] = $block;
			$with_gaps[] = self::separator();
		}
		array_splice( $blocks, $index, 0, $with_gaps );
		return true;
	}
}
