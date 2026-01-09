<?php
/**
 * Form tạo phiếu nhập từ shop mẹ
 *
 * Tạo phiếu nhập dựa trên transfer đã được duyệt xuất
 * - Sản phẩm không tracking: nhập số lượng
 * - Sản phẩm tracking: chọn mã định danh (lot) qua modal + scan
 *
 * @package tgs_shop_management
 */

if (!defined('ABSPATH')) {
    exit;
}

$ajax_url = admin_url('admin-ajax.php');
$nonce = wp_create_nonce('tgs_transfer_nonce');
$transfer_id = isset($_GET['transfer_id']) ? intval($_GET['transfer_id']) : 0;

if (!$transfer_id) {
    echo '<div class="alert alert-danger">Không tìm thấy thông tin phiếu chuyển. <a href="' . esc_url(admin_url('admin.php?page=tgs-shop-management&view=transfer-pending-imports')) . '">Quay lại</a></div>';
    return;
}
?>

<div class="app-transfer-import-add">
    <!-- Breadcrumb & Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div class="d-flex flex-column justify-content-center">
            <h4 class="mb-1">
                Tạo phiếu nhập từ shop mẹ
            </h4>
            <p class="text-muted mb-0">
                <a href="<?php echo admin_url('admin.php?page=tgs-shop-management'); ?>">Dashboard</a>
                <span class="mx-1">/</span>
                <a href="<?php echo admin_url('admin.php?page=tgs-shop-management&view=transfer-pending-imports'); ?>">Phiếu chờ nhận</a>
                <span class="mx-1">/</span>
                <span>Tạo phiếu nhập</span>
            </p>
        </div>
        <div>
            <a href="<?php echo admin_url('admin.php?page=tgs-shop-management&view=transfer-pending-imports'); ?>"
               class="btn btn-outline-secondary">
                <i class="bx bx-arrow-back"></i> Quay lại
            </a>
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
        <p class="mt-3 text-muted">Đang tải thông tin phiếu chuyển...</p>
    </div>

    <!-- Error State -->
    <div id="errorState" class="card d-none">
        <div class="card-body text-center py-5">
            <i class="bx bx-error-circle text-danger" style="font-size: 64px;"></i>
            <h5 class="mt-3 text-danger" id="errorMessage">Có lỗi xảy ra</h5>
            <a href="<?php echo admin_url('admin.php?page=tgs-shop-management&view=transfer-pending-imports'); ?>"
               class="btn btn-secondary mt-3">Quay lại</a>
        </div>
    </div>

    <!-- Main Form -->
    <div id="mainForm" class="d-none">
        <div class="row">
            <!-- Left Column: Transfer Info -->
            <div class="col-12 col-lg-8 mb-4">
                <!-- Transfer Info Card -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Thông tin phiếu chuyển</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <small class="text-muted d-block">Mã Transfer</small>
                                <span class="fw-semibold" id="infoTransferId">#<?php echo esc_html($transfer_id); ?></span>
                            </div>
                            <div class="col-md-4 mb-3">
                                <small class="text-muted d-block">Shop nguồn</small>
                                <span class="fw-semibold" id="infoSourceShop">—</span>
                            </div>
                            <div class="col-md-4 mb-3">
                                <small class="text-muted d-block">Phiếu xuất nguồn</small>
                                <span id="infoSourceLedger">—</span>
                            </div>
                            <div class="col-md-4 mb-3">
                                <small class="text-muted d-block">Ngày tạo</small>
                                <span id="infoCreatedAt">—</span>
                            </div>
                            <div class="col-md-4 mb-3">
                                <small class="text-muted d-block">Trạng thái</small>
                                <span id="infoStatus">—</span>
                            </div>
                            <div class="col-md-4 mb-3">
                                <small class="text-muted d-block">Ghi chú từ shop mẹ</small>
                                <span class="text-muted fst-italic" id="infoNote">—</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Products Card -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Sản phẩm nhận</h5>
                        <span class="badge bg-secondary" id="productCount">0 sản phẩm</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="productsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 3%;">#</th>
                                        <th style="width: 20%;">Sản phẩm</th>
                                        <th style="width: 12%;">Barcode</th>
                                        <th style="width: 10%;">SL tối đa</th>
                                        <th style="width: 15%;">Mã định danh</th>
                                        <th style="width: 10%;">SL nhập</th>
                                        <th style="width: 8%;">Đơn vị</th>
                                        <th style="width: 12%;">Trạng thái</th>
                                    </tr>
                                </thead>
                                <tbody id="productsTableBody">
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted">
                                            Đang tải sản phẩm...
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot class="table-light" id="productsTableFoot" style="display: none;">
                                    <tr>
                                        <td colspan="4" class="text-end fw-semibold">Tổng cộng:</td>
                                        <td></td>
                                        <td class="fw-semibold" id="footTotalImport">0</td>
                                        <td></td>
                                        <td id="footStatus">—</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Actions -->
            <div class="col-12 col-lg-4 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Tạo phiếu nhập</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Ghi chú phiếu nhập</label>
                            <textarea class="form-control" id="importNote" rows="3"
                                      placeholder="Ghi chú cho phiếu nhập (tùy chọn)..."></textarea>
                        </div>

                        <div class="alert alert-warning mb-3" id="warningSync" style="display: none;">
                            <i class="bx bx-sync"></i>
                            <span id="warningSyncText"></span>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-success btn-lg" id="btnCreateImport" disabled>
                                <i class="bx bx-check-circle"></i> Tạo phiếu nhập
                            </button>
                            <small class="text-muted text-center">
                                Phiếu nhập sẽ ở trạng thái chờ duyệt
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Help Card -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h6 class="card-title mb-0">Hướng dẫn</h6>
                    </div>
                    <div class="card-body">
                        <ol class="mb-0 ps-3">
                            <li class="mb-2">Kiểm tra sản phẩm và số lượng</li>
                            <li class="mb-2">Với SP <strong>theo dõi HSD</strong>: click "Chọn mã" để scan/chọn mã định danh</li>
                            <li class="mb-2">Với SP <strong>không tracking</strong>: nhập số lượng trực tiếp</li>
                            <li class="mb-0">Nhấn "Tạo phiếu nhập" khi hoàn tất</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal chọn mã định danh -->
<div class="modal fade" id="lotSelectModal" tabindex="-1" aria-labelledby="lotSelectModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="lotSelectModalLabel">
                    <i class="bx bx-barcode"></i> Chọn mã định danh
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Product info -->
                <div class="alert alert-secondary mb-3">
                    <strong id="modalProductName">—</strong>
                    <br>
                    <small class="text-muted">Barcode: <code id="modalProductBarcode">—</code></small>
                </div>

                <!-- Scan input -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">
                        <i class="bx bx-scan"></i> Scan mã định danh
                    </label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="scanLotInput"
                               placeholder="Scan hoặc nhập mã định danh..." autofocus>
                        <button class="btn btn-outline-secondary" type="button" id="btnClearScan">
                            <i class="bx bx-x"></i>
                        </button>
                    </div>
                    <div id="scanResult" class="mt-2"></div>
                </div>

                <!-- Quick actions -->
                <div class="mb-3 d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-success" id="btnSelectAll">
                        <i class="bx bx-check-double"></i> Chọn tất cả
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="btnDeselectAll">
                        <i class="bx bx-x"></i> Bỏ chọn tất cả
                    </button>
                    <span class="ms-auto badge bg-primary align-self-center" id="modalSelectedCount">0 / 0 đã chọn</span>
                </div>

                <!-- Lot list -->
                <div class="border rounded" style="max-height: 300px; overflow-y: auto;">
                    <div id="lotListContainer" class="p-2">
                        <!-- Rendered by JS -->
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                <button type="button" class="btn btn-primary" id="btnConfirmLots">
                    <i class="bx bx-check"></i> Xác nhận
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.lot-item {
    padding: 8px 12px;
    border-bottom: 1px solid #eee;
    transition: background-color 0.2s;
}
.lot-item:last-child {
    border-bottom: none;
}
.lot-item:hover {
    background-color: #f8f9fa;
}
.lot-item.selected {
    background-color: #d1e7dd;
}
.lot-item .form-check-input:checked {
    background-color: #198754;
    border-color: #198754;
}
.scan-success {
    color: #198754;
    font-weight: 500;
}
.scan-error {
    color: #dc3545;
    font-weight: 500;
}
</style>

<script>
jQuery(document).ready(function($) {
    const ajaxUrl = '<?php echo esc_js($ajax_url); ?>';
    const nonce = '<?php echo esc_js($nonce); ?>';
    const transferId = <?php echo intval($transfer_id); ?>;

    let transferData = null;
    let productsData = [];

    // Lưu trữ dữ liệu nhập cho từng sản phẩm
    // Key = barcode, Value = { quantity, selectedLots: [] }
    let importData = {};

    // Modal state
    let currentModalBarcode = null;
    let currentModalLots = [];
    let currentModalSelectedLots = [];

    // =========================================================================
    // LOAD DATA
    // =========================================================================
    function loadTransferInfo() {
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'tgs_transfer_get_transfer_detail',
                nonce: nonce,
                transfer_id: transferId
            },
            success: function(response) {
                $('#loadingSpinner').addClass('d-none');

                if (response.success && response.data) {
                    transferData = response.data.transfer;
                    productsData = response.data.items || [];

                    // Check if already imported
                    if (transferData.destination_ledger_id) {
                        showError('Phiếu chuyển này đã được nhập. Mã phiếu nhập: #' + transferData.destination_ledger_id);
                        return;
                    }

                    // Check if approved by source shop
                    if (transferData.local_ledger_approver_status != 1) {
                        showError('Phiếu chuyển này chưa được shop mẹ duyệt xuất.');
                        return;
                    }

                    // Initialize import data - mặc định lấy full
                    productsData.forEach(function(item) {
                        const barcode = item.barcode_main || item.barcode;
                        const isTracking = item.is_tracking == 1;
                        const maxQty = parseInt(item.quantity) || 0;

                        if (isTracking) {
                            // Sử dụng lots_detail từ response (có barcode và exp_date)
                            let lotsDetail = item.lots_detail || [];
                            // Mặc định chọn tất cả lots
                            importData[barcode] = {
                                isTracking: true,
                                maxQuantity: maxQty,
                                allLots: lotsDetail, // Array of {id, barcode, exp_date, ...}
                                selectedLots: lotsDetail.map(l => l.id), // Chọn tất cả IDs
                                quantity: lotsDetail.length
                            };
                        } else {
                            // Không tracking - mặc định full quantity
                            importData[barcode] = {
                                isTracking: false,
                                maxQuantity: maxQty,
                                allLots: [],
                                selectedLots: [],
                                quantity: maxQty
                            };
                        }
                    });

                    renderTransferInfo();
                    renderProducts();
                    updateSummary();
                    $('#mainForm').removeClass('d-none');
                } else {
                    showError(response.data?.message || 'Không tìm thấy thông tin phiếu chuyển');
                }
            },
            error: function() {
                $('#loadingSpinner').addClass('d-none');
                showError('Có lỗi khi tải thông tin phiếu chuyển');
            }
        });
    }

    // =========================================================================
    // RENDER FUNCTIONS
    // =========================================================================
    function showError(message) {
        $('#errorMessage').text(message);
        $('#errorState').removeClass('d-none');
    }

    function renderTransferInfo() {
        $('#infoSourceShop').text(transferData.source_shop_name || 'Shop #' + transferData.source_blog_id);
        $('#infoSourceLedger').html('<a href="#">#' + transferData.source_ledger_id + '</a>');
        $('#infoCreatedAt').text(formatDate(transferData.created_at));
        $('#infoNote').text(transferData.local_ledger_note || transferData.note || '—');

        const statusBadge = transferData.local_ledger_approver_status == 1
            ? '<span class="badge bg-success">Đã duyệt xuất</span>'
            : '<span class="badge bg-warning">Chờ duyệt</span>';
        $('#infoStatus').html(statusBadge);
    }

    function renderProducts() {
        if (!productsData || productsData.length === 0) {
            $('#productsTableBody').html('<tr><td colspan="8" class="text-center py-4 text-muted">Không có sản phẩm</td></tr>');
            return;
        }

        let html = '';
        let needsSync = 0;

        productsData.forEach(function(item, index) {
            const barcode = item.barcode_main || item.barcode;
            const isTracking = item.is_tracking == 1;
            const maxQty = parseInt(item.quantity) || 0;
            const data = importData[barcode] || {};
            const importQty = data.quantity || 0;

            if (!item.synced_in_destination) {
                needsSync++;
            }

            // Cột mã định danh
            let lotColumn;
            if (isTracking) {
                const selectedCount = (data.selectedLots || []).length;
                const totalCount = (data.allLots || []).length;
                lotColumn = `
                    <button type="button" class="btn btn-sm btn-outline-info btn-select-lots"
                            data-barcode="${escapeHtml(barcode)}"
                            data-product-name="${escapeHtml(item.product_name)}">
                        <i class="bx bx-barcode"></i> ${selectedCount}/${totalCount}
                    </button>
                `;
            } else {
                lotColumn = '<span class="text-muted">—</span>';
            }

            // Cột số lượng nhập
            let qtyColumn;
            if (isTracking) {
                // Tracking: readonly, tính từ số lots đã chọn
                qtyColumn = `<span class="fw-semibold import-qty-display" data-barcode="${escapeHtml(barcode)}">${importQty}</span>`;
            } else {
                // Không tracking: input number
                qtyColumn = `
                    <input type="number"
                           class="form-control form-control-sm import-qty-input"
                           value="${importQty}"
                           min="0"
                           max="${maxQty}"
                           data-barcode="${escapeHtml(barcode)}"
                           style="width: 80px;">
                `;
            }

            // Trạng thái từng dòng
            const itemStatus = getItemStatus(importQty, maxQty);

            html += `
                <tr data-barcode="${escapeHtml(barcode)}" data-max="${maxQty}" data-tracking="${isTracking ? 1 : 0}">
                    <td>${index + 1}</td>
                    <td>
                        <strong>${escapeHtml(item.product_name)}</strong>
                        ${isTracking ? '<br><span class="badge bg-info badge-sm">Theo HSD</span>' : ''}
                    </td>
                    <td><code>${escapeHtml(barcode)}</code></td>
                    <td class="text-center"><strong>${maxQty}</strong></td>
                    <td class="text-center">${lotColumn}</td>
                    <td class="text-center">${qtyColumn}</td>
                    <td>${escapeHtml(item.unit_name || 'SP')}</td>
                    <td class="item-status">${itemStatus}</td>
                </tr>
            `;
        });

        $('#productsTableBody').html(html);
        $('#productCount').text(productsData.length + ' sản phẩm');
        $('#productsTableFoot').show();

        // Show sync warning
        if (needsSync > 0) {
            $('#warningSyncText').text(needsSync + ' sản phẩm sẽ được tự động đồng bộ khi tạo phiếu nhập.');
            $('#warningSync').show();
        }
    }

    function getItemStatus(importQty, maxQty) {
        if (importQty === 0) {
            return '<span class="badge bg-danger">Không nhập</span>';
        } else if (importQty < maxQty) {
            return '<span class="badge bg-warning">Nhập 1 phần</span>';
        } else {
            return '<span class="badge bg-success">Nhập hết</span>';
        }
    }

    function updateSummary() {
        let totalMax = 0;
        let totalImport = 0;

        productsData.forEach(function(item) {
            const barcode = item.barcode_main || item.barcode;
            const maxQty = parseInt(item.quantity) || 0;
            const data = importData[barcode] || {};
            const importQty = data.quantity || 0;

            totalMax += maxQty;
            totalImport += importQty;
        });

        $('#footTotalImport').text(totalImport + ' / ' + totalMax);

        // Trạng thái tổng
        let statusBadge;
        if (totalImport === 0) {
            statusBadge = '<span class="badge bg-danger">Không nhập gì</span>';
            $('#btnCreateImport').prop('disabled', true);
        } else if (totalImport < totalMax) {
            statusBadge = '<span class="badge bg-warning">Nhập 1 phần</span>';
            $('#btnCreateImport').prop('disabled', false);
        } else {
            statusBadge = '<span class="badge bg-success">Nhập hết</span>';
            $('#btnCreateImport').prop('disabled', false);
        }
        $('#footStatus').html(statusBadge);
    }

    function updateRowDisplay(barcode) {
        const $row = $(`tr[data-barcode="${barcode}"]`);
        const data = importData[barcode] || {};
        const maxQty = parseInt($row.data('max')) || 0;
        const importQty = data.quantity || 0;
        const isTracking = data.isTracking;

        // Update quantity display
        if (isTracking) {
            $row.find('.import-qty-display').text(importQty);
            // Update button text
            const selectedCount = (data.selectedLots || []).length;
            const totalCount = (data.allLots || []).length;
            $row.find('.btn-select-lots').html(`<i class="bx bx-barcode"></i> ${selectedCount}/${totalCount}`);
        }

        // Update status
        $row.find('.item-status').html(getItemStatus(importQty, maxQty));

        // Update summary
        updateSummary();
    }

    // =========================================================================
    // QUANTITY INPUT (Non-tracking products)
    // =========================================================================
    $(document).on('input', '.import-qty-input', function() {
        const $input = $(this);
        const barcode = $input.data('barcode');
        const $row = $input.closest('tr');
        const maxQty = parseInt($row.data('max')) || 0;
        let value = parseInt($input.val()) || 0;

        // Validate range
        if (value < 0) value = 0;
        if (value > maxQty) value = maxQty;
        $input.val(value);

        // Update stored data
        if (importData[barcode]) {
            importData[barcode].quantity = value;
        }

        // Update row status
        $row.find('.item-status').html(getItemStatus(value, maxQty));

        // Update summary
        updateSummary();
    });

    // =========================================================================
    // LOT SELECT MODAL (Tracking products)
    // =========================================================================
    $(document).on('click', '.btn-select-lots', function() {
        const barcode = $(this).data('barcode');
        const productName = $(this).data('product-name');
        const data = importData[barcode];

        if (!data) return;

        // Set modal state
        currentModalBarcode = barcode;
        currentModalLots = data.allLots || [];
        currentModalSelectedLots = [...(data.selectedLots || [])]; // Clone

        // Update modal UI
        $('#modalProductName').text(productName);
        $('#modalProductBarcode').text(barcode);
        $('#scanLotInput').val('');
        $('#scanResult').html('');

        renderLotList();
        updateModalSelectedCount();

        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('lotSelectModal'));
        modal.show();

        // Focus scan input
        setTimeout(() => $('#scanLotInput').focus(), 500);
    });

    function renderLotList() {
        let html = '';

        if (currentModalLots.length === 0) {
            html = '<div class="text-center text-muted py-3">Không có mã định danh</div>';
        } else {
            currentModalLots.forEach(function(lot, index) {
                const lotId = lot.id;
                const lotBarcode = lot.barcode || lotId;
                const expDate = lot.exp_date ? formatExpDate(lot.exp_date) : '';
                const isSelected = currentModalSelectedLots.includes(lotId);
                html += `
                    <div class="lot-item ${isSelected ? 'selected' : ''}" data-lot-id="${lotId}" data-lot-barcode="${escapeHtml(lotBarcode)}">
                        <div class="form-check d-flex align-items-center justify-content-between">
                            <div>
                                <input class="form-check-input lot-checkbox" type="checkbox"
                                       id="lot_${lotId}" value="${lotId}" ${isSelected ? 'checked' : ''}>
                                <label class="form-check-label" for="lot_${lotId}">
                                    <code class="text-primary">${escapeHtml(lotBarcode)}</code>
                                </label>
                            </div>
                            ${expDate ? `<small class="text-muted">HSD: ${expDate}</small>` : ''}
                        </div>
                    </div>
                `;
            });
        }

        $('#lotListContainer').html(html);
    }

    function formatExpDate(dateStr) {
        if (!dateStr) return '';
        const date = new Date(dateStr);
        if (isNaN(date.getTime())) return dateStr;
        return date.toLocaleDateString('vi-VN');
    }

    function updateModalSelectedCount() {
        const selected = currentModalSelectedLots.length;
        const total = currentModalLots.length;
        $('#modalSelectedCount').text(`${selected} / ${total} đã chọn`);
    }

    // Checkbox change
    $(document).on('change', '.lot-checkbox', function() {
        const lotId = parseInt($(this).val()); // Convert to number for consistency
        const isChecked = $(this).is(':checked');
        const $item = $(this).closest('.lot-item');

        if (isChecked) {
            if (!currentModalSelectedLots.includes(lotId)) {
                currentModalSelectedLots.push(lotId);
            }
            $item.addClass('selected');
        } else {
            currentModalSelectedLots = currentModalSelectedLots.filter(id => id !== lotId);
            $item.removeClass('selected');
        }

        updateModalSelectedCount();
    });

    // Select all
    $('#btnSelectAll').on('click', function() {
        currentModalSelectedLots = currentModalLots.map(lot => lot.id);
        renderLotList();
        updateModalSelectedCount();
    });

    // Deselect all
    $('#btnDeselectAll').on('click', function() {
        currentModalSelectedLots = [];
        renderLotList();
        updateModalSelectedCount();
    });

    // Scan input
    $('#scanLotInput').on('keypress', function(e) {
        if (e.which === 13) { // Enter
            e.preventDefault();
            processScan();
        }
    });

    $('#btnClearScan').on('click', function() {
        $('#scanLotInput').val('').focus();
        $('#scanResult').html('');
    });

    function processScan() {
        const scannedValue = $('#scanLotInput').val().trim();
        if (!scannedValue) return;

        // Tìm lot matching theo barcode (mã định danh thực)
        const matchedLot = currentModalLots.find(lot => lot.barcode === scannedValue);

        if (matchedLot) {
            const lotId = matchedLot.id;
            // Nếu đã chọn rồi thì báo
            if (currentModalSelectedLots.includes(lotId)) {
                $('#scanResult').html('<span class="scan-success"><i class="bx bx-check-circle"></i> Mã này đã được chọn</span>');
            } else {
                // Chọn mã này
                currentModalSelectedLots.push(lotId);
                renderLotList();
                updateModalSelectedCount();
                $('#scanResult').html('<span class="scan-success"><i class="bx bx-check-circle"></i> Đã thêm mã: ' + escapeHtml(matchedLot.barcode) + '</span>');
            }
        } else {
            // Không tìm thấy
            $('#scanResult').html('<span class="scan-error"><i class="bx bx-x-circle"></i> Mã không khớp: ' + escapeHtml(scannedValue) + '</span>');
        }

        // Clear input và focus lại
        $('#scanLotInput').val('').focus();
    }

    // Confirm lots
    $('#btnConfirmLots').on('click', function() {
        if (!currentModalBarcode) return;

        // Update import data
        importData[currentModalBarcode].selectedLots = [...currentModalSelectedLots];
        importData[currentModalBarcode].quantity = currentModalSelectedLots.length;

        // Update row display
        updateRowDisplay(currentModalBarcode);

        // Close modal
        bootstrap.Modal.getInstance(document.getElementById('lotSelectModal')).hide();

        // Reset modal state
        currentModalBarcode = null;
        currentModalLots = [];
        currentModalSelectedLots = [];
    });

    // =========================================================================
    // CREATE IMPORT
    // =========================================================================
    function buildItemsData() {
        const items = [];
        productsData.forEach(function(item) {
            const barcode = item.barcode_main || item.barcode;
            const data = importData[barcode];

            if (!data || data.quantity <= 0) return;

            items.push({
                barcode: barcode,
                max_quantity: data.maxQuantity,
                import_quantity: data.quantity,
                is_tracking: data.isTracking,
                selected_lots: data.isTracking ? data.selectedLots : [],
                source_ledger_item_id: item.local_ledger_item_id || item.ledger_item_id
            });
        });
        return items;
    }

    $('#btnCreateImport').on('click', function() {
        const btn = $(this);
        const originalText = btn.html();
        const itemsData = buildItemsData();

        if (itemsData.length === 0) {
            showAlert('warning', 'Vui lòng chọn ít nhất 1 sản phẩm để nhập.');
            return;
        }

        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Đang xử lý...');

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'tgs_transfer_create_import',
                nonce: nonce,
                transfer_id: transferId,
                note: $('#importNote').val(),
                items: JSON.stringify(itemsData)
            },
            success: function(response) {
                if (response.success) {
                    showAlert('success', 'Tạo phiếu nhập thành công! Mã phiếu: #' + response.data.ledger_id);

                    // Redirect to detail page after 2 seconds
                    setTimeout(function() {
                        window.location.href = '<?php echo admin_url('admin.php?page=tgs-shop-management&view=ticket-transfer-import-detail'); ?>&id=' + response.data.ledger_id;
                    }, 2000);
                } else {
                    showAlert('danger', response.data?.message || 'Có lỗi khi tạo phiếu nhập');
                    btn.prop('disabled', false).html(originalText);
                }
            },
            error: function() {
                showAlert('danger', 'Có lỗi kết nối server');
                btn.prop('disabled', false).html(originalText);
            }
        });
    });

    // =========================================================================
    // HELPERS
    // =========================================================================
    function showAlert(type, message) {
        $('#alertMessage')
            .removeClass('d-none alert-success alert-danger alert-warning alert-info')
            .addClass('alert-' + type);
        $('#alertText').text(message);
        $('html, body').animate({scrollTop: 0}, 300);
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

    // Initial load
    loadTransferInfo();
});
</script>
