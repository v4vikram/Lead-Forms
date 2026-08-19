<?php
/**
 * Immutable field definition.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * One field of a form, already normalised and safe to read.
 *
 * Instances are only ever created through `from_array()`, which is also the
 * single place where untrusted builder input gets sanitised.
 */
final class Field {

	private string $id;
	private string $type;
	private string $label;
	private string $placeholder;
	private string $help;
	private bool $required;
	private string $width;

	/** @var string[] */
	private array $options;

	/** Lower bound: characters, numeric value, date or choice count by type. '' means unset. */
	private string $min;

	/** Upper bound, same units as `$min`. '' means unset. */
	private string $max;

	/** Format preset key from FieldRegistry::patterns(), or '' for none. */
	private string $pattern;

	/** PCRE body used when `$pattern` is 'custom'. */
	private string $pattern_custom;

	/** Overrides the built-in message when the field fails validation. */
	private string $error_message;

	/**
	 * @param string[] $options Choice list for select/radio/checkbox types.
	 */
	private function __construct(
		string $id,
		string $type,
		string $label,
		string $placeholder,
		string $help,
		bool $required,
		string $width,
		array $options,
		string $min = '',
		string $max = '',
		string $pattern = '',
		string $pattern_custom = '',
		string $error_message = ''
	) {
		$this->id             = $id;
		$this->type           = $type;
		$this->label          = $label;
		$this->placeholder    = $placeholder;
		$this->help           = $help;
		$this->required       = $required;
		$this->width          = $width;
		$this->options        = $options;
		$this->min            = $min;
		$this->max            = $max;
		$this->pattern        = $pattern;
		$this->pattern_custom = $pattern_custom;
		$this->error_message  = $error_message;
	}

	/**
	 * Build a field from a raw (possibly user supplied) array.
	 *
	 * @param array<string, mixed> $raw      Raw field data.
	 * @param int                  $position Index used to generate a key when none is given.
	 * @return self|null Null when the data cannot make a usable field.
	 */
	public static function from_array( array $raw, int $position = 0 ): ?self {
		$label = isset( $raw['label'] ) ? sanitize_text_field( (string) $raw['label'] ) : '';
		$type  = isset( $raw['type'] ) ? sanitize_key( (string) $raw['type'] ) : 'text';

		if ( ! FieldRegistry::exists( $type ) ) {
			$type = 'text';
		}

		// A field without a label has nothing to render or store against.
		if ( '' === $label ) {
			return null;
		}

		$id = isset( $raw['id'] ) ? self::to_key( (string) $raw['id'] ) : '';

		if ( '' === $id ) {
			$id = self::to_key( $label );
		}

		if ( '' === $id ) {
			$id = 'field_' . ( $position + 1 );
		}

		$options = array();

		if ( FieldRegistry::get( $type, 'has_options', false ) ) {
			$options = self::sanitize_options( $raw['options'] ?? array() );
		}

		$width = ( isset( $raw['width'] ) && 'half' === $raw['width'] ) ? 'half' : 'full';

		// Bounds only make sense for types that offer them; storing a stale
		// value from a type change would silently enforce an invisible rule.
		$has_bounds = FieldRegistry::has_rule( $type, 'length' )
			|| FieldRegistry::has_rule( $type, 'range' )
			|| FieldRegistry::has_rule( $type, 'count' );

		$min = $has_bounds ? self::sanitize_bound( $raw['min'] ?? '', $type ) : '';
		$max = $has_bounds ? self::sanitize_bound( $raw['max'] ?? '', $type ) : '';

		$pattern        = '';
		$pattern_custom = '';

		if ( FieldRegistry::has_rule( $type, 'pattern' ) ) {
			$candidate = sanitize_key( (string) ( $raw['pattern'] ?? '' ) );

			if ( isset( FieldRegistry::patterns()[ $candidate ] ) ) {
				$pattern = $candidate;
			}

			if ( 'custom' === $pattern ) {
				$pattern_custom = self::sanitize_regex( (string) ( $raw['pattern_custom'] ?? '' ) );

				// An unusable regex must not become a rule nothing can satisfy.
				if ( '' === $pattern_custom ) {
					$pattern = '';
				}
			}
		}

		return new self(
			$id,
			$type,
			$label,
			isset( $raw['placeholder'] ) ? sanitize_text_field( (string) $raw['placeholder'] ) : '',
			isset( $raw['help'] ) ? sanitize_text_field( (string) $raw['help'] ) : '',
			! empty( $raw['required'] ),
			$width,
			$options,
			$min,
			$max,
			$pattern,
			$pattern_custom,
			isset( $raw['error_message'] ) ? sanitize_text_field( (string) $raw['error_message'] ) : ''
		);
	}

	/**
	 * Normalise a min/max bound for the field's type.
	 *
	 * Dates stay as `Y-m-d`, everything else becomes a plain number.
	 *
	 * @param mixed  $value Raw bound.
	 * @param string $type  Field type.
	 */
	private static function sanitize_bound( $value, string $type ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( 'date' === $type ) {
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
		}

		if ( 'number' === $type ) {
			return is_numeric( $value ) ? $value : '';
		}

		// Character counts and choice counts are non-negative integers.
		return ctype_digit( $value ) ? (string) (int) $value : '';
	}

	/**
	 * Accept a custom regex only if PCRE can actually compile it.
	 *
	 * The body is stored without delimiters and always run with `#…#u` later,
	 * so an author cannot smuggle in modifiers such as `e`.
	 */
	private static function sanitize_regex( string $regex ): string {
		$regex = trim( wp_unslash( $regex ) );

		if ( '' === $regex || strlen( $regex ) > 500 ) {
			return '';
		}

		$compiled = '#' . str_replace( '#', '\#', $regex ) . '#u';

		// A malformed pattern makes preg_match() emit a warning and return
		// false; suppress it here so saving a typo cannot break the screen.
		$result = @preg_match( $compiled, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return false === $result ? '' : $regex;
	}

	/**
	 * Normalise a choice list coming from a textarea (one entry per line) or an array.
	 *
	 * @param mixed $options Raw options.
	 * @return string[]
	 */
	private static function sanitize_options( $options ): array {
		if ( is_string( $options ) ) {
			$split   = preg_split( '/\R/', $options );
			$options = is_array( $split ) ? $split : array();
		}

		if ( ! is_array( $options ) ) {
			return array();
		}

		$clean = array();

		foreach ( $options as $option ) {
			if ( ! is_scalar( $option ) ) {
				continue;
			}

			$option = sanitize_text_field( (string) $option );

			// Duplicate choices break value validation, so drop them.
			if ( '' !== $option && ! in_array( $option, $clean, true ) ) {
				$clean[] = $option;
			}
		}

		return $clean;
	}

	/**
	 * Turn arbitrary text into a safe, stable field key.
	 */
	private static function to_key( string $value ): string {
		$value = remove_accents( $value );
		$value = strtolower( $value );
		$value = (string) preg_replace( '/[^a-z0-9]+/', '_', $value );

		return trim( $value, '_' );
	}

	/* ------------------------------------------------------------------ */

	public function id(): string {
		return $this->id;
	}

	public function type(): string {
		return $this->type;
	}

	public function label(): string {
		return $this->label;
	}

	public function placeholder(): string {
		return $this->placeholder;
	}

	public function help(): string {
		return $this->help;
	}

	public function is_required(): bool {
		return $this->required;
	}

	public function width(): string {
		return $this->width;
	}

	/** @return string[] */
	public function options(): array {
		return $this->options;
	}

	public function min(): string {
		return $this->min;
	}

	public function max(): string {
		return $this->max;
	}

	public function pattern(): string {
		return $this->pattern;
	}

	public function pattern_custom(): string {
		return $this->pattern_custom;
	}

	public function error_message(): string {
		return $this->error_message;
	}

	/**
	 * The compiled regex for this field, or '' when no format rule applies.
	 *
	 * @return array{regex: string, target: string, message: string}|null
	 */
	public function pattern_rule(): ?array {
		if ( '' === $this->pattern ) {
			return null;
		}

		$preset = FieldRegistry::patterns()[ $this->pattern ] ?? null;

		if ( null === $preset ) {
			return null;
		}

		$regex = 'custom' === $this->pattern ? $this->pattern_custom : (string) $preset['regex'];

		if ( '' === $regex ) {
			return null;
		}

		return array(
			'regex'   => '#' . str_replace( '#', '\#', $regex ) . '#u',
			'target'  => (string) $preset['target'],
			'message' => (string) $preset['message'],
		);
	}

	/**
	 * Value for the HTML `pattern` attribute, or '' when it cannot be mirrored
	 * client-side.
	 */
	public function html_pattern(): string {
		if ( '' === $this->pattern ) {
			return '';
		}

		if ( 'custom' === $this->pattern ) {
			return $this->pattern_custom;
		}

		return (string) ( FieldRegistry::patterns()[ $this->pattern ]['html'] ?? '' );
	}

	public function accepts_multiple(): bool {
		return (bool) FieldRegistry::get( $this->type, 'multiple', false );
	}

	public function has_options(): bool {
		return (bool) FieldRegistry::get( $this->type, 'has_options', false );
	}

	/**
	 * The HTML input name used on the front end.
	 */
	public function input_name(): string {
		return $this->accepts_multiple()
			? 'lf_field[' . $this->id . '][]'
			: 'lf_field[' . $this->id . ']';
	}

	/**
	 * Serialise back to the storage shape.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'             => $this->id,
			'type'           => $this->type,
			'label'          => $this->label,
			'placeholder'    => $this->placeholder,
			'help'           => $this->help,
			'required'       => $this->required,
			'width'          => $this->width,
			'options'        => $this->options,
			'min'            => $this->min,
			'max'            => $this->max,
			'pattern'        => $this->pattern,
			'pattern_custom' => $this->pattern_custom,
			'error_message'  => $this->error_message,
		);
	}
}
