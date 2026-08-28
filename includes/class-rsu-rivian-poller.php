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

	/** Upper bound on a cached release-notes document (bytes). */
	const MAX_NOTES_BYTES = 20971520; // 20 MB.

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
		$awaiting = isset( $state['awaiting'] ) && is_array( $state['awaiting'] ) ? $state['awaiting'] : array();
		$done     = isset( $state['notes_done'] ) && is_array( $state['notes_done'] ) ? $state['notes_done'] : array();
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
			$watching = isset( $awaiting[ $vehicle_id ] ) && is_array( $awaiting[ $vehicle_id ] ) ? $awaiting[ $vehicle_id ] : array();
			$settled  = isset( $done[ $vehicle_id ] ) && is_array( $done[ $vehicle_id ] ) ? $done[ $vehicle_id ] : array();

			foreach ( $candidates as $version => $detail ) {
				if ( ! in_array( $version, $known, true ) ) {
					$known[] = $version;

					$result = self::handle_new_version( $slug, $detail );

					if ( is_wp_error( $result ) ) {
						$report['errors'][] = $result->get_error_message();
						continue;
					}

					$report['detections'][] = $result;

					// Rivian routinely publishes the release-notes document a
					// little after the version itself appears, so an empty URL
					// now is not final — keep asking on later polls.
					if ( empty( $result['url'] ) ) {
						$watching[ $version ] = array(
							'post_id' => $result['post_id'],
							'since'   => time(),
						);
					} else {
						$settled[] = $version;
					}

					continue;
				}

				// Already recorded. The only thing still outstanding is the
				// notes document; fill it in the moment it shows up.
				if ( empty( $detail['url'] ) ) {
					// Stop waiting eventually — some releases never get a document.
					if ( isset( $watching[ $version ] ) && time() - (int) $watching[ $version ]['since'] > WEEK_IN_SECONDS ) {
						unset( $watching[ $version ] );
					}
					continue;
				}

				// Settled already — do not look it up again on every poll.
				if ( in_array( $version, $settled, true ) ) {
					continue;
				}

				// Prefer the draft recorded when the version was first seen; fall
				// back to a title match so drafts created before this watch list
				// existed still get their notes.
				$target = isset( $watching[ $version ] )
					? (int) $watching[ $version ]['post_id']
					: self::find_update_post( $version );

				unset( $watching[ $version ] );
				$settled[] = $version;

				if ( ! $target ) {
					continue;
				}

				$filled = self::backfill_notes( $slug, $detail, $target );

				if ( ! is_wp_error( $filled ) ) {
					$report['detections'][] = $filled;
				}
			}

			// Keep the tail only — enough to avoid re-detecting a rollback.
			$seen[ $vehicle_id ]     = array_slice( $known, -10 );
			$awaiting[ $vehicle_id ] = $watching;
			$done[ $vehicle_id ]     = array_slice( $settled, -10 );
		}

		self::update_state(
			array(
				'seen'     => $seen,
				'awaiting'   => $awaiting,
				'notes_done' => $done,
				'observed'   => $observed,
				'last_run' => $report,
			)
		);

		return $report;
	}

	/**
	 * Create or join the draft for a newly seen version, then notify.
	 *
	 * @param string $slug   Plugin vehicle slug.
	 * @param array  $detail array{ url, version, locale }.
	 * @return array|WP_Error Detection record.
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

		// Park the notes URL, then pull the document down straight away — the
		// signed link is only good for about an hour.
		$cached = false;

		if ( ! empty( $detail['url'] ) ) {
			update_post_meta( $post_id, self::NOTES_META_PREFIX . $slug, esc_url_raw( $detail['url'] ) );

			$stored = self::store_notes_document( $post_id, $slug, $detail['url'] );
			$cached = ! is_wp_error( $stored );
		}

		$record = array(
			'kind'         => 'new',
			'slug'         => $slug,
			'version'      => $version,
			'url'          => isset( $detail['url'] ) ? $detail['url'] : '',
			'url_rejected' => isset( $detail['url_rejected'] ) ? $detail['url_rejected'] : '',
			'cached'       => $cached,
			'post_id'      => (int) $post_id,
			'created'      => $created,
			'at'           => time(),
		);

		self::notify( $record );

		/**
		 * Fires when the poller records a newly available OTA version.
		 *
		 * @param array $record Detection record.
		 */
		do_action( 'rsu_rivian_update_detected', $record );

		return $record;
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
	private static function store_notes_document( $post_id, $slug, $url ) {
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

		$dir = self::notes_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$filename = sprintf( '%d-%s.pdf', (int) $post_id, sanitize_key( $slug ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- binary write to our own upload subdirectory.
		if ( false === file_put_contents( trailingslashit( $dir ) . $filename, $body ) ) {
			return new WP_Error(
				'rsu_rivian_notes_unwritable',
				__( 'Could not save the release-notes document to the uploads directory.', 'rivian-software-updates' )
			);
		}

		update_post_meta( $post_id, self::NOTES_FILE_PREFIX . $slug, $filename );

		return $filename;
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
	public static function forget_notes( $post_id, $slug ) {
		$path = self::cached_notes_path( $post_id, $slug );

		if ( $path ) {
			wp_delete_file( $path );
		}

		delete_post_meta( $post_id, self::NOTES_FILE_PREFIX . $slug );
		delete_post_meta( $post_id, self::NOTES_META_PREFIX . $slug );
	}

	/**
	 * Attach a release-notes document that showed up after first detection.
	 *
	 * @param string $slug    Plugin vehicle slug.
	 * @param array  $detail  array{ url, version, ... }.
	 * @param int    $post_id Draft recorded at first detection.
	 * @return array|WP_Error Detection record.
	 */
	private static function backfill_notes( $slug, $detail, $post_id ) {
		$post = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || ! in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft' ), true ) ) {
			return new WP_Error(
				'rsu_rivian_not_a_draft',
				__( 'That version is no longer an open draft — leaving it alone.', 'rivian-software-updates' )
			);
		}

		// Already queued for this vehicle — nothing to do, and no second email.
		if ( get_post_meta( $post_id, self::NOTES_META_PREFIX . $slug, true ) ) {
			return new WP_Error(
				'rsu_rivian_already_queued',
				__( 'That draft is already waiting on this release-notes document.', 'rivian-software-updates' )
			);
		}

		// Notes already written by hand win; do not queue an import over them.
		$sections = get_post_meta( $post_id, '_rsu_sections_' . $slug, true );
		$decoded  = $sections ? json_decode( $sections, true ) : array();

		if ( ! empty( $decoded ) ) {
			return new WP_Error(
				'rsu_rivian_already_written',
				__( 'That draft already has release notes for this vehicle.', 'rivian-software-updates' )
			);
		}

		update_post_meta( $post_id, self::NOTES_META_PREFIX . $slug, esc_url_raw( $detail['url'] ) );

		$stored = self::store_notes_document( $post_id, $slug, $detail['url'] );

		$record = array(
			'cached'       => ! is_wp_error( $stored ),
			'kind'         => 'notes',
			'slug'         => $slug,
			'version'      => $detail['version'],
			'url'          => $detail['url'],
			'url_rejected' => '',
			'post_id'      => (int) $post_id,
			'created'      => false,
			'at'           => time(),
		);

		self::notify( $record );

		/**
		 * Fires when a release-notes document is attached to an existing draft.
		 *
		 * @param array $record Detection record.
		 */
		do_action( 'rsu_rivian_notes_attached', $record );

		return $record;
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

		$edit_link  = get_edit_post_link( $record['post_id'], 'raw' );
		$notes_late = isset( $record['kind'] ) && 'notes' === $record['kind'];

		if ( $notes_late ) {
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

		// The notes document itself — the thing worth clicking.
		if ( ! empty( $record['url'] ) ) {
			$lines[] = '';
			$lines[] = __( 'Official release notes (PDF):', 'rivian-software-updates' );
			$lines[] = $record['url'];
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

			if ( ! empty( $record['cached'] ) ) {
				$lines[] = '';
				$lines[] = __( 'Opening the draft pulls in the release notes automatically — review the sections, then publish.', 'rivian-software-updates' );
			} elseif ( ! empty( $record['url'] ) ) {
				$lines[] = '';
				$lines[] = __( 'The document could not be downloaded, and Rivian\'s link expires about an hour after it is issued — open it now if you want to import the notes by hand.', 'rivian-software-updates' );
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
			// A cached copy is authoritative — the signed URL has usually expired.
			if ( self::cached_notes_path( $post_id, $slug ) ) {
				$pending[ $slug ] = 'cached';
				continue;
			}

			$url = get_post_meta( $post_id, self::NOTES_META_PREFIX . $slug, true );

			if ( $url && RSU_Rivian_API::is_allowed_notes_url( $url ) ) {
				$pending[ $slug ] = $url;
			}
		}

		return $pending;
	}
}
