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
     */
    public static function create_export()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        // TODO: Implement return export logic (similar to transfer but reverse direction)
        // This will create a RETURN_EXPORT ledger instead of TRANSFER_EXPORT

        wp_send_json_error(['message' => 'Chức năng đang được phát triển']);
    }

    /**
     * Duyệt phiếu trả
     */
    public static function approve_export()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        // TODO: Implement approve return export
        wp_send_json_error(['message' => 'Chức năng đang được phát triển']);
    }

    /**
     * Từ chối phiếu trả
     */
    public static function reject_export()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        // TODO: Implement reject return export
        wp_send_json_error(['message' => 'Chức năng đang được phát triển']);
    }

    /**
     * Lấy danh sách phiếu trả chờ nhận (shop mẹ nhận từ shop con)
     */
    public static function get_pending_returns()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        // TODO: Query return_ledger table for pending returns
        // where destination_blog_id = current_blog_id and destination_ledger_id is null

        wp_send_json_success([]);
    }

    /**
     * Tạo phiếu nhận hàng trả (shop mẹ nhận từ shop con)
     */
    public static function create_import()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        // TODO: Implement return import logic
        wp_send_json_error(['message' => 'Chức năng đang được phát triển']);
    }

    /**
     * Duyệt phiếu nhận
     */
    public static function approve_import()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        // TODO: Implement approve return import
        wp_send_json_error(['message' => 'Chức năng đang được phát triển']);
    }

    /**
     * Từ chối phiếu nhận
     */
    public static function reject_import()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        // TODO: Implement reject return import
        wp_send_json_error(['message' => 'Chức năng đang được phát triển']);
    }

    /**
     * Danh sách phiếu trả đã tạo
     */
    public static function get_exports_list()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        // TODO: Get list of return exports
        wp_send_json_success(['data' => [], 'total' => 0]);
    }

    /**
     * Danh sách phiếu nhận hàng trả
     */
    public static function get_imports_list()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        // TODO: Get list of return imports
        wp_send_json_success(['data' => [], 'total' => 0]);
    }

    /**
     * Chi tiết phiếu
     */
    public static function get_detail()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        // TODO: Get return detail
        wp_send_json_error(['message' => 'Không tìm thấy phiếu']);
    }

    /**
     * Lấy chi tiết phiếu trả từ return_ledger
     */
    public static function get_return_detail()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        // TODO: Implement get return detail
        wp_send_json_error(['message' => 'Không tìm thấy phiếu']);
    }

    /**
     * Lấy items của phiếu trả
     */
    public static function get_return_items()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        // TODO: Get return items
        wp_send_json_success([]);
    }

    /**
     * Cập nhật tình trạng lot
     */
    public static function update_lot_conditions()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        // TODO: Update lot conditions
        wp_send_json_success(['message' => 'Đã cập nhật']);
    }

    /**
     * Lấy dữ liệu báo cáo trả hàng
     */
    public static function get_report_data()
    {
        check_ajax_referer('tgs_return_nonce', 'nonce');

        // Return empty report data for now
        wp_send_json_success([
            'summary' => [
                'export_count' => 0,
                'export_approved' => 0,
                'export_pending' => 0,
                'import_count' => 0,
                'import_approved' => 0,
                'import_pending' => 0,
                'pending_receive' => 0,
                'products_exported' => 0,
                'products_imported' => 0
            ],
            'trend' => [],
            'exported_to' => [],
            'imported_from' => [],
            'recent' => []
        ]);
    }
}
