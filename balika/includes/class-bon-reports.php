<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * کلاس گزارش‌ها - تولید متن انواع گزارش فروش، پرفروش‌ترین محصولات، موجودی و سفارشات معلق
 * استفاده مشترک توسط: کرون خودکار، دکمه ارسال دستی در پنل، و دکمه‌های شیشه‌ای داخل بله
 */
class Bale_Order_Notifier_Reports {

    /**
     * لیست انواع گزارش قابل پشتیبانی
     */
    public static function get_report_types() {
        return [
            'today'          => '📊 گزارش فروش امروز',
            'last7days'      => '📈 گزارش فروش ۷ روز اخیر',
            'this_week'      => '🗓 گزارش فروش هفته جاری',
            'last30days'     => '📉 گزارش فروش ۳۰ روز اخیر',
            'this_month'     => '📆 گزارش فروش ماه جاری',
            'top_products'   => '🏆 پرفروش‌ترین محصولات',
            'out_of_stock'   => '🚫 محصولات ناموجود',
            'low_stock'      => '⚠️ هشدار موجودی کم',
            'pending_orders' => '⏳ سفارشات در انتظار پرداخت',
        ];
    }

    /**
     * تولید متن گزارش بر اساس نوع درخواستی
     */
    public static function generate( $type ) {
        $now = time();
        switch ( $type ) {
            case 'today':
                return self::sales_report( ( new DateTimeImmutable( 'today', wp_timezone() ) )->getTimestamp(), $now, '📊 گزارش فروش امروز' );
            case 'last7days':
                return self::sales_report( ( new DateTimeImmutable( '-7 days', wp_timezone() ) )->getTimestamp(), $now, '📈 گزارش فروش ۷ روز اخیر' );
            case 'this_week':
                return self::sales_report( bon_week_start_timestamp(), $now, '🗓 گزارش فروش هفته جاری' );
            case 'last30days':
                return self::sales_report( ( new DateTimeImmutable( '-30 days', wp_timezone() ) )->getTimestamp(), $now, '📉 گزارش فروش ۳۰ روز اخیر' );
            case 'this_month':
                return self::sales_report( ( new DateTimeImmutable( 'first day of this month midnight', wp_timezone() ) )->getTimestamp(), $now, '📆 گزارش فروش ماه جاری' );
            case 'top_products':
                return self::top_products_report();
            case 'out_of_stock':
                return self::stock_report( 'out_of_stock' );
            case 'low_stock':
                return self::stock_report( 'low_stock' );
            case 'pending_orders':
                return self::pending_orders_report();
            default:
                return 'نوع گزارش نامعتبر است.';
        }
    }

    /**
     * گزارش فروش برای یک بازه زمانی مشخص
     * نکته مهم: همیشه یک بازه بسته (from...to) به wc_get_orders داده می‌شود، نه یک مقایسه باز با >=،
     * چون WC_Order_Query در برخی نسخه‌ها عملگر >= را برای date_created به‌درستی تفسیر نمی‌کند
     * و نتیجه آن گزارش خالی (مثلاً «فروش امروز» که چیزی نشان نمی‌دهد) خواهد بود.
     */
    public static function range_bounds($from, $to) {
        if (!is_string($from) || !is_string($to) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$from) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$to) || !checkdate((int)substr($from,5,2),(int)substr($from,8,2),(int)substr($from,0,4)) || !checkdate((int)substr($to,5,2),(int)substr($to,8,2),(int)substr($to,0,4))) return new WP_Error('dates', 'بازه تاریخ نامعتبر است.');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from, wp_timezone());
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $to, wp_timezone());
        if (!$start || !$end || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to || $end < $start || $start->diff($end)->days >= 366) {
            return new WP_Error('dates', 'بازه معتبر با حداکثر ۳۶۶ روز انتخاب کنید.');
        }
        return [$start->getTimestamp(), $end->modify('+1 day')->getTimestamp() - 1];
    }
    public static function range_report($bounds) {
        return self::sales_report($bounds[0], $bounds[1], 'گزارش بازه انتخابی (ناخالص؛ شامل در انتظار بررسی، در حال انجام و تکمیل‌شده)');
    }
    public static function range_orders($bounds) {
        return self::order_batches(['type' => 'shop_order', 'status' => ['processing', 'completed', 'on-hold'], 'date_created' => $bounds[0] . '...' . $bounds[1]]);
    }
    public static function csv_cell($value) {
        $value = (string) $value;
        return preg_match('/^[\s]*[=+@-]/u', $value) ? "'" . $value : $value;
    }

    private static function order_batches( $args ) {
        $args['limit'] = 100;
        $args['orderby'] = 'ID';
        $args['order'] = 'ASC';
        $args['page'] = 1;
        do {
            $batch = wc_get_orders( $args );
            foreach ( $batch as $order ) yield $order;
            $args['page']++;
        } while ( count( $batch ) === 100 );
    }

    private static function sales_report( $from_ts, $to_ts, $title ) {
        $args = [
            'limit'  => -1,
            'status' => [ 'processing', 'completed', 'on-hold' ],
            'type'   => 'shop_order',
        ];
        $to_ts                 = $to_ts ?: time();
        $args['date_created']  = "{$from_ts}...{$to_ts}";

        $orders = self::order_batches( $args );

        $total_orders = 0;
        $total_sales  = 0;
        $currency_totals = [];
        $product_qty  = [];

        foreach ( $orders as $order ) {
            $total_orders++;
            $total_sales += (float) $order->get_total();
            $currency_key = $order->get_currency();
            if (!isset($currency_totals[$currency_key])) $currency_totals[$currency_key] = ['sum' => 0, 'count' => 0];
            $currency_totals[$currency_key]['sum'] += (float) $order->get_total();
            $currency_totals[$currency_key]['count']++;
            foreach ( $order->get_items() as $item ) {
                $name = $item->get_name();
                $product_qty[ $name ] = ( $product_qty[ $name ] ?? 0 ) + $item->get_quantity();
            }
        }

        arsort( $product_qty );
        $top_products = array_slice( $product_qty, 0, 5, true );

        $message  = "{$title}\n\n";
        $message .= "🧾 تعداد سفارش‌ها: {$total_orders}\n";
        foreach ($currency_totals as $code => $totals) {
            $message .= '💰 مجموع فروش: ' . bon_money($totals['sum'], $code) . "\n";
            $message .= '📌 میانگین سفارش: ' . bon_money($totals['sum'] / $totals['count'], $code) . "\n";
        }
        $message .= "\n";

        if ( ! empty( $top_products ) ) {
            $message .= "🏆 پرفروش‌ترین محصولات این بازه:\n";
            $i = 1;
            foreach ( $top_products as $name => $qty ) {
                $message .= "{$i}. {$name} — {$qty} عدد\n";
                $i++;
            }
        } else {
            $message .= 'در این بازه سفارشی ثبت نشده است.';
        }

        return $message;
    }

    /**
     * گزارش پرفروش‌ترین محصولات در ۳۰ روز اخیر (مستقل از بازه فروش)
     */
    private static function top_products_report() {
        $orders = self::order_batches( [
            'limit'        => -1,
            'status'       => [ 'processing', 'completed' ],
            'type'         => 'shop_order',
            'date_created' => ( new DateTimeImmutable( '-30 days', wp_timezone() ) )->getTimestamp() . '...' . time(),
        ] );

        $product_qty = [];
        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                $name = $item->get_name();
                $product_qty[ $name ] = ( $product_qty[ $name ] ?? 0 ) + $item->get_quantity();
            }
        }
        arsort( $product_qty );
        $top = array_slice( $product_qty, 0, 10, true );

        $message = "🏆 پرفروش‌ترین محصولات (۳۰ روز اخیر)\n\n";
        if ( empty( $top ) ) {
            $message .= 'داده‌ای برای نمایش وجود ندارد.';
            return $message;
        }
        $i = 1;
        foreach ( $top as $name => $qty ) {
            $message .= "{$i}. {$name} — {$qty} عدد فروش\n";
            $i++;
        }
        return $message;
    }

    /**
     * گزارش موجودی: محصولات ناموجود یا دارای موجودی کم
     */
    private static function stock_report( $mode ) {
        $threshold = (int) bon_get_option( 'low_stock_threshold', 3 );

        $args = [
            'status' => 'publish',
            'limit'  => 200,
            'return' => 'objects',
        ];
        if ( $mode === 'out_of_stock' ) {
            $args['stock_status'] = 'outofstock';
        } else {
            $args['manage_stock'] = true;
        }

        $products = function_exists( 'wc_get_products' ) ? wc_get_products( $args ) : [];

        $list = [];
        foreach ( $products as $product ) {
            $qty = $product->get_stock_quantity();
            if ( $mode === 'out_of_stock' ) {
                $list[] = $product->get_name();
            } elseif ( $qty !== null && $qty <= $threshold && $qty > 0 ) {
                $list[] = $product->get_name() . " ({$qty} عدد)";
            }
        }

        $title   = $mode === 'out_of_stock' ? '🚫 محصولات ناموجود' : '⚠️ محصولات با موجودی کم (کمتر یا مساوی ' . $threshold . ')';
        $message = "{$title}\n\n⚠ این فهرست از بررسی حداکثر ۲۰۰ محصول تهیه شده و ممکن است کامل نباشد.\n\n";

        if ( empty( $list ) ) {
            $message .= 'در محصولات بررسی‌شده موردی یافت نشد.';
            return $message;
        }

        $i = 1;
        foreach ( array_slice( $list, 0, 30 ) as $name ) {
            $message .= "{$i}. {$name}\n";
            $i++;
        }
        if ( count( $list ) > 30 ) {
            $message .= "\n... و " . ( count( $list ) - 30 ) . ' محصول دیگر';
        }

        return $message;
    }

    /**
     * گزارش سفارشات در انتظار پرداخت
     */
    private static function pending_orders_report() {
        $orders = wc_get_orders( [
            'limit'  => 50,
            'status' => [ 'pending', 'on-hold' ],
            'type'   => 'shop_order',
        ] );

        $message  = '⏳ سفارشات در انتظار پرداخت (حداکثر ۵۰ سفارش اخیر)' . "\n\n";

        if ( empty( $orders ) ) {
            $message .= 'سفارش در انتظاری وجود ندارد. ✅';
            return $message;
        }

        $total = 0;
        foreach ( $orders as $order ) {
            $name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: 'مهمان';
            $message .= '#' . $order->get_order_number() . " - {$name} - " . bon_money( $order->get_total(), $order->get_currency() ) . "\n";
            $total++;
        }
        $message .= "\nتعداد نمایش‌داده‌شده: {$total} سفارش در انتظار";

        return $message;
    }
}
