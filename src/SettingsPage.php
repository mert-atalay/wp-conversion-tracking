<?php
/**
 * Settings screen under Settings > Lead Conversion Tracking.
 *
 * @package LCT
 */

namespace LCT;

defined( 'ABSPATH' ) || exit;

/**
 * Admin settings page built on the Settings API.
 */
final class SettingsPage {

	public const SLUG         = 'lead-conversion-tracking';
	public const GROUP        = 'lct_settings_group';
	public const CAPABILITY   = 'manage_options';
	public const CLEAR_ACTION = 'lct_clear_log';

	/**
	 * Lead log.
	 *
	 * @var LeadLog
	 */
	private LeadLog $log;

	/**
	 * Constructor.
	 *
	 * @param LeadLog $log Lead log.
	 */
	public function __construct( LeadLog $log ) {
		$this->log = $log;
	}

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_' . self::CLEAR_ACTION, array( $this, 'clear_log' ) );
	}

	/**
	 * Adds the page to the Settings menu.
	 */
	public function add_page(): void {
		add_options_page(
			__( 'Lead Conversion Tracking', 'lead-conversion-tracking' ),
			__( 'Lead Conversion Tracking', 'lead-conversion-tracking' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Field definitions: key => [ label, type, section, description ].
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	private function fields(): array {
		return array(
			'cookie_days'     => array(
				__( 'Attribution lifetime (days)', 'lead-conversion-tracking' ),
				'number',
				'lct_capture',
				__( 'Counted from the first visit. After that the stored attribution is dropped.', 'lead-conversion-tracking' ),
			),
			'assume_consent'  => array(
				__( 'Assume consent', 'lead-conversion-tracking' ),
				'checkbox',
				'lct_capture',
				__( 'Only applies when no WP Consent API plugin is active. Leave this off unless you have a legal basis to track without asking.', 'lead-conversion-tracking' ),
			),
			'log_enabled'     => array(
				__( 'Keep a lead log', 'lead-conversion-tracking' ),
				'checkbox',
				'lct_capture',
				__( 'Stores the event id, form id and attribution of the last 100 leads. No names, emails or message text.', 'lead-conversion-tracking' ),
			),
			'forward_enabled' => array(
				__( 'Send leads to an endpoint', 'lead-conversion-tracking' ),
				'checkbox',
				'lct_forward',
				__( 'Posts a JSON payload for every lead that has consent.', 'lead-conversion-tracking' ),
			),
			'endpoint_url'    => array(
				__( 'Endpoint URL', 'lead-conversion-tracking' ),
				'url',
				'lct_forward',
				__( 'Must start with https://.', 'lead-conversion-tracking' ),
			),
			'shared_secret'   => array(
				__( 'Shared secret', 'lead-conversion-tracking' ),
				'password',
				'lct_forward',
				__( 'Sent in the X-LCT-Secret header so the endpoint can reject other callers.', 'lead-conversion-tracking' ),
			),
			'hash_email'      => array(
				__( 'Include hashed email', 'lead-conversion-tracking' ),
				'checkbox',
				'lct_forward',
				__( 'Adds the SHA-256 hash of the submitted email address to the payload. The plain address is never sent or stored by this plugin.', 'lead-conversion-tracking' ),
			),
		);
	}

	/**
	 * Registers the option, sections and fields.
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);

		add_settings_section( 'lct_capture', __( 'Attribution and consent', 'lead-conversion-tracking' ), '__return_false', self::SLUG );
		add_settings_section( 'lct_forward', __( 'Server-side forward', 'lead-conversion-tracking' ), '__return_false', self::SLUG );

		foreach ( $this->fields() as $key => $field ) {
			add_settings_field(
				'lct_' . $key,
				$field[0],
				array( $this, 'render_field' ),
				self::SLUG,
				$field[2],
				array(
					'key'         => $key,
					'type'        => $field[1],
					'description' => $field[3],
					'label_for'   => 'lct_' . $key,
				)
			);
		}
	}

	/**
	 * Prints one form field.
	 *
	 * @param array<string, string> $args Arguments from add_settings_field().
	 */
	public function render_field( array $args ): void {
		$settings = Settings::get();
		$key      = $args['key'];
		$id       = 'lct_' . $key;
		$name     = Settings::OPTION . '[' . $key . ']';

		if ( 'checkbox' === $args['type'] ) {
			printf(
				'<input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s />',
				esc_attr( $id ),
				esc_attr( $name ),
				checked( ! empty( $settings[ $key ] ), true, false )
			);
		} else {
			printf(
				'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="%5$s" autocomplete="off" %6$s />',
				esc_attr( $args['type'] ),
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( (string) $settings[ $key ] ),
				'number' === $args['type'] ? 'small-text' : 'regular-text',
				'number' === $args['type']
					? sprintf( 'min="%d" max="%d" step="1"', (int) Settings::MIN_COOKIE_DAYS, (int) Settings::MAX_COOKIE_DAYS )
					: ''
			);
		}

		printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
	}

	/**
	 * Prints the settings page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::SLUG );
				submit_button();
				?>
			</form>

			<?php $this->render_log(); ?>
		</div>
		<?php
	}

	/**
	 * Prints the most recent log entries and the clear button.
	 */
	private function render_log(): void {
		$entries = array_reverse( $this->log->all() );
		?>
		<h2><?php esc_html_e( 'Recent leads', 'lead-conversion-tracking' ); ?></h2>

		<?php if ( array() === $entries ) : ?>
			<p><?php esc_html_e( 'No leads logged yet.', 'lead-conversion-tracking' ); ?></p>
			<?php
			return;
		endif;
		?>

		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Time (UTC)', 'lead-conversion-tracking' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Event id', 'lead-conversion-tracking' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Form', 'lead-conversion-tracking' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last source / medium', 'lead-conversion-tracking' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_slice( $entries, 0, 20 ) as $entry ) : ?>
					<?php $last = is_array( $entry['attribution']['last'] ?? null ) ? $entry['attribution']['last'] : array(); ?>
					<tr>
						<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) ( $entry['timestamp'] ?? 0 ) ) ); ?></td>
						<td><code><?php echo esc_html( (string) ( $entry['event_id'] ?? '' ) ); ?></code></td>
						<td><?php echo esc_html( ( $entry['form_type'] ?? '' ) . ' ' . ( $entry['form_id'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( ( $last['utm_source'] ?? '-' ) . ' / ' . ( $last['utm_medium'] ?? '-' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::CLEAR_ACTION ); ?>" />
			<?php
			wp_nonce_field( self::CLEAR_ACTION );
			submit_button( __( 'Clear log', 'lead-conversion-tracking' ), 'delete' );
			?>
		</form>
		<?php
	}

	/**
	 * Handles the "Clear log" button.
	 */
	public function clear_log(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'lead-conversion-tracking' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::CLEAR_ACTION );

		$this->log->clear();

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG ) );
		exit;
	}
}
