<?php
/**
 * A form: its fields plus its settings.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Read model passed to the renderer, the validator and the mailer.
 */
final class Form {

	private int $id;
	private string $title;

	/** @var Field[] */
	private array $fields;

	private FormSettings $settings;

	/**
	 * @param Field[] $fields Ordered field list.
	 */
	public function __construct( int $id, string $title, array $fields, FormSettings $settings ) {
		$this->id       = $id;
		$this->title    = $title;
		$this->fields   = $fields;
		$this->settings = $settings;
	}

	public function id(): int {
		return $this->id;
	}

	public function title(): string {
		return $this->title;
	}

	/** @return Field[] */
	public function fields(): array {
		return $this->fields;
	}

	public function settings(): FormSettings {
		return $this->settings;
	}

	public function has_fields(): bool {
		return array() !== $this->fields;
	}

	/**
	 * Find a field by its key.
	 */
	public function field( string $id ): ?Field {
		foreach ( $this->fields as $field ) {
			if ( $field->id() === $id ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * The heading shown above the form, falling back to the post title.
	 */
	public function heading(): string {
		$heading = $this->settings->text( 'heading' );

		return '' !== $heading ? $heading : $this->title;
	}

	/**
	 * Best-effort guess at which field holds the submitter's e-mail address.
	 *
	 * Used for Reply-To and the auto-reply when no field is configured
	 * explicitly in the form settings.
	 */
	public function email_field(): ?Field {
		$configured = $this->settings->text( 'reply_to_field' );

		if ( '' !== $configured ) {
			$field = $this->field( $configured );

			if ( $field instanceof Field ) {
				return $field;
			}
		}

		foreach ( $this->fields as $field ) {
			if ( 'email' === $field->type() ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * First field of a given type — used to fill the denormalised lead columns.
	 */
	public function first_field_of_type( string ...$types ): ?Field {
		foreach ( $this->fields as $field ) {
			if ( in_array( $field->type(), $types, true ) ) {
				return $field;
			}
		}

		return null;
	}
}
