<?php
/**
 * Front-end asset registration.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms\Frontend;

use LeadForms\Plugin;
use LeadForms\Submission\RestController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the CSS/JS up front but only enqueues it on pages that actually
 * render a form, so the rest of the site ships zero extra bytes.
 */
final class Assets {

	public const STYLE_HANDLE  = 'lead-forms';
	public const SCRIPT_HANDLE = 'lead-forms';

	private Plugin $plugin;
	private bool $enqueued = false;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'register' ) );
	}

	/**
	 * Register (not enqueue) the handles.
	 */
	public function register(): void {
		$version = $this->plugin->version();

		wp_register_style(
			self::STYLE_HANDLE,
			$this->plugin->url( 'assets/css/form.css' ),
			array(),
			$version
		);

		wp_register_script(
			self::SCRIPT_HANDLE,
			$this->plugin->url( 'assets/js/form.js' ),
			array(),
			$version,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		wp_set_script_translations( self::SCRIPT_HANDLE, 'lead-forms', $this->plugin->path( 'languages' ) );
	}

	/**
	 * Enqueue on demand, once per request.
	 */
	public function enqueue(): void {
		if ( $this->enqueued ) {
			return;
		}

		$this->enqueued = true;

		if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			$this->register();
		}

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.leadFormsConfig = ' . wp_json_encode(
				array(
					'submitUrl' => rest_url( RestController::NAMESPACE . '/submit' ),
					'tokenUrl'  => rest_url( RestController::NAMESPACE . '/token' ),
					'i18n'      => array(
						'sending'      => __( 'Sending…', 'lead-forms' ),
						'networkError' => __( 'We could not reach the server. Please check your connection and try again.', 'lead-forms' ),
						'genericError' => __( 'Something went wrong. Please try again.', 'lead-forms' ),
					),
				)
			) . ';',
			'before'
		);
	}
}
