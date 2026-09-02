<?php
/**
 * AutoCoupons
 *
 * @package Auto Coupons for WooCommerce
 * @author    Chillcode
 * @copyright Copyright (c) 2003, Chillcode (https://github.com/chillcode/)
 * @license   GPLv3
 */

namespace ACWC;

use WC_Cart;
use WC_Coupon;
use wpdb;

/**
 * AutoCoupons class.
 */
final class AutoCoupons {

	/**
	 * Coupons available as discounts.
	 *
	 * @var int[]
	 */
	private array $acwc_available_coupons = array();

	/**
	 * Options.
	 *
	 * @var string[]
	 */
	private static array $acwc_options = array(
		'acwc_enable_auto_coupons',
		'acwc_remove_auto_coupons',
		'acwc_remove_coupons',
	);

	/**
	 * Bulk actions.
	 *
	 * @var string[]
	 */
	private static array $acwc_bulk_actions = array(
		'acwc_mark_auto',
		'acwc_unmark_auto',
	);

	/**
	 * Coupons applied as discounts.
	 *
	 * @var array<array<bool|string>>
	 */
	private array $acwc_applied_coupons = array();

	/**
	 * Singleton instance.
	 *
	 * @var AutoCoupons|null
	 */
	private static $acwc_instance = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action(
			'plugins_loaded',
			array( $this, 'plugins_loaded' )
		);
	}

	/**
	 * Plugins loaded.
	 *
	 * @return void
	 */
	public function plugins_loaded(): void {
		if (
			! class_exists(
				'WooCommerce'
			)
		) {
			add_action(
				'admin_notices',
				function () {
					global $pagenow;

					if ( 'plugins.php' === $pagenow ) {
						printf( '<div class="%1$s"><p>%2$s</p></div>', 'notice notice-error is-dismissible', esc_html__( 'Auto Coupons for WooCommerce requires WooCommerce to be installed and active.', 'automatic-coupons-for-woocommerce' ) );
					}
				}
			);

			return;
		}

		add_action(
			'wp',
			array( $this, 'init_wp' )
		);

		add_action(
			'current_screen',
			array( $this, 'current_screen' )
		);

		if ( is_admin() ) {
			add_filter(
				'woocommerce_general_settings',
				array( $this, 'woocommerce_general_settings' )
			);

			add_filter(
				'handle_bulk_actions-edit-shop_coupon',
				array( $this, 'handle_bulk_actions_edit_shop_coupon' ),
				10,
				3
			);

			add_action(
				'woocommerce_coupon_options_save',
				array( $this, 'woocommerce_coupon_options_save' ),
				10,
				1
			);

			add_action(
				'deleted_post',
				array( $this, 'cache_invalidation' )
			);

			add_action(
				'trashed_post',
				array( $this, 'cache_invalidation' )
			);

			add_action(
				'untrashed_post',
				array( $this, 'cache_invalidation' )
			);

			add_action(
				'transition_post_status',
				array( $this, 'transition_post_status_cache_invalidation' ),
				10,
				3
			);

			add_action(
				'admin_notices',
				array( $this, 'admin_notices' ),
			);
		}
	}

	/**
	 * Invalidate coupons cache when deleting, trashing or untrashing them.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function cache_invalidation( int $post_id ): void {
		if ( 'shop_coupon' === get_post_type( $post_id ) ) {
			self::invalidate_automated_coupons_cache();
		}
	}

	/**
	 * Invalidate coupons when status changes.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public function transition_post_status_cache_invalidation( string $new_status, string $old_status, \WP_Post $post ): void {
		if (
			'shop_coupon' === $post->post_type &&
			$new_status !== $old_status
		) {
			self::invalidate_automated_coupons_cache();
		}
	}

	/**
	 * Init the plugin
	 *
	 * @return void
	 */
	public function init_wp(): void {
		if ( ! is_cart() && ! is_checkout() && ! is_checkout_pay_page() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! wc_coupons_enabled() || ! $this->auto_coupons_enabled() ) {
			add_action(
				'woocommerce_before_cart',
				array( $this, 'remove_coupons_if_disabled' )
			);

			add_action(
				'woocommerce_before_checkout_process',
				array( $this, 'remove_coupons_if_disabled' )
			);

			return;
		}

		$this->acwc_available_coupons = $this->get_automated_coupons();

		if ( ! empty( $this->acwc_available_coupons ) ) {
			add_filter(
				'woocommerce_coupon_error',
				array( $this, 'woocommerce_coupon_error' ),
				10,
				3
			);

			add_filter(
				'woocommerce_coupon_message',
				array( $this, 'woocommerce_coupon_message' ),
				10,
				3
			);

			add_action(
				'woocommerce_after_calculate_totals',
				array( $this, 'woocommerce_after_calculate_totals' )
			);

			add_action(
				'woocommerce_after_checkout_validation',
				array( $this, 'woocommerce_after_checkout_validation' ),
				-1,
				0
			);

			add_filter(
				'woocommerce_cart_totals_coupon_label',
				array( $this, 'woocommerce_cart_totals_coupon_label' ),
				10,
				2
			);

			add_filter(
				'woocommerce_cart_totals_coupon_html',
				array( $this, 'woocommerce_cart_totals_coupon_html' ),
				10,
				3
			);

			add_filter(
				'woocommerce_cart_item_subtotal',
				array( $this, 'woocommerce_cart_item_subtotal' ),
				10,
				3
			);
		}
	}

	/**
	 * Use coupons when we are on the screen we want.
	 *
	 * @return void
	 */
	public function current_screen(): void {
		/** Add only on listings pages and compatible post types. */
		$current_screen = get_current_screen();

		if ( null === $current_screen ) {
			return;
		}

		if ( current_user_can( 'manage_options' ) && $this->auto_coupons_enabled() ) {
			if (
				'edit-shop_coupon' === $current_screen->id
			) {
				add_filter(
					'bulk_actions-edit-shop_coupon',
					function ( $bulk_actions ): array {
						/**
						 * Bulk actions array
						 *
						 * @var array<string, string> $bulk_actions
						 */
						$bulk_actions['acwc_mark_auto']   = __( 'Mark as automatic', 'automatic-coupons-for-woocommerce' );
						$bulk_actions['acwc_unmark_auto'] = __( 'Unmark as automatic', 'automatic-coupons-for-woocommerce' );

						return $bulk_actions;
					}
				);

				return;
			}

			if (
				'shop_coupon' === $current_screen->id
			) {
				add_action(
					'woocommerce_coupon_options',
					array( $this, 'woocommerce_coupon_options' ),
					10,
					2
				);

				return;
			}
		}
	}

	/**
	 * Remove coupons from the cart if they are no longer enabled.
	 *
	 * @since  1.0.6
	 *
	 * @return void
	 */
	public function remove_coupons_if_disabled(): void {
		$wc_cart = WC()->cart;

		if ( null === $wc_cart ) {
			return;
		}

		/**
		 * Applied coupons.
		 *
		 * @var string[] $applied_coupons
		 * */
		$applied_coupons = $wc_cart->get_applied_coupons();

		if ( empty( $applied_coupons ) ) {
			return;
		}

		if ( apply_filters(
			'acwc_remove_coupons',
			filter_var( get_option( 'acwc_remove_coupons' ), FILTER_VALIDATE_BOOLEAN, array( 'default' => false ) )
		) ) {
			// Remove all coupons and calculate totals.
			$wc_cart->remove_coupons();
			$wc_cart->calculate_totals();

			return;
		}

		if ( apply_filters(
			'acwc_remove_auto_coupons',
			filter_var( get_option( 'acwc_remove_auto_coupons' ), FILTER_VALIDATE_BOOLEAN, array( 'default' => false ) )
		) ) {
			// Remove only auto applied coupons.

			/** Remove action temporally to prevent calculating totals on each removed coupon */
			remove_action(
				'woocommerce_removed_coupon',
				array( $wc_cart, 'calculate_totals' ),
				20
			);

			$coupons_removed = false;

			try {
				foreach ( $applied_coupons as $coupon_code ) {
					$coupon_code = wc_format_coupon_code( $coupon_code );

					if ( $this->coupon_is_autoapply( new WC_Coupon( $coupon_code ) ) ) {
						$wc_cart->remove_coupon( $coupon_code );
						$coupons_removed = true;
					}
				}

				if ( $coupons_removed ) {
					$wc_cart->calculate_totals();
				}
			} finally {
				add_action(
					'woocommerce_removed_coupon',
					array( $wc_cart, 'calculate_totals' ),
					20,
					0
				);
			}
		}
	}

	/**
	 * Check if auto coupons are enabled.
	 *
	 * @since  1.0.2
	 *
	 * @return bool
	 */
	public function auto_coupons_enabled(): bool {
		return (bool) apply_filters( 'acwc_enable_auto_coupons', true === filter_var( get_option( 'acwc_enable_auto_coupons' ), FILTER_VALIDATE_BOOLEAN, array( 'default' => false ) ) );
	}

	/**
	 * Check if coupon is set as automatic discount.
	 *
	 * @param WC_Coupon $coupon Coupon.
	 * @return bool
	 */
	private function coupon_is_autoapply( WC_Coupon $coupon ): bool {
		return ( filter_var( $coupon->get_meta( '_acwc_discount_autoapply', true ), FILTER_VALIDATE_BOOLEAN ) ? true : false );
	}

	/**
	 * Get all coupons marked as automatic.
	 *
	 * @since 1.0.0
	 *
	 * @return int[]
	 */
	public function get_automated_coupons(): array {

		/**
		 * Cached automated coupons.
		 *
		 * @var int[]|false $get_automated_coupons
		 * */
		$get_automated_coupons = wp_cache_get( 'get_automated_coupons', 'acwc' );

		if ( false !== $get_automated_coupons ) {
			return $get_automated_coupons;
		}

		$get_automated_coupons = array();

		$args = array(
			'posts_per_page'   => -1,
			'suppress_filters' => false,
			'orderby'          => 'title',
			'order'            => 'asc',
			'fields'           => 'ids',
			'post_type'        => 'shop_coupon',
			'post_status'      => 'publish',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'       => array(
				'relation' => 'AND',
				array(
					'key'   => '_acwc_discount_autoapply',
					'value' => 1,
				),
			),
		);

		$get_automated_coupons = get_posts( $args );

		if ( empty( $get_automated_coupons ) ) {
			return array();
		}

		wp_cache_set( 'get_automated_coupons', $get_automated_coupons, 'acwc' );

		return $get_automated_coupons;
	}

	/**
	 * Invalidate automated coupons cache.
	 *
	 * @return void
	 */
	public static function invalidate_automated_coupons_cache(): void {
		wp_cache_delete( 'get_automated_coupons', 'acwc' );
	}

	/**
	 * Add an additional checkbox to Woo general settings to enable/disable automatic coupons.
	 *
	 * @param list<array<mixed>> $settings Settings Tab.
	 *
	 * @return list<array<mixed>> $settings
	 */
	public function woocommerce_general_settings( array $settings ): array {
		$updated_settings = array();

		foreach ( $settings as $section ) {
			$updated_settings[] = $section;
			if ( isset( $section['id'] ) && 'woocommerce_enable_coupons' === $section['id'] ) {
				$updated_settings[] = array(
					'desc'          => __( 'Remove all coupons already applied to carts', 'automatic-coupons-for-woocommerce' ),
					'desc_tip'      => __( 'Remove all coupons already applied to carts when "Enable the use of coupon codes" is unchecked.', 'automatic-coupons-for-woocommerce' ),
					'id'            => 'acwc_remove_coupons',
					'default'       => 'no',
					'type'          => 'checkbox',
					'checkboxgroup' => '',
				);

				$updated_settings[] = array(
					'desc'            => __( 'Allow coupons to apply automatically', 'automatic-coupons-for-woocommerce' ),
					'desc_tip'        => __( 'Coupons can be applied automatically without user interaction.', 'automatic-coupons-for-woocommerce' ),
					'id'              => 'acwc_enable_auto_coupons',
					'default'         => 'no',
					'type'            => 'checkbox',
					'checkboxgroup'   => '',
					'show_if_checked' => 'yes',
				);

				$updated_settings[] = array(
					'desc'            => __( 'Remove all coupons already applied automatically to carts', 'automatic-coupons-for-woocommerce' ),
					'desc_tip'        => __( 'Remove all coupons already applied automatically to carts when "Allow coupons to apply automatically" is unchecked.', 'automatic-coupons-for-woocommerce' ),
					'id'              => 'acwc_remove_auto_coupons',
					'default'         => 'no',
					'type'            => 'checkbox',
					'checkboxgroup'   => '',
					'show_if_checked' => 'yes',
				);
			}
		}

		return $updated_settings;
	}

	/**
	 * Add a checkbox to the coupon page to make it automatic.
	 *
	 * @param int       $coupon_id Coupon ID.
	 * @param WC_Coupon $coupon Coupon.
	 *
	 * @return void
	 */
	public function woocommerce_coupon_options( $coupon_id, $coupon ): void {
		woocommerce_wp_checkbox(
			array(
				'id'          => 'discount_autoapply',
				'label'       => __( 'Allow automatic application', 'automatic-coupons-for-woocommerce' ),
				'description' => __( 'Apply this coupon automatically as a discount.', 'automatic-coupons-for-woocommerce' ),
				'value'       => $this->coupon_is_autoapply( $coupon ) ? 'yes' : 'no',
			)
		);
	}

	/**
	 * Save auto coupon options.
	 *
	 * @since 1.0.0
	 *
	 * @param int $coupon_id Coupon ID.
	 *
	 * @return void
	 */
	public function woocommerce_coupon_options_save( int $coupon_id ): void {
		if (
			! check_ajax_referer( 'woocommerce_save_data', 'woocommerce_meta_nonce', false ) ||
			! current_user_can( 'edit_post', $coupon_id )
		) {
			return;
		}

		update_post_meta(
			$coupon_id,
			'_acwc_discount_autoapply',
			filter_input(
				INPUT_POST,
				'discount_autoapply',
				FILTER_VALIDATE_BOOLEAN,
				array( 'default' => false )
			)
		);

		$this->invalidate_automated_coupons_cache();
	}

	/**
	 * Returns the subtotal for a cart item adding a discount label.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $subtotal Subtotal.
	 * @param array<string, float> $cart_item Cart item.
	 * @param string               $cart_item_key Cart item key.
	 *
	 * @return string
	 */
	public function woocommerce_cart_item_subtotal( string $subtotal, array $cart_item, string $cart_item_key ): string {
		$line_subtotal = (float) $cart_item['line_subtotal'];
		$line_total    = (float) $cart_item['line_total'];

		if (
			empty( $this->acwc_applied_coupons[ $cart_item_key ] ) &&
			wc_format_decimal( $line_subtotal, wc_get_price_decimals() ) === wc_format_decimal( $line_total, wc_get_price_decimals() )
		) {
			return $subtotal;
		}

		/**
		* Cart item data.
		*
		* @var array{ line_tax: float, line_subtotal_tax: float, data: \WC_Product, quantity: int, ... } $cart_item */
		if ( ! WC()->cart->get_customer()->get_is_vat_exempt() && $cart_item['data']->is_taxable() && WC()->cart->display_prices_including_tax() ) {
			$line_subtotal += (float) $cart_item['line_subtotal_tax'];
			$line_total    += (float) $cart_item['line_tax'];
		}

		$subtotal = '<del style="color:red">' . wc_price( $line_subtotal ) . '</del><div>' . wc_price( $line_total ) . '</div>';

		/**
		 * Item total row.
		 *
		 * @since 1.2.0
		 */
		return apply_filters( 'acwc_cart_item_subtotal', $subtotal, $line_subtotal, $line_subtotal - $line_total );
	}

	/**
	 * Coupon Label on Cart Page.
	 *
	 * @since 1.0.0
	 *
	 * @param string    $label Label to display.
	 * @param WC_Coupon $coupon Coupon Object.
	 *
	 * @return string
	 */
	public function woocommerce_cart_totals_coupon_label( $label, $coupon ) {

		if ( $this->coupon_is_autoapply( $coupon ) ) {
			/* translators: %s: Coupon code */
			$label = sprintf( __( 'Applied Discount: %s', 'automatic-coupons-for-woocommerce' ), $coupon->get_code() );
		}

		return $label;
	}

	/**
	 * Html Label on Cart totals.
	 *
	 * @since 1.0.0
	 *
	 * @param string    $coupon_html Html to display.
	 * @param WC_Coupon $coupon Coupon Object.
	 * @param string    $discount_amount_html Discounted amount to display.
	 *
	 * @return string
	 */
	public function woocommerce_cart_totals_coupon_html( $coupon_html, $coupon, $discount_amount_html ) {
		if ( $this->coupon_is_autoapply( $coupon ) ) {
			$coupon_html = $discount_amount_html;
		}

		return $coupon_html;
	}

	/**
	 * Error Codes:
	 * - 100: Invalid filtered.
	 * - 101: Invalid removed.
	 * - 102: Not yours removed.
	 * - 103: Already applied.
	 * - 104: Individual use only.
	 * - 105: Not exists.
	 * - 106: Usage limit reached.
	 * - 107: Expired.
	 * - 108: Minimum spend limit not met.
	 * - 109: Not applicable.
	 * - 110: Not valid for sale items.
	 * - 111: Missing coupon code.
	 * - 112: Maximum spend limit met.
	 * - 113: Excluded products.
	 * - 114: Excluded categories.
	 * - 115: Usage limit stuck.
	 * - 116: Guest usage limit stuck.
	 *
	 * @param  string    $error_message Message.
	 * @param  int       $error_code Code.
	 * @param  WC_Coupon $coupon Coupon.
	 * @return string
	 */
	public function woocommerce_coupon_error( $error_message, $error_code, $coupon ) {
		/**
		 * Ignore errors we don't want to show on auto coupons application.
		 */
		if ( $this->coupon_is_autoapply( $coupon ) ) {
			$error_message = sprintf(
				/* translators: %s: coupon code */
				esc_html__( 'Sorry, it seems the discount "%s" is no longer valid and has been removed from your order.', 'automatic-coupons-for-woocommerce' ),
				esc_html( strval( $error_code ) )
			);

			switch ( $error_code ) {
				case 100:
				case 101:
				case WC_Coupon::E_WC_COUPON_NOT_YOURS_REMOVED:
					wc_add_notice( $error_message, 'error' );
					break;
				case 103:
				case WC_Coupon::E_WC_COUPON_ALREADY_APPLIED_INDIV_USE_ONLY:
					wc_add_notice( $error_message, 'error' );
					break;
				case 105:
				case 106:
				case 107:
				case 108:
				case 109:
				case 110:
				case 111:
				case 112:
				case 113:
				case 114:
				case 115:
				case 116:
					$error_message = '';
					break;
			}
		}

		return $error_message;
	}

	/**
	 * Error Codes:
	 * - 200: Applied.
	 * - 201: Removed.
	 *
	 * @param  string    $message Message.
	 * @param  int       $message_code Code.
	 * @param  WC_Coupon $coupon Coupon.
	 * @return string
	 */
	public function woocommerce_coupon_message( $message, $message_code, $coupon ): string {
		if ( $this->coupon_is_autoapply( $coupon ) ) {
			switch ( $message_code ) {
				case WC_Coupon::WC_COUPON_REMOVED:
				case WC_Coupon::WC_COUPON_SUCCESS:
					$message = '';
					break;
			}
		}

		return $message;
	}

	/**
	 * Bulk handler to mark/unmark coupons as automatic.
	 *
	 * @param string $redirect_to URL to redirect after bulk delete action completes.
	 * @param string $action Bulk delete action.
	 * @param int[]  $post_ids Post IDs to apply bulk delete action.
	 * @return mixed|string
	 */
	public function handle_bulk_actions_edit_shop_coupon( string $redirect_to, string $action, array $post_ids ) {
		if ( ! in_array( $action, self::$acwc_bulk_actions, true ) ) {
			return $redirect_to;
		}

		$discount_autoapply = ( 'acwc_mark_auto' === $action ) ? 1 : 0;

		$processed = 0;

		foreach ( $post_ids as $post_id ) {
			if ( 'shop_coupon' === get_post_type( $post_id ) && current_user_can( 'edit_post', $post_id ) ) {
				if ( update_post_meta( $post_id, '_acwc_discount_autoapply', $discount_autoapply ) ) {
					++$processed;
				}
			}
		}

		if ( $processed ) {
			$this->invalidate_automated_coupons_cache();

			$redirect_to = add_query_arg(
				array(
					'bulk_action' => $action,
					'changed'     => $processed,
				),
				$redirect_to
			);
		}

		return esc_url_raw( $redirect_to );
	}

	/**
	 * Display admin noticies for bulk delete actions.
	 *
	 * @return void
	 */
	public function admin_notices(): void {
		global $post_type, $pagenow;

		if ( ! isset( $GLOBALS['post'] ) || ! function_exists( 'get_current_screen' ) || 'edit.php' !== $pagenow || 'shop_coupon' !== $post_type ) {
			return;
		}

		$bulk_action = filter_input( INPUT_GET, 'bulk_action', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		if ( ! in_array( $bulk_action, self::$acwc_bulk_actions, true ) ) {
			return;
		}

		$bulk_changed = filter_input( INPUT_GET, 'changed', FILTER_VALIDATE_INT );

		if ( ! $bulk_changed ) {
			return;
		}

		$bulk_messages = array(
			/* translators: %s: coupon count */
			'acwc_mark_auto'   => _n( '%s coupon marked as automatic.', '%s coupons marked as automatic.', $bulk_changed, 'automatic-coupons-for-woocommerce' ),
			/* translators: %s: coupon count */
			'acwc_unmark_auto' => _n( '%s coupon unmarked as automatic.', '%s coupons unmarked as automatic.', $bulk_changed, 'automatic-coupons-for-woocommerce' ),
		);

		wp_admin_notice(
			sprintf( $bulk_messages[ $bulk_action ], number_format_i18n( $bulk_changed ) ),
			array(
				'id'                 => 'message',
				'additional_classes' => array( 'updated' ),
				'dismissible'        => true,
			)
		);
	}

	/**
	 * Apply automatic coupons to WC_Cart.
	 *
	 * @param WC_Cart $cart Cart to apply copupons.
	 *
	 * @return void
	 */
	private function apply_coupons( WC_Cart $cart ): void {
		$this->acwc_applied_coupons = array();

		$coupon_noticies      = array();
		$coupons_is_cart_page = is_cart();

		foreach ( $this->acwc_available_coupons as $coupon_id ) {
			$coupon      = new WC_Coupon( $coupon_id );
			$coupon_code = $coupon->get_code();

			/** Remove all the auto coupons to prevent updated or previously applied coupons. */
			$cart->remove_coupon( $coupon_code );

			if ( $cart->add_discount( $coupon_code ) !== true ) {
				continue;
			}

			$discount_product = false;
			$discount_symbol  = '%';

			switch ( $coupon->get_discount_type() ) {
				case 'percent':
					$discount_product = true;
					break;
				case 'fixed_product':
					$discount_product = true;
					// Not a product discount but share same symbol.
				case 'fixed_cart':
					$discount_symbol = get_woocommerce_currency_symbol();
			}

			if ( $coupon->is_valid_for_cart() ) {
				if ( true === $coupons_is_cart_page ) {
					// translators: Text to show when cart coupons are applied to cart, %1$s can be amount or percentage quantity.
					$coupon_noticies[] = sprintf( __( 'A %1$s discount has been applied to the cart.', 'automatic-coupons-for-woocommerce' ), $coupon->get_amount() . $discount_symbol );
				}

				continue;
			}

			if ( $discount_product ) {
				/**
				 * Cart item data.
				 *
				 * @var array{data: \WC_Product, quantity: int, ...} $cart_item */
				foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
					if ( $coupon->is_valid_for_product( $cart_item['data'] ) ) {
						$this->acwc_applied_coupons[ $cart_item_key ][ $coupon_id ] = true;

						if ( true === $coupons_is_cart_page ) {
							// translators: Text to show when product coupons are applied to products, %1$s can be amount or percentage quantity and %2$s the name of the product.
							$coupon_noticies[] = sprintf( __( 'A %1$s discount has been applied to the following product %2$s.', 'automatic-coupons-for-woocommerce' ), $coupon->get_amount() . $discount_symbol, $cart_item['data']->get_name() );
						}
					}
				}
			}
		}

		array_walk(
			$coupon_noticies,
			function ( $notice ) {
				wc_add_notice( $notice, 'notice' );
			}
		);
	}

	/**
	 * Wrapper to mark products as discounted in cart.
	 *
	 * @param WC_Cart $cart Cart Object.
	 *
	 * @return void
	 */
	public function woocommerce_after_calculate_totals( WC_Cart $cart ): void {

		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( did_action( 'woocommerce_after_calculate_totals' ) >= 2 ) {
			return;
		}

		$this->apply_coupons( $cart );
	}

	/**
	 * Apply coupons after checkout.
	 *
	 * @return void
	 */
	public function woocommerce_after_checkout_validation(): void {
		$this->apply_coupons( WC()->cart );
	}

	/**
	 * Delete ACWC meta data.
	 *
	 * @return void
	 */
	public static function delete_meta(): void {
		global $wpdb;

		/**
		 * Wpdb class
		 *
		 * @var wpdb $wpdb */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$meta_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT meta_id FROM %i WHERE meta_key LIKE %s',
				$wpdb->postmeta,
				$wpdb->esc_like( '_acwc' ) . '%'
			)
		);

		/**
		 * Meta ID to delete.
		 *
		 * @var int $meta_id */
		foreach ( $meta_ids as $meta_id ) {
			delete_metadata_by_mid( 'post', absint( $meta_id ) );
		}
	}

	/**
	 * Delete plugin options.
	 *
	 * @return void
	 */
	private static function delete_options(): void {
		array_walk( self::$acwc_options, 'delete_option' );
	}

	/**
	 * Main AutoCoupons Instance.
	 *
	 * Ensures only one instance of AutoCoupons is loaded or can be loaded.
	 *
	 * @since 1.0.0
	 *
	 * @return AutoCoupons - Main instance.
	 */
	public static function instance(): AutoCoupons {
		if ( is_null( self::$acwc_instance ) ) {
			self::$acwc_instance = new self();
		}

		return self::$acwc_instance;
	}

	/**
	 * Activate plugin.
	 *
	 * @return void
	 */
	public static function activate(): void {}

	/**
	 * Deactivate plugin.
	 *
	 * @return void
	 */
	public static function deactivate(): void {}

	/**
	 * Uninstall plugin.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		self::delete_meta();
		self::delete_options();
	}
}

register_activation_hook(
	ACWC_PLUGIN_FILE,
	array( AutoCoupons::class, 'activate' )
);

register_deactivation_hook(
	ACWC_PLUGIN_FILE,
	array( AutoCoupons::class, 'deactivate' )
);

register_uninstall_hook(
	ACWC_PLUGIN_FILE,
	array( AutoCoupons::class, 'uninstall' )
);
