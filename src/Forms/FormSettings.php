<?php
/**
 * Per-form settings, normalised.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Typed accessor over the `_lead_forms_settings` meta array.
 *
 * Storing one array in a single meta key keeps the meta table tidy; this class
 * is what stops the rest of the plugin from doing defensive `isset()` dances.
 */
final class FormSettings {

	/** @var array<string, mixed> */
	private array $data;

	/**
	 * @param array<string, mixed> $data Already sanitised settings.
	 */
	public function __construct( array $data = array() ) {
		$this->data = array_merge( self::defaults(), $data );
	}

	/**
	 * Default values for every supported setting.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'heading'              => '',
			'subheading'           => '',
			'submit_label'         => __( 'Submit', 'lead-forms' ),
			'success_message'      => __( 'Thanks! Your message has been sent.', 'lead-forms' ),
			'error_message'        => __( 'Please check the highlighted fields and try again.', 'lead-forms' ),
			'redirect_url'         => '',

			// Notification e-mail.
			'notify'               => true,
			'recipients'           => '',
			'cc'                   => '',
			'bcc'                  => '',
			'subject'              => '',
			'from_name'            => '',
			'from_email'           => '',
			'reply_to_field'       => '',

			// Auto-reply to the person who submitted.
			'autoreply'            => false,
			'autoreply_subject'    => '',
			'autoreply_message'    => '',

			// Storage and anti-spam.
			'store_leads'          => true,
			'honeypot'             => true,
			'min_submit_seconds'   => 3,
			'rate_limit_per_hour'  => 8,

			// Presentation.
			'theme'                => 'classic',
			'accent_color'         => '#1e88f0',
			'panel_color'          => '#7ba7d7',
		);
	}

	/**
	 * Sanitise a raw settings array (e.g. straight from $_POST).
	 *
	 * @param array<string, mixed> $raw Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $raw ): array {
		$clean = array();

		$clean['heading']         = sanitize_text_field( (string) ( $raw['heading'] ?? '' ) );
		$clean['subheading']      = sanitize_text_field( (string) ( $raw['subheading'] ?? '' ) );
		$clean['submit_label']    = sanitize_text_field( (string) ( $raw['submit_label'] ?? '' ) );
		$clean['success_message'] = sanitize_textarea_field( (string) ( $raw['success_message'] ?? '' ) );
		$clean['error_message']   = sanitize_textarea_field( (string) ( $raw['error_message'] ?? '' ) );
		$clean['redirect_url']    = esc_url_raw( (string) ( $raw['redirect_url'] ?? '' ) );

		$clean['notify']         = ! empty( $raw['notify'] );
		$clean['recipients']     = self::sanitize_email_list( (string) ( $raw['recipients'] ?? '' ) );
		$clean['cc']             = self::sanitize_email_list( (string) ( $raw['cc'] ?? '' ) );
		$clean['bcc']            = self::sanitize_email_list( (string) ( $raw['bcc'] ?? '' ) );
		$clean['subject']        = sanitize_text_field( (string) ( $raw['subject'] ?? '' ) );
		$clean['from_name']      = sanitize_text_field( (string) ( $raw['from_name'] ?? '' ) );
		$clean['from_email']     = sanitize_email( (string) ( $raw['from_email'] ?? '' ) );
		$clean['reply_to_field'] = sanitize_key( (string) ( $raw['reply_to_field'] ?? '' ) );

		$clean['autoreply']         = ! empty( $raw['autoreply'] );
		$clean['autoreply_subject'] = sanitize_text_field( (string) ( $raw['autoreply_subject'] ?? '' ) );
		$clean['autoreply_message'] = sanitize_textarea_field( (string) ( $raw['autoreply_message'] ?? '' ) );

		$clean['store_leads'] = ! empty( $raw['store_leads'] );
		$clean['honeypot']    = ! empty( $raw['honeypot'] );

		// Clamp the anti-spam numbers so a typo cannot lock the form out.
		$clean['min_submit_seconds']  = max( 0, min( 60, absint( $raw['min_submit_seconds'] ?? 3 ) ) );
		$clean['rate_limit_per_hour'] = max( 0, min( 200, absint( $raw['rate_limit_per_hour'] ?? 8 ) ) );

		$theme          = sanitize_key( (string) ( $raw['theme'] ?? 'classic' ) );
		$clean['theme'] = in_array( $theme, array( 'classic', 'minimal' ), true ) ? $theme : 'classic';

		$clean['accent_color'] = self::sanitize_color( (string) ( $raw['accent_color'] ?? '' ), '#1e88f0' );
		$clean['panel_color']  = self::sanitize_color( (string) ( $raw['panel_color'] ?? '' ), '#7ba7d7' );

		return $clean;
	}

	/**
	 * Keep only valid addresses from a comma / newline separated list.
	 */
	private static function sanitize_email_list( string $value ): string {
		$parts = preg_split( '/[,;\r\n]+/', $value );
		$valid = array();

		foreach ( (array) $parts as $part ) {
			$email = sanitize_email( trim( (string) $part ) );

			if ( '' !== $email && is_email( $email ) && ! in_array( $email, $valid, true ) ) {
				$valid[] = $email;
			}
		}

		return implode( ', ', $valid );
	}

	/**
	 * Validate a hex colour, falling back when it is malformed.
	 */
	private static function sanitize_color( string $value, string $fallback ): string {
		$color = sanitize_hex_color( $value );

		return $color ?: $fallback;
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Raw setting access with a fallback.
	 *
	 * @param string $key      Setting name.
	 * @param mixed  $fallback Returned when unset.
	 * @return mixed
	 */
	public function get( string $key, $fallback = null ) {
		return $this->data[ $key ] ?? $fallback;
	}

	public function text( string $key ): string {
		return (string) ( $this->data[ $key ] ?? '' );
	}

	public function flag( string $key ): bool {
		return ! empty( $this->data[ $key ] );
	}

	public function int( string $key ): int {
		return (int) ( $this->data[ $key ] ?? 0 );
	}

	/**
	 * Notification recipients, falling back to the site admin so a freshly
	 * created form never silently drops leads.
	 *
	 * @return string[]
	 */
	public function recipients(): array {
		$list = self::to_array( $this->text( 'recipients' ) );

		if ( empty( $list ) ) {
			$list = array( (string) get_option( 'admin_email' ) );
		}

		return array_values( array_filter( $list, 'is_email' ) );
	}

	/** @return string[] */
	public function cc(): array {
		return self::to_array( $this->text( 'cc' ) );
	}

	/** @return string[] */
	public function bcc(): array {
		return self::to_array( $this->text( 'bcc' ) );
	}

	/**
	 * Split a stored address list back into an array.
	 *
	 * @return string[]
	 */
	private static function to_array( string $value ): array {
		if ( '' === trim( $value ) ) {
			return array();
		}

		$parts = preg_split( '/[,;\r\n]+/', $value );

		return array_values(
			array_filter(
				array_map( 'trim', (array) $parts ),
				static function ( $email ): bool {
					return '' !== $email && is_email( $email );
				}
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array_all(): array {
		return $this->data;
	}
}
