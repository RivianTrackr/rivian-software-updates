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
		<p class="rsu-builds-field__desc">The exact version each generation received, e.g. 2026.31.00 for Gen 1 and 2026.31.30 for Gen 2. Filled in automatically when a connected vehicle reports the update.</p>
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

<?php
// ── Archived release-notes documents ──
// Rivian ships a separate document per generation and body style, and can
// reissue any of them, so every distinct PDF is kept and listed per scope.
$rsu_history = array();
$rsu_hashes  = array();

foreach ( RSU_Platforms::get_all() as $rsu_slug => $rsu_vehicle ) {
	$rsu_scopes = array_merge( array( '' ), array_keys( RSU_Platforms::get_generations( $rsu_slug ) ) );

	foreach ( $rsu_scopes as $rsu_gen ) {
		$rsu_revisions = RSU_Rivian_Poller::get_revisions( $post->ID, $rsu_slug, $rsu_gen );

		if ( empty( $rsu_revisions ) ) {
			continue;
		}

		$rsu_gens  = RSU_Platforms::get_generations( $rsu_slug );
		$rsu_label = $rsu_vehicle['label'] . ( $rsu_gen && isset( $rsu_gens[ $rsu_gen ] ) ? ' ' . $rsu_gens[ $rsu_gen ] : '' );

		$rsu_history[] = array(
			'label'      => $rsu_label,
			'vehicle'    => $rsu_slug,
			'generation' => $rsu_gen,
			'revisions'  => $rsu_revisions,
		);

		// Track hashes so identical documents across scopes can be called out —
		// that is how you find out whether two variants really differ.
		foreach ( $rsu_revisions as $rsu_rev ) {
			if ( ! empty( $rsu_rev['hash'] ) ) {
				$rsu_hashes[ $rsu_rev['hash'] ][] = $rsu_label . ' r' . (int) $rsu_rev['index'];
			}
		}
	}
}

if ( ! empty( $rsu_history ) ) :
	?>
	<div class="rsu-notes-history">
		<p class="rsu-notes-history__title">Release notes history</p>
		<?php foreach ( $rsu_history as $rsu_group ) : ?>
			<p class="rsu-notes-history__vehicle"><?php echo esc_html( $rsu_group['label'] ); ?></p>
			<ul class="rsu-notes-history__list">
				<?php foreach ( array_reverse( $rsu_group['revisions'] ) as $rsu_rev ) :
					$rsu_index = isset( $rsu_rev['index'] ) ? (int) $rsu_rev['index'] : 0;
					$rsu_gen   = $rsu_group['generation'];
					$rsu_link  = wp_nonce_url(
						admin_url(
							'admin-post.php?action=rsu_rivian_notes_pdf&post_id=' . (int) $post->ID
							. '&vehicle=' . rawurlencode( $rsu_group['vehicle'] )
							. '&generation=' . rawurlencode( $rsu_gen )
							. '&revision=' . $rsu_index
						),
						'rsu_rivian_download_' . (int) $post->ID . '_' . $rsu_group['vehicle'] . '_' . $rsu_gen . '_' . $rsu_index
					);

					// Name any other scope holding a byte-identical document.
					$rsu_twins = array();
					if ( ! empty( $rsu_rev['hash'] ) && ! empty( $rsu_hashes[ $rsu_rev['hash'] ] ) ) {
						$rsu_twins = array_diff( $rsu_hashes[ $rsu_rev['hash'] ], array( $rsu_group['label'] . ' r' . $rsu_index ) );
					}
					?>
					<li>
						<span>
							<a href="<?php echo esc_url( $rsu_link ); ?>" target="_blank" rel="noopener noreferrer">
								Revision <?php echo (int) $rsu_index; ?>
							</a>
							<?php if ( ! empty( $rsu_rev['model'] ) ) : ?>
								<span class="rsu-notes-history__model"><?php echo esc_html( $rsu_rev['model'] ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $rsu_twins ) ) : ?>
								<small class="rsu-notes-history__same">identical to <?php echo esc_html( implode( ', ', $rsu_twins ) ); ?></small>
							<?php endif; ?>
						</span>
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
	.rsu-notes-history__meta { color: #86868b; font-size: 11px; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
	.rsu-notes-history__model { background: #f5f5f7; color: #6e6e73; border-radius: 4px; padding: 1px 5px; font-size: 10px; font-weight: 600; }
	.rsu-notes-history__same { display: block; color: #86868b; font-size: 10px; font-style: italic; }
	.rsu-notes-history__draft { background: #fff3cd; color: #856404; border-radius: 4px; padding: 1px 5px; font-size: 9px; font-weight: 700; letter-spacing: 0.5px; }
	</style>
<?php endif; ?>
