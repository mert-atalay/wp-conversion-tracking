<?php
/**
 * Handles one successful form submission on the server.
 *
 * @package LCT
 */

namespace LCT;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a submission into a lead record, then logs and forwards it.
 */
final class LeadHandler {

	public const EVENT_NAME     = 'generate_lead';
	public const PENDING_COOKIE = 'lct_pending';
	public const PENDING_TTL    = 300;

	/**
	 * Consent gate.
	 *
	 * @var Consent
	 */
	private Consent $consent;

	/**
	 * Server-side forwarder.
	 *
	 * @var Forwarder
	 */
	private Forwarder $forwarder;

	/**
	 * Lead log.
	 *
	 * @var LeadLog
	 */
	private LeadLog $log;

	/**
	 * Callable that sets a cookie: fn( string $name, string $value, int $ttl ): void.
	 *
	 * @var callable
	 */
	private $cookie_setter;

	/**
	 * Constructor.
	 *
	 * @param Consent       $consent       Consent gate.
	 * @param Forwarder     $forwarder     Server-side forwarder.
	 * @param LeadLog       $log           Lead log.
	 * @param callable|null $cookie_setter Optional replacement for the cookie writer (used in tests).
	 */
	public function __construct( Consent $consent, Forwarder $forwarder, LeadLog $log, ?callable $cookie_setter = null ) {
		$this->consent       = $consent;
		$this->forwarder     = $forwarder;
		$this->log           = $log;
		$this->cookie_setter = $cookie_setter ?? array( self::class, 'set_cookie' );
	}

	/**
	 * Callback for the `lct_form_submitted` action.
	 *
	 * @param mixed $form_id   Form id as the form plugin knows it.
	 * @param mixed $form_type Short name of the form plugin, e.g. "cf7".
	 * @param mixed $email     Submitted email address. Only used when hashed email is enabled.
	 */
	public function handle( $form_id, $form_type = 'custom', $email = '' ): void {
		// The form plugin has already verified its own request. These fields are only read and sanitised here.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$post   = wp_unslash( $_POST );
		$cookie = isset( $_COOKIE[ Attribution::COOKIE ] ) && is_string( $_COOKIE[ Attribution::COOKIE ] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, sanitised field by field in Attribution::sanitize().
			? wp_unslash( $_COOKIE[ Attribution::COOKIE ] )
			: '';

		$this->process(
			is_scalar( $form_id ) ? (string) $form_id : '',
			is_scalar( $form_type ) ? (string) $form_type : '',
			is_string( $email ) ? $email : '',
			is_array( $post ) ? $post : array(),
			$cookie
		);
	}

	/**
	 * Builds the lead record and runs the side effects.
	 *
	 * @param string               $form_id     Form id.
	 * @param string               $form_type   Form plugin name.
	 * @param string               $email       Submitted email address, may be empty.
	 * @param array<string, mixed> $post        Unslashed request fields.
	 * @param string               $cookie_json Unslashed attribution cookie.
	 * @return array<string, mixed>|null The lead record, or null when there is no consent.
	 */
	public function process( string $form_id, string $form_type, string $email, array $post, string $cookie_json = '' ): ?array {
		if ( ! $this->consent->has_consent() ) {
			return null;
		}

		$settings = Settings::get();

		$lead = array(
			'event_name'  => self::EVENT_NAME,
			'event_id'    => EventId::resolve( $post[ EventId::FIELD ] ?? null ),
			'timestamp'   => time(),
			'form_id'     => sanitize_key( $form_id ),
			'form_type'   => sanitize_key( $form_type ),
			'attribution' => Attribution::from_request( $post, $cookie_json ),
		);

		/**
		 * Fires when a lead has been recorded. Use it to store the event id
		 * next to the submission in a CRM or entries table.
		 *
		 * @param array<string, mixed> $lead Lead record. Contains no personal data.
		 */
		do_action( 'lct_lead_captured', $lead );

		if ( ! empty( $settings['log_enabled'] ) ) {
			$this->log->add( $lead );
		}

		// Lets the browser push the event after a full page reload (forms without AJAX).
		call_user_func( $this->cookie_setter, self::PENDING_COOKIE, self::pending_cookie_value( $lead ), self::PENDING_TTL );

		$payload = Payload::build( $lead, $email, ! empty( $settings['hash_email'] ), home_url() );

		/**
		 * Filters the payload of the server-side forward.
		 *
		 * @param array<string, mixed> $payload Payload to send.
		 * @param array<string, mixed> $lead    Lead record.
		 */
		$payload = apply_filters( 'lct_server_payload', $payload, $lead );

		if ( is_array( $payload ) ) {
			$this->forwarder->send( $payload, $settings );
		}

		return $lead;
	}

	/**
	 * JSON value of the short-lived cookie that tells the browser which event to push.
	 *
	 * @param array<string, mixed> $lead Lead record.
	 */
	public static function pending_cookie_value( array $lead ): string {
		return (string) wp_json_encode(
			array(
				'event_id'  => $lead['event_id'],
				'form_id'   => $lead['form_id'],
				'form_type' => $lead['form_type'],
			)
		);
	}

	/**
	 * Default cookie writer. The cookie is readable by the front-end script on purpose.
	 *
	 * @param string $name  Cookie name.
	 * @param string $value Cookie value.
	 * @param int    $ttl   Lifetime in seconds.
	 */
	public static function set_cookie( string $name, string $value, int $ttl ): void {
		if ( headers_sent() ) {
			return;
		}

		setcookie(
			$name,
			$value,
			array(
				'expires'  => time() + $ttl,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
	}
}
