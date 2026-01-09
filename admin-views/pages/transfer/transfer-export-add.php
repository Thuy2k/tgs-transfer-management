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
        <div class="row">
            <!-- Left Column: Thông tin chung -->
            <div class="col-12 col-lg-4 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bx bx-info-circle me-2"></i>Thông tin phiếu
                        </h5>
                    </div>
                    <div class="card-body">
                        <!-- Shop đích -->
                        <div class="mb-3">
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

                        <!-- Mã phiếu -->
                        <div class="mb-3">
                            <label class="form-label" for="ledgerCode">Mã phiếu</label>
                            <input type="text" class="form-control" id="ledgerCode" name="ledger_code"
                                   placeholder="Để trống để tự động sinh">
                            <small class="text-muted">Bỏ trống để hệ thống tự tạo mã</small>
                        </div>

                        <!-- Ghi chú -->
                        <div class="mb-3">
                            <label class="form-label" for="transferNote">Ghi chú</label>
                            <textarea class="form-control" id="transferNote" name="transfer_note" rows="3"
                                      placeholder="Nhập ghi chú cho phiếu xuất..."></textarea>
                        </div>

                        <!-- Thông tin tổng -->
                        <div class="border-top pt-3 mt-3">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Tổng sản phẩm:</span>
                                <span class="fw-bold" id="totalProducts">0</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Tổng số lượng:</span>
                                <span class="fw-bold" id="totalQuantity">0</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Tổng giá trị:</span>
                                <span class="fw-bold text-primary fs-5" id="totalValue">0 đ</span>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary w-100" id="btnSubmit">
                            <i class="bx bx-save me-1"></i> Tạo phiếu xuất
                        </button>
                    </div>
                </div>
            </div>

            <!-- Right Column: Danh sách sản phẩm -->
            <div class="col-12 col-lg-8 mb-4">
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
                                        <th style="width: 25%">Sản phẩm</th>
                                        <th style="width: 10%">Tracking</th>
                                        <th style="width: 10%">Tồn kho</th>
                                        <th style="width: 20%">Mã định danh / SL</th>
                                        <th style="width: 12%">Đơn giá</th>
                                        <th style="width: 12%">Thành tiền</th>
                                        <th style="width: 8%">Xóa</th>
                                    </tr>
                                </thead>
                                <tbody id="productsTableBody">
                                    <tr id="emptyRow">
                                        <td colspan="8" class="text-center py-4 text-muted">
                                            <i class="bx bx-package fs-1 d-block mb-2"></i>
                                            Chưa có sản phẩm. Nhấn "Thêm sản phẩm" để bắt đầu.
                                        </td>
                                    </tr>
                                </tbody>
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

        $('#emptyRow').remove();

        productRowIndex++;
        const rowId = 'product-row-' + productRowIndex;

        const inputHtml = isTracking
            ? `<textarea class="form-control form-control-sm lot-barcodes" rows="2"
                         placeholder="Nhập mã định danh (mỗi mã 1 dòng)"
                         data-product-id="${product.id}"></textarea>
               <small class="text-muted">SL: <span class="lot-count">0</span></small>`
            : `<input type="number" class="form-control form-control-sm quantity-input"
                      min="0" max="${stock}" step="0.001" value="1"
                      data-product-id="${product.id}">`;

        const row = `
            <tr id="${rowId}" data-product-id="${product.id}" data-is-tracking="${isTracking ? 1 : 0}">
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
                <td class="product-subtotal">0 đ</td>
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
        $(`#${rowId} .btn-remove-product`).on('click', handleRemoveProduct);
    }

    function handleLotBarcodesInput() {
        const $textarea = $(this);
        const productId = $textarea.data('product-id');
        const barcodes = $textarea.val().split('\n').filter(b => b.trim() !== '');
        const count = barcodes.length;

        $textarea.closest('tr').find('.lot-count').text(count);

        // Calculate subtotal
        const product = selectedProducts.find(p => p.id == productId);
        if (product) {
            const subtotal = count * parseFloat(product.price || 0);
            $textarea.closest('tr').find('.product-subtotal').text(formatCurrency(subtotal));
        }

        updateTotals();
    }

    function handleQuantityInput() {
        const $input = $(this);
        const productId = $input.data('product-id');
        const quantity = parseFloat($input.val()) || 0;

        const product = selectedProducts.find(p => p.id == productId);
        if (product) {
            const subtotal = quantity * parseFloat(product.price || 0);
            $input.closest('tr').find('.product-subtotal').text(formatCurrency(subtotal));
        }

        updateTotals();
    }

    function handleRemoveProduct() {
        const rowId = $(this).data('row-id');
        const productId = $(this).data('product-id');

        $(`#${rowId}`).remove();
        selectedProducts = selectedProducts.filter(p => p.id != productId);

        if (selectedProducts.length === 0) {
            $('#productsTableBody').html(`
                <tr id="emptyRow">
                    <td colspan="8" class="text-center py-4 text-muted">
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
        let totalValue = 0;

        $('#productsTableBody tr[data-product-id]').each(function() {
            const $row = $(this);
            const isTracking = $row.data('is-tracking') == 1;

            if (isTracking) {
                const barcodes = $row.find('.lot-barcodes').val().split('\n').filter(b => b.trim() !== '');
                totalQuantity += barcodes.length;
            } else {
                totalQuantity += parseFloat($row.find('.quantity-input').val()) || 0;
            }

            // Parse subtotal
            const subtotalText = $row.find('.product-subtotal').text();
            const subtotal = parseFloat(subtotalText.replace(/[^\d]/g, '')) || 0;
            totalValue += subtotal;
        });

        $('#totalProducts').text(totalProducts);
        $('#totalQuantity').text(formatNumber(totalQuantity));
        $('#totalValue').text(formatCurrency(totalValue));
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

            const item = {
                product_id: productId,
                is_tracking: isTracking
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
