<?php
/**
 * Meta box: Update Details (dates).
 *
 * @package Rivian_Software_Updates
 * @var WP_Post $post
 */

defined( 'ABSPATH' ) || exit;

$date_noticed  = get_post_meta( $post->ID, '_rsu_date_noticed', true );
$date_released = get_post_meta( $post->ID, '_rsu_date_released', true );
?>

<div class="rsu-details-wrap">
	<div class="rsu-field">
		<label for="rsu-date-noticed">First Noticed</label>
		<input type="text" id="rsu-date-noticed" name="rsu_date_noticed"
			placeholder="MM/DD/YYYY"
			value="<?php echo esc_attr( $date_noticed ); ?>"
			onfocus="this.type='date'" onblur="if(!this.value)this.type='text'" />
	</div>

	<div class="rsu-field">
		<label for="rsu-date-released">Public Release</label>
		<input type="text" id="rsu-date-released" name="rsu_date_released"
			placeholder="MM/DD/YYYY"
			value="<?php echo esc_attr( $date_released ); ?>"
			onfocus="this.type='date'" onblur="if(!this.value)this.type='text'" />
	</div>
</div>

<script>
// If a date value exists, switch to date type immediately so it renders correctly.
(function() {
	['rsu-date-noticed', 'rsu-date-released'].forEach(function(id) {
		var el = document.getElementById(id);
		if (el && el.value) el.type = 'date';
	});
})();
</script>
