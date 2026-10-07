<?php
/**
 * Access levels and safety rules: draft-only mode, permanent deletes,
 * per-tool switches, rate limits, the kill switch, and the capability guard
 * that keeps AI connections away from site administration.
 *
 * @package ViagentProduction
 */

namespace ViagentProduction\Security;

use ViagentProduction\Auth\Authenticator;
use ViagentProduction\Auth\Connection;
use WP_Ability;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Policy.
 */
class Policy {

	const READ    = 'read';
	const CONTENT = 'content';
	const ADMIN   = 'admin';

	const PAUSED_OPTION           = 'viagent_production_paused';
	const PERMANENT_DELETE_OPTION = 'viagent_production_allow_permanent_delete';
	const TOOL_OVERRIDES_OPTION   = 'viagent_production_tool_overrides';

	/**
	 * Post statuses that are not visible to site visitors.
	 */
	const PRIVATE_STATUSES = array( 'draft', 'pending', 'auto-draft' );

	/**
	 * Capabilities an AI connection can never use, whatever the connected
	 * user's role (administrators and super admins included). Site
	 * administration stays with people in the WordPress dashboard.
	 */
	const BLOCKED_CAPS = array(
		// Plugins and themes.
		'install_plugins',
		'activate_plugins',
		'activate_plugin',
		'deactivate_plugins',
		'deactivate_plugin',
		'update_plugins',
		'delete_plugins',
		'edit_plugins',
		'upload_plugins',
		'resume_plugin',
		'switch_themes',
		'install_themes',
		'update_themes',
		'delete_themes',
		'edit_themes',
		'upload_themes',
		'resume_theme',
		// Core, files and languages.
		'update_core',
		'update_php',
		'update_https',
		'update_languages',
		'install_languages',
		'edit_files',
		'unfiltered_html',
		'unfiltered_upload',
		'import',
		'export',
		'export_others_personal_data',
		'erase_others_personal_data',
		'delete_site',
		// Users.
		'create_users',
		'add_users',
		'edit_users',
		'edit_user',
		'delete_users',
		'delete_user',
		'remove_users',
		'remove_user',
		'promote_users',
		'promote_user',
		// Multisite network.
		'manage_network',
		'manage_sites',
		'manage_network_users',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_options',
		'create_sites',
		'delete_sites',
		'upgrade_network',
		'setup_network',
	);

	/**
	 * Options an AI connection can never change, even through another
	 * plugin's ability: site address, admin email, registration and roles,
	 * active plugins and theme, and search engine visibility. Viagent Production's own
	 * options and every user's roles are protected too.
	 */
	const PROTECTED_OPTIONS = array(
		'siteurl',
		'home',
		'admin_email',
		'new_admin_email',
		'users_can_register',
		'default_role',
		'active_plugins',
		'recently_activated',
		'uninstall_plugins',
		'template',
		'stylesheet',
		'current_theme',
		'blog_public',
		'permalink_structure',
		'upload_path',
		'upload_url_path',
		'WPLANG',
	);

	/**
	 * User ID of the AI connection for the current request.
	 *
	 * @var int
	 */
	private static $guarded_user = 0;

	/**
	 * Applies the capability guard to the user an AI connection acts as, for
	 * the rest of the current request.
	 *
	 * @param int $user_id User ID.
	 */
	public static function guard_connection_user( $user_id ) {
		self::$guarded_user = (int) $user_id;
		// map_meta_cap also applies to super admins, unlike user_has_cap.
		add_filter( 'map_meta_cap', array( self::class, 'block_admin_caps' ), PHP_INT_MAX, 4 );
		add_filter( 'pre_update_option', array( self::class, 'block_protected_option' ), PHP_INT_MAX, 3 );
		add_filter( 'update_user_metadata', array( self::class, 'block_role_meta' ), PHP_INT_MAX, 3 );
		add_filter( 'add_user_metadata', array( self::class, 'block_role_meta' ), PHP_INT_MAX, 3 );
		add_filter( 'delete_user_metadata', array( self::class, 'block_role_meta' ), PHP_INT_MAX, 3 );
		// Content filtering was set up when the user was set; redo it without unfiltered_html.
		kses_init();
	}

	/**
	 * Denies administrative capabilities to the guarded user.
	 *
	 * @param string[] $caps    Primitive capabilities required.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User ID.
	 * @return string[]
	 */
	public static function block_admin_caps( $caps, $cap, $user_id ) {
		if ( self::$guarded_user && (int) $user_id === self::$guarded_user && array_intersect( array_merge( array( $cap ), (array) $caps ), self::BLOCKED_CAPS ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	/**
	 * Keeps protected options unchanged during AI requests.
	 *
	 * @param mixed  $value     New value.
	 * @param string $option    Option name.
	 * @param mixed  $old_value Current value.
	 * @return mixed
	 */
	public static function block_protected_option( $value, $option, $old_value ) {
		global $wpdb;
		// Viagent Production's own options (pause switch, tool switches…) are the site owner's alone.
		if ( in_array( $option, self::PROTECTED_OPTIONS, true ) || 0 === strpos( $option, 'viagent_production_' ) || $wpdb->get_blog_prefix() . 'user_roles' === $option ) {
			return $old_value;
		}
		return $value;
	}

	/**
	 * Refuses changes to any user's roles, capabilities or user level during AI requests.
	 *
	 * @param null|bool $check    Short-circuit value.
	 * @param int       $user_id  User ID.
	 * @param string    $meta_key Meta key.
	 * @return null|bool
	 */
	public static function block_role_meta( $check, $user_id, $meta_key ) {
		global $wpdb;
		$prefix = $wpdb->get_blog_prefix();
		if ( in_array( $meta_key, array( $prefix . 'capabilities', $prefix . 'user_level' ), true ) ) {
			return false;
		}
		return $check;
	}

	/**
	 * Access levels, lowest first.
	 *
	 * @return array<string,int>
	 */
	public static function levels() {
		return array(
			self::READ    => 1,
			self::CONTENT => 2,
			self::ADMIN   => 3,
		);
	}

	/**
	 * Human-readable labels for access levels.
	 *
	 * @return array<string,string>
	 */
	public static function level_labels() {
		return array(
			self::READ    => __( 'Read only', 'viagent-production' ),
			self::CONTENT => __( 'Content editor', 'viagent-production' ),
			self::ADMIN   => __( 'Site manager', 'viagent-production' ),
		);
	}

	/**
	 * Whether an access level string is valid.
	 *
	 * @param string $level Level.
	 * @return bool
	 */
	public static function is_valid_level( $level ) {
		return isset( self::levels()[ $level ] );
	}

	/**
	 * Whether a connection with $granted level may use a tool requiring $required level.
	 *
	 * @param string $granted  Level granted to the connection.
	 * @param string $required Level the tool needs.
	 * @return bool
	 */
	public static function allows( $granted, $required ) {
		$levels = self::levels();
		if ( ! isset( $levels[ $granted ], $levels[ $required ] ) ) {
			return false;
		}
		return $levels[ $granted ] >= $levels[ $required ];
	}

	/**
	 * Whether all AI access is paused by the site owner.
	 *
	 * @return bool
	 */
	public static function is_paused() {
		return (bool) get_option( self::PAUSED_OPTION, false );
	}

	/**
	 * Whether the admin allows the AI to delete things permanently (skip the trash).
	 *
	 * @return bool
	 */
	public static function allows_permanent_delete() {
		return (bool) get_option( self::PERMANENT_DELETE_OPTION, false );
	}

	/**
	 * Whether the current MCP connection is limited to drafts.
	 *
	 * @return bool
	 */
	public static function is_draft_only() {
		$connection = Authenticator::current();
		return $connection && $connection->draft_only;
	}

	/**
	 * Blocks statuses that would make content public while in draft-only mode.
	 *
	 * @param string $status Requested post status.
	 * @return true|WP_Error
	 */
	public static function check_status( $status ) {
		if ( self::is_draft_only() && ! in_array( $status, self::PRIVATE_STATUSES, true ) ) {
			return new WP_Error(
				'viagent_production_draft_only',
				__( 'Draft-only mode is on, so the status can only be "draft" or "pending". Ask the site owner to publish it from WordPress, or to allow publishing in Viagent Production.', 'viagent-production' )
			);
		}
		return true;
	}

	/**
	 * Blocks changes to content visitors can already see while in draft-only mode.
	 *
	 * @param WP_Post $post Post.
	 * @return true|WP_Error
	 */
	public static function check_post_editable( WP_Post $post ) {
		if ( self::is_draft_only() && ! in_array( $post->post_status, self::PRIVATE_STATUSES, true ) ) {
			return new WP_Error(
				'viagent_production_draft_only',
				sprintf(
					/* translators: %s: post status */
					__( 'Draft-only mode is on and this item is "%s", so it cannot be changed. Create a new draft instead, or ask the site owner for more access in Viagent Production.', 'viagent-production' ),
					$post->post_status
				)
			);
		}
		return true;
	}

	/**
	 * Whether a tool is switched on. Admin overrides win over the ability's default.
	 *
	 * @param WP_Ability $ability Ability.
	 * @return bool
	 */
	public static function is_tool_enabled( WP_Ability $ability ) {
		$overrides = (array) get_option( self::TOOL_OVERRIDES_OPTION, array() );
		if ( isset( $overrides[ $ability->get_name() ] ) ) {
			return (bool) $overrides[ $ability->get_name() ];
		}
		$viagent = (array) $ability->get_meta_item( 'viagent_production', array() );
		return $viagent['default_enabled'] ?? true;
	}

	/**
	 * Whether a tool may be used in draft-only mode. Read-only tools always can;
	 * write tools must opt in with `meta.viagent_production.draft_safe` and guard themselves.
	 *
	 * @param WP_Ability $ability Ability.
	 * @return bool
	 */
	public static function is_draft_safe( WP_Ability $ability ) {
		$annotations = (array) $ability->get_meta_item( 'annotations', array() );
		if ( ! empty( $annotations['readonly'] ) ) {
			return true;
		}
		$viagent = (array) $ability->get_meta_item( 'viagent_production', array() );
		return ! empty( $viagent['draft_safe'] );
	}

	/**
	 * Counts a tool call against the per-connection limit.
	 *
	 * @param Connection $connection Connection.
	 * @return true|WP_Error
	 */
	public static function check_rate_limit( Connection $connection ) {
		/**
		 * Filters the number of tool calls a connection may make per minute.
		 *
		 * @param int        $limit      Calls per minute. 0 disables the limit.
		 * @param Connection $connection Connection.
		 */
		$limit = (int) apply_filters( 'viagent_production_rate_limit', 120, $connection );
		if ( $limit <= 0 ) {
			return true;
		}

		$key   = 'viagent_production_rl_' . md5( $connection->method . ':' . $connection->credential_id . ':' . $connection->user_id . ':' . gmdate( 'YmdHi' ) );
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, 2 * MINUTE_IN_SECONDS );

		if ( $count > $limit ) {
			return new WP_Error( 'viagent_production_rate_limited', __( 'Too many requests. Please wait a minute and try again.', 'viagent-production' ) );
		}
		return true;
	}
}
