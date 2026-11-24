<?php
/**
 * Loads the front-end script and its configuration.
 *
 * @package LCT
 */

namespace LCT;

defined( 'ABSPATH' ) || exit;

/**
 * Front-end assets.
 */
final class Frontend {

	public const HANDLE = 'lct';

	/**
	 * Consent gate.
	 *
	 * @var Consent
	 */
	private Consent $consent;

	/**
	 * Constructor.
	 *
	 * @param Consent $consent Consent gate.
	 */
	public function __construct( Consent $consent ) {
		$this->consent = $consent;
	}

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the script with its config object.
	 */
	public function enqueue(): void {
		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/js/lct.js', LCT_FILE ),
			array(),
			LCT_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script(
			self::HANDLE,
			'window.lctConfig = ' . wp_json_encode( $this->config() ) . ';',
			'before'
		);
	}

	/**
	 * Configuration passed to the script.
	 *
	 * @return array<string, mixed>
	 */
	public function config(): array {
		$settings = Settings::get();
		$uses_api = $this->consent->uses_api();

		/**
		 * Filters the CSS selectors of forms that get hidden attribution fields added automatically.
		 *
		 * @param string[] $selectors CSS selectors.
		 */
		$selectors = apply_filters(
			'lct_form_selectors',
			array( 'form.wpcf7-form', 'form.wpforms-form', 'form[data-lct-form]' )
		);

		$config = array(
			'cookieName'      => Attribution::COOKIE,
			'pendingCookie'   => LeadHandler::PENDING_COOKIE,
			'cookieDays'      => (int) $settings['cookie_days'],
			'eventName'       => LeadHandler::EVENT_NAME,
			'consentCategory' => Consent::CATEGORY,
			// With the WP Consent API the browser asks wp_has_consent() itself, so no
			// per-visitor answer is printed into a page that may be cached.
			'consentApi'      => $uses_api,
			'consentFallback' => $uses_api ? false : $this->consent->has_consent(),
			'formSelector'    => implode( ', ', array_filter( array_map( 'strval', (array) $selectors ) ) ),
		);

		/**
		 * Filters the configuration object of the front-end script.
		 *
		 * @param array<string, mixed> $config Script configuration.
		 */
		return (array) apply_filters( 'lct_script_config', $config );
	}
}
