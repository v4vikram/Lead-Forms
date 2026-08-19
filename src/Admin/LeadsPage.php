<?php
/**
 * The Leads admin screen.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms\Admin;

use LeadForms\Forms\FormPostType;
use LeadForms\Forms\FormRepository;
use LeadForms\Leads\Lead;
use LeadForms\Leads\LeadRepository;
use LeadForms\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Lists stored leads, handles row/bulk actions and CSV export.
 */
final class LeadsPage {

	public const SLUG         = 'lead-forms-leads';
	public const ACTION_NONCE = 'lead_forms_lead_action';
	public const EXPORT_NONCE = 'lead_forms_export';

	private Plugin $plugin;
	private LeadRepository $leads;
	private FormRepository $forms;

	public function __construct( Plugin $plugin, LeadRepository $leads, FormRepository $forms ) {
		$this->plugin = $plugin;
		$this->leads  = $leads;
		$this->forms  = $forms;
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_export' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Build a URL to this screen.
	 *
	 * @param array<string, mixed> $args Extra query args; null values are dropped.
	 */
	public static function url( array $args = array() ): string {
		$args = array_merge(
			array(
				'post_type' => FormPostType::POST_TYPE,
				'page'      => self::SLUG,
			),
			array_filter( $args, static function ( $value ): bool {
				return null !== $value && '' !== $value;
			} )
		);

		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	/**
	 * Register the submenu under the Lead Forms menu.
	 */
	public function add_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . FormPostType::POST_TYPE,
			__( 'Leads', 'lead-forms' ),
			__( 'Leads', 'lead-forms' ),
			Plugin::capability(),
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Admin styles for this screen.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'lead-forms-admin',
			$this->plugin->url( 'assets/css/admin.css' ),
			array(),
			$this->plugin->version()
		);
	}

	/**
	 * Render either the detail view or the list.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view leads.', 'lead-forms' ) );
		}

		$notice = $this->handle_actions();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view routing.
		$single = isset( $_GET['lead'] ) && ! isset( $_GET['lf_action'] ) ? absint( $_GET['lead'] ) : 0;
		// phpcs:enable

		echo '<div class="wrap lf-leads">';

		if ( '' !== $notice ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $notice ) );
		}

		if ( $single > 0 ) {
			$this->render_single( $single );
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	/**
	 * The list view.
	 */
	private function render_list(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['s'] ) ) : '';
		// phpcs:enable

		if ( ! in_array( $status, LeadRepository::STATUSES, true ) ) {
			$status = '';
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		$table = new LeadsListTable( $this->leads, $this->forms->list_ids(), $form_id, $status, $search );
		$table->prepare_items();

		$export_url = wp_nonce_url(
			self::url(
				array(
					'form_id'   => $form_id ?: null,
					'status'    => '' !== $status ? $status : null,
					's'         => '' !== $search ? $search : null,
					'lf_action' => 'export',
				)
			),
			self::EXPORT_NONCE
		);
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Leads', 'lead-forms' ); ?></h1>
		<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'lead-forms' ); ?></a>
		<hr class="wp-header-end" />

		<?php $table->views(); ?>

		<form method="get">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( FormPostType::POST_TYPE ); ?>" />
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<?php if ( '' !== $status ) : ?>
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" />
			<?php endif; ?>
			<?php $table->search_box( __( 'Search leads', 'lead-forms' ), 'lead-search' ); ?>
		</form>

		<form method="post">
			<?php
			wp_nonce_field( self::ACTION_NONCE . '_bulk' );
			$table->display();
			?>
		</form>
		<?php
	}

	/**
	 * The single-lead detail view.
	 */
	private function render_single( int $lead_id ): void {
		$lead = $this->leads->find( $lead_id );

		if ( null === $lead ) {
			echo '<h1>' . esc_html__( 'Lead not found', 'lead-forms' ) . '</h1>';
			return;
		}

		// Opening a lead marks it read, like an inbox.
		if ( 'new' === $lead->status ) {
			$this->leads->set_status( $lead->id, 'read' );
			$lead->status = 'read';
		}

		$form_title = $this->forms->list_ids()[ $lead->form_id ] ?? sprintf( '#%d', $lead->form_id );
		?>
		<h1 class="wp-heading-inline">
			<?php
			printf(
				/* translators: %d: lead ID. */
				esc_html__( 'Lead #%d', 'lead-forms' ),
				(int) $lead->id
			);
			?>
		</h1>
		<a href="<?php echo esc_url( self::url() ); ?>" class="page-title-action"><?php esc_html_e( '← Back to leads', 'lead-forms' ); ?></a>
		<hr class="wp-header-end" />

		<div class="lf-lead-card">
			<table class="widefat striped lf-lead-table">
				<tbody>
					<?php foreach ( $lead->readable_payload() as $label => $value ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<td><?php echo nl2br( esc_html( $value ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Context', 'lead-forms' ); ?></h2>
			<table class="widefat striped lf-lead-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Form', 'lead-forms' ); ?></th>
						<td><?php echo esc_html( $form_title ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Received', 'lead-forms' ); ?></th>
						<td><?php echo esc_html( $lead->created_display() ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Page', 'lead-forms' ); ?></th>
						<td>
							<?php if ( '' !== $lead->source_url ) : ?>
								<a href="<?php echo esc_url( $lead->source_url ); ?>" target="_blank" rel="noreferrer noopener">
									<?php echo esc_html( $lead->source_url ); ?>
								</a>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Browser', 'lead-forms' ); ?></th>
						<td><?php echo '' !== $lead->user_agent ? esc_html( $lead->user_agent ) : '&mdash;'; ?></td>
					</tr>
				</tbody>
			</table>
			<p class="description">
				<?php esc_html_e( 'IP addresses are stored only as a salted hash, used for rate limiting.', 'lead-forms' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Run any requested row or bulk action.
	 *
	 * @return string A success notice, or ''.
	 */
	private function handle_actions(): string {
		// ---- Single-row actions (GET + per-lead nonce). ----
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce checked explicitly below.
		$action  = isset( $_REQUEST['lf_action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['lf_action'] ) ) : '';
		$lead_id = isset( $_GET['lead'] ) ? absint( $_GET['lead'] ) : 0;
		// phpcs:enable

		if ( '' !== $action && 'export' !== $action && $lead_id > 0 ) {
			check_admin_referer( self::ACTION_NONCE . '_' . $lead_id );

			return $this->apply_action( $action, array( $lead_id ) );
		}

		// ---- Bulk actions (POST + shared nonce). ----
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified immediately below.
		if ( empty( $_POST['lead_ids'] ) ) {
			return '';
		}

		check_admin_referer( self::ACTION_NONCE . '_bulk' );

		$bulk = '';

		foreach ( array( 'action', 'action2' ) as $key ) {
			$value = isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( (string) $_POST[ $key ] ) ) : '';

			if ( '' !== $value && '-1' !== $value ) {
				$bulk = $value;
				break;
			}
		}

		if ( '' === $bulk ) {
			return '';
		}

		$ids = array_map( 'absint', (array) wp_unslash( $_POST['lead_ids'] ) );

		return $this->apply_action( $bulk, array_filter( $ids ) );
	}

	/**
	 * Perform an action on a set of leads.
	 *
	 * @param string $action Action key.
	 * @param int[]  $ids    Lead IDs.
	 */
	private function apply_action( string $action, array $ids ): string {
		if ( empty( $ids ) ) {
			return '';
		}

		switch ( $action ) {
			case 'mark_read':
				foreach ( $ids as $id ) {
					$this->leads->set_status( $id, 'read' );
				}

				return $this->count_notice( count( $ids ), __( '%s lead marked as read.', 'lead-forms' ), __( '%s leads marked as read.', 'lead-forms' ) );

			case 'mark_spam':
				foreach ( $ids as $id ) {
					$this->leads->set_status( $id, 'spam' );
				}

				return $this->count_notice( count( $ids ), __( '%s lead marked as spam.', 'lead-forms' ), __( '%s leads marked as spam.', 'lead-forms' ) );

			case 'delete':
				$deleted = $this->leads->delete( $ids );

				return $this->count_notice( $deleted, __( '%s lead deleted.', 'lead-forms' ), __( '%s leads deleted.', 'lead-forms' ) );
		}

		return '';
	}

	/**
	 * Pluralised "N leads …" notice.
	 */
	private function count_notice( int $count, string $singular, string $plural ): string {
		// The strings are already translated by the caller's __() calls.
		return sprintf( 1 === $count ? $singular : $plural, number_format_i18n( $count ) );
	}

	/**
	 * Stream a CSV of the current filter selection.
	 */
	public function maybe_export(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce checked below.
		if ( ! isset( $_GET['page'], $_GET['lf_action'] ) || self::SLUG !== $_GET['page'] || 'export' !== $_GET['lf_action'] ) {
			return;
		}
		// phpcs:enable

		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to export leads.', 'lead-forms' ) );
		}

		check_admin_referer( self::EXPORT_NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified above.
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		// phpcs:enable

		$leads = $this->leads->query(
			array(
				'form_id'  => $form_id,
				'status'   => in_array( $status, LeadRepository::STATUSES, true ) ? $status : '',
				'search'   => $search,
				'per_page' => 500,
				'page'     => 1,
			)
		);

		$filename = sprintf( 'leads-%s.csv', gmdate( 'Y-m-d-His' ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );

		if ( false === $out ) {
			exit;
		}

		// BOM so Excel opens UTF-8 correctly.
		fwrite( $out, "\xEF\xBB\xBF" );

		$columns = $this->csv_columns( $leads );
		fputcsv( $out, $columns );

		foreach ( $leads as $lead ) {
			$payload = $lead->readable_payload();
			$row     = array(
				$lead->id,
				$lead->created_display(),
				$lead->status,
				$this->forms->list_ids()[ $lead->form_id ] ?? (string) $lead->form_id,
			);

			foreach ( array_slice( $columns, 4 ) as $label ) {
				$row[] = $this->escape_csv( $payload[ $label ] ?? '' );
			}

			fputcsv( $out, $row );
		}

		fclose( $out );
		exit;
	}

	/**
	 * Union of every answer label present in the export.
	 *
	 * @param Lead[] $leads Leads being exported.
	 * @return string[]
	 */
	private function csv_columns( array $leads ): array {
		$labels = array();

		foreach ( $leads as $lead ) {
			foreach ( array_keys( $lead->readable_payload() ) as $label ) {
				$labels[ $label ] = true;
			}
		}

		return array_merge(
			array(
				__( 'ID', 'lead-forms' ),
				__( 'Received', 'lead-forms' ),
				__( 'Status', 'lead-forms' ),
				__( 'Form', 'lead-forms' ),
			),
			array_keys( $labels )
		);
	}

	/**
	 * Neutralise spreadsheet formula injection.
	 *
	 * A cell starting with =, +, - or @ is executed as a formula by Excel and
	 * Google Sheets, so a prefix is added to keep it inert.
	 */
	private function escape_csv( string $value ): string {
		return ( '' !== $value && str_contains( "=+-@\t\r", $value[0] ) ) ? "'" . $value : $value;
	}
}
