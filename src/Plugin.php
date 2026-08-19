<?php
/**
 * Plugin container.
 *
 * @package LeadForms
 */

declare( strict_types=1 );

namespace LeadForms;

use LeadForms\Admin\FormBuilderMetabox;
use LeadForms\Admin\LeadsPage;
use LeadForms\Admin\SettingsMetabox;
use LeadForms\Forms\FormPostType;
use LeadForms\Forms\FormRepository;
use LeadForms\Frontend\Assets;
use LeadForms\Frontend\BlockRegistrar;
use LeadForms\Frontend\FormRenderer;
use LeadForms\Frontend\Shortcode;
use LeadForms\Leads\LeadRepository;
use LeadForms\Mail\Notifier;
use LeadForms\Submission\AdminPostHandler;
use LeadForms\Submission\RestController;
use LeadForms\Submission\SpamGuard;
use LeadForms\Submission\SubmissionHandler;
use LeadForms\Submission\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every service together and registers their hooks.
 *
 * Services are constructed here rather than reaching for globals or
 * singletons, so each class stays unit-testable in isolation.
 */
final class Plugin {

	/** Absolute path to the main plugin file. */
	private string $file;

	/** Current plugin version, used for asset cache busting. */
	private string $version;

	/** Lazily built service instances, keyed by identifier. */
	private array $services = array();

	/** Guards against a double boot when another plugin fires `plugins_loaded` twice. */
	private bool $booted = false;

	/**
	 * @param string $file    Main plugin file.
	 * @param string $version Plugin version.
	 */
	public function __construct( string $file, string $version ) {
		$this->file    = $file;
		$this->version = $version;
	}

	/**
	 * Register every hook the plugin needs.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->forms()->register_hooks();
		$this->shortcode()->register_hooks();
		$this->assets()->register_hooks();
		$this->blocks()->register_hooks();
		$this->rest()->register_hooks();
		$this->admin_post()->register_hooks();

		if ( is_admin() ) {
			$this->form_builder()->register_hooks();
			$this->settings_metabox()->register_hooks();
			$this->leads_page()->register_hooks();
		}

		// Runs on every request so schema upgrades land even when the plugin
		// is updated by file copy rather than through the activation hook.
		add_action( 'init', array( Installer::class, 'maybe_upgrade' ), 5 );

		/**
		 * Fires once all Lead Forms services are wired up.
		 *
		 * @param Plugin $plugin The plugin container.
		 */
		do_action( 'lead_forms_booted', $this );
	}

	/* ---------------------------------------------------------------------
	 * Paths, URLs and metadata.
	 * ------------------------------------------------------------------ */

	public function version(): string {
		return $this->version;
	}

	public function file(): string {
		return $this->file;
	}

	public function path( string $relative = '' ): string {
		return plugin_dir_path( $this->file ) . ltrim( $relative, '/' );
	}

	public function url( string $relative = '' ): string {
		return plugin_dir_url( $this->file ) . ltrim( $relative, '/' );
	}

	/**
	 * Capability required to build forms and read leads.
	 */
	public static function capability(): string {
		/**
		 * Filter the capability that guards the plugin's admin screens.
		 *
		 * @param string $capability Defaults to `edit_pages` (administrators and editors).
		 */
		return (string) apply_filters( 'lead_forms_capability', 'edit_pages' );
	}

	/* ---------------------------------------------------------------------
	 * Service accessors. Each service is created once and reused.
	 * ------------------------------------------------------------------ */

	public function forms(): FormPostType {
		return $this->service( 'forms', fn() => new FormPostType() );
	}

	public function form_repository(): FormRepository {
		return $this->service( 'form_repository', fn() => new FormRepository() );
	}

	public function lead_repository(): LeadRepository {
		return $this->service( 'lead_repository', fn() => new LeadRepository() );
	}

	public function renderer(): FormRenderer {
		return $this->service( 'renderer', fn() => new FormRenderer( $this->form_repository(), $this->assets() ) );
	}

	public function shortcode(): Shortcode {
		return $this->service( 'shortcode', fn() => new Shortcode( $this->renderer() ) );
	}

	public function assets(): Assets {
		return $this->service( 'assets', fn() => new Assets( $this ) );
	}

	public function blocks(): BlockRegistrar {
		return $this->service( 'blocks', fn() => new BlockRegistrar( $this, $this->renderer(), $this->form_repository() ) );
	}

	public function validator(): Validator {
		return $this->service( 'validator', fn() => new Validator() );
	}

	public function spam_guard(): SpamGuard {
		return $this->service( 'spam_guard', fn() => new SpamGuard() );
	}

	public function notifier(): Notifier {
		return $this->service( 'notifier', fn() => new Notifier() );
	}

	public function submissions(): SubmissionHandler {
		return $this->service(
			'submissions',
			fn() => new SubmissionHandler(
				$this->form_repository(),
				$this->validator(),
				$this->spam_guard(),
				$this->lead_repository(),
				$this->notifier()
			)
		);
	}

	public function rest(): RestController {
		return $this->service( 'rest', fn() => new RestController( $this->submissions() ) );
	}

	public function admin_post(): AdminPostHandler {
		return $this->service( 'admin_post', fn() => new AdminPostHandler( $this->submissions() ) );
	}

	public function form_builder(): FormBuilderMetabox {
		return $this->service( 'form_builder', fn() => new FormBuilderMetabox( $this, $this->form_repository() ) );
	}

	public function settings_metabox(): SettingsMetabox {
		return $this->service( 'settings_metabox', fn() => new SettingsMetabox( $this->form_repository() ) );
	}

	public function leads_page(): LeadsPage {
		return $this->service( 'leads_page', fn() => new LeadsPage( $this, $this->lead_repository(), $this->form_repository() ) );
	}

	/**
	 * Resolve (and memoise) a service.
	 *
	 * @param string   $key     Service identifier.
	 * @param callable $factory Factory invoked on first use.
	 * @return mixed
	 */
	private function service( string $key, callable $factory ) {
		if ( ! isset( $this->services[ $key ] ) ) {
			$this->services[ $key ] = $factory();
		}

		return $this->services[ $key ];
	}
}
