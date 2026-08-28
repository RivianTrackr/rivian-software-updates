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
									class="rsu-hotfix-build__input"
									placeholder="e.g. 2026.15.01" />
							</div>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="rsu-hotfix-suggest" id="rsu-hotfix-suggest" hidden>
				<span class="rsu-hotfix-suggest__label">Suggested title</span>
				<div class="rsu-hotfix-suggest__bar">
					<code class="rsu-hotfix-suggest__value" id="rsu-hotfix-suggest-value"></code>
					<button type="button" class="button" id="rsu-hotfix-suggest-apply">Use title</button>
				</div>
			</div>
		</div>
	</div>
</div>

<script>
( function () {
	var toggle     = document.getElementById( 'rsu-is-hotfix' );
	var fields     = document.getElementById( 'rsu-hotfix-fields' );
	var suggestBox = document.getElementById( 'rsu-hotfix-suggest' );
	var suggestVal = document.getElementById( 'rsu-hotfix-suggest-value' );
	var applyBtn   = document.getElementById( 'rsu-hotfix-suggest-apply' );
	if ( ! toggle || ! fields ) {
		return;
	}

	var builds = Array.prototype.slice.call(
		fields.querySelectorAll( '.rsu-hotfix-build__input' )
	);

	// Title last applied by this helper, so we only auto-fill when the title is
	// empty or still matches our own suggestion — never clobber a manual title.
	var lastApplied = '';

	// Reduce a build number to its release family by dropping the trailing patch
	// segment (e.g. "2026.15.30" -> "2026.15"). Builds with fewer than three
	// dot-separated parts are used as-is.
	function familyOf( build ) {
		var parts = build.split( '.' );
		if ( parts.length >= 3 ) {
			parts.pop();
			return parts.join( '.' );
		}
		return build;
	}

	// Build the suggested title from the base release family of the first build,
	// suffixed with " Hotfix" (e.g. "2026.15.30" -> "2026.15 Hotfix").
	function suggestedTitle() {
		for ( var i = 0; i < builds.length; i++ ) {
			var v = builds[ i ].value.trim();
			if ( v ) {
				return familyOf( v ) + ' Hotfix';
			}
		}
		return '';
	}

	// Read/write the post title across both the block and classic editors.
	function editorTitle() {
		if ( window.wp && wp.data && wp.data.select( 'core/editor' ) ) {
			return wp.data.select( 'core/editor' ).getEditedPostAttribute( 'title' ) || '';
		}
		var el = document.getElementById( 'title' );
		return el ? el.value : '';
	}

	function setEditorTitle( value ) {
		if ( window.wp && wp.data && wp.data.dispatch( 'core/editor' ) ) {
			wp.data.dispatch( 'core/editor' ).editPost( { title: value } );
			lastApplied = value;
			return true;
		}
		var el = document.getElementById( 'title' );
		if ( el ) {
			el.value = value;
			var prompt = document.getElementById( 'title-prompt-text' );
			if ( prompt ) {
				prompt.className = 'screen-reader-text';
			}
			lastApplied = value;
			return true;
		}
		return false;
	}

	function refresh( fromUserInput ) {
		var title = suggestedTitle();

		// Update the suggestion display.
		if ( suggestVal ) {
			suggestVal.textContent = title;
		}
		if ( suggestBox ) {
			if ( toggle.checked && title ) {
				suggestBox.removeAttribute( 'hidden' );
			} else {
				suggestBox.setAttribute( 'hidden', '' );
			}
		}

		// Auto-fill on user input when the title is empty or still ours.
		if ( fromUserInput && toggle.checked && title ) {
			var current = editorTitle().trim();
			if ( '' === current || current === lastApplied ) {
				setEditorTitle( title );
			}
		}
	}

	toggle.addEventListener( 'change', function () {
		if ( toggle.checked ) {
			fields.removeAttribute( 'hidden' );
		} else {
			fields.setAttribute( 'hidden', '' );
		}
		refresh( true );
	} );

	builds.forEach( function ( input ) {
		input.addEventListener( 'input', function () {
			refresh( true );
		} );
	} );

	if ( applyBtn ) {
		applyBtn.addEventListener( 'click', function () {
			var title = suggestedTitle();
			if ( title ) {
				setEditorTitle( title );
			}
		} );
	}

	// Populate the suggestion text on load without touching an existing title.
	refresh( false );
} )();
</script>

<?php
// ── Archived release-notes documents ──
// Rivian can reissue the notes for a version it has already shipped, so every
// distinct PDF is kept and listed here rather than being overwritten.
$rsu_history = array();
foreach ( RSU_Platforms::get_all() as $rsu_slug => $rsu_vehicle ) {
	$rsu_revisions = RSU_Rivian_Poller::get_revisions( $post->ID, $rsu_slug );
	if ( ! empty( $rsu_revisions ) ) {
		$rsu_history[ $rsu_slug ] = array(
			'label'     => $rsu_vehicle['label'],
			'revisions' => $rsu_revisions,
		);
	}
}

if ( ! empty( $rsu_history ) ) :
	?>
	<div class="rsu-notes-history">
		<p class="rsu-notes-history__title">Release notes history</p>
		<?php foreach ( $rsu_history as $rsu_slug => $rsu_group ) : ?>
			<p class="rsu-notes-history__vehicle"><?php echo esc_html( $rsu_group['label'] ); ?></p>
			<ul class="rsu-notes-history__list">
				<?php foreach ( array_reverse( $rsu_group['revisions'] ) as $rsu_rev ) :
					$rsu_index = isset( $rsu_rev['index'] ) ? (int) $rsu_rev['index'] : 0;
					$rsu_link  = wp_nonce_url(
						admin_url( 'admin-post.php?action=rsu_rivian_notes_pdf&post_id=' . (int) $post->ID . '&vehicle=' . rawurlencode( $rsu_slug ) . '&revision=' . $rsu_index ),
						'rsu_rivian_download_' . (int) $post->ID . '_' . $rsu_slug . '_' . $rsu_index
					);
					?>
					<li>
						<a href="<?php echo esc_url( $rsu_link ); ?>" target="_blank" rel="noopener noreferrer">
							Revision <?php echo (int) $rsu_index; ?>
						</a>
						<span class="rsu-notes-history__meta">
							<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $rsu_rev['at'] ) ); ?>
							<?php if ( ! empty( $rsu_rev['draft'] ) ) : ?>
								<span class="rsu-notes-history__draft" title="Rivian marked this document THIS IS DRAFT CONTENT">DRAFT</span>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endforeach; ?>
	</div>
	<style>
	.rsu-notes-history { margin-top: 16px; padding-top: 14px; border-top: 1px solid #d2d2d7; }
	.rsu-notes-history__title { margin: 0 0 8px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #6e6e73; }
	.rsu-notes-history__vehicle { margin: 10px 0 4px; font-size: 12px; font-weight: 600; color: #1d1d1f; }
	.rsu-notes-history__list { margin: 0; padding: 0; list-style: none; }
	.rsu-notes-history__list li { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; padding: 4px 0; font-size: 12px; }
	.rsu-notes-history__meta { color: #86868b; font-size: 11px; display: inline-flex; align-items: center; gap: 6px; }
	.rsu-notes-history__draft { background: #fff3cd; color: #856404; border-radius: 4px; padding: 1px 5px; font-size: 9px; font-weight: 700; letter-spacing: 0.5px; }
	</style>
<?php endif; ?>
