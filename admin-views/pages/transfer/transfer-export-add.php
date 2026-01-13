<?php
/**
 * Transfer Export Add - Tạo Phiếu Bán Hàng Nội Bộ
 *
 * Sử dụng ticket-create-base từ tgs_shop_management để render giao diện
 * Tương tự như sale-add.php nhưng dành cho bán nội bộ (xuất cho chi nhánh)
 *
 * @package tgs-transfer-management
 */

if (!defined('ABSPATH')) {
    exit;
}

// Định nghĩa loại phiếu
$ticket_type = 'internal_export';

// Include base template từ tgs_shop_management
include TGS_SHOP_PLUGIN_DIR . 'admin-views/pages/ticket/create/ticket-create-base.php';
