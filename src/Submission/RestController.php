<?php
/**
 * REST endpoints used by the front-end script.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms\Submission;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The AJAX path for submissions.
 *
 * REST is preferred over `admin-ajax.php`: it gives typed argument schemas,
 * proper status codes and a discoverable namespace.
 */
final class RestController {

	public const NAMESPACE = 'lead-forms/v1';

	private SubmissionHandler $handler;

	public function __construct( SubmissionHandler $handler ) {
		$this->handler = $handler;
	}

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Nonce action for a given form.
	 */
	public static function nonce_action( int $form_id ): string {
		return 'lead_forms_submit_' . $form_id;
	}

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/submit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit' ),
				// The form is public by design; authenticity is enforced by the
				// per-form nonce plus the spam guard inside the handler.
				'permission_callback' => '__return_true',
				'args'                => array(
					'form_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ): bool {
							return absint( $value ) > 0;
						},
					),
					'lf_nonce' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/token',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'token' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'form_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Hand out a fresh nonce.
	 *
	 * Full-page caching can serve a form whose embedded nonce has expired, so
	 * the script fetches a live one before submitting.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function token( WP_REST_Request $request ): WP_REST_Response {
		$form_id = (int) $request->get_param( 'form_id' );

		$response = new WP_REST_Response(
			array(
				'nonce' => wp_create_nonce( self::nonce_action( $form_id ) ),
			)
		);

		// Never let a proxy or CDN cache a nonce.
		$response->header( 'Cache-Control', 'no-store, max-age=0' );

		return $response;
	}

	/**
	 * Handle a submission.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit( WP_REST_Request $request ) {
		$form_id = (int) $request->get_param( 'form_id' );
		$nonce   = (string) $request->get_param( 'lf_nonce' );

		if ( ! wp_verify_nonce( $nonce, self::nonce_action( $form_id ) ) ) {
			return new WP_Error(
				'lead_forms_bad_nonce',
				__( 'Your session expired. Please reload the page and try again.', 'lead-forms' ),
				array( 'status' => 403 )
			);
		}

		// REST params arrive unslashed already, unlike $_POST.
		$params = $request->get_params();
		$result = $this->handler->handle( $form_id, $params );

		return new WP_REST_Response( $result->to_array(), $result->status_code() );
	}
}
