<?php
/**
 * Chi tiết phiếu bán nội bộ
 *
 * Sử dụng ticket detail base
 *
 * @package tgs_shop_management
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load config
require_once TGS_SHOP_PLUGIN_DIR . 'admin-views/pages/ticket/base/ticket-config.php';

// Get config
$ticket_config = tgs_get_ticket_config('transfer_export');

// Get ticket ID
$ticket_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Include base template
require_once TGS_SHOP_PLUGIN_DIR . 'admin-views/pages/ticket/base/ticket-detail-base.php';
