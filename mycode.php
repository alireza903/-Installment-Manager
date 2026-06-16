
<?php
/**
 * Plugin Name: AhmadMarket Installment Manager (Pro)
 * Description: نسخه ۴.۷.۰ - حل مشکل نمایش تگ‌های HTML در فاکتور + استایل‌دهی استاندارد CSS برای قرمز شدن ردیف کارمزد
 * Version: 4.7.0
 * Author: AhmadMarket
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ahmad_Installment_Manager {

    private static $instance = null;

    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // پنل مدیریت
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        
        // رابط کاربری
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_assets' ) );
        add_action( 'woocommerce_review_order_before_payment', array( $this, 'render_payment_type_selector' ) );
        
        // پردازش ایجکس تغییر حالت
        add_action( 'wp_ajax_ahmad_toggle_payment_mode', array( $this, 'ajax_toggle_payment_mode' ) );
        add_action( 'wp_ajax_nopriv_ahmad_toggle_payment_mode', array( $this, 'ajax_toggle_payment_mode' ) );

        // سیستم خنثی‌کننده باگ افزونه اسنپ‌پی
        add_action( 'wp_loaded', array( $this, 'nuke_snapppay_bug' ), PHP_INT_MAX );
        add_action( 'wc_ajax_update_order_review', array( $this, 'nuke_snapppay_bug' ), 1 );

        // فیلتر درگاه‌ها
        add_filter( 'woocommerce_available_payment_gateways', array( $this, 'filter_gateways_by_mode' ), PHP_INT_MAX );
        
        // مجبور کردن ووکامرس به محاسبه مجدد با تغییر درگاه
        add_action( 'woocommerce_checkout_update_order_review', array( $this, 'update_on_gateway_change' ) );
        
        // اضافه کردن ردیف کارمزد
        add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_installment_fee_as_row' ) );

        // ذخیره و نمایش
        add_action( 'woocommerce_checkout_create_order', array( $this, 'save_data_to_order_meta' ), 10, 2 );
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_meta_in_admin_order' ) );
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'send_bale_notification' ), 10, 3 );
    }

    public function nuke_snapppay_bug() {
        if ( ! WC()->session ) return;
        $mode = WC()->session->get( 'aim_payment_mode', 'cash' );
        if ( $mode === 'cash' ) {
            global $wp_filter;
            $hook_name = 'woocommerce_available_payment_gateways';
            if ( isset( $wp_filter[$hook_name] ) ) {
                foreach ( $wp_filter[$hook_name]->callbacks as $priority => $callbacks ) {
                    foreach ( $callbacks as $idx => $callback ) {
                        if ( strpos( $idx, 'show_snapppay' ) !== false ) {
                            unset( $wp_filter[$hook_name]->callbacks[$priority][$idx] );
                        }
                    }
                }
            }
        }
    }

    public function register_admin_menu() {
        add_menu_page( 'تنظیمات اقساط', 'نقد و اقساط', 'manage_options', 'ahmad-installment-settings', array( $this, 'render_settings_page' ), 'dashicons-chart-pie', 56 );
    }

    public function render_settings_page() {
        if ( isset( $_POST['ahmad_save_settings'] ) ) {
            update_option( 'aim_digipay_rate', floatval( $_POST['digipay_rate'] ) );
            update_option( 'aim_nopay_rate', floatval( $_POST['nopay_rate'] ) );
            update_option( 'aim_snapppay_rate', floatval( $_POST['snapppay_rate'] ) );
            update_option( 'aim_bale_token', sanitize_text_field( $_POST['bale_token'] ) );
            update_option( 'aim_bale_chat_id', sanitize_text_field( $_POST['bale_chat_id'] ) );
            echo '<div class="notice notice-success"><p>تنظیمات با موفقیت ذخیره شد.</p></div>';
        }

        $rates = array(
            'digipay'  => get_option( 'aim_digipay_rate', 10 ),
            'nopay'    => get_option( 'aim_nopay_rate', 11 ),
            'snapppay' => get_option( 'aim_snapppay_rate', 12 )
        );
        $bale_token   = get_option( 'aim_bale_token', '' );
        $bale_chat_id = get_option( 'aim_bale_chat_id', '' );

        ?>
        <div class="wrap" dir="rtl">
            <h1 style="margin-bottom:20px;">⚙️ تنظیمات سیستم اقساط احمدمارکت</h1>
            <form method="post">
                <table class="form-table" style="background:#fff; padding:20px; border-radius:8px;">
                    <tr><th>درصد افزایش دیجی‌پی (%)</th><td><input name="digipay_rate" type="number" step="0.1" value="<?php echo esc_attr( $rates['digipay'] ); ?>"></td></tr>
                    <tr><th>درصد افزایش نوپی (%)</th><td><input name="nopay_rate" type="number" step="0.1" value="<?php echo esc_attr( $rates['nopay'] ); ?>"></td></tr>
                    <tr><th>درصد افزایش اسنپ‌پی (%)</th><td><input name="snapppay_rate" type="number" step="0.1" value="<?php echo esc_attr( $rates['snapppay'] ); ?>"></td></tr>
                    <tr><th>توکن ربات بله</th><td><input name="bale_token" type="password" style="width:300px;" value="<?php echo esc_attr( $bale_token ); ?>"></td></tr>
                    <tr><th>شناسه چت بله</th><td><input name="bale_chat_id" type="text" style="width:300px;" value="<?php echo esc_attr( $bale_chat_id ); ?>"></td></tr>
                </table>
                <p><input type="submit" name="ahmad_save_settings" class="button button-primary button-large" value="💾 ذخیره تنظیمات"></p>
            </form>
        </div>
        <?php
    }

    public function enqueue_checkout_assets() {
        if ( is_checkout() && ! is_order_received_page() ) {
            wp_register_script( 'ahmad-checkout-js', '', [], '', true );
            wp_enqueue_script( 'ahmad-checkout-js' );
            wp_add_inline_script( 'ahmad-checkout-js', "
                jQuery(document).ready(function($) {
                    // اجبار ووکامرس برای آپدیت فاکتور با تغییر درگاه
                    $(document.body).on('change', 'input[name=\"payment_method\"]', function() {
                        $('body').trigger('update_checkout');
                    });

                    // تغییر وضعیت نقدی و اقساطی
                    $(document.body).on('click', '.aim-switch-btn', function(e) {
                        e.preventDefault();
                        var btn = $(this);
                        if(btn.hasClass('active')) return;

                        $('.aim-switch-btn').prop('disabled', true).css('opacity', '0.6');
                        btn.text('کمی صبر کنید...');

                        $.ajax({
                            url: wc_checkout_params.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'ahmad_toggle_payment_mode',
                                mode: btn.data('mode')
                            },
                            success: function() {
                                location.reload(); 
                            }
                        });
                    });
                });
            " );

            wp_register_style( 'ahmad-checkout-css', false );
            wp_enqueue_style( 'ahmad-checkout-css' );
            wp_add_inline_style( 'ahmad-checkout-css', "
                .aim-switch-btn.active[data-mode='cash'] { background: #4caf50 !important; color: #fff !important; border-color: #4caf50 !important; }
                .aim-switch-btn.active[data-mode='installment'] { background: #2196f3 !important; color: #fff !important; border-color: #2196f3 !important; }
                .aim-switch-btn { background: #fff; color: #333; flex:1; padding:10px; border-radius:8px; border:1px solid #ccc; cursor:pointer; font-weight:bold; font-family:inherit; }
                /* قرمز کردن ردیف هزینه‌های جانبی در جدول فاکتور ووکامرس */
                .woocommerce-checkout-review-order-table tr.fee th, 
                .woocommerce-checkout-review-order-table tr.fee td,
                .shop_table tr.fee th, 
                .shop_table tr.fee td { 
                    color: #d32f2f !important; 
                    font-weight: bold !important; 
                }
            " );
        }
    }

    public function render_payment_type_selector() {
        if ( ! WC()->session ) return;
        $current_mode = WC()->session->get( 'aim_payment_mode', 'cash' );
        ?>
        <div style="margin: 15px 0; padding: 15px; border: 2px solid #e0e0e0; border-radius: 12px; background: #fdfdfd;">
            <h4 style="margin-top:0; font-size:15px; text-align:center; margin-bottom:12px;">نحوه پرداخت نهایی:</h4>
            <div style="display:flex; gap:10px;">
                <button type="button" class="aim-switch-btn <?php echo $current_mode === 'cash' ? 'active' : ''; ?>" data-mode="cash">خرید نقدی</button>
                <button type="button" class="aim-switch-btn <?php echo $current_mode === 'installment' ? 'active' : ''; ?>" data-mode="installment">خرید اقساطی</button>
            </div>
        </div>
        <?php
    }

    public function ajax_toggle_payment_mode() {
        if ( isset( $_POST['mode'] ) ) {
            WC()->session->set( 'aim_payment_mode', sanitize_text_field( $_POST['mode'] ) );
        }
        wp_send_json_success();
    }

    public function update_on_gateway_change( $post_data ) {
        parse_str( $post_data, $data );
        if ( isset( $data['payment_method'] ) && WC()->session ) {
            WC()->session->set( 'chosen_payment_method', sanitize_text_field( $data['payment_method'] ) );
            WC()->cart->calculate_totals(); 
        }
    }

    public function apply_installment_fee_as_row( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        if ( ! WC()->session ) return;

        $mode = WC()->session->get( 'aim_payment_mode', 'cash' );
        if ( $mode !== 'installment' ) return;

        $chosen_gateway = WC()->session->get( 'chosen_payment_method' );
        
        if ( isset( $_POST['post_data'] ) ) {
            parse_str( wp_unslash( $_POST['post_data'] ), $post_data );
            if ( isset( $post_data['payment_method'] ) ) {
                $chosen_gateway = sanitize_text_field( $post_data['payment_method'] );
            }
        } elseif ( isset( $_POST['payment_method'] ) ) {
            $chosen_gateway = sanitize_text_field( wp_unslash( $_POST['payment_method'] ) );
        }

        $percent = 0; 
        $gateway_name = '';
        
        if ( $chosen_gateway === 'WCDigiPay' ) { 
            $percent = floatval( get_option( 'aim_digipay_rate', 10 ) ); 
            $gateway_name = 'دیجی‌پی'; 
        } elseif ( $chosen_gateway === 'WC_nopay_cpg' ) { 
            $percent = floatval( get_option( 'aim_nopay_rate', 11 ) ); 
            $gateway_name = 'نوپی'; 
        } elseif ( $chosen_gateway === 'WC_Gateway_SnappPay' ) { 
            $percent = floatval( get_option( 'aim_snapppay_rate', 12 ) ); 
            $gateway_name = 'اسنپ‌پی'; 
        }

        if ( $percent > 0 ) {
            $increase_amount = 0;
            foreach ( $cart->get_cart() as $item ) {
                $price = $item['data']->get_price();
                $increase_amount += ( floatval( $price ) * ( $percent / 100 ) ) * intval( $item['quantity'] );
            }

            if ( $increase_amount > 0 ) {
                // 🟢 تگ‌های HTML حذف شدند و به جای آن یک ایموجی قرار گرفت. قرمز شدن ردیف هم توسط CSS بالا انجام می‌شود.
                $fee_label = sprintf( '🔴 افزایش قیمت اقساط %s (%s%%)', $gateway_name, $percent );
                
                $cart->add_fee( $fee_label, $increase_amount, false );
            }
        }
    }

    public function filter_gateways_by_mode( $gateways ) {
        if ( is_admin() || ! is_checkout() || ! WC()->session ) return $gateways;

        $mode = WC()->session->get( 'aim_payment_mode', 'cash' );
        $installment_ids = array( 'WCDigiPay', 'WC_nopay_cpg', 'WC_Gateway_SnappPay' );

        if ( $mode === 'installment' ) {
            foreach ( $gateways as $id => $gateway ) {
                if ( ! in_array( $id, $installment_ids ) ) unset( $gateways[$id] );
            }
        } else {
            foreach ( $gateways as $id => $gateway ) {
                if ( in_array( $id, $installment_ids ) ) unset( $gateways[$id] );
            }
        }
        return $gateways;
    }

    public function save_data_to_order_meta( $order, $data ) {
        if ( ! WC()->session ) return;
        $mode = WC()->session->get( 'aim_payment_mode', 'cash' );
        $chosen_gateway = $order->get_payment_method();
        
        $percent = 0;
        if ( $mode === 'installment' ) {
            if ( $chosen_gateway === 'WCDigiPay' ) $percent = get_option( 'aim_digipay_rate', 10 );
            elseif ( $chosen_gateway === 'WC_nopay_cpg' ) $percent = get_option( 'aim_nopay_rate', 11 );
            elseif ( $chosen_gateway === 'WC_Gateway_SnappPay' ) $percent = get_option( 'aim_snapppay_rate', 12 );
        }

        $label = ( $mode === 'installment' ) ? "اقساطی (+{$percent}%)" : 'نقدی';
        $order->update_meta_data( '_purchase_mode_label', $label );
        $order->update_meta_data( '_installment_percentage', $percent );
    }

    public function display_meta_in_admin_order( $order ) {
        $mode    = $order->get_meta( '_purchase_mode_label' );
        $percent = $order->get_meta( '_installment_percentage' );

        if ( $mode ) echo '<p><strong>نوع پرداخت نهایی:</strong> ' . esc_html( $mode ) . '</p>';
        if ( $percent ) echo '<p><strong>درصد افزایش اقساط:</strong> ' . esc_html( $percent ) . '%</p>';
    }

    public function send_bale_notification( $order_id, $posted_data, $order ) {
        $token   = get_option( 'aim_bale_token', '' );
        $chat_id = get_option( 'aim_bale_chat_id', '' );
        if ( empty( $token ) || empty( $chat_id ) ) return;

        $payment_label = $order->get_meta( '_purchase_mode_label' ) ?: 'نقدی';
        $total         = wc_price( $order->get_total(), array('currency' => 'IRT') );

        $items = '';
        foreach ( $order->get_items() as $item ) {
            $items .= "🔹 " . $item->get_name() . " (تعداد: " . $item->get_quantity() . ")\n";
        }

        $message = "🛒 *سفارش جدید: #{$order_id}*\n━━━━━━━━━━━━━━━━\n👤 نام: " . $order->get_billing_first_name() . " " . $order->get_billing_last_name() . "\n📞 موبایل: " . $order->get_billing_phone() . "\n💳 نوع: *{$payment_label}*\n💰 مبلغ کل: " . strip_tags($total) . "\n━━━━━━━━━━━━━━━━\n📦 *محصولات:*\n" . $items;

        wp_remote_post( "https://tapi.bale.ai/bot{$token}/sendMessage", array(
            'body'    => wp_json_encode( array( 'chat_id' => $chat_id, 'text' => $message, 'parse_mode' => 'Markdown' ) ),
            'headers' => array( 'Content-Type' => 'application/json' ),
            'timeout' => 15,
        ) );
    }
}

Ahmad_Installment_Manager::get_instance();
