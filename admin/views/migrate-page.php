<?php
defined( 'ABSPATH' ) || exit;

$dry_run_results = null;
$migrate_results = null;

if ( isset( $_POST['rsu_migrate_preview'] ) && check_admin_referer( 'rsu_migrate' ) ) {
	$dry_run_results = RSU_Migrate::migrate_all( true );
}

if ( isset( $_POST['rsu_migrate_run'] ) && check_admin_referer( 'rsu_migrate' ) ) {
	$migrate_results = RSU_Migrate::migrate_all( false );
}

$migratable = RSU_Migrate::get_migratable_posts();
?>
<div class="wrap">
	<h1>Migrate Essential Blocks Toggle Content</h1>
	<p>This tool converts old Essential Blocks Gen&nbsp;1 / Gen&nbsp;2 toggle posts to the RSU plugin format with intelligent generation tagging.</p>
	<p><strong>How it works:</strong> For each post, the Gen 1 and Gen 2 content are parsed and compared section by section. Identical content is merged without tags. Differences are tagged with "Gen 1 Only" or "Gen 2 Only" pills.</p>

	<hr>

	<h2>Eligible Posts (<?php echo count( $migratable ); ?>)</h2>

	<?php if ( empty( $migratable ) ) : ?>
		<p>No posts found with Essential Blocks toggle content that haven't already been migrated.</p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>ID</th>
					<th>Title</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $migratable as $post ) : ?>
					<tr>
						<td><?php echo esc_html( $post->ID ); ?></td>
						<td>
							<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>">
								<?php echo esc_html( $post->post_title ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" style="margin-top: 20px;">
			<?php wp_nonce_field( 'rsu_migrate' ); ?>
			<p>
				<button type="submit" name="rsu_migrate_preview" class="button button-secondary">Preview Migration (Dry Run)</button>
				<button type="submit" name="rsu_migrate_run" class="button button-primary" onclick="return confirm('This will write RSU section data to all eligible posts. Continue?');">Migrate All</button>
			</p>
		</form>
	<?php endif; ?>

	<?php if ( $dry_run_results || $migrate_results ) :
		$results = $dry_run_results ? $dry_run_results : $migrate_results;
		$is_dry  = (bool) $dry_run_results;
		?>
		<hr>
		<h2><?php echo $is_dry ? 'Preview Results' : 'Migration Results'; ?></h2>

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
</div>
