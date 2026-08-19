<?php
/**
 * Activation, schema management and seed data.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms;

use LeadForms\Forms\FormPostType;
use LeadForms\Leads\LeadRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Owns everything that touches the database schema.
 */
final class Installer {

	/** Option holding the installed schema version. */
	public const DB_VERSION_OPTION = 'lead_forms_db_version';

	/** Bump whenever the schema below changes. */
	public const DB_VERSION = '1.0.0';

	/**
	 * Runs on activation.
	 */
	public static function activate(): void {
		self::install_schema();
		self::seed_default_form();

		// The CPT rewrite rules are registered on `init`, which has not run for
		// this request, so flush lazily on the next one instead.
		update_option( 'lead_forms_flush_rewrites', 1, false );
	}

	/**
	 * Runs on deactivation. Deliberately keeps all user data.
	 */
	public static function deactivate(): void {
		delete_option( 'lead_forms_flush_rewrites' );
		flush_rewrite_rules();
	}

	/**
	 * Applies pending schema upgrades on normal page loads.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install_schema();
		}

		if ( get_option( 'lead_forms_flush_rewrites' ) ) {
			delete_option( 'lead_forms_flush_rewrites' );
			flush_rewrite_rules();
		}
	}

	/**
	 * Creates or migrates the leads table with dbDelta().
	 */
	public static function install_schema(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = LeadRepository::table_name();
		$collate = $wpdb->get_charset_collate();

		// dbDelta is whitespace sensitive: two spaces before each key
		// definition and one space around every column type.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'new',
			name varchar(200) NOT NULL DEFAULT '',
			email varchar(200) NOT NULL DEFAULT '',
			phone varchar(60) NOT NULL DEFAULT '',
			payload longtext NOT NULL,
			source_url varchar(255) NOT NULL DEFAULT '',
			referer varchar(255) NOT NULL DEFAULT '',
			ip_hash varchar(64) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY status (status),
			KEY created_at (created_at),
			KEY email (email)
		) {$collate};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Creates one ready-to-use form the first time the plugin is activated,
	 * so a fresh install has something to embed immediately.
	 */
	private static function seed_default_form(): void {
		if ( get_option( 'lead_forms_seeded' ) ) {
			return;
		}

		update_option( 'lead_forms_seeded', 1, false );

		// The post type is not registered during activation; register it now
		// so wp_insert_post() accepts it.
		( new FormPostType() )->register();

		$form_id = wp_insert_post(
			array(
				'post_type'   => FormPostType::POST_TYPE,
				'post_title'  => __( 'Book Your Visit', 'lead-forms' ),
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $form_id ) ) {
			return;
		}

		update_post_meta(
			$form_id,
			FormPostType::META_FIELDS,
			array(
				array(
					'id'          => 'name',
					'type'        => 'text',
					'label'       => __( 'Name', 'lead-forms' ),
					'placeholder' => '',
					'help'        => __( 'Enter your name here', 'lead-forms' ),
					'required'    => true,
					'width'       => 'half',
					'options'     => array(),
				),
				array(
					'id'          => 'number',
					'type'        => 'tel',
					'label'       => __( 'Number', 'lead-forms' ),
					'placeholder' => '',
					'help'        => __( 'Enter your mobile number', 'lead-forms' ),
					'required'    => true,
					'width'       => 'half',
					'options'     => array(),
				),
				array(
					'id'          => 'email',
					'type'        => 'email',
					'label'       => __( 'Email Address', 'lead-forms' ),
					'placeholder' => '',
					'help'        => __( 'Example: user@website.com', 'lead-forms' ),
					'required'    => false,
					'width'       => 'half',
					'options'     => array(),
				),
				array(
					'id'          => 'service',
					'type'        => 'select',
					'label'       => __( 'Which Service You Need?', 'lead-forms' ),
					'placeholder' => __( '— Select —', 'lead-forms' ),
					'help'        => __( 'Select your service', 'lead-forms' ),
					'required'    => true,
					'width'       => 'half',
					'options'     => array(
						__( 'Refrigerator Repair', 'lead-forms' ),
						__( 'AC Repair', 'lead-forms' ),
						__( 'Washing Machine Repair', 'lead-forms' ),
						__( 'Microwave Repair', 'lead-forms' ),
						__( 'Other', 'lead-forms' ),
					),
				),
				array(
					'id'          => 'message',
					'type'        => 'textarea',
					'label'       => __( 'Message', 'lead-forms' ),
					'placeholder' => '',
					'help'        => __( 'Describe your problem little bit here!', 'lead-forms' ),
					'required'    => false,
					'width'       => 'full',
					'options'     => array(),
				),
			)
		);

		update_post_meta(
			$form_id,
			FormPostType::META_SETTINGS,
			array(
				'heading'          => __( 'Book Your Visit Now!', 'lead-forms' ),
				'subheading'       => __( 'Let us know how to get back to you.', 'lead-forms' ),
				'submit_label'     => __( 'SUBMIT', 'lead-forms' ),
				'success_message'  => __( 'Thank you! We have received your request and will call you back shortly.', 'lead-forms' ),
				'recipients'       => get_option( 'admin_email' ),
				'store_leads'      => true,
				'notify'           => true,
			)
		);
	}
}
