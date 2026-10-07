<?php
/**
 * Site editor abilities for block themes: templates, template parts (header,
 * footer…) and global styles (colors, typography, spacing). They go through
 * WordPress's own REST controllers, so they behave exactly like the site
 * editor: saving customizes a theme template, deleting reverts it.
 *
 * @package ViagentProduction
 */

namespace ViagentProduction\Abilities;

use ViagentProduction\Log\Activity_Log;
use ViagentProduction\Security\Policy;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Site editor abilities.
 */
class Site_Editor {

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_filter( 'viagent_production_apply_undo', array( self::class, 'apply_undo' ), 10, 2 );
		add_filter( 'viagent_production_undo_label', array( self::class, 'undo_label' ), 10, 2 );
	}

	/**
	 * Registers abilities.
	 */
	public static function register() {
		$type = array(
			'type'    => 'string',
			'enum'    => array( 'template', 'template_part' ),
			'default' => 'template',
		);
		$id   = array(
			'type'        => 'string',
			'description' => __( 'Template ID such as "twentytwentyfive//single" (from list_templates).', 'viagent-production' ),
		);

		Abilities::add(
			'list-templates',
			array(
				'category'    => 'viagent-production-site-editor',
				'label'       => __( 'List templates', 'viagent-production' ),
				'description' => __( 'Lists the block theme\'s templates (home, single, page, archive, 404, custom page templates…) or template parts (header, footer…), and whether each was customized.', 'viagent-production' ),
				'input'       => array(
					'type'   => $type,
					'search' => array( 'type' => 'string' ),
				),
				'execute'     => array( self::class, 'list_templates' ),
				'permission'  => 'edit_theme_options',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'get-template',
			array(
				'category'    => 'viagent-production-site-editor',
				'label'       => __( 'Get template', 'viagent-production' ),
				'description' => __( 'Gets the block markup of a template or template part.', 'viagent-production' ),
				'input'       => array(
					'id'   => $id,
					'type' => $type,
				),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'get_template' ),
				'permission'  => 'edit_theme_options',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'update-template',
			array(
				'category'    => 'viagent-production-site-editor',
				'label'       => __( 'Update template', 'viagent-production' ),
				'description' => __( 'Saves new block markup for a template or template part (e.g. change the header or the single post layout). Read it with get_template first and send the full new markup. Theme templates become customized and can be reverted with delete_template.', 'viagent-production' ),
				'input'       => array(
					'id'      => $id,
					'type'    => $type,
					'content' => array( 'type' => 'string' ),
					'title'   => array( 'type' => 'string' ),
				),
				'required'    => array( 'id', 'content' ),
				'execute'     => array( self::class, 'update_template' ),
				'permission'  => 'edit_theme_options',
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'idempotent' => true ) ),
			)
		);

		Abilities::add(
			'create-template',
			array(
				'category'    => 'viagent-production-site-editor',
				'label'       => __( 'Create template', 'viagent-production' ),
				'description' => __( 'Creates a template (slug follows the template hierarchy, e.g. "single-product", "page-about", "category-news", or any slug for a custom page template) or a template part (area header, footer or uncategorized).', 'viagent-production' ),
				'input'       => array(
					'type'        => $type,
					'slug'        => array(
						'type'    => 'string',
						'pattern' => '^[a-z0-9_-]+$',
					),
					'title'       => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'content'     => array( 'type' => 'string' ),
					'area'        => array(
						'type'    => 'string',
						'enum'    => array( 'header', 'footer', 'uncategorized' ),
						'default' => 'uncategorized',
					),
				),
				'required'    => array( 'slug', 'title', 'content' ),
				'execute'     => array( self::class, 'create_template' ),
				'permission'  => 'edit_theme_options',
				'meta'        => Abilities::write_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'delete-template',
			array(
				'category'    => 'viagent-production-site-editor',
				'label'       => __( 'Delete or revert template', 'viagent-production' ),
				'description' => __( 'Deletes a custom template, or reverts a customized theme template to the theme\'s original. Can be undone.', 'viagent-production' ),
				'input'       => array(
					'id'   => $id,
					'type' => $type,
				),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'delete_template' ),
				'permission'  => 'edit_theme_options',
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'destructive' => true ) ),
			)
		);

		Abilities::add(
			'get-global-styles',
			array(
				'category'    => 'viagent-production-site-editor',
				'label'       => __( 'Get global styles', 'viagent-production' ),
				'description' => __( 'Gets the site\'s global styles: the user customizations (settings and styles, theme.json format) and, with include_theme, the theme\'s defaults such as its color palette, font families and font sizes.', 'viagent-production' ),
				'input'       => array(
					'include_theme' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
				'execute'     => array( self::class, 'get_global_styles' ),
				'permission'  => 'edit_theme_options',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'update-global-styles',
			array(
				'category'    => 'viagent-production-site-editor',
				'label'       => __( 'Update global styles', 'viagent-production' ),
				'description' => __( 'Changes global styles in theme.json format, e.g. {"styles":{"color":{"background":"#fff","text":"#111"},"elements":{"link":{"color":{"text":"var(--wp--preset--color--accent)"}}},"typography":{"fontSize":"18px"}},"settings":{"color":{"palette":{"custom":[{"slug":"brand","name":"Brand","color":"#e63946"}]}}}}. Merged into the current customizations unless replace is true. Can be undone.', 'viagent-production' ),
				'input'       => array(
					'styles'   => array( 'type' => 'object' ),
					'settings' => array( 'type' => 'object' ),
					'replace'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'execute'     => array( self::class, 'update_global_styles' ),
				'permission'  => 'edit_theme_options',
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'idempotent' => true ) ),
			)
		);
	}

	/**
	 * Registers the ability category.
	 */
	public static function register_category() {
		wp_register_ability_category(
			'viagent-production-site-editor',
			array(
				'label'       => __( 'Site editor', 'viagent-production' ),
				'description' => __( 'Block theme templates, template parts and global styles.', 'viagent-production' ),
			)
		);
	}

	/**
	 * REST route base for a template type.
	 *
	 * @param string $type template or template_part.
	 * @return string
	 */
	private static function base( $type ) {
		return 'template_part' === $type ? '/wp/v2/template-parts' : '/wp/v2/templates';
	}

	/**
	 * Runs an internal REST request.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 * @param array  $params Parameters.
	 * @return array|WP_Error Response data.
	 */
	private static function rest( $method, $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, $route );
		if ( 'GET' === $method || 'DELETE' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}
		return $response->get_data();
	}

	/**
	 * Fails unless the active theme is a block theme.
	 *
	 * @return true|WP_Error
	 */
	private static function require_block_theme() {
		if ( ! wp_is_block_theme() ) {
			return new WP_Error( 'viagent_production_not_block_theme', __( 'The active theme is a classic theme, so it has no editable templates or global styles. Edit its files with the theme file tools instead.', 'viagent-production' ) );
		}
		return true;
	}

	/**
	 * Compact template data.
	 *
	 * @param array $item REST item.
	 * @param bool  $full Include content.
	 * @return array
	 */
	private static function item( array $item, $full = false ) {
		$result = array(
			'id'          => $item['id'],
			'slug'        => $item['slug'],
			'title'       => is_array( $item['title'] ) ? ( $item['title']['raw'] ?? $item['title']['rendered'] ?? '' ) : $item['title'],
			'description' => $item['description'] ?? '',
			'source'      => $item['source'] ?? '',
			'customized'  => 'custom' === ( $item['source'] ?? '' ),
			'from_theme'  => ! empty( $item['has_theme_file'] ),
		);
		if ( isset( $item['area'] ) ) {
			$result['area'] = $item['area'];
		}
		if ( $full ) {
			$result['content'] = is_array( $item['content'] ) ? ( $item['content']['raw'] ?? '' ) : (string) $item['content'];
		}
		return $result;
	}

	/**
	 * Lists templates.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function list_templates( $input ) {
		$check = self::require_block_theme();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$data = self::rest( 'GET', self::base( $input['type'] ?? 'template' ), array( 'context' => 'edit' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$search = (string) ( $input['search'] ?? '' );
		$items  = array();
		foreach ( (array) $data as $item ) {
			$row = self::item( (array) $item );
			if ( '' === $search || false !== stripos( $row['slug'] . ' ' . $row['title'] . ' ' . $row['description'], $search ) ) {
				$items[] = $row;
			}
		}
		return array(
			'theme' => get_stylesheet(),
			'items' => $items,
		);
	}

	/**
	 * Gets a template.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_template( $input ) {
		$check = self::require_block_theme();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$data = self::rest( 'GET', self::base( $input['type'] ?? 'template' ) . '/' . $input['id'], array( 'context' => 'edit' ) );
		return is_wp_error( $data ) ? $data : self::item( (array) $data, true );
	}

	/**
	 * Prepares and validates template markup.
	 *
	 * @param string $content Content.
	 * @return string|WP_Error
	 */
	private static function markup( $content ) {
		$markup = Content_Format::prepare( (string) $content );
		$errors = wp_list_filter( Content_Format::validate( $markup ), array( 'severity' => 'error' ) );
		if ( $errors ) {
			return new WP_Error( 'viagent_production_invalid_blocks', __( 'The template markup is broken, so nothing was saved: ', 'viagent-production' ) . implode( ' ', array_column( $errors, 'message' ) ) );
		}
		return $markup;
	}

	/**
	 * Updates a template.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_template( $input ) {
		$before = self::get_template( $input );
		if ( is_wp_error( $before ) ) {
			return $before;
		}
		$markup = self::markup( $input['content'] );
		if ( is_wp_error( $markup ) ) {
			return $markup;
		}
		$type   = $input['type'] ?? 'template';
		$params = array( 'content' => $markup );
		if ( isset( $input['title'] ) ) {
			$params['title'] = $input['title'];
		}
		$data = self::rest( 'POST', self::base( $type ) . '/' . $input['id'], $params );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		Activity_Log::set_object( 'template', 0 );
		Activity_Log::set_undo(
			$before['customized']
				? array(
					'action'  => 'restore_template',
					'type'    => $type,
					'id'      => $before['id'],
					'content' => $before['content'],
					'title'   => $before['title'],
				)
				: array(
					'action' => 'delete_template',
					'type'   => $type,
					'id'     => $before['id'],
				)
		);
		return self::item( (array) $data );
	}

	/**
	 * Creates a template.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function create_template( $input ) {
		$check = self::require_block_theme();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$markup = self::markup( $input['content'] );
		if ( is_wp_error( $markup ) ) {
			return $markup;
		}
		$type   = $input['type'] ?? 'template';
		$params = array(
			'slug'        => $input['slug'],
			'title'       => $input['title'],
			'description' => $input['description'] ?? '',
			'content'     => $markup,
		);
		if ( 'template_part' === $type ) {
			$params['area'] = $input['area'] ?? 'uncategorized';
		}
		$data = self::rest( 'POST', self::base( $type ), $params );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		Activity_Log::set_object( 'template', 0 );
		Activity_Log::set_undo(
			array(
				'action' => 'delete_template',
				'type'   => $type,
				'id'     => $data['id'],
			)
		);
		return self::item( (array) $data );
	}

	/**
	 * Deletes or reverts a template.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function delete_template( $input ) {
		$before = self::get_template( $input );
		if ( is_wp_error( $before ) ) {
			return $before;
		}
		if ( ! $before['customized'] ) {
			return new WP_Error( 'viagent_production_not_customized', __( 'This template comes straight from the theme and has no customizations to revert.', 'viagent-production' ) );
		}
		$type = $input['type'] ?? 'template';
		$data = self::rest( 'DELETE', self::base( $type ) . '/' . $input['id'], array( 'force' => true ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		Activity_Log::set_object( 'template', 0 );
		Activity_Log::set_undo(
			$before['from_theme']
				? array(
					'action'  => 'restore_template',
					'type'    => $type,
					'id'      => $before['id'],
					'content' => $before['content'],
					'title'   => $before['title'],
				)
				: array(
					'action'      => 'recreate_template',
					'type'        => $type,
					'slug'        => $before['slug'],
					'title'       => $before['title'],
					'description' => $before['description'],
					'content'     => $before['content'],
					'area'        => $before['area'] ?? 'uncategorized',
				)
		);
		return array(
			'id'       => $before['id'],
			'reverted' => $before['from_theme'],
			'deleted'  => ! $before['from_theme'],
		);
	}

	/**
	 * ID of the user global styles post.
	 *
	 * @return int
	 */
	private static function styles_id() {
		return (int) \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
	}

	/**
	 * Gets global styles.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_global_styles( $input ) {
		$check = self::require_block_theme();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$user = self::rest( 'GET', '/wp/v2/global-styles/' . self::styles_id(), array( 'context' => 'edit' ) );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		$result = array(
			'id'       => self::styles_id(),
			'settings' => $user['settings'] ? $user['settings'] : new \stdClass(),
			'styles'   => $user['styles'] ? $user['styles'] : new \stdClass(),
		);
		if ( $input['include_theme'] ?? true ) {
			$theme           = \WP_Theme_JSON_Resolver::get_theme_data()->get_raw_data();
			$result['theme'] = array(
				'palette'      => $theme['settings']['color']['palette'] ?? array(),
				'fontFamilies' => $theme['settings']['typography']['fontFamilies'] ?? array(),
				'fontSizes'    => $theme['settings']['typography']['fontSizes'] ?? array(),
				'spacing'      => $theme['settings']['spacing']['spacingSizes'] ?? array(),
				'layout'       => $theme['settings']['layout'] ?? array(),
				'styles'       => $theme['styles'] ?? new \stdClass(),
			);
		}
		return $result;
	}

	/**
	 * Updates global styles.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_global_styles( $input ) {
		$check = self::require_block_theme();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( ! isset( $input['styles'] ) && ! isset( $input['settings'] ) ) {
			return new WP_Error( 'viagent_production_nothing_to_update', __( 'Pass styles and/or settings.', 'viagent-production' ) );
		}
		$id     = self::styles_id();
		$before = get_post( $id );
		$user   = self::rest( 'GET', '/wp/v2/global-styles/' . $id, array( 'context' => 'edit' ) );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		$replace = ! empty( $input['replace'] );
		$params  = array();
		foreach ( array( 'styles', 'settings' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$params[ $key ] = $replace ? (array) $input[ $key ] : array_replace_recursive( (array) ( $user[ $key ] ?? array() ), (array) $input[ $key ] );
			} else {
				$params[ $key ] = (array) ( $user[ $key ] ?? array() );
			}
		}
		$data = self::rest( 'POST', '/wp/v2/global-styles/' . $id, $params );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		Activity_Log::set_object( 'global_styles', 0 );
		Activity_Log::set_undo(
			array(
				'action'  => 'restore_global_styles',
				'id'      => $id,
				'content' => $before ? $before->post_content : '',
			)
		);
		return array(
			'id'       => $id,
			'settings' => $data['settings'] ? $data['settings'] : new \stdClass(),
			'styles'   => $data['styles'] ? $data['styles'] : new \stdClass(),
		);
	}

	/**
	 * Undo handlers.
	 *
	 * @param mixed $result Result from earlier handlers.
	 * @param array $undo   Undo instructions.
	 * @return mixed
	 */
	public static function apply_undo( $result, $undo ) {
		if ( null !== $result ) {
			return $result;
		}
		$action = $undo['action'] ?? '';
		if ( ! in_array( $action, array( 'restore_template', 'delete_template', 'recreate_template', 'restore_global_styles' ), true ) ) {
			return null;
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new WP_Error( 'viagent_production_forbidden', __( 'You are not allowed to change the theme.', 'viagent-production' ) );
		}
		switch ( $action ) {
			case 'restore_template':
				$data = self::rest(
					'POST',
					self::base( $undo['type'] ) . '/' . $undo['id'],
					array(
						'content' => $undo['content'],
						'title'   => $undo['title'],
					)
				);
				break;
			case 'delete_template':
				$data = self::rest( 'DELETE', self::base( $undo['type'] ) . '/' . $undo['id'], array( 'force' => true ) );
				break;
			case 'recreate_template':
				$params = array(
					'slug'        => $undo['slug'],
					'title'       => $undo['title'],
					'description' => $undo['description'],
					'content'     => $undo['content'],
				);
				if ( 'template_part' === $undo['type'] ) {
					$params['area'] = $undo['area'];
				}
				$data = self::rest( 'POST', self::base( $undo['type'] ), $params );
				break;
			default:
				$data = wp_update_post(
					array(
						'ID'           => (int) $undo['id'],
						'post_content' => wp_slash( $undo['content'] ),
					),
					true
				);
				delete_transient( 'global_styles' );
				delete_transient( 'global_styles_' . get_stylesheet() );
				\WP_Theme_JSON_Resolver::clean_cached_data();
		}
		return is_wp_error( $data ) ? $data : true;
	}

	/**
	 * Undo descriptions.
	 *
	 * @param string|null $label Label.
	 * @param array       $undo  Undo instructions.
	 * @return string|null
	 */
	public static function undo_label( $label, $undo ) {
		switch ( $undo['action'] ?? '' ) {
			case 'restore_template':
				return __( 'The template will go back to how it was.', 'viagent-production' );
			case 'delete_template':
				return __( 'The template will go back to the theme\'s version (or be removed if the AI created it).', 'viagent-production' );
			case 'recreate_template':
				return __( 'The deleted template will be created again.', 'viagent-production' );
			case 'restore_global_styles':
				return __( 'The colors, fonts and other global styles will go back to how they were.', 'viagent-production' );
		}
		return $label;
	}
}
