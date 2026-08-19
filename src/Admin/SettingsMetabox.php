<?php
/**
 * Per-form settings meta boxes.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms\Admin;

use LeadForms\Forms\FormPostType;
use LeadForms\Forms\FormRepository;
use LeadForms\Forms\FormSettings;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Everything that is not a field: notification e-mail, messages, appearance
 * and the anti-spam thresholds.
 */
final class SettingsMetabox {

	private const NONCE_ACTION = 'lead_forms_save_settings';
	private const NONCE_NAME   = 'lead_forms_settings_nonce';

	private FormRepository $forms;

	public function __construct( FormRepository $forms ) {
		$this->forms = $forms;
	}

	public function register_hooks(): void {
		add_action( 'add_meta_boxes_' . FormPostType::POST_TYPE, array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . FormPostType::POST_TYPE, array( $this, 'save' ) );
	}

	/**
	 * Register all three boxes.
	 */
	public function add_meta_boxes(): void {
		add_meta_box(
			'lead-forms-email',
			__( 'E-mail notifications', 'lead-forms' ),
			array( $this, 'render_email' ),
			FormPostType::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'lead-forms-messages',
			__( 'Labels & messages', 'lead-forms' ),
			array( $this, 'render_messages' ),
			FormPostType::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'lead-forms-embed',
			__( 'Embed & appearance', 'lead-forms' ),
			array( $this, 'render_sidebar' ),
			FormPostType::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Load the settings for the post being edited.
	 */
	private function settings( WP_Post $post ): FormSettings {
		$form = $this->forms->find( (int) $post->ID );

		return null !== $form ? $form->settings() : new FormSettings();
	}

	/**
	 * Notification settings.
	 *
	 * @param WP_Post $post Current form.
	 */
	public function render_email( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$settings = $this->settings( $post );
		$form     = $this->forms->find( (int) $post->ID );
		$fields   = null !== $form ? $form->fields() : array();
		?>
		<table class="form-table lf-settings" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Send notifications', 'lead-forms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="lf_settings[notify]" value="1" <?php checked( $settings->flag( 'notify' ) ); ?> />
						<?php esc_html_e( 'E-mail me every new lead', 'lead-forms' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="lf-recipients"><?php esc_html_e( 'Send to', 'lead-forms' ); ?></label>
				</th>
				<td>
					<textarea id="lf-recipients" name="lf_settings[recipients]" rows="3" class="large-text code"
						placeholder="sales@example.com, owner@example.com"><?php echo esc_textarea( $settings->text( 'recipients' ) ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'One or more addresses, separated by commas or new lines. Invalid addresses are dropped when you save. Leave empty to use the site admin address.', 'lead-forms' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf-cc"><?php esc_html_e( 'Cc / Bcc', 'lead-forms' ); ?></label></th>
				<td>
					<input type="text" id="lf-cc" class="large-text code" name="lf_settings[cc]"
						value="<?php echo esc_attr( $settings->text( 'cc' ) ); ?>" placeholder="<?php esc_attr_e( 'Cc addresses', 'lead-forms' ); ?>" />
					<input type="text" class="large-text code" name="lf_settings[bcc]" style="margin-top:6px;"
						value="<?php echo esc_attr( $settings->text( 'bcc' ) ); ?>" placeholder="<?php esc_attr_e( 'Bcc addresses', 'lead-forms' ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf-subject"><?php esc_html_e( 'Subject', 'lead-forms' ); ?></label></th>
				<td>
					<input type="text" id="lf-subject" class="large-text" name="lf_settings[subject]"
						value="<?php echo esc_attr( $settings->text( 'subject' ) ); ?>"
						placeholder="<?php esc_attr_e( 'New lead: {form_title}', 'lead-forms' ); ?>" />
					<p class="description">
						<?php esc_html_e( 'Available tags:', 'lead-forms' ); ?>
						<code>{site_name}</code> <code>{form_title}</code> <code>{date}</code> <code>{time}</code>
						<?php if ( ! empty( $fields ) ) : ?>
							<?php foreach ( $fields as $field ) : ?>
								<code>{<?php echo esc_html( $field->id() ); ?>}</code>
							<?php endforeach; ?>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf-from-name"><?php esc_html_e( 'From', 'lead-forms' ); ?></label></th>
				<td>
					<input type="text" id="lf-from-name" name="lf_settings[from_name]" class="regular-text"
						value="<?php echo esc_attr( $settings->text( 'from_name' ) ); ?>"
						placeholder="<?php esc_attr_e( 'From name', 'lead-forms' ); ?>" />
					<input type="email" name="lf_settings[from_email]" class="regular-text"
						value="<?php echo esc_attr( $settings->text( 'from_email' ) ); ?>"
						placeholder="<?php esc_attr_e( 'From address', 'lead-forms' ); ?>" />
					<p class="description">
						<?php esc_html_e( 'Leave blank to use the WordPress default. Always use an address on this site\'s own domain — sending as the visitor\'s address makes mail fail SPF/DKIM checks. Their address is set as Reply-To instead, so replying still reaches them.', 'lead-forms' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf-reply-to"><?php esc_html_e( 'Reply-To field', 'lead-forms' ); ?></label></th>
				<td>
					<select id="lf-reply-to" name="lf_settings[reply_to_field]">
						<option value=""><?php esc_html_e( 'First e-mail field (automatic)', 'lead-forms' ); ?></option>
						<?php foreach ( $fields as $field ) : ?>
							<?php if ( 'email' === $field->type() ) : ?>
								<option value="<?php echo esc_attr( $field->id() ); ?>" <?php selected( $settings->text( 'reply_to_field' ), $field->id() ); ?>>
									<?php echo esc_html( $field->label() ); ?>
								</option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Auto-reply', 'lead-forms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="lf_settings[autoreply]" value="1" <?php checked( $settings->flag( 'autoreply' ) ); ?> />
						<?php esc_html_e( 'Send a confirmation to the person who submitted', 'lead-forms' ); ?>
					</label>
					<p style="margin-top:8px;">
						<input type="text" class="large-text" name="lf_settings[autoreply_subject]"
							value="<?php echo esc_attr( $settings->text( 'autoreply_subject' ) ); ?>"
							placeholder="<?php esc_attr_e( 'We received your message — {site_name}', 'lead-forms' ); ?>" />
					</p>
					<p>
						<textarea class="large-text" rows="4" name="lf_settings[autoreply_message]"
							placeholder="<?php esc_attr_e( 'Hi {name}, thanks for contacting us…', 'lead-forms' ); ?>"><?php echo esc_textarea( $settings->text( 'autoreply_message' ) ); ?></textarea>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Labels, messages and storage.
	 *
	 * @param WP_Post $post Current form.
	 */
	public function render_messages( WP_Post $post ): void {
		$settings = $this->settings( $post );
		?>
		<table class="form-table lf-settings" role="presentation">
			<tr>
				<th scope="row"><label for="lf-heading"><?php esc_html_e( 'Heading', 'lead-forms' ); ?></label></th>
				<td>
					<input type="text" id="lf-heading" class="large-text" name="lf_settings[heading]"
						value="<?php echo esc_attr( $settings->text( 'heading' ) ); ?>"
						placeholder="<?php esc_attr_e( 'Book Your Visit Now!', 'lead-forms' ); ?>" />
					<input type="text" class="large-text" name="lf_settings[subheading]" style="margin-top:6px;"
						value="<?php echo esc_attr( $settings->text( 'subheading' ) ); ?>"
						placeholder="<?php esc_attr_e( 'Let us know how to get back to you.', 'lead-forms' ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf-submit-label"><?php esc_html_e( 'Button text', 'lead-forms' ); ?></label></th>
				<td>
					<input type="text" id="lf-submit-label" class="regular-text" name="lf_settings[submit_label]"
						value="<?php echo esc_attr( $settings->text( 'submit_label' ) ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf-success"><?php esc_html_e( 'Success message', 'lead-forms' ); ?></label></th>
				<td>
					<textarea id="lf-success" class="large-text" rows="2" name="lf_settings[success_message]"><?php echo esc_textarea( $settings->text( 'success_message' ) ); ?></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf-error"><?php esc_html_e( 'Error message', 'lead-forms' ); ?></label></th>
				<td>
					<textarea id="lf-error" class="large-text" rows="2" name="lf_settings[error_message]"><?php echo esc_textarea( $settings->text( 'error_message' ) ); ?></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lf-redirect"><?php esc_html_e( 'Redirect after submit', 'lead-forms' ); ?></label></th>
				<td>
					<input type="url" id="lf-redirect" class="large-text code" name="lf_settings[redirect_url]"
						value="<?php echo esc_attr( $settings->text( 'redirect_url' ) ); ?>"
						placeholder="https://example.com/thank-you/" />
					<p class="description"><?php esc_html_e( 'Optional. Leave blank to show the success message in place.', 'lead-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Storage', 'lead-forms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="lf_settings[store_leads]" value="1" <?php checked( $settings->flag( 'store_leads' ) ); ?> />
						<?php esc_html_e( 'Save submissions in the Leads screen', 'lead-forms' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Recommended — it is the only copy that survives a mail delivery failure.', 'lead-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Spam protection', 'lead-forms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="lf_settings[honeypot]" value="1" <?php checked( $settings->flag( 'honeypot' ) ); ?> />
						<?php esc_html_e( 'Enable the honeypot field', 'lead-forms' ); ?>
					</label>
					<p style="margin-top:8px;">
						<label>
							<?php esc_html_e( 'Minimum seconds before submit', 'lead-forms' ); ?>
							<input type="number" min="0" max="60" class="small-text" name="lf_settings[min_submit_seconds]"
								value="<?php echo esc_attr( (string) $settings->int( 'min_submit_seconds' ) ); ?>" />
						</label>
					</p>
					<p>
						<label>
							<?php esc_html_e( 'Max submissions per hour, per visitor', 'lead-forms' ); ?>
							<input type="number" min="0" max="200" class="small-text" name="lf_settings[rate_limit_per_hour]"
								value="<?php echo esc_attr( (string) $settings->int( 'rate_limit_per_hour' ) ); ?>" />
						</label>
					</p>
					<p class="description"><?php esc_html_e( 'Set either to 0 to switch that check off.', 'lead-forms' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Shortcode plus colours.
	 *
	 * @param WP_Post $post Current form.
	 */
	public function render_sidebar( WP_Post $post ): void {
		$settings = $this->settings( $post );
		?>
		<p>
			<label for="lf-shortcode-copy"><strong><?php esc_html_e( 'Shortcode', 'lead-forms' ); ?></strong></label>
			<input type="text" id="lf-shortcode-copy" class="widefat code" readonly onfocus="this.select();"
				value="<?php echo esc_attr( sprintf( '[lead_form id="%d"]', (int) $post->ID ) ); ?>" />
			<span class="description"><?php esc_html_e( 'Paste this into any page, post or widget. In the block editor, search for the “Lead Form” block instead.', 'lead-forms' ); ?></span>
		</p>

		<p>
			<label for="lf-theme"><strong><?php esc_html_e( 'Style', 'lead-forms' ); ?></strong></label>
			<select id="lf-theme" name="lf_settings[theme]" class="widefat">
				<option value="classic" <?php selected( $settings->text( 'theme' ), 'classic' ); ?>>
					<?php esc_html_e( 'Classic (coloured panel)', 'lead-forms' ); ?>
				</option>
				<option value="minimal" <?php selected( $settings->text( 'theme' ), 'minimal' ); ?>>
					<?php esc_html_e( 'Minimal (inherits your theme)', 'lead-forms' ); ?>
				</option>
			</select>
		</p>

		<p>
			<label for="lf-panel-color"><strong><?php esc_html_e( 'Panel colour', 'lead-forms' ); ?></strong></label><br />
			<input type="color" id="lf-panel-color" name="lf_settings[panel_color]"
				value="<?php echo esc_attr( $settings->text( 'panel_color' ) ); ?>" />
		</p>

		<p>
			<label for="lf-accent-color"><strong><?php esc_html_e( 'Button colour', 'lead-forms' ); ?></strong></label><br />
			<input type="color" id="lf-accent-color" name="lf_settings[accent_color]"
				value="<?php echo esc_attr( $settings->text( 'accent_color' ) ); ?>" />
		</p>
		<?php
	}

	/**
	 * Persist the settings.
	 *
	 * @param int $post_id Post being saved.
	 */
	public function save( int $post_id ): void {
		if ( ! FormBuilderMetabox::should_save( $post_id, self::NONCE_NAME, self::NONCE_ACTION ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in should_save() above.

		if ( ! isset( $_POST['lf_settings'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised key by key in FormSettings::sanitize().
		$raw = wp_unslash( $_POST['lf_settings'] );

		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->forms->save_settings( $post_id, is_array( $raw ) ? $raw : array() );
	}
}
