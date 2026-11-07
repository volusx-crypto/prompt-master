<?php
/**
 * Recipes: e-commerce.
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

return array(
	array(
		'id'           => 'woocommerce',
		'name'         => 'WooCommerce',
		'family'       => 'ecommerce',
		'priority'     => 100,
		'plugins'      => array( 'woocommerce/woocommerce.php' ),
		'detect'       => array( 'class' => 'WooCommerce', 'constant' => 'WC_VERSION' ),
		'capabilities' => array( 'ecommerce' => 100 ),
		'note'         => 'وجود ووکامرس، پروفایل کل سایت را به «فروشگاهی» تغییر می‌دهد: صفحات سبد/تسویه از کش و ایندکس خارج می‌شوند و پشتیبان‌گیری دیتابیس روزانه پیشنهاد می‌شود.',
		'rules'        => array(
			array(
				'id'     => 'wc_cart_redirect',
				'label'  => 'نرفتن خودکار به سبد خرید پس از افزودن محصول',
				'option' => 'woocommerce_cart_redirect_after_add',
				'value'  => 'no',
				'risk'   => 'low',
			),
			array(
				'id'     => 'wc_ajax_add_to_cart',
				'label'  => 'فعال‌کردن افزودن آجاکسی به سبد خرید',
				'option' => 'woocommerce_enable_ajax_add_to_cart',
				'value'  => 'yes',
				'risk'   => 'low',
			),
			array(
				'id'     => 'wc_guest_checkout',
				'label'  => 'اجازهٔ خرید بدون ساخت حساب کاربری (کاهش رهاکردن سبد)',
				'option' => 'woocommerce_enable_guest_checkout',
				'value'  => 'yes',
				'risk'   => 'medium',
			),
		),
	),
);
