<?php
defined( 'ABSPATH' ) || exit;

$dry_run_results       = null;
$migrate_results       = null;
$block_dry_run_results = null;
$block_migrate_results = null;
$is_force              = false;
$is_block              = false;

// Essential Blocks toggle migration actions.
if ( isset( $_POST['rsu_migrate_preview'] ) && check_admin_referer( 'rsu_migrate' ) ) {
	$dry_run_results = RSU_Migrate::migrate_all( true );
}

if ( isset( $_POST['rsu_migrate_run'] ) && check_admin_referer( 'rsu_migrate' ) ) {
	$migrate_results = RSU_Migrate::migrate_all( false );
}

if ( isset( $_POST['rsu_migrate_force_preview'] ) && check_admin_referer( 'rsu_migrate' ) ) {
	$dry_run_results = RSU_Migrate::migrate_all( true, true );
	$is_force = true;
}

if ( isset( $_POST['rsu_migrate_force_run'] ) && check_admin_referer( 'rsu_migrate' ) ) {
	$migrate_results = RSU_Migrate::migrate_all( false, true );
	$is_force = true;
}

// Block editor migration actions.
if ( isset( $_POST['rsu_block_preview'] ) && check_admin_referer( 'rsu_migrate' ) ) {
	$block_dry_run_results = RSU_Migrate::migrate_all_blocks( true );
	$is_block = true;
}

if ( isset( $_POST['rsu_block_run'] ) && check_admin_referer( 'rsu_migrate' ) ) {
	$block_migrate_results = RSU_Migrate::migrate_all_blocks( false );
	$is_block = true;
}

if ( isset( $_POST['rsu_block_force_preview'] ) && check_admin_referer( 'rsu_migrate' ) ) {
	$block_dry_run_results = RSU_Migrate::migrate_all_blocks( true, true );
	$is_force = true;
	$is_block = true;
}

if ( isset( $_POST['rsu_block_force_run'] ) && check_admin_referer( 'rsu_migrate' ) ) {
	$block_migrate_results = RSU_Migrate::migrate_all_blocks( false, true );
	$is_force = true;
	$is_block = true;
}

$migratable   = RSU_Migrate::get_migratable_posts();
$all_toggle   = RSU_Migrate::get_migratable_posts( true );
$already_done = count( $all_toggle ) - count( $migratable );

$block_migratable   = RSU_Migrate::get_block_migratable_posts();
$all_block          = RSU_Migrate::get_block_migratable_posts( true );
$block_already_done = count( $all_block ) - count( $block_migratable );
?>
<div class="wrap">
	<h1>Migrate Essential Blocks Toggle Content</h1>
	<p>This tool converts old Essential Blocks Gen&nbsp;1 / Gen&nbsp;2 toggle posts to the RSU plugin format with intelligent generation tagging.</p>
	<p><strong>How it works:</strong> For each post, the Gen 1 and Gen 2 content are parsed and compared section by section. Identical content is merged without tags. Differences are tagged with "Gen 1 Only" or "Gen 2 Only" pills.</p>

	<hr>

	<h2>Not Yet Migrated (<?php echo count( $migratable ); ?>)</h2>

	<?php if ( empty( $migratable ) ) : ?>
		<p>All toggle posts have been migrated.</p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr><th>ID</th><th>Title</th></tr>
			</thead>
			<tbody>
				<?php foreach ( $migratable as $post ) : ?>
					<tr>
						<td><?php echo esc_html( $post->ID ); ?></td>
						<td><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( $post->post_title ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" style="margin-top: 20px;" class="rsu-migrate-form">
			<?php wp_nonce_field( 'rsu_migrate' ); ?>
			<p>
				<button type="submit" name="rsu_migrate_preview" class="button button-secondary">Preview (Dry Run)</button>
				<button type="submit" name="rsu_migrate_run" class="button button-primary" data-rsu-confirm="This will write RSU section data to all eligible posts. Continue?">Migrate All</button>
			</p>
		</form>
	<?php endif; ?>

	<?php if ( $already_done > 0 ) : ?>
		<hr>
		<h2>Re-migrate Already Migrated Posts (<?php echo $already_done; ?>)</h2>
		<p>Use this to re-run migration on posts that were already converted. This <strong>overwrites</strong> existing RSU section data with a fresh parse from the Essential Blocks content.</p>

		<form method="post" style="margin-top: 10px;" class="rsu-migrate-form">
			<?php wp_nonce_field( 'rsu_migrate' ); ?>
			<p>
				<button type="submit" name="rsu_migrate_force_preview" class="button button-secondary">Preview Re-migration (Dry Run)</button>
				<button type="submit" name="rsu_migrate_force_run" class="button button-primary" style="background: #d63638; border-color: #d63638;" data-rsu-confirm="This will OVERWRITE existing RSU section data for all toggle posts. Continue?">Re-migrate All (Force)</button>
			</p>
		</form>
	<?php endif; ?>

	<!-- ═══ Block Editor Migration ═══ -->
	<hr>
	<h1 style="margin-top: 30px;">Migrate WordPress Block Editor Posts</h1>
	<p>This tool converts posts that use standard WordPress block editor content (headings, paragraphs, lists) into the RSU plugin format. Table of contents and video embeds are automatically skipped.</p>
	<p><strong>How it works:</strong> The post content is parsed directly &mdash; no Gen&nbsp;1 / Gen&nbsp;2 split. The same sections are saved for all default vehicles (<?php echo esc_html( implode( ', ', array_map( 'strtoupper', (array) RSU_Settings::get( 'default_vehicles', array( 'r1' ) ) ) ) ); ?>).</p>

	<hr>

	<h2>Not Yet Migrated (<?php echo count( $block_migratable ); ?>)</h2>

	<?php if ( empty( $block_migratable ) ) : ?>
		<p>All block editor posts have been migrated (or none found).</p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr><th>ID</th><th>Title</th></tr>
			</thead>
			<tbody>
				<?php foreach ( $block_migratable as $post ) : ?>
					<tr>
						<td><?php echo esc_html( $post->ID ); ?></td>
						<td><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( $post->post_title ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" style="margin-top: 20px;" class="rsu-migrate-form">
			<?php wp_nonce_field( 'rsu_migrate' ); ?>
			<p>
				<button type="submit" name="rsu_block_preview" class="button button-secondary">Preview (Dry Run)</button>
				<button type="submit" name="rsu_block_run" class="button button-primary" data-rsu-confirm="This will write RSU section data to all eligible block editor posts. Continue?">Migrate All</button>
			</p>
		</form>
	<?php endif; ?>

	<?php if ( $block_already_done > 0 ) : ?>
		<hr>
		<h2>Re-migrate Already Migrated Block Posts (<?php echo $block_already_done; ?>)</h2>
		<p>Use this to re-run migration on block editor posts that were already converted. This <strong>overwrites</strong> existing RSU section data.</p>

		<form method="post" style="margin-top: 10px;" class="rsu-migrate-form">
			<?php wp_nonce_field( 'rsu_migrate' ); ?>
			<p>
				<button type="submit" name="rsu_block_force_preview" class="button button-secondary">Preview Re-migration (Dry Run)</button>
				<button type="submit" name="rsu_block_force_run" class="button button-primary" style="background: #d63638; border-color: #d63638;" data-rsu-confirm="This will OVERWRITE existing RSU section data for all block editor posts. Continue?">Re-migrate All (Force)</button>
			</p>
		</form>
	<?php endif; ?>

	<?php
	// Diagnostic: check DB directly for all toggle posts.
	if ( isset( $_POST['rsu_migrate_diagnose'] ) && check_admin_referer( 'rsu_migrate' ) ) :
		global $wpdb;
		$diag_posts = RSU_Migrate::get_migratable_posts( true );
		?>
		<hr>
		<h2>Database Diagnostic</h2>
		<table class="widefat striped" style="font-size: 12px;">
			<thead>
				<tr>
					<th>ID</th>
					<th>Title</th>
					<th>_rsu_is_update</th>
					<th>_rsu_vehicles</th>
					<th>_rsu_sections_r1</th>
					<th>_rsu_content_r1</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $diag_posts as $dp ) :
					$pid = $dp->ID;
					$is_update  = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_rsu_is_update' LIMIT 1", $pid ) );
					$vehicles   = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_rsu_vehicles' LIMIT 1", $pid ) );
					$sections   = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_rsu_sections_r1' LIMIT 1", $pid ) );
					$content    = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_rsu_content_r1' LIMIT 1", $pid ) );
					?>
					<tr>
						<td><?php echo esc_html( $pid ); ?></td>
						<td><?php echo esc_html( $dp->post_title ); ?></td>
						<td><?php echo null === $is_update ? '<em style="color:red;">NOT SET</em>' : esc_html( $is_update ); ?></td>
						<td><?php echo null === $vehicles ? '<em style="color:red;">NOT SET</em>' : esc_html( substr( $vehicles, 0, 50 ) ); ?></td>
						<td><?php echo null === $sections ? '<em style="color:red;">NOT SET</em>' : esc_html( strlen( $sections ) ) . ' chars'; ?></td>
						<td><?php echo null === $content ? '<em style="color:red;">NOT SET</em>' : esc_html( strlen( $content ) ) . ' chars'; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<hr>
	<h2>Diagnostics</h2>
	<form method="post" style="margin-top: 10px;">
		<?php wp_nonce_field( 'rsu_migrate' ); ?>
		<button type="submit" name="rsu_migrate_diagnose" class="button button-secondary">Check Database Directly</button>
	</form>

	<script>
	(function() {
		function rsuConfirm(message) {
			return new Promise(function (resolve) {
				var dialog = document.createElement('dialog');
				dialog.className = 'rsu-confirm-dialog';
				dialog.setAttribute('style', 'border:none;border-radius:12px;padding:0;box-shadow:0 8px 32px rgba(0,0,0,0.2);max-width:400px;width:calc(100% - 32px);');
				dialog.innerHTML =
					'<div style="padding:24px 24px 0;"><p style="font-size:14px;line-height:1.6;margin:0;">' + message + '</p></div>' +
					'<div style="display:flex;justify-content:flex-end;gap:8px;padding:16px 24px 20px;">' +
						'<button type="button" class="button button-secondary rsu-dlg-cancel">Cancel</button>' +
						'<button type="button" class="button button-primary" style="background:#d63638;border-color:#d63638;">Confirm</button>' +
					'</div>';
				document.body.appendChild(dialog);
				dialog.showModal();
				dialog.querySelector('.rsu-dlg-cancel').addEventListener('click', function () { dialog.close(); dialog.remove(); resolve(false); });
				dialog.querySelector('.button-primary').addEventListener('click', function () { dialog.close(); dialog.remove(); resolve(true); });
				dialog.addEventListener('cancel', function () { dialog.remove(); resolve(false); });
			});
		}

		// Intercept confirm buttons and add loading state.
		document.querySelectorAll('.rsu-migrate-form').forEach(function (form) {
			form.addEventListener('click', function (e) {
				var btn = e.target.closest('[data-rsu-confirm]');
				if (!btn) return;

				e.preventDefault();
				var msg = btn.getAttribute('data-rsu-confirm');
				rsuConfirm(msg).then(function (ok) {
					if (!ok) return;
					// Add loading state to all buttons in form.
					form.querySelectorAll('button[type="submit"]').forEach(function (b) {
						b.disabled = true;
						b.style.opacity = '0.6';
					});
					btn.textContent = btn.textContent.trim() + '...';
					// Create a hidden input to carry the button name since disabled buttons don't submit.
					var hidden = document.createElement('input');
					hidden.type = 'hidden';
					hidden.name = btn.name;
					hidden.value = '1';
					form.appendChild(hidden);
					form.submit();
				});
			});

			// Also add loading state for non-confirm buttons (Preview/Dry Run).
			form.addEventListener('submit', function () {
				form.querySelectorAll('button[type="submit"]').forEach(function (b) {
					b.disabled = true;
					b.style.opacity = '0.6';
				});
			});
		});
	})();
	</script>

	<?php if ( $dry_run_results || $migrate_results ) :
		$results = $dry_run_results ? $dry_run_results : $migrate_results;
		$is_dry  = (bool) $dry_run_results;
		?>
		<hr>
		<h2><?php echo $is_dry ? 'Preview Results' : 'Migration Results'; ?> (Toggle)<?php echo $is_force ? ' (Force)' : ''; ?></h2>

		<?php foreach ( $results as $result ) : ?>
			<?php if ( is_wp_error( $result ) ) : ?>
				<div class="notice notice-warning inline" style="margin: 10px 0; padding: 10px;">
					<strong>Error:</strong> <?php echo esc_html( $result->get_error_message() ); ?>
				</div>
			<?php else : ?>
				<div class="notice notice-success inline" style="margin: 10px 0; padding: 10px;">
					<strong><?php echo esc_html( $result['title'] ); ?></strong> (ID: <?php echo esc_html( $result['post_id'] ); ?>)
					&mdash; Gen 1: <?php echo esc_html( $result['stats']['gen1_sections'] ); ?> sections,
					Gen 2: <?php echo esc_html( $result['stats']['gen2_sections'] ); ?> sections
					&rarr; Merged: <?php echo esc_html( $result['stats']['merged'] ); ?> sections
					<?php if ( ! $is_dry && ! empty( $result['saved'] ) ) : ?>
						<span style="color: green;">&check; Saved</span>
					<?php endif; ?>
				</div>

				<?php if ( $is_dry ) : ?>
					<details style="margin: 0 0 15px 10px;">
						<summary>View merged sections JSON</summary>
						<pre style="background: #f0f0f1; padding: 12px; overflow-x: auto; max-height: 400px; font-size: 12px;"><?php
							echo esc_html( wp_json_encode( $result['sections'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
						?></pre>
					</details>
				<?php endif; ?>
			<?php endif; ?>
		<?php endforeach; ?>
	<?php endif; ?>

	<?php if ( $block_dry_run_results || $block_migrate_results ) :
		$results = $block_dry_run_results ? $block_dry_run_results : $block_migrate_results;
		$is_dry  = (bool) $block_dry_run_results;
		?>
		<hr>
		<h2><?php echo $is_dry ? 'Preview Results' : 'Migration Results'; ?> (Block Editor)<?php echo $is_force ? ' (Force)' : ''; ?></h2>

		<?php foreach ( $results as $result ) : ?>
			<?php if ( is_wp_error( $result ) ) : ?>
				<div class="notice notice-warning inline" style="margin: 10px 0; padding: 10px;">
					<strong>Error:</strong> <?php echo esc_html( $result->get_error_message() ); ?>
				</div>
			<?php else : ?>
				<div class="notice notice-success inline" style="margin: 10px 0; padding: 10px;">
					<strong><?php echo esc_html( $result['title'] ); ?></strong> (ID: <?php echo esc_html( $result['post_id'] ); ?>)
					&mdash; <?php echo esc_html( $result['stats']['sections'] ); ?> sections,
					<?php echo esc_html( $result['stats']['blocks'] ); ?> blocks
					&rarr; Vehicles: <?php echo esc_html( implode( ', ', array_map( 'strtoupper', $result['vehicles'] ) ) ); ?>
					<?php if ( ! $is_dry && ! empty( $result['saved'] ) ) : ?>
						<span style="color: green;">&check; Saved</span>
					<?php endif; ?>
				</div>

				<?php if ( $is_dry ) : ?>
					<details style="margin: 0 0 15px 10px;">
						<summary>View parsed sections JSON</summary>
						<pre style="background: #f0f0f1; padding: 12px; overflow-x: auto; max-height: 400px; font-size: 12px;"><?php
							echo esc_html( wp_json_encode( $result['sections'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
						?></pre>
					</details>
				<?php endif; ?>
			<?php endif; ?>
		<?php endforeach; ?>
	<?php endif; ?>
</div>
