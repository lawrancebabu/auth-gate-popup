<?php
/**
 * Uninstall handler for Auth Gate Popup.
 *
 * Removes the plugin's own settings and migration marker, including the
 * legacy (pre 1.1.0) settings option. Site-wide settings changed on
 * activation (users_can_register, default_role) and per-user terms consent
 * records (agp_terms_agreed user meta) are intentionally left untouched.
 *
 * @package AuthGatePopup
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'agp_options' );
delete_option( 'agp_migration_version' );
delete_option( 'alphalabs_auth_gate_options' );
