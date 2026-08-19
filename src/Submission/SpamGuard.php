<?php
/**
 * Lightweight, privacy-friendly spam checks.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms\Submission;

use LeadForms\Forms\Form;

defined( 'ABSPATH' ) || exit;

/**
 * Three cheap layers that stop the overwhelming majority of bot traffic
 * without a third-party service or a CAPTCHA:
 *
 * 1. A honeypot field no human ever fills in.
 * 2. A signed timestamp, so a form cannot be submitted faster than a person
 *    could type — and the timestamp cannot be forged because it is HMAC'd.
 * 3. A per-IP hourly rate limit held in a transient.
 */
final class SpamGuard {

	/** Name of the honeypot input. */
	public const HONEYPOT_FIELD = 'lf_website';

	/** Name of the signed timestamp input. */
	public const TIMESTAMP_FIELD = 'lf_ts';

	/**
	 * Produce the signed timestamp token embedded in a rendered form.
	 */
	public function issue_token(): string {
		$now = time();

		return $now . '.' . $this->sign( (string) $now );
	}

	/**
	 * Run all checks.
	 *
	 * @param Form                 $form    Form being submitted.
	 * @param array<string, mixed> $request Raw request data.
	 * @return string Empty when the submission looks legitimate, otherwise a reason code.
	 */
	public function check( Form $form, array $request ): string {
		$settings = $form->settings();

		if ( $settings->flag( 'honeypot' ) && '' !== trim( (string) ( $request[ self::HONEYPOT_FIELD ] ?? '' ) ) ) {
			return 'honeypot';
		}

		$min_seconds = $settings->int( 'min_submit_seconds' );

		if ( $min_seconds > 0 && ! $this->timestamp_is_old_enough( (string) ( $request[ self::TIMESTAMP_FIELD ] ?? '' ), $min_seconds ) ) {
			return 'too_fast';
		}

		$limit = $settings->int( 'rate_limit_per_hour' );

		if ( $limit > 0 && $this->rate_limit_exceeded( $form->id(), $limit ) ) {
			return 'rate_limited';
		}

		/**
		 * Filter the spam verdict, e.g. to plug in Akismet or a CAPTCHA.
		 *
		 * @param string               $reason  Empty string means "not spam".
		 * @param Form                 $form    The form.
		 * @param array<string, mixed> $request Raw request data.
		 */
		return (string) apply_filters( 'lead_forms_spam_check', '', $form, $request );
	}

	/**
	 * Record a successful submission against the current IP.
	 */
	public function record_submission( int $form_id ): void {
		$key   = $this->rate_limit_key( $form_id );
		$count = (int) get_transient( $key );

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
	}

	/**
	 * Verify the signed timestamp and its age.
	 */
	private function timestamp_is_old_enough( string $token, int $min_seconds ): bool {
		if ( ! str_contains( $token, '.' ) ) {
			return false;
		}

		[ $issued, $signature ] = explode( '.', $token, 2 );

		if ( ! ctype_digit( $issued ) || ! hash_equals( $this->sign( $issued ), $signature ) ) {
			return false;
		}

		$age = time() - (int) $issued;

		// Reject anything older than a day: a stale token means a cached page
		// or a replayed request.
		return $age >= $min_seconds && $age < DAY_IN_SECONDS;
	}

	/**
	 * HMAC a value with the site's own salts.
	 */
	private function sign( string $value ): string {
		return hash_hmac( 'sha256', 'lead_forms|' . $value, (string) wp_salt( 'nonce' ) );
	}

	/**
	 * Whether this IP has already submitted too often in the past hour.
	 */
	private function rate_limit_exceeded( int $form_id, int $limit ): bool {
		return (int) get_transient( $this->rate_limit_key( $form_id ) ) >= $limit;
	}

	/**
	 * Transient key for the current visitor.
	 */
	private function rate_limit_key( int $form_id ): string {
		return 'lf_rl_' . $form_id . '_' . substr( self::client_ip_hash(), 0, 20 );
	}

	/**
	 * A salted hash of the visitor IP.
	 *
	 * The raw address is never stored, which keeps the leads table free of
	 * directly identifying network data while still supporting rate limiting.
	 */
	public static function client_ip_hash(): string {
		return hash_hmac( 'sha256', self::client_ip(), (string) wp_salt( 'secure_auth' ) );
	}

	/**
	 * Best-effort client IP.
	 *
	 * Proxy headers are only honoured when the site opts in, because they are
	 * trivially spoofable when the site is not actually behind a proxy.
	 */
	private static function client_ip(): string {
		$candidates = array( 'REMOTE_ADDR' );

		/**
		 * Filter the server keys inspected for the client IP.
		 *
		 * Add `HTTP_CF_CONNECTING_IP` or `HTTP_X_FORWARDED_FOR` only if a
		 * trusted proxy sets them.
		 *
		 * @param string[] $candidates Server keys, in priority order.
		 */
		$candidates = (array) apply_filters( 'lead_forms_ip_server_keys', $candidates );

		foreach ( $candidates as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			$raw   = sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) );
			$first = trim( explode( ',', $raw )[0] );

			if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
				return $first;
			}
		}

		return '0.0.0.0';
	}
}
