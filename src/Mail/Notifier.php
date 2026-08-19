<?php
/**
 * Outbound notification e-mail.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms\Mail;

use LeadForms\Forms\Form;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the lead to the configured recipients and, optionally, an
 * acknowledgement back to the person who submitted.
 */
final class Notifier {

	/**
	 * Mail the lead to every configured recipient.
	 *
	 * @param Form                 $form    The form.
	 * @param array<string, array> $values  Sanitised values.
	 * @param int                  $lead_id Stored lead ID (0 when storage is off).
	 */
	public function notify_recipients( Form $form, array $values, int $lead_id = 0 ): bool {
		$settings   = $form->settings();
		$recipients = $settings->recipients();

		if ( empty( $recipients ) ) {
			return false;
		}

		$context = MergeTags::context( $form, $values, $lead_id );

		$subject = $settings->text( 'subject' );
		$subject = '' !== $subject
			? MergeTags::replace( $subject, $context )
			/* translators: 1: form name, 2: site name. */
			: sprintf( __( 'New lead: %1$s — %2$s', 'lead-forms' ), $form->title(), (string) get_bloginfo( 'name' ) );

		$headers = $this->base_headers( $form, $values );

		foreach ( $settings->cc() as $cc ) {
			$headers[] = 'Cc: ' . $cc;
		}

		foreach ( $settings->bcc() as $bcc ) {
			$headers[] = 'Bcc: ' . $bcc;
		}

		$body = $this->render_body( $form, $values, $lead_id );

		/**
		 * Filter the admin notification just before it is sent.
		 *
		 * @param array<string, mixed> $email Keys: to, subject, body, headers.
		 * @param Form                 $form  The form.
		 */
		$email = (array) apply_filters(
			'lead_forms_notification_email',
			array(
				'to'      => $recipients,
				'subject' => $subject,
				'body'    => $body,
				'headers' => $headers,
			),
			$form,
			$values
		);

		return $this->send( $email );
	}

	/**
	 * Send the acknowledgement to the submitter.
	 *
	 * @param Form                 $form   The form.
	 * @param array<string, array> $values Sanitised values.
	 */
	public function send_autoreply( Form $form, array $values ): bool {
		$field = $form->email_field();

		if ( null === $field ) {
			return false;
		}

		$to = (string) ( $values[ $field->id() ]['value'] ?? '' );

		if ( '' === $to || ! is_email( $to ) ) {
			return false;
		}

		$settings = $form->settings();
		$context  = MergeTags::context( $form, $values );

		$subject = $settings->text( 'autoreply_subject' );
		$subject = '' !== $subject
			? MergeTags::replace( $subject, $context )
			/* translators: %s: site name. */
			: sprintf( __( 'We received your message — %s', 'lead-forms' ), (string) get_bloginfo( 'name' ) );

		$message = MergeTags::replace( $settings->text( 'autoreply_message' ), $context );

		if ( '' === trim( $message ) ) {
			$message = __( 'Thank you for getting in touch. We have received your request and will reply shortly.', 'lead-forms' );
		}

		$body = $this->wrap_html(
			esc_html( $subject ),
			wpautop( esc_html( $message ) )
		);

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$from    = $this->from_header( $form );

		if ( '' !== $from ) {
			$headers[] = $from;
		}

		// Replies to an auto-reply should reach a human.
		$recipients = $settings->recipients();

		if ( ! empty( $recipients ) ) {
			$headers[] = 'Reply-To: ' . $recipients[0];
		}

		return $this->send(
			array(
				'to'      => $to,
				'subject' => $subject,
				'body'    => $body,
				'headers' => $headers,
			)
		);
	}

	/**
	 * Shared headers: HTML content type, From, and Reply-To pointing at the lead.
	 *
	 * @param Form                 $form   The form.
	 * @param array<string, array> $values Sanitised values.
	 * @return string[]
	 */
	private function base_headers( Form $form, array $values ): array {
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$from = $this->from_header( $form );

		if ( '' !== $from ) {
			$headers[] = $from;
		}

		// Reply-To is what makes "Reply" in the mail client go to the lead,
		// while From stays on the site's own domain so SPF/DKIM still pass.
		$field = $form->email_field();

		if ( null !== $field ) {
			$email = (string) ( $values[ $field->id() ]['value'] ?? '' );

			if ( '' !== $email && is_email( $email ) ) {
				$name = $this->guess_name( $form, $values );

				$headers[] = '' !== $name
					? sprintf( 'Reply-To: %s <%s>', $this->encode_name( $name ), $email )
					: 'Reply-To: ' . $email;
			}
		}

		return $headers;
	}

	/**
	 * Build the From header, or '' to let WordPress decide.
	 */
	private function from_header( Form $form ): string {
		$settings = $form->settings();
		$email    = $settings->text( 'from_email' );
		$name     = $settings->text( 'from_name' );

		if ( '' === $email || ! is_email( $email ) ) {
			return '';
		}

		return '' !== $name
			? sprintf( 'From: %s <%s>', $this->encode_name( $name ), $email )
			: 'From: ' . $email;
	}

	/**
	 * Quote a display name so a comma or angle bracket cannot break the header.
	 */
	private function encode_name( string $name ): string {
		$name = str_replace( array( "\r", "\n", '"', '<', '>' ), '', $name );

		return '"' . $name . '"';
	}

	/**
	 * Pick something to address the lead by.
	 *
	 * @param Form                 $form   The form.
	 * @param array<string, array> $values Sanitised values.
	 */
	private function guess_name( Form $form, array $values ): string {
		$field = $form->first_field_of_type( 'text' );

		if ( null === $field ) {
			return '';
		}

		$value = $values[ $field->id() ]['value'] ?? '';

		return is_array( $value ) ? '' : (string) $value;
	}

	/**
	 * Render the notification body as a simple, mail-client-safe table.
	 *
	 * @param Form                 $form    The form.
	 * @param array<string, array> $values  Sanitised values.
	 * @param int                  $lead_id Stored lead ID.
	 */
	private function render_body( Form $form, array $values, int $lead_id ): string {
		$rows = '';

		foreach ( $values as $entry ) {
			$label = (string) ( $entry['label'] ?? '' );
			$value = $entry['value'] ?? '';

			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}

			if ( 'acceptance' === ( $entry['type'] ?? '' ) ) {
				$value = '' !== (string) $value ? __( 'Yes', 'lead-forms' ) : __( 'No', 'lead-forms' );
			}

			$rows .= sprintf(
				'<tr>
					<th align="left" valign="top" style="padding:10px 14px;border-bottom:1px solid #e6e8eb;background:#f7f9fc;font:600 13px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1d2939;width:38%%;">%s</th>
					<td valign="top" style="padding:10px 14px;border-bottom:1px solid #e6e8eb;font:400 14px/1.6 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#101828;">%s</td>
				</tr>',
				esc_html( $label ),
				'' !== (string) $value ? nl2br( esc_html( (string) $value ) ) : '<span style="color:#98a2b3;">&mdash;</span>'
			);
		}

		$meta = sprintf(
			/* translators: 1: form name, 2: site name. */
			esc_html__( 'Submitted through %1$s on %2$s.', 'lead-forms' ),
			'<strong>' . esc_html( $form->title() ) . '</strong>',
			'<a href="' . esc_url( home_url( '/' ) ) . '" style="color:#1e88f0;">' . esc_html( (string) get_bloginfo( 'name' ) ) . '</a>'
		);

		if ( $lead_id > 0 ) {
			$link  = add_query_arg(
				array(
					'post_type' => 'lead_form',
					'page'      => 'lead-forms-leads',
					'lead'      => $lead_id,
				),
				admin_url( 'edit.php' )
			);
			$meta .= sprintf(
				' <a href="%s" style="color:#1e88f0;">%s</a>',
				esc_url( $link ),
				esc_html__( 'View this lead', 'lead-forms' )
			);
		}

		$content = sprintf(
			'<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #e6e8eb;border-radius:6px;overflow:hidden;">%s</table>
			<p style="margin:18px 0 0;font:400 12px/1.6 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#667085;">%s</p>',
			$rows,
			$meta
		);

		return $this->wrap_html(
			/* translators: %s: form name. */
			sprintf( esc_html__( 'New submission: %s', 'lead-forms' ), esc_html( $form->title() ) ),
			$content
		);
	}

	/**
	 * Minimal responsive HTML shell.
	 */
	private function wrap_html( string $title, string $content ): string {
		return sprintf(
			'<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
			<body style="margin:0;padding:24px;background:#f2f4f7;">
				<table role="presentation" width="100%%" cellpadding="0" cellspacing="0"><tr><td align="center">
					<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%%;background:#ffffff;border-radius:10px;padding:28px;">
						<tr><td>
							<h1 style="margin:0 0 18px;font:600 19px/1.35 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#101828;">%s</h1>
							%s
						</td></tr>
					</table>
				</td></tr></table>
			</body></html>',
			$title,
			$content
		);
	}

	/**
	 * Send through `wp_mail`, temporarily switching the content type to HTML.
	 *
	 * The filter is removed again straight away so other plugins' plain-text
	 * mail is unaffected.
	 *
	 * @param array<string, mixed> $email Keys: to, subject, body, headers.
	 */
	private function send( array $email ): bool {
		$html_content_type = static function (): string {
			return 'text/html';
		};

		add_filter( 'wp_mail_content_type', $html_content_type );

		$sent = wp_mail(
			$email['to'] ?? array(),
			(string) ( $email['subject'] ?? '' ),
			(string) ( $email['body'] ?? '' ),
			(array) ( $email['headers'] ?? array() )
		);

		remove_filter( 'wp_mail_content_type', $html_content_type );

		if ( ! $sent && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$to = $email['to'] ?? '';
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				'[Lead Forms] wp_mail() failed for: ' . ( is_array( $to ) ? implode( ', ', $to ) : (string) $to )
			);
		}

		return (bool) $sent;
	}
}
