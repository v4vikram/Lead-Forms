<?php
/**
 * Loading and saving form definitions.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms\Forms;

use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * The only place that knows forms live in post meta.
 */
final class FormRepository {

	/** @var array<int, Form|null> Request-level cache. */
	private array $cache = array();

	/**
	 * Load a form by ID.
	 *
	 * @param int $form_id Post ID.
	 * @return Form|null Null when missing, trashed or of the wrong type.
	 */
	public function find( int $form_id ): ?Form {
		if ( array_key_exists( $form_id, $this->cache ) ) {
			return $this->cache[ $form_id ];
		}

		$post = get_post( $form_id );

		$missing = ! $post instanceof WP_Post || FormPostType::POST_TYPE !== $post->post_type;

		// Drafts stay invisible to visitors but remain previewable by an editor.
		$hidden = ! $missing && 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $form_id );

		if ( $missing || $hidden ) {
			$this->cache[ $form_id ] = null;

			return null;
		}

		$raw_fields = get_post_meta( $form_id, FormPostType::META_FIELDS, true );
		$raw_config = get_post_meta( $form_id, FormPostType::META_SETTINGS, true );

		$form = new Form(
			$form_id,
			(string) $post->post_title,
			$this->hydrate_fields( is_array( $raw_fields ) ? $raw_fields : array() ),
			new FormSettings( is_array( $raw_config ) ? $raw_config : array() )
		);

		/**
		 * Filter a form right after it is loaded.
		 *
		 * @param Form $form The loaded form.
		 */
		$form = apply_filters( 'lead_forms_load_form', $form );

		$this->cache[ $form_id ] = $form;

		return $form;
	}

	/**
	 * The first published form, used when a shortcode omits an ID.
	 */
	public function find_default(): ?Form {
		$ids = $this->list_ids();

		return empty( $ids ) ? null : $this->find( (int) array_key_first( $ids ) );
	}

	/**
	 * All published forms as `id => title`, for dropdowns.
	 *
	 * @return array<int, string>
	 */
	public function list_ids(): array {
		$query = new WP_Query(
			array(
				'post_type'              => FormPostType::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => 100,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$forms = array();

		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$forms[ (int) $post->ID ] = (string) $post->post_title;
			}
		}

		return $forms;
	}

	/**
	 * Persist the field list for a form.
	 *
	 * @param int                             $form_id Post ID.
	 * @param array<int, array<string,mixed>> $raw     Raw field rows from the builder.
	 */
	public function save_fields( int $form_id, array $raw ): void {
		$fields = array();
		$used   = array();

		foreach ( array_values( $raw ) as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$field = Field::from_array( $row, $index );

			if ( ! $field instanceof Field ) {
				continue;
			}

			$data = $field->to_array();

			// Two fields sharing a key would overwrite each other in the
			// payload, so suffix any collision.
			if ( isset( $used[ $data['id'] ] ) ) {
				$suffix = 2;

				while ( isset( $used[ $data['id'] . '_' . $suffix ] ) ) {
					++$suffix;
				}

				$data['id'] = $data['id'] . '_' . $suffix;
			}

			$used[ $data['id'] ] = true;
			$fields[]            = $data;
		}

		update_post_meta( $form_id, FormPostType::META_FIELDS, $fields );
		unset( $this->cache[ $form_id ] );
	}

	/**
	 * Persist the settings for a form.
	 *
	 * @param int                  $form_id Post ID.
	 * @param array<string, mixed> $raw     Raw settings from the meta box.
	 */
	public function save_settings( int $form_id, array $raw ): void {
		update_post_meta( $form_id, FormPostType::META_SETTINGS, FormSettings::sanitize( $raw ) );
		unset( $this->cache[ $form_id ] );
	}

	/**
	 * Turn stored arrays into Field objects.
	 *
	 * @param array<int, mixed> $raw Stored rows.
	 * @return Field[]
	 */
	private function hydrate_fields( array $raw ): array {
		$fields = array();

		foreach ( array_values( $raw ) as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$field = Field::from_array( $row, $index );

			if ( $field instanceof Field ) {
				$fields[] = $field;
			}
		}

		return $fields;
	}
}
