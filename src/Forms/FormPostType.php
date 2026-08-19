<?php
/**
 * The `lead_form` custom post type.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms\Forms;

use LeadForms\Leads\LeadRepository;
use LeadForms\Plugin;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Forms are stored as posts: it gives us the list table, revisions of the
 * title, capabilities and trash handling for free.
 */
final class FormPostType {

	public const POST_TYPE     = 'lead_form';
	public const META_FIELDS   = '_lead_forms_fields';
	public const META_SETTINGS = '_lead_forms_settings';

	/**
	 * Attach WordPress hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'enter_title_here', array( $this, 'title_placeholder' ), 10, 2 );
	}

	/**
	 * Register the post type and its meta.
	 */
	public function register(): void {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Lead Forms', 'lead-forms' ),
					'singular_name'      => __( 'Form', 'lead-forms' ),
					'menu_name'          => __( 'Lead Forms', 'lead-forms' ),
					'add_new'            => __( 'Add Form', 'lead-forms' ),
					'add_new_item'       => __( 'Add New Form', 'lead-forms' ),
					'edit_item'          => __( 'Edit Form', 'lead-forms' ),
					'new_item'           => __( 'New Form', 'lead-forms' ),
					'view_item'          => __( 'View Form', 'lead-forms' ),
					'search_items'       => __( 'Search Forms', 'lead-forms' ),
					'not_found'          => __( 'No forms yet.', 'lead-forms' ),
					'not_found_in_trash' => __( 'No forms in the trash.', 'lead-forms' ),
				),
				// Forms are embedded, never browsed directly, so no public archive.
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'menu_position'       => 26,
				'menu_icon'           => 'dashicons-feedback',
				'capability_type'     => 'page',
				'map_meta_cap'        => true,
				'hierarchical'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title' ),
			)
		);

		// Registering meta gives us a documented schema and, more importantly,
		// an auth_callback so REST/meta box writes are capability checked.
		$auth = static function (): bool {
			return current_user_can( Plugin::capability() );
		};

		register_post_meta(
			self::POST_TYPE,
			self::META_FIELDS,
			array(
				'type'          => 'array',
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => $auth,
				'default'       => array(),
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_SETTINGS,
			array(
				'type'          => 'object',
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => $auth,
				'default'       => array(),
			)
		);
	}

	/**
	 * Columns for the forms list table.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		$date = $columns['date'] ?? '';
		unset( $columns['date'] );

		$columns['lf_shortcode'] = __( 'Shortcode', 'lead-forms' );
		$columns['lf_fields']    = __( 'Fields', 'lead-forms' );
		$columns['lf_leads']     = __( 'Leads', 'lead-forms' );

		if ( '' !== $date ) {
			$columns['date'] = $date;
		}

		return $columns;
	}

	/**
	 * Render a custom column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'lf_shortcode':
				printf(
					'<input type="text" class="lf-shortcode-field" readonly value="%s" onfocus="this.select();" />',
					esc_attr( sprintf( '[lead_form id="%d"]', $post_id ) )
				);
				break;

			case 'lf_fields':
				$fields = get_post_meta( $post_id, self::META_FIELDS, true );
				echo esc_html( (string) ( is_array( $fields ) ? count( $fields ) : 0 ) );
				break;

			case 'lf_leads':
				$count = ( new LeadRepository() )->count( array( 'form_id' => $post_id ) );
				$url   = add_query_arg(
					array(
						'post_type' => self::POST_TYPE,
						'page'      => 'lead-forms-leads',
						'form_id'   => $post_id,
					),
					admin_url( 'edit.php' )
				);

				printf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( number_format_i18n( $count ) ) );
				break;
		}
	}

	/**
	 * Add a "Leads" link to each row.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param WP_Post               $post    Current post.
	 * @return array<string, string>
	 */
	public function row_actions( array $actions, WP_Post $post ): array {
		if ( self::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		$url = add_query_arg(
			array(
				'post_type' => self::POST_TYPE,
				'page'      => 'lead-forms-leads',
				'form_id'   => $post->ID,
			),
			admin_url( 'edit.php' )
		);

		$actions['lf_leads'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Leads', 'lead-forms' )
		);

		return $actions;
	}

	/**
	 * Friendlier placeholder in the title field.
	 *
	 * @param string  $text Existing placeholder.
	 * @param WP_Post $post Current post.
	 */
	public function title_placeholder( string $text, WP_Post $post ): string {
		return self::POST_TYPE === $post->post_type
			? __( 'Form name (internal, e.g. Service Enquiry)', 'lead-forms' )
			: $text;
	}
}
