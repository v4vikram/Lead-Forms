<?php
/**
 * Catalogue of the field types the builder can offer.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for field types.
 *
 * Both the admin builder and the front-end renderer read from here, so adding
 * a type is a one-place change.
 */
final class FieldRegistry {

	/**
	 * All supported types keyed by slug.
	 *
	 * - `label`        Human readable name shown in the builder.
	 * - `has_options`  Whether the type needs a choice list.
	 * - `multiple`     Whether a submitted value may be an array.
	 * - `input_type`   The HTML input type, when the type renders an <input>.
	 * - `autocomplete` A sensible autocomplete token for browsers.
	 * - `rules`        Which validation controls the builder offers:
	 *                  `length` (characters), `range` (numeric/date bounds),
	 *                  `count` (number of choices) and `pattern` (format).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		static $types = null;

		if ( null === $types ) {
			$types = array(
				'text'       => array(
					'label'        => __( 'Text', 'lead-forms' ),
					'has_options'  => false,
					'multiple'     => false,
					'input_type'   => 'text',
					'autocomplete' => 'name',
					'rules'        => array( 'length', 'pattern' ),
				),
				'email'      => array(
					'label'        => __( 'Email', 'lead-forms' ),
					'has_options'  => false,
					'multiple'     => false,
					'input_type'   => 'email',
					'autocomplete' => 'email',
					'rules'        => array( 'length' ),
				),
				'tel'        => array(
					'label'        => __( 'Phone', 'lead-forms' ),
					'has_options'  => false,
					'multiple'     => false,
					'input_type'   => 'tel',
					'autocomplete' => 'tel',
					'rules'        => array( 'length', 'pattern' ),
				),
				'url'        => array(
					'label'        => __( 'URL', 'lead-forms' ),
					'has_options'  => false,
					'multiple'     => false,
					'input_type'   => 'url',
					'autocomplete' => 'url',
					'rules'        => array( 'length' ),
				),
				'number'     => array(
					'label'        => __( 'Number', 'lead-forms' ),
					'has_options'  => false,
					'multiple'     => false,
					'input_type'   => 'number',
					'autocomplete' => 'off',
					'rules'        => array( 'range' ),
				),
				'date'       => array(
					'label'        => __( 'Date', 'lead-forms' ),
					'has_options'  => false,
					'multiple'     => false,
					'input_type'   => 'date',
					'autocomplete' => 'off',
					'rules'        => array( 'range' ),
				),
				'textarea'   => array(
					'label'        => __( 'Message (multi-line)', 'lead-forms' ),
					'has_options'  => false,
					'multiple'     => false,
					'input_type'   => '',
					'autocomplete' => 'off',
					'rules'        => array( 'length' ),
				),
				'select'     => array(
					'label'        => __( 'Dropdown', 'lead-forms' ),
					'has_options'  => true,
					'multiple'     => false,
					'input_type'   => '',
					'autocomplete' => 'off',
					'rules'        => array(),
				),
				'radio'      => array(
					'label'        => __( 'Radio buttons', 'lead-forms' ),
					'has_options'  => true,
					'multiple'     => false,
					'input_type'   => 'radio',
					'autocomplete' => 'off',
					'rules'        => array(),
				),
				'checkbox'   => array(
					'label'        => __( 'Checkboxes (multiple)', 'lead-forms' ),
					'has_options'  => true,
					'multiple'     => true,
					'input_type'   => 'checkbox',
					'autocomplete' => 'off',
					'rules'        => array( 'count' ),
				),
				'acceptance' => array(
					'label'        => __( 'Consent checkbox', 'lead-forms' ),
					'has_options'  => false,
					'multiple'     => false,
					'input_type'   => 'checkbox',
					'autocomplete' => 'off',
					'rules'        => array(),
				),
			);

			/**
			 * Filter the registered field types.
			 *
			 * @param array<string, array<string, mixed>> $types Field type definitions.
			 */
			$types = (array) apply_filters( 'lead_forms_field_types', $types );
		}

		return $types;
	}

	/**
	 * Whether a type slug is known.
	 */
	public static function exists( string $type ): bool {
		return isset( self::all()[ $type ] );
	}

	/**
	 * Read one property of a type, with a fallback.
	 *
	 * @param string $type     Type slug.
	 * @param string $key      Property name.
	 * @param mixed  $fallback Returned when the type or key is unknown.
	 * @return mixed
	 */
	public static function get( string $type, string $key, $fallback = null ) {
		return self::all()[ $type ][ $key ] ?? $fallback;
	}

	/**
	 * Ready-made format rules offered in the builder.
	 *
	 * - `regex`        PCRE body, without delimiters. Applied server-side.
	 * - `html`         Equivalent for the HTML `pattern` attribute, giving the
	 *                  browser a chance to catch the problem first. Empty when
	 *                  the rule cannot be expressed the same way client-side.
	 * - `target`       `value` matches what was typed; `digits` matches the
	 *                  digits only, so "98765 43210" passes a 10-digit rule.
	 * - `message`      Error shown when the value does not match.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function patterns(): array {
		static $patterns = null;

		if ( null === $patterns ) {
			$patterns = array(
				'letters'      => array(
					'label'   => __( 'Letters only', 'lead-forms' ),
					'regex'   => '^[\p{L}\s.\'-]+$',
					'html'    => '[\p{L}\s.\x27-]+',
					'target'  => 'value',
					'message' => __( 'Please use letters only.', 'lead-forms' ),
				),
				'alphanumeric' => array(
					'label'   => __( 'Letters and numbers only', 'lead-forms' ),
					'regex'   => '^[\p{L}\p{N}\s.\'-]+$',
					'html'    => '[\p{L}\p{N}\s.\x27-]+',
					'target'  => 'value',
					'message' => __( 'Please use letters and numbers only.', 'lead-forms' ),
				),
				'digits'       => array(
					'label'   => __( 'Digits only', 'lead-forms' ),
					'regex'   => '^\d+$',
					'html'    => '',
					'target'  => 'digits',
					'message' => __( 'Please enter digits only.', 'lead-forms' ),
				),
				'mobile_in'    => array(
					'label'   => __( 'Indian mobile (10 digits, starts 6-9)', 'lead-forms' ),
					'regex'   => '^[6-9]\d{9}$',
					'html'    => '[\s()+-]*[6-9](?:[\s()-]*[0-9]){9}[\s()-]*',
					'target'  => 'digits',
					'message' => __( 'Please enter a 10-digit mobile number starting with 6, 7, 8 or 9.', 'lead-forms' ),
				),
				'custom'       => array(
					'label'   => __( 'Custom pattern…', 'lead-forms' ),
					'regex'   => '',
					'html'    => '',
					'target'  => 'value',
					'message' => __( 'Please match the requested format.', 'lead-forms' ),
				),
			);

			/**
			 * Filter the format presets offered in the field builder.
			 *
			 * @param array<string, array<string, string>> $patterns Preset definitions.
			 */
			$patterns = (array) apply_filters( 'lead_forms_field_patterns', $patterns );
		}

		return $patterns;
	}

	/**
	 * Whether a type offers a given validation control.
	 */
	public static function has_rule( string $type, string $rule ): bool {
		return in_array( $rule, (array) self::get( $type, 'rules', array() ), true );
	}

	/**
	 * Types offering each validation control, for the builder's JavaScript.
	 *
	 * @return array<string, string[]>
	 */
	public static function types_by_rule(): array {
		$map = array(
			'length'  => array(),
			'range'   => array(),
			'count'   => array(),
			'pattern' => array(),
		);

		foreach ( self::all() as $slug => $definition ) {
			foreach ( (array) ( $definition['rules'] ?? array() ) as $rule ) {
				if ( isset( $map[ $rule ] ) ) {
					$map[ $rule ][] = $slug;
				}
			}
		}

		return $map;
	}

	/**
	 * Types that require a choice list.
	 *
	 * @return string[]
	 */
	public static function types_with_options(): array {
		return array_keys(
			array_filter( self::all(), static function ( array $type ): bool {
				return ! empty( $type['has_options'] );
			} )
		);
	}
}
