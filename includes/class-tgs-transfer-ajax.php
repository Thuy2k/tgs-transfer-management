<?php
/**
 * TGS Transfer AJAX Handler
 *
 * Xử lý xuất/nhập hàng giữa các shop trong multisite
 *
 * @package tgs_transfer_management
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Transfer_Ajax
{
    /**
     * Constructor - đăng ký các AJAX actions
     */
    public static function init()
    {
        // Xuất hàng
        add_action('wp_ajax_tgs_transfer_get_products', [__CLASS__, 'get_products']);
        add_action('wp_ajax_tgs_transfer_check_products_sync', [__CLASS__, 'check_products_sync']);
        add_action('wp_ajax_tgs_transfer_create_export', [__CLASS__, 'create_export']);
        add_action('wp_ajax_tgs_transfer_approve_export', [__CLASS__, 'approve_export']);
        add_action('wp_ajax_tgs_transfer_reject_export', [__CLASS__, 'reject_export']);

        // Nhập hàng
        add_action('wp_ajax_tgs_transfer_get_pending_imports', [__CLASS__, 'get_pending_imports']);
        add_action('wp_ajax_tgs_transfer_create_import', [__CLASS__, 'create_import']);
        add_action('wp_ajax_tgs_transfer_approve_import', [__CLASS__, 'approve_import']);
        add_action('wp_ajax_tgs_transfer_reject_import', [__CLASS__, 'reject_import']);

        // Danh sách phiếu
        add_action('wp_ajax_tgs_transfer_get_exports_list', [__CLASS__, 'get_exports_list']);
        add_action('wp_ajax_tgs_transfer_get_imports_list', [__CLASS__, 'get_imports_list']);
        add_action('wp_ajax_tgs_transfer_get_detail', [__CLASS__, 'get_detail']);

        // Transfer detail
        add_action('wp_ajax_tgs_transfer_get_transfer_detail', [__CLASS__, 'get_transfer_detail']);
        add_action('wp_ajax_tgs_transfer_get_items', [__CLASS__, 'get_transfer_items']);

        // Report
        add_action('wp_ajax_tgs_transfer_get_report_data', [__CLASS__, 'get_report_data']);
    }

    /**
     * Lấy danh sách sản phẩm có tồn kho để xuất
     */
    public static function get_products()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

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
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

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
     * Tạo phiếu xuất đến shop con
     *
     * Luồng mới theo mô hình cha-con (giống phiếu bán hàng):
     * 1. Tạo phiếu xuất xuống con (PARENT) - type = TRANSFER_EXPORT, KHÔNG có items
     * 2. Tạo phiếu xuất tự động (CHILD) - type = SALE, CÓ items, parent_id = phiếu cha
     * 3. Cập nhật phiếu cha với local_ledger_item_id = items từ phiếu con
     * 4. Tạo transfer_ledger với source_ledger_id = phiếu cha, source_ledger_item_id = items phiếu con
     */
    public static function create_export()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();
        $current_user_id = get_current_user_id();

        $destination_blog_id = intval($_POST['destination_blog_id'] ?? 0);
        $ledger_code = sanitize_text_field($_POST['ledger_code'] ?? '');
        $transfer_note = sanitize_textarea_field($_POST['transfer_note'] ?? '');
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

            // 1. Tạo mã phiếu cha (xuất xuống con)
            if (empty($ledger_code)) {
                $ledger_code = 'TXS-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -4));
            }
            // Mã phiếu con (xuất tự động)
            $auto_export_code = 'AXS-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -4));

            // ========== BƯỚC 1: Validate và chuẩn bị items ==========
            $total_amount = 0;
            $export_items_data = []; // Items cho phiếu xuất tự động

            foreach ($items as $item) {
                $product_id = intval($item['product_id']);
                $is_tracking = !empty($item['is_tracking']);
                $quantity = floatval($item['quantity'] ?? 0);

                // Lấy thông tin sản phẩm
                $product = $wpdb->get_row($wpdb->prepare("
                    SELECT * FROM {$products_table}
                    WHERE local_product_name_id = %d
                ", $product_id));

                if (!$product) {
                    throw new Exception("Sản phẩm ID {$product_id} không tồn tại");
                }

                $price = floatval($product->local_product_price ?? 0);

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
                    $subtotal = $quantity * $price;
                    $total_amount += $subtotal;

                    $export_items_data[] = [
                        'product_id' => $product_id,
                        'quantity' => $quantity,
                        'price' => $price,
                        'lot_barcodes' => $lot_barcodes,
                        'lot_ids' => $lot_ids,
                        'is_tracking' => true,
                        'product' => $product
                    ];

                } else {
                    // Xử lý sản phẩm không tracking
                    $available_stock = floatval($product->local_product_quantity_no_tracking ?? 0);

                    if ($quantity > $available_stock) {
                        throw new Exception("Số lượng xuất ({$quantity}) vượt quá tồn kho ({$available_stock}) cho sản phẩm: {$product->local_product_name}");
                    }

                    $subtotal = $quantity * $price;
                    $total_amount += $subtotal;

                    $export_items_data[] = [
                        'product_id' => $product_id,
                        'quantity' => $quantity,
                        'price' => $price,
                        'lot_barcodes' => [],
                        'is_tracking' => false,
                        'product' => $product
                    ];
                }
            }

            // ========== BƯỚC 2: Tạo phiếu CHA (Xuất xuống con) - KHÔNG có items ==========
            $wpdb->insert($ledger_table, [
                'local_ledger_code' => $ledger_code,
                'local_ledger_type' => TGS_LEDGER_TYPE_TRANSFER_EXPORT, // Type 12
                'local_ledger_note' => $transfer_note,
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
                throw new Exception('Lỗi tạo phiếu xuất xuống con (phiếu cha)');
            }

            // ========== BƯỚC 3: Tạo phiếu CON (Xuất tự động) - CÓ items ==========
            // Sử dụng TGS_Shop_Base_Import_Export::create_export_ledger
            $auto_export_ledger_data = [
                'local_ledger_code' => $auto_export_code,
                'local_ledger_type' => TGS_LEDGER_TYPE_SALE, // Type 2 - Phiếu xuất
                'local_ledger_note' => 'Xuất tự động từ phiếu: ' . $ledger_code,
                'local_ledger_total_amount' => $total_amount,
                'local_ledger_status' => TGS_LEDGER_STATUS_PENDING,
                'local_ledger_approver_status' => TGS_APPROVER_STATUS_PENDING,
                'user_id' => $current_user_id,
            ];

            $auto_export_result = TGS_Shop_Base_Import_Export::create_export_ledger(
                $auto_export_ledger_data,
                $export_items_data,
                $parent_ledger_id // parent_id = phiếu cha
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
                        // Tự động điền local_product_barcode_main nếu chưa có
                        TGS_Global_Lots_Helper::ensure_lot_has_barcode_main($lot_id, $item_data['product_id']);

                        $wpdb->update($lots_table, [
                            'local_product_lot_is_active' => TGS_PRODUCT_LOT_PENDING, // 2 - Chờ duyệt
                            'updated_at' => current_time('mysql')
                        ], ['global_product_lot_id' => $lot_id]);

                        // Ghi log vào product_lot_meta
                        TGS_Global_Lots_Helper::add_lot_log($lot_id, 'transfer_export_created', [
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

                    // Ghi log trừ kho
                    if (class_exists('TGS_Shop_Base_Import_Export') && method_exists('TGS_Shop_Base_Import_Export', 'add_no_tracking_stock_log')) {
                        TGS_Shop_Base_Import_Export::add_no_tracking_stock_log(
                            $item_data['product_id'],
                            'transfer_export_create',
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

            // ========== BƯỚC 6: Tạo transfer_ledger ở shop MẸ ==========
            $transfer_table = $wpdb->prefix . 'transfer_ledger';

            $wpdb->insert($transfer_table, [
                'source_blog_id' => $current_blog_id,
                'source_ledger_id' => $parent_ledger_id, // Phiếu cha
                'source_ledger_item_id' => $items_json, // Items từ phiếu con (xuất tự động)
                'destination_blog_id' => $destination_blog_id,
                'transfer_status' => TGS_TRANSFER_STATUS_PENDING,
                'transfer_type' => TGS_TRANSFER_TYPE_INTERNAL,
                'created_at' => current_time('mysql'),
                'created_by_user_id' => $current_user_id,
                'transfer_note' => $transfer_note
            ]);

            $transfer_id = $wpdb->insert_id;

            if (!$transfer_id) {
                throw new Exception('Lỗi tạo bản ghi transfer ở shop mẹ');
            }

            // ========== BƯỚC 7: Tạo transfer_ledger ở shop CON ==========
            switch_to_blog($destination_blog_id);

            $dest_transfer_table = $wpdb->prefix . 'transfer_ledger';

            $wpdb->insert($dest_transfer_table, [
                'source_blog_id' => $current_blog_id,
                'source_ledger_id' => $parent_ledger_id, // Phiếu cha
                'source_ledger_item_id' => $items_json, // Items từ phiếu con
                'destination_blog_id' => $destination_blog_id,
                'transfer_status' => TGS_TRANSFER_STATUS_PENDING,
                'transfer_type' => TGS_TRANSFER_TYPE_INTERNAL,
                'created_at' => current_time('mysql'),
                'created_by_user_id' => $current_user_id,
                'transfer_note' => $transfer_note
            ]);

            $dest_transfer_id = $wpdb->insert_id;

            restore_current_blog();

            if (!$dest_transfer_id) {
                throw new Exception('Lỗi tạo bản ghi transfer ở shop con');
            }

            $wpdb->query('COMMIT');

            // Thêm log tạo phiếu xuất transfer
            $dest_shop_name = get_blog_option($destination_blog_id, 'blogname');
            TGS_Shop_Ticket_Helper::add_ticket_log($parent_ledger_id, 'create', [
                'destination_blog_id' => $destination_blog_id,
                'destination_shop_name' => $dest_shop_name,
                'items_count' => count($export_items_data),
                'total_amount' => $total_amount,
                'auto_export_ledger_id' => $auto_export_ledger_id,
                'auto_export_code' => $auto_export_code
            ], 'Tạo phiếu xuất đến shop: ' . $dest_shop_name);

            wp_send_json_success([
                'message' => 'Tạo phiếu xuất thành công',
                'ledger_id' => $parent_ledger_id,
                'auto_export_ledger_id' => $auto_export_ledger_id,
                'transfer_id' => $transfer_id,
                'ledger_code' => $ledger_code,
                'auto_export_code' => $auto_export_code,
                'redirect_url' => admin_url('admin.php?page=tgs-shop-management&view=ticket-transfer-export-detail&id=' . $parent_ledger_id)
            ]);

        } catch (Exception $e) {

            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Duyệt phiếu xuất - thực hiện:
     * 1. Cập nhật trạng thái lot thành PENDING (chờ nhận)
     * 2. Trừ tồn kho không tracking
     * 3. Đồng bộ sản phẩm sang shop đích nếu cần
     * 4. Tạo thông báo cho shop đích
     */
    public static function approve_export()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();
        $current_user_id = get_current_user_id();

        $ledger_id = intval($_POST['ledger_id'] ?? 0);
        $ledger_type = intval($_POST['ledger_type'] ?? 0);
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

        // Tìm phiếu cha TRANSFER_EXPORT (type 12)
        $parent_id = intval($child_ledger->local_ledger_parent_id);
        if (!$parent_id) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu cha']);
        }

        $parent_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$ledger_table}
            WHERE local_ledger_id = %d
            AND local_ledger_type = %d
        ", $parent_id, TGS_LEDGER_TYPE_TRANSFER_EXPORT));

        if (!$parent_ledger) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu xuất đến shop con']);
        }

        // Lấy thông tin transfer từ phiếu cha
        $transfer = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$transfer_table}
            WHERE source_ledger_id = %d
            AND source_blog_id = %d
        ", $parent_id, $current_blog_id));

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
                    // Cập nhật trạng thái lot thành PENDING (đang chờ nhận ở shop đích)
                    $lot_ids = json_decode($item->list_product_lots, true) ?: [];

                    foreach ($lot_ids as $lot_id) {
                        // Lấy lot hiện tại để biết previous_status
                        $current_lot = TGS_Global_Lots_Helper::get_lot_by_id($lot_id);
                        $previous_status = $current_lot ? $current_lot->local_product_lot_is_active : TGS_PRODUCT_LOT_PENDING;

                        // Tự động điền local_product_barcode_main nếu chưa có
                        TGS_Global_Lots_Helper::ensure_lot_has_barcode_main($lot_id, $item->local_product_name_id);

                        $wpdb->update($lots_table, [
                            'source_blog_id' => $current_blog_id, // Shop mẹ xuất đi
                            'to_blog_id' => $destination_blog_id, // Shop con đích
                            'local_product_lot_is_active' => TGS_PRODUCT_LOT_PENDING,
                            'local_exported_date' => time(),
                            'updated_at' => current_time('mysql')
                        ], ['global_product_lot_id' => $lot_id]);

                        // ========== GHI LOG VÀO product_lot_meta ==========
                        TGS_Global_Lots_Helper::add_lot_log($lot_id, 'transfer_export_approved', [
                            'previous_status' => $previous_status,
                            'new_status' => TGS_PRODUCT_LOT_PENDING,
                            'source_blog_id' => $current_blog_id,
                            'destination_blog_id' => $destination_blog_id,
                            'ledger_id' => $ledger_id,
                            'ledger_code' => $child_ledger->local_ledger_code ?? ''
                        ]);
                    }

                } else {
                    // ========== KHÔNG TRỪ TỒN KHO KHI DUYỆT PHIẾU XUẤT TRANSFER ==========
                    // Theo yêu cầu fileyeucauthuy5: Đã trừ ngay khi tạo phiếu rồi
                    // Khi duyệt chỉ cần giữ nguyên, không làm gì thêm cho sản phẩm non-tracking
                }

                // Đồng bộ sản phẩm sang shop đích nếu chưa có
                self::sync_product_to_destination($item, $destination_blog_id, $current_blog_id);
            }

            // Cập nhật trạng thái phiếu con xuất kho
            $wpdb->update($ledger_table, [
                'local_ledger_approver_status' => TGS_APPROVER_STATUS_APPROVED,
                'local_ledger_status' => TGS_LEDGER_STATUS_APPROVED,
                'local_ledger_approver_id' => $current_user_id,
                'updated_at' => current_time('mysql')
            ], ['local_ledger_id' => $ledger_id]);

            // Cập nhật transfer_ledger - sẵn sàng cho shop đích nhận
            $wpdb->update($transfer_table, [
                'transfer_status' => TGS_TRANSFER_STATUS_PENDING, // Vẫn pending, chờ shop đích nhận
                'transfer_note' => $transfer->transfer_note . "\n[Duyệt xuất kho] " . date('d/m/Y H:i') . ": " . $note
            ], ['transfer_ledger_id' => $transfer->transfer_ledger_id]);

            $wpdb->query('COMMIT');

            // Thêm log duyệt phiếu xuất kho
            $dest_shop_name = get_blog_option($destination_blog_id, 'blogname');
            TGS_Shop_Ticket_Helper::add_ticket_log($ledger_id, 'approve', [
                'destination_blog_id' => $destination_blog_id,
                'destination_shop_name' => $dest_shop_name,
                'items_count' => count($items),
                'note' => $note,
                'parent_ledger_id' => $parent_id,
                'parent_ledger_code' => $parent_ledger->local_ledger_code ?? ''
            ], !empty($note) ? $note : 'Duyệt phiếu xuất kho (chuyển đến shop: ' . $dest_shop_name . ')');

            wp_send_json_success([
                'message' => 'Duyệt phiếu xuất kho thành công. Shop đích có thể nhận hàng.'
            ]);

        } catch (Exception $e) {
            
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Đồng bộ sản phẩm sang shop đích nếu chưa có
     */
    private static function sync_product_to_destination($item, $destination_blog_id, $source_blog_id)
    {
        global $wpdb;

        $barcode = $item->local_product_barcode_main;

        if (empty($barcode)) {
            return; // Không thể đồng bộ nếu không có barcode
        }

        // Chuyển sang shop đích
        switch_to_blog($destination_blog_id);

        $dest_products_table = $wpdb->prefix . 'local_product_name';

        // Kiểm tra sản phẩm đã tồn tại chưa
        $exists = $wpdb->get_var($wpdb->prepare("
            SELECT local_product_name_id
            FROM {$dest_products_table}
            WHERE local_product_barcode_main = %s
            AND (is_deleted IS NULL OR is_deleted = 0)
        ", $barcode));

        if ($exists) {
            restore_current_blog();
            return; // Đã có rồi
        }

        // Cần đồng bộ - quay lại shop nguồn lấy thông tin đầy đủ
        restore_current_blog();

        $source_products_table = $wpdb->prefix . 'local_product_name';
        $full_product = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$source_products_table}
            WHERE local_product_name_id = %d
        ", $item->local_product_name_id));

        if (!$full_product) {
            return;
        }

        // Chuyển sang shop đích để tạo sản phẩm
        switch_to_blog($destination_blog_id);

        // Tạo sản phẩm mới ở shop đích
        $wpdb->insert($dest_products_table, [
            'source_blog_id' => $source_blog_id,
            'local_product_name' => $full_product->local_product_name,
            'global_product_name' => $full_product->global_product_name,
            'local_product_thumbnail' => $full_product->local_product_thumbnail,
            'local_product_cat_id' => null, // Cần map danh mục nếu có
            'local_product_description' => $full_product->local_product_description,
            'local_product_content' => $full_product->local_product_content,
            'local_product_price' => $full_product->local_product_price,
            'local_product_tax' => $full_product->local_product_tax,
            'local_product_meta' => $full_product->local_product_meta,
            'local_product_barcode_main' => $full_product->local_product_barcode_main,
            'local_product_barcode_url_main' => $full_product->local_product_barcode_url_main,
            'local_product_is_tracking' => $full_product->local_product_is_tracking,
            'local_product_quantity_no_tracking' => 0, // Bắt đầu với 0
            'local_product_status' => 'publish',
            'user_id' => get_current_user_id(),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ]);

        restore_current_blog();
    }

    /**
     * Lấy danh sách phiếu đang chờ nhận từ shop mẹ
     *
     * Logic mới: Shop con có bảng transfer_ledger của riêng mình
     * - Query transfer_ledger của shop con hiện tại
     * - Switch sang shop mẹ (source_blog_id) để lấy local_ledger_approver_status
     */
    public static function get_pending_imports()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();

        $pending_imports = [];

        // Lấy từ transfer_ledger của shop con hiện tại
        $transfer_table = $wpdb->prefix . 'transfer_ledger';

        // Lấy các transfer chờ nhận (chưa có destination_ledger_id hoặc destination_ledger_id = 0)
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
            AND (t.destination_ledger_id IS NULL OR t.destination_ledger_id = 0)
            AND t.transfer_status != %d
        ", $current_blog_id, TGS_TRANSFER_STATUS_ACCEPTED));

        foreach ($transfers as $transfer) {
            $source_blog_id = intval($transfer->source_blog_id);

            if (!$source_blog_id) continue;

            // Switch sang shop mẹ để lấy thông tin phiếu xuất
            switch_to_blog($source_blog_id);

            $source_ledger_table = $wpdb->prefix . 'local_ledger';
            $source_ledger_item_table = $wpdb->prefix . 'local_ledger_item';

            // Lấy thông tin phiếu xuất từ shop mẹ (phiếu cha type 12)
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

                // Tên shop mẹ
                $transfer->source_shop_name = get_bloginfo('name');

                // Đếm số sản phẩm từ local_ledger_item_id (JSON array của item IDs từ phiếu con)
                $item_ids = [];
                if (!empty($source_ledger->local_ledger_item_id)) {
                    $item_ids = json_decode($source_ledger->local_ledger_item_id, true) ?: [];
                }

                // Đếm số items thực tế từ bảng local_ledger_item
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

                // Set trạng thái hiển thị - nếu phiếu xuất đã duyệt thì mới cho tạo phiếu nhập
                // TGS_TRANSFER_STATUS_ACCEPTED = 1 (đã duyệt xuất, cho phép tạo phiếu nhập)
                // TGS_TRANSFER_STATUS_PENDING = 0 (chờ duyệt)
                $transfer->transfer_status = ($source_ledger->local_ledger_approver_status == TGS_APPROVER_STATUS_APPROVED)
                    ? TGS_TRANSFER_STATUS_ACCEPTED : TGS_TRANSFER_STATUS_PENDING;

                $pending_imports[] = $transfer;
            }

            restore_current_blog();
        }

        wp_send_json_success($pending_imports);
    }

    /**
     * Tạo phiếu nhập từ shop mẹ
     *
     * Luồng mới theo mô hình cha-con (giống phiếu mua hàng):
     * 1. Tạo phiếu nhập từ mẹ (PARENT) - type = TRANSFER_IMPORT, KHÔNG có items
     * 2. Tạo phiếu nhập tự động (CHILD) - type = PURCHASE, CÓ items, parent_id = phiếu cha
     * 3. Cập nhật phiếu cha với local_ledger_item_id = items từ phiếu con
     * 4. Cập nhật transfer_ledger với destination_ledger_id = phiếu cha, destination_ledger_item_id = items phiếu con
     */
    public static function create_import()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();
        $current_user_id = get_current_user_id();

        $transfer_id = intval($_POST['transfer_id'] ?? 0);
        $import_note = sanitize_textarea_field($_POST['note'] ?? $_POST['import_note'] ?? '');
        $items_json = isset($_POST['items']) ? wp_unslash($_POST['items']) : '';

        if (!$transfer_id) {
            wp_send_json_error(['message' => 'Thiếu ID transfer']);
        }

        // Parse items từ frontend (nếu có)
        $custom_items = [];
        if (!empty($items_json)) {
            $custom_items = json_decode($items_json, true);
            if (!is_array($custom_items)) {
                $custom_items = [];
            }
        }

        // Build lookup maps: barcode => import_quantity và barcode => selected_lots
        $import_quantities = [];
        $selected_lots_map = [];
        foreach ($custom_items as $ci) {
            if (isset($ci['barcode']) && isset($ci['import_quantity'])) {
                $import_quantities[$ci['barcode']] = intval($ci['import_quantity']);
            }
            // Lưu selected_lots cho tracking products
            if (isset($ci['barcode']) && isset($ci['selected_lots']) && is_array($ci['selected_lots'])) {
                $selected_lots_map[$ci['barcode']] = $ci['selected_lots'];
            }
        }

        // Step 1: Query bảng transfer_ledger của shop hiện tại
        $local_transfer_table = $wpdb->prefix . 'transfer_ledger';

        $local_transfer = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$local_transfer_table}
            WHERE transfer_ledger_id = %d
        ", $transfer_id));

        if (!$local_transfer) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu chuyển']);
        }

        // Kiểm tra đã nhập chưa
        if (!empty($local_transfer->destination_ledger_id)) {
            wp_send_json_error(['message' => 'Phiếu này đã được tạo phiếu nhập trước đó']);
        }

        $source_blog_id = intval($local_transfer->source_blog_id);
        $source_ledger_id = intval($local_transfer->source_ledger_id);

        // Step 2: Switch sang shop mẹ để lấy thông tin
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
            wp_send_json_error(['message' => 'Không tìm thấy phiếu xuất nguồn']);
        }

        // Kiểm tra phiếu xuất đã duyệt chưa
        if ($source_ledger->local_ledger_approver_status != TGS_APPROVER_STATUS_APPROVED) {
            restore_current_blog();
            wp_send_json_error(['message' => 'Phiếu xuất chưa được shop mẹ duyệt']);
        }

        // Lấy các item từ local_ledger_item_id (JSON array của item IDs từ phiếu con xuất kho)
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
            wp_send_json_error(['message' => 'Không có sản phẩm trong phiếu xuất']);
        }

        // Step 3: Quay về shop hiện tại để tạo phiếu nhập
        $wpdb->query('START TRANSACTION');

        try {
            $ledger_table = $wpdb->prefix . 'local_ledger';
            $products_table = $wpdb->prefix . 'local_product_name';
            $lots_table = TGS_TABLE_GLOBAL_PRODUCT_LOTS;

            // Tạo mã phiếu cha (nhập từ mẹ)
            $parent_ledger_code = 'TNM-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -4));
            // Mã phiếu con (nhập tự động)
            $auto_import_code = 'ANM-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -4));

            // Tính tổng giá trị và xử lý các item
            $total_amount = 0;
            $import_items_data = []; // Items cho phiếu nhập tự động
            $total_max_qty = 0;
            $total_import_qty = 0;

            foreach ($source_items as $source_item) {
                $barcode = $source_item->local_product_barcode_main;
                $is_tracking = intval($source_item->local_product_is_tracking) === 1;
                $max_quantity = intval($source_item->quantity);

                // Lấy quantity nhập từ frontend, nếu không có thì dùng max
                $import_quantity = isset($import_quantities[$barcode])
                    ? intval($import_quantities[$barcode])
                    : $max_quantity;

                // Xử lý lot_ids cho tracking products TRƯỚC khi validate quantity
                $lot_barcodes_to_import = [];
                if ($is_tracking && !empty($source_item->list_product_lots)) {
                    $all_lot_ids = json_decode($source_item->list_product_lots, true) ?: [];

                    // Ưu tiên sử dụng selected_lots từ frontend (user đã chọn cụ thể)
                    if (isset($selected_lots_map[$barcode]) && !empty($selected_lots_map[$barcode])) {
                        // Validate: chỉ lấy các lot thực sự có trong source
                        $lot_ids_to_import = array_values(array_intersect(
                            $selected_lots_map[$barcode],
                            $all_lot_ids
                        ));
                        // Quantity cho tracking = số lot đã chọn
                        $import_quantity = count($lot_ids_to_import);
                    } else {
                        // Fallback: lấy số lượng lot tương ứng với import_quantity (legacy)
                        $lot_ids_to_import = array_slice($all_lot_ids, 0, $import_quantity);
                    }

                    // Convert lot_ids to barcodes for create_import_ledger
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

                // Validate: không được vượt quá max và không âm
                if ($import_quantity < 0) $import_quantity = 0;
                if ($import_quantity > $max_quantity) $import_quantity = $max_quantity;

                // Track totals để xác định transfer_status
                $total_max_qty += $max_quantity;
                $total_import_qty += $import_quantity;

                // Bỏ qua nếu không nhập sản phẩm này
                if ($import_quantity <= 0) {
                    continue;
                }

                // Tìm sản phẩm tương ứng ở shop hiện tại
                $local_product = $wpdb->get_row($wpdb->prepare("
                    SELECT * FROM {$products_table}
                    WHERE local_product_barcode_main = %s
                    AND (is_deleted IS NULL OR is_deleted = 0)
                ", $barcode));

                // Nếu sản phẩm chưa có ở shop con thì đồng bộ từ shop mẹ (bao gồm cả danh mục)
                if (!$local_product) {
                    $new_product_id = self::sync_product_from_source($source_item, $source_blog_id);

                    if (!$new_product_id) {
                        throw new Exception("Lỗi tạo sản phẩm mới với barcode '{$barcode}'");
                    }

                    // Lấy lại thông tin sản phẩm vừa tạo
                    $local_product = $wpdb->get_row($wpdb->prepare("
                        SELECT * FROM {$products_table}
                        WHERE local_product_name_id = %d
                    ", $new_product_id));
                }

                $price = floatval($source_item->price ?? 0);
                $subtotal = $import_quantity * $price;
                $total_amount += $subtotal;

                $import_items_data[] = [
                    'product_id' => $local_product->local_product_name_id,
                    'quantity' => $import_quantity,
                    'price' => $price,
                    'lot_barcodes' => $lot_barcodes_to_import,
                    'is_tracking' => $is_tracking,
                    'source_item' => $source_item,
                    'local_product' => $local_product,
                    'max_quantity' => $max_quantity
                ];
            }

            // Validate: phải có ít nhất 1 sản phẩm được nhập
            if (empty($import_items_data)) {
                throw new Exception('Vui lòng chọn ít nhất 1 sản phẩm để nhập');
            }

            // Xác định transfer_status dựa trên tổng quantity
            $is_partial = ($total_import_qty < $total_max_qty);

            // ========== BƯỚC 1: Tạo phiếu CHA (Nhập từ mẹ) - KHÔNG có items ==========
            $note_suffix = $is_partial
                ? "\n[Từ phiếu xuất: {$source_ledger->local_ledger_code}] - Nhập 1 phần: {$total_import_qty}/{$total_max_qty}"
                : "\n[Từ phiếu xuất: {$source_ledger->local_ledger_code}]";

            $wpdb->insert($ledger_table, [
                'local_ledger_code' => $parent_ledger_code,
                'local_ledger_type' => TGS_LEDGER_TYPE_TRANSFER_IMPORT, // Type 13
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
                throw new Exception('Lỗi tạo phiếu nhập từ mẹ (phiếu cha)');
            }

            // ========== BƯỚC 2: Tạo phiếu CON (Nhập tự động) - CÓ items ==========
            // Sử dụng TGS_Shop_Base_Import_Export::create_import_ledger
            $auto_import_ledger_data = [
                'local_ledger_code' => $auto_import_code,
                'local_ledger_type' => TGS_LEDGER_TYPE_PURCHASE, // Type 1 - Phiếu nhập
                'local_ledger_note' => 'Nhập tự động từ phiếu: ' . $parent_ledger_code,
                'local_ledger_total_amount' => $total_amount,
                'local_ledger_status' => TGS_LEDGER_STATUS_PENDING,
                'local_ledger_approver_status' => TGS_APPROVER_STATUS_PENDING,
                'user_id' => $current_user_id,
            ];

            $auto_import_result = TGS_Shop_Base_Import_Export::create_import_ledger(
                $auto_import_ledger_data,
                $import_items_data,
                $parent_ledger_id // parent_id = phiếu cha
            );

            $auto_import_ledger_id = $auto_import_result['ledger_id'];
            $auto_import_item_ids = $auto_import_result['items'];

            // ========== BƯỚC 3: Cập nhật phiếu CHA với item IDs từ phiếu con ==========
            $items_json = json_encode($auto_import_item_ids, JSON_UNESCAPED_UNICODE);
            $wpdb->update($ledger_table, [
                'local_ledger_item_id' => $items_json
            ], ['local_ledger_id' => $parent_ledger_id]);

            // ========== BƯỚC 4: Cập nhật transfer_ledger ở shop nguồn (mẹ) ==========
            switch_to_blog($source_blog_id);

            $source_transfer_table_name = $wpdb->prefix . 'transfer_ledger';
            $wpdb->update($source_transfer_table_name, [
                'destination_ledger_id' => $parent_ledger_id, // Phiếu cha
                'destination_ledger_item_id' => $items_json, // Items từ phiếu con (nhập tự động)
            ], ['transfer_ledger_id' => $transfer_id]);

            restore_current_blog();

            // ========== BƯỚC 5: Cập nhật transfer_ledger ở shop con (hiện tại) ==========
            $wpdb->update($local_transfer_table, [
                'destination_ledger_id' => $parent_ledger_id, // Phiếu cha
                'destination_ledger_item_id' => $items_json, // Items từ phiếu con
            ], ['transfer_ledger_id' => $transfer_id]);

            $wpdb->query('COMMIT');

            // ========== GHI LOG LOT: transfer_import_created ==========
            foreach ($import_items_data as $item_data) {
                if ($item_data['is_tracking'] && !empty($item_data['lot_barcodes'])) {
                    foreach ($item_data['lot_barcodes'] as $lot_barcode) {
                        $lot = $wpdb->get_row($wpdb->prepare("
                            SELECT global_product_lot_id FROM {$lots_table}
                            WHERE global_product_lot_barcode = %s
                        ", $lot_barcode));
                        if ($lot) {
                            TGS_Global_Lots_Helper::add_lot_log($lot->global_product_lot_id, 'transfer_import_created', [
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

            // Thêm log tạo phiếu nhập transfer
            $source_shop_name = get_blog_option($source_blog_id, 'blogname');
            TGS_Shop_Ticket_Helper::add_ticket_log($parent_ledger_id, 'create', [
                'source_blog_id' => $source_blog_id,
                'source_shop_name' => $source_shop_name,
                'items_count' => count($import_items_data),
                'total_amount' => $total_amount,
                'is_partial' => $is_partial,
                'auto_import_ledger_id' => $auto_import_ledger_id,
                'auto_import_code' => $auto_import_code
            ], 'Tạo phiếu nhập từ shop: ' . $source_shop_name);

            wp_send_json_success([
                'message' => 'Tạo phiếu nhập thành công',
                'ledger_id' => $parent_ledger_id,
                'auto_import_ledger_id' => $auto_import_ledger_id,
                'ledger_code' => $parent_ledger_code,
                'auto_import_code' => $auto_import_code,
                'is_partial' => $is_partial,
                'total_imported' => $total_import_qty,
                'total_max' => $total_max_qty,
                'redirect_url' => admin_url('admin.php?page=tgs-shop-management&view=ticket-transfer-import-detail&id=' . $parent_ledger_id)
            ]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Duyệt phiếu nhập - thực hiện:
     * 1. Chuyển lot sang ACTIVE trong kho hiện tại
     * 2. Cộng tồn kho không tracking
     * 3. Cập nhật transfer_status thành ACCEPTED hoặc PARTIAL
     */
    public static function approve_import()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

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

        // Tìm phiếu cha TRANSFER_IMPORT (type 13)
        $parent_id = intval($child_ledger->local_ledger_parent_id);
        if (!$parent_id) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu cha']);
        }

        $parent_ledger = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$ledger_table}
            WHERE local_ledger_id = %d
            AND local_ledger_type = %d
        ", $parent_id, TGS_LEDGER_TYPE_TRANSFER_IMPORT));

        if (!$parent_ledger) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu nhập từ shop mẹ']);
        }

        // Lấy các item từ phiếu con nhập kho
        $items = $wpdb->get_results($wpdb->prepare("
            SELECT li.*, p.local_product_name, p.local_product_is_tracking
            FROM {$ledger_item_table} li
            JOIN {$products_table} p ON li.local_product_name_id = p.local_product_name_id
            WHERE li.local_ledger_id = %d
        ", $ledger_id));

        // Tìm transfer_ledger thông qua phiếu cha để xác định is_partial
        $local_transfer_table = $wpdb->prefix . 'transfer_ledger';
        $local_transfer = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$local_transfer_table}
            WHERE destination_ledger_id = %d
        ", $parent_id));

        // Xác định có phải nhập 1 phần không bằng cách so sánh với phiếu xuất gốc
        $is_partial = false;
        $all_source_lot_ids_for_partial = []; // Lưu để dùng cho việc cập nhật lot status = 5
        
        if ($local_transfer) {
            $source_blog_id = intval($local_transfer->source_blog_id);
            $source_ledger_id = intval($local_transfer->source_ledger_id);

            // Switch sang shop mẹ để lấy thông tin phiếu xuất gốc
            switch_to_blog($source_blog_id);

            $source_ledger_table = $wpdb->prefix . 'local_ledger';
            $source_ledger_item_table = $wpdb->prefix . 'local_ledger_item';

            // Lấy local_ledger_item_id từ phiếu cha xuất
            $source_ledger_data = $wpdb->get_row($wpdb->prepare("
                SELECT local_ledger_item_id FROM {$source_ledger_table}
                WHERE local_ledger_id = %d
            ", $source_ledger_id));

            // Lấy tổng quantity VÀ danh sách lot IDs từ các items (qua local_ledger_item_id)
            $source_items_data = [];
            if ($source_ledger_data && !empty($source_ledger_data->local_ledger_item_id)) {
                $source_item_ids = json_decode($source_ledger_data->local_ledger_item_id, true) ?: [];
                if (!empty($source_item_ids)) {
                    $source_item_ids_str = implode(',', array_map('intval', $source_item_ids));
                    $source_items_data = $wpdb->get_results("
                        SELECT quantity, list_product_lots FROM {$source_ledger_item_table}
                        WHERE local_ledger_item_id IN ({$source_item_ids_str})
                          AND (is_deleted = 0 OR is_deleted IS NULL)
                    ", ARRAY_A);
                }
            }

            restore_current_blog();

            // Tính tổng quantity và thu thập lot IDs từ phiếu xuất gốc
            $source_total = 0;
            foreach ($source_items_data as $source_item) {
                $source_total += floatval($source_item['quantity']);
                $lots = !empty($source_item['list_product_lots']) ? json_decode($source_item['list_product_lots'], true) : [];
                $lots = $lots ?: [];
                $all_source_lot_ids_for_partial = array_merge($all_source_lot_ids_for_partial, array_map('intval', $lots));
            }
            $all_source_lot_ids_for_partial = array_unique(array_filter($all_source_lot_ids_for_partial));

            // Tính tổng quantity và thu thập lot IDs đã nhập
            $imported_total = 0;
            $imported_lot_ids_for_partial = [];
            foreach ($items as $item) {
                $imported_total += floatval($item->quantity);
                $lots = !empty($item->list_product_lots) ? json_decode($item->list_product_lots, true) : [];
                $lots = $lots ?: [];
                $imported_lot_ids_for_partial = array_merge($imported_lot_ids_for_partial, array_map('intval', $lots));
            }
            $imported_lot_ids_for_partial = array_unique(array_filter($imported_lot_ids_for_partial));

            // So sánh - là partial nếu:
            // 1. Tổng quantity nhập < tổng quantity xuất, HOẶC
            // 2. Số lượng lot nhập < số lượng lot xuất (cho tracking products)
            $is_partial = ($imported_total < floatval($source_total)) || 
                          (count($imported_lot_ids_for_partial) < count($all_source_lot_ids_for_partial));
        }

        $wpdb->query('START TRANSACTION');

        try {
            foreach ($items as $item) {
                $is_tracking = intval($item->local_product_is_tracking) === 1;

                if ($is_tracking) {
                    // Cập nhật lot thành ACTIVE
                    $lot_ids = json_decode($item->list_product_lots, true) ?: [];
                    $source_blog_id = $local_transfer ? intval($local_transfer->source_blog_id) : 0;

                    foreach ($lot_ids as $lot_id) {
                        // Tự động điền local_product_barcode_main nếu chưa có
                        TGS_Global_Lots_Helper::ensure_lot_has_barcode_main($lot_id, $item->local_product_name_id);

                        // Cập nhật lot về shop hiện tại và active
                        // Note: to_blog_id đã đúng rồi (được set từ lúc shop mẹ duyệt xuất), không cần update
                        $wpdb->update($lots_table, [
                            'local_product_lot_is_active' => TGS_PRODUCT_LOT_ACTIVE,
                            'local_product_name_id' => $item->local_product_name_id,
                            'local_imported_date' => time(),
                            'updated_at' => current_time('mysql')
                        ], ['global_product_lot_id' => $lot_id]);

                        // ========== GHI LOG VÀO product_lot_meta ==========
                        TGS_Global_Lots_Helper::add_lot_log($lot_id, 'transfer_import_approved', [
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

            // Cập nhật trạng thái phiếu
            $wpdb->update($ledger_table, [
                'local_ledger_approver_status' => TGS_APPROVER_STATUS_APPROVED,
                'local_ledger_status' => TGS_LEDGER_STATUS_APPROVED,
                'local_ledger_approver_id' => $current_user_id,
                'updated_at' => current_time('mysql')
            ], ['local_ledger_id' => $ledger_id]);

            // Xác định transfer_status: ACCEPTED (nhập hết) hoặc PARTIAL (nhập 1 phần)
            $final_transfer_status = $is_partial
                ? TGS_TRANSFER_STATUS_PARTIAL
                : TGS_TRANSFER_STATUS_ACCEPTED;

            if ($local_transfer) {
                $source_blog_id = intval($local_transfer->source_blog_id);

                // Cập nhật transfer_ledger ở shop mẹ
                switch_to_blog($source_blog_id);

                $source_transfer_table = $wpdb->prefix . 'transfer_ledger';
                $wpdb->update($source_transfer_table, [
                    'transfer_status' => $final_transfer_status,
                    'accepted_at' => current_time('mysql'),
                    'accepted_by_user_id' => $current_user_id
                ], ['transfer_ledger_id' => $local_transfer->transfer_ledger_id]);

                restore_current_blog();

                // Cập nhật transfer_ledger ở shop con (hiện tại)
                $wpdb->update($local_transfer_table, [
                    'transfer_status' => $final_transfer_status,
                    'accepted_at' => current_time('mysql'),
                    'accepted_by_user_id' => $current_user_id
                ], ['transfer_ledger_id' => $local_transfer->transfer_ledger_id]);
            }

            // ===== XỬ LÝ CÁC LOT CHƯA NHẬP (STATUS = 5) =====
            // Nếu là nhập 1 phần, cần cập nhật các lot chưa được nhập về status = 5
            if ($is_partial && $local_transfer && !empty($all_source_lot_ids_for_partial)) {
                // Sử dụng biến đã thu thập ở trên thay vì query lại
                $all_source_lot_ids = $all_source_lot_ids_for_partial;
                $source_blog_id = intval($local_transfer->source_blog_id);

                // Thu thập các lot đã nhập trong phiếu nhập hiện tại
                $imported_lot_ids = [];
                foreach ($items as $item) {
                    $lots = !empty($item->list_product_lots) ? json_decode($item->list_product_lots, true) : [];
                    $lots = $lots ?: [];
                    $imported_lot_ids = array_merge($imported_lot_ids, array_map('intval', $lots));
                }
                $imported_lot_ids = array_unique(array_filter($imported_lot_ids));

                // Tìm các lot chưa nhập = lot từ phiếu xuất - lot đã nhập
                $not_imported_lot_ids = array_diff($all_source_lot_ids, $imported_lot_ids);

                // Cập nhật status = 5 (Chờ xử lý trả về shop mẹ) cho các lot chưa nhập
                if (!empty($not_imported_lot_ids)) {
                    foreach ($not_imported_lot_ids as $lot_id) {
                        // Tự động điền local_product_barcode_main nếu chưa có
                        TGS_Global_Lots_Helper::ensure_lot_has_barcode_main($lot_id);

                        $wpdb->update($lots_table, [
                            'local_product_lot_is_active' => TGS_PRODUCT_LOT_PENDING_RETURN, // Chờ xử lý trả về shop mẹ
                            'updated_at' => current_time('mysql')
                        ], ['global_product_lot_id' => $lot_id]);

                        // ========== GHI LOG VÀO product_lot_meta ==========
                        TGS_Global_Lots_Helper::add_lot_log($lot_id, 'transfer_partial_pending_return', [
                            'previous_status' => TGS_PRODUCT_LOT_PENDING,
                            'new_status' => TGS_PRODUCT_LOT_PENDING_RETURN,
                            'source_blog_id' => $source_blog_id,
                            'to_blog_id' => $current_blog_id,
                            'ledger_id' => $ledger_id,
                            'ledger_code' => $child_ledger->local_ledger_code ?? '',
                            'reason' => 'Không được nhập trong phiếu nhập 1 phần'
                        ]);
                    }
                }
            }
            // ===== END: XỬ LÝ LOT CHƯA NHẬP =====

            $wpdb->query('COMMIT');

            // Thêm log duyệt phiếu nhập kho
            $source_shop_name = $local_transfer ? get_blog_option(intval($local_transfer->source_blog_id), 'blogname') : '';
            TGS_Shop_Ticket_Helper::add_ticket_log($ledger_id, 'approve', [
                'source_blog_id' => $local_transfer ? intval($local_transfer->source_blog_id) : 0,
                'source_shop_name' => $source_shop_name,
                'items_count' => count($items),
                'is_partial' => $is_partial,
                'transfer_status' => $final_transfer_status,
                'note' => $note,
                'parent_ledger_id' => $parent_id,
                'parent_ledger_code' => $parent_ledger->local_ledger_code ?? ''
            ], !empty($note) ? $note : ($is_partial ? 'Duyệt phiếu nhập kho (1 phần) từ shop: ' . $source_shop_name : 'Duyệt phiếu nhập kho từ shop: ' . $source_shop_name));

            $status_message = $is_partial
                ? 'Duyệt phiếu nhập kho thành công (Nhập 1 phần). Hàng đã vào kho.'
                : 'Duyệt phiếu nhập kho thành công. Hàng đã vào kho.';

            wp_send_json_success([
                'message' => $status_message,
                'is_partial' => $is_partial,
                'transfer_status' => $final_transfer_status
            ]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Lấy danh sách phiếu xuất
     */
    public static function get_exports_list()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $transfer_table = $wpdb->prefix . 'transfer_ledger';

        $exports = $wpdb->get_results($wpdb->prepare("
            SELECT l.*, t.destination_blog_id, t.transfer_status, t.transfer_ledger_id
            FROM {$ledger_table} l
            LEFT JOIN {$transfer_table} t ON l.local_ledger_id = t.source_ledger_id
            WHERE l.local_ledger_type = %d
            AND (l.is_deleted IS NULL OR l.is_deleted = 0)
            ORDER BY l.created_at DESC
        ", TGS_LEDGER_TYPE_TRANSFER_EXPORT));

        // Thêm tên shop đích
        foreach ($exports as &$export) {
            if ($export->destination_blog_id) {
                $export->destination_shop_name = get_blog_details($export->destination_blog_id)->blogname ?? 'Shop #' . $export->destination_blog_id;
            }
        }

        wp_send_json_success(['exports' => $exports]);
    }

    /**
     * Lấy danh sách phiếu nhập
     */
    public static function get_imports_list()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        global $wpdb;

        $ledger_table = $wpdb->prefix . 'local_ledger';

        $imports = $wpdb->get_results($wpdb->prepare("
            SELECT *
            FROM {$ledger_table}
            WHERE local_ledger_type = %d
            AND (is_deleted IS NULL OR is_deleted = 0)
            ORDER BY created_at DESC
        ", TGS_LEDGER_TYPE_TRANSFER_IMPORT));

        wp_send_json_success(['imports' => $imports]);
    }

    /**
     * Lấy chi tiết phiếu transfer
     */
    public static function get_detail()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        global $wpdb;

        $ledger_id = intval($_POST['ledger_id'] ?? 0);
        $type = sanitize_text_field($_POST['type'] ?? 'export');

        if (!$ledger_id) {
            wp_send_json_error(['message' => 'Thiếu ID phiếu']);
        }

        $ledger_type = $type === 'import' ? TGS_LEDGER_TYPE_TRANSFER_IMPORT : TGS_LEDGER_TYPE_TRANSFER_EXPORT;

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

        // Lấy các item
        $items = $wpdb->get_results($wpdb->prepare("
            SELECT li.*, p.local_product_name, p.local_product_barcode_main, p.local_product_is_tracking
            FROM {$ledger_item_table} li
            JOIN {$products_table} p ON li.local_product_name_id = p.local_product_name_id
            WHERE li.local_ledger_id = %d
        ", $ledger_id));

        // Nếu là phiếu xuất, lấy thêm thông tin transfer
        $transfer = null;
        if ($type === 'export') {
            $transfer_table = $wpdb->prefix . 'transfer_ledger';
            $transfer = $wpdb->get_row($wpdb->prepare("
                SELECT * FROM {$transfer_table}
                WHERE source_ledger_id = %d
            ", $ledger_id));

            if ($transfer && $transfer->destination_blog_id) {
                $transfer->destination_shop_name = get_blog_details($transfer->destination_blog_id)->blogname ?? 'Shop #' . $transfer->destination_blog_id;
            }
        }

        wp_send_json_success([
            'ledger' => $ledger,
            'items' => $items,
            'transfer' => $transfer
        ]);
    }

    /**
     * Lấy chi tiết transfer theo transfer_id
     *
     * Logic:
     * - Query bảng transfer_ledger của shop hiện tại để lấy source_blog_id, source_ledger_id
     * - Switch sang shop mẹ (source_blog_id) để lấy thông tin ledger và items
     */
    public static function get_transfer_detail()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();

        $transfer_id = intval($_POST['transfer_id'] ?? 0);

        if (!$transfer_id) {
            wp_send_json_error(['message' => 'Thiếu ID transfer']);
        }

        // Step 1: Query bảng transfer_ledger của shop hiện tại
        $transfer_table = $wpdb->prefix . 'transfer_ledger';

        $transfer = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$transfer_table}
            WHERE transfer_ledger_id = %d
        ", $transfer_id));

        if (!$transfer) {
            wp_send_json_error(['message' => 'Không tìm thấy transfer']);
        }

        $source_blog_id = intval($transfer->source_blog_id);
        $source_ledger_id = intval($transfer->source_ledger_id);
        $destination_blog_id = intval($transfer->destination_blog_id);

        // Step 2: Switch sang shop mẹ để lấy thông tin ledger và items
        switch_to_blog($source_blog_id);

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $ledger_item_table = $wpdb->prefix . 'local_ledger_item';
        $products_table = $wpdb->prefix . 'local_product_name';

        // Lấy thông tin ledger từ shop mẹ
        $ledger = $wpdb->get_row($wpdb->prepare("
            SELECT local_ledger_code, local_ledger_total_amount, local_ledger_note, local_ledger_approver_status
            FROM {$ledger_table}
            WHERE local_ledger_id = %d
        ", $source_ledger_id));

        if ($ledger) {
            $transfer->local_ledger_code = $ledger->local_ledger_code;
            $transfer->local_ledger_total_amount = $ledger->local_ledger_total_amount;
            $transfer->local_ledger_note = $ledger->local_ledger_note;
            $transfer->local_ledger_approver_status = $ledger->local_ledger_approver_status;
        }

        $transfer->source_shop_name = get_bloginfo('name');

        // Lấy items từ shop mẹ - phiếu cha lưu item IDs trong local_ledger_item_id
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

        // Step 3: Kiểm tra sync status cho từng sản phẩm ở shop đích (shop con)
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

        // Step 4: Lấy thông tin chi tiết lots từ bảng global cho các sản phẩm tracking
        foreach ($items as &$item) {
            $item->lots_detail = [];

            if ($item->is_tracking && !empty($item->list_product_lots)) {
                $lot_ids = json_decode($item->list_product_lots, true);
                if (!empty($lot_ids) && is_array($lot_ids)) {
                    // Sử dụng helper để lấy thông tin lots từ bảng global
                    $lots = TGS_Global_Lots_Helper::get_lots_by_ids($lot_ids);

                    // Build array với thông tin cần thiết
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
            'transfer' => $transfer,
            'items' => $items
        ]);
    }

    /**
     * Lấy danh sách items của một transfer
     *
     * Logic:
     * - Query bảng transfer_ledger của shop hiện tại để lấy source_blog_id và source_ledger_id
     * - Switch sang shop mẹ (source_blog_id) để lấy danh sách sản phẩm từ local_ledger_item
     */
    public static function get_transfer_items()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        global $wpdb;

        $transfer_id = intval($_POST['transfer_id'] ?? 0);

        if (!$transfer_id) {
            wp_send_json_error(['message' => 'Thiếu ID transfer']);
        }

        $current_blog_id = get_current_blog_id();
        $items = [];

        // Step 1: Query bảng transfer_ledger của shop hiện tại
        $transfer_table = $wpdb->prefix . 'transfer_ledger';

        $transfer = $wpdb->get_row($wpdb->prepare("
            SELECT source_blog_id, source_ledger_id
            FROM {$transfer_table}
            WHERE transfer_ledger_id = %d
        ", $transfer_id));

        if (!$transfer) {
            wp_send_json_error(['message' => 'Không tìm thấy thông tin transfer']);
        }

        $source_blog_id = intval($transfer->source_blog_id);
        $source_ledger_id = intval($transfer->source_ledger_id);

        // Step 2: Switch sang shop mẹ để lấy danh sách sản phẩm
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
     * Lấy dữ liệu báo cáo chuyển kho
     */
    public static function get_report_data()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();

        $period = sanitize_text_field($_POST['period'] ?? 'month');

        // Calculate date range
        $date_condition = '';
        switch ($period) {
            case 'today':
                $date_condition = "AND DATE(l.created_at) = CURDATE()";
                break;
            case 'week':
                $date_condition = "AND l.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                break;
            case 'month':
                $date_condition = "AND l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                break;
            case 'quarter':
                $date_condition = "AND l.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
                break;
            case 'year':
                $date_condition = "AND l.created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
                break;
            default:
                $date_condition = '';
        }

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $ledger_item_table = $wpdb->prefix . 'local_ledger_item';
        $transfer_table = $wpdb->prefix . 'transfer_ledger';

        // Check if transfer_ledger table exists in current blog
        $transfer_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$transfer_table}'") === $transfer_table;

        // ============= EXPORT STATS =============
        $export_stats = $wpdb->get_row($wpdb->prepare("
            SELECT
                COUNT(*) as total_count,
                SUM(CASE WHEN l.local_ledger_approver_status = %d THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN l.local_ledger_approver_status != %d THEN 1 ELSE 0 END) as pending_count
            FROM {$ledger_table} l
            WHERE l.local_ledger_type = %d
            AND (l.is_deleted IS NULL OR l.is_deleted = 0)
            {$date_condition}
        ", TGS_APPROVER_STATUS_APPROVED, TGS_APPROVER_STATUS_APPROVED, TGS_LEDGER_TYPE_TRANSFER_EXPORT));

        // Products exported count
        $products_exported = $wpdb->get_var($wpdb->prepare("
            SELECT COALESCE(SUM(li.quantity), 0)
            FROM {$ledger_item_table} li
            JOIN {$ledger_table} l ON li.local_ledger_id = l.local_ledger_id
            WHERE l.local_ledger_type = %d
            AND (l.is_deleted IS NULL OR l.is_deleted = 0)
            {$date_condition}
        ", TGS_LEDGER_TYPE_TRANSFER_EXPORT));

        // ============= IMPORT STATS =============
        $import_stats = $wpdb->get_row($wpdb->prepare("
            SELECT
                COUNT(*) as total_count,
                SUM(CASE WHEN l.local_ledger_approver_status = %d THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN l.local_ledger_approver_status != %d THEN 1 ELSE 0 END) as pending_count
            FROM {$ledger_table} l
            WHERE l.local_ledger_type = %d
            AND (l.is_deleted IS NULL OR l.is_deleted = 0)
            {$date_condition}
        ", TGS_APPROVER_STATUS_APPROVED, TGS_APPROVER_STATUS_APPROVED, TGS_LEDGER_TYPE_TRANSFER_IMPORT));

        // Products imported count
        $products_imported = $wpdb->get_var($wpdb->prepare("
            SELECT COALESCE(SUM(li.quantity), 0)
            FROM {$ledger_item_table} li
            JOIN {$ledger_table} l ON li.local_ledger_id = l.local_ledger_id
            WHERE l.local_ledger_type = %d
            AND (l.is_deleted IS NULL OR l.is_deleted = 0)
            {$date_condition}
        ", TGS_LEDGER_TYPE_TRANSFER_IMPORT));

        // ============= PENDING RECEIVE COUNT =============
        $pending_receive = 0;
        if (is_multisite()) {
            $sites = get_sites(['number' => 1000]);
            foreach ($sites as $site) {
                if ($site->blog_id == $current_blog_id) continue;

                switch_to_blog($site->blog_id);
                $other_transfer_table = $wpdb->prefix . 'transfer_ledger';
                $other_ledger_table = $wpdb->prefix . 'local_ledger';

                // Check if transfer_ledger table exists in this blog
                $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$other_transfer_table}'") === $other_transfer_table;

                if ($table_exists) {
                    $count = $wpdb->get_var($wpdb->prepare("
                        SELECT COUNT(*)
                        FROM {$other_transfer_table} t
                        JOIN {$other_ledger_table} l ON t.source_ledger_id = l.local_ledger_id
                        WHERE t.destination_blog_id = %d
                        AND (t.destination_ledger_id IS NULL OR t.destination_ledger_id = 0)
                        AND l.local_ledger_approver_status = %d
                    ", $current_blog_id, TGS_APPROVER_STATUS_APPROVED));

                    $pending_receive += intval($count);
                }
                restore_current_blog();
            }
        }

        // ============= EXPORTED TO SHOPS =============
        $exported_to = [];
        if ($transfer_table_exists) {
            $export_transfers = $wpdb->get_results("
                SELECT t.destination_blog_id,
                       COUNT(*) as tickets_count,
                       SUM(CASE WHEN l.local_ledger_approver_status = " . TGS_APPROVER_STATUS_APPROVED . " THEN 1 ELSE 0 END) as approved_count,
                       SUM(CASE WHEN l.local_ledger_approver_status != " . TGS_APPROVER_STATUS_APPROVED . " THEN 1 ELSE 0 END) as pending_count
                FROM {$transfer_table} t
                JOIN {$ledger_table} l ON t.source_ledger_id = l.local_ledger_id
                WHERE t.source_blog_id = {$current_blog_id}
                GROUP BY t.destination_blog_id
            ");

            foreach ($export_transfers as $et) {
                $shop_name = '';
                if (is_multisite()) {
                    $details = get_blog_details($et->destination_blog_id);
                    $shop_name = $details ? $details->blogname : '';
                }

                // Get products count
                $products_count = $wpdb->get_var($wpdb->prepare("
                    SELECT COALESCE(SUM(li.quantity), 0)
                    FROM {$ledger_item_table} li
                    JOIN {$ledger_table} l ON li.local_ledger_id = l.local_ledger_id
                    JOIN {$transfer_table} t ON l.local_ledger_id = t.source_ledger_id
                    WHERE t.destination_blog_id = %d AND t.source_blog_id = %d
                ", $et->destination_blog_id, $current_blog_id));

                $exported_to[] = [
                    'blog_id' => $et->destination_blog_id,
                    'shop_name' => $shop_name,
                    'tickets_count' => $et->tickets_count,
                    'products_count' => intval($products_count),
                    'approved_count' => $et->approved_count,
                    'pending_count' => $et->pending_count
                ];
            }
        }

        // ============= IMPORTED FROM SHOPS =============
        $imported_from = [];
        if (is_multisite()) {
            $sites = get_sites(['number' => 1000]);
            foreach ($sites as $site) {
                if ($site->blog_id == $current_blog_id) continue;

                switch_to_blog($site->blog_id);
                $other_transfer_table = $wpdb->prefix . 'transfer_ledger';

                // Check if transfer_ledger table exists in this blog
                $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$other_transfer_table}'") === $other_transfer_table;

                if ($table_exists) {
                    $imports = $wpdb->get_row($wpdb->prepare("
                        SELECT COUNT(*) as tickets_count,
                               SUM(CASE WHEN transfer_status = %d THEN 1 ELSE 0 END) as approved_count,
                               SUM(CASE WHEN transfer_status != %d THEN 1 ELSE 0 END) as pending_count
                        FROM {$other_transfer_table}
                        WHERE destination_blog_id = %d
                        AND destination_ledger_id IS NOT NULL
                    ", TGS_TRANSFER_STATUS_ACCEPTED, TGS_TRANSFER_STATUS_ACCEPTED, $current_blog_id));

                    if ($imports && $imports->tickets_count > 0) {
                        $shop_name = get_bloginfo('name');

                        $imported_from[] = [
                            'blog_id' => $site->blog_id,
                            'shop_name' => $shop_name,
                            'tickets_count' => $imports->tickets_count,
                            'products_count' => 0, // Will calculate later
                            'approved_count' => $imports->approved_count,
                            'pending_count' => $imports->pending_count
                        ];
                    }
                }

                restore_current_blog();
            }
        }

        // ============= TREND DATA =============
        $trend = [];
        $days = 30;
        if ($period === 'week') $days = 7;
        elseif ($period === 'quarter') $days = 90;
        elseif ($period === 'year') $days = 365;
        elseif ($period === 'today') $days = 1;

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $date_label = date('d/m', strtotime("-{$i} days"));

            $exports_day = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$ledger_table}
                WHERE local_ledger_type = %d
                AND DATE(created_at) = %s
                AND (is_deleted IS NULL OR is_deleted = 0)
            ", TGS_LEDGER_TYPE_TRANSFER_EXPORT, $date));

            $imports_day = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$ledger_table}
                WHERE local_ledger_type = %d
                AND DATE(created_at) = %s
                AND (is_deleted IS NULL OR is_deleted = 0)
            ", TGS_LEDGER_TYPE_TRANSFER_IMPORT, $date));

            $trend[] = [
                'date' => $date_label,
                'exports' => intval($exports_day),
                'imports' => intval($imports_day)
            ];
        }

        // Group by week if more than 30 days
        if ($days > 30 && count($trend) > 0) {
            $weekly_trend = [];
            $week_data = ['exports' => 0, 'imports' => 0, 'date' => ''];
            $week_count = 0;

            foreach ($trend as $i => $day) {
                $week_data['exports'] += $day['exports'];
                $week_data['imports'] += $day['imports'];
                $week_count++;

                if ($week_count === 7 || $i === count($trend) - 1) {
                    $week_data['date'] = $day['date'];
                    $weekly_trend[] = $week_data;
                    $week_data = ['exports' => 0, 'imports' => 0, 'date' => ''];
                    $week_count = 0;
                }
            }
            $trend = $weekly_trend;
        }

        // ============= RECENT TRANSFERS =============
        $recent = [];

        // Recent exports - only if transfer_table exists
        if ($transfer_table_exists) {
            $recent_exports = $wpdb->get_results($wpdb->prepare("
                SELECT l.local_ledger_id as ledger_id, l.local_ledger_code as ledger_code,
                       l.local_ledger_approver_status, l.created_at,
                       t.destination_blog_id as related_blog_id
                FROM {$ledger_table} l
                LEFT JOIN {$transfer_table} t ON l.local_ledger_id = t.source_ledger_id
                WHERE l.local_ledger_type = %d
                AND (l.is_deleted IS NULL OR l.is_deleted = 0)
                ORDER BY l.created_at DESC
                LIMIT 5
            ", TGS_LEDGER_TYPE_TRANSFER_EXPORT));
        } else {
            // Query without transfer_table join
            $recent_exports = $wpdb->get_results($wpdb->prepare("
                SELECT l.local_ledger_id as ledger_id, l.local_ledger_code as ledger_code,
                       l.local_ledger_approver_status, l.created_at,
                       NULL as related_blog_id
                FROM {$ledger_table} l
                WHERE l.local_ledger_type = %d
                AND (l.is_deleted IS NULL OR l.is_deleted = 0)
                ORDER BY l.created_at DESC
                LIMIT 5
            ", TGS_LEDGER_TYPE_TRANSFER_EXPORT));
        }

        foreach ($recent_exports as $re) {
            $shop_name = '';
            if ($re->related_blog_id && is_multisite()) {
                $details = get_blog_details($re->related_blog_id);
                $shop_name = $details ? $details->blogname : '';
            }

            $products_count = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$ledger_item_table} WHERE local_ledger_id = %d
            ", $re->ledger_id));

            $recent[] = [
                'type' => 'export',
                'ledger_id' => $re->ledger_id,
                'ledger_code' => $re->ledger_code,
                'created_at' => $re->created_at,
                'status' => $re->local_ledger_approver_status == TGS_APPROVER_STATUS_APPROVED ? 'approved' : 'pending',
                'related_blog_id' => $re->related_blog_id,
                'related_shop_name' => $shop_name,
                'products_count' => intval($products_count)
            ];
        }

        // Recent imports
        $recent_imports = $wpdb->get_results($wpdb->prepare("
            SELECT l.local_ledger_id as ledger_id, l.local_ledger_code as ledger_code,
                   l.local_ledger_approver_status, l.created_at, l.local_ledger_note
            FROM {$ledger_table} l
            WHERE l.local_ledger_type = %d
            AND (l.is_deleted IS NULL OR l.is_deleted = 0)
            ORDER BY l.created_at DESC
            LIMIT 5
        ", TGS_LEDGER_TYPE_TRANSFER_IMPORT));

        foreach ($recent_imports as $ri) {
            $products_count = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$ledger_item_table} WHERE local_ledger_id = %d
            ", $ri->ledger_id));

            // Try to extract source shop from note (format: [Từ phiếu xuất: XXX])
            $recent[] = [
                'type' => 'import',
                'ledger_id' => $ri->ledger_id,
                'ledger_code' => $ri->ledger_code,
                'created_at' => $ri->created_at,
                'status' => $ri->local_ledger_approver_status == TGS_APPROVER_STATUS_APPROVED ? 'approved' : 'pending',
                'related_blog_id' => 0,
                'related_shop_name' => 'Shop mẹ',
                'products_count' => intval($products_count)
            ];
        }

        // Sort by created_at
        usort($recent, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        $recent = array_slice($recent, 0, 10);

        // ============= RESPONSE =============
        wp_send_json_success([
            'summary' => [
                'export_count' => intval($export_stats->total_count ?? 0),
                'export_approved' => intval($export_stats->approved_count ?? 0),
                'export_pending' => intval($export_stats->pending_count ?? 0),
                'import_count' => intval($import_stats->total_count ?? 0),
                'import_approved' => intval($import_stats->approved_count ?? 0),
                'import_pending' => intval($import_stats->pending_count ?? 0),
                'pending_receive' => $pending_receive,
                'products_exported' => intval($products_exported),
                'products_imported' => intval($products_imported)
            ],
            'exported_to' => $exported_to,
            'imported_from' => $imported_from,
            'trend' => $trend,
            'recent' => $recent
        ]);
    }

    // =========================================================================
    // HELPER FUNCTIONS - Đồng bộ sản phẩm và danh mục từ shop mẹ
    // =========================================================================

    /**
     * Đồng bộ sản phẩm từ shop nguồn sang shop hiện tại
     *
     * @param object $source_product Thông tin sản phẩm từ shop nguồn
     * @param int $source_blog_id Blog ID của shop nguồn
     * @return int|false ID sản phẩm mới tạo hoặc false nếu lỗi
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

        // Tạo sản phẩm mới - chỉ sử dụng các cột có trong schema gốc
        // Các field phụ (sku, weight, unit, brand, origin, gallery) được lưu trong local_product_meta
        $meta = $source_product->local_product_meta;
        if (is_string($meta)) {
            $meta_array = json_decode($meta, true) ?: [];
        } elseif (is_array($meta)) {
            $meta_array = $meta;
        } else {
            $meta_array = [];
        }

        // Thêm các field phụ vào meta nếu có
        if (!empty($source_product->local_product_unit)) {
            $meta_array['unit'] = $source_product->local_product_unit;
        }
        if (isset($source_product->local_product_price_in)) {
            $meta_array['price_in'] = $source_product->local_product_price_in;
        }

        $wpdb->insert($products_table, [
            'local_product_barcode_main' => $source_product->local_product_barcode_main,
            'local_product_name' => $source_product->local_product_name,
            'local_product_price' => $source_product->local_product_price ?? 0,
            'local_product_cat_id' => $local_cat_id,
            'local_product_is_tracking' => $source_product->local_product_is_tracking ?? 0,
            'local_product_quantity_no_tracking' => 0, // Mới tạo, chưa có tồn kho
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
     * Đồng bộ danh mục từ shop nguồn sang shop hiện tại (đệ quy)
     *
     * Logic:
     * - Lấy thông tin danh mục từ shop nguồn
     * - Nếu có parent thì đệ quy sync parent trước
     * - Kiểm tra danh mục đã tồn tại ở shop hiện tại chưa (theo tên và parent)
     * - Nếu chưa có thì tạo mới
     *
     * @param int $source_cat_id ID danh mục ở shop nguồn
     * @param int $source_blog_id Blog ID của shop nguồn
     * @return int|null ID danh mục ở shop hiện tại hoặc null nếu lỗi
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

        // Đệ quy sync danh mục cha trước (nếu có)
        $local_parent_id = null;
        if (!empty($source_cat->local_cat_parent_id)) {
            $local_parent_id = self::sync_category_from_source(
                $source_cat->local_cat_parent_id,
                $source_blog_id
            );
        }

        // Kiểm tra danh mục đã tồn tại ở shop hiện tại chưa
        $cat_table = $wpdb->prefix . 'local_product_cat';

        // Tìm theo tên + parent_id để đảm bảo đúng vị trí trong cây
        $existing = $wpdb->get_row($wpdb->prepare("
            SELECT local_product_cat_id FROM {$cat_table}
            WHERE local_product_cat_name = %s
            AND (local_cat_parent_id = %d OR (local_cat_parent_id IS NULL AND %d = 0))
            AND (is_deleted IS NULL OR is_deleted = 0)
        ", $source_cat->local_product_cat_name, $local_parent_id ?? 0, $local_parent_id ?? 0));

        if ($existing) {
            return $existing->local_product_cat_id;
        }

        // Tạo danh mục mới - chỉ sử dụng các cột có trong schema gốc
        // Schema gốc: local_product_cat_id, global_product_cat_id, local_cat_parent_id,
        //             local_product_cat_name, global_product_cat_name, local_product_cat_status,
        //             user_id, is_deleted, deleted_at, created_at, updated_at
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
     * Từ chối phiếu xuất sang shop con
     */
    public static function reject_export()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();
        $current_user_id = get_current_user_id();

        $ledger_id = intval($_POST['ledger_id'] ?? 0);
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');

        if (!$ledger_id) {
            wp_send_json_error(['message' => 'ID phiếu không hợp lệ']);
        }

        if (empty($reason)) {
            wp_send_json_error(['message' => 'Vui lòng nhập lý do từ chối']);
        }

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $ledger_item_table = $wpdb->prefix . 'local_ledger_item';
        $products_table = $wpdb->prefix . 'local_product_name';
        $lots_table = 'wp_global_product_lots';

        // Lấy thông tin phiếu
        $ledger = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$ledger_table}
            WHERE local_ledger_id = %d
            AND local_ledger_type = %d
        ", $ledger_id, TGS_LEDGER_TYPE_TRANSFER_EXPORT));

        if (!$ledger) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu']);
        }

        if ($ledger->local_ledger_approver_status == TGS_APPROVER_STATUS_REJECTED) {
            wp_send_json_error(['message' => 'Phiếu đã bị từ chối trước đó']);
        }

        // Lấy các item
        $items = $wpdb->get_results($wpdb->prepare("
            SELECT li.*, p.local_product_name, p.local_product_is_tracking
            FROM {$ledger_item_table} li
            JOIN {$products_table} p ON li.local_product_name_id = p.local_product_name_id
            WHERE li.local_ledger_id = %d
        ", $ledger_id));

        $wpdb->query('START TRANSACTION');

        try {
            foreach ($items as $item) {
                $is_tracking = intval($item->local_product_is_tracking) === 1;

                if ($is_tracking) {
                    // Cập nhật lot về trạng thái ACTIVE (nhập kho lại shop mẹ)
                    $lot_ids = json_decode($item->list_product_lots, true) ?: [];

                    foreach ($lot_ids as $lot_id) {
                        // Tự động điền local_product_barcode_main nếu chưa có
                        TGS_Global_Lots_Helper::ensure_lot_has_barcode_main($lot_id, $item->local_product_name_id);

                        // Khi từ chối xuất: lot về lại shop mẹ
                        // to_blog_id giữ nguyên = shop mẹ (current_blog_id)
                        // local_product_lot_is_active = 1 (nhập kho lại)
                        $wpdb->update($lots_table, [
                            'local_product_lot_is_active' => TGS_PRODUCT_LOT_ACTIVE, // 1 - nhập kho lại
                            'updated_at' => current_time('mysql')
                        ], ['global_product_lot_id' => $lot_id]);
                    }

                } else {
                    // Cộng lại tồn kho không tracking
                    $quantity = floatval($item->quantity);

                    $wpdb->query($wpdb->prepare("
                        UPDATE {$products_table}
                        SET local_product_quantity_no_tracking = local_product_quantity_no_tracking + %f,
                            updated_at = %s
                        WHERE local_product_name_id = %d
                    ", $quantity, current_time('mysql'), $item->local_product_name_id));
                }
            }

            // Cập nhật trạng thái phiếu
            $wpdb->update($ledger_table, [
                'local_ledger_approver_status' => TGS_APPROVER_STATUS_REJECTED,
                'local_ledger_status' => TGS_LEDGER_STATUS_REJECTED,
                'local_ledger_approver_id' => $current_user_id,
                'updated_at' => current_time('mysql')
            ], ['local_ledger_id' => $ledger_id]);

            // Cập nhật transfer_ledger
            $transfer_table = $wpdb->prefix . 'transfer_ledger';
            $wpdb->update($transfer_table, [
                'transfer_status' => TGS_TRANSFER_STATUS_REJECTED,
                'updated_at' => current_time('mysql')
            ], ['source_ledger_id' => $ledger_id]);

            $wpdb->query('COMMIT');

            // Thêm log từ chối phiếu xuất transfer
            TGS_Shop_Ticket_Helper::add_ticket_log($ledger_id, 'reject', [
                'reason' => $reason,
                'items_count' => count($items)
            ], $reason);

            wp_send_json_success([
                'message' => 'Từ chối phiếu xuất thành công! Hàng đã nhập kho lại.',
                'reason' => $reason
            ]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Từ chối phiếu nhận từ shop mẹ (shop con từ chối nhận)
     */
    public static function reject_import()
    {
        check_ajax_referer('tgs_transfer_nonce', 'nonce');

        global $wpdb;
        $current_blog_id = get_current_blog_id();
        $current_user_id = get_current_user_id();

        $ledger_id = intval($_POST['ledger_id'] ?? 0);
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');

        if (!$ledger_id) {
            wp_send_json_error(['message' => 'ID phiếu không hợp lệ']);
        }

        if (empty($reason)) {
            wp_send_json_error(['message' => 'Vui lòng nhập lý do từ chối']);
        }

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $ledger_item_table = $wpdb->prefix . 'local_ledger_item';
        $products_table = $wpdb->prefix . 'local_product_name';
        $lots_table = 'wp_global_product_lots';

        // Lấy thông tin phiếu
        $ledger = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$ledger_table}
            WHERE local_ledger_id = %d
            AND local_ledger_type = %d
        ", $ledger_id, TGS_LEDGER_TYPE_TRANSFER_IMPORT));

        if (!$ledger) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu']);
        }

        if ($ledger->local_ledger_approver_status == TGS_APPROVER_STATUS_REJECTED) {
            wp_send_json_error(['message' => 'Phiếu đã bị từ chối trước đó']);
        }

        // Lấy các item trong phiếu nhập hiện tại (các lot đã được chọn để nhập)
        $items = $wpdb->get_results($wpdb->prepare("
            SELECT li.*, p.local_product_name, p.local_product_is_tracking
            FROM {$ledger_item_table} li
            JOIN {$products_table} p ON li.local_product_name_id = p.local_product_name_id
            WHERE li.local_ledger_id = %d
        ", $ledger_id));

        // Tìm transfer_ledger để biết phiếu xuất gốc
        $local_transfer_table = $wpdb->prefix . 'transfer_ledger';
        $local_transfer = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$local_transfer_table}
            WHERE destination_ledger_id = %d
        ", $ledger_id));

        $wpdb->query('START TRANSACTION');

        try {
            // ===== XỬ LÝ CÁC LOT ĐÃ ĐƯỢC CHỌN ĐỂ NHẬP (trong phiếu nhập hiện tại) =====
            foreach ($items as $item) {
                $is_tracking = intval($item->local_product_is_tracking) === 1;

                if ($is_tracking) {
                    // Cập nhật các lot đã chọn về status = 5 (chờ trả mẹ)
                    $lot_ids = json_decode($item->list_product_lots, true) ?: [];

                    foreach ($lot_ids as $lot_id) {
                        // Tự động điền local_product_barcode_main nếu chưa có
                        TGS_Global_Lots_Helper::ensure_lot_has_barcode_main($lot_id, $item->local_product_name_id);

                        // Khi shop con từ chối nhận:
                        // source_blog_id: giữ nguyên (shop mẹ)
                        // to_blog_id: giữ nguyên (shop con)
                        // local_product_lot_is_active = 5 (chờ trả mẹ)
                        $wpdb->update($lots_table, [
                            'local_product_lot_is_active' => TGS_PRODUCT_LOT_PENDING_RETURN, // Chờ trả mẹ
                            'updated_at' => current_time('mysql')
                        ], ['global_product_lot_id' => $lot_id]);
                    }

                } else {
                    // Không cộng/trừ tồn kho vì chưa nhập
                    // Không cần xử lý gì
                }
            }

            // ===== XỬ LÝ CÁC LOT CHƯA ĐƯỢC CHỌN ĐỂ NHẬP (nếu là nhập 1 phần) =====
            if ($local_transfer) {
                $source_blog_id = intval($local_transfer->source_blog_id);
                $source_ledger_id = intval($local_transfer->source_ledger_id);

                // Lấy TOÀN BỘ lot từ phiếu xuất gốc (shop mẹ)
                switch_to_blog($source_blog_id);
                $source_ledger_item_table = $wpdb->prefix . 'local_ledger_item';

                $source_items = $wpdb->get_results($wpdb->prepare("
                    SELECT list_product_lots
                    FROM {$source_ledger_item_table}
                    WHERE local_ledger_id = %d
                      AND (is_deleted = 0 OR is_deleted IS NULL)
                ", $source_ledger_id), ARRAY_A);

                restore_current_blog();

                // Thu thập tất cả lot IDs từ phiếu xuất gốc
                $all_source_lot_ids = [];
                foreach ($source_items as $source_item) {
                    $lots = json_decode($source_item['list_product_lots'], true) ?: [];
                    $all_source_lot_ids = array_merge($all_source_lot_ids, array_map('intval', $lots));
                }
                $all_source_lot_ids = array_unique(array_filter($all_source_lot_ids));

                // Thu thập các lot đã chọn trong phiếu nhập
                $selected_lot_ids = [];
                foreach ($items as $item) {
                    $lots = json_decode($item->list_product_lots, true) ?: [];
                    $selected_lot_ids = array_merge($selected_lot_ids, array_map('intval', $lots));
                }
                $selected_lot_ids = array_unique(array_filter($selected_lot_ids));

                // Tìm các lot chưa được chọn
                $not_selected_lot_ids = array_diff($all_source_lot_ids, $selected_lot_ids);

                // Cập nhật status = 5 cho các lot chưa được chọn
                if (!empty($not_selected_lot_ids)) {
                    foreach ($not_selected_lot_ids as $lot_id) {
                        // Tự động điền local_product_barcode_main nếu chưa có
                        TGS_Global_Lots_Helper::ensure_lot_has_barcode_main($lot_id);

                        $wpdb->update($lots_table, [
                            'local_product_lot_is_active' => TGS_PRODUCT_LOT_PENDING_RETURN, // Chờ trả mẹ
                            'updated_at' => current_time('mysql')
                        ], ['global_product_lot_id' => $lot_id]);
                    }
                }
            }

            // Cập nhật trạng thái phiếu
            $wpdb->update($ledger_table, [
                'local_ledger_approver_status' => TGS_APPROVER_STATUS_REJECTED,
                'local_ledger_status' => TGS_LEDGER_STATUS_REJECTED,
                'local_ledger_approver_id' => $current_user_id,
                'updated_at' => current_time('mysql')
            ], ['local_ledger_id' => $ledger_id]);

            // Cập nhật transfer_ledger ở shop con
            $wpdb->update($local_transfer_table, [
                'transfer_status' => TGS_TRANSFER_STATUS_REJECTED,
                'updated_at' => current_time('mysql')
            ], ['transfer_ledger_id' => $local_transfer->transfer_ledger_id]);

            // Cập nhật transfer_ledger ở shop mẹ
            if ($local_transfer) {
                $source_blog_id = intval($local_transfer->source_blog_id);

                switch_to_blog($source_blog_id);

                $source_transfer_table = $wpdb->prefix . 'transfer_ledger';
                $wpdb->update($source_transfer_table, [
                    'transfer_status' => TGS_TRANSFER_STATUS_REJECTED,
                    'updated_at' => current_time('mysql')
                ], ['transfer_ledger_id' => $local_transfer->transfer_ledger_id]);

                restore_current_blog();
            }

            $wpdb->query('COMMIT');

            // Thêm log từ chối phiếu nhập transfer
            $source_shop_name = $local_transfer ? get_blog_option(intval($local_transfer->source_blog_id), 'blogname') : '';
            TGS_Shop_Ticket_Helper::add_ticket_log($ledger_id, 'reject', [
                'source_blog_id' => $local_transfer ? intval($local_transfer->source_blog_id) : 0,
                'source_shop_name' => $source_shop_name,
                'reason' => $reason,
                'items_count' => count($items)
            ], $reason);

            wp_send_json_success([
                'message' => 'Từ chối phiếu nhập thành công! Hàng sẽ chờ trả về shop mẹ.',
                'reason' => $reason
            ]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
}
