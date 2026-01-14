<?php
/**
 * Trang tạo phiếu Trả hàng nội bộ
 *
 * Tận dụng 100% giao diện ticket-create-base từ plugin shop
 *
 * @package tgs_transfer_management
 */

if (!defined('ABSPATH')) {
    exit;
}

// Định nghĩa loại phiếu
$ticket_type = 'internal_return';

// Include base template từ tgs_shop_management
include TGS_SHOP_PLUGIN_DIR . 'admin-views/pages/ticket/create/ticket-create-base.php';
