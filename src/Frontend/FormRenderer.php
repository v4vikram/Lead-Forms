<?php
/**
 * Turns a Form into accessible HTML.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms\Frontend;

use LeadForms\Forms\Field;
use LeadForms\Forms\FieldRegistry;
use LeadForms\Forms\Form;
use LeadForms\Forms\FormRepository;
use LeadForms\Submission\AdminPostHandler;
use LeadForms\Submission\RestController;
use LeadForms\Submission\SpamGuard;

defined( 'ABSPATH' ) || exit;

/**
 * All markup lives here, escaped at the point of output.
 *
 * The form degrades gracefully: it is a real `<form>` posting to
 * `admin-post.php`, and the script only intercepts it to avoid the page reload.
 */
final class FormRenderer {

	private FormRepository $forms;
	private Assets $assets;

	/** Incremented so several copies of one form on a page keep unique ids. */
	private int $instance = 0;

	public function __construct( FormRepository $forms, Assets $assets ) {
		$this->forms  = $forms;
		$this->assets = $assets;
	}

	/**
	 * Render a form by ID.
	 */
	public function render( int $form_id ): string {
		$form = $this->forms->find( $form_id );

		if ( null === $form ) {
			return $this->notice( __( 'Form not found.', 'lead-forms' ) );
		}

		if ( ! $form->has_fields() ) {
			return $this->notice( __( 'This form has no fields yet.', 'lead-forms' ) );
		}

		$this->assets->enqueue();
		++$this->instance;

		$settings  = $form->settings();
		$uid       = sprintf( 'lf-%d-%d', $form->id(), $this->instance );
		$flash     = AdminPostHandler::consume_flash( $form->id() );
		$errors    = is_array( $flash['errors'] ?? null ) ? $flash['errors'] : array();
		$old       = is_array( $flash['values'] ?? null ) ? $flash['values'] : array();
		$succeeded = ! empty( $flash['success'] );

		$style = sprintf(
			'--lf-accent:%s;--lf-panel:%s;',
			esc_attr( $settings->text( 'accent_color' ) ),
			esc_attr( $settings->text( 'panel_color' ) )
		);

		ob_start();
		?>
		<div class="lf-wrap lf-theme--<?php echo esc_attr( $settings->text( 'theme' ) ); ?>"
			id="lf-form-<?php echo esc_attr( (string) $form->id() ); ?>"
			style="<?php echo esc_attr( $style ); ?>">

			<form class="lf-form"
				method="post"
				novalidate
				action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				data-lf-form
				data-form-id="<?php echo esc_attr( (string) $form->id() ); ?>"
				aria-describedby="<?php echo esc_attr( $uid ); ?>-status">

				<?php $this->render_header( $form ); ?>

				<div class="lf-grid">
					<?php foreach ( $form->fields() as $field ) : ?>
						<?php $this->render_field( $field, $uid, $errors, $old ); ?>
					<?php endforeach; ?>
				</div>

				<?php $this->render_hidden_inputs( $form ); ?>

				<div class="lf-actions">
					<button type="submit" class="lf-submit" data-lf-submit>
						<span class="lf-submit__label"><?php echo esc_html( $this->submit_label( $form ) ); ?></span>
						<span class="lf-spinner" aria-hidden="true"></span>
					</button>
				</div>

				<div class="lf-status<?php echo $flash ? ' is-visible' : ''; ?><?php echo $succeeded ? ' lf-status--success' : ( $flash ? ' lf-status--error' : '' ); ?>"
					id="<?php echo esc_attr( $uid ); ?>-status"
					data-lf-status
					role="status"
					aria-live="polite">
					<?php echo $flash ? esc_html( (string) ( $flash['message'] ?? '' ) ) : ''; ?>
				</div>
			</form>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Heading and sub-heading.
	 */
	private function render_header( Form $form ): void {
		$settings   = $form->settings();
		$heading    = $form->heading();
		$subheading = $settings->text( 'subheading' );

		if ( '' === $heading && '' === $subheading ) {
			return;
		}

		echo '<div class="lf-form__header">';

		if ( '' !== $heading ) {
			printf( '<h2 class="lf-form__title">%s</h2>', esc_html( $heading ) );
		}

		if ( '' !== $subheading ) {
			printf( '<p class="lf-form__subtitle">%s</p>', esc_html( $subheading ) );
		}

		echo '</div>';
	}

	/**
	 * Hidden inputs: routing, CSRF, and the spam guard tokens.
	 */
	private function render_hidden_inputs( Form $form ): void {
		printf(
			'<input type="hidden" name="action" value="%s" />',
			esc_attr( AdminPostHandler::ACTION )
		);

		printf(
			'<input type="hidden" name="form_id" value="%d" />',
			(int) $form->id()
		);

		printf(
			'<input type="hidden" name="lf_nonce" data-lf-nonce value="%s" />',
			esc_attr( wp_create_nonce( RestController::nonce_action( $form->id() ) ) )
		);

		printf(
			'<input type="hidden" name="%s" value="%s" />',
			esc_attr( SpamGuard::TIMESTAMP_FIELD ),
			esc_attr( ( new SpamGuard() )->issue_token() )
		);

		printf(
			'<input type="hidden" name="lf_source_url" value="%s" />',
			esc_url( $this->current_url() )
		);

		if ( $form->settings()->flag( 'honeypot' ) ) {
			// Hidden from people (CSS + aria-hidden) but visible to naive bots.
			printf(
				'<div class="lf-hp" aria-hidden="true">
					<label for="%1$s">%2$s</label>
					<input type="text" id="%1$s" name="%1$s" value="" tabindex="-1" autocomplete="off" />
				</div>',
				esc_attr( SpamGuard::HONEYPOT_FIELD ),
				esc_html__( 'Leave this field empty', 'lead-forms' )
			);
		}
	}

	/**
	 * Render one field with its label, control, help text and error slot.
	 *
	 * @param Field                 $field  Field definition.
	 * @param string                $uid    Unique prefix for element ids.
	 * @param array<string, string> $errors Server-side errors from a no-JS post.
	 * @param array<string, mixed>  $old    Previously submitted values.
	 */
	private function render_field( Field $field, string $uid, array $errors, array $old ): void {
		$id       = $uid . '-' . $field->id();
		$error    = (string) ( $errors[ $field->id() ] ?? '' );
		$value    = $old[ $field->id() ] ?? '';
		$help_id  = $id . '-help';
		$error_id = $id . '-error';

		$described = array();

		if ( '' !== $field->help() ) {
			$described[] = $help_id;
		}

		$described[] = $error_id;

		$classes = array(
			'lf-field',
			'lf-field--' . $field->type(),
			'lf-field--' . $field->width(),
		);

		if ( '' !== $error ) {
			$classes[] = 'has-error';
		}

		$grouped = in_array( $field->type(), array( 'radio', 'checkbox' ), true );

		printf( '<div class="%s" data-lf-field="%s">', esc_attr( implode( ' ', $classes ) ), esc_attr( $field->id() ) );

		// Radio and checkbox groups need a fieldset/legend rather than a label.
		if ( $grouped ) {
			echo '<fieldset class="lf-fieldset">';
			printf( '<legend class="lf-label">%s</legend>', $this->label_html( $field ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in label_html().
		} elseif ( 'acceptance' !== $field->type() ) {
			printf( '<label class="lf-label" for="%s">%s</label>', esc_attr( $id ), $this->label_html( $field ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in label_html().
		}

		$this->render_control( $field, $id, $value, $described, $error );

		if ( '' !== $field->help() ) {
			printf( '<p class="lf-help" id="%s">%s</p>', esc_attr( $help_id ), esc_html( $field->help() ) );
		}

		printf(
			'<p class="lf-error" id="%s" data-lf-error role="alert">%s</p>',
			esc_attr( $error_id ),
			esc_html( $error )
		);

		if ( $grouped ) {
			echo '</fieldset>';
		}

		echo '</div>';
	}

	/**
	 * The label text plus the required marker.
	 */
	private function label_html( Field $field ): string {
		$html = esc_html( $field->label() );

		if ( $field->is_required() ) {
			$html .= sprintf(
				' <span class="lf-required" aria-hidden="true">*</span><span class="screen-reader-text"> %s</span>',
				esc_html__( '(required)', 'lead-forms' )
			);
		}

		return $html;
	}

	/**
	 * Render the input control itself.
	 *
	 * @param Field    $field     Field definition.
	 * @param string   $id        Element id.
	 * @param mixed    $value     Previously submitted value.
	 * @param string[] $described IDs for aria-describedby.
	 * @param string   $error     Current error message.
	 */
	private function render_control( Field $field, string $id, $value, array $described, string $error ): void {
		$common = sprintf(
			' id="%s" name="%s" aria-describedby="%s"%s%s%s',
			esc_attr( $id ),
			esc_attr( $field->input_name() ),
			esc_attr( implode( ' ', $described ) ),
			$field->is_required() ? ' required aria-required="true"' : '',
			'' !== $error ? ' aria-invalid="true"' : '',
			$this->constraint_attributes( $field )
		);

		switch ( $field->type() ) {
			case 'textarea':
				printf(
					'<textarea class="lf-input lf-textarea" rows="5" placeholder="%s"%s>%s</textarea>',
					esc_attr( $field->placeholder() ),
					$common, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from escaped parts above.
					esc_textarea( is_array( $value ) ? '' : (string) $value )
				);
				break;

			case 'select':
				printf( '<select class="lf-input lf-select"%s>', $common ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

				printf(
					'<option value="">%s</option>',
					esc_html( '' !== $field->placeholder() ? $field->placeholder() : __( '— Select —', 'lead-forms' ) )
				);

				foreach ( $field->options() as $option ) {
					printf(
						'<option value="%s"%s>%s</option>',
						esc_attr( $option ),
						selected( (string) $value, $option, false ),
						esc_html( $option )
					);
				}

				echo '</select>';
				break;

			case 'radio':
			case 'checkbox':
				$multiple = $field->accepts_multiple();
				$selected = is_array( $value ) ? array_map( 'strval', $value ) : array( (string) $value );

				echo '<div class="lf-choices">';

				foreach ( $field->options() as $index => $option ) {
					$choice_id = $id . '-' . $index;

					printf(
						'<label class="lf-choice" for="%1$s">
							<input type="%2$s" id="%1$s" name="%3$s" value="%4$s"%5$s%6$s />
							<span class="lf-choice__text">%7$s</span>
						</label>',
						esc_attr( $choice_id ),
						$multiple ? 'checkbox' : 'radio',
						esc_attr( $field->input_name() ),
						esc_attr( $option ),
						checked( in_array( $option, $selected, true ), true, false ),
						// Requiring every checkbox in a group would be wrong, so
						// only radios carry the attribute.
						( $field->is_required() && ! $multiple ) ? ' required' : '',
						esc_html( $option )
					);
				}

				echo '</div>';
				break;

			case 'acceptance':
				printf(
					'<label class="lf-choice lf-choice--consent" for="%s">
						<input type="checkbox" value="1"%s%s />
						<span class="lf-choice__text">%s</span>
					</label>',
					esc_attr( $id ),
					$common, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					checked( '' !== (string) $value, true, false ),
					$this->label_html( $field ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in label_html().
				);
				break;

			default:
				$type = (string) \LeadForms\Forms\FieldRegistry::get( $field->type(), 'input_type', 'text' );
				$auto = (string) \LeadForms\Forms\FieldRegistry::get( $field->type(), 'autocomplete', 'off' );

				printf(
					'<input class="lf-input" type="%s" value="%s" placeholder="%s" autocomplete="%s"%s />',
					esc_attr( '' !== $type ? $type : 'text' ),
					esc_attr( is_array( $value ) ? '' : (string) $value ),
					esc_attr( $field->placeholder() ),
					esc_attr( $auto ),
					$common // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
				break;
		}
	}

	/**
	 * Mirror the field's validation rules as HTML constraint attributes.
	 *
	 * The browser then catches most mistakes before a request is made; the
	 * server still enforces every rule, since these are trivially bypassed.
	 */
	private function constraint_attributes( Field $field ): string {
		$attributes = array();
		$type       = $field->type();
		$min        = $field->min();
		$max        = $field->max();

		if ( FieldRegistry::has_rule( $type, 'length' ) ) {
			if ( '' !== $min ) {
				$attributes[] = sprintf( 'minlength="%s"', esc_attr( $min ) );
			}

			if ( '' !== $max ) {
				$attributes[] = sprintf( 'maxlength="%s"', esc_attr( $max ) );
			}
		}

		if ( FieldRegistry::has_rule( $type, 'range' ) ) {
			if ( '' !== $min ) {
				$attributes[] = sprintf( 'min="%s"', esc_attr( $min ) );
			}

			if ( '' !== $max ) {
				$attributes[] = sprintf( 'max="%s"', esc_attr( $max ) );
			}
		}

		$pattern = $field->html_pattern();

		// `pattern` is only valid on these input types; anywhere else the
		// browser ignores it, so it is left off rather than emitted as noise.
		if ( '' !== $pattern && in_array( $type, array( 'text', 'tel', 'url', 'email' ), true ) ) {
			$attributes[] = sprintf( 'pattern="%s"', esc_attr( $pattern ) );

			// Without a title, browsers show a bare "match the requested
			// format" with no hint of what the format is.
			$hint = '' !== $field->error_message()
				? $field->error_message()
				: (string) ( FieldRegistry::patterns()[ $field->pattern() ]['message'] ?? '' );

			if ( '' !== $hint ) {
				$attributes[] = sprintf( 'title="%s"', esc_attr( $hint ) );
			}
		}

		if ( 'tel' === $type && in_array( $field->pattern(), array( 'digits', 'mobile_in' ), true ) ) {
			$attributes[] = 'inputmode="numeric"';
		}

		return empty( $attributes ) ? '' : ' ' . implode( ' ', $attributes );
	}

	/**
	 * Submit button text.
	 */
	private function submit_label( Form $form ): string {
		$label = $form->settings()->text( 'submit_label' );

		return '' !== $label ? $label : __( 'Submit', 'lead-forms' );
	}

	/**
	 * A small inline notice, only ever shown to users who can fix the problem.
	 */
	private function notice( string $message ): string {
		if ( ! current_user_can( \LeadForms\Plugin::capability() ) ) {
			return '';
		}

		return sprintf( '<p class="lf-notice">%s</p>', esc_html( $message ) );
	}

	/**
	 * Canonical URL of the page holding the form.
	 */
	private function current_url(): string {
		$permalink = is_singular() ? get_permalink() : '';

		if ( is_string( $permalink ) && '' !== $permalink ) {
			return $permalink;
		}

		global $wp;

		return home_url( add_query_arg( array(), $wp->request ?? '' ) );
	}
}
