<?php
/**
 * Plugin settings management via WordPress Settings API.
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;

class RSU_Settings {

	const OPTION_KEY = 'rsu_settings';

	/**
	 * Default settings values.
	 *
	 * @var array
	 */
	private static $defaults = array(
		'default_vehicles'   => array( 'r1', 'r2' ),
		'default_tab'        => 'r1',
		'heading_level'      => 'h3',
		'note_label'         => 'NOTE',
		'schema_enabled'     => true,
		'organization_name'  => 'RivianTrackr',
		'archive_slug'       => '/software-updates/',
		'accent_color'       => '#fba919',
	);

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue color picker on settings page.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'settings_page_rsu-settings' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script( 'wp-color-picker', "
			jQuery(document).ready(function($) {
				$('.rsu-color-picker').wpColorPicker();
			});
		" );

		wp_add_inline_style( 'wp-color-picker', '
			.rsu-vehicles-table { max-width: 900px; border-collapse: collapse; }
			.rsu-vehicles-table th { font-size: 13px; font-weight: 600; }
			.rsu-vehicles-table td { vertical-align: top; }
			.rsu-vehicles-table input[type="text"],
			.rsu-vehicles-table input[type="number"] { font-size: 13px; padding: 4px 8px; }
			.rsu-vehicle-row { background: #fff; }
			.rsu-vehicle-row td { border-top: 2px solid #c3c4c7; padding-top: 12px; }
			.rsu-generations-table { margin-top: 8px; width: 100%; border-collapse: collapse; }
			.rsu-generations-table th { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; text-align: left; padding: 4px 6px; }
			.rsu-generations-table td { padding: 4px 6px; }
			.rsu-generations-table input[type="text"],
			.rsu-generations-table input[type="number"] { font-size: 12px; padding: 3px 6px; }
			.rsu-add-generation { font-size: 11px !important; margin-top: 4px !important; }
			.rsu-remove-btn .dashicons { font-size: 18px; width: 18px; height: 18px; }
		' );
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Optional override default.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$options = get_option( self::OPTION_KEY, array() );
		$options = wp_parse_args( $options, self::$defaults );

		// Backward compat: map old key to new.
		if ( 'default_platforms' === $key ) {
			$key = 'default_vehicles';
		}

		if ( null !== $default ) {
			return isset( $options[ $key ] ) ? $options[ $key ] : $default;
		}

		return isset( $options[ $key ] ) ? $options[ $key ] : null;
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_all() {
		$options = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $options, self::$defaults );
	}

	/**
	 * Add settings page under the Settings menu.
	 */
	public function add_menu_page() {
		add_options_page(
			'Rivian Software Updates',
			'RSU Settings',
			'manage_options',
			'rsu-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		include RSU_PLUGIN_DIR . 'admin/views/settings-page.php';
	}

	/**
	 * Register settings, sections, and fields.
	 */
	public function register_settings() {
		register_setting( 'rsu_settings_group', self::OPTION_KEY, array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_settings' ),
		) );

		register_setting( 'rsu_settings_group', RSU_Platforms::OPTION_KEY, array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_platforms' ),
		) );

		// -- Vehicles section --
		add_settings_section(
			'rsu_platforms',
			'Vehicles',
			array( $this, 'render_section_platforms' ),
			'rsu-settings'
		);

		add_settings_field(
			'platforms_manager',
			'Vehicle Models',
			array( $this, 'render_field_platforms_manager' ),
			'rsu-settings',
			'rsu_platforms'
		);

		// -- General section --
		add_settings_section(
			'rsu_general',
			'General',
			array( $this, 'render_section_general' ),
			'rsu-settings'
		);

		add_settings_field(
			'default_vehicles',
			'Default Vehicles',
			array( $this, 'render_field_default_vehicles' ),
			'rsu-settings',
			'rsu_general'
		);

		add_settings_field(
			'default_tab',
			'Default Frontend Tab',
			array( $this, 'render_field_default_tab' ),
			'rsu-settings',
			'rsu_general'
		);

		add_settings_field(
			'heading_level',
			'Section Heading Level',
			array( $this, 'render_field_heading_level' ),
			'rsu-settings',
			'rsu_general'
		);

		add_settings_field(
			'note_label',
			'Note Block Label',
			array( $this, 'render_field_note_label' ),
			'rsu-settings',
			'rsu_general'
		);

		// -- Appearance section --
		add_settings_section(
			'rsu_appearance',
			'Appearance',
			array( $this, 'render_section_appearance' ),
			'rsu-settings'
		);

		add_settings_field(
			'accent_color',
			'Accent Color',
			array( $this, 'render_field_accent_color' ),
			'rsu-settings',
			'rsu_appearance'
		);

		// -- SEO / Schema section --
		add_settings_section(
			'rsu_schema',
			'SEO & Schema',
			array( $this, 'render_section_schema' ),
			'rsu-settings'
		);

		add_settings_field(
			'schema_enabled',
			'Schema Markup',
			array( $this, 'render_field_schema_enabled' ),
			'rsu-settings',
			'rsu_schema'
		);

		add_settings_field(
			'organization_name',
			'Organization Name',
			array( $this, 'render_field_organization_name' ),
			'rsu-settings',
			'rsu_schema'
		);

		add_settings_field(
			'archive_slug',
			'Updates Archive Slug',
			array( $this, 'render_field_archive_slug' ),
			'rsu-settings',
			'rsu_schema'
		);
	}

	// -- Section descriptions --

	public function render_section_platforms() {
		echo '<p>Manage vehicle models and their generations. Each vehicle gets its own tab on the frontend. Generations are used as pill badges to mark generation-specific features within release notes.</p>';
	}

	public function render_section_general() {
		echo '<p>Configure default behavior for the release notes editor.</p>';
	}

	public function render_section_appearance() {
		echo '<p>Customize the frontend appearance of release notes.</p>';
	}

	public function render_section_schema() {
		echo '<p>Configure SEO structured data output for software update posts.</p>';
	}

	// -- Field renderers --

	public function render_field_platforms_manager() {
		$vehicles = RSU_Platforms::get_all();
		?>
		<div id="rsu-vehicles-manager">
			<?php
			$vi = 0;
			foreach ( $vehicles as $slug => $vehicle ) :
				$this->render_vehicle_block( $vi, $slug, $vehicle );
				$vi++;
			endforeach;
			?>
		</div>
		<p>
			<button type="button" class="button" id="rsu-add-vehicle">+ Add Vehicle</button>
		</p>
		<template id="rsu-vehicle-template">
			<?php $this->render_vehicle_block( '__VI__', '', array(
				'label'       => '',
				'description' => '',
				'sort'        => '',
				'generations' => array(),
			) ); ?>
		</template>
		<template id="rsu-generation-template">
			<?php $this->render_generation_row( '__VI__', '__GI__', '', array(
				'label'       => '',
				'description' => '',
				'sort'        => '',
			) ); ?>
		</template>
		<script>
		(function() {
			var manager = document.getElementById('rsu-vehicles-manager');
			var vTemplate = document.getElementById('rsu-vehicle-template');
			var gTemplate = document.getElementById('rsu-generation-template');

			document.getElementById('rsu-add-vehicle').addEventListener('click', function() {
				var vi = manager.querySelectorAll('.rsu-vehicle-block').length;
				var html = vTemplate.innerHTML.replace(/__VI__/g, vi);
				var temp = document.createElement('div');
				temp.innerHTML = html;
				var block = temp.firstElementChild;
				manager.appendChild(block);
				block.querySelector('.rsu-vehicle-slug').focus();
			});

			document.addEventListener('click', function(e) {
				if (e.target.closest('.rsu-add-generation')) {
					var block = e.target.closest('.rsu-vehicle-block');
					var tbody = block.querySelector('.rsu-gen-tbody');
					var vi = block.getAttribute('data-index');
					var gi = tbody.querySelectorAll('tr').length;
					var html = gTemplate.innerHTML.replace(/__VI__/g, vi).replace(/__GI__/g, gi);
					var temp = document.createElement('tbody');
					temp.innerHTML = html;
					var row = temp.querySelector('tr');
					tbody.appendChild(row);
					row.querySelector('.rsu-gen-slug').focus();
				}

				if (e.target.closest('.rsu-remove-vehicle')) {
					if (manager.querySelectorAll('.rsu-vehicle-block').length <= 1) {
						alert('You must have at least one vehicle.');
						return;
					}
					if (confirm('Remove this vehicle? Existing post data will not be deleted.')) {
						e.target.closest('.rsu-vehicle-block').remove();
					}
				}

				if (e.target.closest('.rsu-remove-generation')) {
					if (confirm('Remove this generation?')) {
						e.target.closest('tr').remove();
					}
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * Render a single vehicle block in the settings manager.
	 */
	private function render_vehicle_block( $vi, $slug, $vehicle ) {
		$prefix = RSU_Platforms::OPTION_KEY . '[' . $vi . ']';
		$is_existing = ! empty( $slug );
		$generations = isset( $vehicle['generations'] ) ? $vehicle['generations'] : array();
		?>
		<div class="rsu-vehicle-block" data-index="<?php echo esc_attr( $vi ); ?>" style="border: 1px solid #c3c4c7; border-radius: 6px; padding: 16px; margin-bottom: 16px; background: #fff; max-width: 800px;">
			<div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
				<div style="flex: 0 0 120px;">
					<label style="font-size: 11px; font-weight: 600; text-transform: uppercase; color: #6b7280;">Slug</label><br>
					<?php if ( $is_existing ) : ?>
						<code><?php echo esc_html( $slug ); ?></code>
						<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[slug]" value="<?php echo esc_attr( $slug ); ?>" />
					<?php else : ?>
						<input type="text" name="<?php echo esc_attr( $prefix ); ?>[slug]"
							class="rsu-vehicle-slug" style="width: 100%;"
							placeholder="e.g. r3" pattern="[a-z0-9_]+" title="Lowercase letters, numbers, underscores only"
							value="" />
					<?php endif; ?>
				</div>
				<div style="flex: 1;">
					<label style="font-size: 11px; font-weight: 600; text-transform: uppercase; color: #6b7280;">Label</label><br>
					<input type="text" name="<?php echo esc_attr( $prefix ); ?>[label]"
						value="<?php echo esc_attr( $vehicle['label'] ); ?>"
						style="width: 100%;" placeholder="e.g. R3" />
				</div>
				<div style="flex: 1;">
					<label style="font-size: 11px; font-weight: 600; text-transform: uppercase; color: #6b7280;">Description</label><br>
					<input type="text" name="<?php echo esc_attr( $prefix ); ?>[description]"
						value="<?php echo esc_attr( $vehicle['description'] ); ?>"
						style="width: 100%;" placeholder="e.g. R3 SUV" />
				</div>
				<div style="flex: 0 0 70px;">
					<label style="font-size: 11px; font-weight: 600; text-transform: uppercase; color: #6b7280;">Order</label><br>
					<input type="number" name="<?php echo esc_attr( $prefix ); ?>[sort]"
						value="<?php echo esc_attr( isset( $vehicle['sort'] ) ? $vehicle['sort'] : '' ); ?>"
						style="width: 100%;" min="0" step="10" />
				</div>
				<div style="flex: 0 0 40px; padding-top: 16px;">
					<button type="button" class="button-link rsu-remove-vehicle rsu-remove-btn" title="Remove" style="color: #b32d2e;">
						<span class="dashicons dashicons-trash"></span>
					</button>
				</div>
			</div>

			<div style="margin-left: 12px; padding: 10px 14px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 4px;">
				<strong style="font-size: 12px; color: #374151;">Generations</strong>
				<table class="rsu-generations-table">
					<thead>
						<tr>
							<th style="width: 100px;">Slug</th>
							<th>Label</th>
							<th>Description</th>
							<th style="width: 60px;">Order</th>
							<th style="width: 30px;"></th>
						</tr>
					</thead>
					<tbody class="rsu-gen-tbody">
						<?php
						$gi = 0;
						foreach ( $generations as $gen_slug => $gen ) :
							$this->render_generation_row( $vi, $gi, $gen_slug, $gen );
							$gi++;
						endforeach;
						?>
					</tbody>
				</table>
				<button type="button" class="button button-small rsu-add-generation">+ Add Generation</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single generation row within a vehicle block.
	 */
	private function render_generation_row( $vi, $gi, $slug, $gen ) {
		$prefix = RSU_Platforms::OPTION_KEY . '[' . $vi . '][generations][' . $gi . ']';
		$is_existing = ! empty( $slug );
		?>
		<tr>
			<td>
				<?php if ( $is_existing ) : ?>
					<code style="font-size: 11px;"><?php echo esc_html( $slug ); ?></code>
					<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[slug]" value="<?php echo esc_attr( $slug ); ?>" />
				<?php else : ?>
					<input type="text" name="<?php echo esc_attr( $prefix ); ?>[slug]"
						class="rsu-gen-slug" style="width: 100%;"
						placeholder="e.g. gen3" pattern="[a-z0-9_]+" title="Lowercase letters, numbers, underscores only"
						value="" />
				<?php endif; ?>
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $prefix ); ?>[label]"
					value="<?php echo esc_attr( $gen['label'] ); ?>"
					style="width: 100%;" placeholder="e.g. Gen 3" />
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $prefix ); ?>[description]"
					value="<?php echo esc_attr( $gen['description'] ); ?>"
					style="width: 100%;" placeholder="e.g. 2028+" />
			</td>
			<td>
				<input type="number" name="<?php echo esc_attr( $prefix ); ?>[sort]"
					value="<?php echo esc_attr( isset( $gen['sort'] ) ? $gen['sort'] : '' ); ?>"
					style="width: 100%;" min="0" step="10" />
			</td>
			<td>
				<button type="button" class="button-link rsu-remove-generation rsu-remove-btn" title="Remove" style="color: #b32d2e;">
					<span class="dashicons dashicons-trash"></span>
				</button>
			</td>
		</tr>
		<?php
	}

	public function render_field_default_vehicles() {
		$settings = self::get_all();
		$vehicles = RSU_Platforms::get_all();
		$selected = isset( $settings['default_vehicles'] ) ? (array) $settings['default_vehicles'] : array();

		// Backward compat.
		if ( empty( $selected ) && isset( $settings['default_platforms'] ) ) {
			$selected = (array) $settings['default_platforms'];
		}

		foreach ( $vehicles as $slug => $vehicle ) {
			printf(
				'<label style="margin-right: 16px;"><input type="checkbox" name="%s[default_vehicles][]" value="%s" %s /> %s <span class="description">(%s)</span></label>',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $slug ),
				checked( in_array( $slug, $selected, true ), true, false ),
				esc_html( $vehicle['label'] ),
				esc_html( $vehicle['description'] )
			);
		}
		echo '<p class="description">Vehicles pre-selected when creating a new software update post.</p>';
	}

	public function render_field_default_tab() {
		$settings = self::get_all();
		$vehicles = RSU_Platforms::get_all();

		echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[default_tab]">';
		foreach ( $vehicles as $slug => $vehicle ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $slug ),
				selected( $settings['default_tab'], $slug, false ),
				esc_html( $vehicle['label'] )
			);
		}
		echo '</select>';
		echo '<p class="description">The vehicle tab shown by default on the frontend for first-time visitors.</p>';
	}

	public function render_field_heading_level() {
		$settings = self::get_all();
		$levels   = array( 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4' );

		echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[heading_level]">';
		foreach ( $levels as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $settings['heading_level'], $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<p class="description">HTML heading level used for section headings in the rendered release notes.</p>';
	}

	public function render_field_note_label() {
		$settings = self::get_all();
		printf(
			'<input type="text" name="%s[note_label]" value="%s" class="regular-text" />',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $settings['note_label'] )
		);
		echo '<p class="description">Label shown at the top of note/blockquote blocks (e.g. "NOTE", "IMPORTANT", "TIP").</p>';
	}

	public function render_field_accent_color() {
		$settings = self::get_all();
		printf(
			'<input type="text" name="%s[accent_color]" value="%s" class="rsu-color-picker" data-default-color="#fba919" />',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $settings['accent_color'] )
		);
		echo '<p class="description">Primary accent color for tabs, links, and bullet markers on the frontend.</p>';
	}

	public function render_field_schema_enabled() {
		$settings = self::get_all();
		printf(
			'<label><input type="checkbox" name="%s[schema_enabled]" value="1" %s /> Output JSON-LD structured data on software update posts</label>',
			esc_attr( self::OPTION_KEY ),
			checked( $settings['schema_enabled'], true, false )
		);
	}

	public function render_field_organization_name() {
		$settings = self::get_all();
		printf(
			'<input type="text" name="%s[organization_name]" value="%s" class="regular-text" />',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $settings['organization_name'] )
		);
		echo '<p class="description">Used as the author and publisher in schema markup.</p>';
	}

	public function render_field_archive_slug() {
		$settings = self::get_all();
		echo '<code>' . esc_html( home_url() ) . '</code>';
		printf(
			'<input type="text" name="%s[archive_slug]" value="%s" style="width: 200px;" />',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $settings['archive_slug'] )
		);
		echo '<p class="description">URL path used in the breadcrumb schema for the updates archive page.</p>';
	}

	/**
	 * Sanitize platforms (vehicles + generations) on save.
	 *
	 * @param array $input Raw platforms array from form.
	 * @return array Sanitized vehicles keyed by slug.
	 */
	public function sanitize_platforms( $input ) {
		if ( ! is_array( $input ) || empty( $input ) ) {
			return RSU_Platforms::get_defaults();
		}

		$clean = array();
		$sort_counter = 10;

		foreach ( $input as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$slug = isset( $row['slug'] ) ? sanitize_key( $row['slug'] ) : '';
			if ( empty( $slug ) ) {
				continue;
			}

			if ( isset( $clean[ $slug ] ) ) {
				continue;
			}

			$vehicle = array(
				'label'       => isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : $slug,
				'description' => isset( $row['description'] ) ? sanitize_text_field( $row['description'] ) : '',
				'sort'        => isset( $row['sort'] ) && is_numeric( $row['sort'] ) ? intval( $row['sort'] ) : $sort_counter,
				'generations' => array(),
			);

			// Parse generations.
			if ( ! empty( $row['generations'] ) && is_array( $row['generations'] ) ) {
				$gen_sort = 10;
				foreach ( $row['generations'] as $gen_row ) {
					if ( ! is_array( $gen_row ) ) {
						continue;
					}

					$gen_slug = isset( $gen_row['slug'] ) ? sanitize_key( $gen_row['slug'] ) : '';
					if ( empty( $gen_slug ) || isset( $vehicle['generations'][ $gen_slug ] ) ) {
						continue;
					}

					$vehicle['generations'][ $gen_slug ] = array(
						'label'       => isset( $gen_row['label'] ) ? sanitize_text_field( $gen_row['label'] ) : $gen_slug,
						'description' => isset( $gen_row['description'] ) ? sanitize_text_field( $gen_row['description'] ) : '',
						'sort'        => isset( $gen_row['sort'] ) && is_numeric( $gen_row['sort'] ) ? intval( $gen_row['sort'] ) : $gen_sort,
					);

					$gen_sort += 10;
				}
			}

			$clean[ $slug ] = $vehicle;
			$sort_counter += 10;
		}

		if ( empty( $clean ) ) {
			return RSU_Platforms::get_defaults();
		}

		return $clean;
	}

	/**
	 * Sanitize settings on save.
	 *
	 * @param array $input Raw input from form.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$clean = array();

		// Default vehicles.
		$clean['default_vehicles'] = isset( $input['default_vehicles'] ) && is_array( $input['default_vehicles'] )
			? array_values( array_map( 'sanitize_text_field', $input['default_vehicles'] ) )
			: array();

		// Default tab.
		$clean['default_tab'] = isset( $input['default_tab'] )
			? sanitize_text_field( $input['default_tab'] )
			: self::$defaults['default_tab'];

		// Heading level.
		$valid_levels = array( 'h2', 'h3', 'h4' );
		$clean['heading_level'] = isset( $input['heading_level'] ) && in_array( $input['heading_level'], $valid_levels, true )
			? $input['heading_level']
			: self::$defaults['heading_level'];

		// Note label.
		$clean['note_label'] = isset( $input['note_label'] )
			? sanitize_text_field( $input['note_label'] )
			: self::$defaults['note_label'];

		// Schema enabled.
		$clean['schema_enabled'] = ! empty( $input['schema_enabled'] );

		// Organization name.
		$clean['organization_name'] = isset( $input['organization_name'] )
			? sanitize_text_field( $input['organization_name'] )
			: self::$defaults['organization_name'];

		// Archive slug.
		$clean['archive_slug'] = isset( $input['archive_slug'] )
			? sanitize_text_field( $input['archive_slug'] )
			: self::$defaults['archive_slug'];

		// Accent color.
		$clean['accent_color'] = isset( $input['accent_color'] ) && preg_match( '/^#[a-fA-F0-9]{6}$/', $input['accent_color'] )
			? $input['accent_color']
			: self::$defaults['accent_color'];

		return $clean;
	}
}
