<?php
/**
 * The single code path every submission travels down.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms\Submission;

use LeadForms\Forms\Form;
use LeadForms\Forms\FormRepository;
use LeadForms\Leads\LeadRepository;
use LeadForms\Mail\Notifier;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates validate → spam check → store → notify.
 *
 * Both the REST controller and the no-JavaScript POST handler call this, which
 * is what guarantees identical validation and identical security checks
 * regardless of how the browser submitted the form.
 */
final class SubmissionHandler {

	private FormRepository $forms;
	private Validator $validator;
	private SpamGuard $spam_guard;
	private LeadRepository $leads;
	private Notifier $notifier;

	public function __construct(
		FormRepository $forms,
		Validator $validator,
		SpamGuard $spam_guard,
		LeadRepository $leads,
		Notifier $notifier
	) {
		$this->forms      = $forms;
		$this->validator  = $validator;
		$this->spam_guard = $spam_guard;
		$this->leads      = $leads;
		$this->notifier   = $notifier;
	}

	/**
	 * Process one submission.
	 *
	 * @param int                  $form_id Target form.
	 * @param array<string, mixed> $request The full, unslashed request payload.
	 */
	public function handle( int $form_id, array $request ): SubmissionResult {
		$form = $this->forms->find( $form_id );

		if ( null === $form ) {
			return SubmissionResult::failure( __( 'This form is no longer available.', 'lead-forms' ), 404 );
		}

		if ( ! $form->has_fields() ) {
			return SubmissionResult::failure( __( 'This form has no fields configured.', 'lead-forms' ), 409 );
		}

		$settings = $form->settings();

		// 1. Spam checks run before anything expensive happens.
		$spam_reason = $this->spam_guard->check( $form, $request );

		if ( '' !== $spam_reason ) {
			/**
			 * Fires when a submission is rejected as spam.
			 *
			 * @param string $spam_reason Reason code.
			 * @param Form   $form        The form.
			 */
			do_action( 'lead_forms_spam_rejected', $spam_reason, $form );

			if ( 'rate_limited' === $spam_reason ) {
				return SubmissionResult::failure(
					__( 'Too many submissions from this device. Please try again later.', 'lead-forms' ),
					429
				);
			}

			// Honeypot and timing failures get a deliberately vague answer so
			// bots learn nothing about which check caught them.
			return SubmissionResult::failure( __( 'Your submission could not be processed.', 'lead-forms' ), 400 );
		}

		// 2. Validate and sanitise.
		$raw_fields = isset( $request['lf_field'] ) && is_array( $request['lf_field'] ) ? $request['lf_field'] : array();
		$validated  = $this->validator->validate( $form, $raw_fields );

		if ( ! empty( $validated['errors'] ) ) {
			$message = $settings->text( 'error_message' );

			return SubmissionResult::invalid(
				'' !== $message ? $message : __( 'Please check the highlighted fields and try again.', 'lead-forms' ),
				$validated['errors']
			);
		}

		$values = $validated['values'];

		// 3. Store, if the form keeps leads.
		$lead_id = 0;

		if ( $settings->flag( 'store_leads' ) ) {
			$lead_id = $this->store( $form, $values, $request );
		}

		$this->spam_guard->record_submission( $form->id() );

		/**
		 * Fires after a valid submission has been stored.
		 *
		 * @param int                  $lead_id Stored lead ID, 0 when storage is off.
		 * @param array<string, array> $values  Sanitised values.
		 * @param Form                 $form    The form.
		 */
		do_action( 'lead_forms_submission_created', $lead_id, $values, $form );

		// 4. Notify. A mail failure must not lose an already-stored lead, so it
		// is reported in the log rather than to the visitor.
		if ( $settings->flag( 'notify' ) ) {
			$this->notifier->notify_recipients( $form, $values, $lead_id );
		}

		if ( $settings->flag( 'autoreply' ) ) {
			$this->notifier->send_autoreply( $form, $values );
		}

		$message = $settings->text( 'success_message' );

		return SubmissionResult::success(
			'' !== $message ? $message : __( 'Thanks! Your message has been sent.', 'lead-forms' ),
			$lead_id,
			$settings->text( 'redirect_url' )
		);
	}

	/**
	 * Persist the lead, denormalising the obvious columns for the list table.
	 *
	 * @param Form                 $form    The form.
	 * @param array<string, array> $values  Sanitised values.
	 * @param array<string, mixed> $request Raw request, for context fields.
	 */
	private function store( Form $form, array $values, array $request ): int {
		$name_field  = $form->first_field_of_type( 'text' );
		$email_field = $form->email_field();
		$phone_field = $form->first_field_of_type( 'tel' );

		$pick = static function ( ?object $field ) use ( $values ): string {
			if ( null === $field ) {
				return '';
			}

			$value = $values[ $field->id() ]['value'] ?? '';

			return is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		};

		$source_url = isset( $request['lf_source_url'] ) ? esc_url_raw( (string) $request['lf_source_url'] ) : '';
		$referer    = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['HTTP_REFERER'] ) ) : '';
		$agent      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		return $this->leads->insert(
			array(
				'form_id'    => $form->id(),
				'status'     => 'new',
				'name'       => $pick( $name_field ),
				'email'      => $pick( $email_field ),
				'phone'      => $pick( $phone_field ),
				'payload'    => $values,
				'source_url' => $source_url,
				'referer'    => $referer,
				'ip_hash'    => SpamGuard::client_ip_hash(),
				'user_agent' => $agent,
				'user_id'    => get_current_user_id(),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}
}
