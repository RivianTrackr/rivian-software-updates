<?php
/**
 * Client for Rivian's mobile-app GraphQL gateway.
 *
 * This is an UNOFFICIAL, undocumented API — the same endpoint the Rivian
 * consumer app talks to. Rivian can change or revoke it without notice, so
 * every response here is treated as untrusted shape: we probe for fields
 * rather than assuming them, and surface the raw payload in the connection
 * test so a schema drift is diagnosable instead of silent.
 *
 * Auth is a three-step dance:
 *   1. createCsrfToken  → csrfToken + appSessionToken (unauthenticated)
 *   2. login            → either tokens, or an otpToken when MFA is on
 *   3. loginWithOTP     → tokens, exchanging the emailed/SMS code
 *
 * Tokens are then refreshed via refreshToken. Because step 3 needs a human
 * to read a one-time code, the initial connect is interactive (settings
 * screen) and only the refresh runs unattended from cron.
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;

class RSU_Rivian_API {

	/** GraphQL gateway used by the consumer app. */
	const GATEWAY = 'https://rivian.com/api/gql/gateway/graphql';

	/** Option holding the session/token bundle (never autoloaded). */
	const SESSION_KEY = 'rsu_rivian_session';

	/** Client name the gateway expects; requests without it are rejected. */
	const CLIENT_NAME = 'com.rivian.android.consumer';

	/**
	 * In-request cache of the decrypted session bundle.
	 *
	 * @var array|null
	 */
	private static $session = null;

	// ─────────────────────────── Session storage ───────────────────────────

	/**
	 * Read the stored session bundle.
	 *
	 * @return array
	 */
	public static function get_session() {
		if ( null !== self::$session ) {
			return self::$session;
		}

		$raw = get_option( self::SESSION_KEY, array() );

		if ( is_string( $raw ) && '' !== $raw ) {
			$raw = json_decode( self::decrypt( $raw ), true );
		}

		self::$session = is_array( $raw ) ? $raw : array();

		return self::$session;
	}

	/**
	 * Persist the session bundle, merging over what is already stored.
	 *
	 * @param array $data Keys to write.
	 * @return void
	 */
	public static function update_session( array $data ) {
		$session = array_merge( self::get_session(), $data );

		self::$session = $session;

		update_option(
			self::SESSION_KEY,
			self::encrypt( wp_json_encode( $session ) ),
			false // Never autoload — this holds credentials.
		);
	}

	/**
	 * Drop all stored session state.
	 *
	 * @return void
	 */
	public static function clear_session() {
		self::$session = array();
		delete_option( self::SESSION_KEY );
	}

	/**
	 * Whether we hold tokens that can be used (or refreshed) right now.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$session = self::get_session();

		return ! empty( $session['access_token'] ) && ! empty( $session['user_session_token'] );
	}

	// ───────────────────────────── Encryption ─────────────────────────────

	/**
	 * Encrypt a string with the site's AUTH_KEY.
	 *
	 * Falls back to storing plaintext when OpenSSL or the salts are missing —
	 * the option is non-autoloaded and DB-only either way, but on a normally
	 * configured site the refresh token is not readable from a DB dump alone.
	 *
	 * @param string $value Plaintext.
	 * @return string Encrypted payload, or the input when unavailable.
	 */
	private static function encrypt( $value ) {
		if ( ! function_exists( 'openssl_encrypt' ) || ! defined( 'AUTH_KEY' ) || ! AUTH_KEY ) {
			return $value;
		}

		$key = hash( 'sha256', AUTH_KEY, true );
		$iv  = openssl_random_pseudo_bytes( 16 );

		$cipher = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $cipher ) {
			return $value;
		}

		return 'rsuenc:' . base64_encode( $iv . $cipher );
	}

	/**
	 * Reverse self::encrypt().
	 *
	 * @param string $value Stored payload.
	 * @return string Plaintext.
	 */
	private static function decrypt( $value ) {
		if ( 0 !== strpos( $value, 'rsuenc:' ) ) {
			return $value; // Stored before encryption was available.
		}

		if ( ! function_exists( 'openssl_decrypt' ) || ! defined( 'AUTH_KEY' ) || ! AUTH_KEY ) {
			return '';
		}

		$blob = base64_decode( substr( $value, 7 ), true );

		if ( false === $blob || strlen( $blob ) <= 16 ) {
			return '';
		}

		$key   = hash( 'sha256', AUTH_KEY, true );
		$plain = openssl_decrypt( substr( $blob, 16 ), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr( $blob, 0, 16 ) );

		return false === $plain ? '' : $plain;
	}

	// ──────────────────────────── Transport ────────────────────────────

	/**
	 * POST a GraphQL operation to the gateway.
	 *
	 * @param string $operation Operation name (sent as operationName).
	 * @param string $query     Query/mutation document.
	 * @param array  $variables Variables map.
	 * @param array  $args      'authed' => bool (send u-sess/access token).
	 * @return array|WP_Error Decoded 'data' payload, or error.
	 */
	private static function request( $operation, $query, $variables = array(), $args = array() ) {
		$authed  = ! empty( $args['authed'] );
		$session = self::get_session();

		$headers = array(
			'Content-Type'              => 'application/json',
			'Accept'                    => 'application/json',
			'apollographql-client-name' => self::CLIENT_NAME,
			'User-Agent'                => 'RivianSoftwareUpdates/' . RSU_VERSION . '; ' . home_url(),
		);

		if ( ! empty( $session['csrf_token'] ) ) {
			$headers['Csrf-Token'] = $session['csrf_token'];
		}
		if ( ! empty( $session['app_session_token'] ) ) {
			$headers['A-Sess'] = $session['app_session_token'];
		}
		if ( $authed && ! empty( $session['user_session_token'] ) ) {
			$headers['U-Sess'] = $session['user_session_token'];
		}
		if ( $authed && ! empty( $session['access_token'] ) ) {
			$headers['Authorization'] = 'Bearer ' . $session['access_token'];
		}

		$response = wp_remote_post(
			self::GATEWAY,
			array(
				'timeout' => 20,
				'headers' => $headers,
				'body'    => wp_json_encode(
					array(
						'operationName' => $operation,
						'query'         => $query,
						'variables'     => (object) $variables,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		if ( ! is_array( $json ) ) {
			return new WP_Error(
				'rsu_rivian_bad_response',
				sprintf(
					/* translators: 1: HTTP status code, 2: response excerpt. */
					__( 'Rivian returned an unreadable response (HTTP %1$d): %2$s', 'rivian-software-updates' ),
					$code,
					esc_html( substr( $body, 0, 200 ) )
				)
			);
		}

		// GraphQL reports failures in-band with HTTP 200, so check errors first.
		if ( ! empty( $json['errors'] ) && is_array( $json['errors'] ) ) {
			$messages = array();
			foreach ( $json['errors'] as $error ) {
				if ( ! empty( $error['message'] ) ) {
					$messages[] = sanitize_text_field( $error['message'] );
				}
			}

			return new WP_Error(
				'rsu_rivian_graphql_error',
				$messages
					? implode( ' / ', $messages )
					: __( 'Rivian rejected the request.', 'rivian-software-updates' ),
				array( 'errors' => $json['errors'] )
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'rsu_rivian_http_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Rivian returned HTTP %d.', 'rivian-software-updates' ),
					$code
				)
			);
		}

		return isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : array();
	}

	// ────────────────────────────── Auth ──────────────────────────────

	/**
	 * Fetch a CSRF + app session token. Required before login.
	 *
	 * @return true|WP_Error
	 */
	public static function create_csrf_token() {
		$data = self::request(
			'CreateCSRFToken',
			'mutation CreateCSRFToken { createCsrfToken { __typename csrfToken appSessionToken } }'
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$token = isset( $data['createCsrfToken'] ) ? $data['createCsrfToken'] : array();

		if ( empty( $token['csrfToken'] ) || empty( $token['appSessionToken'] ) ) {
			return new WP_Error(
				'rsu_rivian_no_csrf',
				__( 'Rivian did not return a CSRF token.', 'rivian-software-updates' )
			);
		}

		self::update_session(
			array(
				'csrf_token'        => $token['csrfToken'],
				'app_session_token' => $token['appSessionToken'],
			)
		);

		return true;
	}

	/**
	 * Begin a login. Returns whether MFA is required.
	 *
	 * @param string $email    Account email.
	 * @param string $password Account password.
	 * @return array|WP_Error array{ mfa: bool } on success.
	 */
	public static function login( $email, $password ) {
		$csrf = self::create_csrf_token();

		if ( is_wp_error( $csrf ) ) {
			return $csrf;
		}

		$data = self::request(
			'Login',
			'mutation Login($email: String!, $password: String!) {
				login(email: $email, password: $password) {
					__typename
					... on MobileLoginResponse { __typename accessToken refreshToken userSessionToken }
					... on MobileMFALoginResponse { __typename otpToken }
				}
			}',
			array(
				'email'    => $email,
				'password' => $password,
			)
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$login = isset( $data['login'] ) ? $data['login'] : array();

		// MFA enabled: stash the OTP token and wait for the code.
		if ( ! empty( $login['otpToken'] ) ) {
			self::update_session(
				array(
					'otp_token' => $login['otpToken'],
					'email'     => $email,
				)
			);

			return array( 'mfa' => true );
		}

		if ( ! empty( $login['accessToken'] ) ) {
			self::store_tokens( $login, $email );

			return array( 'mfa' => false );
		}

		return new WP_Error(
			'rsu_rivian_login_failed',
			__( 'Rivian did not accept those credentials.', 'rivian-software-updates' )
		);
	}

	/**
	 * Complete an MFA login with the one-time code.
	 *
	 * @param string $otp_code Code from email/SMS.
	 * @return true|WP_Error
	 */
	public static function login_with_otp( $otp_code ) {
		$session = self::get_session();

		if ( empty( $session['otp_token'] ) || empty( $session['email'] ) ) {
			return new WP_Error(
				'rsu_rivian_no_otp_token',
				__( 'That login attempt expired. Start again with your email and password.', 'rivian-software-updates' )
			);
		}

		$data = self::request(
			'LoginWithOTP',
			'mutation LoginWithOTP($email: String!, $otpCode: String!, $otpToken: String!) {
				loginWithOTP(email: $email, otpCode: $otpCode, otpToken: $otpToken) {
					__typename
					... on MobileLoginResponse { __typename accessToken refreshToken userSessionToken }
				}
			}',
			array(
				'email'    => $session['email'],
				'otpCode'  => $otp_code,
				'otpToken' => $session['otp_token'],
			)
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$login = isset( $data['loginWithOTP'] ) ? $data['loginWithOTP'] : array();

		if ( empty( $login['accessToken'] ) ) {
			return new WP_Error(
				'rsu_rivian_otp_failed',
				__( 'That code was not accepted. Check it and try again.', 'rivian-software-updates' )
			);
		}

		self::store_tokens( $login, $session['email'] );

		return true;
	}

	/**
	 * Exchange the refresh token for a fresh access token.
	 *
	 * @return true|WP_Error
	 */
	public static function refresh() {
		$session = self::get_session();

		if ( empty( $session['refresh_token'] ) ) {
			return new WP_Error(
				'rsu_rivian_no_refresh_token',
				__( 'No refresh token stored — reconnect the Rivian account.', 'rivian-software-updates' )
			);
		}

		$data = self::request(
			'RefreshToken',
			'mutation RefreshToken($refreshToken: String!) {
				refreshToken(refreshToken: $refreshToken) {
					__typename accessToken refreshToken userSessionToken
				}
			}',
			array( 'refreshToken' => $session['refresh_token'] )
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$tokens = isset( $data['refreshToken'] ) ? $data['refreshToken'] : array();

		if ( empty( $tokens['accessToken'] ) ) {
			return new WP_Error(
				'rsu_rivian_refresh_failed',
				__( 'Rivian would not renew the session — reconnect the account.', 'rivian-software-updates' )
			);
		}

		self::store_tokens( $tokens, isset( $session['email'] ) ? $session['email'] : '' );

		return true;
	}

	/**
	 * Persist a token triple and clear any pending OTP state.
	 *
	 * @param array  $tokens Response fragment holding the tokens.
	 * @param string $email  Account email.
	 * @return void
	 */
	private static function store_tokens( $tokens, $email ) {
		self::update_session(
			array(
				'access_token'       => isset( $tokens['accessToken'] ) ? $tokens['accessToken'] : '',
				'refresh_token'      => isset( $tokens['refreshToken'] ) ? $tokens['refreshToken'] : '',
				'user_session_token' => isset( $tokens['userSessionToken'] ) ? $tokens['userSessionToken'] : '',
				'email'              => $email,
				'connected_at'       => time(),
				'otp_token'          => '',
			)
		);
	}

	/**
	 * Run an authenticated query, refreshing once on an auth failure.
	 *
	 * @param string $operation Operation name.
	 * @param string $query     Query document.
	 * @param array  $variables Variables.
	 * @return array|WP_Error
	 */
	private static function authed_request( $operation, $query, $variables = array() ) {
		$data = self::request( $operation, $query, $variables, array( 'authed' => true ) );

		if ( ! is_wp_error( $data ) || ! self::is_auth_error( $data ) ) {
			return $data;
		}

		$refreshed = self::refresh();

		if ( is_wp_error( $refreshed ) ) {
			return $refreshed;
		}

		return self::request( $operation, $query, $variables, array( 'authed' => true ) );
	}

	/**
	 * Whether an error looks like an expired/invalid session.
	 *
	 * @param WP_Error $error Error to inspect.
	 * @return bool
	 */
	private static function is_auth_error( $error ) {
		$message = strtolower( $error->get_error_message() );

		foreach ( array( 'unauthenticated', 'unauthorized', 'session', 'token', 'expired', 'http 401', 'http 403' ) as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	// ───────────────────────────── Queries ─────────────────────────────

	/**
	 * List the vehicles on the connected account.
	 *
	 * @return array|WP_Error List of array{ id, vin, name, model, model_year }.
	 */
	public static function get_vehicles() {
		$data = self::authed_request(
			'currentUser',
			'query currentUser {
				currentUser {
					__typename
					id
					vehicles {
						id
						name
						vin
						vehicle { __typename id model modelYear }
					}
				}
			}'
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$raw = array();
		if ( isset( $data['currentUser']['vehicles'] ) && is_array( $data['currentUser']['vehicles'] ) ) {
			$raw = $data['currentUser']['vehicles'];
		}

		$vehicles = array();

		foreach ( $raw as $vehicle ) {
			if ( empty( $vehicle['id'] ) ) {
				continue;
			}

			$vehicles[] = array(
				'id'         => sanitize_text_field( $vehicle['id'] ),
				'vin'        => isset( $vehicle['vin'] ) ? sanitize_text_field( $vehicle['vin'] ) : '',
				'name'       => isset( $vehicle['name'] ) ? sanitize_text_field( $vehicle['name'] ) : '',
				'model'      => isset( $vehicle['vehicle']['model'] ) ? sanitize_text_field( $vehicle['vehicle']['model'] ) : '',
				'model_year' => isset( $vehicle['vehicle']['modelYear'] ) ? sanitize_text_field( $vehicle['vehicle']['modelYear'] ) : '',
			);
		}

		return $vehicles;
	}

	/**
	 * Fetch current + available OTA details for one vehicle.
	 *
	 * Each detail carries the release-notes document URL, the version string,
	 * and a locale — Rivian does not return the notes body inline.
	 *
	 * @param string $vehicle_id Rivian vehicle id.
	 * @return array|WP_Error array{ available: array|null, current: array|null }.
	 */
	public static function get_ota_update_details( $vehicle_id ) {
		$data = self::authed_request(
			'getOTAUpdateDetails',
			'query getOTAUpdateDetails($vehicleId: String!) {
				getVehicle(id: $vehicleId) {
					__typename
					id
					availableOTAUpdateDetails { url version locale }
					currentOTAUpdateDetails { url version locale }
				}
			}',
			array( 'vehicleId' => $vehicle_id )
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$vehicle = isset( $data['getVehicle'] ) ? $data['getVehicle'] : array();

		return array(
			'available' => self::normalize_ota_detail( isset( $vehicle['availableOTAUpdateDetails'] ) ? $vehicle['availableOTAUpdateDetails'] : null ),
			'current'   => self::normalize_ota_detail( isset( $vehicle['currentOTAUpdateDetails'] ) ? $vehicle['currentOTAUpdateDetails'] : null ),
		);
	}

	/**
	 * Reduce an OTA detail node to clean scalars.
	 *
	 * @param mixed $detail Raw node.
	 * @return array|null array{ url, version, locale } or null when absent.
	 */
	private static function normalize_ota_detail( $detail ) {
		if ( ! is_array( $detail ) || empty( $detail['version'] ) ) {
			return null;
		}

		$raw = isset( $detail['url'] ) ? esc_url_raw( $detail['url'] ) : '';
		$url = $raw;

		// A URL on an unexpected host is dropped rather than followed — but the
		// host is reported, never silently swallowed, because "no release notes"
		// and "notes on a host we don't allow yet" need different fixes.
		$rejected = '';
		if ( $raw && ! self::is_allowed_notes_url( $raw ) ) {
			$url      = '';
			$rejected = (string) wp_parse_url( $raw, PHP_URL_HOST );
		}

		return array(
			'url'          => $url,
			'url_rejected' => $rejected,
			'has_url'      => '' !== $raw,
			'version'      => sanitize_text_field( $detail['version'] ),
			'locale'       => isset( $detail['locale'] ) ? sanitize_text_field( $detail['locale'] ) : '',
		);
	}

	/**
	 * Pull the platform, model, version and locale out of a notes URL.
	 *
	 * Rivian's document paths look like:
	 *   /Vehicle/{platform}/{model}/UpdateDetails/{version}/PDF-digital/{region}/{locale}/…
	 * where platform identifies the hardware generation (observed: "r1x" for
	 * R1 Gen 1, "r1x_1_6" for Gen 2) and model is the body style (R1T, R1S).
	 * The same software version ships a separate document per combination, so
	 * these fields are what tell two documents apart.
	 *
	 * @param string $url Signed document URL.
	 * @return array|WP_Error array{ platform, model, version, region, locale }.
	 */
	public static function parse_notes_url( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( '' === $path ) {
			return new WP_Error(
				'rsu_rivian_unparsable_url',
				__( 'That does not look like a Rivian release-notes link.', 'rivian-software-updates' )
			);
		}

		$segments = array_values( array_filter( explode( '/', rawurldecode( $path ) ), 'strlen' ) );
		$at       = array_search( 'UpdateDetails', $segments, true );

		// The two segments before UpdateDetails are platform and model; the one
		// after is the version. Anchoring on the keyword rather than on fixed
		// offsets survives Rivian adding or renaming a leading segment.
		if ( false === $at || $at < 2 || ! isset( $segments[ $at + 1 ] ) ) {
			return new WP_Error(
				'rsu_rivian_unparsable_url',
				__( 'That link is not in the expected Update Details format.', 'rivian-software-updates' )
			);
		}

		$raw_version = sanitize_text_field( $segments[ $at + 1 ] );

		return array(
			'platform' => sanitize_text_field( $segments[ $at - 2 ] ),
			'model'    => sanitize_text_field( $segments[ $at - 1 ] ),
			'version'  => self::expand_version( $raw_version ),
			'raw'      => $raw_version,
			'region'   => isset( $segments[ $at + 3 ] ) ? sanitize_text_field( $segments[ $at + 3 ] ) : '',
			'locale'   => isset( $segments[ $at + 4 ] ) ? sanitize_text_field( $segments[ $at + 4 ] ) : '',
		);
	}

	/**
	 * Turn a packed path version back into a dotted version string.
	 *
	 * Paths carry "2026310" for 2026.31.0 — year, two-digit minor, then the
	 * remainder as the patch. Anything that does not fit that shape is handed
	 * back untouched rather than mangled into a wrong version.
	 *
	 * @param string $packed Version as it appears in the path.
	 * @return string
	 */
	private static function expand_version( $packed ) {
		if ( ! preg_match( '/^(\d{4})(\d{2})(\d+)$/', $packed, $m ) ) {
			return $packed;
		}

		// The trailing group is kept verbatim: a leading zero is significant in
		// Rivian's numbering, where 2026.24.01 is a hotfix of 2026.24.
		return $m[1] . '.' . $m[2] . '.' . $m[3];
	}

	/**
	 * Whether a release-notes URL is on a Rivian-controlled host.
	 *
	 * The URL arrives from an external API and is later fetched server-side,
	 * so it is constrained to Rivian domains over HTTPS rather than trusted.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	public static function is_allowed_notes_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $host || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return false;
		}

		$host = strtolower( $host );

		/**
		 * Hosts a release-notes document may be fetched from.
		 *
		 * Rivian serves these from its own domains and from its CDN/object
		 * storage providers. Add a host here (or via this filter) if the
		 * settings screen reports one being rejected.
		 *
		 * @param array $domains Allowed registrable domains.
		 */
		$domains = apply_filters(
			'rsu_rivian_allowed_notes_hosts',
			array( 'rivian.com', 'rivianservices.com', 'cloudfront.net', 'amazonaws.com' )
		);

		foreach ( (array) $domains as $domain ) {
			$domain = strtolower( ltrim( (string) $domain, '.' ) );

			if ( '' === $domain ) {
				continue;
			}

			if ( $host === $domain || substr( $host, -( strlen( $domain ) + 1 ) ) === '.' . $domain ) {
				return true;
			}
		}

		return false;
	}
}
