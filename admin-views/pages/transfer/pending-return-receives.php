<?php
/**
 * Danh sách phiếu chờ nhận từ shop trả
 *
 * Hiển thị các transfer return đang pending cần nhận hàng
 * Tận dụng giao diện pending-imports với transfer_type = return
 *
 * @package tgs_transfer_management
 */

if (!defined('ABSPATH')) {
    exit;
}

$ajax_url = admin_url('admin-ajax.php');
$nonce = wp_create_nonce('tgs_transfer_nonce');
$current_blog_id = get_current_blog_id();

// Đánh dấu là trang pending returns
$transfer_type = TGS_TRANSFER_TYPE_RETURN;
$page_title = 'Chờ nhận từ shop trả';
$empty_message = 'Hiện tại không có hàng trả nội bộ nào đang chờ nhận.';
$action_url = 'transfer-return-receive-add';
$ajax_action = 'tgs_transfer_get_pending_returns';
?>

<div class="app-pending-returns">
    <!-- Breadcrumb & Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div class="d-flex flex-column justify-content-center">
            <h4 class="mb-1">
                <?php echo esc_html($page_title); ?>
            </h4>
            <p class="text-muted mb-0">
                <a href="<?php echo admin_url('admin.php?page=tgs-shop-management'); ?>">Dashboard</a>
                <span class="mx-1">/</span>
                <span>Phiếu chờ nhận trả</span>
            </p>
        </div>
        <div>
            <button type="button" class="btn btn-outline-secondary" id="btnRefresh">
                <i class="bx bx-refresh"></i> Làm mới
            </button>
        </div>
    </div>

    <!-- Alert Message -->
    <div id="alertMessage" class="alert alert-dismissible mb-4 d-none" role="alert">
        <span id="alertText"></span>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>

    <!-- Loading -->
    <div id="loadingSpinner" class="text-center py-5">
        <div class="spinner-border text-secondary" role="status">
            <span class="visually-hidden">Đang tải...</span>
        </div>
        <p class="mt-3 text-muted">Đang tải danh sách phiếu chờ nhận trả...</p>
    </div>

    <!-- Empty State -->
    <div id="emptyState" class="card d-none">
        <div class="card-body text-center py-5">
            <i class="bx bx-package text-muted" style="font-size: 64px;"></i>
            <h5 class="mt-3 text-muted">Không có phiếu chờ nhận</h5>
            <p class="text-muted mb-0"><?php echo esc_html($empty_message); ?></p>
        </div>
    </div>

    <!-- Pending Returns List -->
    <div id="pendingReturnsList" class="d-none">
        <!-- Cards will be rendered here -->
    </div>
</div>

<style>
.pending-return-card {
    transition: all 0.2s ease;
    border-left: 4px solid #ff6b35;
}
.pending-return-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.pending-return-card.approved {
    border-left-color: #71dd37;
}
.product-list-item {
    padding: 8px 12px;
    background: #f8f9fa;
    border-radius: 6px;
    margin-bottom: 6px;
}
.product-list-item:last-child {
    margin-bottom: 0;
}
.tracking-badge {
    font-size: 11px;
    padding: 2px 6px;
}
</style>

<script>
jQuery(document).ready(function($) {
    const ajaxUrl = '<?php echo esc_js($ajax_url); ?>';
    const nonce = '<?php echo esc_js($nonce); ?>';
    const currentBlogId = <?php echo intval($current_blog_id); ?>;

    // Load pending returns
    function loadPendingReturns() {
        $('#loadingSpinner').removeClass('d-none');
        $('#emptyState').addClass('d-none');
        $('#pendingReturnsList').addClass('d-none');

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: '<?php echo esc_js($ajax_action); ?>',
                nonce: nonce
            },
            success: function(response) {
                $('#loadingSpinner').addClass('d-none');

                if (response.success && response.data && response.data.length > 0) {
                    renderPendingReturns(response.data);
                    $('#pendingReturnsList').removeClass('d-none');
                } else {
                    $('#emptyState').removeClass('d-none');
                }
            },
            error: function() {
                $('#loadingSpinner').addClass('d-none');
                showAlert('danger', 'Có lỗi khi tải danh sách phiếu chờ nhận trả');
            }
        });
    }

    // Render pending returns
    function renderPendingReturns(returns) {
        let html = '';

        returns.forEach(function(item) {
            const statusClass = item.transfer_status == 1 ? 'approved' : '';
            const statusBadge = item.transfer_status == 1
                ? '<span class="badge bg-success">Đã duyệt trả</span>'
                : '<span class="badge bg-warning">Chờ duyệt trả</span>';

            html += `
                <div class="card mb-3 pending-return-card ${statusClass}">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h6 class="mb-0">
                                <i class="bx bx-store text-warning"></i>
                                Từ: <strong>${escapeHtml(item.source_shop_name || 'Shop #' + item.source_blog_id)}</strong>
                            </h6>
                            <small class="text-muted">
                                Mã transfer: #${item.transfer_id} |
                                Phiếu trả: #${item.source_ledger_id}
                            </small>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            ${statusBadge}
                            <span class="badge bg-secondary">${item.items_count || 0} sản phẩm</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <small class="text-muted d-block">Ngày tạo</small>
                                <span>${formatDate(item.created_at)}</span>
                            </div>
                            <div class="col-md-4">
                                <small class="text-muted d-block">Ghi chú</small>
                                <span class="text-muted fst-italic">${item.note || '—'}</span>
                            </div>
                            <div class="col-md-4 text-md-end">
                                ${item.transfer_status == 1 ? `
                                    <a href="<?php echo admin_url('admin.php?page=tgs-shop-management&view=' . $action_url); ?>&transfer_id=${item.transfer_id}"
                                       class="btn btn-info">
                                        <i class="bx bx-import"></i> Tạo phiếu nhận trả
                                    </a>
                                ` : `
                                    <button class="btn btn-outline-secondary" disabled>
                                        Chờ shop trả duyệt
                                    </button>
                                `}
                            </div>
                        </div>

                        <!-- Collapsed product list -->
                        <div class="collapse" id="products-${item.transfer_id}">
                            <div class="border-top pt-3">
                                <h6 class="mb-2">Danh sách sản phẩm trả:</h6>
                                <div class="products-container" id="products-list-${item.transfer_id}">
                                    <div class="text-center py-2">
                                        <div class="spinner-border spinner-border-sm" role="status"></div>
                                        <span class="ms-2">Đang tải...</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button class="btn btn-sm btn-outline-secondary mt-2 btn-toggle-products"
                                data-transfer-id="${item.transfer_id}"
                                data-loaded="false">
                            <i class="bx bx-chevron-down"></i> Xem sản phẩm
                        </button>
                    </div>
                </div>
            `;
        });

        $('#pendingReturnsList').html(html);
    }

    // Load products for a transfer
    function loadTransferProducts(transferId) {
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'tgs_transfer_get_items',
                nonce: nonce,
                transfer_id: transferId
            },
            success: function(response) {
                if (response.success && response.data) {
                    renderTransferProducts(transferId, response.data);
                } else {
                    $(`#products-list-${transferId}`).html('<div class="text-muted">Không có sản phẩm</div>');
                }
            },
            error: function() {
                $(`#products-list-${transferId}`).html('<div class="text-danger">Lỗi tải sản phẩm</div>');
            }
        });
    }

    // Render products for a transfer
    function renderTransferProducts(transferId, items) {
        let html = '';

        items.forEach(function(item, index) {
            const isTracking = item.is_tracking == 1;
            const trackingBadge = isTracking
                ? '<span class="badge bg-info tracking-badge">Theo HSD</span>'
                : '<span class="badge bg-secondary tracking-badge">Không tracking</span>';

            html += `
                <div class="product-list-item d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <span class="text-muted">${index + 1}.</span>
                        <div>
                            <strong>${escapeHtml(item.product_name)}</strong>
                            <br>
                            <small class="text-muted">Barcode: ${escapeHtml(item.barcode_main || item.barcode)}</small>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        ${trackingBadge}
                        <span class="badge bg-primary">${formatQuantity(item.quantity)} ${escapeHtml(item.unit_name || 'SP')}</span>
                    </div>
                </div>
            `;
        });

        $(`#products-list-${transferId}`).html(html || '<div class="text-muted">Không có sản phẩm</div>');
    }

    // Toggle products
    $(document).on('click', '.btn-toggle-products', function() {
        const btn = $(this);
        const transferId = btn.data('transfer-id');
        const loaded = btn.data('loaded');
        const target = $(`#products-${transferId}`);

        if (!loaded || loaded === 'false') {
            loadTransferProducts(transferId);
            btn.data('loaded', 'true');
        }

        if (target.hasClass('show')) {
            target.removeClass('show');
            btn.html('<i class="bx bx-chevron-down"></i> Xem sản phẩm');
        } else {
            target.addClass('show');
            btn.html('<i class="bx bx-chevron-up"></i> Ẩn sản phẩm');
        }
    });

    // Refresh
    $('#btnRefresh').on('click', function() {
        loadPendingReturns();
    });

    // Helpers
    function showAlert(type, message) {
        $('#alertMessage')
            .removeClass('d-none alert-success alert-danger alert-warning alert-info')
            .addClass('alert-' + type);
        $('#alertText').text(message);
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatDate(dateStr) {
        if (!dateStr) return '—';
        const date = new Date(dateStr);
        return date.toLocaleDateString('vi-VN') + ' ' + date.toLocaleTimeString('vi-VN', {hour: '2-digit', minute: '2-digit'});
    }

    function formatQuantity(value) {
        if (!value) return '0';
        const num = parseFloat(value);
        return Number.isInteger(num) ? num.toString() : num.toFixed(2).replace(/\.?0+$/, '');
    }

    // Initial load
    loadPendingReturns();
});
</script>
