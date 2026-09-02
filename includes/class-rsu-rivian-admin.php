<?php
/**
 * Admin screen and AJAX endpoints for the Rivian account connection.
 *
 * Holds the interactive connect flow (password → one-time code), the vehicle
 * mapping, poll status, and the server-side proxy the editor uses to fetch a
 * release-notes document (the browser cannot request it cross-origin).
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;

class RSU_Rivian_Admin {

	/** Settings page slug. */
	const PAGE_SLUG = 'rsu-rivian';

	/** Nonce action shared by every endpoint on this screen. */
	const NONCE = 'rsu_rivian';

	/** Transient holding the account's vehicle list. */
	const VEHICLES_TRANSIENT = 'rsu_rivian_vehicles';

	/** Upper bound on a fetched release-notes document (bytes). */
	const MAX_NOTES_BYTES = 20971520; // 20 MB.

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_post_rsu_rivian_notes_pdf', array( $this, 'serve_notes_download' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		$ajax = array(
			'login'       => 'ajax_login',
			'otp'         => 'ajax_otp',
			'disconnect'  => 'ajax_disconnect',
			'save_map'    => 'ajax_save_map',
			'poll_now'    => 'ajax_poll_now',
			'fetch_notes' => 'ajax_fetch_notes',
			'clear_notes' => 'ajax_clear_notes',
			'clear_revised' => 'ajax_clear_revised',
			'import_url'    => 'ajax_import_url',
		);

		foreach ( $ajax as $action => $method ) {
			add_action( 'wp_ajax_rsu_rivian_' . $action, array( $this, $method ) );
		}
	}

	/**
	 * Register the screen under Settings.
	 *
	 * @return void
	 */
	/**
	 * Serve one archived release-notes PDF to an editor.
	 *
	 * The cache directory is not web-readable, so history downloads are streamed
	 * through here behind the same capability check as editing the post.
	 *
	 * @return void
	 */
	public function serve_notes_download() {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		$slug    = isset( $_GET['vehicle'] ) ? sanitize_key( wp_unslash( $_GET['vehicle'] ) ) : '';
		$index   = isset( $_GET['revision'] ) ? absint( $_GET['revision'] ) : 0;
		$gen     = isset( $_GET['generation'] ) ? sanitize_key( wp_unslash( $_GET['generation'] ) ) : '';

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to view this document.', 'rivian-software-updates' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'rsu_rivian_download_' . $post_id . '_' . $slug . '_' . $gen . '_' . $index );

		$path = RSU_Rivian_Poller::revision_path( $post_id, $slug, $index, $gen );

		if ( ! $path ) {
			wp_die( esc_html__( 'That release-notes document is no longer stored.', 'rivian-software-updates' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: inline; filename="' . sprintf( '%s-%s-r%d.pdf', get_post_field( 'post_title', $post_id ), $slug, $index ) . '"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming our own cached file.
		readfile( $path );
		exit;
	}

	public function add_menu_page() {
		add_options_page(
			__( 'Rivian Account', 'rivian-software-updates' ),
			__( 'Rivian Account', 'rivian-software-updates' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Load styles and the connect-flow script on this screen only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		if ( ! file_exists( RSU_PLUGIN_DIR . 'admin/css/rsu-settings' . $suffix . '.css' ) ) {
			$suffix = '';
		}

		wp_enqueue_style(
			'rsu-settings',
			RSU_PLUGIN_URL . 'admin/css/rsu-settings' . $suffix . '.css',
			array(),
			RSU_VERSION
		);

		$js_suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		if ( ! file_exists( RSU_PLUGIN_DIR . 'admin/js/rsu-rivian-connect' . $js_suffix . '.js' ) ) {
			$js_suffix = '';
		}

		wp_enqueue_script(
			'rsu-rivian-connect',
			RSU_PLUGIN_URL . 'admin/js/rsu-rivian-connect' . $js_suffix . '.js',
			array(),
			RSU_VERSION,
			true
		);

		wp_localize_script(
			'rsu-rivian-connect',
			'RSU_RIVIAN',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
			)
		);
	}

	// ────────────────────────────── Screen ──────────────────────────────

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$connected = RSU_Rivian_API::is_connected();
		$session   = RSU_Rivian_API::get_session();

		// A reload part-way through MFA should land back on the code step
		// rather than silently discarding the pending login.
		$awaiting_otp = ! $connected && ! empty( $session['otp_token'] );
		$state     = RSU_Rivian_Poller::get_state();
		$last_run  = isset( $state['last_run'] ) ? $state['last_run'] : array();
		$next_run  = wp_next_scheduled( RSU_Rivian_Poller::CRON_HOOK );
		?>
		<div class="wrap rsu-settings-wrap">
			<div class="rsu-settings-header">
				<h1 class="rsu-settings-title"><?php esc_html_e( 'Rivian Account', 'rivian-software-updates' ); ?></h1>
				<p class="rsu-settings-subtitle">
					<?php esc_html_e( 'Connect your Rivian account to watch for new software updates and draft posts automatically.', 'rivian-software-updates' ); ?>
				</p>
			</div>

			<div class="rsu-notice rsu-notice--info" style="margin-bottom:20px;">
				<?php esc_html_e( 'This uses Rivian\'s private mobile-app API, which is undocumented and unsupported. It can change without notice — if polling starts failing, reconnect here.', 'rivian-software-updates' ); ?>
			</div>

			<div id="rsu-rivian-feedback"></div>

			<div class="rsu-card">
				<div class="rsu-card__header">
					<h2 class="rsu-card__title"><?php esc_html_e( 'Connection', 'rivian-software-updates' ); ?></h2>
					<p class="rsu-card__desc">
						<?php esc_html_e( 'Your password is used once to sign in and is never stored — only the resulting session tokens are kept, encrypted.', 'rivian-software-updates' ); ?>
					</p>
				</div>

				<?php if ( $connected ) : ?>
					<div class="rsu-field-row">
						<span class="rsu-field-label"><?php esc_html_e( 'Status', 'rivian-software-updates' ); ?></span>
						<span class="rsu-field-control">
							<strong style="color:#34c759;">&#9679; <?php esc_html_e( 'Connected', 'rivian-software-updates' ); ?></strong>
							<?php if ( ! empty( $session['email'] ) ) : ?>
								<span style="color:#6e6e73;"> &mdash; <?php echo esc_html( $session['email'] ); ?></span>
							<?php endif; ?>
						</span>
					</div>
					<div class="rsu-field-row">
						<span class="rsu-field-label"><?php esc_html_e( 'Next check', 'rivian-software-updates' ); ?></span>
						<span class="rsu-field-control">
							<?php
							if ( $next_run ) {
								echo esc_html(
									sprintf(
										/* translators: %s: human-readable time difference. */
										__( 'in %s', 'rivian-software-updates' ),
										human_time_diff( time(), $next_run )
									)
								);
							} else {
								esc_html_e( 'Not scheduled', 'rivian-software-updates' );
							}
							?>
							<button type="button" class="rsu-btn rsu-btn-secondary rsu-btn-sm" id="rsu-rivian-poll-now" style="margin-left:8px;">
								<?php esc_html_e( 'Check now', 'rivian-software-updates' ); ?>
							</button>
						</span>
					</div>
					<div class="rsu-field-row">
						<span class="rsu-field-label"><?php esc_html_e( 'Last result', 'rivian-software-updates' ); ?></span>
						<span class="rsu-field-control"><?php echo esc_html( self::describe_last_run( $last_run ) ); ?></span>
					</div>
					<div class="rsu-field-row">
						<span class="rsu-field-label"></span>
						<span class="rsu-field-control">
							<button type="button" class="rsu-btn rsu-btn-danger rsu-btn-sm" id="rsu-rivian-disconnect">
								<?php esc_html_e( 'Disconnect', 'rivian-software-updates' ); ?>
							</button>
						</span>
					</div>
				<?php else : ?>
					<div id="rsu-rivian-login-step" <?php echo $awaiting_otp ? 'hidden' : ''; ?>>
						<div class="rsu-field-row">
							<label class="rsu-field-label" for="rsu-rivian-email"><?php esc_html_e( 'Email', 'rivian-software-updates' ); ?></label>
							<span class="rsu-field-control">
								<input type="email" id="rsu-rivian-email" class="rsu-input" autocomplete="username" />
							</span>
						</div>
						<div class="rsu-field-row">
							<label class="rsu-field-label" for="rsu-rivian-password"><?php esc_html_e( 'Password', 'rivian-software-updates' ); ?></label>
							<span class="rsu-field-control">
								<input type="password" id="rsu-rivian-password" class="rsu-input" autocomplete="current-password" />
							</span>
						</div>
						<div class="rsu-field-row">
							<span class="rsu-field-label"></span>
							<span class="rsu-field-control">
								<button type="button" class="rsu-btn rsu-btn-primary" id="rsu-rivian-login">
									<?php esc_html_e( 'Connect', 'rivian-software-updates' ); ?>
								</button>
							</span>
						</div>
					</div>

					<div id="rsu-rivian-otp-step" <?php echo $awaiting_otp ? '' : 'hidden'; ?>>
						<div class="rsu-field-row">
							<label class="rsu-field-label" for="rsu-rivian-otp"><?php esc_html_e( 'Verification code', 'rivian-software-updates' ); ?></label>
							<span class="rsu-field-control">
								<input type="text" id="rsu-rivian-otp" class="rsu-input" inputmode="numeric" autocomplete="one-time-code" style="max-width:160px;" />
								<button type="button" class="rsu-btn rsu-btn-primary" id="rsu-rivian-verify" style="margin-left:8px;">
									<?php esc_html_e( 'Verify', 'rivian-software-updates' ); ?>
								</button>
								<?php if ( $awaiting_otp ) : ?>
									<p class="description" style="margin-top:8px;">
										<?php esc_html_e( 'Waiting on the verification code Rivian sent you.', 'rivian-software-updates' ); ?>
										<button type="button" class="rsu-btn-link" id="rsu-rivian-restart" style="background:none;border:none;padding:0;color:#0071e3;cursor:pointer;text-decoration:underline;">
											<?php esc_html_e( 'Start over', 'rivian-software-updates' ); ?>
										</button>
									</p>
								<?php endif; ?>
							</span>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( $connected ) : ?>
				<div class="rsu-card">
					<div class="rsu-card__header">
						<h2 class="rsu-card__title"><?php esc_html_e( 'Vehicles', 'rivian-software-updates' ); ?></h2>
						<p class="rsu-card__desc">
							<?php esc_html_e( 'Match each vehicle on your account to the tab its release notes should land under. Unmapped vehicles are ignored.', 'rivian-software-updates' ); ?>
						</p>
					</div>
					<?php self::render_vehicle_map(); ?>
				</div>
				<div class="rsu-card">
					<div class="rsu-card__header">
						<h2 class="rsu-card__title"><?php esc_html_e( 'Last response from Rivian', 'rivian-software-updates' ); ?></h2>
						<p class="rsu-card__desc">
							<?php esc_html_e( 'Exactly what the API returned on the most recent check. Rivian often publishes the release-notes document a while after the version itself appears — polling keeps watching and attaches it when it lands.', 'rivian-software-updates' ); ?>
						</p>
					</div>
					<?php self::render_diagnostics( isset( $state['observed'] ) ? $state['observed'] : array() ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the per-vehicle view of the last OTA response.
	 *
	 * @param array $observed Observations keyed by Rivian vehicle id.
	 * @return void
	 */
	private static function render_diagnostics( $observed ) {
		if ( empty( $observed ) || ! is_array( $observed ) ) {
			printf(
				'<div class="rsu-notice rsu-notice--info">%s</div>',
				esc_html__( 'Nothing recorded yet — run "Check now" above.', 'rivian-software-updates' )
			);

			return;
		}

		$platforms = RSU_Platforms::get_all();

		foreach ( $observed as $entry ) {
			$slug  = isset( $entry['slug'] ) ? $entry['slug'] : '';
			$label = isset( $platforms[ $slug ]['label'] ) ? $platforms[ $slug ]['label'] : strtoupper( $slug );

			foreach ( array( 'available' => __( 'Available', 'rivian-software-updates' ), 'current' => __( 'Current', 'rivian-software-updates' ) ) as $key => $heading ) {
				$detail = isset( $entry[ $key ] ) ? $entry[ $key ] : array();

				if ( empty( $detail['version'] ) ) {
					continue;
				}
				?>
				<div class="rsu-field-row">
					<span class="rsu-field-label">
						<?php echo esc_html( $label . ' — ' . $heading ); ?>
						<small style="display:block;color:#86868b;font-weight:400;font-family:'SF Mono',Monaco,monospace;">
							<?php echo esc_html( $detail['version'] ); ?>
						</small>
					</span>
					<span class="rsu-field-control">
						<?php if ( ! empty( $detail['url'] ) ) : ?>
							<span style="color:#0a5e2a;font-weight:600;">&#10003; <?php esc_html_e( 'Release notes available', 'rivian-software-updates' ); ?></span>
							<a href="<?php echo esc_url( $detail['url'] ); ?>" target="_blank" rel="noopener noreferrer"
								style="display:block;margin-top:4px;font-size:12px;word-break:break-all;">
								<?php echo esc_html( $detail['url'] ); ?>
							</a>
						<?php elseif ( ! empty( $detail['url_rejected'] ) ) : ?>
							<span style="color:#c41e3a;font-weight:600;">
								<?php
								printf(
									/* translators: %s: hostname. */
									esc_html__( 'Document offered on an unrecognized host: %s', 'rivian-software-updates' ),
									'<code>' . esc_html( $detail['url_rejected'] ) . '</code>'
								);
								?>
							</span>
							<small style="display:block;margin-top:4px;color:#6e6e73;">
								<?php esc_html_e( 'Add it to the rsu_rivian_allowed_notes_hosts filter to allow fetching from there.', 'rivian-software-updates' ); ?>
							</small>
						<?php else : ?>
							<span style="color:#856404;font-weight:600;">&#9679; <?php esc_html_e( 'Rivian returned no release-notes document yet', 'rivian-software-updates' ); ?></span>
							<small style="display:block;margin-top:4px;color:#6e6e73;">
								<?php esc_html_e( 'Still watching — the draft is updated and you are emailed when it appears.', 'rivian-software-updates' ); ?>
							</small>
						<?php endif; ?>
					</span>
				</div>
				<?php
			}
		}
	}

	/**
	 * Render the vehicle → tab mapping rows.
	 *
	 * @return void
	 */
	private static function render_vehicle_map() {
		// Cache briefly so reloading the screen does not re-query the API on
		// every render; short enough that a newly added vehicle shows up fast.
		$vehicles = get_transient( self::VEHICLES_TRANSIENT );

		if ( false === $vehicles ) {
			$vehicles = RSU_Rivian_API::get_vehicles();

			if ( ! is_wp_error( $vehicles ) ) {
				set_transient( self::VEHICLES_TRANSIENT, $vehicles, 5 * MINUTE_IN_SECONDS );
			}
		}

		if ( is_wp_error( $vehicles ) ) {
			printf(
				'<div class="rsu-notice rsu-notice--error">%s</div>',
				esc_html( $vehicles->get_error_message() )
			);

			return;
		}

		if ( empty( $vehicles ) ) {
			printf(
				'<div class="rsu-notice rsu-notice--warning">%s</div>',
				esc_html__( 'No vehicles found on this account.', 'rivian-software-updates' )
			);

			return;
		}

		$map      = RSU_Rivian_Poller::get_map();
		$platforms = RSU_Platforms::get_all();

		foreach ( $vehicles as $vehicle ) {
			$label = $vehicle['name'] ? $vehicle['name'] : $vehicle['model'];
			$meta  = trim( $vehicle['model_year'] . ' ' . $vehicle['model'] );
			$value = isset( $map[ $vehicle['id'] ] ) ? $map[ $vehicle['id'] ] : '';
			?>
			<div class="rsu-field-row">
				<span class="rsu-field-label">
					<?php echo esc_html( $label ? $label : __( 'Vehicle', 'rivian-software-updates' ) ); ?>
					<?php if ( $meta ) : ?>
						<small style="display:block;color:#86868b;font-weight:400;"><?php echo esc_html( $meta ); ?></small>
					<?php endif; ?>
					<?php if ( $vehicle['vin'] ) : ?>
						<small style="display:block;color:#86868b;font-weight:400;font-family:'SF Mono',Monaco,monospace;font-size:11px;">
							<?php echo esc_html( substr( $vehicle['vin'], -6 ) ); ?>
						</small>
					<?php endif; ?>
				</span>
				<span class="rsu-field-control">
					<select class="rsu-select rsu-rivian-map" data-vehicle-id="<?php echo esc_attr( $vehicle['id'] ); ?>">
						<option value=""><?php esc_html_e( '— Ignore —', 'rivian-software-updates' ); ?></option>
						<?php foreach ( $platforms as $slug => $platform ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $value, $slug ); ?>>
								<?php echo esc_html( $platform['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</span>
			</div>
			<?php
		}
		?>
		<div class="rsu-field-row">
			<span class="rsu-field-label"></span>
			<span class="rsu-field-control">
				<button type="button" class="rsu-btn rsu-btn-primary" id="rsu-rivian-save-map">
					<?php esc_html_e( 'Save vehicles', 'rivian-software-updates' ); ?>
				</button>
			</span>
		</div>
		<?php
	}

	/**
	 * Summarize the last poll for the status row.
	 *
	 * @param array $run Last-run report.
	 * @return string
	 */
	private static function describe_last_run( $run ) {
		if ( empty( $run['ran_at'] ) ) {
			return __( 'Has not run yet.', 'rivian-software-updates' );
		}

		$when = sprintf(
			/* translators: %s: human-readable time difference. */
			__( '%s ago', 'rivian-software-updates' ),
			human_time_diff( $run['ran_at'], time() )
		);

		if ( ! empty( $run['errors'] ) ) {
			return $when . ' — ' . implode( '; ', array_map( 'strval', $run['errors'] ) );
		}

		$found = ! empty( $run['detections'] ) ? count( $run['detections'] ) : 0;

		if ( $found ) {
			return $when . ' — ' . sprintf(
				/* translators: %d: number of updates found. */
				_n( '%d new update found.', '%d new updates found.', $found, 'rivian-software-updates' ),
				$found
			);
		}

		return $when . ' — ' . sprintf(
			/* translators: %d: number of vehicles checked. */
			_n( '%d vehicle checked, nothing new.', '%d vehicles checked, nothing new.', (int) $run['checked'], 'rivian-software-updates' ),
			(int) $run['checked']
		);
	}

	// ─────────────────────────────── AJAX ───────────────────────────────

	/**
	 * Verify nonce + capability for a management endpoint.
	 *
	 * @return void Dies with a JSON error when the request is not allowed.
	 */
	private function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'rivian-software-updates' ) ), 403 );
		}

		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Reload the page.', 'rivian-software-updates' ) ), 403 );
		}
	}

	/**
	 * Step one of the connect flow.
	 *
	 * @return void
	 */
	public function ajax_login() {
		$this->guard();

		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sent verbatim to Rivian, never stored or echoed.

		if ( ! is_email( $email ) || '' === $password ) {
			wp_send_json_error( array( 'message' => __( 'Enter your Rivian email and password.', 'rivian-software-updates' ) ) );
		}

		$result = RSU_Rivian_API::login( $email, $password );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( ! empty( $result['mfa'] ) ) {
			wp_send_json_success(
				array(
					'mfa'     => true,
					'message' => __( 'Rivian sent a verification code. Enter it below.', 'rivian-software-updates' ),
				)
			);
		}

		RSU_Rivian_Poller::ensure_scheduled();

		wp_send_json_success(
			array(
				'mfa'     => false,
				'message' => __( 'Connected.', 'rivian-software-updates' ),
			)
		);
	}

	/**
	 * Step two of the connect flow.
	 *
	 * @return void
	 */
	public function ajax_otp() {
		$this->guard();

		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

		if ( '' === $code ) {
			wp_send_json_error( array( 'message' => __( 'Enter the verification code.', 'rivian-software-updates' ) ) );
		}

		$result = RSU_Rivian_API::login_with_otp( $code );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		RSU_Rivian_Poller::ensure_scheduled();

		wp_send_json_success( array( 'message' => __( 'Connected.', 'rivian-software-updates' ) ) );
	}

	/**
	 * Forget the stored session and stop polling.
	 *
	 * @return void
	 */
	public function ajax_disconnect() {
		$this->guard();

		RSU_Rivian_API::clear_session();
		RSU_Rivian_Poller::unschedule();
		delete_transient( self::VEHICLES_TRANSIENT );

		wp_send_json_success( array( 'message' => __( 'Disconnected.', 'rivian-software-updates' ) ) );
	}

	/**
	 * Persist the vehicle → tab mapping.
	 *
	 * @return void
	 */
	public function ajax_save_map() {
		$this->guard();

		$raw = isset( $_POST['map'] ) ? wp_unslash( $_POST['map'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in save_map().

		RSU_Rivian_Poller::save_map( is_array( $raw ) ? $raw : array() );

		wp_send_json_success( array( 'message' => __( 'Vehicles saved.', 'rivian-software-updates' ) ) );
	}

	/**
	 * Run the poll immediately.
	 *
	 * @return void
	 */
	public function ajax_poll_now() {
		$this->guard();

		$report = RSU_Rivian_Poller::run();

		wp_send_json_success(
			array(
				'message' => self::describe_last_run( $report ),
				'found'   => ! empty( $report['detections'] ) ? count( $report['detections'] ) : 0,
			)
		);
	}

	/**
	 * Proxy a release-notes document to the editor.
	 *
	 * The browser cannot fetch Rivian's URL directly (no CORS headers), so the
	 * bytes are pulled server-side and handed back base64-encoded for pdf.js.
	 *
	 * @return void
	 */
	public function ajax_fetch_notes() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Reload the page.', 'rivian-software-updates' ) ), 403 );
		}

		$post_id    = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$slug       = isset( $_POST['vehicle'] ) ? sanitize_key( wp_unslash( $_POST['vehicle'] ) ) : '';
		$generation = isset( $_POST['generation'] ) ? sanitize_key( wp_unslash( $_POST['generation'] ) ) : '';

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this post.', 'rivian-software-updates' ) ), 403 );
		}

		// The poller downloads the document while its signed link is fresh, so
		// the cached copy is the normal path; the URL is only a fallback for
		// documents recorded before caching existed.
		$cached = RSU_Rivian_Poller::cached_notes_path( $post_id, $slug, $generation );

		if ( $cached ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- binary read from our own upload subdirectory.
			$body = file_get_contents( $cached );

			if ( false !== $body && '' !== $body ) {
				wp_send_json_success(
					array(
						'contentType' => 'application/pdf',
						'data'        => base64_encode( $body ),
					)
				);
			}
		}

		// Only ever fetch a URL this plugin recorded for this post and vehicle.
		$url = get_post_meta( $post_id, RSU_Rivian_Poller::notes_meta_key( $slug, $generation ), true );

		if ( ! $url || ! RSU_Rivian_API::is_allowed_notes_url( $url ) ) {
			wp_send_json_error( array( 'message' => __( 'No release-notes document is pending for this vehicle.', 'rivian-software-updates' ) ) );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'  => 30,
				'headers'  => array( 'Accept' => 'application/pdf,*/*' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		// The document is fetched from a CDN host, so bound what is accepted
		// rather than trusting the response: PDFs of release notes are small.
		if ( strlen( $body ) > self::MAX_NOTES_BYTES ) {
			wp_send_json_error(
				array(
					'message' => __( 'That release-notes document is unexpectedly large — refusing to load it.', 'rivian-software-updates' ),
				)
			);
		}

		// pdf.js needs a PDF; anything else means the URL is not what we expect.
		if ( '' !== $body && 0 !== strpos( $body, '%PDF-' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'That release-notes link did not return a PDF. Use Import to paste the notes instead.', 'rivian-software-updates' ),
				)
			);
		}

		if ( 403 === $code || 401 === $code ) {
			wp_send_json_error(
				array(
					'message' => __( 'Rivian\'s release-notes link has expired — they are only valid for about an hour. Use Import to upload the PDF by hand.', 'rivian-software-updates' ),
				)
			);
		}

		if ( $code < 200 || $code >= 300 || '' === $body ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: HTTP status code. */
						__( 'Could not download the release notes (HTTP %d).', 'rivian-software-updates' ),
						$code
					),
				)
			);
		}

		wp_send_json_success(
			array(
				'contentType' => wp_remote_retrieve_header( $response, 'content-type' ),
				'data'        => base64_encode( $body ),
			)
		);
	}

	/**
	 * Drop the pending notes URL once the editor has imported it.
	 *
	 * @return void
	 */
	public function ajax_clear_notes() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Reload the page.', 'rivian-software-updates' ) ), 403 );
		}

		$post_id    = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$slug       = isset( $_POST['vehicle'] ) ? sanitize_key( wp_unslash( $_POST['vehicle'] ) ) : '';
		$generation = isset( $_POST['generation'] ) ? sanitize_key( wp_unslash( $_POST['generation'] ) ) : '';

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this post.', 'rivian-software-updates' ) ), 403 );
		}

		// Keep the archived PDFs — dismissing only stops the import prompt.
		RSU_Rivian_Poller::forget_notes( $post_id, $slug, true, $generation );

		wp_send_json_success();
	}

	/**
	 * Import a release-notes document from a link pasted by an editor.
	 *
	 * Rivian only signs links for vehicles on the connected account, so notes
	 * for a generation nobody here owns arrive this way. The link is fetched
	 * server-side (the browser cannot, and the signature expires within the
	 * hour), archived like a polled document, and handed back for parsing.
	 *
	 * @return void
	 */
	public function ajax_import_url() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Reload the page.', 'rivian-software-updates' ) ), 403 );
		}

		$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$url      = isset( $_POST['url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['url'] ) ) ) : '';
		$override = isset( $_POST['vehicle'] ) ? sanitize_key( wp_unslash( $_POST['vehicle'] ) ) : '';

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this post.', 'rivian-software-updates' ) ), 403 );
		}

		if ( ! $url ) {
			wp_send_json_error( array( 'message' => __( 'Paste a Rivian release-notes link.', 'rivian-software-updates' ) ) );
		}

		$result = RSU_Rivian_Poller::ingest_url( $post_id, $url, $override );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$path = RSU_Rivian_Poller::cached_notes_path( $post_id, $result['vehicle'], $result['generation'] );

		if ( ! $path ) {
			wp_send_json_error( array( 'message' => __( 'The document was fetched but could not be stored.', 'rivian-software-updates' ) ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- binary read from our own upload subdirectory.
		$body = file_get_contents( $path );

		// A link for a different release is almost always a paste mistake, so
		// say so rather than silently filing it under the wrong post. A post
		// titled with the release family ("2026.31") owns every build in that
		// family (2026.31.00, 2026.31.30), as does one whose recorded builds
		// name the version outright.
		$title    = (string) get_post_field( 'post_title', $post_id );
		$mismatch = '';

		if ( $result['version'] && ! RSU_Rivian_Poller::post_covers_version( $post_id, $result['version'] ) ) {
			$mismatch = sprintf(
				/* translators: 1: version from the link, 2: post title. */
				__( 'Heads up: that link is for version %1$s, but this post is "%2$s".', 'rivian-software-updates' ),
				$result['version'],
				$title
			);
		}

		wp_send_json_success(
			array(
				'vehicle'    => $result['vehicle'],
				'generation' => $result['generation'],
				'label'      => $result['label'],
				'version'    => $result['version'],
				'model'      => $result['model'],
				'draft'      => $result['draft'],
				'revision'   => $result['revision'],
				'mismatch'   => $mismatch,
				'data'       => base64_encode( $body ),
			)
		);
	}

	/**
	 * Dismiss the "notes were revised" prompt without touching the history.
	 *
	 * @return void
	 */
	public function ajax_clear_revised() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Reload the page.', 'rivian-software-updates' ) ), 403 );
		}

		$post_id    = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$slug       = isset( $_POST['vehicle'] ) ? sanitize_key( wp_unslash( $_POST['vehicle'] ) ) : '';
		$generation = isset( $_POST['generation'] ) ? sanitize_key( wp_unslash( $_POST['generation'] ) ) : '';

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this post.', 'rivian-software-updates' ) ), 403 );
		}

		RSU_Rivian_Poller::clear_revised_flag( $post_id, $slug, $generation );

		wp_send_json_success();
	}
}
