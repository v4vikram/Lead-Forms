<?php
/**
 * Plugin Name:       Lead Forms
 * Plugin URI:        https://codevani.com/plugins/lead-forms
 * Description:       Dynamic contact / enquiry forms with a field builder, per-field validation, lead storage and multi-recipient e-mail notifications.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Vikram
 * Author URI:        https://codevani.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lead-forms
 * Domain Path:       /languages
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms;

// Never allow direct file access.
defined( 'ABSPATH' ) || exit;

const VERSION     = '1.0.0';
const PLUGIN_FILE = __FILE__;

/**
 * Minimal PSR-4 autoloader.
 *
 * Keeping our own loader means the plugin ships without a vendor/ directory
 * while still following the one-class-per-file / namespace-maps-to-folder rule.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		$length = strlen( $prefix );

		if ( 0 !== strncmp( $prefix, $class_name, $length ) ) {
			return;
		}

		$relative = substr( $class_name, $length );
		$file     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Bail out early on unsupported environments instead of fataling later.
 */
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Lead Forms requires PHP 7.4 or newer. The plugin is inactive.', 'lead-forms' )
			);
		}
	);

	return;
}

register_activation_hook( __FILE__, array( Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Installer::class, 'deactivate' ) );

/**
 * Resolve the plugin container.
 *
 * @return Plugin
 */
function plugin(): Plugin {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new Plugin( __FILE__, VERSION );
	}

	return $instance;
}

// `plugins_loaded` is the earliest safe point: pluggable functions and the
// translation layer are ready, and other plugins can still unhook us.
add_action( 'plugins_loaded', static fn() => plugin()->boot() );
