<?php
/**
 * Quote request storage, public form and private workflow.
 *
 * @package Digicyfr_Service_Quote_Desk_PL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns the public quote form and administrator workflow. */
final class Digicyfr_Service_Quote_Desk_PL {
	private const OPTION   = 'digicyfr_quote_desk_settings';
	private const CRON     = 'digicyfr_quote_desk_retention';
	private const STATUSES = array( 'new', 'contacted', 'quote_sent', 'won', 'lost' );

	/** Return the site-specific request table name. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'digicyfr_quote_requests';
	}

	/** Create the request table and schedule retention. */
	public static function activate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at_gmt datetime NOT NULL,
			service varchar(120) NOT NULL DEFAULT '',
			location varchar(120) NOT NULL DEFAULT '',
			details text NOT NULL,
			customer_name varchar(120) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			preferred_time varchar(120) NOT NULL DEFAULT '',
			source_url varchar(500) NOT NULL DEFAULT '',
			status varchar(24) NOT NULL DEFAULT 'new',
			assignee_id bigint(20) unsigned NOT NULL DEFAULT 0,
			follow_up_date date DEFAULT NULL,
			first_contacted_at_gmt datetime DEFAULT NULL,
			quote_sent_at_gmt datetime DEFAULT NULL,
			closed_at_gmt datetime DEFAULT NULL,
			internal_note text NOT NULL,
			notification_status varchar(16) NOT NULL DEFAULT 'pending',
			PRIMARY KEY  (id),
			KEY status_created (status,created_at_gmt),
			KEY email (email),
			KEY follow_up_date (follow_up_date)
		) $charset;";
		dbDelta( $sql );
		if ( ! get_option( self::OPTION ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/** Stop the scheduled retention task. */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	/** Register all public and private WordPress hooks. */
	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_shortcode( 'digicyfr_service_quote', array( __CLASS__, 'render_form' ) );
		add_action( 'admin_post_nopriv_digicyfr_quote_submit', array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_post_digicyfr_quote_submit', array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_post_digicyfr_quote_update', array( __CLASS__, 'handle_update' ) );
		add_action( 'admin_post_digicyfr_quote_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_digicyfr_quote_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_digicyfr_quote_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( self::CRON, array( __CLASS__, 'purge_expired' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'privacy_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'privacy_eraser' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
	}

	/** Return default site settings. */
	private static function defaults(): array {
		return array(
			'notification_email' => get_option( 'admin_email' ),
			'services'           => '',
			'retention_days'     => 180,
			'privacy_url'        => '',
		);
	}

	/** Return saved settings merged with defaults. */
	private static function settings(): array {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	/** Register the public block, scripts and translations. */
	public static function register_block(): void {
		load_plugin_textdomain( 'digicyfr-service-quote-desk-pl', false, dirname( plugin_basename( DIGICYFR_QUOTE_DESK_PATH . 'digicyfr-service-quote-desk-pl.php' ) ) . '/languages' );
		wp_register_script( 'digicyfr-quote-desk-editor', plugins_url( 'blocks/quote-form/editor.js', DIGICYFR_QUOTE_DESK_PATH . 'digicyfr-service-quote-desk-pl.php' ), array( 'wp-blocks', 'wp-element', 'wp-components' ), '0.1.1', true );
		wp_localize_script(
			'digicyfr-quote-desk-editor',
			'digicyfrQuoteDeskLabels',
			array(
				'title'       => __( 'Service Quote Request', 'digicyfr-service-quote-desk-pl' ),
				'description' => __( 'Show a service quote request form.', 'digicyfr-service-quote-desk-pl' ),
				'preview'     => __( 'The request form appears on the published page.', 'digicyfr-service-quote-desk-pl' ),
			)
		);
		wp_register_style( 'digicyfr-quote-desk-form', plugins_url( 'assets/form.css', DIGICYFR_QUOTE_DESK_PATH . 'digicyfr-service-quote-desk-pl.php' ), array(), '0.1.1' );
		wp_register_script( 'digicyfr-quote-desk-form', plugins_url( 'assets/form.js', DIGICYFR_QUOTE_DESK_PATH . 'digicyfr-service-quote-desk-pl.php' ), array(), '0.1.1', true );
		register_block_type( DIGICYFR_QUOTE_DESK_PATH . 'blocks/quote-form', array( 'render_callback' => array( __CLASS__, 'render_form' ) ) );
	}

	/** Return distinct configured service labels. */
	private static function service_choices(): array {
		$lines   = preg_split( '/\R/u', (string) self::settings()['services'] );
		$choices = array();
		foreach ( $lines as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( '' !== $line && ! in_array( $line, $choices, true ) ) {
				$choices[] = $line;
			}
		}
		return array_slice( $choices, 0, 30 );
	}

	/** Render the public request form. */
	public static function render_form(): string {
		wp_enqueue_style( 'digicyfr-quote-desk-form' );
		wp_enqueue_script( 'digicyfr-quote-desk-form' );
		$settings    = self::settings();
		$privacy_url = $settings['privacy_url'] ? $settings['privacy_url'] : get_privacy_policy_url();
		$source      = get_permalink( get_queried_object_id() );
		if ( ! $source ) {
			$source = home_url( '/' );
		}
		$prefix = wp_unique_id( 'digicyfr-quote-' );
		$state  = isset( $_GET['digicyfr_quote'] ) ? sanitize_key( wp_unslash( $_GET['digicyfr_quote'] ) ) : '';
		ob_start();
		?>
		<div class="digicyfr-quote-desk">
		<?php if ( 'success' === $state ) : ?>
			<p class="digicyfr-quote-message" role="status"><?php esc_html_e( 'Thank you. Your request has been saved.', 'digicyfr-service-quote-desk-pl' ); ?></p>
		<?php elseif ( 'expired' === $state ) : ?>
			<p class="digicyfr-quote-error" role="alert"><?php esc_html_e( 'The form expired. Reload the page and try again.', 'digicyfr-service-quote-desk-pl' ); ?></p>
		<?php elseif ( 'error' === $state ) : ?>
			<p class="digicyfr-quote-error" role="alert"><?php esc_html_e( 'Please check the required fields and try again.', 'digicyfr-service-quote-desk-pl' ); ?></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="digicyfr_quote_submit">
			<input type="hidden" name="source_url" value="<?php echo esc_url( $source ); ?>">
			<?php wp_nonce_field( 'digicyfr_quote_submit', 'digicyfr_quote_nonce' ); ?>
			<div class="digicyfr-quote-trap" aria-hidden="true"><label for="<?php echo esc_attr( $prefix . 'website' ); ?>"><?php esc_html_e( 'Website', 'digicyfr-service-quote-desk-pl' ); ?></label><input id="<?php echo esc_attr( $prefix . 'website' ); ?>" name="website" type="text" tabindex="-1" autocomplete="off"></div>
			<?php $choices = self::service_choices(); ?>
			<p><label for="<?php echo esc_attr( $prefix . 'service' ); ?>"><?php esc_html_e( 'Service', 'digicyfr-service-quote-desk-pl' ); ?> <span aria-hidden="true">*</span></label>
			<?php if ( $choices ) : ?>
				<select id="<?php echo esc_attr( $prefix . 'service' ); ?>" name="service" required><option value=""><?php esc_html_e( 'Choose a service', 'digicyfr-service-quote-desk-pl' ); ?></option>
				<?php
				foreach ( $choices as $choice ) :
					?>
					<option value="<?php echo esc_attr( $choice ); ?>"><?php echo esc_html( $choice ); ?></option><?php endforeach; ?></select>
			<?php else : ?>
				<input id="<?php echo esc_attr( $prefix . 'service' ); ?>" name="service" type="text" maxlength="120" required>
			<?php endif; ?></p>
			<p><label for="<?php echo esc_attr( $prefix . 'location' ); ?>"><?php esc_html_e( 'City or postcode', 'digicyfr-service-quote-desk-pl' ); ?> <span aria-hidden="true">*</span></label><input id="<?php echo esc_attr( $prefix . 'location' ); ?>" name="location" type="text" maxlength="120" required autocomplete="address-level2"></p>
			<p><label for="<?php echo esc_attr( $prefix . 'details' ); ?>"><?php esc_html_e( 'What work do you need?', 'digicyfr-service-quote-desk-pl' ); ?> <span aria-hidden="true">*</span></label><textarea id="<?php echo esc_attr( $prefix . 'details' ); ?>" name="details" maxlength="2000" rows="5" required></textarea></p>
			<p><label for="<?php echo esc_attr( $prefix . 'name' ); ?>"><?php esc_html_e( 'Your name', 'digicyfr-service-quote-desk-pl' ); ?> <span aria-hidden="true">*</span></label><input id="<?php echo esc_attr( $prefix . 'name' ); ?>" name="customer_name" type="text" maxlength="120" autocomplete="name" required></p>
			<p><label for="<?php echo esc_attr( $prefix . 'email' ); ?>"><?php esc_html_e( 'Email', 'digicyfr-service-quote-desk-pl' ); ?></label><input id="<?php echo esc_attr( $prefix . 'email' ); ?>" name="email" type="email" maxlength="190" autocomplete="email" aria-describedby="<?php echo esc_attr( $prefix . 'contact-hint' ); ?>"></p>
			<p><label for="<?php echo esc_attr( $prefix . 'phone' ); ?>"><?php esc_html_e( 'Phone', 'digicyfr-service-quote-desk-pl' ); ?></label><input id="<?php echo esc_attr( $prefix . 'phone' ); ?>" name="phone" type="tel" maxlength="40" autocomplete="tel" aria-describedby="<?php echo esc_attr( $prefix . 'contact-hint' ); ?>"></p>
			<p class="digicyfr-quote-hint" id="<?php echo esc_attr( $prefix . 'contact-hint' ); ?>" data-contact-hint><?php esc_html_e( 'Provide an email address or phone number so we can reply.', 'digicyfr-service-quote-desk-pl' ); ?></p>
			<p><label for="<?php echo esc_attr( $prefix . 'time' ); ?>"><?php esc_html_e( 'Preferred callback time (optional)', 'digicyfr-service-quote-desk-pl' ); ?></label><input id="<?php echo esc_attr( $prefix . 'time' ); ?>" name="preferred_time" type="text" maxlength="120"></p>
			<?php
			if ( $privacy_url ) :
				?>
				<p class="digicyfr-quote-hint"><a href="<?php echo esc_url( $privacy_url ); ?>"><?php esc_html_e( 'How we use your information', 'digicyfr-service-quote-desk-pl' ); ?></a></p><?php endif; ?>
			<p><button type="submit"><?php esc_html_e( 'Request a quote', 'digicyfr-service-quote-desk-pl' ); ?></button></p>
		</form></div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Read and sanitize a bounded plain-text field.
	 *
	 * @param array  $raw The raw value.
	 * @param string $key The key value.
	 * @param int    $limit The limit value.
	 */
	private static function text_value( array $raw, string $key, int $limit ): string {
		$value = isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $raw[ $key ] ) ) : '';
		return self::length( $value ) <= $limit ? trim( $value ) : '';
	}

	/**
	 * Count Unicode characters when possible.
	 *
	 * @param string $value The value value.
	 */
	private static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	/**
	 * Validate, save and notify for a public request.
	 *
	 * @param array $raw The raw value.
	 */
	public static function process_submission( array $raw ): WP_Error|int {
		$nonce = isset( $raw['digicyfr_quote_nonce'] ) && is_scalar( $raw['digicyfr_quote_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $raw['digicyfr_quote_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'digicyfr_quote_submit' ) ) {
			return new WP_Error( 'nonce', __( 'The form expired. Reload the page and try again.', 'digicyfr-service-quote-desk-pl' ) );
		}
		$trap = isset( $raw['website'] ) ? ( is_scalar( $raw['website'] ) ? trim( wp_unslash( (string) $raw['website'] ) ) : 'filled' ) : '';
		if ( '' !== $trap ) {
			return new WP_Error( 'spam', __( 'Request rejected.', 'digicyfr-service-quote-desk-pl' ) );
		}
		$service      = self::text_value( $raw, 'service', 120 );
		$location     = self::text_value( $raw, 'location', 120 );
		$name         = self::text_value( $raw, 'customer_name', 120 );
		$email        = self::text_value( $raw, 'email', 190 );
		$phone        = self::text_value( $raw, 'phone', 40 );
		$phone_digits = preg_replace( '/\D/', '', $phone );
		$time         = self::text_value( $raw, 'preferred_time', 120 );
		$details      = isset( $raw['details'] ) && is_scalar( $raw['details'] ) ? trim( sanitize_textarea_field( wp_unslash( (string) $raw['details'] ) ) ) : '';
		if ( '' === $service || '' === $location || '' === $name || '' === $details || self::length( $details ) > 2000 || ( '' === $email && '' === $phone ) || ( '' !== $email && ! is_email( $email ) ) || ( '' !== $phone && ( ! preg_match( '/^\+?[0-9 ()-]{6,40}$/', $phone ) || strlen( $phone_digits ) < 7 || strlen( $phone_digits ) > 15 ) ) ) {
			return new WP_Error( 'fields', __( 'Please check the required fields and try again.', 'digicyfr-service-quote-desk-pl' ) );
		}
		$choices = self::service_choices();
		if ( $choices && ! in_array( $service, $choices, true ) ) {
			return new WP_Error( 'service', __( 'Please choose a listed service.', 'digicyfr-service-quote-desk-pl' ) );
		}
		$ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$rate_key = 'digicyfr_quote_rate_' . hash( 'sha256', $ip . '|' . wp_salt( 'nonce' ) );
		if ( (int) get_transient( $rate_key ) >= 5 ) {
			return new WP_Error( 'rate', __( 'Too many requests. Please try again later.', 'digicyfr-service-quote-desk-pl' ) );
		}
		global $wpdb;
		$source   = self::safe_source( $raw['source_url'] ?? '' );
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'created_at_gmt'      => current_time( 'mysql', true ),
				'service'             => $service,
				'location'            => $location,
				'details'             => $details,
				'customer_name'       => $name,
				'email'               => $email,
				'phone'               => $phone,
				'preferred_time'      => $time,
				'source_url'          => $source,
				'status'              => 'new',
				'internal_note'       => '',
				'notification_status' => 'pending',
			)
		);
		if ( false === $inserted ) {
			return new WP_Error( 'storage', __( 'The request could not be saved. Please try again.', 'digicyfr-service-quote-desk-pl' ) );
		}
		$id = (int) $wpdb->insert_id;
		set_transient( $rate_key, (int) get_transient( $rate_key ) + 1, HOUR_IN_SECONDS );
		$recipient = self::settings()['notification_email'];
		/* translators: %d: quote request ID. */
		$subject   = sprintf( __( 'New quote request #%d', 'digicyfr-service-quote-desk-pl' ), $id );
		/* translators: 1: request ID, 2: service, 3: location, 4: name, 5: email, 6: phone, 7: details, 8: admin review URL. */
		$body      = sprintf( __( "New request #%1\$d\nService: %2\$s\nLocation: %3\$s\nName: %4\$s\nEmail: %5\$s\nPhone: %6\$s\nDetails: %7\$s\n\nReview: %8\$s", 'digicyfr-service-quote-desk-pl' ), $id, $service, $location, $name, $email, $phone, $details, admin_url( 'admin.php?page=digicyfr-quote-desk&request=' . $id ) );
		$accepted  = is_email( $recipient ) && wp_mail( $recipient, $subject, $body );
		$wpdb->update( self::table(), array( 'notification_status' => $accepted ? 'accepted' : 'failed' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		return $id;
	}

	/**
	 * Return a same-host HTTP source URL.
	 *
	 * @param mixed $raw The raw value.
	 */
	private static function safe_source( mixed $raw ): string {
		$source = is_scalar( $raw ) ? esc_url_raw( wp_unslash( (string) $raw ) ) : '';
		if ( '' === $source || strtolower( (string) wp_parse_url( $source, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return home_url( '/' );
		}
		if ( ! in_array( strtolower( (string) wp_parse_url( $source, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true ) ) {
			return home_url( '/' );
		}
		return self::length( $source ) <= 500 ? $source : home_url( '/' );
	}

	/** Process a public form POST and redirect back. */
	public static function handle_submit(): void {
		// The submission nonce is checked by process_submission before storage.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- This handler delegates nonce verification to process_submission.
		$source = self::safe_source( isset( $_POST['source_url'] ) && is_scalar( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['source_url'] ) ) : '' );
		$result = self::process_submission( $_POST );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$state  = is_wp_error( $result ) ? ( 'nonce' === $result->get_error_code() ? 'expired' : 'error' ) : 'success';
		$url    = add_query_arg( 'digicyfr_quote', $state, $source );
		wp_safe_redirect( $url, 303 );
		exit;
	}

	/** Register the private inbox and settings menu. */
	public static function admin_menu(): void {
		global $wpdb;
		$new_count  = current_user_can( 'manage_options' ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', self::table(), 'new' ) ) : 0;
		$menu_label = __( 'Quote Desk', 'digicyfr-service-quote-desk-pl' );
		if ( $new_count ) {
			$menu_label .= ' <span class="awaiting-mod count-' . (int) $new_count . '"><span class="pending-count">' . (int) $new_count . '</span></span>';
		}
		add_menu_page( __( 'Quote Desk', 'digicyfr-service-quote-desk-pl' ), $menu_label, 'manage_options', 'digicyfr-quote-desk', array( __CLASS__, 'admin_page' ), 'dashicons-email-alt2', 31 );
		add_submenu_page( 'digicyfr-quote-desk', __( 'Settings', 'digicyfr-service-quote-desk-pl' ), __( 'Settings', 'digicyfr-service-quote-desk-pl' ), 'manage_options', 'digicyfr-quote-settings', array( __CLASS__, 'settings_page' ) );
	}

	/**
	 * Load CSS only on the plugin admin screens.
	 *
	 * @param string $hook The hook value.
	 */
	public static function admin_assets( string $hook ): void {
		if ( false !== strpos( $hook, 'digicyfr-quote-' ) ) {
			wp_enqueue_style( 'digicyfr-quote-desk-admin', plugins_url( 'assets/admin.css', DIGICYFR_QUOTE_DESK_PATH . 'digicyfr-service-quote-desk-pl.php' ), array(), '0.1.1' );
		}
	}

	/**
	 * Require administrator capability and a valid nonce.
	 *
	 * @param string $nonce_action The nonce action value.
	 * @param string $nonce_field The nonce field value.
	 */
	private static function assert_admin( string $nonce_action, string $nonce_field = '_wpnonce' ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage quote requests.', 'digicyfr-service-quote-desk-pl' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce_action, $nonce_field );
	}

	/** Return localized workflow labels. */
	private static function statuses(): array {
		return array(
			'new'        => __( 'New', 'digicyfr-service-quote-desk-pl' ),
			'contacted'  => __( 'Contacted', 'digicyfr-service-quote-desk-pl' ),
			'quote_sent' => __( 'Quote sent', 'digicyfr-service-quote-desk-pl' ),
			'won'        => __( 'Won', 'digicyfr-service-quote-desk-pl' ),
			'lost'       => __( 'Lost', 'digicyfr-service-quote-desk-pl' ),
		);
	}

	/**
	 * Return a localized notification status.
	 *
	 * @param string $status The status value.
	 */
	private static function notification_label( string $status ): string {
		return match ( $status ) {
			'accepted' => __( 'Accepted by WordPress mail (delivery unconfirmed)', 'digicyfr-service-quote-desk-pl' ),
			'failed' => __( 'Failed; check mail settings', 'digicyfr-service-quote-desk-pl' ),
			default => __( 'Pending', 'digicyfr-service-quote-desk-pl' ),
		};
	}

	/** Render the request list or one request. */
	public static function admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$id = isset( $_GET['request'] ) ? absint( $_GET['request'] ) : 0;
		if ( $id ) {
			self::detail_page( $id );
		} else {
			self::list_page();
		}
	}

	/** Render paginated requests and counts. */
	private static function list_page(): void {
		global $wpdb;
		$table  = self::table();
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status = '';
		}
		$page   = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		$offset = ( $page - 1 ) * 25;
		if ( $status ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $table, $status ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT id,created_at_gmt,service,customer_name,status,follow_up_date,notification_status FROM %i WHERE status = %s ORDER BY created_at_gmt DESC,id DESC LIMIT %d OFFSET %d', $table, $status, 25, $offset ) );
		} else {
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT id,created_at_gmt,service,customer_name,status,follow_up_date,notification_status FROM %i ORDER BY created_at_gmt DESC,id DESC LIMIT %d OFFSET %d', $table, 25, $offset ) );
		}
		$counts  = $wpdb->get_results( $wpdb->prepare( 'SELECT status,COUNT(*) AS total FROM %i GROUP BY status', $table ) );
		$sources = $wpdb->get_results( $wpdb->prepare( 'SELECT source_url,COUNT(*) AS total FROM %i GROUP BY source_url ORDER BY total DESC LIMIT 10', $table ) );
		$summary = array_fill_keys( self::STATUSES, 0 );
		foreach ( $counts as $item ) {
			if ( isset( $summary[ $item->status ] ) ) {
				$summary[ $item->status ] = (int) $item->total;
			}
		}
		?>
		<div class="wrap digicyfr-quote-admin"><h1><?php esc_html_e( 'Quote Desk', 'digicyfr-service-quote-desk-pl' ); ?></h1>
		<?php
		if ( isset( $_GET['updated'] ) ) :
			?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Request updated.', 'digicyfr-service-quote-desk-pl' ); ?></p></div><?php endif; ?>
		<?php
		if ( isset( $_GET['deleted'] ) ) :
			?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Request deleted.', 'digicyfr-service-quote-desk-pl' ); ?></p></div><?php endif; ?>
		<p><?php esc_html_e( 'Private customer requests. Dates use the WordPress site timezone.', 'digicyfr-service-quote-desk-pl' ); ?></p>
		<ul class="digicyfr-quote-counts">
		<?php
		foreach ( self::statuses() as $key => $label ) :
			$filter_url = add_query_arg(
				array(
					'page'   => 'digicyfr-quote-desk',
					'status' => $key,
				),
				admin_url( 'admin.php' )
			);
			?>
			<li><a href="<?php echo esc_url( $filter_url ); ?>"><?php echo esc_html( $label ); ?>: <strong><?php echo esc_html( (string) $summary[ $key ] ); ?></strong></a></li><?php endforeach; ?></ul>
		<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=digicyfr_quote_export' ), 'digicyfr_quote_export' ) ); ?>"><?php esc_html_e( 'Export CSV', 'digicyfr-service-quote-desk-pl' ); ?></a> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=digicyfr-quote-settings' ) ); ?>"><?php esc_html_e( 'Settings', 'digicyfr-service-quote-desk-pl' ); ?></a></p>
		<form method="get"><input type="hidden" name="page" value="digicyfr-quote-desk"><label for="digicyfr-status-filter"><?php esc_html_e( 'Status', 'digicyfr-service-quote-desk-pl' ); ?></label> <select id="digicyfr-status-filter" name="status"><option value=""><?php esc_html_e( 'All', 'digicyfr-service-quote-desk-pl' ); ?></option>
		<?php
		foreach ( self::statuses() as $key => $label ) :
			?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select> <button class="button"><?php esc_html_e( 'Filter', 'digicyfr-service-quote-desk-pl' ); ?></button></form>
		<div class="digicyfr-quote-table-wrap"><table class="widefat striped"><thead><tr><th scope="col"><?php esc_html_e( 'Received', 'digicyfr-service-quote-desk-pl' ); ?></th><th scope="col"><?php esc_html_e( 'Customer', 'digicyfr-service-quote-desk-pl' ); ?></th><th scope="col"><?php esc_html_e( 'Service', 'digicyfr-service-quote-desk-pl' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'digicyfr-service-quote-desk-pl' ); ?></th><th scope="col"><?php esc_html_e( 'Follow-up', 'digicyfr-service-quote-desk-pl' ); ?></th><th scope="col"><?php esc_html_e( 'Email notice', 'digicyfr-service-quote-desk-pl' ); ?></th></tr></thead><tbody>
		<?php
		foreach ( $rows as $row ) :
			?>
			<tr><td><?php echo esc_html( get_date_from_gmt( $row->created_at_gmt, 'Y-m-d H:i' ) ); ?></td><td><a href="<?php echo esc_url( admin_url( 'admin.php?page=digicyfr-quote-desk&request=' . (int) $row->id ) ); ?>"><?php echo esc_html( $row->customer_name ); ?> #<?php echo esc_html( (string) $row->id ); ?></a></td><td><?php echo esc_html( $row->service ); ?></td><td><?php echo esc_html( self::statuses()[ $row->status ] ?? $row->status ); ?></td><td><?php echo esc_html( $row->follow_up_date ? $row->follow_up_date : '—' ); ?></td><td><?php echo esc_html( self::notification_label( $row->notification_status ) ); ?></td></tr><?php endforeach; ?>
		<?php
		if ( ! $rows ) :
			?>
			<tr><td colspan="6"><?php esc_html_e( 'No requests found.', 'digicyfr-service-quote-desk-pl' ); ?></td></tr><?php endif; ?></tbody></table></div>
		<?php
		if ( $total > 25 ) :
			?>
			<p>
			<?php
			if ( $page > 1 ) :
				$previous_url = add_query_arg(
					array(
						'page'   => 'digicyfr-quote-desk',
						'status' => $status,
						'paged'  => $page - 1,
					),
					admin_url( 'admin.php' )
				);
				?>
			<a href="<?php echo esc_url( $previous_url ); ?>"><?php esc_html_e( 'Previous', 'digicyfr-service-quote-desk-pl' ); ?></a><?php endif; ?> <?php /* translators: 1: current page number, 2: total page count. */ echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'digicyfr-service-quote-desk-pl' ), $page, (int) ceil( $total / 25 ) ) ); ?>
			<?php
			if ( $page * 25 < $total ) :
				$next_url = add_query_arg(
					array(
						'page'   => 'digicyfr-quote-desk',
						'status' => $status,
						'paged'  => $page + 1,
					),
					admin_url( 'admin.php' )
				);
				?>
			<a href="<?php echo esc_url( $next_url ); ?>"><?php esc_html_e( 'Next', 'digicyfr-service-quote-desk-pl' ); ?></a><?php endif; ?></p><?php endif; ?>
		<h2><?php esc_html_e( 'Top source pages', 'digicyfr-service-quote-desk-pl' ); ?></h2><ul>
		<?php
		foreach ( $sources as $source ) :
			?>
			<li><a href="<?php echo esc_url( $source->source_url ); ?>"><?php echo esc_html( $source->source_url ); ?></a>: <?php echo esc_html( (string) $source->total ); ?></li><?php endforeach; ?></ul></div>
		<?php
	}

	/**
	 * Render one request and its workflow controls.
	 *
	 * @param int $id The id value.
	 */
	private static function detail_page( int $id ): void {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ) );
		if ( ! $row ) {
			wp_die( esc_html__( 'Request not found.', 'digicyfr-service-quote-desk-pl' ), '', array( 'response' => 404 ) );
		}
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'fields' => array( 'ID', 'display_name' ),
			)
		);
		?>
		<div class="wrap digicyfr-quote-admin"><h1><?php /* translators: %d: quote request ID. */ echo esc_html( sprintf( __( 'Request #%d', 'digicyfr-service-quote-desk-pl' ), $id ) ); ?></h1><p><a href="<?php echo esc_url( admin_url( 'admin.php?page=digicyfr-quote-desk' ) ); ?>"><?php esc_html_e( 'Back to requests', 'digicyfr-service-quote-desk-pl' ); ?></a></p>
		<?php
		if ( isset( $_GET['updated'] ) ) :
			?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Request updated.', 'digicyfr-service-quote-desk-pl' ); ?></p></div><?php endif; ?>
		<dl class="digicyfr-quote-details"><dt><?php esc_html_e( 'Received', 'digicyfr-service-quote-desk-pl' ); ?></dt><dd><?php echo esc_html( get_date_from_gmt( $row->created_at_gmt, 'Y-m-d H:i' ) ); ?></dd><dt><?php esc_html_e( 'Service', 'digicyfr-service-quote-desk-pl' ); ?></dt><dd><?php echo esc_html( $row->service ); ?></dd><dt><?php esc_html_e( 'City or postcode', 'digicyfr-service-quote-desk-pl' ); ?></dt><dd><?php echo esc_html( $row->location ); ?></dd><dt><?php esc_html_e( 'Customer', 'digicyfr-service-quote-desk-pl' ); ?></dt><dd><?php echo esc_html( $row->customer_name ); ?></dd><dt><?php esc_html_e( 'Email', 'digicyfr-service-quote-desk-pl' ); ?></dt><dd>
		<?php
		if ( $row->email ) :
			?>
			<a href="mailto:<?php echo esc_attr( $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a><?php endif; ?></dd><dt><?php esc_html_e( 'Phone', 'digicyfr-service-quote-desk-pl' ); ?></dt><dd><?php echo esc_html( $row->phone ); ?></dd><dt><?php esc_html_e( 'Preferred callback time', 'digicyfr-service-quote-desk-pl' ); ?></dt><dd><?php echo esc_html( $row->preferred_time ); ?></dd><dt><?php esc_html_e( 'What work do you need?', 'digicyfr-service-quote-desk-pl' ); ?></dt><dd class="digicyfr-quote-preline"><?php echo esc_html( $row->details ); ?></dd><dt><?php esc_html_e( 'Source page', 'digicyfr-service-quote-desk-pl' ); ?></dt><dd><a href="<?php echo esc_url( $row->source_url ); ?>"><?php echo esc_html( $row->source_url ); ?></a></dd><dt><?php esc_html_e( 'Email notice', 'digicyfr-service-quote-desk-pl' ); ?></dt><dd><?php echo esc_html( self::notification_label( $row->notification_status ) ); ?></dd></dl>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="digicyfr_quote_update"><input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $id ); ?>"><?php wp_nonce_field( 'digicyfr_quote_update_' . $id ); ?>
		<table class="form-table" role="presentation"><tr><th><label for="digicyfr-quote-status"><?php esc_html_e( 'Status', 'digicyfr-service-quote-desk-pl' ); ?></label></th><td><select id="digicyfr-quote-status" name="status">
		<?php
		foreach ( self::statuses() as $key => $label ) :
			?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $row->status, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr><tr><th><label for="digicyfr-quote-assignee"><?php esc_html_e( 'Assignee', 'digicyfr-service-quote-desk-pl' ); ?></label></th><td><select id="digicyfr-quote-assignee" name="assignee_id"><option value="0"><?php esc_html_e( 'Unassigned', 'digicyfr-service-quote-desk-pl' ); ?></option>
			<?php
			foreach ( $admins as $admin ) :
				?>
			<option value="<?php echo esc_attr( (string) $admin->ID ); ?>" <?php selected( (int) $row->assignee_id, (int) $admin->ID ); ?>><?php echo esc_html( $admin->display_name ); ?></option><?php endforeach; ?></select></td></tr><tr><th><label for="digicyfr-quote-followup"><?php esc_html_e( 'Follow-up date', 'digicyfr-service-quote-desk-pl' ); ?></label></th><td><input id="digicyfr-quote-followup" name="follow_up_date" type="date" value="<?php echo esc_attr( $row->follow_up_date ? $row->follow_up_date : '' ); ?>"><p class="description"><?php esc_html_e( 'A site-local calendar date. No automatic reminder is sent.', 'digicyfr-service-quote-desk-pl' ); ?></p></td></tr><tr><th><label for="digicyfr-quote-note"><?php esc_html_e( 'Internal note', 'digicyfr-service-quote-desk-pl' ); ?></label></th><td><textarea id="digicyfr-quote-note" name="internal_note" rows="6" maxlength="2000" class="large-text"><?php echo esc_textarea( $row->internal_note ); ?></textarea></td></tr></table><?php submit_button( __( 'Save request', 'digicyfr-service-quote-desk-pl' ) ); ?></form>
		<h2><?php esc_html_e( 'Delete request', 'digicyfr-service-quote-desk-pl' ); ?></h2><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="digicyfr_quote_delete"><input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $id ); ?>"><?php wp_nonce_field( 'digicyfr_quote_delete_' . $id ); ?><p><label><input type="checkbox" name="confirm_delete" value="1" required> <?php esc_html_e( 'I understand this permanently deletes this request.', 'digicyfr-service-quote-desk-pl' ); ?></label></p><button class="button button-link-delete" type="submit"><?php esc_html_e( 'Delete permanently', 'digicyfr-service-quote-desk-pl' ); ?></button></form></div>
		<?php
	}

	/** Save an administrator workflow update. */
	public static function handle_update(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- assert_admin verifies the request-specific nonce before mutation.
		$id = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
		self::assert_admin( 'digicyfr_quote_update_' . $id );
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ) );
		if ( ! $row ) {
			wp_die( esc_html__( 'Request not found.', 'digicyfr-service-quote-desk-pl' ), '', array( 'response' => 404 ) );
		}
		$status     = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$assignee   = isset( $_POST['assignee_id'] ) ? absint( $_POST['assignee_id'] ) : 0;
		$date       = self::text_value( $_POST, 'follow_up_date', 10 );
		$note       = isset( $_POST['internal_note'] ) && is_scalar( $_POST['internal_note'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['internal_note'] ) ) : '';
		$valid_date = '' === $date || ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && DateTimeImmutable::createFromFormat( '!Y-m-d', $date )?->format( 'Y-m-d' ) === $date );
		if ( ! in_array( $status, self::STATUSES, true ) || ( $assignee && ! user_can( $assignee, 'manage_options' ) ) || ! $valid_date || self::length( $note ) > 2000 ) {
			wp_die( esc_html__( 'Invalid request update.', 'digicyfr-service-quote-desk-pl' ), '', array( 'response' => 400 ) );
		}
		$data = array(
			'status'         => $status,
			'assignee_id'    => $assignee,
			'follow_up_date' => $date ? $date : null,
			'internal_note'  => $note,
		);
		$now  = current_time( 'mysql', true );
		if ( 'new' !== $status && ! $row->first_contacted_at_gmt ) {
			$data['first_contacted_at_gmt'] = $now;
		}
		if ( 'quote_sent' === $status && ! $row->quote_sent_at_gmt ) {
			$data['quote_sent_at_gmt'] = $now;
		}
		if ( in_array( $status, array( 'won', 'lost' ), true ) && ! $row->closed_at_gmt ) {
			$data['closed_at_gmt'] = $now;
		}
		if ( ! in_array( $status, array( 'won', 'lost' ), true ) ) {
			$data['closed_at_gmt'] = null;
		}
		if ( false === $wpdb->update( self::table(), $data, array( 'id' => $id ) ) ) {
			wp_die( esc_html__( 'Could not save the update.', 'digicyfr-service-quote-desk-pl' ), '', array( 'response' => 500 ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=digicyfr-quote-desk&request=' . $id . '&updated=1' ), 303 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		exit;
	}

	/** Permanently delete one confirmed request. */
	public static function handle_delete(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- assert_admin verifies the request-specific nonce before deletion.
		$id = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
		self::assert_admin( 'digicyfr_quote_delete_' . $id );
		if ( empty( $_POST['confirm_delete'] ) || 1 !== absint( $_POST['confirm_delete'] ) ) {
			wp_die( esc_html__( 'Confirm permanent deletion.', 'digicyfr-service-quote-desk-pl' ), '', array( 'response' => 400 ) );
		}
		global $wpdb;
		if ( 1 !== $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) ) ) {
			wp_die( esc_html__( 'Request not found or deletion failed.', 'digicyfr-service-quote-desk-pl' ), '', array( 'response' => 404 ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=digicyfr-quote-desk&deleted=1' ), 303 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		exit;
	}

	/** Render plugin settings. */
	public static function settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = self::settings();
		?>
		<div class="wrap digicyfr-quote-admin"><h1><?php esc_html_e( 'Quote Desk Settings', 'digicyfr-service-quote-desk-pl' ); ?></h1>
		<?php
		if ( isset( $_GET['updated'] ) ) :
			?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Settings saved.', 'digicyfr-service-quote-desk-pl' ); ?></p></div><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="digicyfr_quote_settings"><?php wp_nonce_field( 'digicyfr_quote_settings' ); ?><table class="form-table" role="presentation">
		<tr><th><label for="digicyfr-notification-email"><?php esc_html_e( 'Notification email', 'digicyfr-service-quote-desk-pl' ); ?></label></th><td><input id="digicyfr-notification-email" name="notification_email" type="email" required class="regular-text" value="<?php echo esc_attr( $s['notification_email'] ); ?>"><p class="description"><?php esc_html_e( 'A saved request remains in the inbox even if email is not accepted by WordPress mail.', 'digicyfr-service-quote-desk-pl' ); ?></p></td></tr>
		<tr><th><label for="digicyfr-services"><?php esc_html_e( 'Services', 'digicyfr-service-quote-desk-pl' ); ?></label></th><td><textarea id="digicyfr-services" name="services" rows="8" class="large-text"><?php echo esc_textarea( $s['services'] ); ?></textarea><p class="description"><?php esc_html_e( 'One service per line, up to 30. Leave blank for a free-text service field.', 'digicyfr-service-quote-desk-pl' ); ?></p></td></tr>
		<tr><th><label for="digicyfr-retention"><?php esc_html_e( 'Retention (days)', 'digicyfr-service-quote-desk-pl' ); ?></label></th><td><input id="digicyfr-retention" name="retention_days" type="number" min="30" max="3650" value="<?php echo esc_attr( (string) $s['retention_days'] ); ?>"><p class="description"><?php esc_html_e( 'Requests older than this are deleted daily, including open requests. Default: 180 days.', 'digicyfr-service-quote-desk-pl' ); ?></p></td></tr>
		<tr><th><label for="digicyfr-privacy-url"><?php esc_html_e( 'Privacy notice URL', 'digicyfr-service-quote-desk-pl' ); ?></label></th><td><input id="digicyfr-privacy-url" name="privacy_url" type="url" class="regular-text" value="<?php echo esc_attr( $s['privacy_url'] ); ?>"><p class="description"><?php esc_html_e( 'Link to your own privacy notice. Review the legal basis and wording for your business.', 'digicyfr-service-quote-desk-pl' ); ?></p></td></tr></table><?php submit_button(); ?></form></div>
		<?php
	}

	/** Validate and save plugin settings. */
	public static function handle_settings(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- assert_admin verifies the settings nonce before mutation.
		self::assert_admin( 'digicyfr_quote_settings' );
		$email    = self::text_value( $_POST, 'notification_email', 190 );
		$days     = isset( $_POST['retention_days'] ) ? absint( $_POST['retention_days'] ) : 0;
		$services = isset( $_POST['services'] ) && is_scalar( $_POST['services'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['services'] ) ) : '';
		$url      = isset( $_POST['privacy_url'] ) && is_scalar( $_POST['privacy_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['privacy_url'] ) ) : '';
		if ( ! is_email( $email ) || $days < 30 || $days > 3650 || strlen( $services ) > 6000 || ( $url && ! wp_http_validate_url( $url ) ) ) {
			wp_die( esc_html__( 'Invalid settings.', 'digicyfr-service-quote-desk-pl' ), '', array( 'response' => 400 ) );
		}
		$lines = preg_split( '/\R/u', $services );
		if ( count( array_filter( $lines, 'strlen' ) ) > 30 ) {
			wp_die( esc_html__( 'Use no more than 30 services.', 'digicyfr-service-quote-desk-pl' ), '', array( 'response' => 400 ) );
		}
		foreach ( $lines as $line ) {
			if ( self::length( trim( $line ) ) > 120 ) {
				wp_die( esc_html__( 'A service name is too long.', 'digicyfr-service-quote-desk-pl' ), '', array( 'response' => 400 ) );
			}
		}
		update_option(
			self::OPTION,
			array(
				'notification_email' => $email,
				'services'           => $services,
				'retention_days'     => $days,
				'privacy_url'        => $url,
			),
			false
		);
		wp_safe_redirect( admin_url( 'admin.php?page=digicyfr-quote-settings&updated=1' ), 303 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		exit;
	}

	/**
	 * Neutralize spreadsheet formulas in CSV cells.
	 *
	 * @param mixed $value The value value.
	 */
	private static function csv_cell( mixed $value ): string {
		$text = (string) $value;
		if ( preg_match( '/^[\x00-\x20]*[=+@\-]/u', $text ) ) {
			$text = "'" . $text;
		}
		return $text;
	}

	/** Stream requests to an administrator CSV. */
	public static function handle_export(): void {
		self::assert_admin( 'digicyfr_quote_export' );
		global $wpdb;
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="digicyfr-quote-requests.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'id', 'created_at_gmt', 'service', 'location', 'details', 'customer_name', 'email', 'phone', 'preferred_time', 'source_url', 'status', 'assignee_id', 'follow_up_date', 'first_contacted_at_gmt', 'quote_sent_at_gmt', 'closed_at_gmt', 'internal_note', 'notification_status' ) );
		$last = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d ORDER BY id ASC LIMIT 200', self::table(), $last ), ARRAY_A );
			foreach ( $rows as $row ) {
				$last = (int) $row['id'];
				fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), array_values( $row ) ) );
			}
			$row_count = count( $rows );
		} while ( 200 === $row_count );
		exit;
	}

	/** Delete requests beyond the configured retention period. */
	public static function purge_expired(): void {
		global $wpdb;
		$days = (int) self::settings()['retention_days'];
		if ( $days < 30 || $days > 3650 ) {
			return;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at_gmt < %s', self::table(), $cutoff ) );
	}

	/**
	 * Register the WordPress personal data exporter.
	 *
	 * @param array $exporters The exporters value.
	 */
	public static function privacy_exporter( array $exporters ): array {
		$exporters['digicyfr-service-quote-desk-pl'] = array(
			'exporter_friendly_name' => __( 'Service Quote Desk', 'digicyfr-service-quote-desk-pl' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * Export requests matching an email address.
	 *
	 * @param string $email The email value.
	 * @param int    $page The page value.
	 */
	public static function export_personal_data( string $email, int $page = 1 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d', self::table(), $email, 50, ( max( 1, $page ) - 1 ) * 50 ) );
		$data = array();
		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => 'digicyfr-service-quote-desk-pl',
				'group_label' => __( 'Service quote requests', 'digicyfr-service-quote-desk-pl' ),
				'item_id'     => 'quote-' . $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Received', 'digicyfr-service-quote-desk-pl' ),
						'value' => $row->created_at_gmt,
					),
					array(
						'name'  => __( 'Service', 'digicyfr-service-quote-desk-pl' ),
						'value' => $row->service,
					),
					array(
						'name'  => __( 'City or postcode', 'digicyfr-service-quote-desk-pl' ),
						'value' => $row->location,
					),
					array(
						'name'  => __( 'What work do you need?', 'digicyfr-service-quote-desk-pl' ),
						'value' => $row->details,
					),
					array(
						'name'  => __( 'Your name', 'digicyfr-service-quote-desk-pl' ),
						'value' => $row->customer_name,
					),
					array(
						'name'  => __( 'Email', 'digicyfr-service-quote-desk-pl' ),
						'value' => $row->email,
					),
					array(
						'name'  => __( 'Phone', 'digicyfr-service-quote-desk-pl' ),
						'value' => $row->phone,
					),
					array(
						'name'  => __( 'Preferred callback time (optional)', 'digicyfr-service-quote-desk-pl' ),
						'value' => $row->preferred_time,
					),
					array(
						'name'  => __( 'Internal note', 'digicyfr-service-quote-desk-pl' ),
						'value' => $row->internal_note,
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < 50,
		);
	}

	/**
	 * Register the WordPress personal data eraser.
	 *
	 * @param array $erasers The erasers value.
	 */
	public static function privacy_eraser( array $erasers ): array {
		$erasers['digicyfr-service-quote-desk-pl'] = array(
			'eraser_friendly_name' => __( 'Service Quote Desk', 'digicyfr-service-quote-desk-pl' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Erase requests matching an email address.
	 *
	 * @param string $email The email value.
	 * @param int    $page The page value.
	 */
	public static function erase_personal_data( string $email, int $page = 1 ): array {
		global $wpdb;
		$rows   = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE email = %s ORDER BY id ASC LIMIT 50', self::table(), $email ) );
		$failed = false;
		foreach ( $rows as $id ) {
			$failed = false === $wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) ) || $failed;
		}
		return array(
			'items_removed'  => ! $failed && (bool) $rows,
			'items_retained' => $failed,
			'messages'       => $failed ? array( __( 'Some requests could not be erased.', 'digicyfr-service-quote-desk-pl' ) ) : array(),
			'done'           => count( $rows ) < 50,
		);
	}
}
