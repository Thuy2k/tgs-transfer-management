<?php
/**
 * Chi tiết phiếu Nhận trả nội bộ
 *
 * Tận dụng ticket detail base từ plugin shop
 *
 * @package tgs_transfer_management
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load config
require_once TGS_SHOP_PLUGIN_DIR . 'admin-views/pages/ticket/base/ticket-config.php';

// Get config cho internal_return_receive
$ticket_config = tgs_get_ticket_config('internal_return_receive');

// Include base template
require_once TGS_SHOP_PLUGIN_DIR . 'admin-views/pages/ticket/base/ticket-detail-base.php';
