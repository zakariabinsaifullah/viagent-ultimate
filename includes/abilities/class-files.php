<?php
/**
 * Theme and plugin file abilities: browse, read, write and delete files inside
 * a theme or plugin folder (for example to build a child theme or tweak
 * functions.php). PHP is syntax-checked before saving, and a change that
 * crashes the site is rolled back automatically. Viagent Production's own
 * files can be read but never changed.
 *
 * @package ViagentProduction
 */

namespace ViagentProduction\Abilities;

use ViagentProduction\Log\Activity_Log;
use ViagentProduction\Security\Policy;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Theme and plugin file abilities.
 */
class Files {

	const MAX_BYTES      = 1048576;
	const MAX_UNDO_BYTES = 524288;
	const MAX_LIST       = 500;

	/**
	 * File types that can be read and written.
	 */
	const EXTENSIONS = array( 'php', 'css', 'scss', 'js', 'mjs', 'json', 'html', 'htm', 'txt', 'md', 'svg', 'xml', 'twig', 'pot', 'po' );

	/**
	 * Registers abilities.
	 */
	public static function register() {
		$target = array(
			'type' => array(
				'type' => 'string',
				'enum' => array( 'theme', 'plugin' ),
			),
			'name' => array(
				'type'        => 'string',
				'pattern'     => '^[A-Za-z0-9._-]+$',
				'description' => __( 'Theme folder (stylesheet from list_themes) or plugin folder (e.g. "akismet").', 'viagent-production' ),
			),
		);
		$path   = array(
			'type'        => 'string',
			'description' => __( 'Path inside the theme or plugin folder, e.g. "style.css" or "templates/single.html".', 'viagent-production' ),
		);

		Abilities::add(
			'list-extension-files',
			array(
				'category'    => 'viagent-production-files',
				'label'       => __( 'List theme or plugin files', 'viagent-production' ),
				'description' => __( 'Lists the editable files inside a theme or plugin folder, with sizes.', 'viagent-production' ),
				'input'       => array_merge(
					$target,
					array(
						'path' => array(
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Optional sub-folder to list.', 'viagent-production' ),
						),
					)
				),
				'required'    => array( 'type', 'name' ),
				'execute'     => array( self::class, 'list_files' ),
				'permission'  => array( self::class, 'can_edit' ),
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'read-extension-file',
			array(
				'category'    => 'viagent-production-files',
				'label'       => __( 'Read theme or plugin file', 'viagent-production' ),
				'description' => __( 'Reads a file from a theme or plugin folder.', 'viagent-production' ),
				'input'       => array_merge( $target, array( 'path' => $path ) ),
				'required'    => array( 'type', 'name', 'path' ),
				'execute'     => array( self::class, 'read_file' ),
				'permission'  => array( self::class, 'can_edit' ),
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'write-extension-file',
			array(
				'category'    => 'viagent-production-files',
				'label'       => __( 'Write theme or plugin file', 'viagent-production' ),
				'description' => __( 'Creates or replaces a file in a theme or plugin folder with the full new content. Folders are created as needed, so this can build a new child theme or plugin. PHP is syntax-checked first, and if the change crashes the site it is rolled back automatically. Always read_extension_file first and send the complete file. Only works when the site owner has turned on "Allow AI apps to change code" in Viagent Production → Settings.', 'viagent-production' ),
				'input'       => array_merge(
					$target,
					array(
						'path'    => $path,
						'content' => array(
							'type'        => 'string',
							'description' => __( 'Complete new file content.', 'viagent-production' ),
						),
					)
				),
				'required'    => array( 'type', 'name', 'path', 'content' ),
				'execute'     => array( self::class, 'write_file' ),
				'permission'  => array( self::class, 'can_edit' ),
				'meta'        => Abilities::write_meta(
					Policy::ADMIN,
					array(
						'destructive' => true,
						'idempotent'  => true,
					)
				),
			)
		);

		Abilities::add(
			'delete-extension-file',
			array(
				'category'    => 'viagent-production-files',
				'label'       => __( 'Delete theme or plugin file', 'viagent-production' ),
				'description' => __( 'Deletes one file from a theme or plugin folder. Can be undone from the activity log. Only works when the site owner has turned on "Allow AI apps to change code" in Viagent Production → Settings.', 'viagent-production' ),
				'input'       => array_merge( $target, array( 'path' => $path ) ),
				'required'    => array( 'type', 'name', 'path' ),
				'execute'     => array( self::class, 'delete_file' ),
				'permission'  => array( self::class, 'can_edit' ),
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'destructive' => true ) ),
			)
		);
	}

	/**
	 * Permission: theme files need edit_themes, plugin files edit_plugins.
	 * Both are denied when DISALLOW_FILE_EDIT is set.
	 *
	 * @param array $input Input.
	 * @return bool
	 */
	public static function can_edit( $input = array() ) {
		if ( current_user_can( 'plugin' === ( $input['type'] ?? '' ) ? 'edit_plugins' : 'edit_themes' ) ) {
			return true;
		}

		// Tell the AI the real reason; otherwise it only hears "not allowed" and blames the access level.
		$problem = Policy::file_editing_problem();
		if ( '' !== $problem ) {
			return Policy::deny( $problem );
		}
		if ( is_multisite() && ! is_super_admin() ) {
			return Policy::deny( __( 'On a multisite network only super admins may read or edit theme and plugin files. Connect the AI app as a super admin account to use these tools.', 'viagent-production' ) );
		}
		return Policy::deny( __( 'The WordPress account this AI app is connected as is not an administrator, so it cannot read or edit theme and plugin files. Connect the app as an administrator to use these tools.', 'viagent-production' ) );
	}

	/**
	 * Absolute folder of the theme or plugin (it may not exist yet).
	 *
	 * @param string $type "theme" or "plugin".
	 * @param string $name Folder name.
	 * @return string|WP_Error
	 */
	private static function root( $type, $name ) {
		if ( '' === $name || '.' === $name[0] ) {
			return new WP_Error( 'viagent_production_invalid_path', __( 'Invalid theme or plugin folder name.', 'viagent-production' ) );
		}
		if ( 'plugin' === $type ) {
			return wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) . $name );
		}
		$theme = wp_get_theme( $name );
		$base  = $theme->exists() ? $theme->get_theme_root() : get_theme_root();
		return wp_normalize_path( trailingslashit( $base ) . $name );
	}

	/**
	 * Base folders files may live in.
	 *
	 * @return string[]
	 */
	private static function allowed_bases() {
		$bases = array( WP_PLUGIN_DIR );
		foreach ( (array) search_theme_directories() as $theme ) {
			$bases[] = $theme['theme_root'];
		}
		$bases[] = get_theme_root();
		return array_unique(
			array_map(
				static function ( $dir ) {
					$real = realpath( $dir );
					return trailingslashit( wp_normalize_path( $real ? $real : $dir ) );
				},
				$bases
			)
		);
	}

	/**
	 * The theme or plugin base folder an absolute path lives in.
	 *
	 * @param string $path Absolute path.
	 * @return string|false Base folder with a trailing slash, or false when outside.
	 */
	private static function allowed_base_for( $path ) {
		$path = wp_normalize_path( $path );
		foreach ( self::allowed_bases() as $base ) {
			if ( 0 === strpos( $path, $base ) && strlen( $path ) > strlen( $base ) ) {
				return $base;
			}
		}
		return false;
	}

	/**
	 * Whether an absolute path is inside a theme or plugin folder.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	private static function in_allowed_base( $path ) {
		return false !== self::allowed_base_for( $path );
	}

	/**
	 * Resolves symlinks in an absolute file path: the file itself when it
	 * exists, otherwise its deepest existing parent folder (so a symlinked
	 * folder cannot lead outside the theme and plugin folders). The caller
	 * still has to check the result with in_allowed_base().
	 *
	 * @param string $file Absolute, normalized path.
	 * @return string|WP_Error Canonical path.
	 */
	private static function canonical( $file ) {
		if ( file_exists( $file ) ) {
			$real = realpath( $file );
			if ( ! $real || ! is_file( $real ) ) {
				return new WP_Error( 'viagent_production_invalid_path', __( 'That path is not a file.', 'viagent-production' ) );
			}
			return wp_normalize_path( $real );
		}
		$existing = dirname( $file );
		while ( ! file_exists( $existing ) && dirname( $existing ) !== $existing ) {
			$existing = dirname( $existing );
		}
		$real_dir = realpath( $existing );
		if ( ! $real_dir || ! is_dir( $real_dir ) ) {
			return new WP_Error( 'viagent_production_invalid_path', __( 'Files can only be created inside a theme or plugin folder.', 'viagent-production' ) );
		}
		return wp_normalize_path( $real_dir ) . substr( $file, strlen( $existing ) );
	}

	/**
	 * Whether an absolute path belongs to Viagent Production itself.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	private static function is_own( $path ) {
		$own = realpath( VIAGENT_PRODUCTION_DIR );
		$own = trailingslashit( wp_normalize_path( $own ? $own : VIAGENT_PRODUCTION_DIR ) );
		return 0 === strpos( trailingslashit( wp_normalize_path( $path ) ), $own );
	}

	/**
	 * Resolves input to a safe absolute file path.
	 *
	 * @param array $input     Input with type, name and path.
	 * @param bool  $must_exist Whether the file must already exist.
	 * @return array{root:string,file:string,relative:string}|WP_Error
	 */
	private static function resolve( array $input, $must_exist ) {
		$root = self::root( $input['type'], $input['name'] );
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$relative = ltrim( wp_normalize_path( (string) $input['path'] ), '/' );
		$segments = explode( '/', $relative );
		if ( '' === $relative || false !== strpos( $relative, "\0" ) || in_array( '..', $segments, true ) || in_array( '.', $segments, true ) || in_array( '', $segments, true ) ) {
			return new WP_Error( 'viagent_production_invalid_path', __( 'Use a plain relative path inside the folder, without "..".', 'viagent-production' ) );
		}
		foreach ( $segments as $segment ) {
			if ( '.' === $segment[0] ) {
				return new WP_Error( 'viagent_production_invalid_path', __( 'Hidden files and folders (starting with ".") cannot be used.', 'viagent-production' ) );
			}
		}
		$extension = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, self::EXTENSIONS, true ) ) {
			/* translators: %s: allowed file extensions */
			return new WP_Error( 'viagent_production_invalid_type', sprintf( __( 'Only these file types can be used: %s.', 'viagent-production' ), implode( ', ', self::EXTENSIONS ) ) );
		}

		$file = $root . '/' . $relative;
		if ( $must_exist && ! file_exists( $file ) ) {
			return new WP_Error( 'viagent_production_not_found', __( 'That file does not exist. Use list_extension_files to see the files.', 'viagent-production' ) );
		}
		$file = self::canonical( $file );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		if ( ! self::in_allowed_base( $file ) ) {
			return new WP_Error( 'viagent_production_invalid_path', __( 'Files can only be used inside a theme or plugin folder.', 'viagent-production' ) );
		}

		return array(
			'root'     => $root,
			'file'     => $file,
			'relative' => $relative,
		);
	}

	/**
	 * Lists files.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function list_files( $input ) {
		$root = self::root( $input['type'], $input['name'] );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$real_root = realpath( $root );
		if ( ! $real_root || ! is_dir( $real_root ) ) {
			return new WP_Error( 'viagent_production_not_found', __( 'That theme or plugin folder does not exist.', 'viagent-production' ) );
		}
		$real_root = wp_normalize_path( $real_root );

		$sub   = trim( wp_normalize_path( (string) ( $input['path'] ?? '' ) ), '/' );
		$start = '' === $sub ? $real_root : realpath( $real_root . '/' . $sub );
		if ( ! $start || 0 !== strpos( wp_normalize_path( $start ) . '/', $real_root . '/' ) || ! is_dir( $start ) ) {
			return new WP_Error( 'viagent_production_not_found', __( 'That sub-folder does not exist.', 'viagent-production' ) );
		}

		$files    = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $start, \FilesystemIterator::SKIP_DOTS ),
				static function ( $item ) {
					$name = $item->getFilename();
					return '.' !== $name[0] && ! in_array( $name, array( 'node_modules', 'vendor' ), true );
				}
			)
		);
		foreach ( $iterator as $item ) {
			if ( ! $item->isFile() || ! in_array( strtolower( $item->getExtension() ), self::EXTENSIONS, true ) ) {
				continue;
			}
			if ( count( $files ) >= self::MAX_LIST ) {
				break;
			}
			$files[] = array(
				'path' => substr( wp_normalize_path( $item->getPathname() ), strlen( $real_root ) + 1 ),
				'size' => $item->getSize(),
			);
		}
		usort(
			$files,
			static function ( $a, $b ) {
				return strcmp( $a['path'], $b['path'] );
			}
		);

		return array(
			'folder'    => $real_root,
			'files'     => $files,
			'truncated' => count( $files ) >= self::MAX_LIST,
			'read_only' => self::is_own( $real_root ),
		);
	}

	/**
	 * Reads a file.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function read_file( $input ) {
		$target = self::resolve( $input, true );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		if ( filesize( $target['file'] ) > self::MAX_BYTES ) {
			return new WP_Error( 'viagent_production_too_large', __( 'That file is larger than 1 MB and cannot be read this way.', 'viagent-production' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file inside a theme or plugin folder.
		$content = (string) file_get_contents( $target['file'] );
		return array(
			'path'    => $target['relative'],
			'content' => $content,
		);
	}

	/**
	 * Checks PHP code for syntax errors without running it.
	 *
	 * @param string $code PHP source.
	 * @return true|WP_Error
	 */
	private static function check_php( $code ) {
		try {
			token_get_all( $code, TOKEN_PARSE );
		} catch ( \ParseError $e ) {
			return new WP_Error(
				'viagent_production_php_syntax',
				/* translators: 1: error message, 2: line number */
				sprintf( __( 'PHP syntax error, nothing was saved: %1$s on line %2$d.', 'viagent-production' ), $e->getMessage(), $e->getLine() )
			);
		}
		return true;
	}

	/**
	 * Writes a file through the WordPress filesystem.
	 *
	 * @param string $file    Absolute path.
	 * @param string $content Content.
	 * @return true|WP_Error
	 */
	private static function put( $file, $content ) {
		global $wp_filesystem;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			return new WP_Error( 'viagent_production_filesystem', __( 'WordPress cannot write files on this server without FTP details.', 'viagent-production' ) );
		}
		if ( ! wp_mkdir_p( dirname( $file ) ) || ! $wp_filesystem->put_contents( $file, $content, FS_CHMOD_FILE ) ) {
			return new WP_Error( 'viagent_production_write_failed', __( 'The file could not be saved. Check the folder permissions.', 'viagent-production' ) );
		}
		wp_opcache_invalidate( $file, true );
		return true;
	}

	/**
	 * Whether the site still loads after a PHP change. Unreachable sites
	 * (no loopback) count as healthy, since the check proves nothing there.
	 *
	 * @return true|string True, or a short description of the failure.
	 */
	private static function site_loads() {
		$response = wp_remote_get(
			add_query_arg( 'viagent-production-check', wp_generate_password( 8, false ), home_url( '/' ) ),
			array(
				'timeout'   => 15,
				'sslverify' => false,
				// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
				'headers'   => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return true;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( false !== stripos( $body, 'There has been a critical error' ) || preg_match( '/<b>(Fatal|Parse) error<\/b>/i', $body ) ) {
			return __( 'the homepage showed a PHP fatal error', 'viagent-production' );
		}
		if ( $code >= 500 ) {
			/* translators: %d: HTTP status code */
			return sprintf( __( 'the homepage returned HTTP %d', 'viagent-production' ), $code );
		}
		return true;
	}

	/**
	 * Writes a file.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function write_file( $input ) {
		if ( ! Policy::allows_code() ) {
			return Policy::code_disabled_error();
		}
		$target = self::resolve( $input, false );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		if ( self::is_own( $target['file'] ) ) {
			return new WP_Error( 'viagent_production_self', __( 'Viagent Production’s own files cannot be changed by an AI.', 'viagent-production' ) );
		}
		$content = (string) $input['content'];
		if ( strlen( $content ) > self::MAX_BYTES ) {
			return new WP_Error( 'viagent_production_too_large', __( 'The content is larger than 1 MB.', 'viagent-production' ) );
		}
		$is_php = 'php' === strtolower( pathinfo( $target['file'], PATHINFO_EXTENSION ) );
		if ( $is_php ) {
			$valid = self::check_php( $content );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}
		if ( 'json' === strtolower( pathinfo( $target['file'], PATHINFO_EXTENSION ) ) && null === json_decode( $content ) && 'null' !== trim( $content ) ) {
			return new WP_Error( 'viagent_production_invalid_json', __( 'The content is not valid JSON, nothing was saved.', 'viagent-production' ) );
		}

		$existed = file_exists( $target['file'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file inside a theme or plugin folder.
		$previous = $existed ? (string) file_get_contents( $target['file'] ) : null;

		$saved = self::put( $target['file'], $content );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		if ( $is_php ) {
			$health = self::site_loads();
			if ( true !== $health ) {
				$restored = $existed ? self::put( $target['file'], $previous ) : wp_delete_file( $target['file'] );
				return new WP_Error(
					'viagent_production_rolled_back',
					sprintf(
						/* translators: %s: failure description */
						__( 'The change broke the site (%s), so it was rolled back. Check the code and try again.', 'viagent-production' ),
						$health
					) . ( is_wp_error( $restored ) ? ' ' . $restored->get_error_message() : '' )
				);
			}
		}

		Activity_Log::set_object( 'file', 0 );
		if ( ! $existed || strlen( $previous ) <= self::MAX_UNDO_BYTES ) {
			Activity_Log::set_undo(
				array(
					'action'  => 'restore_file',
					'file'    => $target['file'],
					'content' => $previous,
				)
			);
		}

		return array(
			'path'    => $target['relative'],
			'created' => ! $existed,
			'bytes'   => strlen( $content ),
		);
	}

	/**
	 * Deletes a file.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function delete_file( $input ) {
		if ( ! Policy::allows_code() ) {
			return Policy::code_disabled_error();
		}
		$target = self::resolve( $input, true );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		if ( self::is_own( $target['file'] ) ) {
			return new WP_Error( 'viagent_production_self', __( 'Viagent Production’s own files cannot be changed by an AI.', 'viagent-production' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file inside a theme or plugin folder.
		$previous = (string) file_get_contents( $target['file'] );
		wp_delete_file( $target['file'] );
		if ( file_exists( $target['file'] ) ) {
			return new WP_Error( 'viagent_production_delete_failed', __( 'The file could not be deleted. Check the folder permissions.', 'viagent-production' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		wp_opcache_invalidate( $target['file'], true );

		Activity_Log::set_object( 'file', 0 );
		if ( strlen( $previous ) <= self::MAX_UNDO_BYTES ) {
			Activity_Log::set_undo(
				array(
					'action'  => 'restore_file',
					'file'    => $target['file'],
					'content' => $previous,
				)
			);
		}
		return array(
			'path'    => $target['relative'],
			'deleted' => true,
		);
	}

	/**
	 * Undoes a file change: writes the previous content back, or removes a
	 * file the AI created. Only touches files inside theme or plugin folders.
	 *
	 * @param array $undo Undo instructions.
	 * @return true|WP_Error
	 */
	public static function restore( array $undo ) {
		$file = wp_normalize_path( (string) ( $undo['file'] ?? '' ) );
		$base = '' === $file || false !== strpos( $file, "\0" ) ? false : self::allowed_base_for( $file );
		if ( false === $base || self::is_own( $file ) ) {
			return new WP_Error( 'viagent_production_invalid_path', __( 'This file is outside the theme and plugin folders.', 'viagent-production' ) );
		}
		// Same path rules as resolve(): no "..", no empty or hidden segments, allowed file types only.
		$segments = explode( '/', substr( $file, strlen( $base ) ) );
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment[0] ) {
				return new WP_Error( 'viagent_production_invalid_path', __( 'Hidden files and folders (starting with ".") cannot be used.', 'viagent-production' ) );
			}
		}
		$extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, self::EXTENSIONS, true ) ) {
			/* translators: %s: allowed file extensions */
			return new WP_Error( 'viagent_production_invalid_type', sprintf( __( 'Only these file types can be used: %s.', 'viagent-production' ), implode( ', ', self::EXTENSIONS ) ) );
		}
		if ( is_link( $file ) ) {
			return new WP_Error( 'viagent_production_invalid_path', __( 'Symbolic links cannot be restored.', 'viagent-production' ) );
		}
		// Resolve symlinks in the file or its deepest existing parent folder, then re-check the location.
		$file = self::canonical( $file );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( ! self::in_allowed_base( $file ) || self::is_own( $file ) ) {
			return new WP_Error( 'viagent_production_invalid_path', __( 'This file is outside the theme and plugin folders.', 'viagent-production' ) );
		}

		$plugin_dir = realpath( WP_PLUGIN_DIR );
		$is_plugin  = 0 === strpos( $file, trailingslashit( wp_normalize_path( $plugin_dir ? $plugin_dir : WP_PLUGIN_DIR ) ) );
		if ( ! current_user_can( $is_plugin ? 'edit_plugins' : 'edit_themes' ) ) {
			return new WP_Error( 'viagent_production_forbidden', __( 'You are not allowed to edit these files.', 'viagent-production' ) );
		}

		if ( null === $undo['content'] ) {
			wp_delete_file( $file );
			require_once ABSPATH . 'wp-admin/includes/file.php';
			wp_opcache_invalidate( $file, true );
			return file_exists( $file ) ? new WP_Error( 'viagent_production_revert_failed', __( 'The file could not be removed.', 'viagent-production' ) ) : true;
		}
		return self::put( $file, (string) $undo['content'] );
	}
}
