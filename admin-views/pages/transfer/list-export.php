<?php
/**
 * Danh sách phiếu bán nội bộ
 *
 * Sử dụng ticket list base
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

// Include base template
require_once TGS_SHOP_PLUGIN_DIR . 'admin-views/pages/ticket/base/ticket-list-base.php';
