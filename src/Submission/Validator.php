<?php
/**
 * Sanitises and validates submitted values.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms\Submission;

use LeadForms\Forms\Field;
use LeadForms\Forms\FieldRegistry;
use LeadForms\Forms\Form;

defined( 'ABSPATH' ) || exit;

/**
 * Turns raw request input into a clean, typed payload.
 *
 * Nothing downstream ever touches `$_POST` — it only sees what comes out of
 * here, which is sanitised, whitelisted against the form definition, and
 * accompanied by per-field error messages.
 */
final class Validator {

	/**
	 * Validate a submission against its form.
	 *
	 * @param Form                 $form Form definition.
	 * @param array<string, mixed> $raw  Raw `lf_field` input.
	 * @return array{values: array<string, array{label: string, value: mixed, type: string}>, errors: array<string, string>}
	 */
	public function validate( Form $form, array $raw ): array {
		$values = array();
		$errors = array();

		foreach ( $form->fields() as $field ) {
			$submitted = $raw[ $field->id() ] ?? null;
			$value     = $this->sanitize( $field, $submitted );

			// Whether the visitor actually typed something. Sanitising can
			// legitimately empty a value (sanitize_email() on "nope" returns
			// ''), and that must read as "invalid", not as "left blank".
			$had_input = is_array( $submitted )
				? array() !== array_filter( $submitted, static function ( $item ): bool {
					return is_scalar( $item ) && '' !== trim( (string) $item );
				} )
				: ( is_scalar( $submitted ) && '' !== trim( (string) $submitted ) );

			$error = $this->check( $field, $value, $had_input );

			if ( '' !== $error ) {
				$errors[ $field->id() ] = $error;
			}

			$values[ $field->id() ] = array(
				'label' => $field->label(),
				'value' => $value,
				'type'  => $field->type(),
			);
		}

		/**
		 * Filter the validation outcome, e.g. to add a custom rule.
		 *
		 * @param array<string, string> $errors Errors keyed by field id.
		 * @param array<string, array>  $values Sanitised values.
		 * @param Form                  $form   The form being submitted.
		 */
		$errors = (array) apply_filters( 'lead_forms_validation_errors', $errors, $values, $form );

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * Type-aware sanitisation.
	 *
	 * @param Field $field     Field definition.
	 * @param mixed $submitted Raw value.
	 * @return mixed Scalar string, or an array of strings for multi-value fields.
	 */
	private function sanitize( Field $field, $submitted ) {
		if ( $field->accepts_multiple() ) {
			$items = is_array( $submitted ) ? $submitted : array();
			$clean = array();

			foreach ( $items as $item ) {
				if ( ! is_scalar( $item ) ) {
					continue;
				}

				$item = sanitize_text_field( (string) $item );

				// Only values that exist in the definition are accepted, so a
				// tampered request cannot inject arbitrary choices.
				if ( '' !== $item && in_array( $item, $field->options(), true ) ) {
					$clean[] = $item;
				}
			}

			return array_values( array_unique( $clean ) );
		}

		if ( is_array( $submitted ) || null === $submitted ) {
			$submitted = '';
		}

		$value = trim( (string) $submitted );

		switch ( $field->type() ) {
			case 'email':
				return sanitize_email( $value );

			case 'url':
				return esc_url_raw( $value );

			case 'tel':
				// Keep the characters people actually type in phone numbers.
				return trim( (string) preg_replace( '/[^0-9+()\-\s]/', '', $value ) );

			case 'number':
				return is_numeric( $value ) ? $value : '';

			case 'date':
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';

			case 'textarea':
				return sanitize_textarea_field( $value );

			case 'acceptance':
				return '' !== $value ? '1' : '';

			case 'select':
			case 'radio':
				$value = sanitize_text_field( $value );

				return in_array( $value, $field->options(), true ) ? $value : '';

			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Apply the rules for one field and return an error message, or ''.
	 *
	 * @param Field $field     Field definition.
	 * @param mixed $value     Sanitised value.
	 * @param bool  $had_input Whether the visitor submitted anything at all.
	 */
	private function check( Field $field, $value, bool $had_input = false ): string {
		$empty = is_array( $value ) ? empty( $value ) : ( '' === (string) $value );

		if ( $empty ) {
			// Something was typed but nothing survived sanitisation, so the
			// input was malformed rather than omitted. Say so instead of
			// discarding it quietly — this matters most on optional fields,
			// where a typo would otherwise vanish without a word.
			if ( $had_input ) {
				return $this->invalid_message( $field );
			}

			if ( ! $field->is_required() ) {
				return '';
			}

			if ( 'acceptance' === $field->type() ) {
				return __( 'Please tick this box to continue.', 'lead-forms' );
			}

			/* translators: %s: field label. */
			return sprintf( __( '%s is required.', 'lead-forms' ), $field->label() );
		}

		switch ( $field->type() ) {
			case 'email':
				if ( ! is_email( (string) $value ) ) {
					return __( 'Please enter a valid e-mail address.', 'lead-forms' );
				}
				break;

			case 'tel':
				$digits = preg_replace( '/\D/', '', (string) $value );

				if ( strlen( (string) $digits ) < 7 || strlen( (string) $digits ) > 15 ) {
					return __( 'Please enter a valid phone number.', 'lead-forms' );
				}
				break;

			case 'url':
				if ( ! wp_http_validate_url( (string) $value ) ) {
					return __( 'Please enter a valid URL.', 'lead-forms' );
				}
				break;

			case 'number':
				if ( ! is_numeric( $value ) ) {
					return __( 'Please enter a number.', 'lead-forms' );
				}
				break;

			case 'select':
			case 'radio':
				// Sanitisation blanks unknown choices, so a non-empty value here
				// is already known-good; an empty one was handled above.
				break;

			case 'textarea':
			case 'text':
				if ( mb_strlen( (string) $value ) > 5000 ) {
					return __( 'This value is too long.', 'lead-forms' );
				}
				break;
		}

		// The author's own rules run last, so a custom message overrides the
		// built-in one rather than being pre-empted by it.
		$rule_error = $this->check_rules( $field, $value );

		if ( '' !== $rule_error ) {
			return $rule_error;
		}

		return '';
	}

	/**
	 * Apply the per-field rules configured in the builder.
	 *
	 * @param Field $field Field definition.
	 * @param mixed $value Sanitised, non-empty value.
	 */
	private function check_rules( Field $field, $value ): string {
		$custom = $field->error_message();
		$min    = $field->min();
		$max    = $field->max();

		// --- Choice counts (checkbox groups). ---
		if ( FieldRegistry::has_rule( $field->type(), 'count' ) ) {
			$count = is_array( $value ) ? count( $value ) : 0;

			if ( '' !== $min && $count < (int) $min ) {
				/* translators: %s: minimum number of choices. */
				return '' !== $custom ? $custom : sprintf( _n( 'Please choose at least %s option.', 'Please choose at least %s options.', (int) $min, 'lead-forms' ), number_format_i18n( (int) $min ) );
			}

			if ( '' !== $max && $count > (int) $max ) {
				/* translators: %s: maximum number of choices. */
				return '' !== $custom ? $custom : sprintf( _n( 'Please choose no more than %s option.', 'Please choose no more than %s options.', (int) $max, 'lead-forms' ), number_format_i18n( (int) $max ) );
			}

			return '';
		}

		$text = is_array( $value ) ? implode( ', ', $value ) : (string) $value;

		// --- Numeric and date bounds. ---
		if ( FieldRegistry::has_rule( $field->type(), 'range' ) ) {
			$is_date = 'date' === $field->type();
			$actual  = $is_date ? strtotime( $text ) : (float) $text;

			if ( '' !== $min ) {
				$bound = $is_date ? strtotime( $min ) : (float) $min;

				if ( false !== $actual && false !== $bound && $actual < $bound ) {
					/* translators: %s: minimum allowed value. */
					return '' !== $custom ? $custom : sprintf( __( 'Please enter a value no lower than %s.', 'lead-forms' ), $min );
				}
			}

			if ( '' !== $max ) {
				$bound = $is_date ? strtotime( $max ) : (float) $max;

				if ( false !== $actual && false !== $bound && $actual > $bound ) {
					/* translators: %s: maximum allowed value. */
					return '' !== $custom ? $custom : sprintf( __( 'Please enter a value no higher than %s.', 'lead-forms' ), $max );
				}
			}
		}

		// --- Character counts. ---
		if ( FieldRegistry::has_rule( $field->type(), 'length' ) ) {
			$length = mb_strlen( $text );

			if ( '' !== $min && $length < (int) $min ) {
				/* translators: %s: minimum number of characters. */
				return '' !== $custom ? $custom : sprintf( _n( 'Please enter at least %s character.', 'Please enter at least %s characters.', (int) $min, 'lead-forms' ), number_format_i18n( (int) $min ) );
			}

			if ( '' !== $max && $length > (int) $max ) {
				/* translators: %s: maximum number of characters. */
				return '' !== $custom ? $custom : sprintf( _n( 'Please use no more than %s character.', 'Please use no more than %s characters.', (int) $max, 'lead-forms' ), number_format_i18n( (int) $max ) );
			}
		}

		// --- Format. ---
		$rule = $field->pattern_rule();

		if ( null !== $rule ) {
			$subject = 'digits' === $rule['target'] ? (string) preg_replace( '/\D/', '', $text ) : $text;

			// A pattern that fails to run must not silently pass the value.
			if ( 1 !== @preg_match( $rule['regex'], $subject ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return '' !== $custom ? $custom : $rule['message'];
			}
		}

		return '';
	}

	/**
	 * The "you typed something, but it is not usable" message for a type.
	 */
	private function invalid_message( Field $field ): string {
		switch ( $field->type() ) {
			case 'email':
				return __( 'Please enter a valid e-mail address.', 'lead-forms' );

			case 'tel':
				return __( 'Please enter a valid phone number.', 'lead-forms' );

			case 'url':
				return __( 'Please enter a valid URL.', 'lead-forms' );

			case 'number':
				return __( 'Please enter a number.', 'lead-forms' );

			case 'date':
				return __( 'Please enter a valid date.', 'lead-forms' );

			case 'select':
			case 'radio':
			case 'checkbox':
				return __( 'Please choose one of the available options.', 'lead-forms' );

			default:
				/* translators: %s: field label. */
				return sprintf( __( 'Please check the value entered for %s.', 'lead-forms' ), $field->label() );
		}
	}
}
