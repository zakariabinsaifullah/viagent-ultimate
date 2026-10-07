<?php
/**
 * Custom field (post meta) abilities. Protected keys (starting with "_") are
 * available when a plugin registers them with show_in_rest, or to Full
 * control connections (e.g. page builder data such as _elementor_data).
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
 * Post meta abilities.
 */
class Meta {

	/**
	 * Registers abilities.
	 */
	public static function register() {
		$post_id = array(
			'type'    => 'integer',
			'minimum' => 1,
		);

		Abilities::add(
			'get-post-meta',
			array(
				'category'    => 'viagent-production-content',
				'label'       => __( 'Get custom fields', 'viagent-production' ),
				'description' => __( 'Gets the custom fields (post meta) of a post, or a single field by key.', 'viagent-production' ),
				'input'       => array(
					'post_id' => $post_id,
					'key'     => array( 'type' => 'string' ),
				),
				'required'    => array( 'post_id' ),
				'execute'     => array( self::class, 'get_meta' ),
				'permission'  => static function ( $input ) {
					return current_user_can( 'edit_post', (int) ( $input['post_id'] ?? 0 ) ) || ! get_post( (int) ( $input['post_id'] ?? 0 ) );
				},
				'meta'        => Abilities::read_meta(),
			)
		);

		Abilities::add(
			'update-post-meta',
			array(
				'category'    => 'viagent-production-content',
				'label'       => __( 'Update custom field', 'viagent-production' ),
				'description' => __( 'Sets or deletes one custom field (post meta) on a post.', 'viagent-production' ),
				'input'       => array(
					'post_id' => $post_id,
					'key'     => array(
						'type'      => 'string',
						'minLength' => 1,
					),
					'value'   => array(
						'type'        => array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ),
						'description' => __( 'New value. Omit or pass null with delete=true to remove the field.', 'viagent-production' ),
					),
					'delete'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'required'    => array( 'post_id', 'key' ),
				'execute'     => array( self::class, 'update_meta' ),
				'permission'  => static function ( $input ) {
					return current_user_can( 'edit_post', (int) ( $input['post_id'] ?? 0 ) ) || ! get_post( (int) ( $input['post_id'] ?? 0 ) );
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
	}

	/**
	 * Keys an AI never touches, even with Full control: WordPress bookkeeping.
	 */
	const INTERNAL_KEYS = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug', '_pingme', '_encloseme', '_trackbackme' );

	/**
	 * Whether a meta key is available to the AI.
	 *
	 * @param WP_Post $post Post.
	 * @param string  $key  Meta key.
	 * @return bool
	 */
	public static function is_allowed_key( WP_Post $post, $key ) {
		if ( '' === (string) $key || in_array( $key, self::INTERNAL_KEYS, true ) ) {
			return false;
		}
		// A plugin that registered its own auth callback for this key decides, at every level.
		if ( has_filter( "auth_post_meta_{$key}" ) || has_filter( "auth_post_{$post->post_type}_meta_{$key}" ) ) {
			return current_user_can( 'edit_post_meta', $post->ID, $key );
		}
		if ( Policy::is_full_control() ) {
			return true;
		}
		$registered = get_registered_meta_keys( 'post', $post->post_type ) + get_registered_meta_keys( 'post' );
		if ( isset( $registered[ $key ] ) ) {
			return ! empty( $registered[ $key ]['show_in_rest'] );
		}
		return ! is_protected_meta( $key, 'post' );
	}

	/**
	 * All custom fields of a post the AI may see.
	 *
	 * @param WP_Post $post Post.
	 * @return array|\stdClass
	 */
	public static function get_values( WP_Post $post ) {
		$result = array();
		foreach ( get_post_meta( $post->ID ) as $key => $values ) {
			if ( self::is_allowed_key( $post, $key ) ) {
				$values         = array_map( 'maybe_unserialize', $values );
				$result[ $key ] = 1 === count( $values ) ? $values[0] : $values;
			}
		}
		return empty( $result ) ? new \stdClass() : $result;
	}

	/**
	 * Sets several custom fields; null deletes one. Checks every key first.
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $values Key => value.
	 * @return true|WP_Error
	 */
	public static function set_values( WP_Post $post, array $values ) {
		foreach ( array_keys( $values ) as $key ) {
			if ( ! self::is_allowed_key( $post, (string) $key ) ) {
				/* translators: %s: meta key */
				return new WP_Error( 'viagent_production_protected_meta', sprintf( __( 'The custom field "%s" is private to a plugin. It can only be changed with the Full control level.', 'viagent-production' ), $key ) );
			}
		}
		foreach ( $values as $key => $value ) {
			if ( null === $value ) {
				delete_post_meta( $post->ID, $key );
			} else {
				update_post_meta( $post->ID, $key, wp_slash( $value ) );
			}
		}
		return true;
	}

	/**
	 * Gets meta.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_meta( $input ) {
		$post = Content::find( (int) $input['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! empty( $input['key'] ) ) {
			if ( ! self::is_allowed_key( $post, $input['key'] ) ) {
				return new WP_Error( 'viagent_production_protected_meta', __( 'This custom field is private to a plugin and cannot be read.', 'viagent-production' ) );
			}
			return array(
				'key'    => $input['key'],
				'exists' => metadata_exists( 'post', $post->ID, $input['key'] ),
				'value'  => get_post_meta( $post->ID, $input['key'], true ),
			);
		}

		return self::get_values( $post );
	}

	/**
	 * Updates meta.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_meta( $input ) {
		$post = Content::find( (int) $input['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$key = $input['key'];
		if ( ! self::is_allowed_key( $post, $key ) ) {
			return new WP_Error( 'viagent_production_protected_meta', __( 'This custom field is private to a plugin and cannot be changed.', 'viagent-production' ) );
		}
		$editable = Policy::check_post_editable( $post );
		if ( is_wp_error( $editable ) ) {
			return $editable;
		}

		$previous = metadata_exists( 'post', $post->ID, $key ) ? get_post_meta( $post->ID, $key, true ) : null;

		Activity_Log::set_object( 'post', $post->ID );
		Activity_Log::set_undo(
			array(
				'action'  => 'restore_meta',
				'post_id' => $post->ID,
				'key'     => $key,
				'value'   => $previous,
			)
		);

		if ( ! empty( $input['delete'] ) || ! array_key_exists( 'value', $input ) || null === $input['value'] ) {
			delete_post_meta( $post->ID, $key );
			return array(
				'key'     => $key,
				'deleted' => true,
			);
		}

		update_post_meta( $post->ID, $key, wp_slash( $input['value'] ) );
		return array(
			'key'   => $key,
			'value' => get_post_meta( $post->ID, $key, true ),
		);
	}
}
