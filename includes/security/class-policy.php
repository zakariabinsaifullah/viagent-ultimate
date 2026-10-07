<?php
/**
 * Access levels and safety rules: draft-only mode, permanent deletes,
 * per-tool switches, rate limits, the kill switch, and the capability guard
 * that keeps AI connections away from WordPress core and network administration.
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
	const CODE_OPTION             = 'viagent_production_allow_code';
	const TOOL_OVERRIDES_OPTION   = 'viagent_production_tool_overrides';

	/**
	 * Post statuses that are not visible to site visitors.
	 */
	const PRIVATE_STATUSES = array( 'draft', 'pending', 'auto-draft' );

	/**
	 * Capabilities an AI connection can never use, whatever the connected
	 * user's role (administrators and super admins included). Plugins, themes,
	 * their files and users are open to AI connections at the Full control
	 * level; WordPress core, import/export, privacy tools and the multisite
	 * network stay with people in the WordPress dashboard.
	 */
	const BLOCKED_CAPS = array(
		// Core, languages and generic file access.
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
	 * plugin's ability: site address, admin email, user registration,
	 * search engine visibility and permalinks. The role definitions
	 * ({prefix}user_roles) and Viagent Production's own options are protected
	 * too, so an AI can never unpause itself, switch its own tools on or give
	 * a role new capabilities (see is_protected_option()).
	 */
	const PROTECTED_OPTIONS = array(
		'siteurl',
		'home',
		'admin_email',
		'new_admin_email',
		'users_can_register',
		'default_role',
		'blog_public',
		'permalink_structure',
		'upload_path',
		'upload_url_path',
		'WPLANG',
	);

	/**
	 * Viagent Production options an AI connection may change (managed by its
	 * SEO and content type tools). All other viagent_production_* options are
	 * the site owner's alone.
	 */
	const AI_WRITABLE_OPTION_PREFIXES = array( 'viagent_production_seo_', 'viagent_production_content_types' );

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
		// update_option() is covered above; add_option()/delete_option() have no veto filter, so undo them.
		add_action( 'added_option', array( self::class, 'undo_protected_option_add' ), PHP_INT_MAX, 1 );
		add_action( 'delete_option', array( self::class, 'remember_protected_option' ), PHP_INT_MAX, 1 );
		add_action( 'deleted_option', array( self::class, 'undo_protected_option_delete' ), PHP_INT_MAX, 1 );
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
		// Making the site visible to search engines is allowed; hiding it is not.
		if ( 'blog_public' === $option && '1' === (string) $value ) {
			return $value;
		}
		if ( self::is_protected_option( $option ) ) {
			return $old_value;
		}
		return $value;
	}

	/**
	 * Whether an option may never be changed during an AI request: the
	 * critical site options, the role definitions, and Viagent Production's
	 * own options (pause switch, tool switches…) except the ones its SEO and
	 * content type tools manage.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	public static function is_protected_option( $option ) {
		global $wpdb;
		if ( in_array( $option, self::PROTECTED_OPTIONS, true ) || $wpdb->get_blog_prefix() . 'user_roles' === $option ) {
			return true;
		}
		return 0 === strpos( $option, 'viagent_production_' ) && ! self::is_ai_writable_option( $option );
	}

	/**
	 * Values of protected options about to be deleted, keyed by option name.
	 *
	 * @var array<string,mixed>
	 */
	private static $deleting_options = array();

	/**
	 * Removes a protected option that was added (not updated) during an AI request.
	 *
	 * @param string $option Option name.
	 */
	public static function undo_protected_option_add( $option ) {
		if ( self::is_protected_option( $option ) ) {
			remove_action( 'delete_option', array( self::class, 'remember_protected_option' ), PHP_INT_MAX );
			remove_action( 'deleted_option', array( self::class, 'undo_protected_option_delete' ), PHP_INT_MAX );
			delete_option( $option );
			add_action( 'delete_option', array( self::class, 'remember_protected_option' ), PHP_INT_MAX, 1 );
			add_action( 'deleted_option', array( self::class, 'undo_protected_option_delete' ), PHP_INT_MAX, 1 );
		}
	}

	/**
	 * Remembers a protected option's value right before it is deleted.
	 *
	 * @param string $option Option name.
	 */
	public static function remember_protected_option( $option ) {
		if ( self::is_protected_option( $option ) ) {
			$value = get_option( $option, null );
			if ( null !== $value ) {
				self::$deleting_options[ $option ] = $value;
			}
		}
	}

	/**
	 * Puts a protected option back after it was deleted during an AI request.
	 *
	 * @param string $option Option name.
	 */
	public static function undo_protected_option_delete( $option ) {
		if ( array_key_exists( $option, self::$deleting_options ) ) {
			$value = self::$deleting_options[ $option ];
			unset( self::$deleting_options[ $option ] );
			remove_action( 'added_option', array( self::class, 'undo_protected_option_add' ), PHP_INT_MAX );
			add_option( $option, $value );
			add_action( 'added_option', array( self::class, 'undo_protected_option_add' ), PHP_INT_MAX, 1 );
		}
	}

	/**
	 * Whether an AI connection may change one of Viagent Production's own options.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	public static function is_ai_writable_option( $option ) {
		foreach ( self::AI_WRITABLE_OPTION_PREFIXES as $prefix ) {
			if ( 0 === strpos( $option, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the current AI connection has the Full control level. Outside
	 * an AI request (e.g. the dashboard or WP-CLI) this is false.
	 *
	 * @return bool
	 */
	public static function is_full_control() {
		$connection = Authenticator::current();
		return $connection && self::ADMIN === $connection->access_level;
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
			self::ADMIN   => __( 'Full control', 'viagent-production' ),
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
	 * Whether the site owner lets AI connections change code: write or delete
	 * plugin and theme files, and install plugins or themes from a .zip link.
	 * Off by default, because code the AI writes runs on the server with no
	 * guard at all. Installing from WordPress.org and reading code are always
	 * allowed at the Full control level.
	 *
	 * @return bool
	 */
	public static function allows_code() {
		return (bool) get_option( self::CODE_OPTION, false );
	}

	/**
	 * Error returned by code-changing tools while the switch is off.
	 *
	 * @return WP_Error
	 */
	public static function code_disabled_error() {
		return new WP_Error(
			'viagent_production_code_disabled',
			__( 'Changing code is switched off for AI apps on this site. Ask the site owner to turn on "Allow AI apps to change code" in WordPress → Tools → Viagent Production → Settings, then try again.', 'viagent-production' )
		);
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
