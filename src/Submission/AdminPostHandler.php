<?php
/**
 * Progressive-enhancement fallback: a plain form POST.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms\Submission;

defined( 'ABSPATH' ) || exit;

/**
 * Handles submissions from browsers where the script did not run.
 *
 * The form's `action` always points here, and JavaScript merely intercepts the
 * submit event — so the form keeps working with JS disabled, blocked, or still
 * loading.
 */
final class AdminPostHandler {

	public const ACTION = 'lead_forms_submit';

	/** How long a flash result stays available after the redirect. */
	private const FLASH_TTL = 5 * MINUTE_IN_SECONDS;

	private SubmissionHandler $handler;

	public function __construct( SubmissionHandler $handler ) {
		$this->handler = $handler;
	}

	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Process the POST, then redirect back (Post/Redirect/Get).
	 */
	public function handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified immediately below.
		$request = wp_unslash( $_POST );
		$form_id = isset( $request['form_id'] ) ? absint( $request['form_id'] ) : 0;
		$nonce   = isset( $request['lf_nonce'] ) ? sanitize_text_field( (string) $request['lf_nonce'] ) : '';

		if ( $form_id <= 0 || ! wp_verify_nonce( $nonce, RestController::nonce_action( $form_id ) ) ) {
			wp_die(
				esc_html__( 'Your session expired. Please go back, reload the page and try again.', 'lead-forms' ),
				esc_html__( 'Submission failed', 'lead-forms' ),
				array( 'response' => 403 )
			);
		}

		$result = $this->handler->handle( $form_id, (array) $request );

		if ( $result->is_success() && '' !== $result->redirect_url() ) {
			wp_safe_redirect( $result->redirect_url() );
			exit;
		}

		$return_url = $this->return_url( (array) $request );
		$token      = $this->store_flash( $form_id, $result, (array) $request );

		wp_safe_redirect(
			add_query_arg( 'lf_result', $token, $return_url ) . '#lf-form-' . $form_id
		);
		exit;
	}

	/**
	 * Save the outcome so the page we redirect to can render it.
	 *
	 * @param int                  $form_id Form ID.
	 * @param SubmissionResult     $result  Outcome.
	 * @param array<string, mixed> $request Raw request, used to repopulate inputs.
	 * @return string Opaque token placed in the redirect URL.
	 */
	private function store_flash( int $form_id, SubmissionResult $result, array $request ): string {
		$token = wp_generate_password( 20, false, false );

		$flash = array(
			'form_id' => $form_id,
			'success' => $result->is_success(),
			'message' => $result->message(),
			'errors'  => $result->errors(),
			'values'  => array(),
		);

		// Only repopulate when something went wrong; a successful form resets.
		if ( ! $result->is_success() && isset( $request['lf_field'] ) && is_array( $request['lf_field'] ) ) {
			$flash['values'] = $this->shallow_clean( $request['lf_field'] );
		}

		set_transient( 'lf_flash_' . $token, $flash, self::FLASH_TTL );

		return $token;
	}

	/**
	 * Rough sanitisation for repopulating inputs. The values are re-escaped at
	 * render time; this only keeps obviously unusable data out of the store.
	 *
	 * @param array<string, mixed> $values Raw submitted values.
	 * @return array<string, mixed>
	 */
	private function shallow_clean( array $values ): array {
		$clean = array();

		foreach ( $values as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( is_array( $value ) ) {
				$clean[ $key ] = array_map( 'sanitize_text_field', array_filter( $value, 'is_scalar' ) );
			} elseif ( is_scalar( $value ) ) {
				$clean[ $key ] = sanitize_textarea_field( (string) $value );
			}
		}

		return $clean;
	}

	/**
	 * Where to send the visitor back to.
	 *
	 * @param array<string, mixed> $request Raw request.
	 */
	private function return_url( array $request ): string {
		$candidate = isset( $request['lf_source_url'] ) ? esc_url_raw( (string) $request['lf_source_url'] ) : '';

		if ( '' === $candidate ) {
			$candidate = wp_get_referer() ?: home_url( '/' );
		}

		// Only ever redirect within this site.
		return wp_validate_redirect( $candidate, home_url( '/' ) );
	}

	/**
	 * Read and consume a flash result.
	 *
	 * @param int $form_id Form currently being rendered.
	 * @return array<string, mixed>|null
	 */
	public static function consume_flash( int $form_id ): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a single-use token.
		$token = isset( $_GET['lf_result'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['lf_result'] ) ) : '';

		if ( '' === $token || ! preg_match( '/^[A-Za-z0-9]{20}$/', $token ) ) {
			return null;
		}

		$flash = get_transient( 'lf_flash_' . $token );

		if ( ! is_array( $flash ) || (int) ( $flash['form_id'] ?? 0 ) !== $form_id ) {
			return null;
		}

		delete_transient( 'lf_flash_' . $token );

		return $flash;
	}
}
