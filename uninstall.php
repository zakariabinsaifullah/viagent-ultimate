<?php
/**
 * Removes Viagent Production data when the plugin is deleted — only if the site owner
 * turned on "Delete all Viagent Production data" in Viagent Production → Settings.
 *
 * @package ViagentProduction
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! get_option( 'viagent_production_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- removes the plugin's own data on uninstall.

/**
 * Removes Viagent Production tables, options and transients for the current site.
 */
function viagent_production_uninstall_site() {
	global $wpdb;

	foreach ( array( 'keys', 'activity', 'oauth_clients', 'oauth_tokens', 'seo_redirects', 'seo_404' ) as $viagent_production_table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'viagent_production_' . $viagent_production_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own tables on uninstall.
	}

	$viagent_production_options = array(
		'viagent_production_db_version',
		'viagent_production_paused',
		'viagent_production_allow_permanent_delete',
		'viagent_production_allow_code',
		'viagent_production_tool_overrides',
		'viagent_production_prompt_overrides',
		'viagent_production_compact_mode',
		'viagent_production_activation_redirect',
		'viagent_production_delete_data_on_uninstall',
		'viagent_production_seo_settings',
		'viagent_production_seo_robots_txt',
		'viagent_production_seo_indexnow_key',
	);
	foreach ( $viagent_production_options as $viagent_production_option ) {
		delete_option( $viagent_production_option );
	}

	// Sessions, rate-limit counters and authorization codes.
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( '_transient_viagent_production_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_viagent_production_' ) . '%'
		)
	);

	wp_clear_scheduled_hook( 'viagent_production_prune_activity' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $viagent_production_site_id ) {
		switch_to_blog( $viagent_production_site_id );
		viagent_production_uninstall_site();
		restore_current_blog();
	}
} else {
	viagent_production_uninstall_site();
}
