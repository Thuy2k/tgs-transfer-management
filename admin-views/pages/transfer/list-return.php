<?php
/**
 * Danh sách phiếu Trả hàng nội bộ
 *
 * Tận dụng ticket list base từ plugin shop
 *
 * @package tgs_transfer_management
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load config
require_once TGS_SHOP_PLUGIN_DIR . 'admin-views/pages/ticket/base/ticket-config.php';

// Get config cho internal_return
$ticket_config = tgs_get_ticket_config('internal_return');

// Include base template
require_once TGS_SHOP_PLUGIN_DIR . 'admin-views/pages/ticket/base/ticket-list-base.php';
