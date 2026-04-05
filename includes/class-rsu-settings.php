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
		'default_platforms'  => array( 'gen1', 'gen2' ),
		'default_tab'        => 'gen2',
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

		// ── General section ──
		add_settings_section(
			'rsu_general',
			'General',
			array( $this, 'render_section_general' ),
			'rsu-settings'
		);

		add_settings_field(
			'default_platforms',
			'Default Platforms',
			array( $this, 'render_field_default_platforms' ),
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

		// ── Appearance section ──
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

		// ── SEO / Schema section ──
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

	// ── Section descriptions ──

	public function render_section_general() {
		echo '<p>Configure default behavior for the release notes editor.</p>';
	}

	public function render_section_appearance() {
		echo '<p>Customize the frontend appearance of release notes.</p>';
	}

	public function render_section_schema() {
		echo '<p>Configure SEO structured data output for software update posts.</p>';
	}

	// ── Field renderers ──

	public function render_field_default_platforms() {
		$settings  = self::get_all();
		$platforms = RSU_Platforms::get_all();
		$selected  = (array) $settings['default_platforms'];

		foreach ( $platforms as $slug => $platform ) {
			printf(
				'<label style="margin-right: 16px;"><input type="checkbox" name="%s[default_platforms][]" value="%s" %s /> %s <span class="description">(%s)</span></label>',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $slug ),
				checked( in_array( $slug, $selected, true ), true, false ),
				esc_html( $platform['label'] ),
				esc_html( $platform['description'] )
			);
		}
		echo '<p class="description">Platforms pre-selected when creating a new software update post.</p>';
	}

	public function render_field_default_tab() {
		$settings  = self::get_all();
		$platforms = RSU_Platforms::get_all();

		echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[default_tab]">';
		foreach ( $platforms as $slug => $platform ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $slug ),
				selected( $settings['default_tab'], $slug, false ),
				esc_html( $platform['label'] )
			);
		}
		echo '</select>';
		echo '<p class="description">The platform tab shown by default on the frontend.</p>';
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
	 * Sanitize settings on save.
	 *
	 * @param array $input Raw input from form.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$clean = array();

		// Default platforms.
		$valid_slugs = array_keys( RSU_Platforms::get_all() );
		$clean['default_platforms'] = isset( $input['default_platforms'] ) && is_array( $input['default_platforms'] )
			? array_intersect( array_map( 'sanitize_text_field', $input['default_platforms'] ), $valid_slugs )
			: array();

		// Default tab.
		$clean['default_tab'] = isset( $input['default_tab'] ) && in_array( $input['default_tab'], $valid_slugs, true )
			? $input['default_tab']
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
