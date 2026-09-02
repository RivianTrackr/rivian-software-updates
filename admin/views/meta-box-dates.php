<?php
/**
 * Meta box: Update Details (dates, build numbers, hotfix).
 *
 * @package Rivian_Software_Updates
 * @var WP_Post $post
 */

defined( 'ABSPATH' ) || exit;

$date_noticed  = get_post_meta( $post->ID, '_rsu_date_noticed', true );
$date_released = get_post_meta( $post->ID, '_rsu_date_released', true );

$is_hotfix = get_post_meta( $post->ID, '_rsu_is_hotfix', true );
$parent_id = (int) get_post_meta( $post->ID, '_rsu_parent_release', true );
$builds    = RSU_Builds::get( $post->ID );

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

// Titles keyed by ID so the editor can warn when a hotfix reuses its base
// release's title — two posts with one title collide on the URL slug.
$base_titles = array();
foreach ( $base_releases as $release ) {
	$base_titles[ (int) $release->ID ] = $release->post_title;
}

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

	<div class="rsu-field rsu-builds-field">
		<span class="rsu-builds-field__label">Build numbers</span>
		<p class="rsu-builds-field__desc">The exact version each generation received, e.g. 2026.31.00 for Gen 1 and 2026.31.30 for Gen 2. Only vehicles checked above are kept on save.</p>
		<?php
		foreach ( $all_vehicles as $v_slug => $vehicle ) :
			$generations = ! empty( $vehicle['generations'] ) ? $vehicle['generations'] : array();
			if ( empty( $generations ) ) {
				continue;
			}
			?>
			<div class="rsu-builds-vehicle">
				<span class="rsu-builds-vehicle__label"><?php echo esc_html( $vehicle['label'] ); ?></span>
				<?php
				foreach ( $generations as $g_slug => $gen ) :
					$value = isset( $builds[ $v_slug ][ $g_slug ] ) ? $builds[ $v_slug ][ $g_slug ] : '';
					$field = 'rsu_builds[' . $v_slug . '][' . $g_slug . ']';
					$id    = 'rsu-build-' . $v_slug . '-' . $g_slug;
					?>
					<div class="rsu-build-row">
						<label class="rsu-build-row__gen" for="<?php echo esc_attr( $id ); ?>">
							<?php echo esc_html( $gen['label'] ); ?>
						</label>
						<input type="text" id="<?php echo esc_attr( $id ); ?>"
							name="<?php echo esc_attr( $field ); ?>"
							value="<?php echo esc_attr( $value ); ?>"
							class="rsu-build-row__input"
							placeholder="e.g. 2026.31.00" />
					</div>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="rsu-field rsu-hotfix">
		<label class="rsu-hotfix-toggle">
			<input type="checkbox" id="rsu-is-hotfix" name="rsu_is_hotfix" value="1"
				<?php checked( $is_hotfix, '1' ); ?> />
			This is a hotfix
		</label>

		<div class="rsu-hotfix-fields" id="rsu-hotfix-fields" <?php echo $is_hotfix ? '' : 'hidden'; ?>>
			<p class="rsu-hotfix-desc">A patch on top of a base release. The base release page lists its patches, and this page links back to the full notes.</p>

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

			<div class="rsu-hotfix-suggest" id="rsu-hotfix-suggest" hidden>
				<span class="rsu-hotfix-suggest__label">Suggested title</span>
				<div class="rsu-hotfix-suggest__bar">
					<code class="rsu-hotfix-suggest__value" id="rsu-hotfix-suggest-value"></code>
					<button type="button" class="button" id="rsu-hotfix-suggest-apply">Use title</button>
				</div>
			</div>

			<p class="rsu-hotfix-warning" id="rsu-hotfix-warning" hidden>
				This title matches the base release, so the two posts will collide on the same URL. Give the hotfix its own title.
			</p>
		</div>
	</div>
</div>

<script>
( function () {
	var toggle     = document.getElementById( 'rsu-is-hotfix' );
	var fields     = document.getElementById( 'rsu-hotfix-fields' );
	var parentSel  = document.getElementById( 'rsu-parent-release' );
	var suggestBox = document.getElementById( 'rsu-hotfix-suggest' );
	var suggestVal = document.getElementById( 'rsu-hotfix-suggest-value' );
	var applyBtn   = document.getElementById( 'rsu-hotfix-suggest-apply' );
	var warning    = document.getElementById( 'rsu-hotfix-warning' );
	if ( ! toggle || ! fields ) {
		return;
	}

	var baseTitles = <?php echo wp_json_encode( $base_titles ); ?> || {};

	var builds = Array.prototype.slice.call(
		document.querySelectorAll( '.rsu-build-row__input' )
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

	// The family this hotfix belongs to: the selected base release's title when
	// one is chosen, otherwise derived from the first build number entered.
	function familyName() {
		if ( parentSel && parentSel.value && baseTitles[ parentSel.value ] ) {
			return familyOf( baseTitles[ parentSel.value ].trim() );
		}
		for ( var i = 0; i < builds.length; i++ ) {
			var v = builds[ i ].value.trim();
			if ( v ) {
				return familyOf( v );
			}
		}
		return '';
	}

	// Build the suggested title from the release family, suffixed with " Hotfix"
	// (e.g. "2026.15.30" -> "2026.15 Hotfix").
	function suggestedTitle() {
		var family = familyName();
		return family ? family + ' Hotfix' : '';
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

	// Flag a hotfix whose title is identical to its base release's title.
	function refreshWarning() {
		if ( ! warning ) {
			return;
		}
		var duplicate = false;
		if ( toggle.checked && parentSel && parentSel.value && baseTitles[ parentSel.value ] ) {
			duplicate = editorTitle().trim().toLowerCase() === baseTitles[ parentSel.value ].trim().toLowerCase();
		}
		if ( duplicate ) {
			warning.removeAttribute( 'hidden' );
		} else {
			warning.setAttribute( 'hidden', '' );
		}
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

		refreshWarning();
	}

	toggle.addEventListener( 'change', function () {
		if ( toggle.checked ) {
			fields.removeAttribute( 'hidden' );
		} else {
			fields.setAttribute( 'hidden', '' );
		}
		refresh( true );
	} );

	if ( parentSel ) {
		parentSel.addEventListener( 'change', function () {
			refresh( true );
		} );
	}

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
				refreshWarning();
			}
		} );
	}

	// Watch the title itself so the duplicate warning tracks manual edits.
	var titleField = document.getElementById( 'title' );
	if ( titleField ) {
		titleField.addEventListener( 'input', refreshWarning );
	}
	if ( window.wp && wp.data && wp.data.subscribe && wp.data.select( 'core/editor' ) ) {
		var lastTitle = editorTitle();
		wp.data.subscribe( function () {
			var now = editorTitle();
			if ( now !== lastTitle ) {
				lastTitle = now;
				refreshWarning();
			}
		} );
	}

	// Populate the suggestion text on load without touching an existing title.
	refresh( false );
} )();
</script>
