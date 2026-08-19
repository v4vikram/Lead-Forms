<?php
/**
 * The field builder meta box.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms\Admin;

use LeadForms\Forms\Field;
use LeadForms\Forms\FieldRegistry;
use LeadForms\Forms\FormPostType;
use LeadForms\Forms\FormRepository;
use LeadForms\Plugin;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * A repeater UI for the form's fields.
 *
 * Rows are plain inputs named `lf_fields[i][...]`, so the whole thing submits
 * with the post and keeps working if the JavaScript fails to load.
 */
final class FormBuilderMetabox {

	private const NONCE_ACTION = 'lead_forms_save_fields';
	private const NONCE_NAME   = 'lead_forms_fields_nonce';

	private Plugin $plugin;
	private FormRepository $forms;

	public function __construct( Plugin $plugin, FormRepository $forms ) {
		$this->plugin = $plugin;
		$this->forms  = $forms;
	}

	public function register_hooks(): void {
		add_action( 'add_meta_boxes_' . FormPostType::POST_TYPE, array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . FormPostType::POST_TYPE, array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'before_delete_post', array( $this, 'cleanup_leads' ) );
	}

	/**
	 * Register the meta box.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'lead-forms-builder',
			__( 'Form fields', 'lead-forms' ),
			array( $this, 'render' ),
			FormPostType::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Load admin CSS/JS only on the form editing screen.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( null === $screen || FormPostType::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'lead-forms-admin',
			$this->plugin->url( 'assets/css/admin.css' ),
			array(),
			$this->plugin->version()
		);

		wp_enqueue_script(
			'lead-forms-admin',
			$this->plugin->url( 'assets/js/admin-builder.js' ),
			array( 'wp-i18n' ),
			$this->plugin->version(),
			true
		);

		wp_localize_script(
			'lead-forms-admin',
			'leadFormsBuilder',
			array(
				'typesWithOptions' => FieldRegistry::types_with_options(),
				'typesByRule'      => FieldRegistry::types_by_rule(),
				'i18n'             => array(
					'confirmRemove' => __( 'Remove this field?', 'lead-forms' ),
					'untitled'      => __( 'Untitled field', 'lead-forms' ),
				),
			)
		);
	}

	/**
	 * Render the builder.
	 *
	 * @param WP_Post $post Current form.
	 */
	public function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$form   = $this->forms->find( (int) $post->ID );
		$fields = null !== $form ? $form->fields() : array();
		?>
		<div class="lf-builder" data-lf-builder>
			<p class="lf-builder__intro">
				<?php esc_html_e( 'Add the questions you want to ask. The field key is generated from the label and is what you use in e-mail merge tags.', 'lead-forms' ); ?>
			</p>

			<div class="lf-builder__rows" data-lf-rows>
				<?php foreach ( $fields as $index => $field ) : ?>
					<?php $this->render_row( (string) $index, $field ); ?>
				<?php endforeach; ?>
			</div>

			<p class="lf-builder__empty" data-lf-empty <?php echo empty( $fields ) ? '' : 'hidden'; ?>>
				<?php esc_html_e( 'No fields yet — add your first one below.', 'lead-forms' ); ?>
			</p>

			<div class="lf-builder__add">
				<label class="screen-reader-text" for="lf-add-type"><?php esc_html_e( 'Field type', 'lead-forms' ); ?></label>
				<select id="lf-add-type" data-lf-add-type>
					<?php foreach ( FieldRegistry::all() as $slug => $type ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( (string) $type['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button button-secondary" data-lf-add>
					<?php esc_html_e( '+ Add field', 'lead-forms' ); ?>
				</button>
			</div>

			<template data-lf-template>
				<?php $this->render_row( '__INDEX__', null ); ?>
			</template>
		</div>
		<?php
	}

	/**
	 * Render a single repeater row.
	 *
	 * @param string     $index Row index, or the `__INDEX__` placeholder.
	 * @param Field|null $field Existing field, or null for a blank row.
	 */
	private function render_row( string $index, ?Field $field ): void {
		$name = 'lf_fields[' . $index . ']';
		$uid  = 'lf-row-' . $index;

		$type     = null !== $field ? $field->type() : 'text';
		$label    = null !== $field ? $field->label() : '';
		$key      = null !== $field ? $field->id() : '';
		$required = null !== $field && $field->is_required();
		$half     = null !== $field && 'half' === $field->width();
		$options  = null !== $field ? implode( "\n", $field->options() ) : '';
		?>
		<div class="lf-row" data-lf-row>
			<div class="lf-row__head">
				<button type="button" class="lf-row__toggle" data-lf-toggle aria-expanded="false">
					<span class="lf-row__title" data-lf-row-title>
						<?php echo esc_html( '' !== $label ? $label : __( 'Untitled field', 'lead-forms' ) ); ?>
					</span>
					<code class="lf-row__key" data-lf-row-key><?php echo esc_html( $key ); ?></code>
					<span class="lf-row__type" data-lf-row-type>
						<?php echo esc_html( (string) FieldRegistry::get( $type, 'label', $type ) ); ?>
					</span>
				</button>
				<span class="lf-row__controls">
					<button type="button" class="button-link lf-row__move" data-lf-move="up" aria-label="<?php esc_attr_e( 'Move up', 'lead-forms' ); ?>">&uarr;</button>
					<button type="button" class="button-link lf-row__move" data-lf-move="down" aria-label="<?php esc_attr_e( 'Move down', 'lead-forms' ); ?>">&darr;</button>
					<button type="button" class="button-link lf-row__remove" data-lf-remove aria-label="<?php esc_attr_e( 'Remove field', 'lead-forms' ); ?>">&times;</button>
				</span>
			</div>

			<div class="lf-row__body" hidden>
				<p class="lf-row__control">
					<label for="<?php echo esc_attr( $uid . '-label' ); ?>"><?php esc_html_e( 'Label', 'lead-forms' ); ?></label>
					<input type="text" class="widefat" id="<?php echo esc_attr( $uid . '-label' ); ?>"
						name="<?php echo esc_attr( $name . '[label]' ); ?>"
						value="<?php echo esc_attr( $label ); ?>" data-lf-label-input />
				</p>

				<p class="lf-row__control">
					<label for="<?php echo esc_attr( $uid . '-key' ); ?>"><?php esc_html_e( 'Field key', 'lead-forms' ); ?></label>
					<input type="text" class="widefat code" id="<?php echo esc_attr( $uid . '-key' ); ?>"
						name="<?php echo esc_attr( $name . '[id]' ); ?>"
						value="<?php echo esc_attr( $key ); ?>" data-lf-key-input />
					<span class="description">
						<?php esc_html_e( 'Filled in from the label. Used in merge tags and stored with every lead — changing it on a live form means older leads keep the old key.', 'lead-forms' ); ?>
					</span>
				</p>

				<p class="lf-row__control">
					<label for="<?php echo esc_attr( $uid . '-type' ); ?>"><?php esc_html_e( 'Type', 'lead-forms' ); ?></label>
					<select id="<?php echo esc_attr( $uid . '-type' ); ?>" name="<?php echo esc_attr( $name . '[type]' ); ?>" data-lf-type-input>
						<?php foreach ( FieldRegistry::all() as $slug => $definition ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $type, $slug ); ?>>
								<?php echo esc_html( (string) $definition['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>

				<p class="lf-row__control" data-lf-options <?php echo FieldRegistry::get( $type, 'has_options', false ) ? '' : 'hidden'; ?>>
					<label for="<?php echo esc_attr( $uid . '-options' ); ?>"><?php esc_html_e( 'Choices (one per line)', 'lead-forms' ); ?></label>
					<textarea class="widefat" rows="4" id="<?php echo esc_attr( $uid . '-options' ); ?>"
						name="<?php echo esc_attr( $name . '[options]' ); ?>"><?php echo esc_textarea( $options ); ?></textarea>
				</p>

				<p class="lf-row__control">
					<label for="<?php echo esc_attr( $uid . '-placeholder' ); ?>"><?php esc_html_e( 'Placeholder', 'lead-forms' ); ?></label>
					<input type="text" class="widefat" id="<?php echo esc_attr( $uid . '-placeholder' ); ?>"
						name="<?php echo esc_attr( $name . '[placeholder]' ); ?>"
						value="<?php echo esc_attr( null !== $field ? $field->placeholder() : '' ); ?>" />
				</p>

				<p class="lf-row__control">
					<label for="<?php echo esc_attr( $uid . '-help' ); ?>"><?php esc_html_e( 'Help text', 'lead-forms' ); ?></label>
					<input type="text" class="widefat" id="<?php echo esc_attr( $uid . '-help' ); ?>"
						name="<?php echo esc_attr( $name . '[help]' ); ?>"
						value="<?php echo esc_attr( null !== $field ? $field->help() : '' ); ?>" />
				</p>

				<p class="lf-row__control lf-row__control--inline">
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $name . '[required]' ); ?>" value="1" <?php checked( $required ); ?> />
						<?php esc_html_e( 'Required', 'lead-forms' ); ?>
					</label>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $name . '[width]' ); ?>" value="half" <?php checked( $half ); ?> />
						<?php esc_html_e( 'Half width (two per row)', 'lead-forms' ); ?>
					</label>
				</p>

				<?php $this->render_validation( $name, $uid, $field, $type ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * The validation controls for one row.
	 *
	 * Every control is always present in the markup and shown or hidden by
	 * type, so switching type never loses what was already typed — and the
	 * server drops any rule that does not apply to the saved type.
	 *
	 * @param string     $name  Input name prefix.
	 * @param string     $uid   Unique id prefix.
	 * @param Field|null $field Existing field, or null for a blank row.
	 * @param string     $type  Current field type.
	 */
	private function render_validation( string $name, string $uid, ?Field $field, string $type ): void {
		$min      = null !== $field ? $field->min() : '';
		$max      = null !== $field ? $field->max() : '';
		$pattern  = null !== $field ? $field->pattern() : '';
		$custom   = null !== $field ? $field->pattern_custom() : '';
		$message  = null !== $field ? $field->error_message() : '';
		$is_date  = 'date' === $type;
		$bound_ui = $is_date ? 'date' : 'number';

		// All three blocks reuse the [min]/[max] names, so the ones that do not
		// apply must be disabled as well as hidden — a disabled input is not
		// submitted, which keeps exactly one pair live.
		$off = array();

		foreach ( array( 'length', 'range', 'count' ) as $rule ) {
			$off[ $rule ] = FieldRegistry::has_rule( $type, $rule ) ? '' : ' disabled';
		}
		?>
		<fieldset class="lf-row__validation">
			<legend><?php esc_html_e( 'Validation', 'lead-forms' ); ?></legend>

			<div class="lf-row__control lf-rule" data-lf-rule="length"
				<?php echo FieldRegistry::has_rule( $type, 'length' ) ? '' : 'hidden'; ?>>
				<span class="lf-rule__label"><?php esc_html_e( 'Length', 'lead-forms' ); ?></span>
				<label for="<?php echo esc_attr( $uid . '-min' ); ?>"><?php esc_html_e( 'Min characters', 'lead-forms' ); ?></label>
				<input type="number" min="0" step="1" class="small-text" id="<?php echo esc_attr( $uid . '-min' ); ?>"
					name="<?php echo esc_attr( $name . '[min]' ); ?>" value="<?php echo esc_attr( $min ); ?>" data-lf-min<?php echo esc_attr( $off['length'] ); ?> />
				<label for="<?php echo esc_attr( $uid . '-max' ); ?>"><?php esc_html_e( 'Max characters', 'lead-forms' ); ?></label>
				<input type="number" min="0" step="1" class="small-text" id="<?php echo esc_attr( $uid . '-max' ); ?>"
					name="<?php echo esc_attr( $name . '[max]' ); ?>" value="<?php echo esc_attr( $max ); ?>" data-lf-max<?php echo esc_attr( $off['length'] ); ?> />
			</div>

			<div class="lf-row__control lf-rule" data-lf-rule="range"
				<?php echo FieldRegistry::has_rule( $type, 'range' ) ? '' : 'hidden'; ?>>
				<span class="lf-rule__label"><?php esc_html_e( 'Allowed range', 'lead-forms' ); ?></span>
				<label><?php esc_html_e( 'Lowest', 'lead-forms' ); ?></label>
				<input type="<?php echo esc_attr( $bound_ui ); ?>" class="<?php echo $is_date ? '' : 'small-text'; ?>"
					name="<?php echo esc_attr( $name . '[min]' ); ?>" value="<?php echo esc_attr( $min ); ?>" data-lf-min disabled />
				<label><?php esc_html_e( 'Highest', 'lead-forms' ); ?></label>
				<input type="<?php echo esc_attr( $bound_ui ); ?>" class="<?php echo $is_date ? '' : 'small-text'; ?>"
					name="<?php echo esc_attr( $name . '[max]' ); ?>" value="<?php echo esc_attr( $max ); ?>" data-lf-max disabled />
			</div>

			<div class="lf-row__control lf-rule" data-lf-rule="count"
				<?php echo FieldRegistry::has_rule( $type, 'count' ) ? '' : 'hidden'; ?>>
				<span class="lf-rule__label"><?php esc_html_e( 'Choices', 'lead-forms' ); ?></span>
				<label><?php esc_html_e( 'Min selected', 'lead-forms' ); ?></label>
				<input type="number" min="0" step="1" class="small-text"
					name="<?php echo esc_attr( $name . '[min]' ); ?>" value="<?php echo esc_attr( $min ); ?>" data-lf-min disabled />
				<label><?php esc_html_e( 'Max selected', 'lead-forms' ); ?></label>
				<input type="number" min="0" step="1" class="small-text"
					name="<?php echo esc_attr( $name . '[max]' ); ?>" value="<?php echo esc_attr( $max ); ?>" data-lf-max disabled />
			</div>

			<div class="lf-row__control lf-rule" data-lf-rule="pattern"
				<?php echo FieldRegistry::has_rule( $type, 'pattern' ) ? '' : 'hidden'; ?>>
				<span class="lf-rule__label"><?php esc_html_e( 'Format', 'lead-forms' ); ?></span>
				<select name="<?php echo esc_attr( $name . '[pattern]' ); ?>" data-lf-pattern-input>
					<option value=""><?php esc_html_e( 'Anything', 'lead-forms' ); ?></option>
					<?php foreach ( FieldRegistry::patterns() as $slug => $preset ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $pattern, $slug ); ?>>
							<?php echo esc_html( (string) $preset['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="lf-row__control lf-rule" data-lf-custom-pattern <?php echo 'custom' === $pattern ? '' : 'hidden'; ?>>
				<label for="<?php echo esc_attr( $uid . '-regex' ); ?>"><?php esc_html_e( 'Custom pattern', 'lead-forms' ); ?></label>
				<input type="text" class="widefat code" id="<?php echo esc_attr( $uid . '-regex' ); ?>"
					name="<?php echo esc_attr( $name . '[pattern_custom]' ); ?>"
					value="<?php echo esc_attr( $custom ); ?>" placeholder="^[A-Z]{2}[0-9]{4}$" />
				<span class="description">
					<?php esc_html_e( 'A regular expression without delimiters. Rejected on save if it does not compile.', 'lead-forms' ); ?>
				</span>
			</div>

			<div class="lf-row__control">
				<label for="<?php echo esc_attr( $uid . '-error' ); ?>"><?php esc_html_e( 'Custom error message', 'lead-forms' ); ?></label>
				<input type="text" class="widefat" id="<?php echo esc_attr( $uid . '-error' ); ?>"
					name="<?php echo esc_attr( $name . '[error_message]' ); ?>"
					value="<?php echo esc_attr( $message ); ?>"
					placeholder="<?php esc_attr_e( 'Leave blank to use the built-in message', 'lead-forms' ); ?>" />
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Persist the submitted fields.
	 *
	 * @param int $post_id Post being saved.
	 */
	public function save( int $post_id ): void {
		// should_save() is what verifies the nonce; PHPCS cannot follow the
		// call, hence the annotations on the $_POST reads below.
		if ( ! self::should_save( $post_id, self::NONCE_NAME, self::NONCE_ACTION ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in should_save() above.

		// An absent key means the meta box was not rendered (e.g. a quick edit),
		// which must not wipe the existing configuration.
		if ( ! isset( $_POST['lf_fields'] ) ) {
			return;
		}

		// Each row is sanitised field by field in Field::from_array(); there is
		// no single scalar here to run through a sanitiser at this point.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per value in Field::from_array().
		$raw = wp_unslash( $_POST['lf_fields'] );

		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->forms->save_fields( $post_id, is_array( $raw ) ? $raw : array() );
	}

	/**
	 * Delete a form's leads when the form itself is permanently deleted.
	 *
	 * @param int $post_id Post being deleted.
	 */
	public function cleanup_leads( int $post_id ): void {
		if ( FormPostType::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		( new \LeadForms\Leads\LeadRepository() )->delete_for_form( $post_id );
	}

	/**
	 * The standard save_post guard clauses.
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $nonce_name   Nonce field name.
	 * @param string $nonce_action Nonce action.
	 */
	public static function should_save( int $post_id, string $nonce_name, string $nonce_action ): bool {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return false;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		$nonce = isset( $_POST[ $nonce_name ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $nonce_name ] ) ) : '';

		return (bool) wp_verify_nonce( $nonce, $nonce_action );
	}
}
