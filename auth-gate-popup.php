<?php
/**
 * Plugin Name:       Auth Gate Popup
 * Plugin URI:        https://github.com/lawrancebabu/auth-gate-popup
 * Description:       Forces visitors to create an account or log in before accessing the site, and requires login before WooCommerce purchases.
 * Version:           1.1.0
 * Author:            Lawrance Babu Gain
 * Author URI:        https://github.com/lawrancebabu
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Text Domain:       auth-gate-popup
 * Domain Path:       /languages
 *
 * @package AuthGatePopup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 *
 * Renders a non-dismissible login / registration popup for logged-out
 * visitors, handles the AJAX auth requests and gates WooCommerce purchases.
 */
final class Auth_Gate_Popup {

	/**
	 * Plugin version, used for asset cache busting and migrations.
	 */
	public const VERSION = '1.1.0';

	/**
	 * Nonce action for all popup forms.
	 */
	private const NONCE_ACTION = 'agp_auth';

	/**
	 * POST field name that carries the nonce.
	 */
	private const NONCE_NAME = 'agp_nonce';

	/**
	 * Option that stores the plugin settings.
	 */
	public const OPTION_NAME = 'agp_options';

	/**
	 * Settings API group name.
	 */
	private const SETTINGS_GROUP = 'agp_settings';

	/**
	 * Settings page slug.
	 */
	private const PAGE_SLUG = 'auth-gate-popup';

	/**
	 * Short-lived cookie set after a successful password reset.
	 */
	private const RESET_SUCCESS_COOKIE = 'agp_password_reset_success';

	/**
	 * User meta key that stores when the user accepted the terms.
	 */
	public const TERMS_META_KEY = 'agp_terms_agreed';

	/**
	 * Option that records the last completed data migration.
	 */
	private const MIGRATION_OPTION = 'agp_migration_version';

	/**
	 * Legacy (pre 1.1.0) settings option name.
	 */
	private const LEGACY_OPTION_NAME = 'alphalabs_auth_gate_options';

	/**
	 * Legacy (pre 1.1.0) terms user meta key.
	 */
	private const LEGACY_TERMS_META_KEY = 'alphalabs_terms_agreed';

	/**
	 * Whether the popup markup has already been printed on this request.
	 *
	 * @var bool
	 */
	private $popup_rendered = false;

	/**
	 * Registers all hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'init', array( __CLASS__, 'maybe_migrate_legacy_data' ), 5 );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_body_open', array( $this, 'render_popup' ), 1 );
		add_action( 'wp_footer', array( $this, 'render_popup' ) );
		add_action( 'wp_ajax_nopriv_agp_login', array( $this, 'ajax_login' ) );
		add_action( 'wp_ajax_nopriv_agp_register', array( $this, 'ajax_register' ) );
		add_action( 'wp_ajax_nopriv_agp_lost_password', array( $this, 'ajax_lost_password' ) );
		add_action( 'woocommerce_customer_reset_password', array( $this, 'set_password_reset_success_cookie' ), 10, 0 );
		add_action( 'password_reset', array( $this, 'set_password_reset_success_cookie' ), 10, 0 );
		add_filter( 'woocommerce_is_purchasable', array( $this, 'require_login_for_purchases' ), 10, 1 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'require_login_before_cart' ), 10, 1 );
	}

	/**
	 * Activation callback.
	 *
	 * Enables public registration, sets the default role and seeds settings.
	 * Deactivation intentionally leaves these site settings unchanged.
	 */
	public static function activate(): void {
		update_option( 'users_can_register', 1 );
		update_option( 'default_role', get_role( 'customer' ) ? 'customer' : 'subscriber' );

		self::maybe_migrate_legacy_data();

		if ( false === get_option( self::OPTION_NAME ) ) {
			add_option( self::OPTION_NAME, self::get_default_options() );
		}
	}

	/**
	 * Copies settings and user meta from the legacy "alphalabs" keys once.
	 *
	 * Legacy data is copied, not deleted, so downgrading remains possible.
	 */
	public static function maybe_migrate_legacy_data(): void {
		if ( get_option( self::MIGRATION_OPTION ) ) {
			return;
		}

		$legacy = get_option( self::LEGACY_OPTION_NAME );
		if ( is_array( $legacy ) && false === get_option( self::OPTION_NAME ) ) {
			$options = self::get_default_options();

			if ( ! empty( $legacy['accent_color'] ) ) {
				$options['accent_color'] = (string) $legacy['accent_color'];
			}

			// The old default image URL pointed into the old plugin folder, so only keep custom images.
			if ( ! empty( $legacy['promo_image_url'] ) && false === strpos( (string) $legacy['promo_image_url'], 'assets/top-shelf-popup.jpg' ) ) {
				$options['promo_image_url'] = (string) $legacy['promo_image_url'];
			}

			add_option( self::OPTION_NAME, self::sanitize_option_values( $options ) );
		}

		$user_ids = get_users(
			array(
				'meta_key' => self::LEGACY_TERMS_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-time migration.
				'fields'   => 'ID',
				'number'   => -1,
			)
		);

		foreach ( $user_ids as $user_id ) {
			if ( '' === get_user_meta( (int) $user_id, self::TERMS_META_KEY, true ) ) {
				update_user_meta( (int) $user_id, self::TERMS_META_KEY, get_user_meta( (int) $user_id, self::LEGACY_TERMS_META_KEY, true ) );
			}
		}

		update_option( self::MIGRATION_OPTION, self::VERSION );
	}

	/**
	 * Loads translations from the plugin's /languages folder.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'auth-gate-popup', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Adds the Settings > Auth Gate Popup page.
	 */
	public function add_settings_page(): void {
		add_options_page(
			__( 'Auth Gate Popup', 'auth-gate-popup' ),
			__( 'Auth Gate Popup', 'auth-gate-popup' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Registers the settings option with the Settings API.
	 */
	public function register_settings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => self::get_default_options(),
			)
		);
	}

	/**
	 * Enqueues the media picker script on the settings page only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_admin_assets( $hook ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script(
			'auth-gate-popup-admin',
			plugin_dir_url( __FILE__ ) . 'assets/auth-gate-admin.js',
			array( 'jquery' ),
			self::VERSION,
			true
		);
		wp_localize_script(
			'auth-gate-popup-admin',
			'AuthGatePopupAdmin',
			array(
				'frameTitle'  => __( 'Select popup image', 'auth-gate-popup' ),
				'frameButton' => __( 'Use this image', 'auth-gate-popup' ),
			)
		);
	}

	/**
	 * Renders the settings page.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options = self::get_options();
		$name    = self::OPTION_NAME;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Auth Gate Popup', 'auth-gate-popup' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::SETTINGS_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="agp-promo-image"><?php esc_html_e( 'Popup image', 'auth-gate-popup' ); ?></label>
						</th>
						<td>
							<input
								id="agp-promo-image"
								class="regular-text"
								type="url"
								name="<?php echo esc_attr( $name ); ?>[promo_image_url]"
								value="<?php echo esc_url( $options['promo_image_url'] ); ?>"
								placeholder="<?php echo esc_attr( self::get_default_image_url() ); ?>"
							>
							<button class="button agp-upload-image" type="button"><?php esc_html_e( 'Select Image', 'auth-gate-popup' ); ?></button>
							<p class="description"><?php esc_html_e( 'Leave empty to use the bundled default artwork. Recommended size is about 790 x 686.', 'auth-gate-popup' ); ?></p>
							<p>
								<img
									class="agp-image-preview"
									src="<?php echo esc_url( self::get_image_url( $options ) ); ?>"
									alt=""
									style="max-width: 360px; height: auto; border: 1px solid #ccd0d4;"
								>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="agp-image-alt"><?php esc_html_e( 'Image alt text', 'auth-gate-popup' ); ?></label>
						</th>
						<td>
							<input
								id="agp-image-alt"
								class="regular-text"
								type="text"
								name="<?php echo esc_attr( $name ); ?>[image_alt]"
								value="<?php echo esc_attr( $options['image_alt'] ); ?>"
							>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="agp-accent-color"><?php esc_html_e( 'Base color', 'auth-gate-popup' ); ?></label>
						</th>
						<td>
							<input
								id="agp-accent-color"
								type="text"
								name="<?php echo esc_attr( $name ); ?>[accent_color]"
								value="<?php echo esc_attr( $options['accent_color'] ); ?>"
								pattern="^#[0-9a-fA-F]{6}$"
							>
							<p class="description"><?php esc_html_e( 'Default: #053776', 'auth-gate-popup' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="agp-terms-text"><?php esc_html_e( 'Registration terms text', 'auth-gate-popup' ); ?></label>
						</th>
						<td>
							<textarea
								id="agp-terms-text"
								class="large-text"
								rows="4"
								name="<?php echo esc_attr( $name ); ?>[terms_text]"
							><?php echo esc_textarea( $options['terms_text'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Shown next to the checkbox on the Sign Up form. Links (a), strong and em tags are allowed. Leave empty to hide the checkbox.', 'auth-gate-popup' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Require terms checkbox', 'auth-gate-popup' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( $name ); ?>[terms_required]" value="0">
							<label for="agp-terms-required">
								<input
									id="agp-terms-required"
									type="checkbox"
									name="<?php echo esc_attr( $name ); ?>[terms_required]"
									value="1"
									<?php checked( $options['terms_required'] ); ?>
								>
								<?php esc_html_e( 'Visitors must tick the terms checkbox to create an account.', 'auth-gate-popup' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Settings API sanitize callback.
	 *
	 * @param mixed $options Raw submitted value.
	 * @return array Sanitized settings.
	 */
	public function sanitize_options( $options ): array {
		return self::sanitize_option_values( is_array( $options ) ? $options : array() );
	}

	/**
	 * Sanitizes a settings array, falling back to defaults for invalid values.
	 *
	 * @param array $options Raw settings.
	 * @return array Sanitized settings.
	 */
	private static function sanitize_option_values( array $options ): array {
		$defaults     = self::get_default_options();
		$accent_color = sanitize_hex_color( (string) ( $options['accent_color'] ?? '' ) );

		return array(
			'promo_image_url' => esc_url_raw( (string) ( $options['promo_image_url'] ?? '' ) ),
			'image_alt'       => sanitize_text_field( (string) ( $options['image_alt'] ?? $defaults['image_alt'] ) ),
			'accent_color'    => $accent_color ? $accent_color : $defaults['accent_color'],
			'terms_text'      => wp_kses( trim( (string) ( $options['terms_text'] ?? $defaults['terms_text'] ) ), self::get_terms_allowed_html() ),
			'terms_required'  => isset( $options['terms_required'] ) ? ! empty( $options['terms_required'] ) : $defaults['terms_required'],
		);
	}

	/**
	 * Enqueues front-end assets for logged-out visitors.
	 */
	public function enqueue_assets(): void {
		if ( ! $this->should_show_popup() ) {
			return;
		}

		$options = self::get_options();

		wp_enqueue_style(
			'auth-gate-popup',
			plugin_dir_url( __FILE__ ) . 'assets/auth-gate.css',
			array(),
			self::VERSION
		);

		wp_add_inline_style(
			'auth-gate-popup',
			'.agp-overlay{--agp-accent:' . esc_html( $options['accent_color'] ) . ';}'
		);

		wp_enqueue_script(
			'auth-gate-popup',
			plugin_dir_url( __FILE__ ) . 'assets/auth-gate.js',
			array( 'jquery' ),
			self::VERSION,
			true
		);

		wp_localize_script(
			'auth-gate-popup',
			'AuthGatePopup',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'fallbackImageUrl' => self::get_default_image_url(),
				'messages'         => array(
					'working'      => __( 'Please wait...', 'auth-gate-popup' ),
					'genericError' => __( 'Something went wrong. Please try again.', 'auth-gate-popup' ),
				),
			)
		);
	}

	/**
	 * Prints the popup markup once per request.
	 */
	public function render_popup(): void {
		if ( $this->popup_rendered || ! $this->should_show_popup() ) {
			return;
		}

		$this->popup_rendered = true;
		$options              = self::get_options();
		$initial_message      = $this->get_password_reset_success_message();
		$nonce                = wp_create_nonce( self::NONCE_ACTION );
		?>
		<div class="agp-overlay" role="dialog" aria-modal="true" aria-labelledby="agp-title">
			<div class="agp-modal" role="document">
				<h2 id="agp-title" class="agp-title"><?php esc_html_e( 'Login or Create Account', 'auth-gate-popup' ); ?></h2>
				<div class="agp-hero">
					<img
						src="<?php echo esc_url( self::get_image_url( $options ) ); ?>"
						data-fallback-src="<?php echo esc_url( self::get_default_image_url() ); ?>"
						alt="<?php echo esc_attr( $options['image_alt'] ); ?>"
					>
				</div>

				<div class="agp-tabs" role="tablist">
					<button class="agp-tab is-active" type="button" data-tab="login" role="tab" aria-selected="true"><?php esc_html_e( 'Login', 'auth-gate-popup' ); ?></button>
					<button class="agp-tab" type="button" data-tab="register" role="tab" aria-selected="false"><?php esc_html_e( 'Sign Up', 'auth-gate-popup' ); ?></button>
				</div>

				<div class="agp-message<?php echo $initial_message ? ' is-visible is-success' : ''; ?>" aria-live="polite"><?php echo esc_html( $initial_message ); ?></div>

				<form class="agp-form is-active" data-form="login" autocomplete="on">
					<input type="hidden" name="action" value="agp_login">
					<input type="hidden" name="<?php echo esc_attr( self::NONCE_NAME ); ?>" value="<?php echo esc_attr( $nonce ); ?>">
					<label>
						<span><?php esc_html_e( 'Email Address', 'auth-gate-popup' ); ?></span>
						<input type="email" name="log" placeholder="<?php esc_attr_e( 'Email Address', 'auth-gate-popup' ); ?>" autocomplete="email" required>
					</label>
					<label>
						<span><?php esc_html_e( 'Password', 'auth-gate-popup' ); ?></span>
						<input type="password" name="pwd" placeholder="<?php esc_attr_e( 'Password', 'auth-gate-popup' ); ?>" autocomplete="current-password" required>
					</label>
					<div class="agp-row">
						<label class="agp-check"><input type="checkbox" name="rememberme" value="forever"> <?php esc_html_e( 'Remember me', 'auth-gate-popup' ); ?></label>
						<a href="#" class="agp-lost-link"><?php esc_html_e( 'Forgot Password?', 'auth-gate-popup' ); ?></a>
					</div>
					<button class="agp-submit" type="submit"><?php esc_html_e( 'Login', 'auth-gate-popup' ); ?></button>
				</form>

				<form class="agp-form" data-form="lost-password" autocomplete="on">
					<input type="hidden" name="action" value="agp_lost_password">
					<input type="hidden" name="<?php echo esc_attr( self::NONCE_NAME ); ?>" value="<?php echo esc_attr( $nonce ); ?>">
					<p class="agp-help-text"><?php esc_html_e( 'Lost your password? Please enter your email address. You will receive a link to create a new password via email.', 'auth-gate-popup' ); ?></p>
					<label>
						<span><?php esc_html_e( 'Email Address', 'auth-gate-popup' ); ?></span>
						<input type="email" name="user_login" placeholder="<?php esc_attr_e( 'Email Address', 'auth-gate-popup' ); ?>" autocomplete="email" required>
					</label>
					<button class="agp-submit" type="submit"><?php esc_html_e( 'Email Reset Link', 'auth-gate-popup' ); ?></button>
					<button class="agp-back-login" type="button"><?php esc_html_e( 'Back to Login', 'auth-gate-popup' ); ?></button>
				</form>

				<form class="agp-form" data-form="register" autocomplete="on">
					<input type="hidden" name="action" value="agp_register">
					<input type="hidden" name="<?php echo esc_attr( self::NONCE_NAME ); ?>" value="<?php echo esc_attr( $nonce ); ?>">
					<div class="agp-name-grid">
						<label>
							<span><?php esc_html_e( 'First Name', 'auth-gate-popup' ); ?></span>
							<input type="text" name="first_name" placeholder="<?php esc_attr_e( 'First Name', 'auth-gate-popup' ); ?>" autocomplete="given-name" required>
						</label>
						<label>
							<span><?php esc_html_e( 'Last Name', 'auth-gate-popup' ); ?></span>
							<input type="text" name="last_name" placeholder="<?php esc_attr_e( 'Last Name', 'auth-gate-popup' ); ?>" autocomplete="family-name" required>
						</label>
					</div>
					<label>
						<span><?php esc_html_e( 'Email address', 'auth-gate-popup' ); ?></span>
						<input type="email" name="email" placeholder="<?php esc_attr_e( 'Email address', 'auth-gate-popup' ); ?>" autocomplete="email" required>
					</label>
					<label>
						<span><?php esc_html_e( 'Password', 'auth-gate-popup' ); ?></span>
						<input type="password" name="password" placeholder="<?php esc_attr_e( 'Password', 'auth-gate-popup' ); ?>" autocomplete="new-password" required minlength="8">
					</label>
					<?php if ( '' !== $options['terms_text'] ) : ?>
						<label class="agp-terms">
							<input type="checkbox" name="terms_agree" value="1"<?php echo $options['terms_required'] ? ' required' : ''; ?>>
							<span><?php echo wp_kses( $options['terms_text'], self::get_terms_allowed_html() ); ?></span>
						</label>
					<?php endif; ?>
					<button class="agp-submit" type="submit"><?php esc_html_e( 'Sign Up', 'auth-gate-popup' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: logs a visitor in.
	 */
	public function ajax_login(): void {
		$this->verify_request();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce checked in verify_request().
		$creds = array(
			'user_login'    => sanitize_text_field( wp_unslash( $_POST['log'] ?? '' ) ),
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be altered.
			'user_password' => (string) wp_unslash( $_POST['pwd'] ?? '' ),
			'remember'      => isset( $_POST['rememberme'] ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$user = wp_signon( $creds, is_ssl() );

		if ( is_wp_error( $user ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid email address or password.', 'auth-gate-popup' ) ), 401 );
		}

		wp_send_json_success( array( 'message' => __( 'Login successful.', 'auth-gate-popup' ) ) );
	}

	/**
	 * AJAX: sends a password reset email.
	 */
	public function ajax_lost_password(): void {
		$this->verify_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked in verify_request().
		$user_login = sanitize_text_field( wp_unslash( $_POST['user_login'] ?? '' ) );

		if ( '' === $user_login ) {
			wp_send_json_error( array( 'message' => __( 'Please enter your email address.', 'auth-gate-popup' ) ), 422 );
		}

		$result = retrieve_password( $user_login );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => wp_strip_all_tags( $result->get_error_message() ) ), 422 );
		}

		wp_send_json_success( array( 'message' => __( 'Password reset email sent. Please check your inbox.', 'auth-gate-popup' ) ) );
	}

	/**
	 * Sets a short-lived cookie so the popup can confirm a password reset.
	 */
	public function set_password_reset_success_cookie(): void {
		if ( headers_sent() ) {
			return;
		}

		setcookie( self::RESET_SUCCESS_COOKIE, '1', time() + 300, self::get_cookie_path(), self::get_cookie_domain(), is_ssl(), true );
		$_COOKIE[ self::RESET_SUCCESS_COOKIE ] = '1';
	}

	/**
	 * AJAX: registers a visitor and logs them in.
	 */
	public function ajax_register(): void {
		$this->verify_request();

		if ( ! get_option( 'users_can_register' ) ) {
			wp_send_json_error( array( 'message' => __( 'Registration is currently disabled. Enable it in WordPress settings.', 'auth-gate-popup' ) ), 403 );
		}

		$options = self::get_options();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce checked in verify_request().
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be altered.
		$password     = (string) wp_unslash( $_POST['password'] ?? '' );
		$first_name   = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last_name    = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
		$terms_agreed = ! empty( $_POST['terms_agree'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $first_name || '' === $last_name ) {
			wp_send_json_error( array( 'message' => __( 'Please enter your first and last name.', 'auth-gate-popup' ) ), 422 );
		}

		if ( '' !== $options['terms_text'] && $options['terms_required'] && ! $terms_agreed ) {
			wp_send_json_error( array( 'message' => __( 'Please accept the terms to create an account.', 'auth-gate-popup' ) ), 422 );
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'auth-gate-popup' ) ), 422 );
		}

		if ( email_exists( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'An account already exists with that email address.', 'auth-gate-popup' ) ), 409 );
		}

		if ( strlen( $password ) < 8 ) {
			wp_send_json_error( array( 'message' => __( 'Password must be at least 8 characters.', 'auth-gate-popup' ) ), 422 );
		}

		$user_id = wp_create_user( $this->generate_username_from_email( $email ), $password, $email );

		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( array( 'message' => wp_strip_all_tags( $user_id->get_error_message() ) ), 500 );
		}

		wp_update_user(
			array(
				'ID'           => $user_id,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => trim( $first_name . ' ' . $last_name ),
			)
		);

		if ( $terms_agreed ) {
			update_user_meta( $user_id, self::TERMS_META_KEY, current_time( 'mysql' ) );
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );

		wp_send_json_success( array( 'message' => __( 'Account created successfully.', 'auth-gate-popup' ) ) );
	}

	/**
	 * Blocks guests from adding products to the cart.
	 *
	 * @param bool $passed Whether validation passed so far.
	 * @return bool
	 */
	public function require_login_before_cart( $passed ): bool {
		if ( is_user_logged_in() ) {
			return (bool) $passed;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'Please log in or create an account before purchasing.', 'auth-gate-popup' ), 'error' );
		}

		return false;
	}

	/**
	 * Makes products non-purchasable for guests.
	 *
	 * @param bool $is_purchasable Whether the product is purchasable.
	 * @return bool
	 */
	public function require_login_for_purchases( $is_purchasable ): bool {
		return is_user_logged_in() ? (bool) $is_purchasable : false;
	}

	/**
	 * Verifies the form nonce or ends the request with a 403 JSON error.
	 */
	private function verify_request(): void {
		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh and try again.', 'auth-gate-popup' ) ), 403 );
		}
	}

	/**
	 * Whether the popup should be shown on the current request.
	 *
	 * @return bool
	 */
	private function should_show_popup(): bool {
		return ! ( is_user_logged_in() || is_admin() || wp_doing_ajax() || $this->is_allowed_auth_page() );
	}

	/**
	 * Requests that must never be gated (login, reset, feeds, REST).
	 *
	 * @return bool
	 */
	private function is_allowed_auth_page(): bool {
		global $pagenow;

		if ( in_array( $pagenow, array( 'wp-login.php', 'wp-register.php' ), true ) ) {
			return true;
		}

		if ( $this->is_password_reset_url() ) {
			return true;
		}

		return is_feed() || is_robots() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/**
	 * Detects password reset links so users can finish the reset flow.
	 *
	 * @return bool
	 */
	private function is_password_reset_url(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing check, no state change.
		$has_reset_key = isset( $_GET['key'], $_GET['login'] )
			&& '' !== sanitize_text_field( wp_unslash( $_GET['key'] ) )
			&& '' !== sanitize_text_field( wp_unslash( $_GET['login'] ) );

		$action = sanitize_key( wp_unslash( $_GET['action'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $has_reset_key || in_array( $action, array( 'rp', 'resetpass' ), true );
	}

	/**
	 * Returns (and consumes) the password reset success message, if any.
	 *
	 * @return string
	 */
	private function get_password_reset_success_message(): string {
		$has_success_cookie = ! empty( $_COOKIE[ self::RESET_SUCCESS_COOKIE ] );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display flag.
		$has_success_query = isset( $_GET['password-reset'] )
			|| isset( $_GET['password_reset'] )
			|| isset( $_GET['reset-password'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $has_success_cookie && ! $has_success_query ) {
			return '';
		}

		if ( $has_success_cookie && ! headers_sent() ) {
			setcookie( self::RESET_SUCCESS_COOKIE, '', time() - 3600, self::get_cookie_path(), self::get_cookie_domain(), is_ssl(), true );
			unset( $_COOKIE[ self::RESET_SUCCESS_COOKIE ] );
		}

		return __( 'Password changed successfully. Please log in with your new password.', 'auth-gate-popup' );
	}

	/**
	 * Cookie path matching WordPress core.
	 *
	 * @return string
	 */
	private static function get_cookie_path(): string {
		return defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
	}

	/**
	 * Cookie domain matching WordPress core.
	 *
	 * @return string
	 */
	private static function get_cookie_domain(): string {
		return defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '';
	}

	/**
	 * Saved settings merged with defaults.
	 *
	 * @return array
	 */
	private static function get_options(): array {
		$saved = get_option( self::OPTION_NAME, array() );

		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::get_default_options() );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	private static function get_default_options(): array {
		return array(
			'promo_image_url' => '',
			'image_alt'       => __( 'Sign in to continue', 'auth-gate-popup' ),
			'accent_color'    => '#053776',
			'terms_text'      => __( 'I agree to the Terms of Service and Privacy Policy.', 'auth-gate-popup' ),
			'terms_required'  => true,
		);
	}

	/**
	 * URL of the bundled default artwork.
	 *
	 * @return string
	 */
	private static function get_default_image_url(): string {
		return plugin_dir_url( __FILE__ ) . 'assets/top-shelf-popup.jpg';
	}

	/**
	 * Configured popup image, or the default artwork.
	 *
	 * @param array $options Plugin settings.
	 * @return string
	 */
	private static function get_image_url( array $options ): string {
		return '' !== $options['promo_image_url'] ? $options['promo_image_url'] : self::get_default_image_url();
	}

	/**
	 * HTML allowed in the terms text.
	 *
	 * @return array
	 */
	private static function get_terms_allowed_html(): array {
		return array(
			'a'      => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'strong' => array(),
			'em'     => array(),
		);
	}

	/**
	 * Builds a unique username from the local part of an email address.
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	private function generate_username_from_email( string $email ): string {
		$base = sanitize_user( current( explode( '@', $email ) ), true );

		if ( '' === $base ) {
			$base = 'user';
		}

		$username = $base;
		$suffix   = 2;

		while ( username_exists( $username ) ) {
			$username = $base . $suffix;
			++$suffix;
		}

		return $username;
	}
}

register_activation_hook( __FILE__, array( 'Auth_Gate_Popup', 'activate' ) );
new Auth_Gate_Popup();
