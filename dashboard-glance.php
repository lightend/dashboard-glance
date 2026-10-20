<?php
/**
 * Plugin Name: Dashboard Glance
 * Plugin URI: https://wordpress.org/plugins/dashboard-glance/
 * Description: Adds content control and detailed status counters to the "At a Glance" widget on the WordPress Dashboard.
 * Version: 1.0.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Dashboard Glance Contributors
 * Author URI: https://wordpress.org/plugins/dashboard-glance/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dashboard-glance
 * Domain Path: /languages
 *
 * @package Dashboard_Glance
 * @version 1.0.0
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define plugin constants.
 */
define( 'DG_VERSION', '1.0.0' );
define( 'DG_DIR_PATH', plugin_dir_path( __FILE__ ) );
define( 'DG_DIR_URL', plugin_dir_url( __FILE__ ) );

// Backward-compatibility constants
if ( ! defined( 'GT_VERSION' ) ) {
	define( 'GT_VERSION', DG_VERSION );
}
if ( ! defined( 'GT_DIR_PATH' ) ) {
	define( 'GT_DIR_PATH', DG_DIR_PATH );
}
if ( ! defined( 'GT_DIR_URL' ) ) {
	define( 'GT_DIR_URL', DG_DIR_URL );
}

// Capability definitions
if ( ! defined( 'DG_EDIT_GLANCES' ) ) {
	define( 'DG_EDIT_GLANCES', defined( 'GT_EDIT_GLANCES' ) ? GT_EDIT_GLANCES : 'edit_posts' );
}
if ( ! defined( 'DG_ADMIN_GLANCES' ) ) {
	define( 'DG_ADMIN_GLANCES', defined( 'GT_ADMIN_GLANCES' ) ? GT_ADMIN_GLANCES : 'manage_options' );
}

/**
 * Initialize plugin on admin pages.
 */
add_action( 'plugins_loaded', function() {
	global $pagenow;

	if ( is_admin() && ( 'index.php' === $pagenow || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) ) {
		Dashboard_Glance::get_instance();
	}
}, 10 );

/**
 * Dashboard Glance Main Class
 */
class Dashboard_Glance {

	/**
	 * Instance of this class.
	 *
	 * @var Dashboard_Glance|null
	 */
	protected static $instance = null;

	/**
	 * Glances array for the current user.
	 *
	 * @var array
	 */
	protected $glances = array();

	/**
	 * Admin notices.
	 *
	 * @var array
	 */
	protected $notices = array();

	/**
	 * Indexed glances list for sorting reference.
	 *
	 * @var array
	 */
	protected $glances_indexed = array();

	/**
	 * Status visibility ('visible' or 'hidden').
	 *
	 * @var string
	 */
	protected $status_visibility = 'visible';

	/**
	 * Info visibility ('visible' or 'hidden').
	 *
	 * @var string
	 */
	protected $info_visibility = 'visible';

	/**
	 * Available icons.
	 *
	 * @var array
	 */
	protected $icons = array();

	/**
	 * Configured display options.
	 *
	 * @var array
	 */
	protected $options = array();

	/**
	 * Whether current user can edit their glances.
	 *
	 * @var bool
	 */
	protected $editable = false;

	/**
	 * Whether current user can manage site-wide default glances.
	 *
	 * @var bool
	 */
	protected $adminable = false;

	/**
	 * Return the singleton instance.
	 *
	 * @return Dashboard_Glance
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'initialize_variables' ), 4 );
		add_action( 'init', array( $this, 'check_user_cap' ), 5 );
		add_action( 'init', array( $this, 'get_users_glances' ), 6 );
		add_action( 'init', array( $this, 'get_user_status_visibility' ), 7 );
		add_action( 'init', array( $this, 'get_user_info_visibility' ), 8 );

		// Load administrative stylesheets and scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Filter At a Glance dashboard items
		add_filter( 'dashboard_glance_items', array( $this, 'customize_items' ), 10, 1 );

		// Post State Tags compatibility
		add_action( 'admin_head', array( $this, 'check_override_status_icons' ) );

		// Filter post type dropdown selection
		add_filter( 'dg_post_type_selection', array( $this, 'remove_post_type_options' ), 20, 1 );
		add_filter( 'gt_post_type_selection', array( $this, 'remove_post_type_options' ), 20, 1 );

		// Add management form to dashboard widget
		add_action( 'activity_box_end', array( $this, 'add_form' ) );

		// AJAX Endpoints
		add_action( 'wp_ajax_dg_sort_glances', array( $this, 'sort_glances' ) );
		add_action( 'wp_ajax_sort_glances', array( $this, 'sort_glances' ) ); // Legacy fallback

		add_action( 'wp_ajax_dg_default_glances', array( $this, 'default_glances' ) );
		add_action( 'wp_ajax_default_glances', array( $this, 'default_glances' ) ); // Legacy fallback

		add_action( 'wp_ajax_dg_toggle_status_visibility', array( $this, 'toggle_status_visibility' ) );
		add_action( 'wp_ajax_toggle_status_visibility', array( $this, 'toggle_status_visibility' ) ); // Legacy fallback

		add_action( 'wp_ajax_dg_toggle_info_visibility', array( $this, 'toggle_info_visibility' ) );
		add_action( 'wp_ajax_toggle_info_visibility', array( $this, 'toggle_info_visibility' ) ); // Legacy fallback

		add_action( 'wp_ajax_dg_add_remove_glance', array( $this, 'process_form' ) );
		add_action( 'wp_ajax_add_remove_glance', array( $this, 'process_form' ) ); // Legacy fallback

		// Custom labels and default icons
		add_filter( 'dg_labels', array( $this, 'customize_labels' ), 10, 3 );
		add_filter( 'gt_labels', array( $this, 'customize_labels' ), 10, 3 );

		add_filter( 'dg_option_icons', array( $this, 'customize_post_type_icon' ), 10, 2 );
		add_filter( 'gt_option_icons', array( $this, 'customize_post_type_icon' ), 10, 2 );

		// Ensure dashboard widget visibility capability
		add_action( 'wp_dashboard_setup', array( $this, 'ensure_at_a_glance_widget' ) );

		// Record Site Health checks
		add_action( 'set_transient_health-check-site-status-result', array( $this, 'record_site_health_status' ), 10, 3 );
	}

	/**
	 * Helper to apply backward compatible filters (dg_* with fallback to gt_*).
	 *
	 * @param string $tag Filter tag without prefix.
	 * @param mixed  $value Default value.
	 * @param mixed  ...$args Additional parameters.
	 * @return mixed
	 */
	protected function apply_compat_filter( $tag, $value, ...$args ) {
		// First apply legacy filter if hooked
		if ( has_filter( 'gt_' . $tag ) ) {
			$value = apply_filters( 'gt_' . $tag, $value, ...$args );
		}
		// Then apply new filter
		return apply_filters( 'dg_' . $tag, $value, ...$args );
	}

	/**
	 * Initialize plugin variables and options.
	 */
	public function initialize_variables() {
		$this->icons = $this->get_icons();

		$this->options = array(
			'show_zero_count'        => (bool) $this->apply_compat_filter( 'show_zero_count', true ),
			'show_mine'              => (bool) $this->apply_compat_filter( 'show_mine', false ),
			'show_zero_count_status' => (bool) $this->apply_compat_filter( 'show_zero_count_status', false ),
			'show_add_new'           => (bool) $this->apply_compat_filter( 'show_add_new', true ),
			'show_all_status'        => (bool) $this->apply_compat_filter( 'show_all_status', true ),
			'show_settings'          => (bool) $this->apply_compat_filter( 'show_settings', true ),
			'show_mustuse'           => (bool) $this->apply_compat_filter( 'show_mustuse', false ),
			'show_dropins'           => (bool) $this->apply_compat_filter( 'show_dropins', false ),
			'show_archive'           => (bool) $this->apply_compat_filter( 'show_archive', true ),
			'edit_glances'           => (string) $this->apply_compat_filter( 'edit_glances', DG_EDIT_GLANCES ),
			'admin_glances'          => (string) $this->apply_compat_filter( 'admin_glances', DG_ADMIN_GLANCES ),
		);
	}

	/**
	 * Check user capabilities for editing and managing glances.
	 */
	public function check_user_cap() {
		$this->editable  = current_user_can( $this->options['edit_glances'] );
		$this->adminable = current_user_can( $this->options['admin_glances'] );
	}

	/**
	 * Register and enqueue administrative stylesheets and scripts.
	 *
	 * @param string $hook_suffix Current admin screen hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		// Only load assets on the WordPress Dashboard
		if ( 'index.php' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'dashboard-glance',
			DG_DIR_URL . 'dashboard-glance.css',
			array(),
			DG_VERSION
		);

		wp_enqueue_script(
			'dashboard-glance',
			DG_DIR_URL . 'dashboard-glance.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			DG_VERSION,
			true
		);

		// Localize script data with action-specific CSRF nonces and configuration
		wp_localize_script(
			'dashboard-glance',
			'DashboardGlance',
			array(
				'ajaxurl'          => admin_url( 'admin-ajax.php' ),
				'nonces'           => array(
					'processForm'    => wp_create_nonce( 'dg_process_form_nonce' ),
					'sortGlances'    => wp_create_nonce( 'dg_sort_glances_nonce' ),
					'defaultGlances' => wp_create_nonce( 'dg_default_glances_nonce' ),
					'toggleStatus'   => wp_create_nonce( 'dg_toggle_status_nonce' ),
					'toggleInfo'     => wp_create_nonce( 'dg_toggle_info_nonce' ),
				),
				'nonce'            => wp_create_nonce( 'dashboard_glance_nonce' ), // Fallback for backwards compatibility
				'icons'            => $this->get_dashicons(),
				'statusVisibility' => $this->status_visibility,
				'infoVisibility'   => $this->info_visibility,
				'showSettings'     => $this->options['show_settings'],
				'showAllStatus'    => $this->options['show_all_status'],
				'editable'         => $this->editable,
				'adminable'        => $this->adminable,
				'i18n'             => array(
					'selectItemToAdd'    => __( 'You must select an item to add.', 'dashboard-glance' ),
					'selectItemToRemove' => __( 'You must select an item to remove.', 'dashboard-glance' ),
					'applyPrompt'        => __( 'Apply current glance configuration to:', 'dashboard-glance' ),
					'allUsers'           => __( 'All Existing Users', 'dashboard-glance' ),
					'newUsers'           => __( 'New Users Only', 'dashboard-glance' ),
					'revealActions'      => __( 'Click to Reveal Dashboard Glance Actions', 'dashboard-glance' ),
					'revealStatuses'     => __( 'Click to Reveal More Glance Statuses', 'dashboard-glance' ),
					'hideStatuses'       => __( 'Click to Hide Statuses', 'dashboard-glance' ),
					'filterGlances'      => __( 'Click to Add or Remove Glances', 'dashboard-glance' ),
					'saveDefaults'       => __( 'Save as Default Setup for Users', 'dashboard-glance' ),
					'toggleWpInfo'       => __( 'Toggle WordPress Info', 'dashboard-glance' ),
					'searchIcons'        => __( 'Search icons...', 'dashboard-glance' ),
				),
			)
		);
	}

	/**
	 * Extends capability check for users permitted to view At a Glance widget.
	 */
	public function ensure_at_a_glance_widget() {
		if ( is_blog_admin() && current_user_can( $this->apply_compat_filter( 'view_at_a_glance', 'edit_posts' ) ) ) {
			wp_add_dashboard_widget( 'dashboard_right_now', __( 'At a Glance' ), 'wp_dashboard_right_now' );
		}
	}

	/**
	 * Record date & results of last site health check.
	 *
	 * @param mixed  $value Value of transient.
	 * @param int    $expiration Expiration timestamp.
	 * @param string $transient Transient key.
	 */
	public function record_site_health_status( $value, $expiration, $transient ) {
		update_option( 'dg_health_check_site_status_result', $value, false );
		update_option( 'dg_health_check_site_status_date', time(), false );
	}

	/**
	 * Retrieve glances array for current user (with migration from legacy Glance That).
	 */
	public function get_users_glances() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$this->glances = get_user_meta( $user_id, 'dashboard_glance', true );

		// Check for legacy migration
		if ( empty( $this->glances ) || ! is_array( $this->glances ) ) {
			$legacy = get_user_meta( $user_id, 'glance_that', true );
			if ( ! empty( $legacy ) && is_array( $legacy ) ) {
				$this->glances = $legacy;
				update_user_meta( $user_id, 'dashboard_glance', $this->glances );
			}
		}

		// If user has no glances set, use site default or standard default
		if ( empty( $this->glances ) || ! is_array( $this->glances ) ) {
			$site_default = get_option( 'dashboard_glance_default' );
			if ( false === $site_default ) {
				$site_default = get_option( 'glance_that_default' );
			}

			if ( false === $site_default || ! is_array( $site_default ) ) {
				$default_glances = array(
					'post'    => array( 'icon' => 'f109', 'sorted' => 1 ),
					'page'    => array( 'icon' => 'f105', 'sorted' => 2 ),
					'comment' => array( 'icon' => 'f101', 'sorted' => 3 ),
				);
			} else {
				$default_glances = $site_default;
			}

			$this->glances = $this->apply_compat_filter( 'default_glances', $default_glances, $user_id );
			update_user_meta( $user_id, 'dashboard_glance', $this->glances );
		}

		// Rebuild indexed glances list for sorting reference
		$this->glances_indexed = array();
		foreach ( $this->glances as $glance => $data ) {
			$this->glances_indexed[] = array(
				'glance' => $glance,
				'data'   => $data,
			);
		}
	}

	/**
	 * Retrieve status visibility preference for current user.
	 *
	 * @return bool
	 */
	public function get_user_status_visibility() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		$this->status_visibility = get_user_meta( $user_id, 'dashboard_glance_status_visibility', true );

		// Migration check
		if ( empty( $this->status_visibility ) ) {
			$legacy = get_user_meta( $user_id, 'glance_that_status_visibility', true );
			if ( ! empty( $legacy ) ) {
				$this->status_visibility = $legacy;
				update_user_meta( $user_id, 'dashboard_glance_status_visibility', $this->status_visibility );
			}
		}

		if ( empty( $this->status_visibility ) ) {
			$default = get_option( 'dashboard_glance_status_visibility_default', false );
			if ( false === $default ) {
				$default = get_option( 'glance_that_status_visibility_default', 'visible' );
			}
			$this->status_visibility = $default ? $default : 'visible';
			update_user_meta( $user_id, 'dashboard_glance_status_visibility', $this->status_visibility );
		}

		return ( 'visible' === $this->status_visibility && $this->options['show_all_status'] );
	}

	/**
	 * Retrieve WP info visibility preference for current user.
	 *
	 * @return bool
	 */
	public function get_user_info_visibility() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		$this->info_visibility = get_user_meta( $user_id, 'dashboard_glance_info_visibility', true );

		// Migration check
		if ( empty( $this->info_visibility ) ) {
			$legacy = get_user_meta( $user_id, 'glance_that_info_visibility', true );
			if ( ! empty( $legacy ) ) {
				$this->info_visibility = $legacy;
				update_user_meta( $user_id, 'dashboard_glance_info_visibility', $this->info_visibility );
			}
		}

		if ( empty( $this->info_visibility ) ) {
			$default = get_option( 'dashboard_glance_info_visibility_default', false );
			if ( false === $default ) {
				$default = get_option( 'glance_that_info_visibility_default', 'visible' );
			}
			$this->info_visibility = $default ? $default : 'visible';
			update_user_meta( $user_id, 'dashboard_glance_info_visibility', $this->info_visibility );
		}

		return ( 'visible' === $this->info_visibility );
	}

	/**
	 * Return filtered glance label.
	 *
	 * @param string $item Glance identifier.
	 * @param string $label Default label.
	 * @param int    $count Item count.
	 * @return string
	 */
	public function label( $item, $label, $count ) {
		return esc_html( $this->apply_compat_filter( 'labels', $label, $item, $count ) );
	}

	/**
	 * Custom labels callback.
	 *
	 * @param string $label Current label.
	 * @param string $glance Glance item key.
	 * @param int    $count Count.
	 * @return string
	 */
	public function customize_labels( $label, $glance, $count ) {
		switch ( $glance ) {
			case 'wp_block':
				return ( 1 !== (int) $count ) ? __( 'Patterns & Reusable Blocks', 'dashboard-glance' ) : __( 'Pattern / Reusable Block', 'dashboard-glance' );
			case 'user_request-export_personal_data':
				return ( 1 !== (int) $count ) ? __( 'Data Export Requests', 'dashboard-glance' ) : __( 'Data Export Request', 'dashboard-glance' );
			case 'user_request-remove_personal_data':
				return ( 1 !== (int) $count ) ? __( 'Data Erasure Requests', 'dashboard-glance' ) : __( 'Data Erasure Request', 'dashboard-glance' );
			case 'health-check-site-status':
				return __( 'Site Health', 'dashboard-glance' );
			case 'ph-website':
				return ( 1 !== (int) $count ) ? __( 'PH Sites', 'dashboard-glance' ) : __( 'PH Site', 'dashboard-glance' );
			case 'ph-project':
				return ( 1 !== (int) $count ) ? __( 'PH Mockups', 'dashboard-glance' ) : __( 'PH Mockup', 'dashboard-glance' );
			case 'gravityview':
				return ( 1 !== (int) $count ) ? __( 'Gravity Views', 'dashboard-glance' ) : __( 'Gravity View', 'dashboard-glance' );
			default:
				return $label;
		}
	}

	/**
	 * Build and return the glanced dashboard elements.
	 *
	 * @param array $elements Existing dashboard glance items.
	 * @return array Modified glance elements.
	 */
	public function customize_items( $elements = array() ) {
		if ( ! is_array( $elements ) ) {
			$elements = array();
		}

		foreach ( $elements as $key => $element ) {
			if ( is_string( $element ) && strpos( $element, 'give_forms' ) !== false ) {
				unset( $elements[ $key ] );
			}
		}

		$this->get_users_glances();
		$status_display = ( 'visible' === $this->status_visibility ) ? '' : ' style="display:none;"';

		if ( empty( $this->glances_indexed ) ) {
			return $elements;
		}

		$classes = ( $this->editable || $this->adminable ) ? 'gt-item dg-item gt-editable unordered' : 'gt-item dg-item unordered';

		// Sort items by user sort order
		$order = array();
		foreach ( $this->glances as $item => $data ) {
			$order[ $item ] = isset( $data['sorted'] ) ? (int) $data['sorted'] : 999;
		}
		array_multisort( $order, SORT_ASC, $this->glances );

		foreach ( $this->glances as $glance => $options ) {
			foreach ( $this->glances_indexed as $key => $data ) {
				if ( $glance !== $data['glance'] ) {
					continue;
				}

				$item         = $data['glance'];
				$item_options = $data['data'];
				$icon_code    = isset( $item_options['icon'] ) ? sanitize_text_field( $item_options['icon'] ) : 'f159';
				if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $icon_code ) ) {
					$icon_code = 'f159';
				}

				switch ( $item ) {
					case 'health-check-site-status':
						$raw_result = get_option( 'dg_health_check_site_status_result', false );
						if ( false === $raw_result ) {
							$raw_result = get_option( 'gt_health-check-site-status-result', false );
						}
						$site_status_result = ! empty( $raw_result ) ? json_decode( $raw_result, true ) : false;
						$site_status_date   = get_option( 'dg_health_check_site_status_date', get_option( 'gt_health-check-site-status-date', 0 ) );

						if ( $site_status_result && current_user_can( 'view_site_health_checks' ) ) {
							$good        = isset( $site_status_result['good'] ) ? (int) $site_status_result['good'] : 0;
							$recommended = isset( $site_status_result['recommended'] ) ? (int) $site_status_result['recommended'] : 0;
							$critical    = isset( $site_status_result['critical'] ) ? (int) $site_status_result['critical'] : 0;

							$tests_total  = $good + $recommended + ( $critical * 1.5 );
							$tests_failed = ( $recommended * 0.5 ) + ( $critical * 1.5 );
							$tests_result = ( $tests_total > 0 ) ? ( 100 - ceil( ( $tests_failed / $tests_total ) * 100 ) ) : 100;

							$text = ( $tests_result >= 80 && 0 === $critical ) ? __( 'Good', 'dashboard-glance' ) : __( 'Check Me', 'dashboard-glance' );
							$site_info = '<a href="' . esc_url( admin_url( 'site-health.php?tab=debug' ) ) . '" class="gt-view-info"><span class="dashicons dashicons-info" title="' . esc_attr__( 'View Site Info', 'dashboard-glance' ) . '"></span></a>';

							$statuses = '';
							if ( $this->options['show_all_status'] ) {
								$crit_class = ( $critical > 0 ) ? 'gt-critical gt-moderate' : '';
								$rec_class  = ( $recommended > 0 ) ? 'gt-moderate' : '';
								$statuses   = '<div id="gt-statuses-health-check-site-status" class="gt-statuses"' . $status_display . '>';
								if ( $good > 0 || $this->options['show_zero_count_status'] ) {
									$statuses .= '<div class="gt-status"><a href="' . esc_url( admin_url( 'site-health.php' ) ) . '" class="gt-good" title="' . esc_attr__( 'Good', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $good ) ) . '</a></div>';
								}
								if ( $recommended > 0 || $this->options['show_zero_count_status'] ) {
									$statuses .= '<div class="gt-status ' . esc_attr( $rec_class ) . '"><a href="' . esc_url( admin_url( 'site-health.php' ) ) . '" class="gt-recommended" title="' . esc_attr__( 'Recommended', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $recommended ) ) . '</a></div>';
								}
								if ( $critical > 0 || $this->options['show_zero_count_status'] ) {
									$statuses .= '<div class="gt-status ' . esc_attr( $crit_class ) . '"><a href="' . esc_url( admin_url( 'site-health.php' ) ) . '" class="gt-critical" title="' . esc_attr__( 'Critical', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $critical ) ) . '</a></div>';
								}
								$statuses .= '</div>';
							}

							$date_formatted = $site_status_date ? wp_date( get_option( 'date_format' ), (int) $site_status_date ) : __( 'Never', 'dashboard-glance' );
							$elements[] = sprintf(
								'<div class="%1$s" data-order="gt_%2$d"><style>#dashboard_right_now li a[data-gt="%3$s"]:before{content:\'\\%4$s\';}</style><div class="gt-published"><a data-gt="%3$s" href="%5$s" class="glance-that unordered" title="%6$s">%7$s</a>%8$s</div>%9$s</div>',
								esc_attr( $classes ),
								(int) ( $key + 1 ),
								esc_attr( $item ),
								esc_attr( $icon_code ),
								esc_url( admin_url( 'site-health.php' ) ),
								esc_attr( sprintf( __( 'Site Health (last checked %s)', 'dashboard-glance' ), $date_formatted ) ),
								esc_html( $text ),
								$site_info,
								$statuses
							);
						} elseif ( current_user_can( 'view_site_health_checks' ) ) {
							$text = __( 'Site Health (Never Checked)', 'dashboard-glance' );
							$elements[] = sprintf(
								'<div class="%1$s" data-order="gt_%2$d"><style>#dashboard_right_now li a[data-gt="%3$s"]:before{content:\'\\%4$s\';}</style><div class="gt-published"><a data-gt="%3$s" href="%5$s" class="glance-that unordered" title="%6$s">%6$s</a></div></div>',
								esc_attr( $classes ),
								(int) ( $key + 1 ),
								esc_attr( $item ),
								esc_attr( $icon_code ),
								esc_url( admin_url( 'site-health.php' ) ),
								esc_attr( $text )
							);
						}
						break;

					case 'theme':
						$themes = wp_get_themes();
						$theme_stats = array(
							'all'    => count( $themes ),
							'paused' => 0,
						);

						if ( function_exists( 'wp_is_recovery_mode' ) && wp_is_recovery_mode() && function_exists( 'wp_paused_themes' ) ) {
							$theme_stats['paused'] = count( wp_paused_themes()->get_all() );
						}

						if ( $theme_stats['all'] > 0 && current_user_can( 'switch_themes' ) ) {
							$label_text = ( 1 === $theme_stats['all'] ) ? $this->label( $item, __( 'Theme', 'dashboard-glance' ), 1 ) : $this->label( $item, __( 'Themes', 'dashboard-glance' ), $theme_stats['all'] );
							$text       = sprintf( '%s %s', number_format_i18n( $theme_stats['all'] ), $label_text );

							$new_theme = '';
							if ( current_user_can( 'install_themes' ) && $this->options['show_add_new'] ) {
								$new_theme = '<a href="' . esc_url( admin_url( 'theme-install.php' ) ) . '" class="gt-add-new"><span class="dashicons dashicons-plus" title="' . esc_attr( sprintf( __( 'Add New %s', 'dashboard-glance' ), $this->label( $item, __( 'Theme', 'dashboard-glance' ), 1 ) ) ) . '"></span></a>';
							}

							$statuses = '';
							if ( $this->options['show_all_status'] ) {
								$theme_updates = function_exists( 'get_theme_updates' ) ? count( get_theme_updates() ) : 0;
								$moderation    = ( $theme_updates > 0 ) ? 'gt-moderate' : '';
								$statuses      = '<div class="gt-statuses"' . $status_display . '>';
								if ( $theme_updates > 0 || $this->options['show_zero_count_status'] ) {
									$statuses .= '<div class="gt-status ' . esc_attr( $moderation ) . '"><a href="' . esc_url( admin_url( 'update-core.php#update-themes-table' ) ) . '" class="gt-update" title="' . esc_attr__( 'Update Available', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $theme_updates ) ) . '</a></div>';
								}
								if ( $theme_stats['paused'] > 0 ) {
									$statuses .= '<div class="gt-status gt-moderate"><a href="' . esc_url( admin_url( 'themes.php' ) ) . '" class="gt-paused" title="' . esc_attr__( 'Paused', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $theme_stats['paused'] ) ) . '</a></div>';
								}
								$statuses .= '</div>';
							}

							$elements[] = sprintf(
								'<div class="%1$s" data-order="gt_%2$d"><style>#dashboard_right_now li a[data-gt="%3$s"]:before{content:\'\\%4$s\';}</style><a data-gt="%3$s" href="%5$s" class="glance-that" title="%6$s">%7$s</a>%8$s%9$s</div>',
								esc_attr( $classes ),
								(int) ( $key + 1 ),
								esc_attr( $item ),
								esc_attr( $icon_code ),
								esc_url( admin_url( 'themes.php' ) ),
								esc_attr( sprintf( __( 'All %s', 'dashboard-glance' ), $this->label( $item, __( 'Themes', 'dashboard-glance' ), 2 ) ) ),
								esc_html( $text ),
								$new_theme,
								$statuses
							);
						}
						break;

					case 'revision':
						$rev_count = wp_count_posts( 'revision' );
						$num_rev   = ( $rev_count && isset( $rev_count->inherit ) ) ? (int) $rev_count->inherit : 0;
						$post_type_obj = get_post_type_object( 'revision' );

						if ( $num_rev > 0 && $post_type_obj && current_user_can( $post_type_obj->cap->edit_posts ) ) {
							$label_text = ( 1 === $num_rev ) ? $this->label( $item, $post_type_obj->labels->singular_name, 1 ) : $this->label( $item, $post_type_obj->labels->name, $num_rev );
							$text       = sprintf( '%s %s', number_format_i18n( $num_rev ), $label_text );

							$elements[] = sprintf(
								'<div class="%1$s" data-order="gt_%2$d"><style>#dashboard_right_now li a[data-gt="%3$s"]:before{content:\'\\%4$s\';}</style><a data-gt="%3$s" href="#" class="glance-that" style="pointer-events:none;color:#444;">%5$s</a><div class="gt-statuses"%6$s></div></div>',
								esc_attr( $classes ),
								(int) ( $key + 1 ),
								esc_attr( $item ),
								esc_attr( $icon_code ),
								esc_html( $text ),
								$status_display
							);
						}
						break;

					case 'attachment':
						$post_type_obj = get_post_type_object( 'attachment' );
						if ( ! $post_type_obj || ! current_user_can( $post_type_obj->cap->edit_posts ) ) {
							break;
						}

						$num_posts    = wp_count_posts( 'attachment' );
						$inherit_num  = ( $num_posts && isset( $num_posts->inherit ) ) ? (int) $num_posts->inherit : 0;

						// Unattached media count via direct query for speed
						global $wpdb;
						$unattached = (int) $wpdb->get_var( "SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit' AND post_parent = 0" );

						if ( $inherit_num > 0 || $this->options['show_zero_count'] ) {
							$label_text = ( 1 === $inherit_num ) ? $this->label( $item, $post_type_obj->labels->singular_name, 1 ) : $this->label( $item, $post_type_obj->labels->name, $inherit_num );
							$text       = sprintf( '%s %s', number_format_i18n( $inherit_num ), $label_text );

							$new_attachment = '';
							if ( current_user_can( 'upload_files' ) && $this->options['show_add_new'] ) {
								$new_attachment = '<a href="' . esc_url( admin_url( 'media-new.php' ) ) . '" class="gt-add-new"><span class="dashicons dashicons-plus" title="' . esc_attr( sprintf( __( 'Add New %s', 'dashboard-glance' ), $this->label( $item, $post_type_obj->labels->singular_name, 1 ) ) ) . '"></span></a>';
							}

							$statuses = '';
							if ( $this->options['show_all_status'] ) {
								$statuses = '<div class="gt-statuses"' . $status_display . '>';
								if ( $unattached > 0 || $this->options['show_zero_count_status'] ) {
									$statuses .= '<div class="gt-status"><a href="' . esc_url( admin_url( 'upload.php?detached=1' ) ) . '" class="gt-unattached" title="' . esc_attr( sprintf( __( 'Unattached %s', 'dashboard-glance' ), $this->label( $item, $post_type_obj->labels->name, $unattached ) ) ) . '">' . esc_html( number_format_i18n( $unattached ) ) . '</a></div>';
								}
								$statuses .= '</div>';
							}

							$elements[] = sprintf(
								'<div class="%1$s" data-order="gt_%2$d"><style>#dashboard_right_now li a[data-gt="%3$s"]:before{content:\'\\%4$s\';}</style><a data-gt="%3$s" href="%5$s" class="glance-that" title="%6$s">%7$s</a>%8$s%9$s</div>',
								esc_attr( $classes ),
								(int) ( $key + 1 ),
								esc_attr( $item ),
								esc_attr( $icon_code ),
								esc_url( admin_url( 'upload.php' ) ),
								esc_attr( sprintf( __( 'All %s', 'dashboard-glance' ), $this->label( $item, $post_type_obj->labels->name, 2 ) ) ),
								esc_html( $text ),
								$statuses,
								$new_attachment
							);
						}
						break;

					case 'user_request-export_personal_data':
					case 'user_request-remove_personal_data':
						$request_type = str_replace( 'user_request-', '', $item );
						if ( ! current_user_can( 'manage_options' ) ) {
							break;
						}

						global $wpdb;
						$query    = "SELECT post_status, COUNT(*) AS num_posts FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s GROUP BY post_status";
						$requests = $wpdb->get_results( $wpdb->prepare( $query, 'user_request', $request_type ), ARRAY_A );

						$num_requests = array_fill_keys( get_post_stati(), 0 );
						if ( is_array( $requests ) ) {
							foreach ( $requests as $row ) {
								$num_requests[ $row['post_status'] ] = (int) $row['num_posts'];
							}
						}

						$total_active = $num_requests['request-pending'] + $num_requests['request-confirmed'];
						if ( $total_active > 0 || $this->options['show_zero_count'] ) {
							$label_text = $this->label( $item, $request_type, $total_active );
							$text       = sprintf( '%s %s', number_format_i18n( $total_active ), $label_text );

							$new_post = '';
							if ( $this->options['show_add_new'] ) {
								$new_post = '<a href="' . esc_url( admin_url( 'tools.php?page=' . $request_type ) ) . '" class="gt-add-new"><span class="dashicons dashicons-plus" title="' . esc_attr( sprintf( __( 'Add New %s', 'dashboard-glance' ), $this->label( $item, $request_type, 1 ) ) ) . '"></span></a>';
							}

							$statuses = '';
							if ( $this->options['show_all_status'] ) {
								$statuses = '<div class="gt-statuses"' . $status_display . '>';
								if ( $num_requests['request-pending'] > 0 || $this->options['show_zero_count_status'] ) {
									$statuses .= '<div class="gt-status"><a href="' . esc_url( admin_url( 'tools.php?page=' . $request_type . '&filter-status=request-pending' ) ) . '" class="gt-pending" title="' . esc_attr__( 'Pending', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $num_requests['request-pending'] ) ) . '</a></div>';
								}
								if ( $num_requests['request-confirmed'] > 0 || $this->options['show_zero_count_status'] ) {
									$mod_class = ( $num_requests['request-confirmed'] > 0 ) ? 'gt-moderate' : '';
									$statuses .= '<div class="gt-status ' . esc_attr( $mod_class ) . '"><a href="' . esc_url( admin_url( 'tools.php?page=' . $request_type . '&filter-status=request-confirmed' ) ) . '" class="gt-confirmed" title="' . esc_attr__( 'Confirmed', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $num_requests['request-confirmed'] ) ) . '</a></div>';
								}
								if ( $num_requests['request-failed'] > 0 || $this->options['show_zero_count_status'] ) {
									$statuses .= '<div class="gt-status"><a href="' . esc_url( admin_url( 'tools.php?page=' . $request_type . '&filter-status=request-failed' ) ) . '" class="gt-failed" title="' . esc_attr__( 'Failed', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $num_requests['request-failed'] ) ) . '</a></div>';
								}
								if ( $num_requests['request-completed'] > 0 || $this->options['show_zero_count_status'] ) {
									$statuses .= '<div class="gt-status"><a href="' . esc_url( admin_url( 'tools.php?page=' . $request_type . '&filter-status=request-completed' ) ) . '" class="gt-completed" title="' . esc_attr__( 'Completed', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $num_requests['request-completed'] ) ) . '</a></div>';
								}
								$statuses .= '</div>';
							}

							$elements[] = sprintf(
								'<div class="%1$s" data-order="gt_%2$d" data-glance="%3$s"><style>#dashboard_right_now li a[data-gt="%4$s"]:before{content:\'\\%5$s\';}</style><div class="gt-published"><a data-gt="%4$s" href="%6$s" class="glance-that" title="%7$s">%8$s</a>%9$s</div>%10$s</div>',
								esc_attr( $classes ),
								(int) ( $key + 1 ),
								esc_attr( $item ),
								esc_attr( $request_type ),
								esc_attr( $icon_code ),
								esc_url( admin_url( 'tools.php?page=' . $request_type ) ),
								esc_attr( sprintf( __( 'All %s', 'dashboard-glance' ), $this->label( $item, $request_type, 2 ) ) ),
								esc_html( $text ),
								$new_post,
								$statuses
							);
						}
						break;

					case 'comment':
						$num_comments = wp_count_comments();
						$approved     = ( $num_comments && isset( $num_comments->approved ) ) ? (int) $num_comments->approved : 0;
						$moderated    = ( $num_comments && isset( $num_comments->moderated ) ) ? (int) $num_comments->moderated : 0;
						$spam         = ( $num_comments && isset( $num_comments->spam ) ) ? (int) $num_comments->spam : 0;
						$trash        = ( $num_comments && isset( $num_comments->trash ) ) ? (int) $num_comments->trash : 0;

						if ( ( $approved > 0 || $this->options['show_zero_count'] ) && current_user_can( 'moderate_comments' ) ) {
							$label_text = ( 1 === $approved ) ? $this->label( $item, __( 'Comment', 'dashboard-glance' ), 1 ) : $this->label( $item, __( 'Comments', 'dashboard-glance' ), $approved );
							$text       = sprintf( '%s %s', number_format_i18n( $approved ), $label_text );

							$statuses = '';
							if ( $this->options['show_all_status'] ) {
								$mod_class = ( $moderated > 0 ) ? 'gt-moderate' : '';
								$statuses  = '<div id="gt-statuses-comments" class="gt-statuses"' . $status_display . '>';
								if ( $moderated > 0 || $this->options['show_zero_count_status'] ) {
									$statuses .= '<div class="gt-status ' . esc_attr( $mod_class ) . '"><a href="' . esc_url( admin_url( 'edit-comments.php?comment_status=moderated' ) ) . '" class="gt-pending" title="' . esc_attr__( 'Pending', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $moderated ) ) . '</a></div>';
								}
								if ( $spam > 0 || $this->options['show_zero_count_status'] ) {
									$statuses .= '<div class="gt-status"><a href="' . esc_url( admin_url( 'edit-comments.php?comment_status=spam' ) ) . '" class="gt-spam" title="' . esc_attr__( 'Spam', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $spam ) ) . '</a></div>';
								}
								if ( $trash > 0 || $this->options['show_zero_count_status'] ) {
									$statuses .= '<div class="gt-status"><a href="' . esc_url( admin_url( 'edit-comments.php?comment_status=trash' ) ) . '" class="gt-trash" title="' . esc_attr__( 'Trash', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $trash ) ) . '</a></div>';
								}
								$statuses .= '</div>';
							}

							$elements[] = sprintf(
								'<div class="%1$s" data-order="gt_%2$d"><style>#dashboard_right_now li a[data-gt="%3$s"]:before{content:\'\\%4$s\';}</style><div class="gt-published"><a data-gt="%3$s" href="%5$s" class="glance-that unordered" title="%6$s">%7$s</a></div>%8$s</div>',
								esc_attr( $classes ),
								(int) ( $key + 1 ),
								esc_attr( $item ),
								esc_attr( $icon_code ),
								esc_url( admin_url( 'edit-comments.php' ) ),
								esc_attr( sprintf( __( 'All %s', 'dashboard-glance' ), $this->label( $item, __( 'Comments', 'dashboard-glance' ), 2 ) ) ),
								esc_html( $text ),
								$statuses
							);
						}
						break;

					case 'plugin':
						if ( ! function_exists( 'get_plugins' ) ) {
							require_once ABSPATH . 'wp-admin/includes/plugin.php';
						}

						$plugins      = get_plugins();
						$plugin_stats = array(
							'all'       => count( $plugins ),
							'active'    => 0,
							'inactive'  => 0,
							'update'    => count( get_plugin_updates() ),
							'mustuse'   => 0,
							'dropins'   => 0,
							'recent'    => count( (array) get_site_option( 'recently_activated', array() ) ),
							'favorites' => 0,
							'paused'    => 0,
						);

						foreach ( $plugins as $plugin_file => $data ) {
							if ( is_plugin_active( $plugin_file ) ) {
								$plugin_stats['active']++;
							}
						}
						$plugin_stats['inactive'] = $plugin_stats['all'] - $plugin_stats['active'];

						if ( $this->options['show_mustuse'] ) {
							$plugin_stats['mustuse'] = count( get_mu_plugins() );
						}
						if ( $this->options['show_dropins'] ) {
							$plugin_stats['dropins'] = count( get_dropins() );
						}

						// Paused plugins in recovery mode
						if ( function_exists( 'wp_is_recovery_mode' ) && wp_is_recovery_mode() && function_exists( 'wp_paused_plugins' ) ) {
							$plugin_stats['paused'] = count( wp_paused_plugins()->get_all() );
						}

						// Safe check for wporg favorites with transient caching
						$wporg_user = get_user_option( 'wporg_favorites' );
						if ( ! empty( $wporg_user ) ) {
							$cache_key = 'dg_favs_' . md5( $wporg_user );
							$favs_count = get_transient( $cache_key );
							if ( false === $favs_count ) {
								require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
								$api = plugins_api( 'query_plugins', array( 'user' => $wporg_user ) );
								if ( ! is_wp_error( $api ) && isset( $api->plugins ) && is_array( $api->plugins ) ) {
									$favs_count = count( $api->plugins );
								} else {
									$favs_count = 0;
								}
								set_transient( $cache_key, $favs_count, HOUR_IN_SECONDS * 6 );
							}
							$plugin_stats['favorites'] = (int) $favs_count;
						}

						$new_plugin = '';
						if ( current_user_can( 'install_plugins' ) && $this->options['show_add_new'] ) {
							$new_plugin = '<a href="' . esc_url( admin_url( 'plugin-install.php' ) ) . '" class="gt-add-new"><span class="dashicons dashicons-plus" title="' . esc_attr( sprintf( __( 'Add New %s', 'dashboard-glance' ), $this->label( $item, __( 'Plugin', 'dashboard-glance' ), 1 ) ) ) . '"></span></a>';
						}

						if ( ( $plugin_stats['all'] > 0 || $this->options['show_zero_count'] ) && current_user_can( 'activate_plugins' ) ) {
							$label_text = ( 1 === $plugin_stats['all'] ) ? $this->label( $item, __( 'Plugin', 'dashboard-glance' ), 1 ) : $this->label( $item, __( 'Plugins', 'dashboard-glance' ), $plugin_stats['all'] );
							$text       = sprintf( '%s %s', number_format_i18n( $plugin_stats['all'] ), $label_text );

							$statuses = '';
							if ( $this->options['show_all_status'] ) {
								$statuses = '<div class="gt-statuses"' . $status_display . '>';
								if ( $plugin_stats['active'] > 0 || $this->options['show_zero_count_status'] ) {
									$statuses .= '<div class="gt-status"><a href="' . esc_url( admin_url( 'plugins.php?plugin_status=active' ) ) . '" class="gt-active" title="' . esc_attr__( 'Active', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $plugin_stats['active'] ) ) . '</a></div>';
								}
								if ( $plugin_stats['inactive'] > 0 || $this->options['show_zero_count_status'] ) {
									$statuses .= '<div class="gt-status"><a href="' . esc_url( admin_url( 'plugins.php?plugin_status=inactive' ) ) . '" class="gt-inactive" title="' . esc_attr__( 'Inactive', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $plugin_stats['inactive'] ) ) . '</a></div>';
								}
								if ( $plugin_stats['recent'] > 0 || $this->options['show_zero_count_status'] ) {
									$statuses .= '<div class="gt-status"><a href="' . esc_url( admin_url( 'plugins.php?plugin_status=recently_activated' ) ) . '" class="gt-recent" title="' . esc_attr__( 'Recently Active', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $plugin_stats['recent'] ) ) . '</a></div>';
								}
								if ( $plugin_stats['update'] > 0 || $this->options['show_zero_count_status'] ) {
									$mod_class = ( $plugin_stats['update'] > 0 ) ? 'gt-moderate' : '';
									$statuses .= '<div class="gt-status ' . esc_attr( $mod_class ) . '"><a href="' . esc_url( admin_url( 'plugins.php?plugin_status=upgrade' ) ) . '" class="gt-update" title="' . esc_attr__( 'Update Available', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $plugin_stats['update'] ) ) . '</a></div>';
								}
								if ( ! empty( $wporg_user ) && ( $plugin_stats['favorites'] > 0 || $this->options['show_zero_count_status'] ) ) {
									$statuses .= '<div class="gt-status"><a href="' . esc_url( admin_url( 'plugin-install.php?tab=favorites' ) ) . '" class="gt-favorites" title="' . esc_attr( sprintf( __( 'Favorites: %s', 'dashboard-glance' ), $wporg_user ) ) . '">' . esc_html( number_format_i18n( $plugin_stats['favorites'] ) ) . '</a></div>';
								}
								if ( $plugin_stats['mustuse'] > 0 && $this->options['show_mustuse'] ) {
									$statuses .= '<div class="gt-status"><a href="' . esc_url( admin_url( 'plugins.php?plugin_status=mustuse' ) ) . '" class="gt-mustuse" title="' . esc_attr__( 'Must-Use', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $plugin_stats['mustuse'] ) ) . '</a></div>';
								}
								if ( $plugin_stats['dropins'] > 0 && $this->options['show_dropins'] ) {
									$statuses .= '<div class="gt-status"><a href="' . esc_url( admin_url( 'plugins.php?plugin_status=dropins' ) ) . '" class="gt-dropins" title="' . esc_attr__( 'Drop-ins', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $plugin_stats['dropins'] ) ) . '</a></div>';
								}
								if ( $plugin_stats['paused'] > 0 ) {
									$statuses .= '<div class="gt-status gt-moderate"><a href="' . esc_url( admin_url( 'plugins.php?plugin_status=paused' ) ) . '" class="gt-paused" title="' . esc_attr__( 'Paused', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $plugin_stats['paused'] ) ) . '</a></div>';
								}
								$statuses .= '</div>';
							}

							$elements[] = sprintf(
								'<div class="%1$s" data-order="gt_%2$d"><style>#dashboard_right_now li a[data-gt="%3$s"]:before{content:\'\\%4$s\';}</style><div class="gt-published"><a data-gt="%3$s" href="%5$s" class="glance-that" title="%6$s">%7$s</a>%8$s</div>%9$s</div>',
								esc_attr( $classes ),
								(int) ( $key + 1 ),
								esc_attr( $item ),
								esc_attr( $icon_code ),
								esc_url( admin_url( 'plugins.php' ) ),
								esc_attr( sprintf( __( 'All %s', 'dashboard-glance' ), $this->label( $item, __( 'Plugins', 'dashboard-glance' ), 2 ) ) ),
								esc_html( $text ),
								$new_plugin,
								$statuses
							);
						}
						break;

					case 'user':
						$num_users   = count_users();
						$total_users = isset( $num_users['total_users'] ) ? (int) $num_users['total_users'] : 0;

						$new_user = '';
						if ( current_user_can( 'create_users' ) && $this->options['show_add_new'] ) {
							$new_user = '<a href="' . esc_url( admin_url( 'user-new.php' ) ) . '" class="gt-add-new"><span class="dashicons dashicons-plus" title="' . esc_attr( sprintf( __( 'Add New %s', 'dashboard-glance' ), $this->label( $item, __( 'User', 'dashboard-glance' ), 1 ) ) ) . '"></span></a>';
						}

						if ( current_user_can( 'list_users' ) ) {
							$label_text = ( 1 === $total_users ) ? $this->label( $item, __( 'User', 'dashboard-glance' ), 1 ) : $this->label( $item, __( 'Users', 'dashboard-glance' ), $total_users );
							$text       = sprintf( '%s %s', number_format_i18n( $total_users ), $label_text );

							$elements[] = sprintf(
								'<div class="%1$s" data-order="gt_%2$d"><style>#dashboard_right_now li a[data-gt="user"]:before{content:\'\\%3$s\';}</style><a data-gt="user" href="%4$s" class="glance-that" title="%5$s">%6$s</a>%7$s</div>',
								esc_attr( $classes ),
								(int) ( $key + 1 ),
								esc_attr( $icon_code ),
								esc_url( admin_url( 'users.php' ) ),
								esc_attr( sprintf( __( 'All %s', 'dashboard-glance' ), $this->label( $item, __( 'Users', 'dashboard-glance' ), 2 ) ) ),
								esc_html( $text ),
								$new_user
							);
						}
						break;

					case 'gravityform':
						if ( class_exists( 'RGFormsModel' ) ) {
							$num_forms   = RGFormsModel::get_form_count();
							$total_forms = isset( $num_forms['total'] ) ? (int) $num_forms['total'] : 0;

							if ( ( $total_forms > 0 || $this->options['show_zero_count'] ) && ( current_user_can( 'gform_full_access' ) || current_user_can( 'gravityforms_edit_forms' ) ) ) {
								$label_text = ( 1 === $total_forms ) ? $this->label( $item, __( 'Form', 'dashboard-glance' ), 1 ) : $this->label( $item, __( 'Forms', 'dashboard-glance' ), $total_forms );
								$text       = sprintf( '%s %s', number_format_i18n( $total_forms ), $label_text );

								$new_form = '';
								if ( ( current_user_can( 'gravityforms_create_form' ) || current_user_can( 'update_core' ) ) && $this->options['show_add_new'] ) {
									$new_form = '<a href="' . esc_url( admin_url( 'admin.php?page=gf_new_form' ) ) . '" class="gt-add-new"><span class="dashicons dashicons-plus" title="' . esc_attr( sprintf( __( 'Add New %s', 'dashboard-glance' ), $this->label( $item, __( 'Form', 'dashboard-glance' ), 1 ) ) ) . '"></span></a>';
								}

								$statuses = '';
								if ( $this->options['show_all_status'] ) {
									$active   = isset( $num_forms['active'] ) ? (int) $num_forms['active'] : 0;
									$inactive = isset( $num_forms['inactive'] ) ? (int) $num_forms['inactive'] : 0;
									$trash    = isset( $num_forms['trash'] ) ? (int) $num_forms['trash'] : 0;
									$statuses = '<div class="gt-statuses"' . $status_display . '>';
									if ( $active > 0 || $this->options['show_zero_count_status'] ) {
										$statuses .= '<div class="gt-status"><a href="' . esc_url( admin_url( 'admin.php?page=gf_edit_forms&filter=active' ) ) . '" class="gt-active" title="' . esc_attr__( 'Active Forms', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $active ) ) . '</a></div>';
									}
									if ( $inactive > 0 || $this->options['show_zero_count_status'] ) {
										$statuses .= '<div class="gt-status"><a href="' . esc_url( admin_url( 'admin.php?page=gf_edit_forms&filter=inactive' ) ) . '" class="gt-inactive" title="' . esc_attr__( 'Inactive Forms', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $inactive ) ) . '</a></div>';
									}
									if ( $trash > 0 || $this->options['show_zero_count_status'] ) {
										$statuses .= '<div class="gt-status"><a href="' . esc_url( admin_url( 'admin.php?page=gf_edit_forms&filter=trash' ) ) . '" class="gt-trash" title="' . esc_attr__( 'Trash', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $trash ) ) . '</a></div>';
									}
									$statuses .= '</div>';
								}

								$elements[] = sprintf(
									'<div class="%1$s" data-order="gt_%2$d"><div class="gt-published"><a data-gt="%3$s" href="%4$s" class="glance-that unordered" title="%5$s">%6$s</a>%7$s</div>%8$s</div>',
									esc_attr( $classes ),
									(int) ( $key + 1 ),
									esc_attr( $item ),
									esc_url( admin_url( 'admin.php?page=gf_edit_forms' ) ),
									esc_attr( sprintf( __( 'All %s', 'dashboard-glance' ), $this->label( $item, __( 'Forms', 'dashboard-glance' ), 2 ) ) ),
									esc_html( $text ),
									$new_form,
									$statuses
								);
							}
						}
						break;

					case 'formidableform':
						if ( class_exists( 'FrmForm' ) ) {
							$num_forms = FrmForm::get_count();
							$published = isset( $num_forms->published ) ? (int) $num_forms->published : 0;

							if ( ( $published > 0 || $this->options['show_zero_count'] ) && ( current_user_can( 'frm_view_forms' ) || current_user_can( 'frm_edit_forms' ) ) ) {
								$label_text = ( 1 === $published ) ? $this->label( $item, __( 'Form', 'dashboard-glance' ), 1 ) : $this->label( $item, __( 'Forms', 'dashboard-glance' ), $published );
								$text       = sprintf( '%s %s', number_format_i18n( $published ), $label_text );

								$new_form = '';
								if ( current_user_can( 'frm_edit_forms' ) && $this->options['show_add_new'] ) {
									$new_form = '<a href="' . esc_url( admin_url( 'admin.php?page=formidable&frm_action=new' ) ) . '" class="gt-add-new"><span class="dashicons dashicons-plus" title="' . esc_attr( sprintf( __( 'Add New %s', 'dashboard-glance' ), $this->label( $item, __( 'Form', 'dashboard-glance' ), 1 ) ) ) . '"></span></a>';
								}

								$statuses = '';
								if ( $this->options['show_all_status'] ) {
									$template = isset( $num_forms->template ) ? (int) $num_forms->template : 0;
									$draft    = isset( $num_forms->draft ) ? (int) $num_forms->draft : 0;
									$trash    = isset( $num_forms->trash ) ? (int) $num_forms->trash : 0;
									$statuses = '<div class="gt-statuses"' . $status_display . '>';
									if ( $template > 0 || $this->options['show_zero_count_status'] ) {
										$statuses .= '<div class="gt-status"><a href="' . esc_url( admin_url( 'admin.php?page=formidable&form_type=template' ) ) . '" class="gt-template" title="' . esc_attr__( 'Form Templates', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $template ) ) . '</a></div>';
									}
									if ( $draft > 0 || $this->options['show_zero_count_status'] ) {
										$statuses .= '<div class="gt-status"><a href="' . esc_url( admin_url( 'admin.php?page=formidable&form_type=draft' ) ) . '" class="gt-draft" title="' . esc_attr__( 'Drafts', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $draft ) ) . '</a></div>';
									}
									if ( $trash > 0 || $this->options['show_zero_count_status'] ) {
										$statuses .= '<div class="gt-status"><a href="' . esc_url( admin_url( 'admin.php?page=formidable&form_type=trash' ) ) . '" class="gt-trash" title="' . esc_attr__( 'Trash', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $trash ) ) . '</a></div>';
									}
									$statuses .= '</div>';
								}

								$elements[] = sprintf(
									'<div class="%1$s" data-order="gt_%2$d"><div class="gt-published"><a data-gt="%3$s" href="%4$s" class="glance-that unordered" title="%5$s">%6$s</a>%7$s</div>%8$s</div>',
									esc_attr( $classes ),
									(int) ( $key + 1 ),
									esc_attr( $item ),
									esc_url( admin_url( 'admin.php?page=formidable' ) ),
									esc_attr( sprintf( __( 'All %s', 'dashboard-glance' ), $this->label( $item, __( 'Forms', 'dashboard-glance' ), 2 ) ) ),
									esc_html( $text ),
									$new_form,
									$statuses
								);
							}
						}
						break;

					case 'ninjaform':
						if ( function_exists( 'Ninja_Forms' ) ) {
							$forms     = Ninja_Forms()->form()->get_forms();
							$num_forms = is_array( $forms ) ? count( $forms ) : 0;
							$cap       = apply_filters( 'ninja_forms_admin_parent_menu_capabilities', 'manage_options' );

							if ( ( $num_forms > 0 || $this->options['show_zero_count'] ) && current_user_can( $cap ) ) {
								$label_text = ( 1 === $num_forms ) ? $this->label( $item, __( 'Form', 'dashboard-glance' ), 1 ) : $this->label( $item, __( 'Forms', 'dashboard-glance' ), $num_forms );
								$text       = sprintf( '%s %s', number_format_i18n( $num_forms ), $label_text );

								$new_form = '';
								if ( $this->options['show_add_new'] ) {
									$new_form = '<a href="' . esc_url( admin_url( 'admin.php?page=ninja-forms#new-form' ) ) . '" class="gt-add-new"><span class="dashicons dashicons-plus" title="' . esc_attr( sprintf( __( 'Add New %s', 'dashboard-glance' ), $this->label( $item, __( 'Form', 'dashboard-glance' ), 1 ) ) ) . '"></span></a>';
								}

								$elements[] = sprintf(
									'<div class="%1$s" data-order="gt_%2$d"><style>#dashboard_right_now li a[data-gt="%3$s"]:before{content:\'\\%4$s\';}</style><div class="gt-published"><a data-gt="%3$s" href="%5$s" class="glance-that unordered" title="%6$s">%7$s</a>%8$s</div></div>',
									esc_attr( $classes ),
									(int) ( $key + 1 ),
									esc_attr( $item ),
									esc_attr( $icon_code ),
									esc_url( admin_url( 'admin.php?page=ninja-forms' ) ),
									esc_attr( sprintf( __( 'All %s', 'dashboard-glance' ), $this->label( $item, __( 'Forms', 'dashboard-glance' ), 2 ) ) ),
									esc_html( $text ),
									$new_form
								);
							}
						}
						break;

					default:
						// Generic Custom Post Type handling
						if ( post_type_exists( $item ) ) {
							$pt_object = get_post_type_object( $item );
							if ( ! $pt_object || ! current_user_can( $pt_object->cap->edit_posts ) ) {
								break;
							}

							$num_posts = wp_count_posts( $item );
							$publish   = ( $num_posts && isset( $num_posts->publish ) ) ? (int) $num_posts->publish : 0;

							if ( $publish > 0 || $this->options['show_zero_count'] ) {
								$label_text = ( 1 === $publish ) ? $this->label( $item, $pt_object->labels->singular_name, 1 ) : $this->label( $item, $pt_object->labels->name, $publish );
								$text       = sprintf( '%s %s', number_format_i18n( $publish ), $label_text );

								$new_post = '';
								if ( $this->options['show_add_new'] ) {
									$new_post = '<a href="' . esc_url( admin_url( 'post-new.php?post_type=' . $item ) ) . '" class="gt-add-new"><span class="dashicons dashicons-plus" title="' . esc_attr( sprintf( __( 'Add New %s', 'dashboard-glance' ), $this->label( $item, $pt_object->labels->singular_name, 1 ) ) ) . '"></span></a>';
								}

								$archive = '';
								$archive_link = get_post_type_archive_link( $item );
								if ( $archive_link && $this->options['show_archive'] ) {
									$archive = '<a href="' . esc_url( $archive_link ) . '" class="gt-view-archive"><span class="dashicons dashicons-external" title="' . esc_attr( sprintf( __( 'View %s Archive', 'dashboard-glance' ), $this->label( $item, $pt_object->labels->singular_name, 1 ) ) ) . '"></span></a>';
								}

								$statuses = '';
								if ( $this->options['show_all_status'] ) {
									$statuses = '<div class="gt-statuses"' . $status_display . '>';

									// User mine count
									$author = get_current_user_id();
									$user_num_posts = 0;
									if ( $this->options['show_mine'] ) {
										global $wpdb;
										$exclude_states = get_post_stati( array( 'show_in_admin_all_list' => false ) );
										if ( empty( $exclude_states ) ) {
											$user_num_posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type = %s AND post_author = %d", $item, $author ) );
										} else {
											$in_clause = implode( ', ', array_fill( 0, count( $exclude_states ), '%s' ) );
											$query     = "SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type = %s AND post_author = %d AND post_status NOT IN ({$in_clause})";
											$params    = array_merge( array( $query, $item, $author ), array_values( $exclude_states ) );
											$user_num_posts = (int) $wpdb->get_var( call_user_func_array( array( $wpdb, 'prepare' ), $params ) );
										}

										if ( $user_num_posts > 0 || $this->options['show_zero_count_status'] ) {
											$statuses .= '<div class="gt-status"><a href="' . esc_url( admin_url( 'edit.php?post_type=' . $item . '&author=' . $author ) ) . '" class="gt-mine" title="' . esc_attr__( 'Mine', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $user_num_posts ) ) . '</a></div>';
										}
									}

									$future  = ( $num_posts && isset( $num_posts->future ) ) ? (int) $num_posts->future : 0;
									$pending = ( $num_posts && isset( $num_posts->pending ) ) ? (int) $num_posts->pending : 0;
									$draft   = ( $num_posts && isset( $num_posts->draft ) ) ? (int) $num_posts->draft : 0;
									$private = ( $num_posts && isset( $num_posts->private ) ) ? (int) $num_posts->private : 0;
									$trash   = ( $num_posts && isset( $num_posts->trash ) ) ? (int) $num_posts->trash : 0;

									if ( current_user_can( $pt_object->cap->publish_posts ) && ( $future > 0 || $this->options['show_zero_count_status'] ) ) {
										$statuses .= '<div class="gt-status"><a href="' . esc_url( admin_url( 'edit.php?post_type=' . $item . '&post_status=future' ) ) . '" class="gt-future" title="' . esc_attr__( 'Scheduled', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $future ) ) . '</a></div>';
									}

									if ( current_user_can( $pt_object->cap->edit_posts ) && ( $pending > 0 || $this->options['show_zero_count_status'] ) ) {
										$mod_class = ( $pending > 0 ) ? 'gt-moderate' : '';
										$statuses .= '<div class="gt-status ' . esc_attr( $mod_class ) . '"><a href="' . esc_url( admin_url( 'edit.php?post_type=' . $item . '&post_status=pending' ) ) . '" class="gt-pending" title="' . esc_attr__( 'Pending', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $pending ) ) . '</a></div>';
									}

									// FIXED: Parenthesis logic error from original codebase
									if ( current_user_can( $pt_object->cap->edit_posts ) && ( $draft > 0 || $this->options['show_zero_count_status'] ) ) {
										$statuses .= '<div class="gt-status"><a href="' . esc_url( admin_url( 'edit.php?post_type=' . $item . '&post_status=draft' ) ) . '" class="gt-draft" title="' . esc_attr__( 'Drafts', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $draft ) ) . '</a></div>';
									}

									$can_private = ! empty( $pt_object->cap->edit_private_posts ) ? current_user_can( $pt_object->cap->edit_private_posts ) : current_user_can( 'edit_private_posts' );
									if ( $can_private && ( $private > 0 || $this->options['show_zero_count_status'] ) ) {
										$statuses .= '<div class="gt-status"><a href="' . esc_url( admin_url( 'edit.php?post_type=' . $item . '&post_status=private' ) ) . '" class="gt-private" title="' . esc_attr__( 'Private', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $private ) ) . '</a></div>';
									}

									$can_delete = ! empty( $pt_object->cap->delete_posts ) ? current_user_can( $pt_object->cap->delete_posts ) : current_user_can( 'delete_posts' );
									if ( $can_delete && ( $trash > 0 || $this->options['show_zero_count_status'] ) ) {
										$statuses .= '<div class="gt-status"><a href="' . esc_url( admin_url( 'edit.php?post_type=' . $item . '&post_status=trash' ) ) . '" class="gt-trash" title="' . esc_attr__( 'Trash', 'dashboard-glance' ) . '">' . esc_html( number_format_i18n( $trash ) ) . '</a></div>';
									}

									$statuses .= '</div>';
								}

								$elements[] = sprintf(
									'<div class="%1$s" data-order="gt_%2$d"><style>#dashboard_right_now li a[data-gt="%3$s"]:before{content:\'\\%4$s\';}</style><div class="gt-published"><a data-gt="%3$s" href="%5$s" class="glance-that" title="%6$s">%7$s</a>%8$s%9$s</div>%10$s</div>',
									esc_attr( $classes ),
									(int) ( $key + 1 ),
									esc_attr( $item ),
									esc_attr( $icon_code ),
									esc_url( admin_url( 'edit.php?post_type=' . $item ) ),
									esc_attr( sprintf( __( 'All %s', 'dashboard-glance' ), $this->label( $item, $pt_object->labels->name, 2 ) ) ),
									esc_html( $text ),
									$new_post,
									$archive,
									$statuses
								);
							}
						}
						break;
				} // end switch
			} // end inner foreach
		} // end outer foreach

		return $elements;
	}

	/**
	 * Render the add/remove glance form inside the dashboard widget.
	 */
	public function add_form() {
		if ( ! $this->editable && ! $this->adminable ) {
			return;
		}

		$post_types = get_post_types( array(), 'objects' );
		$post_types = $this->apply_compat_filter( 'post_type_selection', $post_types );

		$options = array(
			'comment' => array(
				'glancing'   => isset( $this->glances['comment'] ),
				'capability' => 'moderate_comments',
				'icon'       => array( 'admin-comments', 'dashicons' ),
				'label'      => $this->label( 'comment', __( 'Comments', 'dashboard-glance' ), 2 ),
			),
			'user' => array(
				'glancing'   => isset( $this->glances['user'] ),
				'capability' => 'list_users',
				'icon'       => array( 'admin-users', 'dashicons' ),
				'label'      => $this->label( 'user', __( 'Users', 'dashboard-glance' ), 2 ),
			),
			'plugin' => array(
				'glancing'   => isset( $this->glances['plugin'] ),
				'capability' => 'activate_plugins',
				'icon'       => array( 'admin-plugins', 'dashicons' ),
				'label'      => $this->label( 'plugin', __( 'Plugins', 'dashboard-glance' ), 2 ),
			),
			'theme' => array(
				'glancing'   => isset( $this->glances['theme'] ),
				'capability' => 'switch_themes',
				'icon'       => array( 'admin-appearance', 'dashicons' ),
				'label'      => $this->label( 'theme', __( 'Themes', 'dashboard-glance' ), 2 ),
			),
			'user_request-export_personal_data' => array(
				'glancing'   => isset( $this->glances['user_request-export_personal_data'] ),
				'capability' => 'manage_options',
				'icon'       => array( 'download', 'dashicons' ),
				'label'      => $this->label( 'user_request-export_personal_data', '', 2 ),
			),
			'user_request-remove_personal_data' => array(
				'glancing'   => isset( $this->glances['user_request-remove_personal_data'] ),
				'capability' => 'manage_options',
				'icon'       => array( 'editor-removeformatting', 'dashicons' ),
				'label'      => $this->label( 'user_request-remove_personal_data', '', 2 ),
			),
			'health-check-site-status' => array(
				'glancing'   => isset( $this->glances['health-check-site-status'] ),
				'capability' => 'view_site_health_checks',
				'icon'       => array( 'heart', 'dashicons' ),
				'label'      => $this->label( 'health-check-site-status', '', 2 ),
			),
		);

		foreach ( $post_types as $pt ) {
			if ( 'revision' === $pt->name && current_user_can( 'edit_dashboard' ) ) {
				$options['revision'] = array(
					'glancing'   => isset( $this->glances[ $pt->name ] ),
					'capability' => 'edit_dashboard',
					'icon'       => array( 'backup', 'dashicons' ),
					'label'      => $this->label( $pt->name, $pt->labels->name, 2 ),
				);
			} else {
				if ( 'post' === $pt->name ) {
					$icon = 'admin-post';
				} elseif ( 'page' === $pt->name ) {
					$icon = 'admin-page';
				} elseif ( 'attachment' === $pt->name ) {
					$icon = 'admin-media';
				} elseif ( ! empty( $pt->menu_icon ) ) {
					$icon = esc_attr( str_replace( 'dashicons-', '', $this->apply_compat_filter( 'option_icons', $pt->menu_icon, $pt->name ) ) );
				} else {
					$icon = $this->apply_compat_filter( 'option_icons', 'marker', $pt->name );
				}

				$options[ $pt->name ] = array(
					'glancing'   => isset( $this->glances[ $pt->name ] ),
					'capability' => $pt->cap->edit_posts,
					'icon'       => array( $icon, 'dashicons' ),
					'label'      => $this->label( $pt->name, $pt->labels->name, 2 ),
				);
			}
		}

		if ( class_exists( 'RGFormsModel' ) ) {
			$options['gravityform'] = array(
				'glancing'   => isset( $this->glances['gravityform'] ),
				'capability' => array( 'gform_full_access', 'gravityforms_edit_forms' ),
				'icon'       => array( 'gravityform', 'custom' ),
				'label'      => $this->label( 'gravityform', __( 'Gravity Forms', 'dashboard-glance' ), 2 ),
			);
		}

		if ( class_exists( 'FrmForm' ) ) {
			$options['formidableform'] = array(
				'glancing'   => isset( $this->glances['formidableform'] ),
				'capability' => array( 'frm_view_forms', 'frm_edit_forms' ),
				'icon'       => array( 'formidableform', 'custom' ),
				'label'      => $this->label( 'formidableform', __( 'Formidable Forms', 'dashboard-glance' ), 2 ),
			);
		}

		if ( function_exists( 'Ninja_Forms' ) ) {
			$options['ninjaform'] = array(
				'glancing'   => isset( $this->glances['ninjaform'] ),
				'capability' => apply_filters( 'ninja_forms_admin_parent_menu_capabilities', 'manage_options' ),
				'icon'       => array( 'feedback', 'dashicons' ),
				'label'      => $this->label( 'ninjaform', __( 'Ninja Forms', 'dashboard-glance' ), 2 ),
			);
		}

		$available = $this->apply_compat_filter( 'glance_selection', array_keys( $options ) );
		?>
		<form id="gt-form" class="dg-form" method="post" action="#" style="display:none;">
			<?php wp_nonce_field( 'dg_process_form_nonce', 'dg_nonce' ); ?>
			<div id="visible-icon" alt="<?php echo esc_attr( $this->get_icon_code( 'marker' ) ); ?>" class="dashicon dashicons-marker dashicons-picker"></div>
			<input id="gt-item-icon" name="gt-item-icon" type="hidden" data-dashicon="selected" value="<?php echo esc_attr( $this->get_icon_code( 'marker' ) ); ?>" />
			<select id="gt-item" name="gt-item">
				<option value=""></option>
				<?php
				foreach ( $options as $glance => $args ) {
					if ( ! in_array( $glance, $available, true ) ) {
						continue;
					}

					$has_cap = false;
					if ( is_array( $args['capability'] ) ) {
						foreach ( $args['capability'] as $cap ) {
							if ( current_user_can( $cap ) ) {
								$has_cap = true;
								break;
							}
						}
					} else {
						$has_cap = current_user_can( $args['capability'] );
					}

					if ( ! $has_cap ) {
						continue;
					}

					$glancing = $args['glancing'] ? 'data-glancing="shown"' : 'data-glancing="hidden"';
					$icon_type = isset( $args['icon'][1] ) ? $args['icon'][1] : 'dashicons';
					$code      = $this->get_icon_code( $args['icon'][0], $icon_type );
					?>
					<option value="<?php echo esc_attr( $glance ); ?>" data-dashicon="<?php echo esc_attr( $code ); ?>" <?php echo $glancing; ?>>
						<?php echo esc_html( $args['label'] ); ?>
					</option>
					<?php
				}
				?>
			</select>
			<span style="float: right;">
				<input type="submit" class="button-primary" id="submit-gt-item" style="display:none;" />
			</span>
		</form>
		<?php
	}

	/**
	 * Remove internal or unwanted post types from option list.
	 *
	 * @param array $post_types Post types map.
	 * @return array
	 */
	public function remove_post_type_options( $post_types ) {
		$unwanted = array(
			'give_payment',
			'give_log',
			'oembed_cache',
			'phw_comment_loc',
			'ph-webpage',
			'ph_comment_location',
			'project_image',
			'nav_menu_item',
			'customize_changeset',
			'custom_css',
			'frm_form_actions',
			'frm_styles',
			'acf-field',
			'user_request',
			'gplp',
			'project-huddle',
			'scheduled-action',
			'ph_version',
		);

		foreach ( $unwanted as $slug ) {
			unset( $post_types[ $slug ] );
		}

		return $post_types;
	}

	/**
	 * Customize default post type icon when option is selected.
	 *
	 * @param string $icon Current icon.
	 * @param string $post_type Post type name.
	 * @return string
	 */
	public function customize_post_type_icon( $icon, $post_type ) {
		switch ( $post_type ) {
			case 'acf-field-group':
				return 'welcome-widgets-menus';
			case 'wp_show_posts':
				return 'editor-ul';
			case 'gravityview':
				return 'gravityview';
			case 'wp_block':
				return 'layout';
			default:
				return $icon;
		}
	}

	/**
	 * Process AJAX form submission to add or remove an item.
	 */
	public function process_form() {
		if ( ! check_ajax_referer( 'dg_process_form_nonce', 'nonce', false ) && ! check_ajax_referer( 'dashboard_glance_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'dashboard-glance' ) ), 403 );
		}

		if ( ! $this->editable && ! $this->adminable ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to modify glances.', 'dashboard-glance' ) ), 403 );
		}

		$user_id = get_current_user_id();
		$glance  = isset( $_POST['gt_item'] ) ? sanitize_key( $_POST['gt_item'] ) : '';
		$action  = isset( $_POST['gt_action'] ) ? sanitize_key( $_POST['gt_action'] ) : '';
		$icon    = isset( $_POST['gt_item_icon'] ) ? sanitize_text_field( $_POST['gt_item_icon'] ) : 'f159';

		// Validate icon code against safe pattern
		if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $icon ) ) {
			$icon = 'f159';
		}

		$post_types = get_post_types();
		$success    = false;

		if ( 'add_gt_item' === $action ) {
			if ( empty( $glance ) ) {
				$this->notices[] = array(
					'message' => __( 'You must select an item to add.', 'dashboard-glance' ),
					'class'   => 'error',
				);
			} else {
				$this->glances[ $glance ] = array( 'icon' => $icon );
				ksort( $this->glances );
				update_user_meta( $user_id, 'dashboard_glance', $this->glances );

				$label = isset( $post_types[ $glance ] ) ? get_post_type_object( $glance )->labels->name : ucfirst( str_replace( array( '_', '-' ), ' ', $glance ) );
				$this->notices[] = array(
					'message' => sprintf(
						/* translators: %s: Item name */
						__( '<strong>%s</strong> was successfully added to your glances.', 'dashboard-glance' ),
						esc_html( $label )
					),
					'class'   => 'success',
				);
				$success = true;
			}
		} elseif ( 'remove_gt_item' === $action ) {
			if ( empty( $glance ) ) {
				$this->notices[] = array(
					'message' => __( 'You must select an item to remove.', 'dashboard-glance' ),
					'class'   => 'error',
				);
			} else {
				unset( $this->glances[ $glance ] );
				update_user_meta( $user_id, 'dashboard_glance', $this->glances );

				$label = isset( $post_types[ $glance ] ) ? get_post_type_object( $glance )->labels->name : ucfirst( str_replace( array( '_', '-' ), ' ', $glance ) );
				$this->notices[] = array(
					'message' => sprintf(
						/* translators: %s: Item name */
						__( '<strong>%s</strong> was successfully removed from your glances.', 'dashboard-glance' ),
						esc_html( $label )
					),
					'class'   => 'success',
				);
				$success = true;
			}
		}

		wp_send_json(
			array(
				'success'  => $success,
				'notice'   => $this->show_notices(),
				'glance'   => $glance,
				'elements' => $this->customize_items(),
			)
		);
	}

	/**
	 * Build notice markup.
	 *
	 * @return string
	 */
	public function show_notices() {
		$message = '';
		if ( ! empty( $this->notices ) ) {
			foreach ( $this->notices as $notice ) {
				$type = in_array( $notice['class'], array( 'error', 'warning', 'info', 'success' ), true ) ? $notice['class'] : 'info';
				$message .= sprintf(
					'<div class="notice notice-%1$s is-dismissible gt-message"><p>%2$s</p></div>',
					esc_attr( $type ),
					wp_kses_post( $notice['message'] )
				);
			}
		}
		return $message;
	}

	/**
	 * AJAX handler: Reorder glances.
	 */
	public function sort_glances() {
		if ( ! check_ajax_referer( 'dg_sort_glances_nonce', 'nonce', false ) && ! check_ajax_referer( 'dashboard_glance_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'dashboard-glance' ) ), 403 );
		}

		if ( ! $this->editable && ! $this->adminable ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dashboard-glance' ) ), 403 );
		}

		// Security: Always update the authenticated user's ID to prevent IDOR
		$user_id = get_current_user_id();
		$order   = isset( $_POST['gt_sort'] ) && is_array( $_POST['gt_sort'] ) ? array_map( 'sanitize_text_field', $_POST['gt_sort'] ) : array();

		// Clean up empty entries
		$order = array_values( array_filter( $order ) );

		foreach ( $order as $key => $gt_index ) {
			$index_num = (int) str_replace( 'gt_', '', $gt_index );
			foreach ( $this->glances_indexed as $i => $data ) {
				if ( ( $i + 1 ) === $index_num && isset( $this->glances[ $data['glance'] ] ) ) {
					$this->glances[ $data['glance'] ]['sorted'] = (int) $key;
				}
			}
		}

		update_user_meta( $user_id, 'dashboard_glance', $this->glances );

		wp_send_json(
			array(
				'success' => true,
				'order'   => $order,
			)
		);
	}

	/**
	 * AJAX handler: Set site-wide default glances.
	 */
	public function default_glances() {
		if ( ! check_ajax_referer( 'dg_default_glances_nonce', 'nonce', false ) && ! check_ajax_referer( 'dashboard_glance_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'dashboard-glance' ) ), 403 );
		}

		if ( ! $this->adminable ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dashboard-glance' ) ), 403 );
		}

		$user_id = get_current_user_id();
		$action  = isset( $_POST['gt_action'] ) ? sanitize_key( $_POST['gt_action'] ) : 'new';

		$glances           = get_user_meta( $user_id, 'dashboard_glance', true );
		$status_visibility = get_user_meta( $user_id, 'dashboard_glance_status_visibility', true );
		$info_visibility   = get_user_meta( $user_id, 'dashboard_glance_info_visibility', true );

		// Set default options
		update_option( 'dashboard_glance_default', $glances );
		update_option( 'dashboard_glance_status_visibility_default', $status_visibility );
		update_option( 'dashboard_glance_info_visibility_default', $info_visibility );

		if ( 'all' === $action ) {
			$users = get_users( array( 'fields' => 'ID' ) );
			foreach ( $users as $uid ) {
				update_user_meta( $uid, 'dashboard_glance', $glances );
				update_user_meta( $uid, 'dashboard_glance_status_visibility', $status_visibility );
				update_user_meta( $uid, 'dashboard_glance_info_visibility', $info_visibility );
			}

			$this->notices[] = array(
				'message' => __( 'Current glance configuration has been successfully applied to all existing and new users.', 'dashboard-glance' ),
				'class'   => 'success',
			);
		} else {
			$this->notices[] = array(
				'message' => __( 'Current glance configuration will be applied to new users.', 'dashboard-glance' ),
				'class'   => 'success',
			);
		}

		wp_send_json(
			array(
				'success' => true,
				'notice'  => $this->show_notices(),
			)
		);
	}

	/**
	 * AJAX handler: Toggle status badges visibility.
	 */
	public function toggle_status_visibility() {
		if ( ! check_ajax_referer( 'dg_toggle_status_nonce', 'nonce', false ) && ! check_ajax_referer( 'dashboard_glance_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'dashboard-glance' ) ), 403 );
		}

		if ( ! $this->editable && ! $this->adminable ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dashboard-glance' ) ), 403 );
		}

		$user_id = get_current_user_id();
		$action  = isset( $_POST['gt_action'] ) ? sanitize_key( $_POST['gt_action'] ) : '';

		if ( in_array( $action, array( 'show', 'hide' ), true ) ) {
			$visibility = ( 'show' === $action ) ? 'visible' : 'hidden';
			update_user_meta( $user_id, 'dashboard_glance_status_visibility', $visibility );
			wp_send_json( array( 'success' => true, 'visibility' => $visibility ) );
		}

		wp_send_json( array( 'success' => false ) );
	}

	/**
	 * AJAX handler: Toggle WP info message visibility.
	 */
	public function toggle_info_visibility() {
		if ( ! check_ajax_referer( 'dg_toggle_info_nonce', 'nonce', false ) && ! check_ajax_referer( 'dashboard_glance_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'dashboard-glance' ) ), 403 );
		}

		if ( ! $this->editable && ! $this->adminable ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dashboard-glance' ) ), 403 );
		}

		$user_id = get_current_user_id();
		$action  = isset( $_POST['gt_action'] ) ? sanitize_key( $_POST['gt_action'] ) : '';

		if ( in_array( $action, array( 'show', 'hide' ), true ) ) {
			$visibility = ( 'show' === $action ) ? 'visible' : 'hidden';
			update_user_meta( $user_id, 'dashboard_glance_info_visibility', $visibility );
			wp_send_json( array( 'success' => true, 'visibility' => $visibility ) );
		}

		wp_send_json( array( 'success' => false ) );
	}

	/**
	 * Overrides status icons if defined by Post State Tags plugin.
	 */
	public function check_override_status_icons() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( is_plugin_active( 'post-state-tags/post-state-tags.php' ) ) {
			$pst_default = get_option( 'bb-pst-default', array() );
			$pst_custom  = get_option( 'bb-pst-custom', array() );

			$post_state_tags_options = array(
				'publish' => isset( $pst_default['bb-pst-publish-icon'] ) ? $pst_default['bb-pst-publish-icon'] : '',
				'future'  => isset( $pst_default['bb-pst-future-icon'] ) ? $pst_default['bb-pst-future-icon'] : '',
				'draft'   => isset( $pst_default['bb-pst-draft-icon'] ) ? $pst_default['bb-pst-draft-icon'] : '',
				'pending' => isset( $pst_default['bb-pst-pending-icon'] ) ? $pst_default['bb-pst-pending-icon'] : '',
				'private' => isset( $pst_default['bb-pst-private-icon'] ) ? $pst_default['bb-pst-private-icon'] : '',
				'trash'   => isset( $pst_default['bb-pst-trash-icon'] ) ? $pst_default['bb-pst-trash-icon'] : '',
			);

			if ( isset( $pst_custom['bb-pst-archive-icon'] ) ) {
				$post_state_tags_options['archive'] = $pst_custom['bb-pst-archive-icon'];
			}

			$css = '';
			foreach ( $post_state_tags_options as $status => $icon ) {
				if ( ! empty( $icon ) ) {
					$clean_icon = str_replace( 'dashicons-', '', sanitize_text_field( $icon ) );
					$code       = $this->get_icon_code( $clean_icon );
					if ( $code && preg_match( '/^[a-fA-F0-9]+$/', $code ) && preg_match( '/^[a-zA-Z0-9_-]+$/', $status ) ) {
						$css .= sprintf(
							'#dashboard_right_now div.gt-status a.gt-%1$s:before { content: \'\\%2$s\'; }',
							sanitize_key( $status ),
							$code
						);
					}
				}
			}

			if ( ! empty( $css ) ) {
				echo '<style id="dg-post-state-icons">' . wp_strip_all_tags( $css ) . '</style>';
			}
		}
	}

	/**
	 * Retrieve dashicon character code from dashicon name.
	 *
	 * @param string $icon Dashicon slug.
	 * @param string $family Icon family.
	 * @return string
	 */
	public function get_icon_code( $icon, $family = 'dashicons' ) {
		if ( 'custom' === $family ) {
			return $icon;
		}

		if ( isset( $this->icons[ $family ] ) && is_array( $this->icons[ $family ] ) ) {
			foreach ( $this->icons[ $family ] as $code => $title ) {
				if ( $icon === $title ) {
					return $code;
				}
			}
		}

		return 'f159'; // Default marker
	}

	/**
	 * Get filtered dashicons list.
	 *
	 * @return array
	 */
	public function get_dashicons() {
		$options = $this->apply_compat_filter( 'dashicons', array() );
		if ( ! empty( $options ) && is_array( $options ) ) {
			$dashicons = array();
			foreach ( $options as $title ) {
				$code               = $this->get_icon_code( $title );
				$dashicons[ $code ] = $title;
			}
			return $dashicons;
		}

		return isset( $this->icons['dashicons'] ) ? $this->icons['dashicons'] : array();
	}

	/**
	 * Get full list of supported Dashicons.
	 *
	 * @return array
	 */
	public function get_icons() {
		return array(
			'dashicons' => array(
				// Admin Menu
				'f333' => 'menu',
				'f228' => 'menu-alt',
				'f329' => 'menu-alt2',
				'f349' => 'menu-alt3',
				'f319' => 'admin-site',
				'f11d' => 'admin-site-alt',
				'f11e' => 'admin-site-alt2',
				'f11f' => 'admin-site-alt3',
				'f226' => 'dashboard',
				'f109' => 'admin-post',
				'f104' => 'admin-media',
				'f103' => 'admin-links',
				'f105' => 'admin-page',
				'f101' => 'admin-comments',
				'f100' => 'admin-appearance',
				'f106' => 'admin-plugins',
				'f485' => 'plugins-checked',
				'f110' => 'admin-users',
				'f107' => 'admin-tools',
				'f108' => 'admin-settings',
				'f112' => 'admin-network',
				'f102' => 'admin-home',
				'f111' => 'admin-generic',
				'f148' => 'admin-collapse',
				'f536' => 'filter',
				'f540' => 'admin-customizer',
				'f541' => 'admin-multisite',
				// Welcome Screen
				'f119' => 'welcome-write-blog',
				'f133' => 'welcome-add-page',
				'f115' => 'welcome-view-site',
				'f116' => 'welcome-widgets-menus',
				'f117' => 'welcome-comments',
				'f118' => 'welcome-learn-more',
				// Post Formats
				'f123' => 'format-aside',
				'f128' => 'format-image',
				'f161' => 'format-gallery',
				'f126' => 'format-video',
				'f130' => 'format-status',
				'f122' => 'format-quote',
				'f125' => 'format-chat',
				'f127' => 'format-audio',
				'f306' => 'camera',
				'f129' => 'camera-alt',
				'f232' => 'images-alt',
				'f233' => 'images-alt2',
				'f234' => 'video-alt',
				'f235' => 'video-alt2',
				'f236' => 'video-alt3',
				// Media
				'f501' => 'media-archive',
				'f500' => 'media-audio',
				'f499' => 'media-code',
				'f498' => 'media-default',
				'f497' => 'media-document',
				'f496' => 'media-interactive',
				'f495' => 'media-spreadsheet',
				'f491' => 'media-text',
				'f490' => 'media-video',
				'f492' => 'playlist-audio',
				'f493' => 'playlist-video',
				'f522' => 'controls-play',
				'f523' => 'controls-pause',
				'f519' => 'controls-forward',
				'f517' => 'controls-skipforward',
				'f518' => 'controls-back',
				'f516' => 'controls-skipback',
				'f515' => 'controls-repeat',
				'f521' => 'controls-volumeon',
				'f520' => 'controls-volumeoff',
				'f121' => 'text-page',
				// Image Editing
				'f165' => 'image-crop',
				'f531' => 'image-rotate',
				'f166' => 'image-rotate-left',
				'f167' => 'image-rotate-right',
				'f168' => 'image-flip-vertical',
				'f169' => 'image-flip-horizontal',
				'f533' => 'image-filter',
				'f171' => 'undo',
				'f172' => 'redo',
				// Block Editor
				'f10a' => 'align-pull-left',
				'f10b' => 'align-pull-right',
				'f12b' => 'block-default',
				'f137' => 'cloud-saved',
				'f13b' => 'cloud-upload',
				'f13c' => 'columns',
				'f13d' => 'cover-image',
				'f13e' => 'embed-audio',
				'f13f' => 'embed-generic',
				'f144' => 'embed-photo',
				'f146' => 'embed-post',
				'f149' => 'embed-video',
				'f14a' => 'exit',
				'f14b' => 'html',
				'f14c' => 'info-outline',
				'f14d' => 'insert-after',
				'f14e' => 'insert-before',
				'f10f' => 'insert',
				'f14f' => 'remove',
				'f150' => 'shortcode',
				'f151' => 'table-col-after',
				'f152' => 'table-col-before',
				'f15a' => 'table-col-delete',
				'f15b' => 'table-row-after',
				'f15c' => 'table-row-before',
				'f15d' => 'table-row-delete',
				'f15e' => 'saved',
				// TinyMCE
				'f200' => 'editor-bold',
				'f201' => 'editor-italic',
				'f203' => 'editor-ul',
				'f204' => 'editor-ol',
				'f12c' => 'editor-ol-rtl',
				'f205' => 'editor-quote',
				'f206' => 'editor-alignleft',
				'f207' => 'editor-aligncenter',
				'f208' => 'editor-alignright',
				'f209' => 'editor-insertmore',
				'f210' => 'editor-spellcheck',
				'f211' => 'editor-expand',
				'f506' => 'editor-contract',
				'f212' => 'editor-kitchensink',
				'f213' => 'editor-underline',
				'f214' => 'editor-justify',
				'f215' => 'editor-textcolor',
				'f216' => 'editor-paste-word',
				'f217' => 'editor-paste-text',
				'f218' => 'editor-removeformatting',
				'f219' => 'editor-video',
				'f220' => 'editor-customchar',
				'f221' => 'editor-outdent',
				'f222' => 'editor-indent',
				'f223' => 'editor-help',
				'f224' => 'editor-strikethrough',
				'f225' => 'editor-unlink',
				'f320' => 'editor-rtl',
				'f10c' => 'editor-ltr',
				'f474' => 'editor-break',
				'f475' => 'editor-code',
				'f476' => 'editor-paragraph',
				'f535' => 'editor-table',
				// Posts Screen
				'f135' => 'align-left',
				'f136' => 'align-right',
				'f134' => 'align-center',
				'f138' => 'align-none',
				'f160' => 'lock',
				'f528' => 'unlock',
				'f145' => 'calendar',
				'f508' => 'calendar-alt',
				'f177' => 'visibility',
				'f530' => 'hidden',
				'f173' => 'post-status',
				'f464' => 'edit',
				'f182' => 'trash',
				'f537' => 'sticky',
				// Sorting
				'f504' => 'external',
				'f142' => 'arrow-up',
				'f140' => 'arrow-down',
				'f139' => 'arrow-right',
				'f141' => 'arrow-left',
				'f342' => 'arrow-up-alt',
				'f346' => 'arrow-down-alt',
				'f344' => 'arrow-right-alt',
				'f340' => 'arrow-left-alt',
				'f343' => 'arrow-up-alt2',
				'f347' => 'arrow-down-alt2',
				'f345' => 'arrow-right-alt2',
				'f341' => 'arrow-left-alt2',
				'f156' => 'sort',
				'f229' => 'leftright',
				'f503' => 'randomize',
				'f163' => 'list-view',
				'f164' => 'exerpt-view',
				'f509' => 'grid-view',
				'f545' => 'move',
				// WordPress & Dev
				'f308' => 'hammer',
				'f309' => 'art',
				'f310' => 'migrate',
				'f311' => 'performance',
				'f483' => 'universal-access',
				'f507' => 'universal-access-alt',
				'f486' => 'tickets',
				'f484' => 'nametag',
				'f481' => 'clipboard',
				'f487' => 'heart',
				'f488' => 'megaphone',
				'f489' => 'schedule',
				'f10d' => 'tide',
				'f124' => 'rest-api',
				'f13a' => 'code-standards',
				// Products & WP
				'f120' => 'wordpress',
				'f324' => 'wordpress-alt',
				'f157' => 'pressthis',
				'f463' => 'update',
				'f113' => 'update-alt',
				'f180' => 'screenoptions',
				'f348' => 'info',
				'f174' => 'cart',
				'f175' => 'feedback',
				'f176' => 'cloud',
				'f326' => 'translation',
				// Taxonomies
				'f323' => 'tag',
				'f318' => 'category',
				// Widgets & Archives
				'f480' => 'archive',
				'f479' => 'tagcloud',
				'f478' => 'text',
				// Notifications & Icons
				'f147' => 'yes',
				'f12a' => 'yes-alt',
				'f158' => 'no',
				'f335' => 'no-alt',
				'f132' => 'plus',
				'f502' => 'plus-alt',
				'f460' => 'minus',
				'f153' => 'dismiss',
				'f159' => 'marker',
				'f155' => 'star-filled',
				'f459' => 'star-half',
				'f154' => 'star-empty',
				'f227' => 'flag',
				'f534' => 'warning',
				'f16d' => 'bell',
				// Misc
				'f230' => 'location',
				'f231' => 'location-alt',
				'f178' => 'vault',
				'f332' => 'shield',
				'f334' => 'shield-alt',
				'f468' => 'sos',
				'f179' => 'search',
				'f181' => 'slides',
				'f183' => 'analytics',
				'f184' => 'chart-pie',
				'f185' => 'chart-bar',
				'f238' => 'chart-line',
				'f239' => 'chart-area',
				'f307' => 'groups',
				'f338' => 'businessman',
				'f12f' => 'businesswoman',
				'f12e' => 'businessperson',
				'f336' => 'id',
				'f337' => 'id-alt',
				'f312' => 'products',
				'f313' => 'awards',
				'f314' => 'forms',
				'f473' => 'testimonial',
				'f322' => 'portfolio',
				'f330' => 'book',
				'f331' => 'book-alt',
				'f316' => 'download',
				'f317' => 'upload',
				'f321' => 'backup',
				'f469' => 'clock',
				'f339' => 'lightbulb',
				'f482' => 'microphone',
				'f472' => 'desktop',
				'f547' => 'laptop',
				'f471' => 'tablet',
				'f470' => 'smartphone',
				'f525' => 'phone',
				'f16e' => 'calculator',
				'f18a' => 'games',
				'f193' => 'printer',
				'f510' => 'index-card',
				'f511' => 'carrot',
				'f512' => 'building',
				'f513' => 'store',
				'f514' => 'album',
				'f527' => 'palmtree',
				'f524' => 'tickets-alt',
				'f526' => 'money',
				'f328' => 'smiley',
				'f542' => 'thumbs-down',
				'f529' => 'thumbs-up',
				'f538' => 'layout',
				'f546' => 'paperclip',
				'f131' => 'color-picker',
				'f327' => 'edit-large',
				'f16a' => 'bank',
				'f18c' => 'hourglass',
				'f18e' => 'money-alt',
				'f18f' => 'open-folder',
				'f190' => 'pdf',
				'f191' => 'pets',
				'f194' => 'privacy',
				'f186' => 'edit-page',
				'f188' => 'fullscreen-alt',
				'f189' => 'fullscreen-exit-alt',
			),
		);
	}
}
