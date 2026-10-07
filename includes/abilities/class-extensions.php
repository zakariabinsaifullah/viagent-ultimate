<?php
/**
 * Plugin and theme abilities: list, install (WordPress.org or a .zip link),
 * activate, deactivate, update and delete. Viagent Production never lets an AI
 * deactivate, update or delete itself, so the connection cannot cut itself off.
 *
 * @package ViagentProduction
 */

namespace ViagentProduction\Abilities;

use ViagentProduction\Log\Activity_Log;
use ViagentProduction\Security\Policy;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin and theme abilities.
 */
class Extensions {

	/**
	 * Registers abilities.
	 */
	public static function register() {
		$plugin = array(
			'type'        => 'string',
			'description' => __( 'Plugin slug (e.g. "akismet") or file (e.g. "akismet/akismet.php") from list_plugins.', 'viagent-production' ),
		);
		$theme  = array(
			'type'        => 'string',
			'description' => __( 'Theme folder name (stylesheet) from list_themes.', 'viagent-production' ),
		);
		$source = array(
			'slug'    => array(
				'type'        => 'string',
				'pattern'     => '^[a-z0-9-]+$',
				'description' => __( 'WordPress.org directory slug. Pass this or zip_url.', 'viagent-production' ),
			),
			'zip_url' => array(
				'type'        => 'string',
				'format'      => 'uri',
				'description' => __( 'Public https link to a .zip package (e.g. a premium plugin or GitHub release). Pass this or slug. Only works when the site owner has turned on "Allow AI apps to change code" in Viagent Production → Settings.', 'viagent-production' ),
			),
		);

		Abilities::add(
			'list-plugins',
			array(
				'category'    => 'viagent-production-extensions',
				'label'       => __( 'List plugins', 'viagent-production' ),
				'description' => __( 'Lists installed plugins with version, active state and whether an update is available.', 'viagent-production' ),
				'execute'     => array( self::class, 'list_plugins' ),
				'permission'  => 'activate_plugins',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'install-plugin',
			array(
				'category'    => 'viagent-production-extensions',
				'label'       => __( 'Install plugin', 'viagent-production' ),
				'description' => __( 'Installs a plugin from the WordPress.org directory (slug) or from a .zip link (zip_url), optionally activating it. Set overwrite to replace an installed copy with the package.', 'viagent-production' ),
				'input'       => array_merge(
					$source,
					array(
						'activate'  => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'overwrite' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Replace the plugin if it is already installed.', 'viagent-production' ),
						),
					)
				),
				'execute'     => array( self::class, 'install_plugin' ),
				'permission'  => 'install_plugins',
				'meta'        => Abilities::write_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'activate-plugin',
			array(
				'category'    => 'viagent-production-extensions',
				'label'       => __( 'Activate plugin', 'viagent-production' ),
				'description' => __( 'Activates an installed plugin. If the plugin causes a fatal error it stays inactive.', 'viagent-production' ),
				'input'       => array( 'plugin' => $plugin ),
				'required'    => array( 'plugin' ),
				'execute'     => array( self::class, 'activate_plugin' ),
				'permission'  => 'activate_plugins',
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'idempotent' => true ) ),
			)
		);

		Abilities::add(
			'deactivate-plugin',
			array(
				'category'    => 'viagent-production-extensions',
				'label'       => __( 'Deactivate plugin', 'viagent-production' ),
				'description' => __( 'Deactivates a plugin. Viagent Production itself cannot be deactivated this way.', 'viagent-production' ),
				'input'       => array( 'plugin' => $plugin ),
				'required'    => array( 'plugin' ),
				'execute'     => array( self::class, 'deactivate_plugin' ),
				'permission'  => 'activate_plugins',
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'idempotent' => true ) ),
			)
		);

		Abilities::add(
			'update-plugin',
			array(
				'category'    => 'viagent-production-extensions',
				'label'       => __( 'Update plugin', 'viagent-production' ),
				'description' => __( 'Updates a plugin to the latest version offered by WordPress (see update_available in list_plugins). Active plugins stay active.', 'viagent-production' ),
				'input'       => array( 'plugin' => $plugin ),
				'required'    => array( 'plugin' ),
				'execute'     => array( self::class, 'update_plugin' ),
				'permission'  => 'update_plugins',
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'idempotent' => true ) ),
			)
		);

		Abilities::add(
			'delete-plugin',
			array(
				'category'    => 'viagent-production-extensions',
				'label'       => __( 'Delete plugin', 'viagent-production' ),
				'description' => __( 'Deactivates (if needed) and permanently deletes a plugin and its files. The plugin may also remove its data. This cannot be undone.', 'viagent-production' ),
				'input'       => array( 'plugin' => $plugin ),
				'required'    => array( 'plugin' ),
				'execute'     => array( self::class, 'delete_plugin' ),
				'permission'  => 'delete_plugins',
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'destructive' => true ) ),
			)
		);

		Abilities::add(
			'list-themes',
			array(
				'category'    => 'viagent-production-extensions',
				'label'       => __( 'List themes', 'viagent-production' ),
				'description' => __( 'Lists installed themes, which one is active, and whether an update is available.', 'viagent-production' ),
				'execute'     => array( self::class, 'list_themes' ),
				'permission'  => 'switch_themes',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'install-theme',
			array(
				'category'    => 'viagent-production-extensions',
				'label'       => __( 'Install theme', 'viagent-production' ),
				'description' => __( 'Installs a theme from the WordPress.org directory (slug) or from a .zip link (zip_url), optionally activating it. Set overwrite to replace an installed copy with the package.', 'viagent-production' ),
				'input'       => array_merge(
					$source,
					array(
						'activate'  => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'overwrite' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Replace the theme if it is already installed.', 'viagent-production' ),
						),
					)
				),
				'execute'     => array( self::class, 'install_theme' ),
				'permission'  => 'install_themes',
				'meta'        => Abilities::write_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'activate-theme',
			array(
				'category'    => 'viagent-production-extensions',
				'label'       => __( 'Activate theme', 'viagent-production' ),
				'description' => __( 'Switches the active theme. This changes how the whole site looks.', 'viagent-production' ),
				'input'       => array( 'stylesheet' => $theme ),
				'required'    => array( 'stylesheet' ),
				'execute'     => array( self::class, 'activate_theme' ),
				'permission'  => 'switch_themes',
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'idempotent' => true ) ),
			)
		);

		Abilities::add(
			'update-theme',
			array(
				'category'    => 'viagent-production-extensions',
				'label'       => __( 'Update theme', 'viagent-production' ),
				'description' => __( 'Updates a theme to the latest version offered by WordPress (see update_available in list_themes).', 'viagent-production' ),
				'input'       => array( 'stylesheet' => $theme ),
				'required'    => array( 'stylesheet' ),
				'execute'     => array( self::class, 'update_theme' ),
				'permission'  => 'update_themes',
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'idempotent' => true ) ),
			)
		);

		Abilities::add(
			'delete-theme',
			array(
				'category'    => 'viagent-production-extensions',
				'label'       => __( 'Delete theme', 'viagent-production' ),
				'description' => __( 'Permanently deletes an installed theme and its files. The active theme and its parent cannot be deleted. This cannot be undone.', 'viagent-production' ),
				'input'       => array( 'stylesheet' => $theme ),
				'required'    => array( 'stylesheet' ),
				'execute'     => array( self::class, 'delete_theme' ),
				'permission'  => 'delete_themes',
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'destructive' => true ) ),
			)
		);
	}

	/**
	 * Loads the admin helpers used for installing and deleting.
	 */
	private static function load_admin_includes() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}

	/**
	 * Connects to the filesystem without prompting for FTP details.
	 *
	 * @return true|WP_Error
	 */
	private static function filesystem() {
		self::load_admin_includes();
		if ( ! WP_Filesystem() ) {
			return new WP_Error( 'viagent_production_filesystem', __( 'WordPress cannot write files on this server without FTP details. Add the FTP constants to wp-config.php, or make the change from the WordPress dashboard.', 'viagent-production' ) );
		}
		return true;
	}

	/**
	 * Resolves a slug or file to an installed plugin file.
	 *
	 * @param string $plugin Slug or file.
	 * @return string|WP_Error
	 */
	private static function resolve( $plugin ) {
		self::load_admin_includes();
		$plugins = get_plugins();

		if ( isset( $plugins[ $plugin ] ) ) {
			return $plugin;
		}
		foreach ( array_keys( $plugins ) as $file ) {
			if ( dirname( $file ) === $plugin || basename( $file, '.php' ) === $plugin ) {
				return $file;
			}
		}
		return new WP_Error( 'viagent_production_plugin_not_found', __( 'That plugin is not installed. Use list_plugins to see installed plugins, or install_plugin to add one.', 'viagent-production' ) );
	}

	/**
	 * Refuses actions on Viagent Production itself.
	 *
	 * @param string $file Plugin file.
	 * @return true|WP_Error
	 */
	private static function not_self( $file ) {
		if ( plugin_basename( VIAGENT_PRODUCTION_FILE ) === $file ) {
			return new WP_Error( 'viagent_production_self', __( 'Viagent Production cannot change itself — that would disconnect you. The site owner can do it from the Plugins screen.', 'viagent-production' ) );
		}
		return true;
	}

	/**
	 * Resolves the package for an install from the `slug` or `zip_url` input.
	 *
	 * @param array  $input Input.
	 * @param string $type  "plugin" or "theme".
	 * @return array{package:string,name:string,version:string}|WP_Error
	 */
	private static function package( array $input, $type ) {
		if ( ! empty( $input['zip_url'] ) ) {
			// A .zip from anywhere is unreviewed code; WordPress.org packages are allowed without the switch.
			if ( ! Policy::allows_code() ) {
				return Policy::code_disabled_error();
			}
			$url = wp_http_validate_url( $input['zip_url'] );
			if ( ! $url || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
				return new WP_Error( 'viagent_production_invalid_url', __( 'zip_url must be a public https link.', 'viagent-production' ) );
			}
			return array(
				'package' => $url,
				'name'    => '',
				'version' => '',
			);
		}
		if ( empty( $input['slug'] ) ) {
			return new WP_Error( 'viagent_production_missing_argument', __( 'Pass a WordPress.org slug or a zip_url.', 'viagent-production' ) );
		}

		$args = array(
			'slug'   => $input['slug'],
			'fields' => array( 'sections' => false ),
		);
		$api  = 'plugin' === $type ? plugins_api( 'plugin_information', $args ) : themes_api( 'theme_information', $args );
		if ( is_wp_error( $api ) || empty( $api->download_link ) ) {
			return new WP_Error(
				'viagent_production_not_found',
				'plugin' === $type
					? __( 'No plugin with that slug was found on WordPress.org.', 'viagent-production' )
					: __( 'No theme with that slug was found on WordPress.org.', 'viagent-production' )
			);
		}
		return array(
			'package' => $api->download_link,
			'name'    => $api->name,
			'version' => $api->version,
		);
	}

	/**
	 * Turns an upgrader run into a WP_Error when anything went wrong.
	 *
	 * @param mixed                  $result Upgrader result.
	 * @param \WP_Ajax_Upgrader_Skin $skin   Skin.
	 * @return true|WP_Error
	 */
	private static function upgrader_error( $result, $skin ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( is_wp_error( $skin->result ) ) {
			return $skin->result;
		}
		if ( $skin->get_errors()->has_errors() ) {
			return $skin->get_errors();
		}
		if ( ! $result ) {
			return new WP_Error( 'viagent_production_install_failed', __( 'The package could not be installed.', 'viagent-production' ) );
		}
		return true;
	}

	/**
	 * Lists plugins.
	 *
	 * @return array
	 */
	public static function list_plugins() {
		self::load_admin_includes();
		$updates = get_site_transient( 'update_plugins' );
		$self    = plugin_basename( VIAGENT_PRODUCTION_FILE );
		$result  = array();

		foreach ( get_plugins() as $file => $data ) {
			$result[] = array(
				'file'             => $file,
				'name'             => $data['Name'],
				'version'          => $data['Version'],
				'author'           => wp_strip_all_tags( $data['Author'] ),
				'description'      => wp_trim_words( wp_strip_all_tags( $data['Description'] ), 25 ),
				'active'           => is_plugin_active( $file ),
				'update_available' => isset( $updates->response[ $file ] ) ? $updates->response[ $file ]->new_version : null,
				'protected'        => $file === $self,
			);
		}
		return $result;
	}

	/**
	 * Installs a plugin.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function install_plugin( $input ) {
		$fs = self::filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}
		if ( ! empty( $input['slug'] ) && empty( $input['zip_url'] ) && empty( $input['overwrite'] ) ) {
			$existing = self::resolve( $input['slug'] );
			if ( ! is_wp_error( $existing ) ) {
				/* translators: %s: plugin file */
				return new WP_Error( 'viagent_production_already_installed', sprintf( __( 'This plugin is already installed (%s). Use activate_plugin or update_plugin, or pass overwrite.', 'viagent-production' ), $existing ) );
			}
		}

		$package = self::package( $input, 'plugin' );
		if ( is_wp_error( $package ) ) {
			return $package;
		}

		// A package that unpacks into Viagent Production's own folder must never replace it.
		$guard = static function ( $source ) {
			if ( ! is_wp_error( $source ) && basename( untrailingslashit( $source ) ) === basename( VIAGENT_PRODUCTION_DIR ) ) {
				return new WP_Error( 'viagent_production_self', __( 'This package would replace Viagent Production itself, so it was not installed.', 'viagent-production' ) );
			}
			return $source;
		};
		add_filter( 'upgrader_source_selection', $guard, PHP_INT_MAX );

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $package['package'], array( 'overwrite_package' => ! empty( $input['overwrite'] ) ) );
		remove_filter( 'upgrader_source_selection', $guard, PHP_INT_MAX );
		$error = self::upgrader_error( $result, $skin );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$file = $upgrader->plugin_info();
		wp_clean_plugins_cache();
		Activity_Log::set_object( 'plugin', 0 );

		$data     = $file ? get_plugins()[ $file ] ?? array() : array();
		$response = array(
			'plugin'  => $file,
			'name'    => $data['Name'] ?? $package['name'],
			'version' => $data['Version'] ?? $package['version'],
			'active'  => $file ? is_plugin_active( $file ) : false,
		);

		if ( ! empty( $input['activate'] ) && $file && ! $response['active'] ) {
			$activated = activate_plugin( $file );
			if ( is_wp_error( $activated ) ) {
				$response['warning'] = $activated->get_error_message();
			} else {
				$response['active'] = true;
			}
		}
		return $response;
	}

	/**
	 * Activates a plugin.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function activate_plugin( $input ) {
		$file = self::resolve( $input['plugin'] );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( is_plugin_active( $file ) ) {
			return array(
				'plugin' => $file,
				'active' => true,
				'note'   => __( 'The plugin was already active.', 'viagent-production' ),
			);
		}

		$result = activate_plugin( $file );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Activity_Log::set_object( 'plugin', 0 );
		Activity_Log::set_undo(
			array(
				'action' => 'deactivate_plugin',
				'plugin' => $file,
			)
		);
		return array(
			'plugin' => $file,
			'active' => true,
		);
	}

	/**
	 * Deactivates a plugin.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function deactivate_plugin( $input ) {
		$file = self::resolve( $input['plugin'] );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$self = self::not_self( $file );
		if ( is_wp_error( $self ) ) {
			return $self;
		}
		if ( ! is_plugin_active( $file ) ) {
			return array(
				'plugin' => $file,
				'active' => false,
				'note'   => __( 'The plugin was already inactive.', 'viagent-production' ),
			);
		}

		deactivate_plugins( $file );

		Activity_Log::set_object( 'plugin', 0 );
		Activity_Log::set_undo(
			array(
				'action' => 'activate_plugin',
				'plugin' => $file,
			)
		);
		return array(
			'plugin' => $file,
			'active' => is_plugin_active( $file ),
		);
	}

	/**
	 * Updates a plugin.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_plugin( $input ) {
		$file = self::resolve( $input['plugin'] );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$self = self::not_self( $file );
		if ( is_wp_error( $self ) ) {
			return $self;
		}
		$fs = self::filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		wp_update_plugins();
		$updates = get_site_transient( 'update_plugins' );
		$before  = get_plugins()[ $file ]['Version'];
		if ( empty( $updates->response[ $file ] ) ) {
			return array(
				'plugin'  => $file,
				'version' => $before,
				'note'    => __( 'No update is available for this plugin.', 'viagent-production' ),
			);
		}

		// The bulk upgrader keeps active plugins active, like the Updates screen.
		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$results  = $upgrader->bulk_upgrade( array( $file ) );
		$error    = self::upgrader_error( is_array( $results ) ? ( $results[ $file ] ?? false ) : $results, $skin );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		wp_clean_plugins_cache();
		Activity_Log::set_object( 'plugin', 0 );
		return array(
			'plugin'           => $file,
			'previous_version' => $before,
			'version'          => get_plugins()[ $file ]['Version'] ?? null,
			'active'           => is_plugin_active( $file ),
		);
	}

	/**
	 * Deletes a plugin.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function delete_plugin( $input ) {
		$file = self::resolve( $input['plugin'] );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$self = self::not_self( $file );
		if ( is_wp_error( $self ) ) {
			return $self;
		}
		$fs = self::filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		if ( is_plugin_active( $file ) ) {
			deactivate_plugins( $file );
		}
		$result = delete_plugins( array( $file ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( true !== $result ) {
			return new WP_Error( 'viagent_production_delete_failed', __( 'The plugin could not be deleted.', 'viagent-production' ) );
		}

		Activity_Log::set_object( 'plugin', 0 );
		return array(
			'plugin'  => $file,
			'deleted' => true,
		);
	}

	/**
	 * Lists themes.
	 *
	 * @return array
	 */
	public static function list_themes() {
		$active  = get_stylesheet();
		$updates = get_site_transient( 'update_themes' );
		$result  = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$result[] = array(
				'stylesheet'       => $stylesheet,
				'name'             => $theme->get( 'Name' ),
				'version'          => $theme->get( 'Version' ),
				'block_theme'      => $theme->is_block_theme(),
				'parent'           => $theme->parent() ? $theme->get_template() : null,
				'active'           => $stylesheet === $active,
				'update_available' => $updates->response[ $stylesheet ]['new_version'] ?? null,
			);
		}
		return $result;
	}

	/**
	 * Installs a theme.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function install_theme( $input ) {
		$fs = self::filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}
		if ( ! empty( $input['slug'] ) && empty( $input['zip_url'] ) && empty( $input['overwrite'] ) && wp_get_theme( $input['slug'] )->exists() ) {
			return new WP_Error( 'viagent_production_already_installed', __( 'This theme is already installed. Use activate_theme or update_theme, or pass overwrite.', 'viagent-production' ) );
		}

		$package = self::package( $input, 'theme' );
		if ( is_wp_error( $package ) ) {
			return $package;
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Theme_Upgrader( $skin );
		$result   = $upgrader->install( $package['package'], array( 'overwrite_package' => ! empty( $input['overwrite'] ) ) );
		$error    = self::upgrader_error( $result, $skin );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$theme = $upgrader->theme_info();
		Activity_Log::set_object( 'theme', 0 );
		if ( ! $theme ) {
			return new WP_Error( 'viagent_production_install_failed', __( 'The package was unpacked, but it does not look like a valid theme.', 'viagent-production' ) );
		}

		$response = array(
			'stylesheet' => $theme->get_stylesheet(),
			'name'       => $theme->get( 'Name' ),
			'version'    => $theme->get( 'Version' ),
			'active'     => get_stylesheet() === $theme->get_stylesheet(),
		);
		if ( $theme->parent() === false && $theme->get_template() !== $theme->get_stylesheet() ) {
			/* translators: %s: parent theme folder */
			$response['warning'] = sprintf( __( 'This is a child theme. Install its parent theme "%s" before activating it.', 'viagent-production' ), $theme->get_template() );
		}

		if ( ! empty( $input['activate'] ) && ! $response['active'] && empty( $response['warning'] ) ) {
			$activated = self::activate_theme( array( 'stylesheet' => $theme->get_stylesheet() ) );
			if ( is_wp_error( $activated ) ) {
				$response['warning'] = $activated->get_error_message();
			} else {
				$response['active'] = true;
			}
		}
		return $response;
	}

	/**
	 * Activates a theme.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function activate_theme( $input ) {
		$theme = wp_get_theme( $input['stylesheet'] );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'viagent_production_theme_not_found', __( 'That theme is not installed. Use list_themes to see installed themes.', 'viagent-production' ) );
		}
		if ( ! $theme->is_allowed() ) {
			return new WP_Error( 'viagent_production_theme_not_allowed', __( 'That theme is not allowed on this site.', 'viagent-production' ) );
		}
		if ( $theme->errors() ) {
			return $theme->errors();
		}

		$previous = get_stylesheet();
		if ( $previous === $theme->get_stylesheet() ) {
			return array(
				'active' => $previous,
				'name'   => $theme->get( 'Name' ),
				'note'   => __( 'The theme was already active.', 'viagent-production' ),
			);
		}

		switch_theme( $theme->get_stylesheet() );

		Activity_Log::set_object( 'theme', 0 );
		Activity_Log::set_undo(
			array(
				'action'     => 'switch_theme',
				'stylesheet' => $previous,
			)
		);
		return array(
			'active'   => get_stylesheet(),
			'name'     => wp_get_theme()->get( 'Name' ),
			'previous' => $previous,
		);
	}

	/**
	 * Updates a theme.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_theme( $input ) {
		$theme = wp_get_theme( $input['stylesheet'] );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'viagent_production_theme_not_found', __( 'That theme is not installed. Use list_themes to see installed themes.', 'viagent-production' ) );
		}
		$fs = self::filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		$stylesheet = $theme->get_stylesheet();
		$before     = $theme->get( 'Version' );
		wp_update_themes();
		$updates = get_site_transient( 'update_themes' );
		if ( empty( $updates->response[ $stylesheet ] ) ) {
			return array(
				'stylesheet' => $stylesheet,
				'version'    => $before,
				'note'       => __( 'No update is available for this theme.', 'viagent-production' ),
			);
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Theme_Upgrader( $skin );
		$results  = $upgrader->bulk_upgrade( array( $stylesheet ) );
		$error    = self::upgrader_error( is_array( $results ) ? ( $results[ $stylesheet ] ?? false ) : $results, $skin );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		wp_clean_themes_cache();
		Activity_Log::set_object( 'theme', 0 );
		return array(
			'stylesheet'       => $stylesheet,
			'previous_version' => $before,
			'version'          => wp_get_theme( $stylesheet )->get( 'Version' ),
		);
	}

	/**
	 * Deletes a theme.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function delete_theme( $input ) {
		$theme = wp_get_theme( $input['stylesheet'] );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'viagent_production_theme_not_found', __( 'That theme is not installed. Use list_themes to see installed themes.', 'viagent-production' ) );
		}
		$stylesheet = $theme->get_stylesheet();
		if ( get_stylesheet() === $stylesheet || get_template() === $stylesheet ) {
			return new WP_Error( 'viagent_production_theme_in_use', __( 'The active theme (or its parent) cannot be deleted. Activate another theme first.', 'viagent-production' ) );
		}
		$fs = self::filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		$result = delete_theme( $stylesheet );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new WP_Error( 'viagent_production_delete_failed', __( 'The theme could not be deleted.', 'viagent-production' ) );
		}

		Activity_Log::set_object( 'theme', 0 );
		return array(
			'stylesheet' => $stylesheet,
			'deleted'    => true,
		);
	}
}
