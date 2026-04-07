<?php
/**
 * Migration admin page.
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;

$nonce    = wp_create_nonce( 'rsu_migration' );
$vehicles = RSU_Platforms::get_all();
?>

<div class="rsu-settings-wrap" style="max-width: 1100px;">
	<div class="rsu-settings-header">
		<div>
			<h1 class="rsu-settings-title">Software Update Migration</h1>
			<p class="rsu-settings-subtitle">Scan, migrate, and convert existing posts to use vehicle-based release notes.</p>
		</div>
	</div>

	<div id="rsu-migration-app" data-nonce="<?php echo esc_attr( $nonce ); ?>">

		<!-- Legacy Conversion Card -->
		<div class="rsu-card">
			<div class="rsu-card__header">
				<div>
					<h2 class="rsu-card__title">Convert Legacy Data</h2>
					<p class="rsu-card__desc">Convert old platform data (Gen 1, Gen 2) to the new vehicle model (R1, R2). Uses Gen 2 content as the base for R1. Safe to run multiple times — already-converted posts are skipped.</p>
				</div>
			</div>
			<div style="padding: 20px; display: flex; align-items: center; gap: 16px;">
				<button type="button" id="rsu-convert-legacy-btn" class="rsu-btn rsu-btn-primary">
					Convert to Vehicles
				</button>
				<button type="button" id="rsu-backfill-btn" class="rsu-btn rsu-btn-secondary">
					Backfill Sections
				</button>
				<span id="rsu-legacy-status" style="font-size: 14px; color: #6e6e73;"></span>
			</div>
		</div>

		<!-- Fresh Migration Card -->
		<div class="rsu-card">
			<div class="rsu-card__header">
				<div>
					<h2 class="rsu-card__title">Fresh Migration</h2>
					<p class="rsu-card__desc">Scan published posts and migrate their content to RSU meta fields. Posts with Essential Blocks toggle content are automatically parsed.</p>
				</div>
			</div>
			<div style="padding: 20px;">
				<div style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px;">
					<button type="button" id="rsu-scan-btn" class="rsu-btn rsu-btn-secondary">
						Scan Posts
					</button>
					<button type="button" id="rsu-migrate-selected-btn" class="rsu-btn rsu-btn-primary" style="display:none;">
						Migrate Selected
					</button>
					<span id="rsu-status" style="font-size: 14px; color: #6e6e73;"></span>
				</div>

				<div id="rsu-migration-options" style="display:none; margin-bottom: 20px; padding: 16px; background: #f5f5f7; border: 1px solid #d2d2d7; border-radius: 12px;">
					<p style="margin: 0 0 8px; font-size: 15px; font-weight: 500; color: #1d1d1f;">Default Vehicles</p>
					<div class="rsu-checkbox-group">
						<?php foreach ( $vehicles as $slug => $vehicle ) : ?>
							<label>
								<input type="checkbox" class="rsu-mig-vehicle" value="<?php echo esc_attr( $slug ); ?>" checked />
								<?php echo esc_html( $vehicle['label'] ); ?>
								<span class="rsu-checkbox-desc">(<?php echo esc_html( $vehicle['description'] ); ?>)</span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<table class="widefat fixed striped" id="rsu-posts-table" style="display:none; border-radius: 8px; overflow: hidden; border: 1px solid #d2d2d7;">
					<thead>
						<tr>
							<td class="manage-column column-cb check-column" style="padding: 10px 8px;">
								<input type="checkbox" id="rsu-select-all" />
							</td>
							<th class="manage-column" style="padding: 10px 8px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #6e6e73;">Title</th>
							<th class="manage-column" style="width:100px; padding: 10px 8px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #6e6e73;">Date</th>
							<th class="manage-column" style="width:100px; padding: 10px 8px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #6e6e73;">Status</th>
							<th class="manage-column" style="width:100px; padding: 10px 8px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #6e6e73;">Legacy</th>
							<th class="manage-column" style="width:100px; padding: 10px 8px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #6e6e73;">Toggle</th>
						</tr>
					</thead>
					<tbody id="rsu-posts-body"></tbody>
				</table>

				<div id="rsu-progress" style="display:none; margin-top: 20px;">
					<div style="background: #d2d2d7; border-radius: 6px; overflow: hidden;">
						<div id="rsu-progress-bar" style="background: #0071e3; height: 8px; width: 0%; transition: width 0.3s;"></div>
					</div>
					<p id="rsu-progress-text" style="font-size: 14px; color: #6e6e73; margin-top: 8px;"></p>
				</div>
			</div>
		</div>
	</div>
</div>

<script>
(function () {
	var nonce = document.getElementById('rsu-migration-app').dataset.nonce;
	var scanBtn = document.getElementById('rsu-scan-btn');
	var migrateBtn = document.getElementById('rsu-migrate-selected-btn');
	var backfillBtn = document.getElementById('rsu-backfill-btn');
	var convertBtn = document.getElementById('rsu-convert-legacy-btn');
	var statusEl = document.getElementById('rsu-status');
	var legacyStatus = document.getElementById('rsu-legacy-status');
	var tableEl = document.getElementById('rsu-posts-table');
	var tbody = document.getElementById('rsu-posts-body');
	var selectAll = document.getElementById('rsu-select-all');
	var optionsEl = document.getElementById('rsu-migration-options');
	var progressEl = document.getElementById('rsu-progress');
	var progressBar = document.getElementById('rsu-progress-bar');
	var progressText = document.getElementById('rsu-progress-text');

	// Convert Legacy Data
	convertBtn.addEventListener('click', function () {
		if (!confirm('Convert legacy platform data (gen1/gen2) to the new vehicle model (R1/R2)?\n\nGen 2 content will be used as the base for R1. Already-converted posts are skipped.')) {
			return;
		}

		convertBtn.disabled = true;
		legacyStatus.textContent = 'Converting...';

		var fd = new FormData();
		fd.append('action', 'rsu_convert_legacy');
		fd.append('nonce', nonce);

		fetch(ajaxurl, { method: 'POST', body: fd })
			.then(function (r) { return r.json(); })
			.then(function (resp) {
				convertBtn.disabled = false;
				if (!resp.success) {
					legacyStatus.textContent = 'Error: ' + (resp.data || 'Unknown');
					return;
				}
				legacyStatus.textContent = 'Done! ' + resp.data.converted + ' converted, ' + resp.data.skipped + ' skipped (of ' + resp.data.total + ' with legacy data).';
			})
			.catch(function (err) {
				convertBtn.disabled = false;
				legacyStatus.textContent = 'Error: ' + err.message;
			});
	});

	// Backfill Sections
	backfillBtn.addEventListener('click', function () {
		if (!confirm('Convert existing HTML release notes to structured sections for all migrated posts?')) {
			return;
		}

		backfillBtn.disabled = true;
		legacyStatus.textContent = 'Backfilling sections...';

		var fd = new FormData();
		fd.append('action', 'rsu_backfill_sections');
		fd.append('nonce', nonce);

		fetch(ajaxurl, { method: 'POST', body: fd })
			.then(function (r) { return r.json(); })
			.then(function (resp) {
				backfillBtn.disabled = false;
				if (!resp.success) {
					legacyStatus.textContent = 'Error: ' + (resp.data || 'Unknown');
					return;
				}
				legacyStatus.textContent = 'Backfill complete: ' + resp.data.updated + ' vehicle editor(s) converted across ' + resp.data.total_posts + ' post(s). ' + resp.data.skipped + ' already had sections.';
			})
			.catch(function (err) {
				backfillBtn.disabled = false;
				legacyStatus.textContent = 'Error: ' + err.message;
			});
	});

	// Scan Posts
	scanBtn.addEventListener('click', function () {
		statusEl.textContent = 'Scanning...';
		scanBtn.disabled = true;

		var fd = new FormData();
		fd.append('action', 'rsu_scan_posts');
		fd.append('nonce', nonce);

		fetch(ajaxurl, { method: 'POST', body: fd })
			.then(function (r) { return r.json(); })
			.then(function (resp) {
				scanBtn.disabled = false;
				if (!resp.success) {
					statusEl.textContent = 'Error: ' + (resp.data || 'Unknown');
					return;
				}

				var posts = resp.data;
				statusEl.textContent = posts.length + ' posts found.';
				tbody.innerHTML = '';

				posts.forEach(function (p) {
					var statusLabel = '';
					if (p.has_vehicles) {
						statusLabel = '<span style="color:#34c759; font-weight:500;">Migrated</span>';
					} else if (p.migrated) {
						statusLabel = '<span style="color:#f97316; font-weight:500;">Legacy</span>';
					} else {
						statusLabel = '<span style="color:#86868b;">Pending</span>';
					}

					var legacyLabel = p.has_legacy
						? '<span style="color:#f97316; font-weight:500;">Yes</span>'
						: '<span style="color:#86868b;">No</span>';

					var tr = document.createElement('tr');
					tr.innerHTML =
						'<th class="check-column" style="padding:8px;"><input type="checkbox" class="rsu-post-cb" value="' + p.id + '" ' + (p.has_vehicles ? 'disabled' : '') + ' /></th>' +
						'<td style="padding:8px;"><a href="' + p.url + '" target="_blank" style="color:#0071e3; text-decoration:none;">' + p.title + '</a></td>' +
						'<td style="padding:8px; color:#6e6e73;">' + p.date + '</td>' +
						'<td style="padding:8px;">' + statusLabel + '</td>' +
						'<td style="padding:8px;">' + legacyLabel + '</td>' +
						'<td style="padding:8px;">' + (p.has_toggle ? '<span style="color:#0071e3;">Yes</span>' : '<span style="color:#86868b;">No</span>') + '</td>';
					tbody.appendChild(tr);
				});

				tableEl.style.display = '';
				optionsEl.style.display = '';
				migrateBtn.style.display = '';
			})
			.catch(function (err) {
				scanBtn.disabled = false;
				statusEl.textContent = 'Error: ' + err.message;
			});
	});

	selectAll.addEventListener('change', function () {
		var cbs = tbody.querySelectorAll('.rsu-post-cb:not(:disabled)');
		cbs.forEach(function (cb) { cb.checked = selectAll.checked; });
	});

	// Migrate Selected
	migrateBtn.addEventListener('click', function () {
		var selected = [];
		tbody.querySelectorAll('.rsu-post-cb:checked').forEach(function (cb) {
			selected.push(parseInt(cb.value, 10));
		});

		if (!selected.length) {
			alert('No posts selected.');
			return;
		}

		var vehicles = [];
		document.querySelectorAll('.rsu-mig-vehicle:checked').forEach(function (cb) {
			vehicles.push(cb.value);
		});

		if (!vehicles.length) {
			alert('Select at least one vehicle.');
			return;
		}

		if (!confirm('Migrate ' + selected.length + ' post(s)? This will set RSU meta fields on each post.')) {
			return;
		}

		migrateBtn.disabled = true;
		progressEl.style.display = '';
		var total = selected.length;
		var done = 0;
		var errors = [];

		function next() {
			if (!selected.length) {
				progressText.textContent = 'Done! ' + done + '/' + total + ' migrated.' + (errors.length ? ' ' + errors.length + ' error(s).' : '');
				migrateBtn.disabled = false;
				scanBtn.click();
				return;
			}

			var postId = selected.shift();
			progressText.textContent = 'Migrating post ' + (done + 1) + ' of ' + total + '...';

			var fd = new FormData();
			fd.append('action', 'rsu_migrate_post');
			fd.append('nonce', nonce);
			fd.append('post_id', postId);
			vehicles.forEach(function (v) { fd.append('vehicles[]', v); });

			fetch(ajaxurl, { method: 'POST', body: fd })
				.then(function (r) { return r.json(); })
				.then(function (resp) {
					if (!resp.success) errors.push(postId);
					done++;
					progressBar.style.width = Math.round((done / total) * 100) + '%';
					next();
				})
				.catch(function () {
					errors.push(postId);
					done++;
					progressBar.style.width = Math.round((done / total) * 100) + '%';
					next();
				});
		}

		next();
	});
})();
</script>
