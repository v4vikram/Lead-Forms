<?php
/**
 * Outcome of a submission attempt.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms\Submission;

defined( 'ABSPATH' ) || exit;

/**
 * One value object shared by both entry points (REST and non-JS POST), so the
 * two never drift apart in what they report back.
 */
final class SubmissionResult {

	private bool $success;
	private string $message;

	/** @var array<string, string> */
	private array $errors;

	private int $lead_id;
	private string $redirect_url;
	private int $status_code;

	/**
	 * Built through the named constructors below, never directly.
	 *
	 * $errors maps field id to the message shown against that field.
	 */
	private function __construct(
		bool $success,
		string $message,
		array $errors = array(),
		int $lead_id = 0,
		string $redirect_url = '',
		int $status_code = 200
	) {
		$this->success      = $success;
		$this->message      = $message;
		$this->errors       = $errors;
		$this->lead_id      = $lead_id;
		$this->redirect_url = $redirect_url;
		$this->status_code  = $status_code;
	}

	public static function success( string $message, int $lead_id = 0, string $redirect_url = '' ): self {
		return new self( true, $message, array(), $lead_id, $redirect_url, 200 );
	}

	/**
	 * A submission that failed validation.
	 *
	 * $errors maps field id to the message shown against that field.
	 */
	public static function invalid( string $message, array $errors ): self {
		return new self( false, $message, $errors, 0, '', 422 );
	}

	public static function failure( string $message, int $status_code = 400 ): self {
		return new self( false, $message, array(), 0, '', $status_code );
	}

	public function is_success(): bool {
		return $this->success;
	}

	public function message(): string {
		return $this->message;
	}

	/** @return array<string, string> */
	public function errors(): array {
		return $this->errors;
	}

	public function lead_id(): int {
		return $this->lead_id;
	}

	public function redirect_url(): string {
		return $this->redirect_url;
	}

	public function status_code(): int {
		return $this->status_code;
	}

	/**
	 * Shape returned by the REST endpoint.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'success'  => $this->success,
			'message'  => $this->message,
			'errors'   => (object) $this->errors,
			'lead_id'  => $this->lead_id,
			'redirect' => $this->redirect_url,
		);
	}
}
