<?php
/**
 * Registers Viagent Production ability categories and abilities, plus shared helpers.
 *
 * @package ViagentProduction
 */

namespace ViagentProduction\Abilities;

use ViagentProduction\Security\Policy;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities loader and shared helpers.
 */
class Abilities {

	/**
	 * Registers ability categories.
	 */
	public static function register_categories() {
		$categories = array(
			'viagent-production-site'        => array( __( 'Site', 'viagent-production' ), __( 'Site information and search.', 'viagent-production' ) ),
			'viagent-production-content'     => array( __( 'Content', 'viagent-production' ), __( 'Posts, pages and custom post types.', 'viagent-production' ) ),
			'viagent-production-blocks'      => array( __( 'Blocks & patterns', 'viagent-production' ), __( 'Block types and patterns for building layouts.', 'viagent-production' ) ),
			'viagent-production-taxonomies'  => array( __( 'Categories & tags', 'viagent-production' ), __( 'Categories, tags and custom taxonomies.', 'viagent-production' ) ),
			'viagent-production-media'       => array( __( 'Media', 'viagent-production' ), __( 'Images, documents and other uploads.', 'viagent-production' ) ),
			'viagent-production-comments'    => array( __( 'Comments', 'viagent-production' ), __( 'Reading, replying to and moderating comments.', 'viagent-production' ) ),
			'viagent-production-users'       => array( __( 'Users', 'viagent-production' ), __( 'User accounts and profiles (read only).', 'viagent-production' ) ),
			'viagent-production-menus'       => array( __( 'Menus', 'viagent-production' ), __( 'Navigation menus.', 'viagent-production' ) ),
			'viagent-production-settings'    => array( __( 'Settings', 'viagent-production' ), __( 'General, reading and discussion settings.', 'viagent-production' ) ),
			'viagent-production-extensions'  => array( __( 'Plugins & themes', 'viagent-production' ), __( 'Installed plugins and themes (read only).', 'viagent-production' ) ),
			'viagent-production-diagnostics' => array( __( 'Diagnostics', 'viagent-production' ), __( 'Site health overview.', 'viagent-production' ) ),
		);

		foreach ( $categories as $slug => $data ) {
			wp_register_ability_category(
				$slug,
				array(
					'label'       => $data[0],
					'description' => $data[1],
				)
			);
		}
	}

	/**
	 * Registers all abilities.
	 */
	public static function register() {
		Site::register();
		Content::register();
		Blocks::register();
		Taxonomies::register();
		Media::register();
		Comments::register();
		Users::register();
		Menus::register();
		Settings::register();
		Extensions::register();
		Meta::register();
		Diagnostics::register();
	}

	/**
	 * Compact ability registration.
	 *
	 * @param string $name Ability name without the "viagent-production/" prefix.
	 * @param array  $args {
	 *     Ability settings.
	 *
	 *     @type string          $category    Category slug.
	 *     @type string          $label       Label.
	 *     @type string          $description Description shown to the AI.
	 *     @type array           $input       Input properties (JSON Schema). Omit for no input.
	 *     @type string[]        $required    Required input properties.
	 *     @type callable        $execute     Execute callback.
	 *     @type string|callable $permission  Capability name or permission callback.
	 *     @type array           $meta        Ability meta, see read_meta()/write_meta().
	 * }
	 */
	public static function add( $name, array $args ) {
		$permission = $args['permission'];
		if ( is_string( $permission ) ) {
			$capability = $permission;
			$permission = static function () use ( $capability ) {
				return current_user_can( $capability );
			};
			// Lets the registry hide the tool from users who lack the capability.
			$args['meta']['viagent_production']['capability'] = $capability;
		}

		$schema = array();
		if ( isset( $args['input'] ) ) {
			$schema = array(
				'type'                 => 'object',
				'properties'           => $args['input'],
				'additionalProperties' => false,
			);
			if ( ! empty( $args['required'] ) ) {
				$schema['required'] = $args['required'];
			}
		}

		wp_register_ability(
			'viagent-production/' . $name,
			array(
				'label'               => $args['label'],
				'description'         => $args['description'],
				'category'            => $args['category'],
				'input_schema'        => $schema,
				'execute_callback'    => $args['execute'],
				'permission_callback' => $permission,
				'meta'                => $args['meta'],
			)
		);
	}

	/**
	 * Meta for a read-only ability.
	 *
	 * @param string $level Required access level.
	 * @return array
	 */
	public static function read_meta( $level = Policy::READ ) {
		return array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'viagent_production'     => array( 'level' => $level ),
		);
	}

	/**
	 * Meta for a write ability.
	 *
	 * @param string $level       Required access level.
	 * @param array  $flags {
	 *     Optional behaviour flags.
	 *
	 *     @type bool $destructive     Can remove or overwrite data. Default false.
	 *     @type bool $idempotent      Repeating the call has no further effect. Default false.
	 *     @type bool $draft_safe      Usable in draft-only mode (the tool guards itself). Default false.
	 *     @type bool $default_enabled On unless the admin switches it off. Default true.
	 * }
	 * @return array
	 */
	public static function write_meta( $level = Policy::CONTENT, array $flags = array() ) {
		return array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => ! empty( $flags['destructive'] ),
				'idempotent'  => ! empty( $flags['idempotent'] ),
			),
			'viagent_production'     => array(
				'level'           => $level,
				'draft_safe'      => ! empty( $flags['draft_safe'] ),
				'default_enabled' => $flags['default_enabled'] ?? true,
			),
		);
	}

	/**
	 * Standard pagination input properties.
	 *
	 * @param int $default_per_page Default page size.
	 * @return array
	 */
	public static function paging( $default_per_page = 20 ) {
		return array(
			'per_page' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
				'default' => $default_per_page,
			),
			'page'     => array(
				'type'    => 'integer',
				'minimum' => 1,
				'default' => 1,
			),
		);
	}
}
