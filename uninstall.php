<?php
/**
 * Uninstall WP Duplicate Content Finder.
 *
 * Removes the settings, the last scan results, the dismissed-group list and
 * any half-finished scan state. Trashed posts are left in the Trash — the
 * uninstall never deletes content.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wdcf_settings' );
delete_option( 'wdcf_results' );
delete_option( 'wdcf_scan_state' );
delete_option( 'wdcf_dismissed' );
