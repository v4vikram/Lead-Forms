<?php
/**
 * The leads list table.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms\Admin;

use LeadForms\Leads\Lead;
use LeadForms\Leads\LeadRepository;
use WP_List_Table;

defined( 'ABSPATH' ) || exit;

/**
 * Extends core's list table so the leads screen behaves exactly like every
 * other WordPress admin list: sorting, search, bulk actions, pagination and
 * status views all come from the parent.
 *
 * `WP_List_Table` is only loaded on demand by LeadsPage, so this file must not
 * be autoloaded any earlier.
 */
final class LeadsListTable extends WP_List_Table {

	private LeadRepository $leads;

	/** @var array<int, string> Form id => title, for the source column. */
	private array $forms;

	private int $form_id;
	private string $status;
	private string $search;

	/**
	 * @param array<int, string> $forms Available forms.
	 */
	public function __construct( LeadRepository $leads, array $forms, int $form_id, string $status, string $search ) {
		parent::__construct(
			array(
				'singular' => 'lead',
				'plural'   => 'leads',
				'ajax'     => false,
			)
		);

		$this->leads   = $leads;
		$this->forms   = $forms;
		$this->form_id = $form_id;
		$this->status  = $status;
		$this->search  = $search;
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'      => '<input type="checkbox" />',
			'name'    => __( 'Name', 'lead-forms' ),
			'contact' => __( 'Contact', 'lead-forms' ),
			'details' => __( 'Submission', 'lead-forms' ),
			'form'    => __( 'Form', 'lead-forms' ),
			'date'    => __( 'Received', 'lead-forms' ),
		);
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'name' => array( 'name', false ),
			'date' => array( 'created_at', true ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return array(
			'mark_read' => __( 'Mark as read', 'lead-forms' ),
			'mark_spam' => __( 'Mark as spam', 'lead-forms' ),
			'delete'    => __( 'Delete permanently', 'lead-forms' ),
		);
	}

	/**
	 * Status filter links above the table.
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$counts = $this->leads->count_by_status( $this->form_id );
		$total  = ( $counts['new'] ?? 0 ) + ( $counts['read'] ?? 0 );

		$labels = array(
			''      => array( __( 'All', 'lead-forms' ), $total ),
			'new'   => array( __( 'New', 'lead-forms' ), $counts['new'] ?? 0 ),
			'read'  => array( __( 'Read', 'lead-forms' ), $counts['read'] ?? 0 ),
			'spam'  => array( __( 'Spam', 'lead-forms' ), $counts['spam'] ?? 0 ),
			'trash' => array( __( 'Trash', 'lead-forms' ), $counts['trash'] ?? 0 ),
		);

		$views = array();

		foreach ( $labels as $slug => $info ) {
			[ $label, $count ] = $info;

			$url = LeadsPage::url(
				array(
					'form_id' => $this->form_id ?: null,
					'status'  => '' !== $slug ? $slug : null,
				)
			);

			$views[ $slug ?: 'all' ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( $url ),
				$this->status === $slug ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( (int) $count ) )
			);
		}

		return $views;
	}

	/**
	 * Form filter dropdown.
	 *
	 * @param string $which Top or bottom.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which || count( $this->forms ) < 2 ) {
			return;
		}

		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="lf-filter-form"><?php esc_html_e( 'Filter by form', 'lead-forms' ); ?></label>
			<select name="form_id" id="lf-filter-form">
				<option value="0"><?php esc_html_e( 'All forms', 'lead-forms' ); ?></option>
				<?php foreach ( $this->forms as $id => $title ) : ?>
					<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $this->form_id, $id ); ?>>
						<?php echo esc_html( $title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'lead-forms' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Load the rows for the current page.
	 */
	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( 'lead_forms_per_page', 20 );
		$page     = $this->get_pagenum();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( (string) $_GET['orderby'] ) ) : 'created_at';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( (string) $_GET['order'] ) ) : 'desc';
		// phpcs:enable

		$args = array(
			'form_id'  => $this->form_id,
			'status'   => $this->status,
			'search'   => $this->search,
			'orderby'  => $orderby,
			'order'    => $order,
			'per_page' => $per_page,
			'page'     => $page,
		);

		$this->items = $this->leads->query( $args );

		$total = $this->leads->count(
			array(
				'form_id' => $this->form_id,
				'status'  => $this->status,
				'search'  => $this->search,
			)
		);

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'name' );
	}

	/**
	 * Row checkbox.
	 *
	 * @param Lead $item Current lead.
	 */
	public function column_cb( $item ): string {
		return sprintf(
			'<label class="screen-reader-text" for="lead-%1$d">%2$s</label><input type="checkbox" id="lead-%1$d" name="lead_ids[]" value="%1$d" />',
			(int) $item->id,
			esc_html__( 'Select this lead', 'lead-forms' )
		);
	}

	/**
	 * Name column, with row actions.
	 *
	 * @param Lead $item Current lead.
	 */
	public function column_name( Lead $item ): string {
		$name = '' !== $item->name ? $item->name : __( '(no name)', 'lead-forms' );

		$title = sprintf(
			'<strong>%s%s</strong>',
			'new' === $item->status ? '<span class="lf-dot" title="' . esc_attr__( 'Unread', 'lead-forms' ) . '"></span>' : '',
			esc_html( $name )
		);

		$actions = array();

		if ( 'read' !== $item->status ) {
			$actions['read'] = $this->action_link( $item->id, 'mark_read', __( 'Mark read', 'lead-forms' ) );
		}

		if ( 'spam' !== $item->status ) {
			$actions['spam'] = $this->action_link( $item->id, 'mark_spam', __( 'Spam', 'lead-forms' ) );
		}

		$actions['delete'] = $this->action_link( $item->id, 'delete', __( 'Delete', 'lead-forms' ), true );

		return $title . $this->row_actions( $actions );
	}

	/**
	 * E-mail and phone, as clickable links.
	 *
	 * @param Lead $item Current lead.
	 */
	public function column_contact( Lead $item ): string {
		$parts = array();

		if ( '' !== $item->email ) {
			$parts[] = sprintf( '<a href="mailto:%1$s">%1$s</a>', esc_attr( $item->email ) );
		}

		if ( '' !== $item->phone ) {
			$parts[] = sprintf(
				'<a href="tel:%s">%s</a>',
				esc_attr( (string) preg_replace( '/[^0-9+]/', '', $item->phone ) ),
				esc_html( $item->phone )
			);
		}

		return empty( $parts ) ? '&mdash;' : implode( '<br />', $parts );
	}

	/**
	 * All remaining answers, compactly.
	 *
	 * @param Lead $item Current lead.
	 */
	public function column_details( Lead $item ): string {
		$rows = array();

		foreach ( $item->readable_payload() as $label => $value ) {
			if ( '' === trim( $value ) ) {
				continue;
			}

			$rows[] = sprintf(
				'<span class="lf-kv"><span class="lf-kv__k">%s</span> %s</span>',
				esc_html( $label ),
				esc_html( wp_trim_words( $value, 18 ) )
			);
		}

		return empty( $rows ) ? '&mdash;' : '<div class="lf-details">' . implode( '', $rows ) . '</div>';
	}

	/**
	 * Which form the lead came from.
	 *
	 * @param Lead $item Current lead.
	 */
	public function column_form( Lead $item ): string {
		$title = $this->forms[ $item->form_id ] ?? sprintf( '#%d', $item->form_id );

		return esc_html( $title );
	}

	/**
	 * Received date.
	 *
	 * @param Lead $item Current lead.
	 */
	public function column_date( Lead $item ): string {
		return esc_html( $item->created_display() );
	}

	/**
	 * Fallback renderer.
	 *
	 * @param Lead   $item        Current lead.
	 * @param string $column_name Column key.
	 */
	public function column_default( $item, $column_name ): string {
		return isset( $item->{$column_name} ) ? esc_html( (string) $item->{$column_name} ) : '';
	}

	public function no_items(): void {
		esc_html_e( 'No leads yet.', 'lead-forms' );
	}

	/**
	 * Build a nonce-protected single-row action link.
	 */
	private function action_link( int $lead_id, string $action, string $label, bool $destructive = false ): string {
		$url = wp_nonce_url(
			LeadsPage::url(
				array(
					'form_id'   => $this->form_id ?: null,
					'status'    => '' !== $this->status ? $this->status : null,
					'lf_action' => $action,
					'lead'      => $lead_id,
				)
			),
			LeadsPage::ACTION_NONCE . '_' . $lead_id
		);

		return sprintf(
			'<a href="%s"%s>%s</a>',
			esc_url( $url ),
			$destructive ? ' class="submitdelete" onclick="return confirm(\'' . esc_js( __( 'Delete this lead permanently?', 'lead-forms' ) ) . '\');"' : '',
			esc_html( $label )
		);
	}
}
