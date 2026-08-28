<?php
/**
 * Watches the connected Rivian account for new OTA release notes.
 *
 * Every run asks the API for each mapped vehicle's OTA detail. When a version
 * appears that we have not recorded, the poller creates (or joins) a draft
 * update post, stores the release-notes URL on it for the browser-side PDF
 * importer to pick up, and emails the site admin.
 *
 * Rivian returns a link to the notes document rather than the notes text, so
 * nothing is parsed here — the draft carries the URL and the editor screen
 * turns it into sections using the same pdf.js pipeline as a manual import.
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;

class RSU_Rivian_Poller {

	/** Cron hook name. */
	const CRON_HOOK = 'rsu_rivian_poll';

	/** Custom cron interval slug. */
	const SCHEDULE = 'rsu_five_minutes';

	/** Option mapping Rivian vehicle id => plugin vehicle slug. */
	const MAP_KEY = 'rsu_rivian_vehicle_map';

	/** Option holding per-vehicle last-seen versions and the last run report. */
	const STATE_KEY = 'rsu_rivian_state';

	/** Post meta prefix holding a pending release-notes URL, per vehicle slug. */
	const NOTES_META_PREFIX = '_rsu_notes_url_';

	/** Post meta prefix holding the cached document filename, per vehicle slug. */
	const NOTES_FILE_PREFIX = '_rsu_notes_file_';

	/** Upload subdirectory holding cached release-notes documents. */
	const NOTES_DIR = 'rsu-release-notes';

	/** Post meta prefix holding the revision history JSON, per vehicle slug. */
	const NOTES_REVISIONS_PREFIX = '_rsu_notes_revisions_';

	/** Post meta prefix flagging that notes were revised after the first capture. */
	const NOTES_REVISED_PREFIX = '_rsu_notes_revised_';

	/** Upper bound on a cached release-notes document (bytes). */
	const MAX_NOTES_BYTES = 20971520; // 20 MB.

	/** How often to re-download a tracked document looking for a revision. */
	const REVISION_INTERVAL = HOUR_IN_SECONDS;

	/** How long to keep watching a version's notes for revisions. */
	const TRACK_WINDOW = 30 * DAY_IN_SECONDS;

	public function __construct() {
		add_filter( 'cron_schedules', array( $this, 'register_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- documented five-minute poll.
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );

		// Admin-only: the session option is deliberately not autoloaded, so
		// checking it on every front-end request would cost a query per page.
		// Once scheduled the event lives in the cron option and fires on its
		// own; an admin visit is enough to establish or retire it.
		add_action( 'admin_init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	/**
	 * Add the five-minute interval used by the poll event.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function register_schedule( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (Rivian OTA poll)', 'rivian-software-updates' ),
		);

		return $schedules;
	}

	/**
	 * Schedule the poll event when connected; clear it when not.
	 *
	 * @return void
	 */
	public static function ensure_scheduled() {
		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		if ( RSU_Rivian_API::is_connected() ) {
			if ( ! $scheduled ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::CRON_HOOK );
			}
		} elseif ( $scheduled ) {
			wp_unschedule_event( $scheduled, self::CRON_HOOK );
		}
	}

	/**
	 * Remove the scheduled event (deactivation / disconnect).
	 *
	 * @return void
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	// ─────────────────────────── Vehicle mapping ───────────────────────────

	/**
	 * Get the Rivian vehicle id => plugin slug map.
	 *
	 * @return array
	 */
	public static function get_map() {
		$map = get_option( self::MAP_KEY, array() );

		return is_array( $map ) ? $map : array();
	}

	/**
	 * Save the vehicle map, keeping only known plugin slugs.
	 *
	 * @param array $map Raw map.
	 * @return array The saved map.
	 */
	public static function save_map( $map ) {
		$valid = array_keys( RSU_Platforms::get_all() );
		$clean = array();

		foreach ( (array) $map as $vehicle_id => $slug ) {
			$vehicle_id = sanitize_text_field( $vehicle_id );
			$slug       = sanitize_key( $slug );

			if ( '' !== $vehicle_id && in_array( $slug, $valid, true ) ) {
				$clean[ $vehicle_id ] = $slug;
			}
		}

		update_option( self::MAP_KEY, $clean, false );

		return $clean;
	}

	// ────────────────────────────── State ──────────────────────────────

	/**
	 * Read the poll state (last-seen versions, last run report).
	 *
	 * @return array
	 */
	public static function get_state() {
		$state = get_option( self::STATE_KEY, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Merge values into the poll state.
	 *
	 * @param array $data Keys to write.
	 * @return void
	 */
	private static function update_state( array $data ) {
		update_option( self::STATE_KEY, array_merge( self::get_state(), $data ), false );
	}

	// ─────────────────────────────── Poll ───────────────────────────────

	/**
	 * Poll every mapped vehicle and act on anything new.
	 *
	 * @return array Report: array{ checked, detections, errors }.
	 */
	public static function run() {
		$map = self::get_map();

		$report = array(
			'ran_at'     => time(),
			'checked'    => 0,
			'detections' => array(),
			'errors'     => array(),
		);

		if ( empty( $map ) || ! RSU_Rivian_API::is_connected() ) {
			$report['errors'][] = __( 'Not connected, or no vehicles mapped.', 'rivian-software-updates' );
			self::update_state( array( 'last_run' => $report ) );

			return $report;
		}

		$state    = self::get_state();
		$seen     = isset( $state['seen'] ) && is_array( $state['seen'] ) ? $state['seen'] : array();
		$tracked  = isset( $state['tracked'] ) && is_array( $state['tracked'] ) ? $state['tracked'] : array();
		$observed = array();

		foreach ( $map as $vehicle_id => $slug ) {
			$details = RSU_Rivian_API::get_ota_update_details( $vehicle_id );

			if ( is_wp_error( $details ) ) {
				$report['errors'][] = sprintf(
					/* translators: 1: vehicle slug, 2: error message. */
					__( '%1$s: %2$s', 'rivian-software-updates' ),
					$slug,
					$details->get_error_message()
				);
				continue;
			}

			++$report['checked'];

			// A version can surface as "available", or — if the car installed it
			// between polls — jump straight to "current". Treat either as news.
			$candidates = array();
			foreach ( array( 'available', 'current' ) as $which ) {
				if ( ! empty( $details[ $which ]['version'] ) ) {
					$candidates[ $details[ $which ]['version'] ] = $details[ $which ];
				}
			}

			// Keep the raw observation for the settings screen — when a version
			// arrives without notes, this is the only record of what Rivian
			// actually said, and guessing is worse than looking.
			$observed[ $vehicle_id ] = array(
				'at'        => time(),
				'slug'      => $slug,
				'available' => self::summarize_detail( $details['available'] ),
				'current'   => self::summarize_detail( $details['current'] ),
			);

			$known    = isset( $seen[ $vehicle_id ] ) && is_array( $seen[ $vehicle_id ] ) ? $seen[ $vehicle_id ] : array();
			$watching = isset( $tracked[ $vehicle_id ] ) && is_array( $tracked[ $vehicle_id ] ) ? $tracked[ $vehicle_id ] : array();

			foreach ( $candidates as $version => $detail ) {
				$is_new  = ! in_array( $version, $known, true );
				$created = null;

				if ( $is_new ) {
					$known[] = $version;
					$created = self::handle_new_version( $slug, $detail );

					if ( is_wp_error( $created ) ) {
						$report['errors'][] = $created->get_error_message();
						continue;
					}

					$watching[ $version ] = array(
						'post_id'    => $created['post_id'],
						'hash'       => '',
						'last_check' => 0,
						'since'      => time(),
					);
				}

				// Nothing to download yet. Rivian routinely publishes the notes
				// document after the version appears, so keep the watch open.
				if ( empty( $detail['url'] ) ) {
					if ( $is_new ) {
						self::announce( self::detection_record( 'new', $slug, $version, $detail, $created['post_id'], $created, null ), $report );
					} elseif ( isset( $watching[ $version ] )
						&& '' === $watching[ $version ]['hash']
						&& time() - (int) $watching[ $version ]['since'] > WEEK_IN_SECONDS ) {
						// Some releases never get a document; stop waiting.
						unset( $watching[ $version ] );
					}

					continue;
				}

				// A version known from before revision tracking existed still
				// deserves its notes — find its draft and adopt it.
				if ( ! isset( $watching[ $version ] ) ) {
					$found = self::find_update_post( $version );

					if ( ! $found ) {
						continue;
					}

					$history = self::get_revisions( $found, $slug );
					$last    = $history ? end( $history ) : array();

					$watching[ $version ] = array(
						'post_id'    => (int) $found,
						'hash'       => isset( $last['hash'] ) ? $last['hash'] : '',
						'last_check' => 0,
						'since'      => time(),
					);
				}

				$entry   = $watching[ $version ];
				$post_id = (int) $entry['post_id'];

				// Stop re-downloading long-settled releases.
				if ( time() - (int) $entry['since'] > self::TRACK_WINDOW ) {
					unset( $watching[ $version ] );

					if ( $is_new ) {
						self::announce( self::detection_record( 'new', $slug, $version, $detail, $created['post_id'], $created, null ), $report );
					}

					continue;
				}

				// Capture immediately when we hold nothing; otherwise re-check on
				// the revision interval, since a version's notes can be reissued.
				$revision = null;
				$due      = ( '' === $entry['hash'] ) || ( time() - (int) $entry['last_check'] >= self::REVISION_INTERVAL );

				if ( $due ) {
					$entry['last_check'] = time();

					$result = self::capture_revision( $post_id, $slug, $detail['url'], $entry['hash'] );

					if ( is_wp_error( $result ) ) {
						$report['errors'][] = sprintf(
							/* translators: 1: vehicle slug, 2: error message. */
							__( '%1$s: %2$s', 'rivian-software-updates' ),
							$slug,
							$result->get_error_message()
						);
					} elseif ( is_array( $result ) ) {
						$revision      = $result;
						$entry['hash'] = $result['hash'];
					}
				}

				$watching[ $version ] = $entry;

				if ( $is_new ) {
					self::announce( self::detection_record( 'new', $slug, $version, $detail, $post_id, $created, $revision ), $report );
					continue;
				}

				if ( $revision ) {
					// First capture reads as "the notes arrived"; anything later
					// is Rivian reissuing notes for a version already written up.
					$kind = 1 === (int) $revision['index'] ? 'notes' : 'revision';
					self::announce( self::detection_record( $kind, $slug, $version, $detail, $post_id, null, $revision ), $report );
				}
			}

			// Keep the tail only — enough to avoid re-detecting a rollback.
			$seen[ $vehicle_id ]    = array_slice( $known, -10 );
			$tracked[ $vehicle_id ] = $watching;
		}

		self::update_state(
			array(
				'seen'     => $seen,
				'tracked'  => $tracked,
				'observed' => $observed,
				'last_run' => $report,
			)
		);

		return $report;
	}

	/**
	 * Create or join the draft for a newly seen version.
	 *
	 * Only the post is touched here — downloading the notes and emailing are
	 * handled by the caller, so a single message can report both.
	 *
	 * @param string $slug   Plugin vehicle slug.
	 * @param array  $detail array{ url, version, ... }.
	 * @return array|WP_Error array{ post_id, created }.
	 */
	private static function handle_new_version( $slug, $detail ) {
		$version = $detail['version'];
		$post_id = self::find_update_post( $version );
		$created = false;

		if ( ! $post_id ) {
			$post_id = wp_insert_post(
				array(
					'post_title'   => $version,
					'post_status'  => 'draft',
					'post_type'    => 'post',
					'post_content' => '',
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			$created = true;

			update_post_meta( $post_id, '_rsu_is_update', '1' );
			update_post_meta( $post_id, '_rsu_date_noticed', current_time( 'Y-m-d' ) );
		}

		// Add this vehicle to the post without disturbing vehicles already on it.
		$vehicles = get_post_meta( $post_id, '_rsu_vehicles', true );
		$vehicles = is_array( $vehicles ) ? $vehicles : array();

		if ( ! in_array( $slug, $vehicles, true ) ) {
			$vehicles[] = $slug;
			update_post_meta( $post_id, '_rsu_vehicles', array_values( $vehicles ) );
		}

		// Record the link even if the download fails, so the email can carry it.
		if ( ! empty( $detail['url'] ) ) {
			update_post_meta( $post_id, self::NOTES_META_PREFIX . $slug, esc_url_raw( $detail['url'] ) );
		}

		return array(
			'post_id' => (int) $post_id,
			'created' => $created,
		);
	}

	/**
	 * Build the record describing something worth telling the user about.
	 *
	 * @param string     $kind     'new', 'notes' or 'revision'.
	 * @param string     $slug     Vehicle slug.
	 * @param string     $version  Version string.
	 * @param array      $detail   Normalized OTA detail.
	 * @param int        $post_id  Draft the record refers to.
	 * @param array|null $created  Result of handle_new_version(), when new.
	 * @param array|null $revision Captured revision, when one was stored.
	 * @return array
	 */
	private static function detection_record( $kind, $slug, $version, $detail, $post_id, $created, $revision ) {
		return array(
			'kind'         => $kind,
			'slug'         => $slug,
			'version'      => $version,
			'url'          => isset( $detail['url'] ) ? $detail['url'] : '',
			'url_rejected' => isset( $detail['url_rejected'] ) ? $detail['url_rejected'] : '',
			'post_id'      => (int) $post_id,
			'created'      => $created ? (bool) $created['created'] : false,
			'cached'       => (bool) $revision,
			'revision'     => $revision ? (int) $revision['index'] : 0,
			'draft'        => $revision ? ! empty( $revision['draft'] ) : false,
			'at'           => time(),
		);
	}

	/**
	 * Email a detection record and add it to the run report.
	 *
	 * @param array $record Detection record.
	 * @param array $report Run report, by reference.
	 * @return void
	 */
	private static function announce( $record, &$report ) {
		self::notify( $record );

		$report['detections'][] = $record;

		/**
		 * Fires when the poller records something new about an OTA release.
		 *
		 * @param array $record Detection record.
		 */
		do_action( 'rsu_rivian_update_detected', $record );
	}

	/**
	 * Download a release-notes document and cache it on disk.
	 *
	 * Rivian hands out pre-signed S3 URLs that expire about an hour after they
	 * are issued, so the document has to be pulled while the poller still holds
	 * a fresh link — by the time someone opens the draft the URL is usually
	 * dead. The editor then reads this copy instead of re-fetching.
	 *
	 * @param int    $post_id Draft the document belongs to.
	 * @param string $slug    Vehicle slug.
	 * @param string $url     Signed document URL.
	 * @return string|WP_Error Stored filename, or error.
	 */
	private static function capture_revision( $post_id, $slug, $url, $known_hash ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array( 'Accept' => 'application/pdf,*/*' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error(
				'rsu_rivian_notes_expired',
				__( 'The release-notes link had already expired when the download was attempted.', 'rivian-software-updates' )
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'rsu_rivian_notes_http',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Downloading the release notes returned HTTP %d.', 'rivian-software-updates' ),
					$code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body || 0 !== strpos( $body, '%PDF-' ) ) {
			return new WP_Error(
				'rsu_rivian_notes_not_pdf',
				__( 'The release-notes link did not return a PDF.', 'rivian-software-updates' )
			);
		}

		if ( strlen( $body ) > self::MAX_NOTES_BYTES ) {
			return new WP_Error(
				'rsu_rivian_notes_too_large',
				__( 'The release-notes document is unexpectedly large.', 'rivian-software-updates' )
			);
		}

		$hash = hash( 'sha256', $body );

		// Byte-identical to what we already hold — this is the common case on
		// an hourly re-check, and costs nothing beyond the download.
		if ( $hash === $known_hash ) {
			return null;
		}

		$dir = self::notes_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$revisions = self::get_revisions( $post_id, $slug );
		$index     = count( $revisions ) + 1;

		// The random suffix keeps the file from being guessable if the uploads
		// directory is served directly despite the deny rules written below.
		$filename = sprintf( '%d-%s-r%d-%s.pdf', (int) $post_id, sanitize_key( $slug ), $index, wp_generate_password( 12, false, false ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- binary write to our own upload subdirectory.
		if ( false === file_put_contents( trailingslashit( $dir ) . $filename, $body ) ) {
			return new WP_Error(
				'rsu_rivian_notes_unwritable',
				__( 'Could not save the release-notes document to the uploads directory.', 'rivian-software-updates' )
			);
		}

		$revision = array(
			'file'  => $filename,
			'hash'  => $hash,
			'at'    => time(),
			'size'  => strlen( $body ),
			'draft' => self::looks_like_draft_content( $body ),
			'index' => $index,
		);

		$revisions[] = $revision;

		update_post_meta( $post_id, self::NOTES_REVISIONS_PREFIX . $slug, wp_json_encode( $revisions ) );
		update_post_meta( $post_id, self::NOTES_FILE_PREFIX . $slug, $filename );
		update_post_meta( $post_id, self::NOTES_META_PREFIX . $slug, esc_url_raw( $url ) );

		// Only a change to notes we had already captured needs the editor's
		// attention — the first capture is just the notes arriving.
		if ( $index > 1 ) {
			update_post_meta( $post_id, self::NOTES_REVISED_PREFIX . $slug, time() );
		}

		return $revision;
	}

	/**
	 * Read the stored revision history for a vehicle.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $slug    Vehicle slug.
	 * @return array List of revision records, oldest first.
	 */
	public static function get_revisions( $post_id, $slug ) {
		$raw = get_post_meta( $post_id, self::NOTES_REVISIONS_PREFIX . $slug, true );

		if ( ! $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Whether a PDF carries Rivian's beta "THIS IS DRAFT CONTENT" marker.
	 *
	 * Release notes shipped to beta testers are marked as draft and can be
	 * revised before the wide release, so it is worth knowing which revision
	 * a post was written from. PDF text is usually deflated, so the raw bytes
	 * are searched first and then each inflatable stream.
	 *
	 * @param string $bytes Raw PDF.
	 * @return bool
	 */
	private static function looks_like_draft_content( $bytes ) {
		$needle = 'DRAFT CONTENT';

		if ( false !== stripos( $bytes, $needle ) ) {
			return true;
		}

		if ( ! function_exists( 'gzuncompress' ) ) {
			return false;
		}

		$offset = 0;

		while ( true ) {
			$start = strpos( $bytes, 'stream', $offset );

			if ( false === $start ) {
				break;
			}

			$start += 6;
			// Skip the EOL that follows the "stream" keyword.
			$start += strspn( substr( $bytes, $start, 2 ), "\r\n" );

			$end = strpos( $bytes, 'endstream', $start );

			if ( false === $end ) {
				break;
			}

			$chunk = substr( $bytes, $start, $end - $start );
			$offset = $end + 9;

			if ( '' === $chunk ) {
				continue;
			}

			// Most streams are not text and will simply fail to inflate.
			$plain = @gzuncompress( $chunk ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- non-deflate streams are expected.

			if ( false === $plain ) {
				$plain = @gzinflate( $chunk ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- raw-deflate variant.
			}

			if ( is_string( $plain ) && false !== stripos( $plain, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Path to the cache directory, creating it if needed.
	 *
	 * @return string|WP_Error Absolute path.
	 */
	public static function notes_dir() {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'rsu_rivian_no_uploads', $uploads['error'] );
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::NOTES_DIR;

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error(
				'rsu_rivian_no_notes_dir',
				__( 'Could not create the release-notes cache directory.', 'rivian-software-updates' )
			);
		}

		// Beta release notes are pre-release material, so keep the cache out of
		// the browsable web root where the server honours these files. Filenames
		// also carry a random suffix, since nginx ignores .htaccess.
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing our own deny rules.
			@file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort hardening.
		}

		if ( ! file_exists( $dir . '/index.php' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- directory listing guard.
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort hardening.
		}

		return $dir;
	}

	/**
	 * Absolute path of a cached document, if one exists.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $slug    Vehicle slug.
	 * @return string Path, or '' when there is no cached copy.
	 */
	public static function cached_notes_path( $post_id, $slug ) {
		$filename = get_post_meta( $post_id, self::NOTES_FILE_PREFIX . $slug, true );

		if ( ! $filename ) {
			return '';
		}

		// Never let a stored name escape the cache directory.
		$filename = basename( $filename );
		$dir      = self::notes_dir();

		if ( is_wp_error( $dir ) ) {
			return '';
		}

		$path = trailingslashit( $dir ) . $filename;

		return file_exists( $path ) ? $path : '';
	}

	/**
	 * Delete a cached document and forget both notes meta keys.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $slug    Vehicle slug.
	 * @return void
	 */
	public static function forget_notes( $post_id, $slug, $keep_history = false ) {
		if ( ! $keep_history ) {
			$dir = self::notes_dir();

			if ( ! is_wp_error( $dir ) ) {
				foreach ( self::get_revisions( $post_id, $slug ) as $revision ) {
					if ( empty( $revision['file'] ) ) {
						continue;
					}

					$path = trailingslashit( $dir ) . basename( $revision['file'] );

					if ( file_exists( $path ) ) {
						wp_delete_file( $path );
					}
				}
			}

			delete_post_meta( $post_id, self::NOTES_REVISIONS_PREFIX . $slug );
		}

		delete_post_meta( $post_id, self::NOTES_FILE_PREFIX . $slug );
		delete_post_meta( $post_id, self::NOTES_META_PREFIX . $slug );
		delete_post_meta( $post_id, self::NOTES_REVISED_PREFIX . $slug );
	}

	/**
	 * Absolute path of one stored revision, if it still exists.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $slug    Vehicle slug.
	 * @param int    $index   1-based revision index.
	 * @return string Path, or '' when absent.
	 */
	public static function revision_path( $post_id, $slug, $index ) {
		$dir = self::notes_dir();

		if ( is_wp_error( $dir ) ) {
			return '';
		}

		foreach ( self::get_revisions( $post_id, $slug ) as $revision ) {
			if ( (int) $revision['index'] !== (int) $index || empty( $revision['file'] ) ) {
				continue;
			}

			$path = trailingslashit( $dir ) . basename( $revision['file'] );

			return file_exists( $path ) ? $path : '';
		}

		return '';
	}

	/**
	 * Reduce an OTA detail to the fields the diagnostics panel shows.
	 *
	 * @param array|null $detail Normalized detail.
	 * @return array
	 */
	private static function summarize_detail( $detail ) {
		if ( ! is_array( $detail ) ) {
			return array( 'version' => '' );
		}

		return array(
			'version'      => isset( $detail['version'] ) ? $detail['version'] : '',
			'url'          => isset( $detail['url'] ) ? $detail['url'] : '',
			'has_url'      => ! empty( $detail['has_url'] ),
			'url_rejected' => isset( $detail['url_rejected'] ) ? $detail['url_rejected'] : '',
		);
	}

	/**
	 * Find an existing update post for a version string.
	 *
	 * Titles are normally the bare version ("2026.24"), but some posts carry
	 * the descriptive "Rivian Software Update 2026.24" heading, so both forms
	 * are compared after stripping the prefix.
	 *
	 * @param string $version Version string.
	 * @return int Post ID, or 0.
	 */
	private static function find_update_post( $version ) {
		$posts = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => array( 'draft', 'pending', 'future', 'publish', 'private' ),
				'numberposts'      => 50,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded, runs on cron only.
					array(
						'key'     => '_rsu_is_update',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$target = self::normalize_version( $version );

		foreach ( $posts as $post ) {
			if ( self::normalize_version( $post->post_title ) === $target ) {
				return (int) $post->ID;
			}
		}

		return 0;
	}

	/**
	 * Reduce a title to a comparable bare version string.
	 *
	 * @param string $title Post title or version.
	 * @return string
	 */
	private static function normalize_version( $title ) {
		$title = preg_replace( '/^\s*Rivian\s+Software\s+Update\s*/i', '', (string) $title );

		return strtolower( trim( $title ) );
	}

	// ────────────────────────────── Notify ──────────────────────────────

	/**
	 * Email the admin about a detection.
	 *
	 * @param array $record Detection record.
	 * @return void
	 */
	private static function notify( $record ) {
		$to = RSU_Settings::get( 'rivian_notify_email', '' );

		if ( ! $to || ! is_email( $to ) ) {
			$to = get_option( 'admin_email' );
		}

		if ( ! $to ) {
			return;
		}

		$vehicles = RSU_Platforms::get_all();
		$label    = isset( $vehicles[ $record['slug'] ]['label'] )
			? $vehicles[ $record['slug'] ]['label']
			: strtoupper( $record['slug'] );

		$edit_link = get_edit_post_link( $record['post_id'], 'raw' );
		$kind      = isset( $record['kind'] ) ? $record['kind'] : 'new';

		if ( 'revision' === $kind ) {
			$subject = sprintf(
				/* translators: 1: vehicle label, 2: version string. */
				__( '[%1$s] Release notes for %2$s have been revised', 'rivian-software-updates' ),
				$label,
				$record['version']
			);

			$lines = array(
				sprintf(
					/* translators: 1: version string, 2: vehicle label, 3: revision number. */
					__( 'Rivian has reissued the release notes for %1$s (%2$s). This is revision %3$d — the previous version is kept in the post\'s notes history.', 'rivian-software-updates' ),
					$record['version'],
					$label,
					(int) $record['revision']
				),
				'',
				__( 'The post may need updating. Open it and the editor will offer to load the revised notes so you can compare them against what is already written.', 'rivian-software-updates' ),
			);
		} elseif ( 'notes' === $kind ) {
			$subject = sprintf(
				/* translators: 1: vehicle label, 2: version string. */
				__( '[%1$s] Release notes are now available for %2$s', 'rivian-software-updates' ),
				$label,
				$record['version']
			);

			$lines = array(
				sprintf(
					/* translators: 1: version string, 2: vehicle label. */
					__( 'Rivian has published the release-notes document for %1$s (%2$s).', 'rivian-software-updates' ),
					$record['version'],
					$label
				),
				'',
				__( 'It has been attached to the existing draft — open it and the notes import themselves.', 'rivian-software-updates' ),
			);
		} else {
			$subject = sprintf(
				/* translators: 1: vehicle label, 2: version string. */
				__( '[%1$s] Rivian software update %2$s is available', 'rivian-software-updates' ),
				$label,
				$record['version']
			);

			$lines = array(
				sprintf(
					/* translators: 1: vehicle label, 2: version string. */
					__( 'A new Rivian software update was detected for %1$s: version %2$s.', 'rivian-software-updates' ),
					$label,
					$record['version']
				),
				'',
				$record['created']
					? __( 'A draft post has been created for it.', 'rivian-software-updates' )
					: __( 'This vehicle was added to the existing draft for that version.', 'rivian-software-updates' ),
			);
		}

		// Beta builds ship notes stamped as draft, and those get reissued before
		// the wide release — worth knowing before writing the post up.
		if ( ! empty( $record['draft'] ) ) {
			$lines[] = '';
			$lines[] = __( 'Note: this document is marked THIS IS DRAFT CONTENT, so Rivian may still revise it. Polling keeps checking hourly and you will be emailed if it changes.', 'rivian-software-updates' );
		}

		// The notes document itself — the thing worth clicking.
		if ( ! empty( $record['url'] ) ) {
			$lines[] = '';
			$lines[] = __( 'Official release notes (PDF):', 'rivian-software-updates' );
			$lines[] = $record['url'];

			if ( empty( $record['cached'] ) ) {
				$lines[] = '';
				$lines[] = __( 'The document could not be downloaded, and Rivian\'s links expire about an hour after they are issued — open it now if you want to import the notes by hand.', 'rivian-software-updates' );
			}
		} elseif ( ! empty( $record['url_rejected'] ) ) {
			$lines[] = '';
			$lines[] = sprintf(
				/* translators: %s: hostname. */
				__( 'Rivian returned a release-notes document on an unrecognized host (%s), so it was not fetched. Add that host to the rsu_rivian_allowed_notes_hosts filter to enable it.', 'rivian-software-updates' ),
				$record['url_rejected']
			);
		} else {
			$lines[] = '';
			$lines[] = __( 'Rivian has not published the release-notes document for this version yet. Polling continues, and you will get a follow-up email as soon as it appears.', 'rivian-software-updates' );
		}

		if ( $edit_link ) {
			$lines[] = '';
			$lines[] = __( 'Edit the draft:', 'rivian-software-updates' );
			$lines[] = $edit_link;

			if ( ! empty( $record['cached'] ) && 'revision' !== $kind ) {
				$lines[] = '';
				$lines[] = __( 'Opening the draft pulls in the release notes automatically — review the sections, then publish.', 'rivian-software-updates' );
			}
		}

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Pending release-notes URLs on a post, keyed by vehicle slug.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function get_pending_notes( $post_id ) {
		$pending = array();

		foreach ( array_keys( RSU_Platforms::get_all() ) as $slug ) {
			$cached  = self::cached_notes_path( $post_id, $slug );
			$url     = get_post_meta( $post_id, self::NOTES_META_PREFIX . $slug, true );
			$revised = (int) get_post_meta( $post_id, self::NOTES_REVISED_PREFIX . $slug, true );

			if ( ! $cached && ( ! $url || ! RSU_Rivian_API::is_allowed_notes_url( $url ) ) ) {
				continue;
			}

			$history  = self::get_revisions( $post_id, $slug );
			$latest   = $history ? end( $history ) : array();

			$pending[ $slug ] = array(
				// "revised" means Rivian reissued notes we had already captured,
				// so the editor must offer rather than overwrite.
				'state'    => $revised ? 'revised' : 'new',
				'revision' => isset( $latest['index'] ) ? (int) $latest['index'] : 0,
				'revisedAt' => $revised ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $revised ) : '',
				'draft'    => ! empty( $latest['draft'] ),
				'cached'   => (bool) $cached,
			);
		}

		return $pending;
	}

	/**
	 * Clear the "notes were revised" flag once the editor has dealt with it.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $slug    Vehicle slug.
	 * @return void
	 */
	public static function clear_revised_flag( $post_id, $slug ) {
		delete_post_meta( $post_id, self::NOTES_REVISED_PREFIX . $slug );
	}
}
