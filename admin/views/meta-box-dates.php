<?php
/**
 * Meta box: Update Details (version + dates).
 *
 * @package Rivian_Software_Updates
 * @var WP_Post $post
 */

defined( 'ABSPATH' ) || exit;

$version       = get_post_meta( $post->ID, '_rsu_version', true );
$date_noticed  = get_post_meta( $post->ID, '_rsu_date_noticed', true );
$date_released = get_post_meta( $post->ID, '_rsu_date_released', true );
?>

<div class="rsu-details-wrap">
	<p>
		<label for="rsu-version"><strong>Version Number</strong></label><br />
		<input type="text" id="rsu-version" name="rsu_version"
			value="<?php echo esc_attr( $version ); ?>"
			placeholder="e.g. 2026.27.0"
			class="widefat" />
	</p>

	<p>
		<label for="rsu-date-noticed"><strong>First Noticed</strong></label><br />
		<input type="date" id="rsu-date-noticed" name="rsu_date_noticed"
			value="<?php echo esc_attr( $date_noticed ); ?>"
			class="widefat" />
	</p>

	<p>
		<label for="rsu-date-released"><strong>Public Release</strong></label><br />
		<input type="date" id="rsu-date-released" name="rsu_date_released"
			value="<?php echo esc_attr( $date_released ); ?>"
			class="widefat" />
	</p>
</div>
