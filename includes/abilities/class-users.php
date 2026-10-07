<?php
/**
 * User abilities: list, create, update and delete accounts and change roles.
 * The connected account can never delete itself or lose its own
 * administrator role, and the last administrator is always kept.
 *
 * @package ViagentProduction
 */

namespace ViagentProduction\Abilities;

use ViagentProduction\Log\Activity_Log;
use ViagentProduction\Security\Policy;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * User abilities.
 */
class Users {

	/**
	 * Profile fields accepted by create-user and update-user.
	 */
	const FIELDS = array(
		'email'        => 'user_email',
		'display_name' => 'display_name',
		'first_name'   => 'first_name',
		'last_name'    => 'last_name',
		'nickname'     => 'nickname',
		'website'      => 'user_url',
		'bio'          => 'description',
		'role'         => 'role',
	);

	/**
	 * Registers abilities.
	 */
	public static function register() {
		$id      = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$profile = array(
			'email'        => array(
				'type'   => 'string',
				'format' => 'email',
			),
			'display_name' => array( 'type' => 'string' ),
			'first_name'   => array( 'type' => 'string' ),
			'last_name'    => array( 'type' => 'string' ),
			'nickname'     => array( 'type' => 'string' ),
			'website'      => array( 'type' => 'string' ),
			'bio'          => array( 'type' => 'string' ),
			'role'         => array(
				'type'        => 'string',
				'description' => __( 'Role slug from list_roles, e.g. "subscriber", "editor" or "administrator".', 'viagent-production' ),
			),
			'password'     => array(
				'type'        => 'string',
				'minLength'   => 8,
				'description' => __( 'New password. Leave out to keep it (or, for new users, to generate one).', 'viagent-production' ),
			),
		);

		Abilities::add(
			'list-users',
			array(
				'category'    => 'viagent-production-users',
				'label'       => __( 'List users', 'viagent-production' ),
				'description' => __( 'Lists user accounts with their roles. Filter by role or search by name/email.', 'viagent-production' ),
				'input'       => array_merge(
					array(
						'role'   => array( 'type' => 'string' ),
						'search' => array( 'type' => 'string' ),
					),
					Abilities::paging()
				),
				'execute'     => array( self::class, 'list_users' ),
				'permission'  => 'list_users',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'get-user',
			array(
				'category'    => 'viagent-production-users',
				'label'       => __( 'Get user', 'viagent-production' ),
				'description' => __( 'Gets one user account by ID.', 'viagent-production' ),
				'input'       => array( 'id' => $id ),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'get_user' ),
				'permission'  => 'list_users',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'list-roles',
			array(
				'category'    => 'viagent-production-users',
				'label'       => __( 'List roles', 'viagent-production' ),
				'description' => __( 'Lists the user roles on this site with how many users have each.', 'viagent-production' ),
				'execute'     => array( self::class, 'list_roles' ),
				'permission'  => 'list_users',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'create-user',
			array(
				'category'    => 'viagent-production-users',
				'label'       => __( 'Create user', 'viagent-production' ),
				'description' => __( 'Creates a user account with any role. Without a password a strong one is generated; send_email emails the new user a link to set their own.', 'viagent-production' ),
				'input'       => array_merge(
					array(
						'username'   => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'send_email' => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => __( 'Email the new user their login details.', 'viagent-production' ),
						),
					),
					$profile
				),
				'required'    => array( 'username', 'email' ),
				'execute'     => array( self::class, 'create_user' ),
				'permission'  => 'create_users',
				'meta'        => Abilities::write_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'update-user',
			array(
				'category'    => 'viagent-production-users',
				'label'       => __( 'Update user', 'viagent-production' ),
				'description' => __( 'Updates a user\'s profile, role or password. The role of the connected account cannot be changed, and the password and email of administrators (and of the connected account) can only be changed by a person in WordPress.', 'viagent-production' ),
				'input'       => array_merge( array( 'id' => $id ), $profile ),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'update_user' ),
				'permission'  => static function ( $input ) {
					$user_id = (int) ( $input['id'] ?? 0 );
					if ( ! current_user_can( 'edit_user', $user_id ) ) {
						return false;
					}
					return ! isset( $input['role'] ) || current_user_can( 'promote_user', $user_id );
				},
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'idempotent' => true ) ),
			)
		);

		Abilities::add(
			'delete-user',
			array(
				'category'    => 'viagent-production-users',
				'label'       => __( 'Delete user', 'viagent-production' ),
				'description' => __( 'Permanently deletes a user account (administrators cannot be deleted this way). Pass reassign_to (a user ID) to keep their posts, or delete_content true to delete their posts too — only when the site owner allows permanent deletes. This cannot be undone.', 'viagent-production' ),
				'input'       => array(
					'id'             => $id,
					'reassign_to'    => $id,
					'delete_content' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'delete_user' ),
				'permission'  => static function ( $input ) {
					return current_user_can( 'delete_user', (int) ( $input['id'] ?? 0 ) );
				},
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'destructive' => true ) ),
			)
		);
	}

	/**
	 * Compact representation of a user.
	 *
	 * @param WP_User $user User.
	 * @return array
	 */
	public static function item( WP_User $user ) {
		return array(
			'id'           => $user->ID,
			'username'     => $user->user_login,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'nickname'     => $user->nickname,
			'website'      => $user->user_url,
			'bio'          => $user->description,
			'roles'        => array_values( $user->roles ),
			'registered'   => $user->user_registered,
			'posts'        => (int) count_user_posts( $user->ID ),
		);
	}

	/**
	 * Lists users.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function list_users( $input ) {
		$args = array(
			'number'      => (int) ( $input['per_page'] ?? 20 ),
			'paged'       => (int) ( $input['page'] ?? 1 ),
			'count_total' => true,
		);
		if ( ! empty( $input['role'] ) ) {
			$args['role'] = $input['role'];
		}
		if ( ! empty( $input['search'] ) ) {
			$args['search'] = '*' . $input['search'] . '*';
		}

		$query = new \WP_User_Query( $args );
		return array(
			'total' => (int) $query->get_total(),
			'items' => array_map( array( self::class, 'item' ), $query->get_results() ),
		);
	}

	/**
	 * Gets a user.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_user( $input ) {
		$user = get_userdata( (int) $input['id'] );
		return $user ? self::item( $user ) : new WP_Error( 'viagent_production_not_found', __( 'No user found with that ID.', 'viagent-production' ) );
	}

	/**
	 * Lists roles.
	 *
	 * @return array
	 */
	public static function list_roles() {
		$counts   = count_users();
		$editable = self::editable_roles();
		$result   = array();
		foreach ( wp_roles()->roles as $slug => $role ) {
			$result[] = array(
				'role'       => $slug,
				'name'       => translate_user_role( $role['name'] ),
				'users'      => (int) ( $counts['avail_roles'][ $slug ] ?? 0 ),
				'assignable' => isset( $editable[ $slug ] ),
			);
		}
		return $result;
	}

	/**
	 * Roles the connected user may assign, honouring the `editable_roles`
	 * filter like the dashboard and the REST API do.
	 *
	 * @return array<string,array>
	 */
	private static function editable_roles() {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		return get_editable_roles();
	}

	/**
	 * Checks a requested role exists and may be assigned by the connected user.
	 *
	 * @param string $role Role slug.
	 * @return true|WP_Error
	 */
	private static function check_role( $role ) {
		$editable = self::editable_roles();
		if ( ! wp_roles()->is_role( $role ) || ! isset( $editable[ $role ] ) ) {
			return new WP_Error(
				'viagent_production_invalid_role',
				/* translators: 1: role, 2: available roles */
				sprintf( __( 'The role "%1$s" does not exist or cannot be assigned by the connected user. Available roles: %2$s.', 'viagent-production' ), $role, implode( ', ', array_keys( $editable ) ) )
			);
		}
		return true;
	}

	/**
	 * Whether a user is an administrator (or a super admin on multisite).
	 * Their account details are the site owner's alone.
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	private static function is_privileged( WP_User $user ) {
		return in_array( 'administrator', $user->roles, true ) || ( is_multisite() && is_super_admin( $user->ID ) );
	}

	/**
	 * Number of administrators on the site.
	 *
	 * @return int
	 */
	private static function admin_count() {
		return count(
			get_users(
				array(
					'role'   => 'administrator',
					'fields' => 'ID',
					'number' => 2,
				)
			)
		);
	}

	/**
	 * Maps input to wp_insert_user() fields.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	private static function user_data( array $input ) {
		$data = array();
		foreach ( self::FIELDS as $from => $to ) {
			if ( isset( $input[ $from ] ) ) {
				$data[ $to ] = $input[ $from ];
			}
		}
		if ( isset( $input['password'] ) ) {
			$data['user_pass'] = $input['password'];
		}
		return $data;
	}

	/**
	 * Creates a user.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function create_user( $input ) {
		$input['role'] = $input['role'] ?? get_option( 'default_role', 'subscriber' );
		$valid         = self::check_role( $input['role'] );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( ! current_user_can( 'promote_users' ) && get_option( 'default_role', 'subscriber' ) !== $input['role'] ) {
			return new WP_Error( 'viagent_production_forbidden', __( 'You are not allowed to give new users that role.', 'viagent-production' ) );
		}

		$data               = self::user_data( $input );
		$data['user_login'] = sanitize_user( $input['username'], true );
		if ( empty( $data['user_pass'] ) ) {
			$data['user_pass'] = wp_generate_password( 24 );
		}

		$id = wp_insert_user( wp_slash( $data ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$result = self::item( get_userdata( $id ) );
		if ( $input['send_email'] ?? true ) {
			wp_new_user_notification( $id, null, 'user' );
			$result['note'] = __( 'The user was emailed a link to set their password.', 'viagent-production' );
		}

		Activity_Log::set_object( 'user', $id );
		return $result;
	}

	/**
	 * Updates a user.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_user( $input ) {
		$user = get_userdata( (int) $input['id'] );
		if ( ! $user ) {
			return new WP_Error( 'viagent_production_not_found', __( 'No user found with that ID.', 'viagent-production' ) );
		}
		$is_self = get_current_user_id() === $user->ID;

		// An AI must never be able to lock the owner out: no credential or role
		// changes on the connected account, no credential changes on administrators.
		if ( isset( $input['role'] ) ) {
			$valid = self::check_role( $input['role'] );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			if ( $is_self ) {
				return new WP_Error( 'viagent_production_own_role', __( 'The role of the account the AI is connected as cannot be changed. Ask the site owner to do it in WordPress.', 'viagent-production' ) );
			}
			if ( 'administrator' !== $input['role'] && in_array( 'administrator', $user->roles, true ) && self::admin_count() < 2 ) {
				return new WP_Error( 'viagent_production_last_admin', __( 'This is the only administrator, so their role cannot be changed.', 'viagent-production' ) );
			}
		}
		if ( ( isset( $input['password'] ) || isset( $input['email'] ) ) && ( $is_self || self::is_privileged( $user ) ) ) {
			return new WP_Error( 'viagent_production_protected_account', __( 'The password and email of administrators, and of the account the AI is connected as, can only be changed by a person in WordPress → Users.', 'viagent-production' ) );
		}

		$data = self::user_data( $input );
		if ( empty( $data ) ) {
			return new WP_Error( 'viagent_production_nothing_to_update', __( 'Pass at least one field to change.', 'viagent-production' ) );
		}
		$data['ID'] = $user->ID;

		$previous = array(
			'user_email'   => $user->user_email,
			'display_name' => $user->display_name,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'nickname'     => $user->nickname,
			'user_url'     => $user->user_url,
			'description'  => $user->description,
		);

		$result = wp_update_user( wp_slash( $data ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Activity_Log::set_object( 'user', $user->ID );
		Activity_Log::set_undo(
			array(
				'action'  => 'restore_user',
				'user_id' => $user->ID,
				'fields'  => array_intersect_key( $previous, $data ),
				'roles'   => isset( $data['role'] ) ? array_values( $user->roles ) : null,
			)
		);

		$response = self::item( get_userdata( $user->ID ) );
		if ( isset( $data['user_pass'] ) ) {
			$response['note'] = __( 'The password was changed. A password change cannot be undone from the activity log.', 'viagent-production' );
		}
		return $response;
	}

	/**
	 * Deletes a user.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function delete_user( $input ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$user = get_userdata( (int) $input['id'] );
		if ( ! $user ) {
			return new WP_Error( 'viagent_production_not_found', __( 'No user found with that ID.', 'viagent-production' ) );
		}
		if ( get_current_user_id() === $user->ID ) {
			return new WP_Error( 'viagent_production_own_account', __( 'You cannot delete the account the AI is connected as.', 'viagent-production' ) );
		}
		if ( self::is_privileged( $user ) ) {
			return new WP_Error( 'viagent_production_protected_account', __( 'Administrator accounts cannot be deleted by an AI. Ask the site owner to do it in WordPress → Users.', 'viagent-production' ) );
		}

		$reassign = isset( $input['reassign_to'] ) ? (int) $input['reassign_to'] : null;
		if ( $reassign ) {
			if ( $reassign === $user->ID || ! get_userdata( $reassign ) ) {
				return new WP_Error( 'viagent_production_invalid_user', __( 'reassign_to must be the ID of another existing user.', 'viagent-production' ) );
			}
		} elseif ( count_user_posts( $user->ID, get_post_types(), false ) > 0 ) {
			if ( empty( $input['delete_content'] ) ) {
				return new WP_Error( 'viagent_production_has_content', __( 'This user has content. Pass reassign_to (a user ID) to keep it, or delete_content true to delete it too.', 'viagent-production' ) );
			}
			// Deleting a user's content skips the trash, so it follows the owner's permanent-delete switch.
			if ( ! Policy::allows_permanent_delete() ) {
				return new WP_Error( 'viagent_production_permanent_delete_disabled', __( 'Deleting a user\'s content is permanent and the site owner has not allowed permanent deletes. Pass reassign_to instead, or ask the owner to allow permanent deletes in Viagent Production → Settings.', 'viagent-production' ) );
			}
		}

		if ( ! wp_delete_user( $user->ID, $reassign ) ) {
			return new WP_Error( 'viagent_production_delete_failed', __( 'The user could not be deleted.', 'viagent-production' ) );
		}

		Activity_Log::set_object( 'user', 0 );
		return array(
			'id'          => $user->ID,
			'username'    => $user->user_login,
			'deleted'     => true,
			'reassign_to' => $reassign,
		);
	}

	/**
	 * Undoes a profile or role change.
	 *
	 * @param array $undo Undo instructions.
	 * @return true|WP_Error
	 */
	public static function restore( array $undo ) {
		$user_id = (int) ( $undo['user_id'] ?? 0 );
		$user    = get_userdata( $user_id );
		if ( ! $user || ! current_user_can( 'edit_user', $user_id ) ) {
			return new WP_Error( 'viagent_production_not_found', __( 'The user no longer exists or you cannot edit them.', 'viagent-production' ) );
		}

		$fields = (array) ( $undo['fields'] ?? array() );
		if ( $fields ) {
			$result = wp_update_user( wp_slash( array_merge( $fields, array( 'ID' => $user_id ) ) ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		if ( is_array( $undo['roles'] ?? null ) ) {
			if ( ! current_user_can( 'promote_user', $user_id ) ) {
				return new WP_Error( 'viagent_production_forbidden', __( 'You are not allowed to change this user’s role.', 'viagent-production' ) );
			}
			$user->set_role( '' );
			foreach ( $undo['roles'] as $role ) {
				$user->add_role( $role );
			}
		}
		return true;
	}
}
