<?php
/**
 * Meta box: Update Details (dates + hotfix).
 *
 * @package Rivian_Software_Updates
 * @var WP_Post $post
 */

defined( 'ABSPATH' ) || exit;

$date_noticed  = get_post_meta( $post->ID, '_rsu_date_noticed', true );
$date_released = get_post_meta( $post->ID, '_rsu_date_released', true );

$is_hotfix     = get_post_meta( $post->ID, '_rsu_is_hotfix', true );
$parent_id     = (int) get_post_meta( $post->ID, '_rsu_parent_release', true );
$hotfix_builds = get_post_meta( $post->ID, '_rsu_hotfix_builds', true );
if ( ! is_array( $hotfix_builds ) ) {
	$hotfix_builds = array();
}

// Candidate base releases: published update posts that are not hotfixes, newest first.
$base_releases = get_posts( array(
	'post_type'      => 'post',
	'post_status'    => 'publish',
	'posts_per_page' => 50,
	'orderby'        => 'date',
	'order'          => 'DESC',
	'post__not_in'   => array( $post->ID ),
	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin-only, bounded list.
	'meta_query'     => array(
		'relation' => 'AND',
		array(
			'key'   => '_rsu_is_update',
			'value' => '1',
		),
		array(
			'key'     => '_rsu_is_hotfix',
			'compare' => 'NOT EXISTS',
		),
	),
) );

$all_vehicles = RSU_Platforms::get_all();
?>

<div class="rsu-details-wrap">
	<div class="rsu-field">
		<label for="rsu-date-noticed">First Noticed</label>
		<input type="date" id="rsu-date-noticed" name="rsu_date_noticed"
			value="<?php echo esc_attr( $date_noticed ); ?>"
			max="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" />
	</div>

	<div class="rsu-field">
		<label for="rsu-date-released">Public Release</label>
		<input type="date" id="rsu-date-released" name="rsu_date_released"
			value="<?php echo esc_attr( $date_released ); ?>" />
	</div>

	<div class="rsu-field rsu-hotfix">
		<label class="rsu-hotfix-toggle">
			<input type="checkbox" id="rsu-is-hotfix" name="rsu_is_hotfix" value="1"
				<?php checked( $is_hotfix, '1' ); ?> />
			This is a hotfix
		</label>

		<div class="rsu-hotfix-fields" id="rsu-hotfix-fields" <?php echo $is_hotfix ? '' : 'hidden'; ?>>
			<p class="rsu-hotfix-desc">Patch tied to a base release, with per-generation build numbers.</p>

			<div class="rsu-hotfix-row">
				<label for="rsu-parent-release">Base release</label>
				<select id="rsu-parent-release" name="rsu_parent_release">
					<option value="0">&mdash; Select base release &mdash;</option>
					<?php foreach ( $base_releases as $release ) : ?>
						<option value="<?php echo esc_attr( $release->ID ); ?>"
							<?php selected( $parent_id, $release->ID ); ?>>
							<?php echo esc_html( $release->post_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="rsu-hotfix-builds">
				<span class="rsu-hotfix-builds__label">Build numbers</span>
				<?php
				foreach ( $all_vehicles as $v_slug => $vehicle ) :
					$generations = ! empty( $vehicle['generations'] ) ? $vehicle['generations'] : array();
					if ( empty( $generations ) ) {
						continue;
					}
					?>
					<div class="rsu-hotfix-vehicle">
						<span class="rsu-hotfix-vehicle__label"><?php echo esc_html( $vehicle['label'] ); ?></span>
						<?php
						foreach ( $generations as $g_slug => $gen ) :
							$value = isset( $hotfix_builds[ $v_slug ][ $g_slug ] ) ? $hotfix_builds[ $v_slug ][ $g_slug ] : '';
							$field = 'rsu_hotfix_builds[' . $v_slug . '][' . $g_slug . ']';
							$id    = 'rsu-build-' . $v_slug . '-' . $g_slug;
							?>
							<div class="rsu-hotfix-build">
								<label class="rsu-hotfix-build__gen" for="<?php echo esc_attr( $id ); ?>">
									<?php echo esc_html( $gen['label'] ); ?>
								</label>
								<input type="text" id="<?php echo esc_attr( $id ); ?>"
									name="<?php echo esc_attr( $field ); ?>"
									value="<?php echo esc_attr( $value ); ?>"
									placeholder="e.g. 2026.15.01" />
							</div>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
</div>

<script>
( function () {
	var toggle = document.getElementById( 'rsu-is-hotfix' );
	var fields = document.getElementById( 'rsu-hotfix-fields' );
	if ( ! toggle || ! fields ) {
		return;
	}
	toggle.addEventListener( 'change', function () {
		if ( toggle.checked ) {
			fields.removeAttribute( 'hidden' );
		} else {
			fields.setAttribute( 'hidden', '' );
		}
	} );
} )();
</script>
