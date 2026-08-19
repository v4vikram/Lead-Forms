<?php
/**
 * The `[lead_form]` shortcode.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Classic-editor and page-builder friendly embed.
 */
final class Shortcode {

	public const TAG = 'lead_form';

	private FormRenderer $renderer;

	public function __construct( FormRenderer $renderer ) {
		$this->renderer = $renderer;
	}

	public function register_hooks(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts = array() ): string {
		$atts = shortcode_atts(
			array( 'id' => 0 ),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);

		$form_id = absint( $atts['id'] );

		if ( $form_id <= 0 ) {
			return '';
		}

		return $this->renderer->render( $form_id );
	}
}
