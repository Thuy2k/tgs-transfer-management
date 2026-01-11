<?php
/**
 * TGS Return AJAX Handler
 *
 * Xử lý trả hàng nội bộ giữa các shop trong multisite
 * Shop con trả hàng -> Shop mẹ nhận
 *
 * @package tgs_transfer_management
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Return_Ajax
{
    /**
     * Constructor - đăng ký các AJAX actions
     */
    public static function init()
    {
        // Trả hàng (shop con tạo phiếu trả)
        add_action('wp_ajax_tgs_return_get_products', [__CLASS__, 'get_products']);
        add_action('wp_ajax_tgs_return_check_products_sync', [__CLASS__, 'check_products_sync']);
        add_action('wp_ajax_tgs_return_create_export', [__CLASS__, 'create_export']);
        add_action('wp_ajax_tgs_return_approve_export', [__CLASS__, 'approve_export']);
        add_action('wp_ajax_tgs_return_reject_export', [__CLASS__, 'reject_export']);

        // Nhận hàng trả (shop mẹ nhận)
        add_action('wp_ajax_tgs_return_get_pending_returns', [__CLASS__, 'get_pending_returns']);
        add_action('wp_ajax_tgs_return_create_import', [__CLASS__, 'create_import']);
        add_action('wp_ajax_tgs_return_approve_import', [__CLASS__, 'approve_import']);
        add_action('wp_ajax_tgs_return_reject_import', [__CLASS__, 'reject_import']);

        // Danh sách phiếu
        add_action('wp_ajax_tgs_return_get_exports_list', [__CLASS__, 'get_exports_list']);
        add_action('wp_ajax_tgs_return_get_imports_list', [__CLASS__, 'get_imports_list']);
        add_action('wp_ajax_tgs_return_get_detail', [__CLASS__, 'get_detail']);

        // Return detail
        add_action('wp_ajax_tgs_return_get_return_detail', [__CLASS__, 'get_return_detail']);
        add_action('wp_ajax_tgs_return_get_items', [__CLASS__, 'get_return_items']);
        add_action('wp_ajax_tgs_return_update_lot_conditions', [__CLASS__, 'update_lot_conditions']);

        // Report
        add_action('wp_ajax_tgs_return_get_report_data', [__CLASS__, 'get_report_data']);
    }

    /**
     * Lấy danh sách sản phẩm có tồn kho để trả
     */
    public static function get_products()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();

        // Lấy sản phẩm từ local_product_name
        $products_table = $wpdb->prefix . 'local_product_name';
        $lots_table = TGS_TABLE_GLOBAL_PRODUCT_LOTS;

        $products = $wpdb->get_results("
            SELECT
                p.local_product_name_id as id,
                p.local_product_name as name,
                p.local_product_barcode_main as barcode,
                p.local_product_is_tracking as is_tracking,
                p.local_product_price as price,
                p.local_product_tax as tax_percent,
                p.local_product_quantity_no_tracking as no_tracking_stock,
                COALESCE(p.source_blog_id, 0) as source_blog_id,
                JSON_UNQUOTE(JSON_EXTRACT(p.local_product_meta, '$.product_sku')) as sku
            FROM {$products_table} p
            WHERE p.is_deleted IS NULL OR p.is_deleted = 0
            ORDER BY p.local_product_name ASC
        ");

        // Lấy số lượng tracking stock cho từng sản phẩm
        foreach ($products as &$product) {
            if (intval($product->is_tracking) === 1) {
                // Đếm số lot đang active trong kho hiện tại
                $tracking_stock = $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(*)
                    FROM {$lots_table}
                    WHERE local_product_name_id = %d
                    AND to_blog_id = %d
                    AND local_product_lot_is_active = %d
                    AND (is_deleted IS NULL OR is_deleted = 0)
                ", $product->id, $current_blog_id, TGS_PRODUCT_LOT_ACTIVE));

                $product->tracking_stock = intval($tracking_stock);
            } else {
                $product->tracking_stock = 0;
            }
        }

        wp_send_json_success(['products' => $products]);
    }

    /**
     * Kiểm tra sản phẩm đã được đồng bộ đến shop đích chưa
     */
    public static function check_products_sync()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        $destination_blog_id = intval($_POST['destination_blog_id'] ?? 0);
        $product_ids = $_POST['product_ids'] ?? [];

        if (!$destination_blog_id || empty($product_ids)) {
            wp_send_json_error(['message' => 'Thiếu thông tin']);
        }

        global $wpdb;
        $current_blog_id = get_current_blog_id();

        // Lấy barcode của các sản phẩm hiện tại
        $products_table = $wpdb->prefix . 'local_product_name';
        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));

        $products = $wpdb->get_results($wpdb->prepare("
            SELECT local_product_name_id as id, local_product_barcode_main as barcode
            FROM {$products_table}
            WHERE local_product_name_id IN ({$placeholders})
        ", ...$product_ids));

        $synced = [];
        $need_sync = [];

        // Chuyển sang shop đích để kiểm tra
        switch_to_blog($destination_blog_id);

        $dest_products_table = $wpdb->prefix . 'local_product_name';

        foreach ($products as $product) {
            if (empty($product->barcode)) {
                $need_sync[] = $product->id;
                continue;
            }

            // Kiểm tra barcode có tồn tại ở shop đích không
            $exists = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*)
                FROM {$dest_products_table}
                WHERE local_product_barcode_main = %s
                AND (is_deleted IS NULL OR is_deleted = 0)
            ", $product->barcode));

            if ($exists > 0) {
                $synced[] = $product->id;
            } else {
                $need_sync[] = $product->id;
            }
        }

        restore_current_blog();

        wp_send_json_success([
            'synced' => $synced,
            'need_sync' => $need_sync
        ]);
    }

    /**
     * Tạo phiếu trả hàng nội bộ (shop con trả về shop mẹ)
     *
     * Luồng: Shop con tạo phiếu trả → Shop mẹ nhận
     * 1. Tạo phiếu trả nội bộ (PARENT) - type = RETURN_EXPORT, KHÔNG có items
     * 2. Tạo phiếu xuất tự động (CHILD) - type = SALE, CÓ items, parent_id = phiếu cha
     * 3. Cập nhật phiếu cha với local_ledger_item_id = items từ phiếu con
     * 4. Tạo transfer_ledger với transfer_type = RETURN_INTERNAL
     */
    public static function create_export()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();
        $current_user_id = get_current_user_id();

        $destination_blog_id = intval($_POST['destination_blog_id'] ?? 0); // Shop mẹ nhận
        $ledger_code = sanitize_text_field($_POST['ledger_code'] ?? '');
        $return_note = sanitize_textarea_field($_POST['return_note'] ?? $_POST['transfer_note'] ?? '');
        $items = json_decode(stripslashes($_POST['items'] ?? '[]'), true);

        if (!$destination_blog_id) {
            wp_send_json_error(['message' => 'Vui lòng chọn shop nhận']);
        }

        if (empty($items)) {
            wp_send_json_error(['message' => 'Vui lòng thêm sản phẩm']);
        }

        // Bắt đầu transaction
        $wpdb->query('START TRANSACTION');

        try {
            $ledger_table = $wpdb->prefix . 'local_ledger';
            $products_table = $wpdb->prefix . 'local_product_name';
            $lots_table = TGS_TABLE_GLOBAL_PRODUCT_LOTS;

            // 1. Tạo mã phiếu cha (trả nội bộ) - THN = Trả Hàng Nội bộ
            if (empty($ledger_code)) {
                $ledger_code = 'THN-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -4));
            }
            // Mã phiếu con (xuất tự động) - ATH = Auto Trả Hàng
            $auto_export_code = 'ATH-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -4));

            // ========== BƯỚC 1: Validate và chuẩn bị items ==========
            $total_amount = 0;
            $export_items_data = [];

            foreach ($items as $item) {
                $product_id = intval($item['product_id']);
                $is_tracking = !empty($item['is_tracking']);
                $quantity = floatval($item['quantity'] ?? 0);

                $price_from_frontend = isset($item['price']) ? floatval($item['price']) : null;
                $tax_percent_from_frontend = isset($item['tax_percent']) ? floatval($item['tax_percent']) : null;
                $discount_percent = floatval($item['discount_percent'] ?? 0);
                $item_note = sanitize_text_field($item['item_note'] ?? '');

                // Lấy thông tin sản phẩm
                $product = $wpdb->get_row($wpdb->prepare("
                    SELECT * FROM {$products_table}
                    WHERE local_product_name_id = %d
                ", $product_id));

                if (!$product) {
                    throw new Exception("Sản phẩm ID {$product_id} không tồn tại");
                }

                $price = $price_from_frontend !== null ? $price_from_frontend : floatval($product->local_product_price ?? 0);
                $tax_percent = $tax_percent_from_frontend !== null ? $tax_percent_from_frontend : floatval($product->local_product_tax ?? 0);

                if ($is_tracking) {
                    // Xử lý sản phẩm có tracking
                    $lot_barcodes = $item['lot_barcodes'] ?? [];

                    if (empty($lot_barcodes)) {
                        throw new Exception("Thiếu mã định danh cho sản phẩm: {$product->local_product_name}");
                    }

                    // Kiểm tra và lấy thông tin các lot
                    $lot_ids = [];
                    foreach ($lot_barcodes as $barcode) {
                        $lot = $wpdb->get_row($wpdb->prepare("
                            SELECT *
                            FROM {$lots_table}
                            WHERE global_product_lot_barcode = %s
                            AND to_blog_id = %d
                            AND local_product_lot_is_active = %d
                            AND (is_deleted IS NULL OR is_deleted = 0)
                        ", $barcode, $current_blog_id, TGS_PRODUCT_LOT_ACTIVE));

                        if (!$lot) {
                            throw new Exception("Mã định danh '{$barcode}' không hợp lệ hoặc không có trong kho");
                        }

                        $lot_ids[] = $lot->global_product_lot_id;
                    }

                    $quantity = count($lot_ids);
                    $subtotal_no_vat = $quantity * $price;
                    $discount_amount = $subtotal_no_vat * ($discount_percent / 100);
                    $after_discount = $subtotal_no_vat - $discount_amount;
                    $tax_amount = $after_discount * ($tax_percent / 100);
                    $subtotal = $after_discount + $tax_amount;
                    $total_amount += $subtotal;

                    $export_items_data[] = [
                        'product_id' => $product_id,
                        'quantity' => $quantity,
                        'price' => $price,
                        'tax_percent' => $tax_percent,
                        'tax_amount' => $tax_amount,
                        'discount_type' => 'percent',
                        'discount_value' => $discount_percent,
                        'discount_amount' => $discount_amount,
                        'subtotal' => $subtotal,
                        'note' => $item_note,
                        'lot_barcodes' => $lot_barcodes,
                        'lot_ids' => $lot_ids,
                        'is_tracking' => true,
                        'product' => $product
                    ];

                } else {
                    // Xử lý sản phẩm không tracking
                    $available_stock = floatval($product->local_product_quantity_no_tracking ?? 0);

                    if ($quantity > $available_stock) {
                        throw new Exception("Số lượng trả ({$quantity}) vượt quá tồn kho ({$available_stock}) cho sản phẩm: {$product->local_product_name}");
                    }

                    $subtotal_no_vat = $quantity * $price;
                    $discount_amount = $subtotal_no_vat * ($discount_percent / 100);
                    $after_discount = $subtotal_no_vat - $discount_amount;
                    $tax_amount = $after_discount * ($tax_percent / 100);
                    $subtotal = $after_discount + $tax_amount;
                    $total_amount += $subtotal;

                    $export_items_data[] = [
                        'product_id' => $product_id,
                        'quantity' => $quantity,
                        'price' => $price,
                        'tax_percent' => $tax_percent,
                        'tax_amount' => $tax_amount,
                        'discount_type' => 'percent',
                        'discount_value' => $discount_percent,
                        'discount_amount' => $discount_amount,
                        'subtotal' => $subtotal,
                        'note' => $item_note,
                        'lot_barcodes' => [],
                        'is_tracking' => false,
                        'product' => $product
                    ];
                }
            }

            // ========== BƯỚC 2: Tạo phiếu CHA (Trả nội bộ) - KHÔNG có items ==========
            $wpdb->insert($ledger_table, [
                'local_ledger_code' => $ledger_code,
                'local_ledger_type' => TGS_LEDGER_TYPE_RETURN_EXPORT, // Type 14
                'local_ledger_note' => $return_note,
                'local_ledger_total_amount' => $total_amount,
                'local_ledger_status' => TGS_LEDGER_STATUS_PENDING,
                'local_ledger_approver_status' => TGS_APPROVER_STATUS_PENDING,
                'user_id' => $current_user_id,
                'is_deleted' => 0,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ]);

            $parent_ledger_id = $wpdb->insert_id;

            if (!$parent_ledger_id) {
                throw new Exception('Lỗi tạo phiếu trả nội bộ (phiếu cha)');
            }

            // ========== BƯỚC 3: Tạo phiếu CON (Xuất tự động) - CÓ items ==========
            $auto_export_ledger_data = [
                'local_ledger_code' => $auto_export_code,
                'local_ledger_type' => TGS_LEDGER_TYPE_SALE, // Type 2 - Phiếu xuất
                'local_ledger_note' => 'Xuất tự động từ phiếu trả: ' . $ledger_code,
                'local_ledger_total_amount' => $total_amount,
                'local_ledger_status' => TGS_LEDGER_STATUS_PENDING,
                'local_ledger_approver_status' => TGS_APPROVER_STATUS_PENDING,
                'user_id' => $current_user_id,
            ];

            $auto_export_result = TGS_Shop_Base_Import_Export::create_export_ledger(
                $auto_export_ledger_data,
                $export_items_data,
                $parent_ledger_id
            );

            $auto_export_ledger_id = $auto_export_result['ledger_id'];
            $auto_export_item_ids = $auto_export_result['items'];

            // ========== BƯỚC 4: Cập nhật phiếu CHA với item IDs từ phiếu con ==========
            $items_json = json_encode($auto_export_item_ids, JSON_UNESCAPED_UNICODE);
            $wpdb->update($ledger_table, [
                'local_ledger_item_id' => $items_json
            ], ['local_ledger_id' => $parent_ledger_id]);

            // ========== BƯỚC 5: Xử lý lot status cho tracking products ==========
            foreach ($export_items_data as $item_data) {
                if ($item_data['is_tracking'] && !empty($item_data['lot_ids'])) {
                    foreach ($item_data['lot_ids'] as $lot_id) {
                        TGS_Global_Lots_Helper::ensure_lot_has_barcode_main($lot_id, $item_data['product_id']);

                        $wpdb->update($lots_table, [
                            'local_product_lot_is_active' => TGS_PRODUCT_LOT_PENDING,
                            'updated_at' => current_time('mysql')
                        ], ['global_product_lot_id' => $lot_id]);

                        TGS_Global_Lots_Helper::add_lot_log($lot_id, 'return_export_created', [
                            'previous_status' => TGS_PRODUCT_LOT_ACTIVE,
                            'new_status' => TGS_PRODUCT_LOT_PENDING,
                            'source_blog_id' => $current_blog_id,
                            'destination_blog_id' => $destination_blog_id,
                            'parent_ledger_id' => $parent_ledger_id,
                            'auto_export_ledger_id' => $auto_export_ledger_id,
                            'ledger_code' => $ledger_code
                        ]);
                    }
                }

                // Trừ tồn kho no_tracking
                if (!$item_data['is_tracking']) {
                    $quantity = floatval($item_data['quantity']);
                    $product = $item_data['product'];
                    $current_stock = floatval($product->local_product_quantity_no_tracking ?? 0);
                    $new_stock = $current_stock - $quantity;

                    $wpdb->query($wpdb->prepare("
                        UPDATE {$products_table}
                        SET local_product_quantity_no_tracking = %f,
                            updated_at = %s
                        WHERE local_product_name_id = %d
                    ", $new_stock, current_time('mysql'), $item_data['product_id']));

                    if (class_exists('TGS_Shop_Base_Import_Export') && method_exists('TGS_Shop_Base_Import_Export', 'add_no_tracking_stock_log')) {
                        TGS_Shop_Base_Import_Export::add_no_tracking_stock_log(
                            $item_data['product_id'],
                            'return_export_create',
                            $auto_export_ledger_id,
                            $auto_export_code,
                            $quantity,
                            $current_stock,
                            $new_stock,
                            $current_user_id
                        );
                    }
                }
            }

            // ========== BƯỚC 6: Tạo transfer_ledger ở shop CON (hiện tại) ==========
            $transfer_table = $wpdb->prefix . 'transfer_ledger';

            $wpdb->insert($transfer_table, [
                'source_blog_id' => $current_blog_id, // Shop con trả
                'source_ledger_id' => $parent_ledger_id,
                'source_ledger_item_id' => $items_json,
                'destination_blog_id' => $destination_blog_id, // Shop mẹ nhận
                'transfer_status' => TGS_TRANSFER_STATUS_PENDING,
                'transfer_type' => TGS_TRANSFER_TYPE_RETURN_INTERNAL, // Type 3 - Trả hàng nội bộ
                'created_at' => current_time('mysql'),
                'created_by_user_id' => $current_user_id,
                'transfer_note' => $return_note
            ]);

            $transfer_id = $wpdb->insert_id;

            if (!$transfer_id) {
                throw new Exception('Lỗi tạo bản ghi transfer ở shop trả');
            }

            // ========== BƯỚC 7: Tạo transfer_ledger ở shop MẸ ==========
            switch_to_blog($destination_blog_id);

            $dest_transfer_table = $wpdb->prefix . 'transfer_ledger';

            $wpdb->insert($dest_transfer_table, [
                'source_blog_id' => $current_blog_id,
                'source_ledger_id' => $parent_ledger_id,
                'source_ledger_item_id' => $items_json,
                'destination_blog_id' => $destination_blog_id,
                'transfer_status' => TGS_TRANSFER_STATUS_PENDING,
                'transfer_type' => TGS_TRANSFER_TYPE_RETURN_INTERNAL,
                'created_at' => current_time('mysql'),
                'created_by_user_id' => $current_user_id,
                'transfer_note' => $return_note
            ]);

            $dest_transfer_id = $wpdb->insert_id;

            restore_current_blog();

            if (!$dest_transfer_id) {
                throw new Exception('Lỗi tạo bản ghi transfer ở shop nhận');
            }

            $wpdb->query('COMMIT');

            // Thêm log tạo phiếu trả
            $dest_shop_name = get_blog_option($destination_blog_id, 'blogname');
            TGS_Shop_Ticket_Helper::add_ticket_log($parent_ledger_id, 'create', [
                'destination_blog_id' => $destination_blog_id,
                'destination_shop_name' => $dest_shop_name,
                'items_count' => count($export_items_data),
                'total_amount' => $total_amount,
                'auto_export_ledger_id' => $auto_export_ledger_id,
                'auto_export_code' => $auto_export_code
            ], 'Tạo phiếu trả hàng nội bộ cho shop: ' . $dest_shop_name);

            wp_send_json_success([
                'message' => 'Tạo phiếu trả hàng nội bộ thành công',
                'ledger_id' => $parent_ledger_id,
                'auto_export_ledger_id' => $auto_export_ledger_id,
                'transfer_id' => $transfer_id,
                'ledger_code' => $ledger_code,
                'auto_export_code' => $auto_export_code,
                'redirect_url' => admin_url('admin.php?page=tgs-shop-management&view=ticket-return-export-detail&id=' . $parent_ledger_id)
            ]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Duyệt phiếu trả - thực hiện:
     * 1. Cập nhật trạng thái lot thành PENDING (chờ shop mẹ nhận)
     * 2. Đồng bộ sản phẩm sang shop mẹ nếu cần
     * 3. Tạo thông báo cho shop mẹ
     */
    public static function approve_export()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();
        $current_user_id = get_current_user_id();

        $ledger_id = intval($_POST['ledger_id'] ?? 0);
        $note = sanitize_textarea_field($_POST['note'] ?? '');

        if (!$ledger_id) {
            wp_send_json_error(['message' => 'Thiếu ID phiếu']);
        }

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $ledger_item_table = $wpdb->prefix . 'local_ledger_item';
        $products_table = $wpdb->prefix . 'local_product_name';
        $transfer_table = $wpdb->prefix . 'transfer_ledger';
        $lots_table = TGS_TABLE_GLOBAL_PRODUCT_LOTS;

        // Lấy thông tin phiếu con xuất kho (type 2 - SALE)
        $child_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$ledger_table}
            WHERE local_ledger_id = %d
            AND local_ledger_type = %d
        ", $ledger_id, TGS_LEDGER_TYPE_SALE));

        if (!$child_ledger) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu xuất kho']);
        }

        if ($child_ledger->local_ledger_approver_status == TGS_APPROVER_STATUS_APPROVED) {
            wp_send_json_error(['message' => 'Phiếu đã được duyệt trước đó']);
        }

        // Tìm phiếu cha RETURN_EXPORT (type 14)
        $parent_id = intval($child_ledger->local_ledger_parent_id);
        if (!$parent_id) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu cha']);
        }

        $parent_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$ledger_table}
            WHERE local_ledger_id = %d
            AND local_ledger_type = %d
        ", $parent_id, TGS_LEDGER_TYPE_RETURN_EXPORT));

        if (!$parent_ledger) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu trả nội bộ']);
        }

        // Lấy thông tin transfer từ phiếu cha
        $transfer = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$transfer_table}
            WHERE source_ledger_id = %d
            AND source_blog_id = %d
            AND transfer_type = %d
        ", $parent_id, $current_blog_id, TGS_TRANSFER_TYPE_RETURN_INTERNAL));

        if (!$transfer) {
            wp_send_json_error(['message' => 'Không tìm thấy thông tin transfer']);
        }

        $destination_blog_id = $transfer->destination_blog_id;

        // Lấy các item từ phiếu con xuất kho
        $items = $wpdb->get_results($wpdb->prepare("
            SELECT li.*, p.local_product_name, p.local_product_barcode_main, p.local_product_is_tracking
            FROM {$ledger_item_table} li
            JOIN {$products_table} p ON li.local_product_name_id = p.local_product_name_id
            WHERE li.local_ledger_id = %d
        ", $ledger_id));

        $wpdb->query('START TRANSACTION');

        try {
            foreach ($items as $item) {
                $is_tracking = intval($item->local_product_is_tracking) === 1;

                if ($is_tracking) {
                    $lot_ids = json_decode($item->list_product_lots, true) ?: [];

                    foreach ($lot_ids as $lot_id) {
                        $current_lot = TGS_Global_Lots_Helper::get_lot_by_id($lot_id);
                        $previous_status = $current_lot ? $current_lot->local_product_lot_is_active : TGS_PRODUCT_LOT_PENDING;

                        TGS_Global_Lots_Helper::ensure_lot_has_barcode_main($lot_id, $item->local_product_name_id);

                        // Cập nhật lot: chuyển về shop mẹ (to_blog_id = destination)
                        $wpdb->update($lots_table, [
                            'source_blog_id' => $current_blog_id, // Shop con trả
                            'to_blog_id' => $destination_blog_id, // Shop mẹ nhận
                            'local_product_lot_is_active' => TGS_PRODUCT_LOT_PENDING,
                            'local_exported_date' => time(),
                            'updated_at' => current_time('mysql')
                        ], ['global_product_lot_id' => $lot_id]);

                        TGS_Global_Lots_Helper::add_lot_log($lot_id, 'return_export_approved', [
                            'previous_status' => $previous_status,
                            'new_status' => TGS_PRODUCT_LOT_PENDING,
                            'source_blog_id' => $current_blog_id,
                            'destination_blog_id' => $destination_blog_id,
                            'ledger_id' => $ledger_id,
                            'ledger_code' => $child_ledger->local_ledger_code ?? ''
                        ]);
                    }
                }

                // Đồng bộ sản phẩm sang shop mẹ nếu chưa có
                self::sync_product_to_destination($item, $destination_blog_id, $current_blog_id);
            }

            // Cập nhật trạng thái phiếu con xuất kho
            $wpdb->update($ledger_table, [
                'local_ledger_approver_status' => TGS_APPROVER_STATUS_APPROVED,
                'local_ledger_status' => TGS_LEDGER_STATUS_APPROVED,
                'local_ledger_approver_id' => $current_user_id,
                'updated_at' => current_time('mysql')
            ], ['local_ledger_id' => $ledger_id]);

            // Cập nhật transfer_ledger
            $wpdb->update($transfer_table, [
                'transfer_status' => TGS_TRANSFER_STATUS_PENDING,
                'transfer_note' => $transfer->transfer_note . "\n[Duyệt trả hàng] " . date('d/m/Y H:i') . ": " . $note
            ], ['transfer_ledger_id' => $transfer->transfer_ledger_id]);

            $wpdb->query('COMMIT');

            // Thêm log duyệt phiếu trả
            $dest_shop_name = get_blog_option($destination_blog_id, 'blogname');
            TGS_Shop_Ticket_Helper::add_ticket_log($ledger_id, 'approve', [
                'destination_blog_id' => $destination_blog_id,
                'destination_shop_name' => $dest_shop_name,
                'items_count' => count($items),
                'note' => $note,
                'parent_ledger_id' => $parent_id,
                'parent_ledger_code' => $parent_ledger->local_ledger_code ?? ''
            ], !empty($note) ? $note : 'Duyệt phiếu trả hàng (gửi đến shop: ' . $dest_shop_name . ')');

            wp_send_json_success([
                'message' => 'Duyệt phiếu trả hàng thành công. Shop mẹ có thể nhận hàng.'
            ]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Từ chối phiếu trả - hoàn lại tồn kho
     */
    public static function reject_export()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();
        $current_user_id = get_current_user_id();

        $ledger_id = intval($_POST['ledger_id'] ?? 0);
        $note = sanitize_textarea_field($_POST['note'] ?? '');

        if (!$ledger_id) {
            wp_send_json_error(['message' => 'Thiếu ID phiếu']);
        }

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $ledger_item_table = $wpdb->prefix . 'local_ledger_item';
        $products_table = $wpdb->prefix . 'local_product_name';
        $transfer_table = $wpdb->prefix . 'transfer_ledger';
        $lots_table = TGS_TABLE_GLOBAL_PRODUCT_LOTS;

        // Lấy thông tin phiếu con xuất kho
        $child_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$ledger_table}
            WHERE local_ledger_id = %d
            AND local_ledger_type = %d
        ", $ledger_id, TGS_LEDGER_TYPE_SALE));

        if (!$child_ledger) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu xuất kho']);
        }

        if ($child_ledger->local_ledger_approver_status == TGS_APPROVER_STATUS_APPROVED) {
            wp_send_json_error(['message' => 'Phiếu đã được duyệt, không thể từ chối']);
        }

        // Tìm phiếu cha
        $parent_id = intval($child_ledger->local_ledger_parent_id);

        // Lấy các item
        $items = $wpdb->get_results($wpdb->prepare("
            SELECT li.*, p.local_product_is_tracking
            FROM {$ledger_item_table} li
            JOIN {$products_table} p ON li.local_product_name_id = p.local_product_name_id
            WHERE li.local_ledger_id = %d
        ", $ledger_id));

        $wpdb->query('START TRANSACTION');

        try {
            foreach ($items as $item) {
                $is_tracking = intval($item->local_product_is_tracking) === 1;

                if ($is_tracking) {
                    // Hoàn lại trạng thái lot về ACTIVE
                    $lot_ids = json_decode($item->list_product_lots, true) ?: [];

                    foreach ($lot_ids as $lot_id) {
                        $wpdb->update($lots_table, [
                            'local_product_lot_is_active' => TGS_PRODUCT_LOT_ACTIVE,
                            'updated_at' => current_time('mysql')
                        ], ['global_product_lot_id' => $lot_id]);

                        TGS_Global_Lots_Helper::add_lot_log($lot_id, 'return_export_rejected', [
                            'previous_status' => TGS_PRODUCT_LOT_PENDING,
                            'new_status' => TGS_PRODUCT_LOT_ACTIVE,
                            'ledger_id' => $ledger_id,
                            'reason' => $note
                        ]);
                    }
                } else {
                    // Hoàn lại tồn kho no_tracking
                    $quantity = floatval($item->quantity);

                    $wpdb->query($wpdb->prepare("
                        UPDATE {$products_table}
                        SET local_product_quantity_no_tracking = local_product_quantity_no_tracking + %f,
                            updated_at = %s
                        WHERE local_product_name_id = %d
                    ", $quantity, current_time('mysql'), $item->local_product_name_id));
                }
            }

            // Cập nhật trạng thái phiếu con
            $wpdb->update($ledger_table, [
                'local_ledger_approver_status' => TGS_APPROVER_STATUS_REJECTED,
                'local_ledger_status' => TGS_LEDGER_STATUS_REJECTED,
                'local_ledger_approver_id' => $current_user_id,
                'updated_at' => current_time('mysql')
            ], ['local_ledger_id' => $ledger_id]);

            // Cập nhật phiếu cha
            if ($parent_id) {
                $wpdb->update($ledger_table, [
                    'local_ledger_approver_status' => TGS_APPROVER_STATUS_REJECTED,
                    'local_ledger_status' => TGS_LEDGER_STATUS_REJECTED,
                    'updated_at' => current_time('mysql')
                ], ['local_ledger_id' => $parent_id]);
            }

            // Cập nhật transfer_ledger
            $wpdb->update($transfer_table, [
                'transfer_status' => TGS_TRANSFER_STATUS_REJECTED
            ], [
                'source_ledger_id' => $parent_id,
                'source_blog_id' => $current_blog_id
            ]);

            $wpdb->query('COMMIT');

            TGS_Shop_Ticket_Helper::add_ticket_log($ledger_id, 'reject', [
                'note' => $note
            ], !empty($note) ? $note : 'Từ chối phiếu trả hàng');

            wp_send_json_success([
                'message' => 'Đã từ chối phiếu trả hàng. Tồn kho đã được hoàn lại.'
            ]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Đồng bộ sản phẩm từ shop nguồn sang shop đích
     */
    private static function sync_product_to_destination($item, $destination_blog_id, $source_blog_id)
    {
        global $wpdb;

        $barcode = $item->local_product_barcode_main;

        if (empty($barcode)) {
            return;
        }

        switch_to_blog($destination_blog_id);

        $dest_products_table = $wpdb->prefix . 'local_product_name';

        $exists = $wpdb->get_var($wpdb->prepare("
            SELECT local_product_name_id
            FROM {$dest_products_table}
            WHERE local_product_barcode_main = %s
            AND (is_deleted IS NULL OR is_deleted = 0)
        ", $barcode));

        if ($exists) {
            restore_current_blog();
            return;
        }

        restore_current_blog();

        // Lấy thông tin đầy đủ sản phẩm từ shop nguồn
        $source_products_table = $wpdb->prefix . 'local_product_name';
        $full_product = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$source_products_table}
            WHERE local_product_name_id = %d
        ", $item->local_product_name_id));

        if (!$full_product) {
            return;
        }

        switch_to_blog($destination_blog_id);

        // Tạo sản phẩm mới tại shop đích
        self::sync_product_from_source($full_product, $source_blog_id);

        restore_current_blog();
    }

    /**
     * Đồng bộ sản phẩm từ shop nguồn
     *
     * @param object $source_product Thông tin sản phẩm từ shop nguồn
     * @param int $source_blog_id Blog ID của shop nguồn
     * @return int|false ID sản phẩm mới hoặc false nếu lỗi
     */
    private static function sync_product_from_source($source_product, $source_blog_id)
    {
        global $wpdb;

        $products_table = $wpdb->prefix . 'local_product_name';

        // Kiểm tra sản phẩm đã tồn tại chưa (theo barcode)
        $existing = $wpdb->get_row($wpdb->prepare("
            SELECT local_product_name_id FROM {$products_table}
            WHERE local_product_barcode_main = %s
            AND (is_deleted IS NULL OR is_deleted = 0)
        ", $source_product->local_product_barcode_main));

        if ($existing) {
            return $existing->local_product_name_id;
        }

        // Đồng bộ danh mục nếu có
        $local_cat_id = null;
        if (!empty($source_product->local_product_cat_id)) {
            $local_cat_id = self::sync_category_from_source(
                $source_product->local_product_cat_id,
                $source_blog_id
            );
        }

        // Xử lý meta
        $meta = $source_product->local_product_meta;
        if (is_string($meta)) {
            $meta_array = json_decode($meta, true) ?: [];
        } elseif (is_array($meta)) {
            $meta_array = $meta;
        } else {
            $meta_array = [];
        }

        if (!empty($source_product->local_product_unit)) {
            $meta_array['unit'] = $source_product->local_product_unit;
        }
        if (isset($source_product->local_product_price_in)) {
            $meta_array['price_in'] = $source_product->local_product_price_in;
        }

        $wpdb->insert($products_table, [
            'source_blog_id' => $source_blog_id,
            'local_product_barcode_main' => $source_product->local_product_barcode_main,
            'local_product_barcode_url_main' => $source_product->local_product_barcode_url_main ?? '',
            'local_product_name' => $source_product->local_product_name,
            'global_product_name' => $source_product->global_product_name ?? '',
            'local_product_price' => $source_product->local_product_price ?? 0,
            'local_product_cat_id' => $local_cat_id,
            'local_product_is_tracking' => $source_product->local_product_is_tracking ?? 0,
            'local_product_quantity_no_tracking' => 0,
            'local_product_status' => TGS_PRODUCT_STATUS_ACTIVE,
            'local_product_thumbnail' => $source_product->local_product_thumbnail ?? '',
            'local_product_description' => $source_product->local_product_description ?? '',
            'local_product_content' => $source_product->local_product_content ?? '',
            'local_product_tax' => $source_product->local_product_tax ?? 0,
            'local_product_point' => $source_product->local_product_point ?? 0,
            'local_product_meta' => !empty($meta_array) ? json_encode($meta_array, JSON_UNESCAPED_UNICODE) : null,
            'user_id' => get_current_user_id(),
            'is_deleted' => 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ]);

        return $wpdb->insert_id ?: false;
    }

    /**
     * Đồng bộ danh mục từ shop nguồn (đệ quy)
     *
     * @param int $source_cat_id ID danh mục ở shop nguồn
     * @param int $source_blog_id Blog ID của shop nguồn
     * @return int|null ID danh mục ở shop hiện tại
     */
    private static function sync_category_from_source($source_cat_id, $source_blog_id)
    {
        global $wpdb;

        if (empty($source_cat_id)) {
            return null;
        }

        // Lấy thông tin danh mục từ shop nguồn
        switch_to_blog($source_blog_id);

        $source_cat_table = $wpdb->prefix . 'local_product_cat';
        $source_cat = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$source_cat_table}
            WHERE local_product_cat_id = %d
            AND (is_deleted IS NULL OR is_deleted = 0)
        ", $source_cat_id));

        restore_current_blog();

        if (!$source_cat) {
            return null;
        }

        // Đệ quy sync danh mục cha trước
        $local_parent_id = null;
        if (!empty($source_cat->local_cat_parent_id)) {
            $local_parent_id = self::sync_category_from_source(
                $source_cat->local_cat_parent_id,
                $source_blog_id
            );
        }

        // Kiểm tra danh mục đã tồn tại chưa
        $cat_table = $wpdb->prefix . 'local_product_cat';

        $existing = $wpdb->get_row($wpdb->prepare("
            SELECT local_product_cat_id FROM {$cat_table}
            WHERE local_product_cat_name = %s
            AND (local_cat_parent_id = %d OR (local_cat_parent_id IS NULL AND %d = 0))
            AND (is_deleted IS NULL OR is_deleted = 0)
        ", $source_cat->local_product_cat_name, $local_parent_id ?? 0, $local_parent_id ?? 0));

        if ($existing) {
            return $existing->local_product_cat_id;
        }

        // Tạo danh mục mới
        $wpdb->insert($cat_table, [
            'local_product_cat_name' => $source_cat->local_product_cat_name,
            'local_cat_parent_id' => $local_parent_id,
            'local_product_cat_status' => $source_cat->local_product_cat_status ?? 1,
            'global_product_cat_name' => $source_cat->global_product_cat_name ?? $source_cat->local_product_cat_name,
            'user_id' => get_current_user_id(),
            'is_deleted' => 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ]);

        return $wpdb->insert_id ?: null;
    }

    /**
     * Lấy danh sách phiếu trả chờ nhận (shop mẹ nhận từ shop con)
     *
     * Logic: Shop mẹ query transfer_ledger với transfer_type = RETURN_INTERNAL
     * để lấy các phiếu trả từ shop con chưa nhận
     */
    public static function get_pending_returns()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();

        $pending_returns = [];

        // Lấy từ transfer_ledger của shop hiện tại (shop mẹ)
        $transfer_table = $wpdb->prefix . 'transfer_ledger';

        // Lấy các transfer chờ nhận (destination_ledger_id chưa có hoặc = 0)
        // transfer_type = RETURN_INTERNAL và destination_blog_id = current_blog
        $transfers = $wpdb->get_results($wpdb->prepare("
            SELECT t.transfer_ledger_id as transfer_id,
                   t.source_blog_id,
                   t.source_ledger_id,
                   t.destination_blog_id,
                   t.destination_ledger_id,
                   t.transfer_status,
                   t.transfer_note as note,
                   t.created_at
            FROM {$transfer_table} t
            WHERE t.destination_blog_id = %d
            AND t.transfer_type = %d
            AND (t.destination_ledger_id IS NULL OR t.destination_ledger_id = 0)
            AND t.transfer_status != %d
        ", $current_blog_id, TGS_TRANSFER_TYPE_RETURN_INTERNAL, TGS_TRANSFER_STATUS_ACCEPTED));

        foreach ($transfers as $transfer) {
            $source_blog_id = intval($transfer->source_blog_id);

            if (!$source_blog_id) continue;

            // Switch sang shop con (nguồn trả) để lấy thông tin phiếu trả
            switch_to_blog($source_blog_id);

            $source_ledger_table = $wpdb->prefix . 'local_ledger';
            $source_ledger_item_table = $wpdb->prefix . 'local_ledger_item';

            // Lấy thông tin phiếu trả từ shop con (phiếu cha type 14)
            $source_ledger = $wpdb->get_row($wpdb->prepare("
                SELECT local_ledger_code,
                       local_ledger_total_amount,
                       local_ledger_note,
                       local_ledger_approver_status,
                       local_ledger_item_id
                FROM {$source_ledger_table}
                WHERE local_ledger_id = %d
            ", $transfer->source_ledger_id));

            if ($source_ledger) {
                $transfer->local_ledger_code = $source_ledger->local_ledger_code;
                $transfer->local_ledger_total_amount = $source_ledger->local_ledger_total_amount;
                $transfer->local_ledger_note = $source_ledger->local_ledger_note;
                $transfer->local_ledger_approver_status = $source_ledger->local_ledger_approver_status;

                // Tên shop con (nguồn trả)
                $transfer->source_shop_name = get_bloginfo('name');

                // Đếm số sản phẩm từ local_ledger_item_id
                $item_ids = [];
                if (!empty($source_ledger->local_ledger_item_id)) {
                    $item_ids = json_decode($source_ledger->local_ledger_item_id, true) ?: [];
                }

                $items_count = 0;
                if (!empty($item_ids)) {
                    $item_ids_str = implode(',', array_map('intval', $item_ids));
                    $items_count = $wpdb->get_var("
                        SELECT COUNT(*) FROM {$source_ledger_item_table}
                        WHERE local_ledger_item_id IN ({$item_ids_str})
                        AND (is_deleted = 0 OR is_deleted IS NULL)
                    ");
                }
                $transfer->items_count = intval($items_count);

                // Tìm phiếu xuất tự động (phiếu con) có parent_id = phiếu cha (type 14)
                $auto_export_ledger = $wpdb->get_row($wpdb->prepare("
                    SELECT local_ledger_id, local_ledger_approver_status
                    FROM {$source_ledger_table}
                    WHERE local_ledger_parent_id = %d
                    AND local_ledger_type = %d
                ", $transfer->source_ledger_id, TGS_LEDGER_TYPE_SALE));

                // Set trạng thái hiển thị - check trạng thái duyệt của phiếu xuất tự động
                if ($auto_export_ledger) {
                    $transfer->return_status = ($auto_export_ledger->local_ledger_approver_status == TGS_APPROVER_STATUS_APPROVED)
                        ? 1 : 0;
                } else {
                    $transfer->return_status = ($source_ledger->local_ledger_approver_status == TGS_APPROVER_STATUS_APPROVED)
                        ? 1 : 0;
                }

                // Thêm alias cho JS compatibility
                $transfer->return_id = $transfer->transfer_id;
                $transfer->return_code = $source_ledger->local_ledger_code; // Mã phiếu trả

                $pending_returns[] = $transfer;
            }

            restore_current_blog();
        }

        wp_send_json_success($pending_returns);
    }

    /**
     * Tạo phiếu nhận hàng trả (shop mẹ nhận từ shop con)
     *
     * Luồng: Shop mẹ nhận hàng trả từ shop con
     * 1. Tạo phiếu nhận hàng trả (PARENT) - type = RETURN_IMPORT
     * 2. Tạo phiếu nhập tự động (CHILD) - type = PURCHASE
     * 3. Cập nhật transfer_ledger
     */
    public static function create_import()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();
        $current_user_id = get_current_user_id();

        // Support cả transfer_id và return_id (alias)
        $transfer_id = 0;
        if (!empty($_POST['transfer_id'])) {
            $transfer_id = intval($_POST['transfer_id']);
        } elseif (!empty($_POST['return_id'])) {
            $transfer_id = intval($_POST['return_id']);
        }
        $import_note = sanitize_textarea_field($_POST['note'] ?? $_POST['import_note'] ?? '');
        $items_json = isset($_POST['items']) ? wp_unslash($_POST['items']) : '';

        if (!$transfer_id) {
            wp_send_json_error(['message' => 'Thiếu ID transfer']);
        }

        // Parse items từ frontend
        $custom_items = [];
        if (!empty($items_json)) {
            $custom_items = json_decode($items_json, true);
            if (!is_array($custom_items)) {
                $custom_items = [];
            }
        }

        // Build lookup maps
        $import_quantities = [];
        $selected_lots_map = [];
        $item_notes_map = [];
        foreach ($custom_items as $ci) {
            if (isset($ci['barcode']) && isset($ci['import_quantity'])) {
                $import_quantities[$ci['barcode']] = intval($ci['import_quantity']);
            }
            if (isset($ci['barcode']) && isset($ci['selected_lots']) && is_array($ci['selected_lots'])) {
                $selected_lots_map[$ci['barcode']] = $ci['selected_lots'];
            }
            if (isset($ci['barcode']) && isset($ci['item_note'])) {
                $item_notes_map[$ci['barcode']] = sanitize_textarea_field($ci['item_note']);
            }
        }

        // Step 1: Query bảng transfer_ledger của shop hiện tại
        $local_transfer_table = $wpdb->prefix . 'transfer_ledger';

        $local_transfer = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$local_transfer_table}
            WHERE transfer_ledger_id = %d
            AND transfer_type = %d
        ", $transfer_id, TGS_TRANSFER_TYPE_RETURN_INTERNAL));

        if (!$local_transfer) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu trả hàng']);
        }

        if (!empty($local_transfer->destination_ledger_id)) {
            wp_send_json_error(['message' => 'Phiếu này đã được tạo phiếu nhận trước đó']);
        }

        $source_blog_id = intval($local_transfer->source_blog_id);
        $source_ledger_id = intval($local_transfer->source_ledger_id);

        // Step 2: Switch sang shop con để lấy thông tin phiếu trả
        switch_to_blog($source_blog_id);

        $source_ledger_table = $wpdb->prefix . 'local_ledger';
        $source_ledger_item_table = $wpdb->prefix . 'local_ledger_item';
        $source_products_table = $wpdb->prefix . 'local_product_name';

        $source_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$source_ledger_table}
            WHERE local_ledger_id = %d
        ", $source_ledger_id));

        if (!$source_ledger) {
            restore_current_blog();
            wp_send_json_error(['message' => 'Không tìm thấy phiếu trả nguồn']);
        }

        // Kiểm tra phiếu xuất tự động đã duyệt chưa
        $auto_export_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT local_ledger_id, local_ledger_approver_status
            FROM {$source_ledger_table}
            WHERE local_ledger_parent_id = %d
            AND local_ledger_type = %d
        ", $source_ledger_id, TGS_LEDGER_TYPE_SALE));

        if ($auto_export_ledger) {
            if ($auto_export_ledger->local_ledger_approver_status != TGS_APPROVER_STATUS_APPROVED) {
                restore_current_blog();
                wp_send_json_error(['message' => 'Phiếu trả chưa được shop con duyệt']);
            }
        } else {
            if ($source_ledger->local_ledger_approver_status != TGS_APPROVER_STATUS_APPROVED) {
                restore_current_blog();
                wp_send_json_error(['message' => 'Phiếu trả chưa được shop con duyệt']);
            }
        }

        // Lấy các item từ local_ledger_item_id
        $item_ids = [];
        if (!empty($source_ledger->local_ledger_item_id)) {
            $item_ids = json_decode($source_ledger->local_ledger_item_id, true) ?: [];
        }

        $source_items = [];
        if (!empty($item_ids)) {
            $item_ids_str = implode(',', array_map('intval', $item_ids));
            $source_items = $wpdb->get_results("
                SELECT li.*, p.*
                FROM {$source_ledger_item_table} li
                JOIN {$source_products_table} p ON li.local_product_name_id = p.local_product_name_id
                WHERE li.local_ledger_item_id IN ({$item_ids_str})
                AND (li.is_deleted = 0 OR li.is_deleted IS NULL)
            ");
        }

        restore_current_blog();

        if (empty($source_items)) {
            wp_send_json_error(['message' => 'Không có sản phẩm trong phiếu trả']);
        }

        // Step 3: Tạo phiếu nhận tại shop hiện tại (shop mẹ)
        $wpdb->query('START TRANSACTION');

        try {
            $ledger_table = $wpdb->prefix . 'local_ledger';
            $products_table = $wpdb->prefix . 'local_product_name';
            $lots_table = TGS_TABLE_GLOBAL_PRODUCT_LOTS;

            // Mã phiếu cha (nhận hàng trả) - NHT = Nhận Hàng Trả
            $parent_ledger_code = 'NHT-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -4));
            // Mã phiếu con (nhập tự động) - ANH = Auto Nhập Hàng
            $auto_import_code = 'ANH-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -4));

            $total_amount = 0;
            $import_items_data = [];
            $total_max_qty = 0;
            $total_import_qty = 0;

            foreach ($source_items as $source_item) {
                $barcode = $source_item->local_product_barcode_main;
                $is_tracking = intval($source_item->local_product_is_tracking) === 1;
                $max_quantity = intval($source_item->quantity);

                $import_quantity = isset($import_quantities[$barcode])
                    ? intval($import_quantities[$barcode])
                    : $max_quantity;

                // Xử lý lot_ids cho tracking products
                $lot_barcodes_to_import = [];
                if ($is_tracking && !empty($source_item->list_product_lots)) {
                    $all_lot_ids = json_decode($source_item->list_product_lots, true) ?: [];

                    if (isset($selected_lots_map[$barcode]) && !empty($selected_lots_map[$barcode])) {
                        $lot_ids_to_import = array_values(array_intersect(
                            $selected_lots_map[$barcode],
                            $all_lot_ids
                        ));
                        $import_quantity = count($lot_ids_to_import);
                    } else {
                        $lot_ids_to_import = array_slice($all_lot_ids, 0, $import_quantity);
                    }

                    foreach ($lot_ids_to_import as $lot_id) {
                        $lot = $wpdb->get_row($wpdb->prepare("
                            SELECT global_product_lot_barcode FROM {$lots_table}
                            WHERE global_product_lot_id = %d
                        ", $lot_id));
                        if ($lot) {
                            $lot_barcodes_to_import[] = $lot->global_product_lot_barcode;
                        }
                    }
                }

                if ($import_quantity < 0) $import_quantity = 0;
                if ($import_quantity > $max_quantity) $import_quantity = $max_quantity;

                $total_max_qty += $max_quantity;
                $total_import_qty += $import_quantity;

                if ($import_quantity <= 0) {
                    continue;
                }

                // Tìm hoặc tạo sản phẩm tại shop mẹ
                $local_product = $wpdb->get_row($wpdb->prepare("
                    SELECT * FROM {$products_table}
                    WHERE local_product_barcode_main = %s
                    AND (is_deleted IS NULL OR is_deleted = 0)
                ", $barcode));

                if (!$local_product) {
                    $new_product_id = self::sync_product_from_source($source_item, $source_blog_id);

                    if (!$new_product_id) {
                        throw new Exception("Lỗi tạo sản phẩm mới với barcode '{$barcode}'");
                    }

                    $local_product = $wpdb->get_row($wpdb->prepare("
                        SELECT * FROM {$products_table}
                        WHERE local_product_name_id = %d
                    ", $new_product_id));
                }

                $price = floatval($source_item->price ?? 0);
                $tax_percent = floatval($source_item->local_ledger_item_tax_percent ?? 0);
                $discount_percent = floatval($source_item->local_ledger_item_discount ?? 0);

                $subtotal_no_vat = $import_quantity * $price;
                $discount_amount = $subtotal_no_vat * ($discount_percent / 100);
                $after_discount = $subtotal_no_vat - $discount_amount;
                $tax_amount = $after_discount * ($tax_percent / 100);
                $subtotal = $after_discount + $tax_amount;

                $total_amount += $subtotal;

                $item_note = $item_notes_map[$barcode] ?? ($source_item->local_ledger_item_note ?? '');

                $import_items_data[] = [
                    'product_id' => $local_product->local_product_name_id,
                    'quantity' => $import_quantity,
                    'price' => $price,
                    'tax_percent' => $tax_percent,
                    'tax_amount' => $tax_amount,
                    'discount_type' => 'percent',
                    'discount_value' => $discount_percent,
                    'discount_amount' => $discount_amount,
                    'subtotal' => $subtotal,
                    'lot_barcodes' => $lot_barcodes_to_import,
                    'is_tracking' => $is_tracking,
                    'source_item' => $source_item,
                    'local_product' => $local_product,
                    'max_quantity' => $max_quantity,
                    'note' => $item_note
                ];
            }

            if (empty($import_items_data)) {
                throw new Exception('Vui lòng chọn ít nhất 1 sản phẩm để nhận');
            }

            $is_partial = ($total_import_qty < $total_max_qty);

            // ========== BƯỚC 1: Tạo phiếu CHA (Nhận hàng trả) ==========
            $note_suffix = $is_partial
                ? "\n[Từ phiếu trả: {$source_ledger->local_ledger_code}] - Nhận 1 phần: {$total_import_qty}/{$total_max_qty}"
                : "\n[Từ phiếu trả: {$source_ledger->local_ledger_code}]";

            $wpdb->insert($ledger_table, [
                'local_ledger_code' => $parent_ledger_code,
                'local_ledger_type' => TGS_LEDGER_TYPE_RETURN_IMPORT, // Type 15
                'local_ledger_note' => $import_note . $note_suffix,
                'local_ledger_total_amount' => $total_amount,
                'local_ledger_status' => TGS_LEDGER_STATUS_PENDING,
                'local_ledger_approver_status' => TGS_APPROVER_STATUS_PENDING,
                'user_id' => $current_user_id,
                'is_deleted' => 0,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ]);

            $parent_ledger_id = $wpdb->insert_id;

            if (!$parent_ledger_id) {
                throw new Exception('Lỗi tạo phiếu nhận hàng trả (phiếu cha)');
            }

            // ========== BƯỚC 2: Tạo phiếu CON (Nhập tự động) ==========
            $auto_import_ledger_data = [
                'local_ledger_code' => $auto_import_code,
                'local_ledger_type' => TGS_LEDGER_TYPE_PURCHASE, // Type 1
                'local_ledger_note' => 'Nhập tự động từ phiếu nhận: ' . $parent_ledger_code,
                'local_ledger_total_amount' => $total_amount,
                'local_ledger_status' => TGS_LEDGER_STATUS_PENDING,
                'local_ledger_approver_status' => TGS_APPROVER_STATUS_PENDING,
                'user_id' => $current_user_id,
            ];

            $auto_import_result = TGS_Shop_Base_Import_Export::create_import_ledger(
                $auto_import_ledger_data,
                $import_items_data,
                $parent_ledger_id
            );

            $auto_import_ledger_id = $auto_import_result['ledger_id'];
            $auto_import_item_ids = $auto_import_result['items'];

            // ========== BƯỚC 3: Cập nhật phiếu CHA với item IDs ==========
            $items_json = json_encode($auto_import_item_ids, JSON_UNESCAPED_UNICODE);
            $wpdb->update($ledger_table, [
                'local_ledger_item_id' => $items_json
            ], ['local_ledger_id' => $parent_ledger_id]);

            // ========== BƯỚC 4: Cập nhật transfer_ledger ở shop nguồn (con) ==========
            switch_to_blog($source_blog_id);

            $source_transfer_table_name = $wpdb->prefix . 'transfer_ledger';
            $wpdb->update($source_transfer_table_name, [
                'destination_ledger_id' => $parent_ledger_id,
                'destination_ledger_item_id' => $items_json,
            ], ['transfer_ledger_id' => $transfer_id]);

            restore_current_blog();

            // ========== BƯỚC 5: Cập nhật transfer_ledger ở shop hiện tại (mẹ) ==========
            $wpdb->update($local_transfer_table, [
                'destination_ledger_id' => $parent_ledger_id,
                'destination_ledger_item_id' => $items_json,
            ], ['transfer_ledger_id' => $transfer_id]);

            $wpdb->query('COMMIT');

            // Ghi log lot
            foreach ($import_items_data as $item_data) {
                if ($item_data['is_tracking'] && !empty($item_data['lot_barcodes'])) {
                    foreach ($item_data['lot_barcodes'] as $lot_barcode) {
                        $lot = $wpdb->get_row($wpdb->prepare("
                            SELECT global_product_lot_id FROM {$lots_table}
                            WHERE global_product_lot_barcode = %s
                        ", $lot_barcode));
                        if ($lot) {
                            TGS_Global_Lots_Helper::add_lot_log($lot->global_product_lot_id, 'return_import_created', [
                                'source_blog_id' => $source_blog_id,
                                'destination_blog_id' => $current_blog_id,
                                'parent_ledger_id' => $parent_ledger_id,
                                'auto_import_ledger_id' => $auto_import_ledger_id,
                                'ledger_code' => $parent_ledger_code,
                                'source_ledger_id' => $source_ledger_id,
                                'source_ledger_code' => $source_ledger->local_ledger_code ?? '',
                                'is_partial' => $is_partial
                            ]);
                        }
                    }
                }
            }

            // Log tạo phiếu
            $source_shop_name = get_blog_option($source_blog_id, 'blogname');
            TGS_Shop_Ticket_Helper::add_ticket_log($parent_ledger_id, 'create', [
                'source_blog_id' => $source_blog_id,
                'source_shop_name' => $source_shop_name,
                'items_count' => count($import_items_data),
                'total_amount' => $total_amount,
                'is_partial' => $is_partial,
                'auto_import_ledger_id' => $auto_import_ledger_id,
                'auto_import_code' => $auto_import_code
            ], 'Tạo phiếu nhận hàng trả từ shop: ' . $source_shop_name);

            wp_send_json_success([
                'message' => 'Tạo phiếu nhận hàng trả thành công',
                'ledger_id' => $parent_ledger_id,
                'auto_import_ledger_id' => $auto_import_ledger_id,
                'ledger_code' => $parent_ledger_code,
                'auto_import_code' => $auto_import_code,
                'is_partial' => $is_partial,
                'total_imported' => $total_import_qty,
                'total_max' => $total_max_qty,
                'redirect_url' => admin_url('admin.php?page=tgs-shop-management&view=ticket-return-import-detail&id=' . $parent_ledger_id)
            ]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Duyệt phiếu nhận hàng trả - thực hiện:
     * 1. Chuyển lot sang ACTIVE trong kho shop mẹ
     * 2. Cộng tồn kho không tracking
     * 3. Cập nhật transfer_status thành ACCEPTED
     */
    public static function approve_import()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();
        $current_user_id = get_current_user_id();

        $ledger_id = intval($_POST['ledger_id'] ?? 0);
        $note = sanitize_textarea_field($_POST['note'] ?? '');

        if (!$ledger_id) {
            wp_send_json_error(['message' => 'Thiếu ID phiếu']);
        }

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $ledger_item_table = $wpdb->prefix . 'local_ledger_item';
        $products_table = $wpdb->prefix . 'local_product_name';
        $lots_table = TGS_TABLE_GLOBAL_PRODUCT_LOTS;

        // Lấy thông tin phiếu con nhập kho (type 1 - PURCHASE)
        $child_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$ledger_table}
            WHERE local_ledger_id = %d
            AND local_ledger_type = %d
        ", $ledger_id, TGS_LEDGER_TYPE_PURCHASE));

        if (!$child_ledger) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu nhập kho']);
        }

        if ($child_ledger->local_ledger_approver_status == TGS_APPROVER_STATUS_APPROVED) {
            wp_send_json_error(['message' => 'Phiếu đã được duyệt trước đó']);
        }

        // Tìm phiếu cha RETURN_IMPORT (type 15)
        $parent_id = intval($child_ledger->local_ledger_parent_id);
        if (!$parent_id) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu cha']);
        }

        $parent_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$ledger_table}
            WHERE local_ledger_id = %d
            AND local_ledger_type = %d
        ", $parent_id, TGS_LEDGER_TYPE_RETURN_IMPORT));

        if (!$parent_ledger) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu nhận hàng trả']);
        }

        // Lấy các item từ phiếu con nhập kho
        $items = $wpdb->get_results($wpdb->prepare("
            SELECT li.*, p.local_product_name, p.local_product_is_tracking
            FROM {$ledger_item_table} li
            JOIN {$products_table} p ON li.local_product_name_id = p.local_product_name_id
            WHERE li.local_ledger_id = %d
        ", $ledger_id));

        // Tìm transfer_ledger
        $local_transfer_table = $wpdb->prefix . 'transfer_ledger';
        $local_transfer = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$local_transfer_table}
            WHERE destination_ledger_id = %d
            AND transfer_type = %d
        ", $parent_id, TGS_TRANSFER_TYPE_RETURN_INTERNAL));

        $wpdb->query('START TRANSACTION');

        try {
            foreach ($items as $item) {
                $is_tracking = intval($item->local_product_is_tracking) === 1;

                if ($is_tracking) {
                    $lot_ids = json_decode($item->list_product_lots, true) ?: [];
                    $source_blog_id = $local_transfer ? intval($local_transfer->source_blog_id) : 0;

                    foreach ($lot_ids as $lot_id) {
                        TGS_Global_Lots_Helper::ensure_lot_has_barcode_main($lot_id, $item->local_product_name_id);

                        // Cập nhật lot: về shop mẹ và active
                        $wpdb->update($lots_table, [
                            'local_product_lot_is_active' => TGS_PRODUCT_LOT_ACTIVE,
                            'local_product_name_id' => $item->local_product_name_id,
                            'local_imported_date' => time(),
                            'updated_at' => current_time('mysql')
                        ], ['global_product_lot_id' => $lot_id]);

                        TGS_Global_Lots_Helper::add_lot_log($lot_id, 'return_import_approved', [
                            'previous_status' => TGS_PRODUCT_LOT_PENDING,
                            'new_status' => TGS_PRODUCT_LOT_ACTIVE,
                            'source_blog_id' => $source_blog_id,
                            'to_blog_id' => $current_blog_id,
                            'ledger_id' => $ledger_id,
                            'ledger_code' => $child_ledger->local_ledger_code ?? ''
                        ]);
                    }
                } else {
                    // Cộng tồn kho không tracking
                    $quantity = floatval($item->quantity);

                    $wpdb->query($wpdb->prepare("
                        UPDATE {$products_table}
                        SET local_product_quantity_no_tracking = local_product_quantity_no_tracking + %f,
                            updated_at = %s
                        WHERE local_product_name_id = %d
                    ", $quantity, current_time('mysql'), $item->local_product_name_id));
                }
            }

            // Cập nhật trạng thái phiếu con nhập kho
            $wpdb->update($ledger_table, [
                'local_ledger_approver_status' => TGS_APPROVER_STATUS_APPROVED,
                'local_ledger_status' => TGS_LEDGER_STATUS_APPROVED,
                'local_ledger_approver_id' => $current_user_id,
                'updated_at' => current_time('mysql')
            ], ['local_ledger_id' => $ledger_id]);

            // Cập nhật transfer_ledger
            $final_transfer_status = TGS_TRANSFER_STATUS_ACCEPTED;

            if ($local_transfer) {
                $source_blog_id = intval($local_transfer->source_blog_id);

                // Cập nhật transfer_ledger ở shop con
                switch_to_blog($source_blog_id);

                $source_transfer_table = $wpdb->prefix . 'transfer_ledger';
                $wpdb->update($source_transfer_table, [
                    'transfer_status' => $final_transfer_status,
                    'accepted_at' => current_time('mysql'),
                    'accepted_by_user_id' => $current_user_id
                ], ['transfer_ledger_id' => $local_transfer->transfer_ledger_id]);

                restore_current_blog();

                // Cập nhật transfer_ledger ở shop mẹ
                $wpdb->update($local_transfer_table, [
                    'transfer_status' => $final_transfer_status,
                    'accepted_at' => current_time('mysql'),
                    'accepted_by_user_id' => $current_user_id
                ], ['transfer_ledger_id' => $local_transfer->transfer_ledger_id]);
            }

            $wpdb->query('COMMIT');

            // Log duyệt phiếu
            $source_shop_name = $local_transfer ? get_blog_option(intval($local_transfer->source_blog_id), 'blogname') : '';
            TGS_Shop_Ticket_Helper::add_ticket_log($ledger_id, 'approve', [
                'source_blog_id' => $local_transfer ? intval($local_transfer->source_blog_id) : 0,
                'source_shop_name' => $source_shop_name,
                'items_count' => count($items),
                'transfer_status' => $final_transfer_status,
                'note' => $note,
                'parent_ledger_id' => $parent_id,
                'parent_ledger_code' => $parent_ledger->local_ledger_code ?? ''
            ], !empty($note) ? $note : 'Duyệt phiếu nhận hàng trả từ shop: ' . $source_shop_name);

            wp_send_json_success([
                'message' => 'Duyệt phiếu nhận hàng trả thành công. Hàng đã vào kho.',
                'transfer_status' => $final_transfer_status
            ]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Từ chối phiếu nhận hàng trả
     */
    public static function reject_import()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();
        $current_user_id = get_current_user_id();

        $ledger_id = intval($_POST['ledger_id'] ?? 0);
        $note = sanitize_textarea_field($_POST['note'] ?? '');

        if (!$ledger_id) {
            wp_send_json_error(['message' => 'Thiếu ID phiếu']);
        }

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $transfer_table = $wpdb->prefix . 'transfer_ledger';
        $lots_table = TGS_TABLE_GLOBAL_PRODUCT_LOTS;

        // Lấy thông tin phiếu con nhập kho
        $child_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$ledger_table}
            WHERE local_ledger_id = %d
            AND local_ledger_type = %d
        ", $ledger_id, TGS_LEDGER_TYPE_PURCHASE));

        if (!$child_ledger) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu nhập kho']);
        }

        if ($child_ledger->local_ledger_approver_status == TGS_APPROVER_STATUS_APPROVED) {
            wp_send_json_error(['message' => 'Phiếu đã được duyệt, không thể từ chối']);
        }

        $parent_id = intval($child_ledger->local_ledger_parent_id);

        // Tìm transfer_ledger
        $local_transfer = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$transfer_table}
            WHERE destination_ledger_id = %d
            AND transfer_type = %d
        ", $parent_id, TGS_TRANSFER_TYPE_RETURN_INTERNAL));

        $wpdb->query('START TRANSACTION');

        try {
            // Cập nhật trạng thái phiếu con
            $wpdb->update($ledger_table, [
                'local_ledger_approver_status' => TGS_APPROVER_STATUS_REJECTED,
                'local_ledger_status' => TGS_LEDGER_STATUS_REJECTED,
                'local_ledger_approver_id' => $current_user_id,
                'updated_at' => current_time('mysql')
            ], ['local_ledger_id' => $ledger_id]);

            // Cập nhật phiếu cha
            if ($parent_id) {
                $wpdb->update($ledger_table, [
                    'local_ledger_approver_status' => TGS_APPROVER_STATUS_REJECTED,
                    'local_ledger_status' => TGS_LEDGER_STATUS_REJECTED,
                    'updated_at' => current_time('mysql')
                ], ['local_ledger_id' => $parent_id]);
            }

            // Cập nhật transfer_ledger
            if ($local_transfer) {
                $source_blog_id = intval($local_transfer->source_blog_id);

                // Cập nhật ở shop con
                switch_to_blog($source_blog_id);

                $source_transfer_table = $wpdb->prefix . 'transfer_ledger';
                $wpdb->update($source_transfer_table, [
                    'transfer_status' => TGS_TRANSFER_STATUS_REJECTED
                ], ['transfer_ledger_id' => $local_transfer->transfer_ledger_id]);

                restore_current_blog();

                // Cập nhật ở shop mẹ
                $wpdb->update($transfer_table, [
                    'transfer_status' => TGS_TRANSFER_STATUS_REJECTED
                ], ['transfer_ledger_id' => $local_transfer->transfer_ledger_id]);
            }

            $wpdb->query('COMMIT');

            TGS_Shop_Ticket_Helper::add_ticket_log($ledger_id, 'reject', [
                'note' => $note
            ], !empty($note) ? $note : 'Từ chối phiếu nhận hàng trả');

            wp_send_json_success([
                'message' => 'Đã từ chối phiếu nhận hàng trả.'
            ]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Danh sách phiếu trả đã tạo (shop con xem)
     */
    public static function get_exports_list()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $transfer_table = $wpdb->prefix . 'transfer_ledger';

        $exports = $wpdb->get_results($wpdb->prepare("
            SELECT l.*, t.destination_blog_id, t.transfer_status, t.transfer_ledger_id
            FROM {$ledger_table} l
            LEFT JOIN {$transfer_table} t ON l.local_ledger_id = t.source_ledger_id
                AND t.transfer_type = %d
            WHERE l.local_ledger_type = %d
            AND (l.is_deleted IS NULL OR l.is_deleted = 0)
            ORDER BY l.created_at DESC
        ", TGS_TRANSFER_TYPE_RETURN_INTERNAL, TGS_LEDGER_TYPE_RETURN_EXPORT));

        // Thêm tên shop đích
        foreach ($exports as &$export) {
            if ($export->destination_blog_id) {
                $blog_details = get_blog_details($export->destination_blog_id);
                $export->destination_shop_name = $blog_details ? $blog_details->blogname : 'Shop #' . $export->destination_blog_id;
            }
        }

        wp_send_json_success(['exports' => $exports]);
    }

    /**
     * Danh sách phiếu nhận hàng trả (shop mẹ xem)
     */
    public static function get_imports_list()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        global $wpdb;

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $transfer_table = $wpdb->prefix . 'transfer_ledger';

        $imports = $wpdb->get_results($wpdb->prepare("
            SELECT l.*, t.source_blog_id, t.transfer_status, t.transfer_ledger_id
            FROM {$ledger_table} l
            LEFT JOIN {$transfer_table} t ON l.local_ledger_id = t.destination_ledger_id
                AND t.transfer_type = %d
            WHERE l.local_ledger_type = %d
            AND (l.is_deleted IS NULL OR l.is_deleted = 0)
            ORDER BY l.created_at DESC
        ", TGS_TRANSFER_TYPE_RETURN_INTERNAL, TGS_LEDGER_TYPE_RETURN_IMPORT));

        // Thêm tên shop nguồn
        foreach ($imports as &$import) {
            if ($import->source_blog_id) {
                $blog_details = get_blog_details($import->source_blog_id);
                $import->source_shop_name = $blog_details ? $blog_details->blogname : 'Shop #' . $import->source_blog_id;
            }
        }

        wp_send_json_success(['imports' => $imports]);
    }

    /**
     * Chi tiết phiếu return (export hoặc import)
     */
    public static function get_detail()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        global $wpdb;

        $ledger_id = intval($_POST['ledger_id'] ?? 0);
        $type = sanitize_text_field($_POST['type'] ?? 'export');

        if (!$ledger_id) {
            wp_send_json_error(['message' => 'Thiếu ID phiếu']);
        }

        $ledger_type = $type === 'import' ? TGS_LEDGER_TYPE_RETURN_IMPORT : TGS_LEDGER_TYPE_RETURN_EXPORT;

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $ledger_item_table = $wpdb->prefix . 'local_ledger_item';
        $products_table = $wpdb->prefix . 'local_product_name';

        $ledger = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$ledger_table}
            WHERE local_ledger_id = %d
            AND local_ledger_type = %d
        ", $ledger_id, $ledger_type));

        if (!$ledger) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu']);
        }

        // Lấy các item từ local_ledger_item_id
        $items = [];
        if (!empty($ledger->local_ledger_item_id)) {
            $item_ids = json_decode($ledger->local_ledger_item_id, true) ?: [];
            if (!empty($item_ids)) {
                $item_ids_str = implode(',', array_map('intval', $item_ids));
                $items = $wpdb->get_results("
                    SELECT li.*, p.local_product_name, p.local_product_barcode_main, p.local_product_is_tracking
                    FROM {$ledger_item_table} li
                    JOIN {$products_table} p ON li.local_product_name_id = p.local_product_name_id
                    WHERE li.local_ledger_item_id IN ({$item_ids_str})
                    AND (li.is_deleted = 0 OR li.is_deleted IS NULL)
                ");
            }
        }

        // Lấy thông tin transfer
        $transfer = null;
        $transfer_table = $wpdb->prefix . 'transfer_ledger';

        if ($type === 'export') {
            $transfer = $wpdb->get_row($wpdb->prepare("
                SELECT * FROM {$transfer_table}
                WHERE source_ledger_id = %d
                AND transfer_type = %d
            ", $ledger_id, TGS_TRANSFER_TYPE_RETURN_INTERNAL));

            if ($transfer && $transfer->destination_blog_id) {
                $blog_details = get_blog_details($transfer->destination_blog_id);
                $transfer->destination_shop_name = $blog_details ? $blog_details->blogname : 'Shop #' . $transfer->destination_blog_id;
            }
        } else {
            $transfer = $wpdb->get_row($wpdb->prepare("
                SELECT * FROM {$transfer_table}
                WHERE destination_ledger_id = %d
                AND transfer_type = %d
            ", $ledger_id, TGS_TRANSFER_TYPE_RETURN_INTERNAL));

            if ($transfer && $transfer->source_blog_id) {
                $blog_details = get_blog_details($transfer->source_blog_id);
                $transfer->source_shop_name = $blog_details ? $blog_details->blogname : 'Shop #' . $transfer->source_blog_id;
            }
        }

        wp_send_json_success([
            'ledger' => $ledger,
            'items' => $items,
            'transfer' => $transfer
        ]);
    }

    /**
     * Lấy chi tiết phiếu trả từ transfer_ledger (shop mẹ xem để nhận hàng)
     */
    public static function get_return_detail()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();

        $return_id = intval($_POST['return_id'] ?? $_POST['transfer_id'] ?? 0);

        if (!$return_id) {
            wp_send_json_error(['message' => 'Thiếu ID phiếu trả']);
        }

        // Query bảng transfer_ledger của shop hiện tại
        $transfer_table = $wpdb->prefix . 'transfer_ledger';

        $transfer = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$transfer_table}
            WHERE transfer_ledger_id = %d
            AND transfer_type = %d
        ", $return_id, TGS_TRANSFER_TYPE_RETURN_INTERNAL));

        if (!$transfer) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu trả']);
        }

        $source_blog_id = intval($transfer->source_blog_id);
        $source_ledger_id = intval($transfer->source_ledger_id);
        $destination_blog_id = intval($transfer->destination_blog_id);

        // Switch sang shop con để lấy thông tin phiếu và items
        switch_to_blog($source_blog_id);

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $ledger_item_table = $wpdb->prefix . 'local_ledger_item';
        $products_table = $wpdb->prefix . 'local_product_name';

        // Lấy thông tin phiếu cha (RETURN_EXPORT)
        $ledger = $wpdb->get_row($wpdb->prepare("
            SELECT local_ledger_code, local_ledger_total_amount, local_ledger_note,
                   local_ledger_approver_status, local_ledger_item_id
            FROM {$ledger_table}
            WHERE local_ledger_id = %d
        ", $source_ledger_id));

        if ($ledger) {
            $transfer->local_ledger_code = $ledger->local_ledger_code;
            $transfer->local_ledger_total_amount = $ledger->local_ledger_total_amount;
            $transfer->local_ledger_note = $ledger->local_ledger_note;
            $transfer->local_ledger_approver_status = $ledger->local_ledger_approver_status;
        }

        // Kiểm tra phiếu xuất tự động đã duyệt chưa
        $auto_export_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT local_ledger_id, local_ledger_approver_status
            FROM {$ledger_table}
            WHERE local_ledger_parent_id = %d
            AND local_ledger_type = %d
        ", $source_ledger_id, TGS_LEDGER_TYPE_SALE));

        if ($auto_export_ledger) {
            // Set trạng thái duyệt dựa vào phiếu xuất tự động
            $transfer->local_ledger_approver_status = $auto_export_ledger->local_ledger_approver_status;
        }

        $transfer->source_shop_name = get_bloginfo('name');

        // Lấy items từ local_ledger_item_id
        $items = [];
        if ($ledger && !empty($ledger->local_ledger_item_id)) {
            $item_ids = json_decode($ledger->local_ledger_item_id, true) ?: [];
            if (!empty($item_ids)) {
                $item_ids_str = implode(',', array_map('intval', $item_ids));
                $items = $wpdb->get_results("
                    SELECT li.*, p.local_product_name as product_name,
                           p.local_product_barcode_main as barcode_main,
                           p.local_product_is_tracking as is_tracking
                    FROM {$ledger_item_table} li
                    JOIN {$products_table} p ON li.local_product_name_id = p.local_product_name_id
                    WHERE li.local_ledger_item_id IN ({$item_ids_str})
                    AND (li.is_deleted = 0 OR li.is_deleted IS NULL)
                ");
            }
        }

        restore_current_blog();

        // Kiểm tra sync status cho từng sản phẩm ở shop đích
        switch_to_blog($destination_blog_id);
        $dest_products_table = $wpdb->prefix . 'local_product_name';

        foreach ($items as &$item) {
            $exists = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$dest_products_table}
                WHERE local_product_barcode_main = %s
                AND (is_deleted IS NULL OR is_deleted = 0)
            ", $item->barcode_main));

            $item->synced_in_destination = ($exists > 0);
        }

        restore_current_blog();

        // Lấy thông tin chi tiết lots
        $lots_table = TGS_TABLE_GLOBAL_PRODUCT_LOTS;
        foreach ($items as &$item) {
            $item->lots_detail = [];

            if ($item->is_tracking && !empty($item->list_product_lots)) {
                $lot_ids = json_decode($item->list_product_lots, true);
                if (!empty($lot_ids) && is_array($lot_ids)) {
                    $lots = TGS_Global_Lots_Helper::get_lots_by_ids($lot_ids);

                    foreach ($lots as $lot) {
                        $item->lots_detail[] = [
                            'id' => intval($lot->global_product_lot_id),
                            'barcode' => $lot->global_product_lot_barcode,
                            'exp_date' => $lot->exp_date,
                            'mfg_date' => $lot->mfg_date,
                            'lot_code' => $lot->lot_code
                        ];
                    }
                }
            }
        }

        wp_send_json_success([
            'return' => $transfer,
            'items' => $items
        ]);
    }

    /**
     * Lấy items của phiếu trả
     */
    public static function get_return_items()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        global $wpdb;

        // Support cả transfer_id và return_id (alias)
        $transfer_id = 0;
        if (!empty($_POST['transfer_id'])) {
            $transfer_id = intval($_POST['transfer_id']);
        } elseif (!empty($_POST['return_id'])) {
            $transfer_id = intval($_POST['return_id']);
        }

        if (!$transfer_id) {
            wp_send_json_error(['message' => 'Thiếu ID transfer']);
        }

        $current_blog_id = get_current_blog_id();
        $items = [];

        // Query bảng transfer_ledger của shop hiện tại
        $transfer_table = $wpdb->prefix . 'transfer_ledger';

        $transfer = $wpdb->get_row($wpdb->prepare("
            SELECT source_blog_id, source_ledger_id
            FROM {$transfer_table}
            WHERE transfer_ledger_id = %d
            AND transfer_type = %d
        ", $transfer_id, TGS_TRANSFER_TYPE_RETURN_INTERNAL));

        if (!$transfer) {
            wp_send_json_error(['message' => 'Không tìm thấy thông tin transfer']);
        }

        $source_blog_id = intval($transfer->source_blog_id);
        $source_ledger_id = intval($transfer->source_ledger_id);

        // Switch sang shop con để lấy danh sách sản phẩm
        switch_to_blog($source_blog_id);

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $ledger_item_table = $wpdb->prefix . 'local_ledger_item';
        $products_table = $wpdb->prefix . 'local_product_name';

        // Lấy local_ledger_item_id từ phiếu cha
        $source_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT local_ledger_item_id FROM {$ledger_table}
            WHERE local_ledger_id = %d
        ", $source_ledger_id));

        $items = [];
        if ($source_ledger && !empty($source_ledger->local_ledger_item_id)) {
            $item_ids = json_decode($source_ledger->local_ledger_item_id, true) ?: [];
            if (!empty($item_ids)) {
                $item_ids_str = implode(',', array_map('intval', $item_ids));
                $items = $wpdb->get_results("
                    SELECT li.*,
                           p.local_product_name as product_name,
                           p.local_product_barcode_main as barcode_main,
                           p.local_product_is_tracking as is_tracking
                    FROM {$ledger_item_table} li
                    JOIN {$products_table} p ON li.local_product_name_id = p.local_product_name_id
                    WHERE li.local_ledger_item_id IN ({$item_ids_str})
                    AND (li.is_deleted = 0 OR li.is_deleted IS NULL)
                ");
            }
        }

        restore_current_blog();

        wp_send_json_success($items);
    }

    /**
     * Cập nhật tình trạng lot (condition) khi nhận hàng trả
     */
    public static function update_lot_conditions()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        global $wpdb;

        $lots_json = isset($_POST['lots']) ? wp_unslash($_POST['lots']) : '';
        $lots = json_decode($lots_json, true);

        if (empty($lots) || !is_array($lots)) {
            wp_send_json_error(['message' => 'Không có dữ liệu lot để cập nhật']);
        }

        $lots_table = TGS_TABLE_GLOBAL_PRODUCT_LOTS;
        $updated_count = 0;
        $errors = [];

        foreach ($lots as $lot_data) {
            $lot_id = intval($lot_data['lot_id'] ?? 0);
            $condition = intval($lot_data['condition'] ?? 0);

            if ($lot_id <= 0) {
                continue;
            }

            // Validate condition (0 = Mới, 1 = Hàng lỗi)
            if (!in_array($condition, [0, 1])) {
                $condition = 0;
            }

            $result = $wpdb->update(
                $lots_table,
                [
                    'global_product_lot_condition' => $condition,
                    'updated_at' => current_time('mysql')
                ],
                ['global_product_lot_id' => $lot_id]
            );

            if ($result !== false) {
                $updated_count++;
            } else {
                $errors[] = "Lỗi cập nhật lot ID: {$lot_id}";
            }
        }

        if (!empty($errors)) {
            wp_send_json_error([
                'message' => 'Có lỗi khi cập nhật: ' . implode(', ', $errors),
                'updated_count' => $updated_count
            ]);
        }

        wp_send_json_success([
            'message' => "Đã cập nhật {$updated_count} mã định danh",
            'updated_count' => $updated_count
        ]);
    }

    /**
     * Lấy dữ liệu báo cáo trả hàng nội bộ
     */
    public static function get_report_data()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();

        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $transfer_table = $wpdb->prefix . 'transfer_ledger';

        // Build date conditions
        $date_condition = '';
        $date_params = [];
        if (!empty($date_from)) {
            $date_condition .= ' AND l.created_at >= %s';
            $date_params[] = $date_from . ' 00:00:00';
        }
        if (!empty($date_to)) {
            $date_condition .= ' AND l.created_at <= %s';
            $date_params[] = $date_to . ' 23:59:59';
        }

        // ========== TỔNG QUAN ==========
        // Số phiếu trả (RETURN_EXPORT)
        $export_count_query = "
            SELECT COUNT(*) FROM {$ledger_table} l
            WHERE l.local_ledger_type = %d
            AND (l.is_deleted IS NULL OR l.is_deleted = 0)
            {$date_condition}
        ";
        $export_count = $wpdb->get_var($wpdb->prepare($export_count_query, TGS_LEDGER_TYPE_RETURN_EXPORT, ...$date_params));

        // Số phiếu trả đã duyệt
        $export_approved_query = "
            SELECT COUNT(*) FROM {$ledger_table} l
            WHERE l.local_ledger_type = %d
            AND l.local_ledger_approver_status = %d
            AND (l.is_deleted IS NULL OR l.is_deleted = 0)
            {$date_condition}
        ";
        $export_approved = $wpdb->get_var($wpdb->prepare($export_approved_query, TGS_LEDGER_TYPE_RETURN_EXPORT, TGS_APPROVER_STATUS_APPROVED, ...$date_params));

        // Số phiếu nhận (RETURN_IMPORT)
        $import_count_query = "
            SELECT COUNT(*) FROM {$ledger_table} l
            WHERE l.local_ledger_type = %d
            AND (l.is_deleted IS NULL OR l.is_deleted = 0)
            {$date_condition}
        ";
        $import_count = $wpdb->get_var($wpdb->prepare($import_count_query, TGS_LEDGER_TYPE_RETURN_IMPORT, ...$date_params));

        // Số phiếu nhận đã duyệt
        $import_approved_query = "
            SELECT COUNT(*) FROM {$ledger_table} l
            WHERE l.local_ledger_type = %d
            AND l.local_ledger_approver_status = %d
            AND (l.is_deleted IS NULL OR l.is_deleted = 0)
            {$date_condition}
        ";
        $import_approved = $wpdb->get_var($wpdb->prepare($import_approved_query, TGS_LEDGER_TYPE_RETURN_IMPORT, TGS_APPROVER_STATUS_APPROVED, ...$date_params));

        // Số phiếu chờ nhận
        $pending_receive = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$transfer_table}
            WHERE destination_blog_id = %d
            AND transfer_type = %d
            AND (destination_ledger_id IS NULL OR destination_ledger_id = 0)
            AND transfer_status != %d
        ", $current_blog_id, TGS_TRANSFER_TYPE_RETURN_INTERNAL, TGS_TRANSFER_STATUS_ACCEPTED));

        // Tổng giá trị trả
        $total_export_value_query = "
            SELECT COALESCE(SUM(l.local_ledger_total_amount), 0) FROM {$ledger_table} l
            WHERE l.local_ledger_type = %d
            AND l.local_ledger_approver_status = %d
            AND (l.is_deleted IS NULL OR l.is_deleted = 0)
            {$date_condition}
        ";
        $total_export_value = $wpdb->get_var($wpdb->prepare($total_export_value_query, TGS_LEDGER_TYPE_RETURN_EXPORT, TGS_APPROVER_STATUS_APPROVED, ...$date_params));

        // Tổng giá trị nhận
        $total_import_value_query = "
            SELECT COALESCE(SUM(l.local_ledger_total_amount), 0) FROM {$ledger_table} l
            WHERE l.local_ledger_type = %d
            AND l.local_ledger_approver_status = %d
            AND (l.is_deleted IS NULL OR l.is_deleted = 0)
            {$date_condition}
        ";
        $total_import_value = $wpdb->get_var($wpdb->prepare($total_import_value_query, TGS_LEDGER_TYPE_RETURN_IMPORT, TGS_APPROVER_STATUS_APPROVED, ...$date_params));

        // ========== PHIẾU GẦN ĐÂY ==========
        $recent_exports = $wpdb->get_results($wpdb->prepare("
            SELECT l.local_ledger_id, l.local_ledger_code, l.local_ledger_total_amount,
                   l.local_ledger_approver_status, l.created_at,
                   t.destination_blog_id, t.transfer_status
            FROM {$ledger_table} l
            LEFT JOIN {$transfer_table} t ON l.local_ledger_id = t.source_ledger_id
                AND t.transfer_type = %d
            WHERE l.local_ledger_type = %d
            AND (l.is_deleted IS NULL OR l.is_deleted = 0)
            ORDER BY l.created_at DESC
            LIMIT 10
        ", TGS_TRANSFER_TYPE_RETURN_INTERNAL, TGS_LEDGER_TYPE_RETURN_EXPORT));

        foreach ($recent_exports as &$export) {
            if ($export->destination_blog_id) {
                $blog_details = get_blog_details($export->destination_blog_id);
                $export->destination_shop_name = $blog_details ? $blog_details->blogname : 'Shop #' . $export->destination_blog_id;
            }
        }

        $recent_imports = $wpdb->get_results($wpdb->prepare("
            SELECT l.local_ledger_id, l.local_ledger_code, l.local_ledger_total_amount,
                   l.local_ledger_approver_status, l.created_at,
                   t.source_blog_id, t.transfer_status
            FROM {$ledger_table} l
            LEFT JOIN {$transfer_table} t ON l.local_ledger_id = t.destination_ledger_id
                AND t.transfer_type = %d
            WHERE l.local_ledger_type = %d
            AND (l.is_deleted IS NULL OR l.is_deleted = 0)
            ORDER BY l.created_at DESC
            LIMIT 10
        ", TGS_TRANSFER_TYPE_RETURN_INTERNAL, TGS_LEDGER_TYPE_RETURN_IMPORT));

        foreach ($recent_imports as &$import) {
            if ($import->source_blog_id) {
                $blog_details = get_blog_details($import->source_blog_id);
                $import->source_shop_name = $blog_details ? $blog_details->blogname : 'Shop #' . $import->source_blog_id;
            }
        }

        wp_send_json_success([
            'summary' => [
                'export_count' => intval($export_count),
                'export_approved' => intval($export_approved),
                'export_pending' => intval($export_count) - intval($export_approved),
                'import_count' => intval($import_count),
                'import_approved' => intval($import_approved),
                'import_pending' => intval($import_count) - intval($import_approved),
                'pending_receive' => intval($pending_receive),
                'total_export_value' => floatval($total_export_value),
                'total_import_value' => floatval($total_import_value)
            ],
            'recent_exports' => $recent_exports,
            'recent_imports' => $recent_imports
        ]);
    }
}