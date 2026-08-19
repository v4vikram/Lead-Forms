<?php
/**
 * Uninstall routine.
 *
 * Runs only when the plugin is deleted from the Plugins screen — never on
 * deactivation. It removes every trace of the plugin: forms, leads, options
 * and transients.
 *
 * To keep the data (for example while migrating hosts), add this to
 * wp-config.php before deleting the plugin:
 *
 *     define( 'LEAD_FORMS_KEEP_DATA', true );
 *
 * @package LeadForms
 */

declare( strict_types=1 );

// Called by WordPress only; this constant is the proof.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'LEAD_FORMS_KEEP_DATA' ) && LEAD_FORMS_KEEP_DATA ) {
	return;
}

/**
 * Remove all plugin data for the current site.
 */
function lead_forms_uninstall_site(): void {
	global $wpdb;

	// 1. Forms (custom post type) and their meta.
	$form_ids = get_posts(
		array(
			'post_type'      => 'lead_form',
			'post_status'    => 'any',
			'numberposts'    => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	foreach ( (array) $form_ids as $form_id ) {
		wp_delete_post( (int) $form_id, true );
	}

	// 2. The leads table.
	$table = $wpdb->prefix . 'lead_forms_leads';
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange

	// 3. Options.
	foreach ( array( 'lead_forms_db_version', 'lead_forms_seeded', 'lead_forms_flush_rewrites' ) as $option ) {
		delete_option( $option );
	}

	// 4. Per-user screen options.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( 'lead_forms_per_page' ) . '%'
		)
	);

	// 5. Rate-limit and flash transients.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_lf_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_lf_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		lead_forms_uninstall_site();
		restore_current_blog();
	}
} else {
	lead_forms_uninstall_site();
}
