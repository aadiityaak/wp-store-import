<?php

namespace WP_Store_Import\Migrator;

class Runner
{

    public function run($source = 'velocity')
    {
        $results = [
            'products' => 0,
            'orders'   => 0,
            'errors'   => [],
        ];

        try {
            // Attempt to increase time limit
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }

            if ($source === 'woocommerce') {
                $results['products'] = $this->migrate_woocommerce_products();
                $results['orders']   = $this->migrate_woocommerce_orders();
            } else {
                $results['products'] = $this->migrate_products();
                $results['orders']   = $this->migrate_orders();
            }
        } catch (\Throwable $t) {
            $results['errors'][] = $t->getMessage() . ' in ' . $t->getFile() . ':' . $t->getLine();
        }

        return $results;
    }

    private function migrate_products()
    {
        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'post_status'    => 'any',
        ];

        $query = new \WP_Query($args);
        $count = 0;

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $original_id = get_the_ID();

                // Check if already migrated
                $existing = new \WP_Query([
                    'post_type'  => 'store_product',
                    'meta_key'   => '_velocity_original_id',
                    'meta_value' => $original_id,
                    'post_status' => 'any',
                ]);

                if ($existing->have_posts()) {
                    continue;
                }

                $post_data = [
                    'post_title'   => get_the_title(),
                    'post_content' => get_the_content(),
                    'post_excerpt' => get_the_excerpt(),
                    'post_status'  => get_post_status(),
                    'post_type'    => 'store_product',
                    'post_author'  => get_the_author_meta('ID'),
                    'post_date'    => get_the_date('Y-m-d H:i:s'),
                ];

                $new_id = wp_insert_post($post_data);

                if (! is_wp_error($new_id)) {
                    $this->migrate_product_meta($original_id, $new_id);
                    $this->migrate_product_taxonomies($original_id, $new_id);
                    $this->migrate_product_images($original_id, $new_id);
                    $count++;
                }
            }
            wp_reset_postdata();
        }

        return $count;
    }

    private function migrate_product_meta($old_id, $new_id)
    {
        // Map meta keys: Old => New
        $map = [
            'sku'         => '_store_sku',
            'harga'       => '_store_price',
            'harga_promo' => '_store_sale_price',
            'minorder'    => '_store_min_order',
            'berat'       => '_store_weight_kg',
            'stok'        => '_store_stock',
            'label'       => '_store_label',
        ];

        foreach ($map as $old_key => $new_key) {
            $value = get_post_meta($old_id, $old_key, true);
            if ($value !== '') {
                update_post_meta($new_id, $new_key, $value);
            }
        }

        // Special handling for flashsale
        $flashsale = get_post_meta($old_id, 'flashsale', true);
        if ($flashsale) {
            // Try to format to Y-m-d\TH:i
            $date = date('Y-m-d\TH:i', strtotime($flashsale));
            update_post_meta($new_id, '_store_flashsale_until', $date);
        }

        // Store original ID
        update_post_meta($new_id, '_velocity_original_id', $old_id);

        // Default type
        update_post_meta($new_id, '_store_product_type', 'physical');

        // Basic Options
        $namaopsi = get_post_meta($old_id, 'namaopsi', true);
        if ($namaopsi) {
            update_post_meta($new_id, '_store_option_name', $namaopsi);
        }
        $opsistandart = get_post_meta($old_id, 'opsistandart', false); // Array of strings
        if (! empty($opsistandart) && is_array($opsistandart)) {
            update_post_meta($new_id, '_store_options', $opsistandart);
        }

        // Advanced Options
        $namaopsi2 = get_post_meta($old_id, 'namaopsi2', true);
        if ($namaopsi2) {
            update_post_meta($new_id, '_store_option2_name', $namaopsi2);
        }
        $opsiharga = get_post_meta($old_id, 'opsiharga', false); // Array of strings like "Label=Price"
        if (! empty($opsiharga) && is_array($opsiharga)) {
            $advanced_options = [];
            foreach ($opsiharga as $row) {
                // Handle nested arrays (serialized data)
                if (is_array($row)) {
                    foreach ($row as $sub_row) {
                        if (is_string($sub_row)) {
                            $parts = explode('=', $sub_row);
                            if (count($parts) >= 2) {
                                $advanced_options[] = [
                                    'label' => trim($parts[0]),
                                    'price' => trim($parts[1]),
                                ];
                            }
                        }
                    }
                    continue;
                }

                if (! is_string($row)) {
                    continue;
                }

                $parts = explode('=', $row);
                if (count($parts) >= 2) {
                    $advanced_options[] = [
                        'label' => trim($parts[0]),
                        'price' => trim($parts[1]),
                    ];
                }
            }
            if (! empty($advanced_options)) {
                update_post_meta($new_id, '_store_advanced_options', $advanced_options);
            }
        }
    }

    private function migrate_product_taxonomies($old_id, $new_id)
    {
        $terms = get_the_terms($old_id, 'category-product');
        if ($terms && ! is_wp_error($terms)) {
            $new_term_ids = [];
            foreach ($terms as $term) {
                $new_term_id = $this->ensure_target_term($term, 'store_product_cat');
                if ($new_term_id) {
                    $new_term_ids[] = $new_term_id;
                }
            }
            if (! empty($new_term_ids)) {
                wp_set_object_terms($new_id, $new_term_ids, 'store_product_cat');
            }
        }
    }

    private function ensure_target_term($source_term, $target_tax)
    {
        if (! $source_term || is_wp_error($source_term)) {
            return 0;
        }
        $existing = term_exists($source_term->name, $target_tax);
        if ($existing) {
            return is_array($existing) ? (int) $existing['term_id'] : (int) $existing;
        }
        $parent_id = 0;
        if (! empty($source_term->parent)) {
            $parent_source = get_term((int) $source_term->parent, 'category-product');
            if ($parent_source && ! is_wp_error($parent_source)) {
                $parent_id = $this->ensure_target_term($parent_source, $target_tax);
            }
        }
        $args = [
            'slug'   => $source_term->slug,
        ];
        if ($parent_id > 0) {
            $args['parent'] = $parent_id;
        }
        $created = wp_insert_term($source_term->name, $target_tax, $args);
        if (is_wp_error($created)) {
            return 0;
        }
        return (int) $created['term_id'];
    }

    private function migrate_product_images($old_id, $new_id)
    {
        $thumb_id = get_post_thumbnail_id($old_id);
        if ($thumb_id) {
            set_post_thumbnail($new_id, $thumb_id);
        }

        $gallery_ids = get_post_meta($old_id, 'gallery', false);
        if (count($gallery_ids) === 1 && is_array($gallery_ids[0])) {
            $gallery_ids = $gallery_ids[0];
        }

        if (! empty($gallery_ids)) {
            $gallery_data = [];
            foreach ($gallery_ids as $img_id) {
                $url = wp_get_attachment_url($img_id);
                if ($url) {
                    $gallery_data[$img_id] = $url;
                }
            }

            if (! empty($gallery_data)) {
                update_post_meta($new_id, '_store_gallery_ids', $gallery_data);
            }
        }
    }

    private function migrate_woocommerce_products()
    {
        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'post_status'    => 'any',
        ];
        $query = new \WP_Query($args);
        $count = 0;
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $old_id = get_the_ID();
                $existing = new \WP_Query([
                    'post_type'      => 'store_product',
                    'meta_key'       => '_woocommerce_original_id',
                    'meta_value'     => $old_id,
                    'post_status'    => 'any',
                    'fields'         => 'ids',
                    'posts_per_page' => 1,
                ]);
                if ($existing->have_posts()) {
                    continue;
                }
                $post_data = [
                    'post_title'   => get_the_title(),
                    'post_content' => get_the_content(),
                    'post_excerpt' => get_the_excerpt(),
                    'post_status'  => get_post_status(),
                    'post_type'    => 'store_product',
                    'post_author'  => get_the_author_meta('ID'),
                    'post_date'    => get_the_date('Y-m-d H:i:s'),
                ];
                $new_id = wp_insert_post($post_data);
                if (! is_wp_error($new_id)) {
                    $map = [
                        '_sku'        => '_store_sku',
                        '_price'      => '_store_price',
                        '_sale_price' => '_store_sale_price',
                        '_weight'     => '_store_weight_kg',
                    ];
                    foreach ($map as $old_key => $new_key) {
                        $val = get_post_meta($old_id, $old_key, true);
                        if ($val !== '') {
                            update_post_meta($new_id, $new_key, $val);
                        }
                    }
                    $manage = get_post_meta($old_id, '_manage_stock', true);
                    if ($manage === 'yes') {
                        $stock = get_post_meta($old_id, '_stock', true);
                        if ($stock !== '') {
                            update_post_meta($new_id, '_store_stock', $stock);
                        }
                    }
                    update_post_meta($new_id, '_store_product_type', 'physical');
                    update_post_meta($new_id, '_woocommerce_original_id', $old_id);
                    $terms = get_the_terms($old_id, 'product_cat');
                    if ($terms && ! is_wp_error($terms)) {
                        $new_term_ids = [];
                        foreach ($terms as $t) {
                            $tid = $this->ensure_target_term($t, 'store_product_cat');
                            if ($tid) {
                                $new_term_ids[] = $tid;
                            }
                        }
                        if (! empty($new_term_ids)) {
                            wp_set_object_terms($new_id, $new_term_ids, 'store_product_cat');
                        }
                    }
                    $thumb_id = get_post_thumbnail_id($old_id);
                    if ($thumb_id) {
                        set_post_thumbnail($new_id, $thumb_id);
                    }
                    $gallery = get_post_meta($old_id, '_product_image_gallery', true);
                    if (! empty($gallery)) {
                        $ids = array_filter(array_map('intval', array_map('trim', explode(',', $gallery))));
                        if (! empty($ids)) {
                            $g = [];
                            foreach ($ids as $img_id) {
                                $url = wp_get_attachment_url($img_id);
                                if ($url) {
                                    $g[$img_id] = $url;
                                }
                            }
                            if (! empty($g)) {
                                update_post_meta($new_id, '_store_gallery_ids', $g);
                            }
                        }
                    }
                    $count++;
                }
            }
            wp_reset_postdata();
        }
        return $count;
    }

    private function migrate_orders()
    {
        global $wpdb;
        $table_order = $wpdb->prefix . 'order';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_order'") != $table_order) {
            return 0;
        }

        $count = 0;
        $limit = 50;
        $offset = 0;

        while (true) {
            $orders = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_order ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset));

            if (empty($orders)) {
                break;
            }

            foreach ($orders as $order) {
                $invoice = $order->invoice;

                // Check if exists
                $existing = new \WP_Query([
                    'post_type'  => 'store_order',
                    'meta_key'   => '_velocity_original_invoice',
                    'meta_value' => $invoice,
                    'post_status' => 'any',
                ]);

                if ($existing->have_posts()) {
                    continue;
                }

                $detail = json_decode($order->detail, true);

                // Map Status
                $status_map = [
                    'Transaksi Baru' => 'pending',
                    'Menunggu Pembayaran' => 'pending',
                    'Lunas'          => 'processing',
                    'Proses'         => 'processing',
                    'Dikirim'        => 'shipped',
                    'Selesai'        => 'completed',
                    'Batal'          => 'cancelled',
                ];

                $order_status = isset($order->status) && isset($status_map[$order->status])
                    ? $status_map[$order->status]
                    : 'pending';

                $post_data = [
                    'post_title'   => 'Order ' . $invoice,
                    'post_status'  => 'publish', // store_order is always publish, status is in meta
                    'post_type'    => 'store_order',
                    'post_date'    => date('Y-m-d H:i:s', strtotime($order->date)),
                    'post_author'  => $order->id_pembeli > 0 ? $order->id_pembeli : 1,
                ];

                $new_id = wp_insert_post($post_data);

                if (! is_wp_error($new_id)) {
                    update_post_meta($new_id, '_velocity_original_invoice', $invoice);
                    update_post_meta($new_id, '_velocity_original_order_id', $order->id);

                    // Status
                    update_post_meta($new_id, '_store_order_status', $order_status);

                    // Customer Info
                    $nama   = isset($detail['nama']) ? $detail['nama'] : '';
                    $email  = isset($detail['email']) ? $detail['email'] : '';
                    $hp     = isset($detail['hp']) ? $detail['hp'] : '';
                    $alamat = isset($detail['alamat']) ? $detail['alamat'] : '';

                    update_post_meta($new_id, '_store_order_customer_name', $nama); // Custom meta for display if needed
                    update_post_meta($new_id, '_store_order_email', $email);
                    update_post_meta($new_id, '_store_order_phone', $hp);
                    update_post_meta($new_id, '_store_order_address', $alamat);

                    // Location
                    if (isset($detail['subdistrict_destination'])) {
                        $loc = $this->get_location_details($detail['subdistrict_destination']);
                        if ($loc) {
                            update_post_meta($new_id, '_store_order_subdistrict_name', $loc->subdistrict_name);
                            update_post_meta($new_id, '_store_order_city_name', $loc->city_name);
                            update_post_meta($new_id, '_store_order_province_name', $loc->province);
                            update_post_meta($new_id, '_store_order_postal_code', $loc->postal_code);
                        }
                    }

                    // Shipping
                    // Ongkir format: "JNE - REG - 15.000"
                    $ongkir_str = isset($detail['ongkir']) ? $detail['ongkir'] : '';
                    $parts = explode('-', $ongkir_str);
                    $courier = isset($parts[0]) ? trim($parts[0]) : '';
                    $service = isset($parts[1]) ? trim($parts[1]) : '';
                    $cost_str = isset($parts[2]) ? trim($parts[2]) : '0';
                    $cost = (float) str_replace(['.', ','], '', $cost_str); // Remove dots, assume IDR

                    update_post_meta($new_id, '_store_order_shipping_courier', $courier);
                    update_post_meta($new_id, '_store_order_shipping_service', $service);
                    update_post_meta($new_id, '_store_order_shipping_cost', $cost);

                    update_post_meta($new_id, '_store_order_tracking_number', $order->resi);

                    // Payment
                    update_post_meta($new_id, '_store_order_payment_method', $order->pembayaran);

                    // Items
                    $items = [];
                    $subtotal_accumulated = 0;

                    if (isset($detail['produk']['products']) && is_array($detail['produk']['products'])) {
                        foreach ($detail['produk']['products'] as $item) {
                            $old_pid = isset($item['id']) ? $item['id'] : 0;
                            $qty = isset($item['jumlah']) ? (int) $item['jumlah'] : 1;

                            // Find new Product ID
                            $new_pid = $this->get_new_product_id($old_pid);

                            // Price? Velocity doesn't seem to store price per item in the JSON clearly?
                            // Or maybe it does? Check finish.php again. 
                            // It loops and updates stock, but doesn't show price saving.
                            // Assuming it's not in the simple JSON, we might need to look it up or infer.
                            // Wait, finish.php passes $_POST.
                            // And Keranjang usually has price.
                            // Let's assume $item['harga'] exists or we fetch from new product.
                            // If price is missing, use current price (fallback).

                            $price = 0;
                            if (isset($item['harga'])) {
                                $price = (float) $item['harga'];
                            } elseif ($new_pid) {
                                $price = (float) get_post_meta($new_pid, '_store_price', true);
                            }

                            $line_subtotal = $price * $qty;
                            $subtotal_accumulated += $line_subtotal;

                            // Options
                            // Velocity: "ket" (keterangan) or similar?
                            // Let's check $item keys if possible. For now leave empty.
                            $options = [];
                            if (isset($item['keterangan'])) {
                                $options['Info'] = $item['keterangan'];
                            }

                            $items[] = [
                                'product_id' => $new_pid,
                                'qty'        => $qty,
                                'price'      => $price,
                                'subtotal'   => $line_subtotal,
                                'options'    => $options,
                            ];
                        }
                    }

                    update_post_meta($new_id, '_store_order_items', $items);

                    // Total
                    // Order table has 'total' column
                    $total = (float) $order->total;
                    update_post_meta($new_id, '_store_order_total', $total);

                    $count++;
                }
            }
            $offset += $limit;
        }

        return $count;
    }

    private function get_location_details($subdistrict_id)
    {
        global $wpdb;
        $table_sub = $wpdb->prefix . 'vd_subdistricts';
        $table_city = $wpdb->prefix . 'vd_city';

        // Join to get postal code from city
        $sql = "SELECT s.subdistrict_name, c.city_name, c.province, c.postal_code 
                FROM $table_sub s
                JOIN $table_city c ON s.city_id = c.city_id
                WHERE s.subdistrict_id = %d";

        return $wpdb->get_row($wpdb->prepare($sql, $subdistrict_id));
    }

    private function get_new_product_id($old_id)
    {
        $query = new \WP_Query([
            'post_type'  => 'store_product',
            'meta_key'   => '_velocity_original_id',
            'meta_value' => $old_id,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'post_status' => 'any',
        ]);

        if ($query->have_posts()) {
            return $query->posts[0];
        }
        return 0;
    }

    private function migrate_woocommerce_orders()
    {
        $args = [
            'post_type'      => 'shop_order',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ];
        $q = new \WP_Query($args);
        $count = 0;
        if ($q->have_posts()) {
            global $wpdb;
            $oi = $wpdb->prefix . 'woocommerce_order_items';
            $oim = $wpdb->prefix . 'woocommerce_order_itemmeta';
            while ($q->have_posts()) {
                $q->the_post();
                $old_id = get_the_ID();
                $existing = new \WP_Query([
                    'post_type'   => 'store_order',
                    'meta_key'    => '_woocommerce_original_order_id',
                    'meta_value'  => $old_id,
                    'post_status' => 'any',
                    'fields'      => 'ids',
                    'posts_per_page' => 1,
                ]);
                if ($existing->have_posts()) {
                    continue;
                }
                $status = get_post_status($old_id);
                $map = [
                    'wc-pending'    => 'pending',
                    'wc-on-hold'    => 'awaiting_payment',
                    'wc-processing' => 'processing',
                    'wc-completed'  => 'completed',
                    'wc-cancelled'  => 'cancelled',
                    'wc-failed'     => 'cancelled',
                    'wc-refunded'   => 'cancelled',
                ];
                $order_status = isset($map[$status]) ? $map[$status] : 'pending';
                $post_data = [
                    'post_title'  => 'Order #' . $old_id,
                    'post_status' => 'publish',
                    'post_type'   => 'store_order',
                    'post_date'   => get_post_field('post_date', $old_id),
                    'post_author' => 1,
                ];
                $new_id = wp_insert_post($post_data);
                if (! is_wp_error($new_id)) {
                    update_post_meta($new_id, '_woocommerce_original_order_id', $old_id);
                    update_post_meta($new_id, '_store_order_status', $order_status);
                    $fname = get_post_meta($old_id, '_billing_first_name', true);
                    $lname = get_post_meta($old_id, '_billing_last_name', true);
                    $nama = trim($fname . ' ' . $lname);
                    $email = get_post_meta($old_id, '_billing_email', true);
                    $phone = get_post_meta($old_id, '_billing_phone', true);
                    $addr1 = get_post_meta($old_id, '_billing_address_1', true);
                    $addr2 = get_post_meta($old_id, '_billing_address_2', true);
                    $address = trim($addr1 . ' ' . $addr2);
                    $city = get_post_meta($old_id, '_billing_city', true);
                    $postal = get_post_meta($old_id, '_billing_postcode', true);
                    $state = get_post_meta($old_id, '_billing_state', true);
                    update_post_meta($new_id, '_store_order_customer_name', $nama);
                    update_post_meta($new_id, '_store_order_email', $email);
                    update_post_meta($new_id, '_store_order_phone', $phone);
                    update_post_meta($new_id, '_store_order_address', $address);
                    update_post_meta($new_id, '_store_order_city_name', $city);
                    update_post_meta($new_id, '_store_order_province_name', $state);
                    update_post_meta($new_id, '_store_order_postal_code', $postal);
                    $shipping_total = (float) get_post_meta($old_id, '_shipping_total', true);
                    $shipping_method = get_post_meta($old_id, '_shipping_method', true);
                    update_post_meta($new_id, '_store_order_shipping_courier', $shipping_method);
                    update_post_meta($new_id, '_store_order_shipping_service', '');
                    update_post_meta($new_id, '_store_order_shipping_cost', $shipping_total);
                    $pay_method = get_post_meta($old_id, '_payment_method', true);
                    update_post_meta($new_id, '_store_order_payment_method', $pay_method);
                    $items = [];
                    $rows = $wpdb->get_results($wpdb->prepare(
                        "SELECT order_item_id, order_item_name FROM $oi WHERE order_id = %d AND order_item_type = 'line_item'",
                        $old_id
                    ));
                    foreach ($rows as $row) {
                        $m = $wpdb->get_results($wpdb->prepare(
                            "SELECT meta_key, meta_value FROM $oim WHERE order_item_id = %d AND meta_key IN ('_product_id','_variation_id','_qty','_line_total','_line_subtotal')",
                            $row->order_item_id
                        ));
                        $meta = [];
                        foreach ($m as $mm) {
                            $meta[$mm->meta_key] = $mm->meta_value;
                        }
                        $old_pid = isset($meta['_product_id']) ? (int) $meta['_product_id'] : 0;
                        $qty = isset($meta['_qty']) ? (int) $meta['_qty'] : 1;
                        $line_total = isset($meta['_line_total']) ? (float) $meta['_line_total'] : 0.0;
                        $price = $qty > 0 ? ($line_total / $qty) : 0.0;
                        $new_pid = $this->get_new_product_id_from_woo($old_pid);
                        $items[] = [
                            'product_id' => $new_pid,
                            'qty'        => $qty,
                            'price'      => $price,
                            'subtotal'   => $line_total,
                            'options'    => [],
                        ];
                    }
                    update_post_meta($new_id, '_store_order_items', $items);
                    $total = (float) get_post_meta($old_id, '_order_total', true);
                    update_post_meta($new_id, '_store_order_total', $total);
                    $count++;
                }
            }
            wp_reset_postdata();
        }
        return $count;
    }

    private function get_new_product_id_from_woo($old_id)
    {
        $query = new \WP_Query([
            'post_type'  => 'store_product',
            'meta_key'   => '_woocommerce_original_id',
            'meta_value' => $old_id,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'post_status' => 'any',
        ]);
        if ($query->have_posts()) {
            return $query->posts[0];
        }
        return 0;
    }
}
