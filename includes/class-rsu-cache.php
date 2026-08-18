<?php
/**
 * Cache invalidation for software update posts.
 *
 * Publishing an update changes three surfaces at once: the update's own page,
 * the archive/timeline listings, and the "Latest Software Update" widget, which
 * renders in the sidebar of every page on the site. Nothing in WordPress tells
 * a CDN about any of that, so a new release stayed invisible behind Cloudflare
 * until the edge cache was purged by hand.
 *
 * This class hooks the publish/update/delete lifecycle, flushes the plugin's
 * own transient plus any page-cache plugin it recognizes, and calls the
 * Cloudflare API to purge the edge.
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;

class RSU_Cache {

	/** Option holding the outcome of the most recent purge (for the settings UI). */
	const LAST_PURGE_OPTION = 'rsu_cache_last_purge';

	/** Cloudflare API v4 base URL. */
	const CF_API_BASE = 'https://api.cloudflare.com/client/v4';

	/**
	 * Cloudflare's per-request cap on `files` for purge-by-URL. Larger lists are
	 * sent in batches.
	 */
	const CF_MAX_FILES = 30;

	/** Whether a purge has been queued for this request. */
	private $pending = false;

	/** URLs accumulated for the queued purge. */
	private $pending_urls = array();

	/** Guard so a single request can only ever purge once. */
	private $purged = false;

	public function __construct() {
		// Publish, unpublish, schedule-to-publish, trash.
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );

		// Edits to an already-published update. Priority 20 so it runs after
		// RSU_Admin::save_meta() has written the _rsu_is_update flag.
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 2 );

		// Permanent deletion — the permalink has to be read before the row goes.
		add_action( 'before_delete_post', array( $this, 'on_before_delete_post' ), 10, 2 );

		// Settings and vehicle-registry changes alter every rendered update.
		add_action( 'update_option_' . RSU_Settings::OPTION_KEY, array( $this, 'on_settings_updated' ) );
		add_action( 'update_option_' . RSU_Platforms::OPTION_KEY, array( $this, 'on_settings_updated' ) );

		// Coalesce everything queued during the request into one purge, run
		// after the response has been sent.
		add_action( 'shutdown', array( $this, 'run_pending_purge' ), 5 );

		// "Purge Cache Now" button on the settings screen.
		add_action( 'admin_post_rsu_purge_cache', array( $this, 'handle_manual_purge' ) );
	}

	/* ─────────────────────────── Configuration ─────────────────────────── */

	/**
	 * Whether automatic purging is switched on.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) RSU_Settings::get( 'cache_purge_enabled', true );
	}

	/**
	 * Cloudflare zone ID. A RSU_CLOUDFLARE_ZONE_ID constant in wp-config.php
	 * wins over the stored setting.
	 *
	 * @return string
	 */
	public static function zone_id() {
		if ( defined( 'RSU_CLOUDFLARE_ZONE_ID' ) && RSU_CLOUDFLARE_ZONE_ID ) {
			return trim( (string) RSU_CLOUDFLARE_ZONE_ID );
		}

		return trim( (string) RSU_Settings::get( 'cf_zone_id', '' ) );
	}

	/**
	 * Cloudflare API token. A RSU_CLOUDFLARE_API_TOKEN constant in wp-config.php
	 * wins over the stored setting — the preferred place for a credential.
	 *
	 * @return string
	 */
	public static function api_token() {
		if ( defined( 'RSU_CLOUDFLARE_API_TOKEN' ) && RSU_CLOUDFLARE_API_TOKEN ) {
			return trim( (string) RSU_CLOUDFLARE_API_TOKEN );
		}

		return trim( (string) RSU_Settings::get( 'cf_api_token', '' ) );
	}

	/**
	 * Whether the zone ID and token are both present.
	 *
	 * @return bool
	 */
	public static function is_cloudflare_configured() {
		return '' !== self::zone_id() && '' !== self::api_token();
	}

	/**
	 * Whether a credential comes from a wp-config.php constant.
	 *
	 * @param string $which 'zone' or 'token'.
	 * @return bool
	 */
	public static function is_constant_defined( $which ) {
		if ( 'zone' === $which ) {
			return defined( 'RSU_CLOUDFLARE_ZONE_ID' ) && RSU_CLOUDFLARE_ZONE_ID;
		}

		return defined( 'RSU_CLOUDFLARE_API_TOKEN' ) && RSU_CLOUDFLARE_API_TOKEN;
	}

	/**
	 * The outcome of the last purge, or an empty array if none has run.
	 *
	 * @return array
	 */
	public static function get_last_purge() {
		$last = get_option( self::LAST_PURGE_OPTION, array() );
		return is_array( $last ) ? $last : array();
	}

	/* ───────────────────────────── Triggers ────────────────────────────── */

	/**
	 * Purge when a post enters or leaves public view.
	 *
	 * Covers the scheduled publish path (future → publish, fired from wp-cron),
	 * which never sees a save_post with our meta box in $_POST.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Previous status.
	 * @param WP_Post $post       Post object.
	 */
	public function on_transition_post_status( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status ) {
			return;
		}

		// Only transitions that change what a visitor can see matter.
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}

		if ( ! $this->is_relevant_post( $post ) ) {
			return;
		}

		$this->queue_purge( $post->ID );
	}

	/**
	 * Purge when a published update is edited.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function on_save_post( $post_id, $post ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		if ( ! $this->is_relevant_post( $post ) ) {
			return;
		}

		$this->queue_purge( $post_id );
	}

	/**
	 * Purge when a published update is deleted outright (bypassing the trash).
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function on_before_delete_post( $post_id, $post = null ) {
		if ( ! $post ) {
			$post = get_post( $post_id );
		}

		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		if ( ! $this->is_relevant_post( $post ) ) {
			return;
		}

		$this->queue_purge( $post_id );
	}

	/**
	 * Purge after a settings or vehicle-registry save — both change the markup
	 * of every update already published.
	 */
	public function on_settings_updated() {
		$this->queue_purge();
	}

	/**
	 * Whether a post is one this plugin renders. Update posts carry the
	 * _rsu_is_update flag; posts merely filed under the software-update
	 * category count too, since they appear in the same archive.
	 *
	 * @param WP_Post $post Post object.
	 * @return bool
	 */
	private function is_relevant_post( $post ) {
		$relevant = false;

		if ( $post instanceof WP_Post && 'post' === $post->post_type ) {
			if ( get_post_meta( $post->ID, '_rsu_is_update', true ) ) {
				$relevant = true;
			} else {
				$category = RSU_Settings::get( 'redirect_category_slug' );
				if ( $category && has_category( $category, $post ) ) {
					$relevant = true;
				}
			}
		}

		/**
		 * Filter whether a post save should trigger a cache purge.
		 *
		 * @param bool    $relevant Whether the post is plugin-managed.
		 * @param WP_Post $post     The post being saved.
		 */
		return (bool) apply_filters( 'rsu_cache_is_relevant_post', $relevant, $post );
	}

	/* ────────────────────────────── Queueing ───────────────────────────── */

	/**
	 * Queue a purge for the end of this request. Repeated calls (WordPress
	 * fires several of the hooks above for a single editor save) collapse into
	 * one API call.
	 *
	 * @param int $post_id Optional post whose URLs should be included.
	 */
	public function queue_purge( $post_id = 0 ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		$this->pending      = true;
		$this->pending_urls = array_values( array_unique( array_merge(
			$this->pending_urls,
			$this->collect_urls( $post_id )
		) ) );
	}

	/**
	 * Run whatever was queued during this request. Hooked to shutdown so the
	 * editor's redirect is already on its way before the API call is made.
	 */
	public function run_pending_purge() {
		if ( ! $this->pending || $this->purged ) {
			return;
		}

		$this->purge( $this->pending_urls, 'auto' );
	}

	/* ─────────────────────────────── Purging ───────────────────────────── */

	/**
	 * Flush local caches and the Cloudflare edge, then record the outcome.
	 *
	 * @param array  $urls    URLs for a targeted purge (ignored when the scope
	 *                        is "everything").
	 * @param string $context 'auto' or 'manual', for the status readout.
	 * @return array Result record.
	 */
	public function purge( $urls = array(), $context = 'auto' ) {
		$this->purged  = true;
		$this->pending = false;

		$local = $this->flush_local_caches();
		$scope = $this->purge_scope();
		$urls  = array_values( array_unique( array_filter( (array) $urls ) ) );

		$result = array(
			'time'       => time(),
			'context'    => $context,
			'scope'      => $scope,
			'url_count'  => ( 'urls' === $scope ) ? count( $urls ) : 0,
			'local'      => $local,
			'cloudflare' => 'skipped',
			'message'    => '',
		);

		if ( ! self::is_cloudflare_configured() ) {
			$result['message'] = __( 'Cloudflare credentials are not configured — local caches only.', 'rivian-software-updates' );
		} else {
			$response = $this->cloudflare_purge( $urls, $scope );

			if ( is_wp_error( $response ) ) {
				$result['cloudflare'] = 'error';
				$result['message']    = $response->get_error_message();
			} else {
				$result['cloudflare'] = 'ok';
				$result['message']    = ( 'urls' === $scope )
					/* translators: %d: number of purged URLs. */
					? sprintf( _n( 'Purged %d URL from the Cloudflare edge.', 'Purged %d URLs from the Cloudflare edge.', count( $urls ), 'rivian-software-updates' ), count( $urls ) )
					: __( 'Purged everything from the Cloudflare edge.', 'rivian-software-updates' );
			}
		}

		update_option( self::LAST_PURGE_OPTION, $result, false );

		/**
		 * Fires after a purge attempt, successful or not.
		 *
		 * @param array $result Result record.
		 * @param array $urls   URLs included in the purge.
		 */
		do_action( 'rsu_cache_purged', $result, $urls );

		return $result;
	}

	/**
	 * The configured purge scope, normalized.
	 *
	 * @return string 'everything' or 'urls'.
	 */
	private function purge_scope() {
		$scope = RSU_Settings::get( 'cf_purge_scope', 'everything' );
		return ( 'urls' === $scope ) ? 'urls' : 'everything';
	}

	/**
	 * Send the purge to Cloudflare.
	 *
	 * @param array  $urls  URLs to purge.
	 * @param string $scope 'everything' or 'urls'.
	 * @return true|WP_Error
	 */
	private function cloudflare_purge( $urls, $scope ) {
		if ( 'urls' === $scope && ! empty( $urls ) ) {
			foreach ( array_chunk( $urls, self::CF_MAX_FILES ) as $batch ) {
				$response = $this->cf_request( array( 'files' => array_values( $batch ) ) );
				if ( is_wp_error( $response ) ) {
					return $response;
				}
			}

			return true;
		}

		return $this->cf_request( array( 'purge_everything' => true ) );
	}

	/**
	 * One POST to the Cloudflare purge_cache endpoint.
	 *
	 * @param array $body Request payload.
	 * @return true|WP_Error
	 */
	private function cf_request( $body ) {
		$response = wp_remote_post(
			self::CF_API_BASE . '/zones/' . rawurlencode( self::zone_id() ) . '/purge_cache',
			array(
				/** Filter the Cloudflare API request timeout, in seconds. */
				'timeout' => (int) apply_filters( 'rsu_cache_request_timeout', 10 ),
				'headers' => array(
					'Authorization' => 'Bearer ' . self::api_token(),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code && ! empty( $data['success'] ) ) {
			return true;
		}

		$detail = '';
		if ( ! empty( $data['errors'][0]['message'] ) ) {
			$detail = (string) $data['errors'][0]['message'];
			if ( ! empty( $data['errors'][0]['code'] ) ) {
				$detail = sprintf( '%s (code %s)', $detail, $data['errors'][0]['code'] );
			}
		} elseif ( 403 === $code ) {
			$detail = __( 'Authentication failed — check the API token has the Zone → Cache Purge permission for this zone.', 'rivian-software-updates' );
		} else {
			$detail = wp_remote_retrieve_response_message( $response );
		}

		return new WP_Error(
			'rsu_cache_cloudflare_failed',
			/* translators: 1: HTTP status code, 2: error detail from Cloudflare. */
			sprintf( __( 'Cloudflare purge failed (HTTP %1$d): %2$s', 'rivian-software-updates' ), $code, $detail )
		);
	}

	/**
	 * Clear the plugin's own transient and any page-cache plugin present.
	 *
	 * @return array Names of the caches that were cleared.
	 */
	private function flush_local_caches() {
		$flushed = array();

		if ( class_exists( 'RSU_Widget' ) ) {
			RSU_Widget::purge_cache();
			$flushed[] = 'Latest Update widget';
		}

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$flushed[] = 'WP Rocket';
		}

		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$flushed[] = 'W3 Total Cache';
		}

		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$flushed[] = 'WP Super Cache';
		}

		if ( false !== has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
			$flushed[] = 'LiteSpeed Cache';
		}

		if ( false !== has_action( 'cache_enabler_clear_complete_cache' ) ) {
			do_action( 'cache_enabler_clear_complete_cache' );
			$flushed[] = 'Cache Enabler';
		}

		if ( false !== has_action( 'sg_cachepress_purge_cache' ) ) {
			do_action( 'sg_cachepress_purge_cache' );
			$flushed[] = 'SG Optimizer';
		}

		if ( false !== has_action( 'rt_nginx_helper_purge_all' ) ) {
			do_action( 'rt_nginx_helper_purge_all' );
			$flushed[] = 'Nginx Helper';
		}

		if ( false !== has_action( 'breeze_clear_all_cache' ) ) {
			do_action( 'breeze_clear_all_cache' );
			$flushed[] = 'Breeze';
		}

		if ( class_exists( 'WpeCommon' ) && method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
			WpeCommon::purge_varnish_cache();
			$flushed[] = 'WP Engine';
		}

		/**
		 * Filter the list of locally flushed caches (for logging only — the
		 * flushing itself has already happened).
		 *
		 * @param array $flushed Cache names.
		 */
		return apply_filters( 'rsu_cache_flushed_local', $flushed );
	}

	/* ────────────────────────────── URL list ───────────────────────────── */

	/**
	 * Every URL whose HTML changes when an update is published.
	 *
	 * @param int $post_id Optional update post.
	 * @return array Absolute URLs.
	 */
	public function collect_urls( $post_id = 0 ) {
		$urls = array(
			home_url( '/' ),
			home_url( '/feed/' ),
		);

		$archive = RSU_Settings::get( 'archive_slug' );
		if ( $archive ) {
			$archive_url = home_url( '/' . ltrim( (string) $archive, '/' ) );
			$urls[]      = $archive_url;

			// A new release shifts pagination, so the first few pages go stale too.
			/** Filter how many archive pagination pages are purged. */
			$pages = (int) apply_filters( 'rsu_cache_archive_pages', 3 );
			for ( $page = 2; $page <= max( 1, $pages ); $page++ ) {
				$urls[] = trailingslashit( $archive_url ) . 'page/' . $page . '/';
			}
		}

		if ( $post_id ) {
			$permalink = get_permalink( $post_id );
			if ( $permalink ) {
				$urls[] = $permalink;
			}

			foreach ( (array) get_the_category( $post_id ) as $category ) {
				$link = get_category_link( $category->term_id );
				if ( $link && ! is_wp_error( $link ) ) {
					$urls[] = $link;
				}
			}
		}

		$urls = array_merge( $urls, $this->shortcode_page_urls() );

		/**
		 * Filter the URL list sent to Cloudflare for a targeted purge.
		 *
		 * @param array $urls    Absolute URLs.
		 * @param int   $post_id The post that triggered the purge, if any.
		 */
		$urls = apply_filters( 'rsu_cache_purge_urls', $urls, $post_id );

		return array_values( array_unique( array_filter( (array) $urls ) ) );
	}

	/**
	 * Permalinks of published posts and pages embedding the [rsu_history]
	 * timeline, which also re-renders when a new update lands.
	 *
	 * @return array
	 */
	private function shortcode_page_urls() {
		global $wpdb;

		$ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_status = 'publish'
			   AND post_type IN ('post', 'page')
			   AND post_content LIKE '%[rsu_history%'
			 LIMIT 20"
		);

		$urls = array();
		foreach ( (array) $ids as $id ) {
			$permalink = get_permalink( (int) $id );
			if ( $permalink ) {
				$urls[] = $permalink;
			}
		}

		return $urls;
	}

	/* ─────────────────────────── Manual purge ──────────────────────────── */

	/**
	 * Handle the "Purge Cache Now" button on the settings screen.
	 */
	public function handle_manual_purge() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to purge the cache.', 'rivian-software-updates' ), 403 );
		}

		check_admin_referer( 'rsu_purge_cache' );

		$result = $this->purge( $this->collect_urls(), 'manual' );

		wp_safe_redirect( add_query_arg(
			'rsu_purged',
			( 'error' === $result['cloudflare'] ) ? '0' : '1',
			admin_url( 'options-general.php?page=rsu-settings' )
		) );
		exit;
	}
}
