<?php
/**
 * Migration admin page.
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;

$nonce = wp_create_nonce( 'rsu_migration' );
?>

<div class="wrap">
	<h1>Software Update Migration</h1>
	<p>Scan existing posts and migrate them to use the Rivian Software Updates plugin.</p>

	<div id="rsu-migration-app" data-nonce="<?php echo esc_attr( $nonce ); ?>">

		<div class="rsu-migration-controls" style="margin-bottom: 20px;">
			<button type="button" id="rsu-scan-btn" class="button button-primary">
				Scan Posts
			</button>
			<button type="button" id="rsu-migrate-selected-btn" class="button" style="display:none;">
				Migrate Selected
			</button>
			<span id="rsu-status" style="margin-left: 12px;"></span>
		</div>

		<div id="rsu-migration-options" style="display:none; margin-bottom: 20px; padding: 12px 16px; background: #f6f7f7; border: 1px solid #dcdcde;">
			<h3 style="margin-top: 0;">Migration Settings</h3>
			<p>
				<label><strong>Default Platforms:</strong></label><br />
				<label><input type="checkbox" class="rsu-mig-platform" value="gen1" checked /> Gen 1 R1</label>
				<label><input type="checkbox" class="rsu-mig-platform" value="gen2" checked /> Gen 2 R1</label>
				<label><input type="checkbox" class="rsu-mig-platform" value="r2" /> R2</label>
			</p>
		</div>

		<table class="wp-list-table widefat fixed striped" id="rsu-posts-table" style="display:none;">
			<thead>
				<tr>
					<td class="manage-column column-cb check-column">
						<input type="checkbox" id="rsu-select-all" />
					</td>
					<th class="manage-column">Title</th>
					<th class="manage-column" style="width:100px;">Date</th>
					<th class="manage-column" style="width:100px;">Status</th>
					<th class="manage-column" style="width:120px;">Has Toggle</th>
				</tr>
			</thead>
			<tbody id="rsu-posts-body"></tbody>
		</table>

		<div id="rsu-progress" style="display:none; margin-top: 20px;">
			<div style="background: #dcdcde; border-radius: 4px; overflow: hidden;">
				<div id="rsu-progress-bar" style="background: #2271b1; height: 20px; width: 0%; transition: width 0.3s;"></div>
			</div>
			<p id="rsu-progress-text"></p>
		</div>
	</div>
</div>

<script>
(function () {
	var nonce = document.getElementById('rsu-migration-app').dataset.nonce;
	var scanBtn = document.getElementById('rsu-scan-btn');
	var migrateBtn = document.getElementById('rsu-migrate-selected-btn');
	var statusEl = document.getElementById('rsu-status');
	var tableEl = document.getElementById('rsu-posts-table');
	var tbody = document.getElementById('rsu-posts-body');
	var selectAll = document.getElementById('rsu-select-all');
	var optionsEl = document.getElementById('rsu-migration-options');
	var progressEl = document.getElementById('rsu-progress');
	var progressBar = document.getElementById('rsu-progress-bar');
	var progressText = document.getElementById('rsu-progress-text');

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
					var tr = document.createElement('tr');
					tr.innerHTML =
						'<th class="check-column"><input type="checkbox" class="rsu-post-cb" value="' + p.id + '" ' + (p.migrated ? 'disabled' : '') + ' /></th>' +
						'<td><a href="' + p.url + '" target="_blank">' + p.title + '</a></td>' +
						'<td>' + p.date + '</td>' +
						'<td>' + (p.migrated ? '<span style="color:green;">Migrated</span>' : '<span style="color:#787c82;">Pending</span>') + '</td>' +
						'<td>' + (p.has_toggle ? 'Yes' : 'No') + '</td>';
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

	migrateBtn.addEventListener('click', function () {
		var selected = [];
		tbody.querySelectorAll('.rsu-post-cb:checked').forEach(function (cb) {
			selected.push(parseInt(cb.value, 10));
		});

		if (!selected.length) {
			alert('No posts selected.');
			return;
		}

		var platforms = [];
		document.querySelectorAll('.rsu-mig-platform:checked').forEach(function (cb) {
			platforms.push(cb.value);
		});

		if (!platforms.length) {
			alert('Select at least one platform.');
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
				// Refresh the table.
				scanBtn.click();
				return;
			}

			var postId = selected.shift();
			progressText.textContent = 'Migrating post ' + (done + 1) + ' of ' + total + '...';

			var fd = new FormData();
			fd.append('action', 'rsu_migrate_post');
			fd.append('nonce', nonce);
			fd.append('post_id', postId);
			platforms.forEach(function (p) { fd.append('platforms[]', p); });

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
