<?php
/**
 * Date formatting shared by every front-end surface.
 *
 * The update page, the history table, and the widget used to each pick
 * their own format. They now all go through here, which honors the site's
 * Date Format setting (Settings → General) so the plugin reads like the
 * rest of the site.
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;

class RSU_Dates {

	/**
	 * Format a stored Y-m-d date with the site's date format.
	 *
	 * @param string $ymd Date as stored in post meta (Y-m-d), or empty.
	 * @return string Localized date, or '' when the input is empty/invalid.
	 */
	public static function format( $ymd ) {
		$timestamp = self::timestamp( $ymd );

		if ( ! $timestamp ) {
			return '';
		}

		$format = get_option( 'date_format' );
		if ( ! is_string( $format ) || '' === trim( $format ) ) {
			$format = 'F j, Y';
		}

		return date_i18n( $format, $timestamp );
	}

	/**
	 * Unix timestamp for a stored Y-m-d date, or 0.
	 *
	 * @param string $ymd Date string.
	 * @return int
	 */
	public static function timestamp( $ymd ) {
		if ( ! is_string( $ymd ) || '' === trim( $ymd ) ) {
			return 0;
		}

		$timestamp = strtotime( $ymd );

		return $timestamp ? (int) $timestamp : 0;
	}
}
