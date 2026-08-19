<?php
/**
 * `{tag}` replacement for subjects and message bodies.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms\Mail;

use LeadForms\Forms\Form;

defined( 'ABSPATH' ) || exit;

/**
 * Lets site owners write "New enquiry from {name} — {service}" in the subject
 * line without touching code.
 */
final class MergeTags {

	/**
	 * Build the replacement map for one submission.
	 *
	 * @param Form                 $form    The form.
	 * @param array<string, array> $values  Sanitised values keyed by field id.
	 * @param int                  $lead_id Stored lead ID.
	 * @return array<string, string>
	 */
	public static function context( Form $form, array $values, int $lead_id = 0 ): array {
		$tags = array(
			'{site_name}'  => (string) get_bloginfo( 'name' ),
			'{site_url}'   => home_url( '/' ),
			'{form_title}' => $form->title(),
			'{form_id}'    => (string) $form->id(),
			'{lead_id}'    => (string) $lead_id,
			'{date}'       => (string) wp_date( (string) get_option( 'date_format' ) ),
			'{time}'       => (string) wp_date( (string) get_option( 'time_format' ) ),
		);

		foreach ( $values as $key => $entry ) {
			$value = $entry['value'] ?? '';

			$tags[ '{' . $key . '}' ] = is_array( $value )
				? implode( ', ', array_map( 'strval', $value ) )
				: (string) $value;
		}

		return $tags;
	}

	/**
	 * Replace tags in a plain string.
	 *
	 * @param string                $text    Template text.
	 * @param array<string, string> $context Tag map from `context()`.
	 */
	public static function replace( string $text, array $context ): string {
		if ( '' === $text ) {
			return '';
		}

		$replaced = strtr( $text, $context );

		// Drop any tag that did not resolve, rather than mailing "{whatever}".
		return (string) preg_replace( '/\{[a-z0-9_]+\}/i', '', $replaced );
	}
}
