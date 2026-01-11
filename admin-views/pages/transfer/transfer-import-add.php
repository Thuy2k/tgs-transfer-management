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

<!-- Modal kiểm thực tế và lưu kho -->
<div class="modal fade" id="lotSelectModal" tabindex="-1" aria-labelledby="lotSelectModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="lotSelectModalLabel">Kiểm thực tế và lưu kho</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Product info -->
                <div class="mb-3">
                    <strong id="modalProductName">—</strong>
                    <small class="text-muted ms-2">Barcode: <code id="modalProductBarcode">—</code></small>
                </div>

                <!-- Scan input -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="input-group">
                            <input type="text" class="form-control" id="scanLotInput"
                                   placeholder="Scan mã định danh..." autofocus>
                            <button class="btn btn-outline-secondary" type="button" id="btnClearScan">
                                <i class="bx bx-x"></i>
                            </button>
                        </div>
                        <div id="scanResult" class="mt-1 small"></div>
                    </div>
                    <div class="col-md-6 text-end">
                        <span class="text-muted">Tổng: <strong id="summaryTotal">0</strong></span>
                        <span class="text-success ms-3">Mới: <strong id="summaryNew">0</strong></span>
                        <span class="text-danger ms-3">Lỗi: <strong id="summaryDefect">0</strong></span>
                    </div>
                </div>

                <!-- Lot list table -->
                <div class="border rounded">
                    <table class="table table-hover table-sm mb-0" id="lotListTable" style="width: 100%;">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 50px;" class="text-center">#</th>
                                <th>Mã định danh</th>
                                <th style="width: 100px;">HSD</th>
                                <th style="width: 200px;">Tình trạng</th>
                                <th class="text-center" style="width: 80px;">Đã kiểm</th>
                            </tr>
                        </thead>
                        <tbody id="lotListContainer"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                <button type="button" class="btn btn-success" id="btnSaveLotConditions">Lưu</button>
            </div>
        </div>
    </div>
</div>

<style>
.lot-row.scanned { background-color: #d1e7dd !important; }
.lot-row.defect { background-color: #f8d7da !important; }
.scan-success { color: #198754; }
.scan-error { color: #dc3545; }
.scan-warning { color: #fd7e14; }
#lotListTable_wrapper .dataTables_filter { display: none; }
</style>

<!-- DataTables CSS và JS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

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
    // lotConditions: { lot_id: { condition: 0|1, scanned: true|false } }
    let lotConditions = {};
    // DataTable instance
    let lotDataTable = null;

    // LOAD DATA
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

                    // Initialize import data - LUÔN lấy full (không có nhập 1 phần)
                    productsData.forEach(function(item) {
                        const barcode = item.barcode_main || item.barcode;
                        const isTracking = item.is_tracking == 1;
                        const maxQty = parseInt(item.quantity) || 0;

                        if (isTracking) {
                            let lotsDetail = item.lots_detail || [];
                            // Luôn chọn tất cả lots
                            importData[barcode] = {
                                isTracking: true,
                                maxQuantity: maxQty,
                                allLots: lotsDetail,
                                selectedLots: lotsDetail.map(l => l.id),
                                quantity: lotsDetail.length
                            };
                            // Khởi tạo lotConditions với condition mặc định = 0 (Mới)
                            lotsDetail.forEach(lot => {
                                lotConditions[lot.id] = {
                                    condition: lot.condition || 0,
                                    scanned: false
                                };
                            });
                        } else {
                            // Không tracking - luôn full quantity
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

    // RENDER
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
                const totalCount = (data.allLots || []).length;
                lotColumn = `
                    <button type="button" class="btn btn-sm btn-outline-primary btn-select-lots"
                            data-barcode="${escapeHtml(barcode)}"
                            data-product-name="${escapeHtml(item.product_name)}">
                        <i class="bx bx-check-shield"></i> Kiểm ${totalCount} mã
                    </button>
                `;
            } else {
                lotColumn = '<span class="text-muted">—</span>';
            }

            // Cột số lượng nhập - LUÔN hiển thị readonly vì nhập hết
            let qtyColumn = `<span class="fw-semibold">${importQty}</span>`;

            // Trạng thái - luôn là "Nhập hết" vì không có nhập 1 phần
            const itemStatus = '<span class="badge bg-success">Nhập hết</span>';

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

        // Trạng thái tổng - luôn là "Nhập hết"
        $('#footStatus').html('<span class="badge bg-success">Nhập hết</span>');
        $('#btnCreateImport').prop('disabled', totalImport === 0);
    }

    // LOT MODAL
    $(document).on('click', '.btn-select-lots', function() {
        const barcode = $(this).data('barcode');
        const productName = $(this).data('product-name');
        const data = importData[barcode];

        if (!data) return;

        // Set modal state
        currentModalBarcode = barcode;
        currentModalLots = data.allLots || [];

        // Update modal UI
        $('#modalProductName').text(productName);
        $('#modalProductBarcode').text(barcode);
        $('#scanLotInput').val('');
        $('#scanResult').html('');

        renderLotList();
        updateModalSummary();

        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('lotSelectModal'));
        modal.show();

        // Focus scan input
        setTimeout(() => $('#scanLotInput').focus(), 500);
    });

    function renderLotList() {
        if (lotDataTable) {
            lotDataTable.destroy();
            lotDataTable = null;
        }

        const tableData = currentModalLots.map((lot, index) => {
            const lotId = lot.id;
            const lotBarcode = lot.barcode || lotId;
            const condData = lotConditions[lotId] || { condition: 0, scanned: false };

            return {
                index: index + 1,
                lotId: lotId,
                lotBarcode: lotBarcode,
                barcodeHtml: `<code>${escapeHtml(lotBarcode)}</code>`,
                expDate: lot.exp_date ? formatExpDate(lot.exp_date) : '—',
                conditionHtml: `<select class="form-select form-select-sm" data-lot-id="${lotId}">
                    <option value="0" ${condData.condition === 0 ? 'selected' : ''}>Mới</option>
                    <option value="1" ${condData.condition === 1 ? 'selected' : ''}>Lỗi</option>
                </select>`,
                scannedHtml: condData.scanned ? '<span class="text-success">OK</span>' : '—',
                condition: condData.condition,
                isScanned: condData.scanned
            };
        });

        lotDataTable = $('#lotListTable').DataTable({
            data: tableData,
            columns: [
                { data: 'index', className: 'text-center' },
                { data: 'barcodeHtml' },
                { data: 'expDate' },
                { data: 'conditionHtml' },
                { data: 'scannedHtml', className: 'text-center' }
            ],
            pageLength: 25,
            lengthMenu: [[25, 50, -1], [25, 50, 'Tất cả']],
            language: {
                lengthMenu: 'Hiện _MENU_',
                info: '_START_-_END_ / _TOTAL_',
                infoEmpty: 'Trống',
                zeroRecords: 'Không tìm thấy',
                paginate: { next: '>', previous: '<' }
            },
            createdRow: function(row, data) {
                $(row).attr('data-lot-id', data.lotId).attr('data-lot-barcode', data.lotBarcode);
                if (data.condition === 1) $(row).addClass('lot-row defect');
                else if (data.isScanned) $(row).addClass('lot-row scanned');
            }
        });
    }

    function formatExpDate(dateStr) {
        if (!dateStr) return '';
        const date = new Date(dateStr);
        if (isNaN(date.getTime())) return dateStr;
        return date.toLocaleDateString('vi-VN');
    }

    function updateModalSummary() {
        const total = currentModalLots.length;
        let newCount = 0;
        let defectCount = 0;

        currentModalLots.forEach(lot => {
            const condData = lotConditions[lot.id] || { condition: 0 };
            if (condData.condition === 1) {
                defectCount++;
            } else {
                newCount++;
            }
        });

        $('#summaryTotal').text(total);
        $('#summaryNew').text(newCount);
        $('#summaryDefect').text(defectCount);
    }

    // Condition select change
    $('#lotListTable').on('change', 'select[data-lot-id]', function() {
        const lotId = parseInt($(this).data('lot-id'));
        const condition = parseInt($(this).val());
        const $row = $(this).closest('tr');

        // Update lotConditions
        if (!lotConditions[lotId]) {
            lotConditions[lotId] = { condition: 0, scanned: false };
        }
        lotConditions[lotId].condition = condition;

        // Update row class
        $row.removeClass('scanned defect');
        if (condition === 1) {
            $row.addClass('defect');
        } else if (lotConditions[lotId].scanned) {
            $row.addClass('scanned');
        }

        updateModalSummary();
    });

    // Scan
    $('#scanLotInput').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            processScan();
        }
    });

    $('#btnClearScan').on('click', function() {
        $('#scanLotInput').val('').focus();
        $('#scanResult').html('');
        if (lotDataTable) lotDataTable.search('').draw();
    });

    function processScan() {
        const scannedValue = $('#scanLotInput').val().trim();
        if (!scannedValue) return;

        if (lotDataTable) lotDataTable.search(scannedValue).draw();

        const matchedLot = currentModalLots.find(lot => lot.barcode === scannedValue);

        if (matchedLot) {
            const lotId = matchedLot.id;
            if (!lotConditions[lotId]) lotConditions[lotId] = { condition: 0, scanned: false };

            if (lotConditions[lotId].scanned) {
                $('#scanResult').html('<span class="scan-warning">Đã kiểm: ' + escapeHtml(matchedLot.barcode) + '</span>');
            } else {
                lotConditions[lotId].scanned = true;
                const $row = $(`tr[data-lot-id="${lotId}"]`);
                if ($row.length) {
                    $row.addClass('lot-row scanned');
                    $row.find('td:last').html('<span class="text-success">OK</span>');
                }
                updateModalSummary();
                $('#scanResult').html('<span class="scan-success">OK: ' + escapeHtml(matchedLot.barcode) + '</span>');
            }
        } else {
            $('#scanResult').html('<span class="scan-error">Không khớp: ' + escapeHtml(scannedValue) + '</span>');
        }

        $('#scanLotInput').val('').focus();
    }

    // Save lot conditions
    $('#btnSaveLotConditions').on('click', function() {
        const btn = $(this);
        const lotsToUpdate = currentModalLots.map(lot => ({
            lot_id: lot.id,
            condition: (lotConditions[lot.id] || {}).condition || 0
        }));

        if (lotsToUpdate.length === 0) return;

        btn.prop('disabled', true).text('Đang lưu...');

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'tgs_transfer_update_lot_conditions',
                nonce: nonce,
                lots: JSON.stringify(lotsToUpdate)
            },
            success: function(response) {
                btn.prop('disabled', false).text('Lưu');
                if (response.success) {
                    $('#scanResult').html('<span class="scan-success">Đã lưu!</span>');
                    $(`.btn-select-lots[data-barcode="${currentModalBarcode}"]`)
                        .removeClass('btn-outline-primary').addClass('btn-success').text('Đã kiểm');
                } else {
                    $('#scanResult').html('<span class="scan-error">Lỗi: ' + (response.data?.message || 'Không thể lưu') + '</span>');
                }
            },
            error: function() {
                btn.prop('disabled', false).text('Lưu');
                $('#scanResult').html('<span class="scan-error">Lỗi kết nối</span>');
            }
        });
    });

    // CREATE IMPORT
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

    // HELPERS
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
