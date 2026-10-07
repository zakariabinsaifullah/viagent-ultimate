<?php
/**
 * Diagnostics abilities: a read-only site health overview.
 *
 * @package ViagentProduction
 */

namespace ViagentProduction\Abilities;

use ViagentProduction\Security\Policy;

defined( 'ABSPATH' ) || exit;

/**
 * Diagnostics abilities.
 */
class Diagnostics {

	/**
	 * Registers abilities.
	 */
	public static function register() {
		Abilities::add(
			'get-site-health',
			array(
				'category'    => 'viagent-production-diagnostics',
				'label'       => __( 'Get site health', 'viagent-production' ),
				'description' => __( 'Technical overview for troubleshooting: WordPress/PHP versions, pending updates, debug settings, memory, HTTPS and cron.', 'viagent-production' ),
				'execute'     => array( self::class, 'get_site_health' ),
				'permission'  => 'manage_options',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);
	}

	/**
	 * Site health overview.
	 *
	 * @return array
	 */
	public static function get_site_health() {
		$core    = get_site_transient( 'update_core' );
		$plugins = get_site_transient( 'update_plugins' );
		$themes  = get_site_transient( 'update_themes' );

		$core_update = null;
		if ( isset( $core->updates[0] ) && 'upgrade' === $core->updates[0]->response ) {
			$core_update = $core->updates[0]->current;
		}

		return array(
			'wordpress'         => get_bloginfo( 'version' ),
			'php'               => PHP_VERSION,
			'database'          => defined( 'DB_ENGINE' ) ? DB_ENGINE : 'mysql',
			'https'             => wp_is_using_https(),
			'permalinks'        => get_option( 'permalink_structure' ) ? 'pretty' : 'plain',
			'memory_limit'      => WP_MEMORY_LIMIT,
			'object_cache'      => wp_using_ext_object_cache(),
			'debug'             => array(
				'WP_DEBUG'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'WP_DEBUG_LOG'     => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
				'WP_DEBUG_DISPLAY' => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
			),
			'cron_disabled'     => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'active_plugins'    => count( (array) get_option( 'active_plugins', array() ) ),
			'updates_available' => array(
				'core'    => $core_update,
				'plugins' => isset( $plugins->response ) ? count( $plugins->response ) : 0,
				'themes'  => isset( $themes->response ) ? count( $themes->response ) : 0,
			),
		);
	}
}
