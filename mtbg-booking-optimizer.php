<?php
/*
Plugin Name: MTBG Booking Optimizer
Description: Consolidated booking, payment plan, and pricing table logic for MTB Guatemala tours.
Author: MTB Guatemala
Version: 1.0.7
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/admin.php';

if ( file_exists( __DIR__ . '/includes/class-mtbg-gpx-elevation.php' ) ) {
	require_once __DIR__ . '/includes/class-mtbg-gpx-elevation.php';
}

class MTBG_Booking_Optimizer {

	use MTBG_Booking_Optimizer_Admin;

	const OPTION_KEY = 'mtbg_booking_optimizer_settings';

	const TOURS_CAT_ID_DEFAULT          = 99;
	const UPCOMING_TOURS_CAT_ID_DEFAULT = 3202;

	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		$settings = self::get_settings();
		$base     = plugin_dir_path( __FILE__ ) . 'includes/';

		// Admin-only modules
		if ( ! empty( $settings['tour_difficulty'] ) ) {
			$file = $base . 'tour-difficulty/tour-difficulty.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		if ( is_admin() ) {
			return;
		}

		// Front-end optional modules
		if ( ! empty( $settings['gpx_elevation'] ) && class_exists( 'MTBG_GPX_Elevation_Module' ) ) {
			new MTBG_GPX_Elevation_Module();
		}

		if ( ! empty( $settings['backend_cart'] ) ) {
			$file = $base . 'mtbg-backend-cart-override.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		if ( ! empty( $settings['pricing_table'] ) ) {
			$file = $base . 'mtbg-pricing-table-shortcode.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		$banner_file = $base . 'payment-plans-banner.php';
		if ( file_exists( $banner_file ) ) {
			require_once $banner_file;
			add_action( 'init', 'mtbg_register_payment_plans_banner_shortcode' );
		}

		add_action( 'wp', array( $this, 'maybe_boot_features' ) );
		add_filter( 'script_loader_tag', array( $this, 'add_defer_to_scripts' ), 10, 3 );
	}

	/* ======================================================
	 * Settings
	 * ====================================================== */

	public static function get_default_settings() {
		return array(
			'backend_cart'   => 0,
			'booking_hijack' => 0,
			'payment_plans'  => 0,
			'pricing_table'  => 0,
			'gpx_elevation'  => 0,
			'enable_payment_plans_shortcode' => 0,
			'tour_difficulty' => 0,

			// Bike rentals module toggle
			'bike_rentals' => 0,

			'tours_cat_id'          => self::TOURS_CAT_ID_DEFAULT,
			'upcoming_tours_cat_id' => self::UPCOMING_TOURS_CAT_ID_DEFAULT,

			// Bike rentals category root
			'bike_rentals_cat_id' => 0,

			// Tour discounts
			'tour_discount_1'      => 0,
			'tour_discount_2'      => 40,
			'tour_discount_3_5'    => 50,
			'tour_discount_6_plus' => 60,

			'upcoming_discount_1'      => 0,
			'upcoming_discount_2'      => 40,
			'upcoming_discount_3_5'    => 50,
			'upcoming_discount_6_plus' => 60,

			// Bike rentals discounts
			'rental_discount_1_day'       => 0,
			'rental_discount_2_days'      => 0,
			'rental_discount_3_days'      => 0,
			'rental_discount_5_plus_days' => 0,
		);
	}

	public static function get_settings() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( (array) $saved, self::get_default_settings() );
	}

	/* ======================================================
	 * Product detection
	 * ====================================================== */

	public static function is_tour_product() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) return false;
		global $post;
		if ( ! $post ) return false;

		$settings = self::get_settings();
		$root_id  = (int) ( $settings['tours_cat_id'] ?? self::TOURS_CAT_ID_DEFAULT );
		if ( ! $root_id ) return false;

		static $ids = null;
		if ( $ids === null ) {
			$ids = array_merge(
				array( $root_id ),
				array_map( 'intval', get_term_children( $root_id, 'product_cat' ) ?: array() )
			);
		}

		return has_term( $ids, 'product_cat', $post->ID );
	}

	public static function is_upcoming_tour_product() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) return false;
		global $post;
		if ( ! $post ) return false;

		$settings = self::get_settings();
		$up_id    = (int) ( $settings['upcoming_tours_cat_id'] ?? self::UPCOMING_TOURS_CAT_ID_DEFAULT );

		return $up_id && has_term( $up_id, 'product_cat', $post->ID );
	}

	public static function is_bike_rental_product() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) return false;
		global $post;
		if ( ! $post ) return false;

		$settings = self::get_settings();
		$root_id  = (int) ( $settings['bike_rentals_cat_id'] ?? 0 );
		if ( ! $root_id ) return false;

		static $ids = null;
		if ( $ids === null ) {
			$ids = array_merge(
				array( $root_id ),
				array_map( 'intval', get_term_children( $root_id, 'product_cat' ) ?: array() )
			);
		}

		return has_term( $ids, 'product_cat', $post->ID );
	}

	public static function get_discount_mode() {
		if ( self::is_upcoming_tour_product() ) return 'upcoming';
		return 'tour';
	}

	/* ======================================================
	 * Boot paths
	 * ====================================================== */

	public function maybe_boot_features() {
		$settings = self::get_settings();
		$base     = plugin_dir_path( __FILE__ ) . 'includes/';

		// Tours
		if ( self::is_tour_product() ) {

			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_pricing_core' ), 5 );

			if ( ! empty( $settings['booking_hijack'] ) ) {
				$file = $base . 'mtbg-booking-script-v81.php';
				if ( file_exists( $file ) ) require_once $file;
			}

			if ( ! empty( $settings['payment_plans'] ) ) {
				$file = $base . 'mtbg-payment-plans-patch.php';
				if ( file_exists( $file ) ) require_once $file;
			}

			return;
		}

// Bike rentals
if ( self::is_bike_rental_product() && ! empty( $settings['bike_rentals'] ) ) {
	$file = $base . 'bike-rentals/mtbg-bike-rentals.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
	return;
}
	}

	/* ======================================================
	 * Tours pricing core
	 * ====================================================== */

	public function enqueue_pricing_core() {
		if ( ! self::is_tour_product() ) return;

		$settings      = self::get_settings();
		$discount_mode = self::get_discount_mode();

		wp_enqueue_script(
			'mtbg-pricing-core-js',
			plugins_url( 'assets/js/mtbg-pricing-core.js', __FILE__ ),
			array(),
			'1.0.0',
			true
		);

		wp_localize_script(
			'mtbg-pricing-core-js',
			'MTBG_CONFIG',
			array(
				'discount_mode' => $discount_mode,
				'group_discounts' => array(
					'tour' => array(
						'solo'      => (int) $settings['tour_discount_1'],
						'two'       => (int) $settings['tour_discount_2'],
						'threeFive' => (int) $settings['tour_discount_3_5'],
						'sixPlus'   => (int) $settings['tour_discount_6_plus'],
					),
					'upcoming' => array(
						'solo'      => (int) $settings['upcoming_discount_1'],
						'two'       => (int) $settings['upcoming_discount_2'],
						'threeFive' => (int) $settings['upcoming_discount_3_5'],
						'sixPlus'   => (int) $settings['upcoming_discount_6_plus'],
					),
				),
			)
		);
	}

	/* ======================================================
	 * Defer
	 * ====================================================== */

	public function add_defer_to_scripts( $tag, $handle ) {
		$handles = array(
			'mtbg-pricing-core-js',
			'mtbg-booking-hijack',
			'mtbg-gpx-elevation-js',
			'mtbg-tour-difficulty',
		);

		if ( in_array( $handle, $handles, true ) ) {
			return str_replace( '<script ', '<script defer ', $tag );
		}

		return $tag;
	}
}

new MTBG_Booking_Optimizer();
