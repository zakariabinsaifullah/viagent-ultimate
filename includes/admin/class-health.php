<?php
/**
 * Connection health checks shown on the Viagent Production screen. Each check explains
 * the problem in plain language and how to fix it.
 *
 * Reachability checks (endpoint and Authorization header) run in the browser
 * instead of as loopback requests, which hang on single-threaded dev servers
 * and are blocked by some hosts.
 *
 * @package ViagentProduction
 */

namespace ViagentProduction\Admin;

use ViagentProduction\Security\Policy;

defined( 'ABSPATH' ) || exit;

/**
 * Health checks.
 */
class Health {

	/**
	 * Runs all checks.
	 *
	 * @return array
	 */
	public static function run() {
		return array(
			'is_local' => self::is_local(),
			'checks'   => array_values(
				array_filter(
					array(
						self::check_paused(),
						self::check_permalinks(),
						self::check_https(),
						self::check_public(),
						self::check_file_editing(),
					)
				)
			),
		);
	}

	/**
	 * Builds a check result.
	 *
	 * @param string $id      ID.
	 * @param string $status  "good" | "warning" | "critical" | "info".
	 * @param string $label   Short label.
	 * @param string $message What it means.
	 * @param string $fix     How to fix it (optional).
	 * @return array
	 */
	private static function result( $id, $status, $label, $message, $fix = '' ) {
		return array(
			'id'      => $id,
			'status'  => $status,
			'label'   => $label,
			'message' => $message,
			'fix'     => $fix,
		);
	}

	/**
	 * Whether the site runs on this computer (not reachable from the internet).
	 *
	 * @return bool
	 */
	public static function is_local() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		if ( preg_match( '/\.(local|localhost|test|example|invalid|lan|internal)$/', $host ) ) {
			return true;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return true;
		}
		return 'local' === wp_get_environment_type();
	}

	/**
	 * Kill switch.
	 *
	 * @return array|null
	 */
	private static function check_paused() {
		if ( ! Policy::is_paused() ) {
			return null;
		}
		return self::result(
			'paused',
			'warning',
			__( 'AI access is paused', 'viagent-production' ),
			__( 'All AI apps are blocked until you resume access.', 'viagent-production' ),
			__( 'Turn off "Pause all AI access" to reconnect.', 'viagent-production' )
		);
	}

	/**
	 * Pretty permalinks.
	 *
	 * @return array
	 */
	private static function check_permalinks() {
		if ( get_option( 'permalink_structure' ) ) {
			return self::result( 'permalinks', 'good', __( 'Permalinks', 'viagent-production' ), __( 'Pretty permalinks are on.', 'viagent-production' ) );
		}
		return self::result(
			'permalinks',
			'info',
			__( 'Permalinks', 'viagent-production' ),
			__( 'Plain permalinks are in use, so the connection URL contains "?rest_route=". It still works, but some apps prefer clean URLs.', 'viagent-production' ),
			__( 'Go to Settings → Permalinks and choose "Post name".', 'viagent-production' )
		);
	}

	/**
	 * HTTPS.
	 *
	 * @return array
	 */
	private static function check_https() {
		if ( wp_is_using_https() ) {
			return self::result( 'https', 'good', __( 'HTTPS', 'viagent-production' ), __( 'Your site uses a secure (HTTPS) address, so keys are encrypted in transit.', 'viagent-production' ) );
		}
		return self::result(
			'https',
			self::is_local() ? 'info' : 'critical',
			__( 'HTTPS', 'viagent-production' ),
			__( 'Your site does not use HTTPS, so connection keys travel unencrypted. Web apps like Claude.ai and ChatGPT require HTTPS.', 'viagent-production' ),
			__( 'Ask your host to enable a free SSL certificate, then switch to https:// in Settings → General.', 'viagent-production' )
		);
	}

	/**
	 * File editing turned off in wp-config.php, which also stops Full control
	 * apps from reading or editing theme and plugin code.
	 *
	 * @return array|null
	 */
	private static function check_file_editing() {
		if ( '' === Policy::file_editing_problem() ) {
			return null;
		}
		$mods = defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS;
		return self::result(
			'file_editing',
			'info',
			__( 'Theme and plugin code', 'viagent-production' ),
			$mods
				? __( 'Your wp-config.php sets DISALLOW_FILE_MODS, so AI apps (even with Full control) cannot read or edit theme and plugin files, or install and update plugins and themes. Many hosts and security plugins set this on purpose.', 'viagent-production' )
				: __( 'Your wp-config.php sets DISALLOW_FILE_EDIT, so AI apps (even with Full control) cannot read or edit theme and plugin files. Many hosts and security plugins set this on purpose.', 'viagent-production' ),
			$mods
				? __( 'If you want AI apps to work with code, remove the DISALLOW_FILE_MODS line from wp-config.php (or ask your host). Everything else keeps working without it.', 'viagent-production' )
				: __( 'If you want AI apps to work with code, remove the DISALLOW_FILE_EDIT line from wp-config.php, or set it to false (or ask your host / check your security plugin). Everything else keeps working without it.', 'viagent-production' )
		);
	}

	/**
	 * Local sites can't be reached by cloud-based apps.
	 *
	 * @return array|null
	 */
	private static function check_public() {
		if ( ! self::is_local() ) {
			return null;
		}
		return self::result(
			'local',
			'info',
			__( 'Local site', 'viagent-production' ),
			__( 'This site runs on your computer. Apps on the same computer (Claude Code, Claude Desktop, Cursor, VS Code, Codex…) can connect. Web apps like Claude.ai and ChatGPT cannot reach it.', 'viagent-production' ),
			__( 'To use web apps, publish the site or share it through a tunnel or a WordPress Studio preview site.', 'viagent-production' )
		);
	}
}
