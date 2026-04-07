<?php
/**
 * Plugin settings management.
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
	 * Enqueue settings page assets.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'settings_page_rsu-settings' !== $hook ) {
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		$css_file = RSU_PLUGIN_DIR . 'admin/css/rsu-settings' . $suffix . '.css';
		if ( ! file_exists( $css_file ) ) {
			$suffix = '';
		}

		wp_enqueue_style(
			'rsu-settings',
			RSU_PLUGIN_URL . 'admin/css/rsu-settings' . $suffix . '.css',
			array(),
			RSU_VERSION
		);

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

		add_management_page(
			'RSU Migration',
			'RSU Migration',
			'manage_options',
			'rsu-migrate',
			array( $this, 'render_migrate_page' )
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		include RSU_PLUGIN_DIR . 'admin/views/settings-page.php';
	}

	/**
	 * Render the migration tools page.
	 */
	public function render_migrate_page() {
		include RSU_PLUGIN_DIR . 'admin/views/migrate-page.php';
	}

	/**
	 * Register settings for sanitization.
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
