<?php
/**
 * Block editor integration.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms\Frontend;

use LeadForms\Forms\FormRepository;
use LeadForms\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers a dynamic block from `block.json`.
 *
 * The block is server-rendered, so the saved post content stores only the
 * chosen form ID — editing a form never invalidates existing posts.
 */
final class BlockRegistrar {

	private Plugin $plugin;
	private FormRenderer $renderer;
	private FormRepository $forms;

	public function __construct( Plugin $plugin, FormRenderer $renderer, FormRepository $forms ) {
		$this->plugin   = $plugin;
		$this->renderer = $renderer;
		$this->forms    = $forms;
	}

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_data' ) );
	}

	/**
	 * Register the block type.
	 */
	public function register(): void {
		$block_dir = $this->plugin->path( 'blocks/lead-form' );

		if ( ! is_readable( $block_dir . '/block.json' ) ) {
			return;
		}

		register_block_type(
			$block_dir,
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * Server-side render callback.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( array $attributes = array() ): string {
		$form_id = absint( $attributes['formId'] ?? 0 );

		if ( $form_id <= 0 ) {
			return '';
		}

		$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes() : '';

		return sprintf(
			'<div %s>%s</div>',
			$wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by core.
			$this->renderer->render( $form_id )
		);
	}

	/**
	 * Give the editor script the list of available forms.
	 */
	public function editor_data(): void {
		$handle = 'lead-forms-lead-form-editor-script';

		if ( ! wp_script_is( $handle, 'registered' ) ) {
			return;
		}

		$options = array();

		foreach ( $this->forms->list_ids() as $id => $title ) {
			$options[] = array(
				'value' => $id,
				'label' => '' !== $title ? $title : sprintf( '#%d', $id ),
			);
		}

		wp_add_inline_script(
			$handle,
			'window.leadFormsBlockData = ' . wp_json_encode(
				array(
					'forms'   => $options,
					'newUrl'  => admin_url( 'post-new.php?post_type=lead_form' ),
				)
			) . ';',
			'before'
		);
	}
}
