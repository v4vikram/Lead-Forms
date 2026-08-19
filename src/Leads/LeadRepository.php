<?php
/**
 * Data access for stored leads.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms\Leads;

defined( 'ABSPATH' ) || exit;

/**
 * Every query against the leads table lives here, and every one of them goes
 * through `$wpdb->prepare()`.
 */
final class LeadRepository {

	/** Statuses a lead may hold. */
	public const STATUSES = array( 'new', 'read', 'spam', 'trash' );

	/**
	 * Fully qualified table name.
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'lead_forms_leads';
	}

	/**
	 * Store a submission.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return int Inserted ID, or 0 on failure.
	 */
	public function insert( array $data ): int {
		global $wpdb;

		$row = array(
			'form_id'    => (int) ( $data['form_id'] ?? 0 ),
			'status'     => in_array( $data['status'] ?? 'new', self::STATUSES, true ) ? (string) $data['status'] : 'new',
			'name'       => mb_substr( (string) ( $data['name'] ?? '' ), 0, 200 ),
			'email'      => mb_substr( (string) ( $data['email'] ?? '' ), 0, 200 ),
			'phone'      => mb_substr( (string) ( $data['phone'] ?? '' ), 0, 60 ),
			'payload'    => wp_json_encode( $data['payload'] ?? array() ),
			'source_url' => mb_substr( (string) ( $data['source_url'] ?? '' ), 0, 255 ),
			'referer'    => mb_substr( (string) ( $data['referer'] ?? '' ), 0, 255 ),
			'ip_hash'    => mb_substr( (string) ( $data['ip_hash'] ?? '' ), 0, 64 ),
			'user_agent' => mb_substr( (string) ( $data['user_agent'] ?? '' ), 0, 255 ),
			'user_id'    => (int) ( $data['user_id'] ?? 0 ),
			'created_at' => (string) ( $data['created_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
		);

		$inserted = $wpdb->insert(
			self::table_name(),
			$row,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Fetch one lead.
	 */
	public function find( int $id ): ?Lead {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? Lead::from_row( $row ) : null;
	}

	/**
	 * Query leads.
	 *
	 * @param array<string, mixed> $args form_id, status, search, orderby, order, per_page, page.
	 * @return Lead[]
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'form_id'  => 0,
				'status'   => '',
				'search'   => '',
				'orderby'  => 'created_at',
				'order'    => 'DESC',
				'per_page' => 20,
				'page'     => 1,
			)
		);

		[ $where, $params ] = $this->build_where( $args );

		// Whitelisting is mandatory: ORDER BY cannot be a prepared parameter.
		$allowed_orderby = array( 'id', 'created_at', 'name', 'email', 'status', 'form_id' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? (string) $args['orderby'] : 'created_at';
		$order           = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, min( 500, (int) $args['per_page'] ) );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$table = self::table_name();
		$sql   = "SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is assembled from whitelisted fragments and prepared below.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return array_map(
			static function ( array $row ): Lead {
				return Lead::from_row( $row );
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Count leads matching the same filters as `query()`.
	 *
	 * @param array<string, mixed> $args Filters.
	 */
	public function count( array $args = array() ): int {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'form_id' => 0,
				'status'  => '',
				'search'  => '',
			)
		);

		[ $where, $params ] = $this->build_where( $args );

		$table = self::table_name();
		$sql   = "SELECT COUNT(*) FROM {$table} {$where}";

		if ( empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			return (int) $wpdb->get_var( $sql );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Count leads per status, for the list table's status links.
	 *
	 * @return array<string, int>
	 */
	public function count_by_status( int $form_id = 0 ): array {
		global $wpdb;

		$table = self::table_name();

		if ( $form_id > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT status, COUNT(*) AS total FROM {$table} WHERE form_id = %d GROUP BY status", $form_id ),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );
		}

		$counts = array_fill_keys( self::STATUSES, 0 );

		foreach ( (array) $rows as $row ) {
			$status = (string) ( $row['status'] ?? '' );

			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ] = (int) $row['total'];
			}
		}

		return $counts;
	}

	/**
	 * Move a lead to another status.
	 */
	public function set_status( int $id, string $status ): bool {
		global $wpdb;

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}

		return false !== $wpdb->update(
			self::table_name(),
			array( 'status' => $status ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Permanently delete leads.
	 *
	 * @param int[] $ids Lead IDs.
	 * @return int Number of rows removed.
	 */
	public function delete( array $ids ): int {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

		if ( empty( $ids ) ) {
			return 0;
		}

		$table         = self::table_name();
		$placeholders  = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) );
	}

	/**
	 * Remove every lead belonging to a form (used when a form is deleted).
	 */
	public function delete_for_form( int $form_id ): int {
		global $wpdb;

		return (int) $wpdb->delete( self::table_name(), array( 'form_id' => $form_id ), array( '%d' ) );
	}

	/**
	 * Build the shared WHERE clause plus its bound parameters.
	 *
	 * @param array<string, mixed> $args Filters.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function build_where( array $args ): array {
		global $wpdb;

		$clauses = array();
		$params  = array();

		if ( ! empty( $args['form_id'] ) ) {
			$clauses[] = 'form_id = %d';
			$params[]  = (int) $args['form_id'];
		}

		$status = (string) ( $args['status'] ?? '' );

		if ( '' !== $status && in_array( $status, self::STATUSES, true ) ) {
			$clauses[] = 'status = %s';
			$params[]  = $status;
		} elseif ( '' === $status ) {
			// The default view hides spam and trash, like WP comments do.
			$clauses[] = 'status NOT IN (%s, %s)';
			$params[]  = 'spam';
			$params[]  = 'trash';
		}

		$search = trim( (string) ( $args['search'] ?? '' ) );

		if ( '' !== $search ) {
			$like      = '%' . $wpdb->esc_like( $search ) . '%';
			$clauses[] = '(name LIKE %s OR email LIKE %s OR phone LIKE %s OR payload LIKE %s)';
			array_push( $params, $like, $like, $like, $like );
		}

		$where = empty( $clauses ) ? '' : 'WHERE ' . implode( ' AND ', $clauses );

		return array( $where, $params );
	}
}
