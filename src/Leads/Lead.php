<?php
/**
 * A stored submission.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms\Leads;

defined( 'ABSPATH' ) || exit;

/**
 * Read model for one row of the leads table.
 */
final class Lead {

	public int $id           = 0;
	public int $form_id      = 0;
	public string $status    = 'new';
	public string $name      = '';
	public string $email     = '';
	public string $phone     = '';
	public string $source_url = '';
	public string $referer   = '';
	public string $ip_hash   = '';
	public string $user_agent = '';
	public int $user_id      = 0;
	public string $created_at = '';

	/** @var array<string, array{label: string, value: mixed, type: string}> */
	public array $payload = array();

	/**
	 * Build from a raw database row.
	 *
	 * @param array<string, mixed> $row Row from $wpdb.
	 */
	public static function from_row( array $row ): self {
		$lead = new self();

		$lead->id         = (int) ( $row['id'] ?? 0 );
		$lead->form_id    = (int) ( $row['form_id'] ?? 0 );
		$lead->status     = (string) ( $row['status'] ?? 'new' );
		$lead->name       = (string) ( $row['name'] ?? '' );
		$lead->email      = (string) ( $row['email'] ?? '' );
		$lead->phone      = (string) ( $row['phone'] ?? '' );
		$lead->source_url = (string) ( $row['source_url'] ?? '' );
		$lead->referer    = (string) ( $row['referer'] ?? '' );
		$lead->ip_hash    = (string) ( $row['ip_hash'] ?? '' );
		$lead->user_agent = (string) ( $row['user_agent'] ?? '' );
		$lead->user_id    = (int) ( $row['user_id'] ?? 0 );
		$lead->created_at = (string) ( $row['created_at'] ?? '' );

		$decoded       = json_decode( (string) ( $row['payload'] ?? '[]' ), true );
		$lead->payload = is_array( $decoded ) ? $decoded : array();

		return $lead;
	}

	/**
	 * Field values as `label => printable value`.
	 *
	 * Two fields may legitimately share a label (their keys are what must be
	 * unique), so a repeated label is qualified with its key rather than
	 * silently overwriting the earlier answer.
	 *
	 * @return array<string, string>
	 */
	public function readable_payload(): array {
		$out = array();

		foreach ( $this->payload as $key => $entry ) {
			$label = isset( $entry['label'] ) ? (string) $entry['label'] : (string) $key;
			$value = $entry['value'] ?? '';

			if ( '' === $label ) {
				$label = (string) $key;
			}

			if ( isset( $out[ $label ] ) ) {
				$label = sprintf( '%s (%s)', $label, (string) $key );
			}

			$out[ $label ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
		}

		return $out;
	}

	/**
	 * Submission time formatted for the site's locale and timezone.
	 *
	 * `created_at` is stored in UTC, so it is converted on the way out.
	 */
	public function created_display(): string {
		if ( '' === $this->created_at ) {
			return '';
		}

		$format    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$formatted = wp_date( (string) $format, (int) strtotime( $this->created_at . ' UTC' ) );

		return false === $formatted ? $this->created_at : $formatted;
	}
}
