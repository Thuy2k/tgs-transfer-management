<?php
/**
 * Transfer Export Add - Tạo phiếu xuất hàng đến shop con
 *
 * @package tgs_shop_management
 */

if (!defined('ABSPATH')) {
    exit;
}

$ajax_url = admin_url('admin-ajax.php');
$nonce = wp_create_nonce('tgs_transfer_nonce');
$current_blog_id = get_current_blog_id();

// Lấy thông tin người dùng hiện tại
$current_user = wp_get_current_user();
$current_user_email = $current_user->user_email;

// Sinh mã phiếu tự động (giống backend)
$auto_ledger_code = 'TXS-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -4));

// Lấy thời gian hiện tại
$current_time = current_time('d/m/Y H:i:s');

// Lấy danh sách các shop con trong multisite
$sites = [];
if (is_multisite()) {
    $all_sites = get_sites(['number' => 1000]);
    foreach ($all_sites as $site) {
        if ($site->blog_id != $current_blog_id) {
            $sites[] = [
                'blog_id' => $site->blog_id,
                'name' => get_blog_details($site->blog_id)->blogname,
                'domain' => $site->domain,
                'path' => $site->path
            ];
        }
    }
}
?>

<div class="app-transfer-export">
    <!-- Breadcrumb & Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div class="d-flex flex-column justify-content-center">
            <h4 class="mb-1">Xuất hàng đến Shop con</h4>
            <p class="text-muted mb-0">
                <a href="<?php echo admin_url('admin.php?page=tgs-shop-management'); ?>">Dashboard</a>
                <span class="mx-1">/</span>
                <a href="<?php echo admin_url('admin.php?page=tgs-shop-management&view=ticket-transfer-exports'); ?>">Phiếu xuất đến shop</a>
                <span class="mx-1">/</span>
                <span>Tạo mới</span>
            </p>
        </div>
        <div>
            <a href="<?php echo admin_url('admin.php?page=tgs-shop-management&view=ticket-transfer-exports'); ?>" class="btn btn-outline-secondary">
                <i class="bx bx-arrow-back me-1"></i> Quay lại
            </a>
        </div>
    </div>

    <!-- Alert Message -->
    <div id="alertMessage" class="alert alert-dismissible mb-4 d-none" role="alert">
        <span id="alertText"></span>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>

    <form id="transferExportForm">
        <!-- Row 1: Thông tin phiếu -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bx bx-info-circle me-2"></i>Thông tin phiếu
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- Mã phiếu - tự động sinh -->
                            <div class="col-12 col-md-6 col-lg-3 mb-3">
                                <label class="form-label" for="ledgerCode">Mã phiếu</label>
                                <input type="text" class="form-control" id="ledgerCode" name="ledger_code"
                                       value="<?php echo esc_attr($auto_ledger_code); ?>" readonly
                                       style="background-color: #e9ecef;">
                                <small class="text-muted">Mã tự động sinh bởi hệ thống</small>
                            </div>

                            <!-- Shop nhận -->
                            <div class="col-12 col-md-6 col-lg-3 mb-3">
                                <label class="form-label" for="destinationBlogId">
                                    Shop nhận <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" id="destinationBlogId" name="destination_blog_id" required>
                                    <option value="">-- Chọn shop nhận --</option>
                                    <?php foreach ($sites as $site): ?>
                                        <option value="<?php echo esc_attr($site['blog_id']); ?>">
                                            <?php echo esc_html($site['name']); ?> (ID: <?php echo esc_html($site['blog_id']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Chọn shop con sẽ nhận hàng</small>
                            </div>

                            <!-- Nhân viên thực hiện -->
                            <div class="col-12 col-md-6 col-lg-3 mb-3">
                                <label class="form-label" for="employeeEmail">Nhân viên thực hiện</label>
                                <input type="text" class="form-control" id="employeeEmail"
                                       value="<?php echo esc_attr($current_user_email); ?>" readonly
                                       style="background-color: #e9ecef;">
                                <small class="text-muted">Email người tạo phiếu</small>
                            </div>

                            <!-- Thời gian -->
                            <div class="col-12 col-md-6 col-lg-3 mb-3">
                                <label class="form-label" for="createdTime">Thời gian tạo</label>
                                <input type="text" class="form-control" id="createdTime"
                                       value="<?php echo esc_attr($current_time); ?>" readonly
                                       style="background-color: #e9ecef;">
                                <small class="text-muted">Thời gian tạo phiếu</small>
                            </div>

                            <!-- Ghi chú phiếu -->
                            <div class="col-12 mb-3">
                                <label class="form-label" for="transferNote">Ghi chú phiếu</label>
                                <textarea class="form-control" id="transferNote" name="transfer_note" rows="2"
                                          placeholder="Nhập ghi chú cho phiếu xuất..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Row 2: Danh sách sản phẩm -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="card-title mb-0">
                            <i class="bx bx-package me-2"></i>Sản phẩm xuất
                        </h5>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddProduct">
                            <i class="bx bx-plus me-1"></i> Thêm sản phẩm
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="productsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 3%">#</th>
                                        <th style="width: 18%">Sản phẩm</th>
                                        <th style="width: 6%">Tracking</th>
                                        <th style="width: 6%">Tồn kho</th>
                                        <th style="width: 14%">Mã định danh / SL</th>
                                        <th style="width: 9%">Đơn giá</th>
                                        <th style="width: 9%">TT không VAT</th>
                                        <th style="width: 5%">CK(%)</th>
                                        <th style="width: 5%">Thuế %</th>
                                        <th style="width: 8%">Thuế VNĐ</th>
                                        <th style="width: 9%">Thành tiền</th>
                                        <th style="width: 10%">Ghi chú SP</th>
                                        <th style="width: 4%">Xóa</th>
                                    </tr>
                                </thead>
                                <tbody id="productsTableBody">
                                    <tr id="emptyRow">
                                        <td colspan="13" class="text-center py-4 text-muted">
                                            <i class="bx bx-package fs-1 d-block mb-2"></i>
                                            Chưa có sản phẩm. Nhấn "Thêm sản phẩm" để bắt đầu.
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <td colspan="5" class="text-end fw-bold">Tổng cộng:</td>
                                        <td id="footerTotalPrice" class="fw-bold">0 đ</td>
                                        <td id="footerSubtotal" class="fw-bold">0 đ</td>
                                        <td></td>
                                        <td></td>
                                        <td id="footerTotalTax" class="fw-bold text-danger">0 đ</td>
                                        <td id="footerGrandTotal" class="fw-bold text-primary fs-5">0 đ</td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Hướng dẫn -->
                <div class="alert alert-info mt-3">
                    <h6 class="alert-heading"><i class="bx bx-info-circle me-1"></i> Hướng dẫn</h6>
                    <ul class="mb-0 ps-3">
                        <li><strong>Sản phẩm có tracking HSD:</strong> Scan hoặc nhập mã định danh (mỗi mã 1 dòng)</li>
                        <li><strong>Sản phẩm không tracking:</strong> Nhập số lượng cần xuất</li>
                        <li>Sản phẩm chưa có ở shop nhận sẽ được <strong>đồng bộ tự động</strong></li>
                        <li>Phiếu xuất cần được <strong>duyệt</strong> trước khi shop con có thể nhận hàng</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Row 3: Tổng kết và nút Lưu (sticky bottom) -->
        <div class="row">
            <div class="col-12">
                <div class="card sticky-bottom" style="bottom: 0; z-index: 100;">
                    <div class="card-body py-3">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                            <div class="d-flex gap-4 flex-wrap">
                                <div>
                                    <span class="text-muted">Tổng sản phẩm:</span>
                                    <span class="fw-bold ms-1" id="totalProducts">0</span>
                                </div>
                                <div>
                                    <span class="text-muted">Tổng số lượng:</span>
                                    <span class="fw-bold ms-1" id="totalQuantity">0</span>
                                </div>
                                <div>
                                    <span class="text-muted">Tổng giá trị:</span>
                                    <span class="fw-bold text-primary fs-5 ms-1" id="totalValue">0 đ</span>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary btn-lg" id="btnSubmit">
                                <i class="bx bx-save me-1"></i> Tạo phiếu xuất
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Modal chọn sản phẩm -->
<div class="modal fade" id="productSelectModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Chọn sản phẩm để xuất</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Search -->
                <div class="mb-3">
                    <input type="text" class="form-control" id="productSearchInput"
                           placeholder="Tìm theo tên, mã sản phẩm, barcode...">
                </div>

                <!-- Product list -->
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-hover table-sm" id="productSelectTable">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th style="width: 5%"></th>
                                <th style="width: 30%">Tên sản phẩm</th>
                                <th style="width: 15%">Barcode</th>
                                <th style="width: 15%">Tracking</th>
                                <th style="width: 15%">Tồn kho</th>
                                <th style="width: 10%">Đơn giá</th>
                            </tr>
                        </thead>
                        <tbody id="productSelectTableBody">
                            <tr>
                                <td colspan="6" class="text-center py-3 text-muted">
                                    Đang tải danh sách sản phẩm...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                <button type="button" class="btn btn-primary" id="btnConfirmSelectProducts">
                    Thêm sản phẩm đã chọn (<span id="selectedCount">0</span>)
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal kiểm tra đồng bộ sản phẩm -->
<div class="modal fade" id="syncCheckModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Kiểm tra đồng bộ sản phẩm</h5>
            </div>
            <div class="modal-body text-center">
                <div class="spinner-border text-primary mb-3" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p id="syncCheckMessage">Đang kiểm tra sản phẩm tại shop đích...</p>
                <div id="syncProgress" class="progress d-none">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function($) {
    'use strict';

    const CONFIG = {
        ajaxUrl: '<?php echo esc_js($ajax_url); ?>',
        nonce: '<?php echo esc_js($nonce); ?>',
        currentBlogId: <?php echo intval($current_blog_id); ?>
    };

    let selectedProducts = [];
    let productRowIndex = 0;
    let allProducts = [];

    // Bootstrap 5 Modal instances
    let productSelectModal = null;
    let syncCheckModal = null;

    $(document).ready(function() {
        // Initialize Bootstrap 5 modals
        const productSelectModalEl = document.getElementById('productSelectModal');
        const syncCheckModalEl = document.getElementById('syncCheckModal');

        if (productSelectModalEl && typeof bootstrap !== 'undefined') {
            productSelectModal = new bootstrap.Modal(productSelectModalEl);
        }
        if (syncCheckModalEl && typeof bootstrap !== 'undefined') {
            syncCheckModal = new bootstrap.Modal(syncCheckModalEl);
        }

        initEventHandlers();
        loadProducts();
    });

    function initEventHandlers() {
        // Open product select modal
        $('#btnAddProduct').on('click', function() {
            if (productSelectModal) {
                productSelectModal.show();
            }
        });

        // Search products
        $('#productSearchInput').on('input', debounce(filterProducts, 300));

        // Confirm product selection
        $('#btnConfirmSelectProducts').on('click', confirmProductSelection);

        // Form submit
        $('#transferExportForm').on('submit', handleFormSubmit);

        // Destination shop change - check sync status
        $('#destinationBlogId').on('change', function() {
            if (selectedProducts.length > 0 && $(this).val()) {
                checkProductSync();
            }
        });
    }

    function loadProducts() {
        $.ajax({
            url: CONFIG.ajaxUrl,
            type: 'POST',
            data: {
                action: 'tgs_transfer_get_products',
                nonce: CONFIG.nonce
            },
            success: function(response) {
                if (response.success) {
                    allProducts = response.data.products;
                    renderProductSelectTable(allProducts);
                } else {
                    showAlert('danger', response.data.message || 'Lỗi tải danh sách sản phẩm');
                }
            },
            error: function() {
                showAlert('danger', 'Lỗi kết nối server');
            }
        });
    }

    function renderProductSelectTable(products) {
        const tbody = $('#productSelectTableBody');
        tbody.empty();

        if (products.length === 0) {
            tbody.html('<tr><td colspan="6" class="text-center py-3 text-muted">Không tìm thấy sản phẩm</td></tr>');
            return;
        }

        products.forEach(function(product) {
            const isTracking = parseInt(product.is_tracking) === 1;
            const stock = isTracking ? parseInt(product.tracking_stock || 0) : parseFloat(product.no_tracking_stock || 0);
            const stockClass = stock > 0 ? 'text-success' : 'text-danger';
            const alreadySelected = selectedProducts.some(p => p.id == product.id);

            tbody.append(`
                <tr data-product-id="${product.id}" class="${alreadySelected ? 'table-secondary' : ''}">
                    <td class="text-center">
                        <input type="checkbox" class="form-check-input product-checkbox"
                               data-product='${JSON.stringify(product).replace(/'/g, "&#39;")}'
                               ${alreadySelected ? 'disabled checked' : ''}
                               ${stock <= 0 ? 'disabled' : ''}>
                    </td>
                    <td>
                        <strong>${escapeHtml(product.name)}</strong>
                        ${product.sku ? '<br><small class="text-muted">SKU: ' + escapeHtml(product.sku) + '</small>' : ''}
                    </td>
                    <td>${escapeHtml(product.barcode || '-')}</td>
                    <td>
                        ${isTracking
                            ? '<span class="badge bg-info">Có tracking</span>'
                            : '<span class="badge bg-secondary">Không tracking</span>'}
                    </td>
                    <td class="${stockClass}">${formatNumber(stock)}</td>
                    <td>${formatCurrency(product.price)}</td>
                </tr>
            `);
        });

        // Update checkbox handlers
        $('.product-checkbox').off('change').on('change', updateSelectedCount);
    }

    function filterProducts() {
        const keyword = $('#productSearchInput').val().toLowerCase().trim();

        if (!keyword) {
            renderProductSelectTable(allProducts);
            return;
        }

        const filtered = allProducts.filter(function(p) {
            return (p.name && p.name.toLowerCase().includes(keyword)) ||
                   (p.barcode && p.barcode.toLowerCase().includes(keyword)) ||
                   (p.sku && p.sku.toLowerCase().includes(keyword));
        });

        renderProductSelectTable(filtered);
    }

    function updateSelectedCount() {
        const count = $('.product-checkbox:checked:not(:disabled)').length;
        $('#selectedCount').text(count);
    }

    function confirmProductSelection() {
        const newProducts = [];

        $('.product-checkbox:checked:not(:disabled)').each(function() {
            const product = $(this).data('product');
            if (product && !selectedProducts.some(p => p.id == product.id)) {
                newProducts.push(product);
            }
        });

        if (newProducts.length === 0) {
            showAlert('warning', 'Vui lòng chọn ít nhất 1 sản phẩm mới');
            return;
        }

        // Add to selected products
        newProducts.forEach(function(product) {
            selectedProducts.push(product);
            addProductRow(product);
        });

        // Close modal and refresh
        if (productSelectModal) {
            productSelectModal.hide();
        }
        updateTotals();

        // Check sync if destination is selected
        if ($('#destinationBlogId').val()) {
            checkProductSync();
        }
    }

    function addProductRow(product) {
        const isTracking = parseInt(product.is_tracking) === 1;
        const stock = isTracking ? parseInt(product.tracking_stock || 0) : parseFloat(product.no_tracking_stock || 0);
        const taxPercent = parseFloat(product.tax_percent) || 0;

        $('#emptyRow').remove();

        productRowIndex++;
        const rowId = 'product-row-' + productRowIndex;

        const inputHtml = isTracking
            ? `<textarea class="form-control form-control-sm lot-barcodes" rows="2"
                         placeholder="Nhập mã định danh (mỗi mã 1 dòng)"
                         data-product-id="${product.id}"></textarea>
               <div class="mt-1"><strong style="font-size: 1.1em;">Số lượng: <span class="lot-count text-primary">0</span></strong></div>`
            : `<input type="number" class="form-control form-control-sm quantity-input"
                      min="1" max="${stock}" step="1" value="1"
                      data-product-id="${product.id}">`;

        const row = `
            <tr id="${rowId}" data-product-id="${product.id}" data-is-tracking="${isTracking ? 1 : 0}"
                data-price="${product.price || 0}" data-tax-percent="${taxPercent}">
                <td class="text-center">${productRowIndex}</td>
                <td>
                    <strong>${escapeHtml(product.name)}</strong>
                    ${product.barcode ? '<br><small class="text-muted">' + escapeHtml(product.barcode) + '</small>' : ''}
                    <span class="sync-status ms-1"></span>
                </td>
                <td>
                    ${isTracking
                        ? '<span class="badge bg-info">Có</span>'
                        : '<span class="badge bg-secondary">Không</span>'}
                </td>
                <td class="${stock > 0 ? 'text-success' : 'text-danger'}">${formatNumber(stock)}</td>
                <td>${inputHtml}</td>
                <td class="product-price">${formatCurrency(product.price)}</td>
                <td class="product-subtotal-no-vat">0 đ</td>
                <td>
                    <input type="number" class="form-control form-control-sm discount-input"
                           min="0" max="100" step="0.01" value="0" style="width: 60px;">
                </td>
                <td class="product-tax-percent">${taxPercent}%</td>
                <td class="product-tax-amount text-danger">0 đ</td>
                <td class="product-subtotal fw-bold">0 đ</td>
                <td>
                    <input type="text" class="form-control form-control-sm item-note"
                           placeholder="Ghi chú..." style="width: 100%;">
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger btn-remove-product"
                            data-row-id="${rowId}" data-product-id="${product.id}">
                        <i class="bx bx-trash"></i>
                    </button>
                </td>
            </tr>
        `;

        $('#productsTableBody').append(row);

        // Bind events
        $(`#${rowId} .lot-barcodes`).on('input', handleLotBarcodesInput);
        $(`#${rowId} .quantity-input`).on('input', handleQuantityInput);
        $(`#${rowId} .discount-input`).on('input', handleDiscountInput);
        $(`#${rowId} .btn-remove-product`).on('click', handleRemoveProduct);

        // Tính toán ban đầu cho sản phẩm không tracking (quantity = 1)
        if (!isTracking) {
            calculateRowTotals($(`#${rowId}`));
        }
    }

    function handleLotBarcodesInput() {
        const $textarea = $(this);
        const barcodes = $textarea.val().split('\n').filter(b => b.trim() !== '');
        const count = barcodes.length;

        $textarea.closest('tr').find('.lot-count').text(count);

        // Calculate row totals
        calculateRowTotals($textarea.closest('tr'));
        updateTotals();
    }

    function handleQuantityInput() {
        const $input = $(this);
        calculateRowTotals($input.closest('tr'));
        updateTotals();
    }

    function handleDiscountInput() {
        const $input = $(this);
        calculateRowTotals($input.closest('tr'));
        updateTotals();
    }

    function calculateRowTotals($row) {
        const isTracking = $row.data('is-tracking') == 1;
        const price = parseFloat($row.data('price')) || 0;
        const taxPercent = parseFloat($row.data('tax-percent')) || 0;
        const discountPercent = parseFloat($row.find('.discount-input').val()) || 0;

        let quantity = 0;
        if (isTracking) {
            const barcodes = $row.find('.lot-barcodes').val().split('\n').filter(b => b.trim() !== '');
            quantity = barcodes.length;
        } else {
            quantity = parseFloat($row.find('.quantity-input').val()) || 0;
        }

        // Tính toán
        const subtotalNoVat = quantity * price;
        const discountAmount = subtotalNoVat * (discountPercent / 100);
        const afterDiscount = subtotalNoVat - discountAmount;
        const taxAmount = afterDiscount * (taxPercent / 100);
        const grandTotal = afterDiscount + taxAmount;

        // Lưu giá trị số vào data attributes để tính tổng chính xác
        $row.data('calc-subtotal-no-vat', subtotalNoVat);
        $row.data('calc-tax-amount', taxAmount);
        $row.data('calc-grand-total', grandTotal);

        // Cập nhật UI
        $row.find('.product-subtotal-no-vat').text(formatCurrency(subtotalNoVat));
        $row.find('.product-tax-amount').text(formatCurrency(taxAmount));
        $row.find('.product-subtotal').text(formatCurrency(grandTotal));
    }

    function handleRemoveProduct() {
        const rowId = $(this).data('row-id');
        const productId = $(this).data('product-id');

        $(`#${rowId}`).remove();
        selectedProducts = selectedProducts.filter(p => p.id != productId);

        if (selectedProducts.length === 0) {
            $('#productsTableBody').html(`
                <tr id="emptyRow">
                    <td colspan="13" class="text-center py-4 text-muted">
                        <i class="bx bx-package fs-1 d-block mb-2"></i>
                        Chưa có sản phẩm. Nhấn "Thêm sản phẩm" để bắt đầu.
                    </td>
                </tr>
            `);
        }

        updateTotals();
        renderProductSelectTable(allProducts); // Refresh checkboxes
    }

    function updateTotals() {
        let totalProducts = selectedProducts.length;
        let totalQuantity = 0;
        let totalPrice = 0;
        let totalSubtotalNoVat = 0;
        let totalTax = 0;
        let totalValue = 0;

        $('#productsTableBody tr[data-product-id]').each(function() {
            const $row = $(this);
            const isTracking = $row.data('is-tracking') == 1;
            const price = parseFloat($row.data('price')) || 0;

            let quantity = 0;
            if (isTracking) {
                const barcodes = $row.find('.lot-barcodes').val().split('\n').filter(b => b.trim() !== '');
                quantity = barcodes.length;
            } else {
                quantity = parseFloat($row.find('.quantity-input').val()) || 0;
            }

            totalQuantity += quantity;
            totalPrice += quantity * price;

            // Đọc giá trị từ data attributes (đã được tính trong calculateRowTotals)
            totalSubtotalNoVat += parseFloat($row.data('calc-subtotal-no-vat')) || 0;
            totalTax += parseFloat($row.data('calc-tax-amount')) || 0;
            totalValue += parseFloat($row.data('calc-grand-total')) || 0;
        });

        $('#totalProducts').text(totalProducts);
        $('#totalQuantity').text(formatNumber(totalQuantity));
        $('#totalValue').text(formatCurrency(totalValue));

        // Footer totals
        $('#footerTotalPrice').text(formatCurrency(totalPrice));
        $('#footerSubtotal').text(formatCurrency(totalSubtotalNoVat));
        $('#footerTotalTax').text(formatCurrency(totalTax));
        $('#footerGrandTotal').text(formatCurrency(totalValue));
    }

    function checkProductSync() {
        const destinationBlogId = $('#destinationBlogId').val();
        if (!destinationBlogId || selectedProducts.length === 0) return;

        if (syncCheckModal) {
            syncCheckModal.show();
        }
        $('#syncCheckMessage').text('Đang kiểm tra sản phẩm tại shop đích...');
        $('#syncProgress').addClass('d-none');

        const productIds = selectedProducts.map(p => p.id);

        $.ajax({
            url: CONFIG.ajaxUrl,
            type: 'POST',
            data: {
                action: 'tgs_transfer_check_products_sync',
                nonce: CONFIG.nonce,
                destination_blog_id: destinationBlogId,
                product_ids: productIds
            },
            success: function(response) {
                if (response.success) {
                    handleSyncCheckResult(response.data);
                } else {
                    if (syncCheckModal) {
                        syncCheckModal.hide();
                    }
                    showAlert('danger', response.data.message || 'Lỗi kiểm tra đồng bộ');
                }
            },
            error: function() {
                if (syncCheckModal) {
                    syncCheckModal.hide();
                }
                showAlert('danger', 'Lỗi kết nối server');
            }
        });
    }

    function handleSyncCheckResult(data) {
        const needSync = data.need_sync || [];
        const synced = data.synced || [];

        // Update UI for each product
        synced.forEach(function(productId) {
            $(`tr[data-product-id="${productId}"] .sync-status`)
                .html('<i class="bx bx-check-circle text-success" title="Đã có tại shop đích"></i>');
        });

        if (needSync.length === 0) {
            if (syncCheckModal) {
                syncCheckModal.hide();
            }
            showAlert('success', 'Tất cả sản phẩm đã có tại shop đích');
            return;
        }

        // Need to sync some products
        needSync.forEach(function(productId) {
            $(`tr[data-product-id="${productId}"] .sync-status`)
                .html('<i class="bx bx-sync text-warning" title="Cần đồng bộ"></i>');
        });

        $('#syncCheckMessage').html(`
            <div class="alert alert-warning mb-0">
                <strong>${needSync.length} sản phẩm</strong> chưa có tại shop đích.<br>
                Hệ thống sẽ tự động đồng bộ khi tạo phiếu.
            </div>
        `);

        setTimeout(function() {
            if (syncCheckModal) {
                syncCheckModal.hide();
            }
        }, 2000);
    }

    function handleFormSubmit(e) {
        e.preventDefault();

        const destinationBlogId = $('#destinationBlogId').val();
        if (!destinationBlogId) {
            showAlert('danger', 'Vui lòng chọn shop nhận');
            return;
        }

        if (selectedProducts.length === 0) {
            showAlert('danger', 'Vui lòng thêm ít nhất 1 sản phẩm');
            return;
        }

        // Collect product data
        const items = [];
        let hasError = false;

        $('#productsTableBody tr[data-product-id]').each(function() {
            const $row = $(this);
            const productId = $row.data('product-id');
            const isTracking = $row.data('is-tracking') == 1;
            const price = parseFloat($row.data('price')) || 0;
            const taxPercent = parseFloat($row.data('tax-percent')) || 0;
            const discountPercent = parseFloat($row.find('.discount-input').val()) || 0;
            const itemNote = $row.find('.item-note').val() || '';

            const item = {
                product_id: productId,
                is_tracking: isTracking,
                price: price,
                tax_percent: taxPercent,
                discount_percent: discountPercent,
                item_note: itemNote
            };

            if (isTracking) {
                const barcodes = $row.find('.lot-barcodes').val().split('\n')
                    .map(b => b.trim())
                    .filter(b => b !== '');

                if (barcodes.length === 0) {
                    showAlert('danger', 'Vui lòng nhập mã định danh cho sản phẩm tracking');
                    hasError = true;
                    return false;
                }
                item.lot_barcodes = barcodes;
                item.quantity = barcodes.length;
            } else {
                const quantity = parseFloat($row.find('.quantity-input').val()) || 0;
                if (quantity <= 0) {
                    showAlert('danger', 'Vui lòng nhập số lượng hợp lệ');
                    hasError = true;
                    return false;
                }
                item.quantity = quantity;
            }

            // Tính toán các giá trị
            const subtotalNoVat = item.quantity * price;
            const discountAmount = subtotalNoVat * (discountPercent / 100);
            const afterDiscount = subtotalNoVat - discountAmount;
            const taxAmount = afterDiscount * (taxPercent / 100);
            const grandTotal = afterDiscount + taxAmount;

            item.subtotal_no_vat = subtotalNoVat;
            item.discount_amount = discountAmount;
            item.tax_amount = taxAmount;
            item.subtotal = grandTotal;

            items.push(item);
        });

        if (hasError) return;

        // Submit
        $('#btnSubmit').prop('disabled', true).html('<i class="spinner-border spinner-border-sm me-1"></i> Đang xử lý...');

        $.ajax({
            url: CONFIG.ajaxUrl,
            type: 'POST',
            data: {
                action: 'tgs_transfer_create_export',
                nonce: CONFIG.nonce,
                destination_blog_id: destinationBlogId,
                ledger_code: $('#ledgerCode').val(),
                transfer_note: $('#transferNote').val(),
                items: JSON.stringify(items)
            },
            success: function(response) {
                if (response.success) {
                    showAlert('success', 'Tạo phiếu xuất thành công! Đang chuyển hướng...');
                    setTimeout(function() {
                        window.location.href = response.data.redirect_url ||
                            '<?php echo admin_url("admin.php?page=tgs-shop-management&view=ticket-transfer-exports"); ?>';
                    }, 1500);
                } else {
                    showAlert('danger', response.data.message || 'Lỗi tạo phiếu xuất');
                    $('#btnSubmit').prop('disabled', false).html('<i class="bx bx-save me-1"></i> Tạo phiếu xuất');
                }
            },
            error: function() {
                showAlert('danger', 'Lỗi kết nối server');
                $('#btnSubmit').prop('disabled', false).html('<i class="bx bx-save me-1"></i> Tạo phiếu xuất');
            }
        });
    }

    // Helper functions
    function showAlert(type, message) {
        $('#alertMessage')
            .removeClass('d-none alert-success alert-danger alert-warning alert-info')
            .addClass('alert-' + type);
        $('#alertText').html(message);

        $('html, body').animate({ scrollTop: 0 }, 300);
    }

    function formatCurrency(value) {
        return new Intl.NumberFormat('vi-VN').format(value || 0) + ' đ';
    }

    function formatNumber(value) {
        return new Intl.NumberFormat('vi-VN').format(value || 0);
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function debounce(func, wait) {
        let timeout;
        return function() {
            const context = this, args = arguments;
            clearTimeout(timeout);
            timeout = setTimeout(function() {
                func.apply(context, args);
            }, wait);
        };
    }

})(jQuery);
</script>
