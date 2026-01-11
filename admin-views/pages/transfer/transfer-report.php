<?php
/**
 * Báo cáo & Thống kê mua bán nội bộ
 *
 * Trang dashboard hiển thị tổng quan luân chuyển hàng giữa các shop
 *
 * @package tgs_shop_management
 */

if (!defined('ABSPATH')) {
    exit;
}

$ajax_url = admin_url('admin-ajax.php');
$nonce = wp_create_nonce('tgs_transfer_nonce');
$current_blog_id = get_current_blog_id();
$current_shop_name = get_bloginfo('name');
?>

<div class="app-transfer-report">
    <!-- Breadcrumb & Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div class="d-flex flex-column justify-content-center">
            <h4 class="mb-1">
                <i class="bx bx-bar-chart-alt-2 text-primary"></i>
                Báo cáo & Thống kê mua bán nội bộ
            </h4>
            <p class="text-muted mb-0">
                <a href="<?php echo admin_url('admin.php?page=tgs-shop-management'); ?>">Dashboard</a>
                <span class="mx-1">/</span>
                <span>Báo cáo mua bán nội bộ</span>
            </p>
        </div>
        <div class="d-flex gap-2">
            <select class="form-select" id="filterPeriod" style="width: auto;">
                <option value="all">Tất cả thời gian</option>
                <option value="today">Hôm nay</option>
                <option value="week">7 ngày qua</option>
                <option value="month" selected>30 ngày qua</option>
                <option value="quarter">90 ngày qua</option>
                <option value="year">1 năm qua</option>
            </select>
            <button type="button" class="btn btn-outline-secondary" id="btnRefresh">
                <i class="bx bx-refresh"></i> Làm mới
            </button>
        </div>
    </div>

    <!-- Loading -->
    <div id="loadingSpinner" class="text-center py-5">
        <div class="spinner-border text-secondary" role="status">
            <span class="visually-hidden">Đang tải...</span>
        </div>
        <p class="mt-3 text-muted">Đang tải dữ liệu thống kê...</p>
    </div>

    <!-- Report Content -->
    <div id="reportContent" style="display: none;">
        <!-- Current Shop Info -->
        <div class="card mb-4 bg-primary text-white">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="avatar avatar-lg me-3">
                        <span class="avatar-initial bg-white text-primary rounded-circle">
                            <i class="bx bx-store bx-sm"></i>
                        </span>
                    </div>
                    <div>
                        <h5 class="text-white mb-1"><?php echo esc_html($current_shop_name); ?></h5>
                        <p class="mb-0 opacity-75">Shop ID: #<?php echo esc_html($current_blog_id); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <!-- Tổng bán đi -->
            <div class="col-lg-3 col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="avatar">
                                <span class="avatar-initial bg-label-info rounded">
                                    <i class="bx bx-export"></i>
                                </span>
                            </div>
                            <div class="dropdown">
                                <button class="btn btn-sm p-0" type="button" disabled>
                                    <i class="bx bx-info-circle text-muted"></i>
                                </button>
                            </div>
                        </div>
                        <h4 class="card-title mb-1" id="statExportCount">0</h4>
                        <small class="text-muted">Phiếu bán nội bộ</small>
                        <div class="mt-2">
                            <span class="badge bg-label-success" id="statExportApproved">0 đã duyệt</span>
                            <span class="badge bg-label-warning" id="statExportPending">0 chờ duyệt</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tổng mua về -->
            <div class="col-lg-3 col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="avatar">
                                <span class="avatar-initial bg-label-success rounded">
                                    <i class="bx bx-import"></i>
                                </span>
                            </div>
                        </div>
                        <h4 class="card-title mb-1" id="statImportCount">0</h4>
                        <small class="text-muted">Phiếu mua nội bộ</small>
                        <div class="mt-2">
                            <span class="badge bg-label-success" id="statImportApproved">0 đã duyệt</span>
                            <span class="badge bg-label-warning" id="statImportPending">0 chờ duyệt</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Chờ nhận từ mẹ -->
            <div class="col-lg-3 col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="avatar">
                                <span class="avatar-initial bg-label-warning rounded">
                                    <i class="bx bx-time"></i>
                                </span>
                            </div>
                        </div>
                        <h4 class="card-title mb-1" id="statPendingReceive">0</h4>
                        <small class="text-muted">Đang chờ nhận</small>
                        <div class="mt-2">
                            <a href="<?php echo admin_url('admin.php?page=tgs-shop-management&view=transfer-pending-imports'); ?>"
                               class="btn btn-sm btn-outline-warning">
                                Xem chi tiết
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tổng sản phẩm đã chuyển -->
            <div class="col-lg-3 col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="avatar">
                                <span class="avatar-initial bg-label-primary rounded">
                                    <i class="bx bx-package"></i>
                                </span>
                            </div>
                        </div>
                        <h4 class="card-title mb-1" id="statTotalProducts">0</h4>
                        <small class="text-muted">Tổng SP luân chuyển</small>
                        <div class="mt-2">
                            <span class="text-info" id="statProductsExported">0 xuất</span> |
                            <span class="text-success" id="statProductsImported">0 nhập</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="row mb-4">
            <!-- Biểu đồ xu hướng -->
            <div class="col-lg-8 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Xu hướng luân chuyển</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container" style="position: relative; height: 300px;">
                            <canvas id="trendChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Biểu đồ tròn trạng thái -->
            <div class="col-lg-4 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Trạng thái phiếu</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container" style="position: relative; height: 250px;">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Transfer Relations -->
        <div class="row">
            <!-- Shops đã bán cho -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="bx bx-share text-info"></i>
                            Đã bán cho các shop
                        </h5>
                        <span class="badge bg-info" id="exportedShopsCount">0 shop</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Shop nhận</th>
                                        <th class="text-center">Số phiếu</th>
                                        <th class="text-center">SP</th>
                                        <th class="text-center">Trạng thái</th>
                                    </tr>
                                </thead>
                                <tbody id="exportedShopsTable">
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">
                                            Chưa bán cho shop nào
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Shops đã nhận từ -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="bx bx-download text-success"></i>
                            Đã nhận từ các shop
                        </h5>
                        <span class="badge bg-success" id="importedShopsCount">0 shop</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Shop gửi</th>
                                        <th class="text-center">Số phiếu</th>
                                        <th class="text-center">SP</th>
                                        <th class="text-center">Trạng thái</th>
                                    </tr>
                                </thead>
                                <tbody id="importedShopsTable">
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">
                                            Chưa nhận từ shop nào
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Transfers -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bx bx-history"></i>
                    Hoạt động gần đây
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Thời gian</th>
                                <th>Loại</th>
                                <th>Mã phiếu</th>
                                <th>Shop liên quan</th>
                                <th class="text-center">SP</th>
                                <th class="text-center">Trạng thái</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="recentTransfersTable">
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    Chưa có hoạt động nào
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
jQuery(document).ready(function($) {
    const ajaxUrl = '<?php echo esc_js($ajax_url); ?>';
    const nonce = '<?php echo esc_js($nonce); ?>';
    const currentBlogId = <?php echo intval($current_blog_id); ?>;

    let trendChart = null;
    let statusChart = null;

    // Load report data
    function loadReportData() {
        $('#loadingSpinner').show();
        $('#reportContent').hide();

        const period = $('#filterPeriod').val();

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'tgs_transfer_get_report_data',
                nonce: nonce,
                period: period
            },
            success: function(response) {
                $('#loadingSpinner').hide();

                if (response.success && response.data) {
                    renderReport(response.data);
                    $('#reportContent').show();
                } else {
                    // Show empty state
                    renderEmptyReport();
                    $('#reportContent').show();
                }
            },
            error: function() {
                $('#loadingSpinner').hide();
                alert('Có lỗi khi tải dữ liệu báo cáo');
            }
        });
    }

    // Render report
    function renderReport(data) {
        // Summary stats
        $('#statExportCount').text(data.summary.export_count || 0);
        $('#statExportApproved').text((data.summary.export_approved || 0) + ' đã duyệt');
        $('#statExportPending').text((data.summary.export_pending || 0) + ' chờ duyệt');

        $('#statImportCount').text(data.summary.import_count || 0);
        $('#statImportApproved').text((data.summary.import_approved || 0) + ' đã duyệt');
        $('#statImportPending').text((data.summary.import_pending || 0) + ' chờ duyệt');

        $('#statPendingReceive').text(data.summary.pending_receive || 0);

        const totalProducts = (data.summary.products_exported || 0) + (data.summary.products_imported || 0);
        $('#statTotalProducts').text(totalProducts);
        $('#statProductsExported').text((data.summary.products_exported || 0) + ' xuất');
        $('#statProductsImported').text((data.summary.products_imported || 0) + ' nhập');

        // Render charts
        renderTrendChart(data.trend || []);
        renderStatusChart(data.summary);

        // Render relations
        renderExportedShops(data.exported_to || []);
        renderImportedShops(data.imported_from || []);

        // Render recent transfers
        renderRecentTransfers(data.recent || []);
    }

    // Render empty report
    function renderEmptyReport() {
        $('#statExportCount').text('0');
        $('#statExportApproved').text('0 đã duyệt');
        $('#statExportPending').text('0 chờ duyệt');
        $('#statImportCount').text('0');
        $('#statImportApproved').text('0 đã duyệt');
        $('#statImportPending').text('0 chờ duyệt');
        $('#statPendingReceive').text('0');
        $('#statTotalProducts').text('0');
        $('#statProductsExported').text('0 xuất');
        $('#statProductsImported').text('0 nhập');

        renderTrendChart([]);
        renderStatusChart({});
        renderExportedShops([]);
        renderImportedShops([]);
        renderRecentTransfers([]);
    }

    // Trend Chart
    function renderTrendChart(trendData) {
        const ctx = document.getElementById('trendChart').getContext('2d');

        if (trendChart) {
            trendChart.destroy();
        }

        const labels = trendData.map(d => d.date);
        const exports = trendData.map(d => d.exports || 0);
        const imports = trendData.map(d => d.imports || 0);

        trendChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels.length ? labels : ['Không có dữ liệu'],
                datasets: [{
                    label: 'Xuất đi',
                    data: exports.length ? exports : [0],
                    borderColor: '#03c3ec',
                    backgroundColor: 'rgba(3, 195, 236, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Nhập về',
                    data: imports.length ? imports : [0],
                    borderColor: '#71dd37',
                    backgroundColor: 'rgba(113, 221, 55, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    }

    // Status Chart
    function renderStatusChart(summary) {
        const ctx = document.getElementById('statusChart').getContext('2d');

        if (statusChart) {
            statusChart.destroy();
        }

        const approved = (summary.export_approved || 0) + (summary.import_approved || 0);
        const pending = (summary.export_pending || 0) + (summary.import_pending || 0);

        statusChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Đã duyệt', 'Chờ duyệt'],
                datasets: [{
                    data: [approved || 0, pending || 0],
                    backgroundColor: ['#71dd37', '#ffab00'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }

    // Exported shops table
    function renderExportedShops(shops) {
        if (!shops || shops.length === 0) {
            $('#exportedShopsTable').html('<tr><td colspan="4" class="text-center text-muted py-4">Chưa bán cho shop nào</td></tr>');
            $('#exportedShopsCount').text('0 shop');
            return;
        }

        let html = '';
        shops.forEach(function(shop) {
            html += `
                <tr>
                    <td>
                        <div class="d-flex align-items-center">
                            <div class="avatar avatar-sm me-2">
                                <span class="avatar-initial bg-label-info rounded-circle">
                                    ${escapeHtml((shop.shop_name || 'S').charAt(0))}
                                </span>
                            </div>
                            <div>
                                <strong>${escapeHtml(shop.shop_name || 'Shop #' + shop.blog_id)}</strong>
                                <br><small class="text-muted">ID: ${shop.blog_id}</small>
                            </div>
                        </div>
                    </td>
                    <td class="text-center"><span class="badge bg-info">${shop.tickets_count}</span></td>
                    <td class="text-center">${shop.products_count}</td>
                    <td class="text-center">
                        <span class="badge bg-success">${shop.approved_count || 0}</span>
                        <span class="badge bg-warning">${shop.pending_count || 0}</span>
                    </td>
                </tr>
            `;
        });

        $('#exportedShopsTable').html(html);
        $('#exportedShopsCount').text(shops.length + ' shop');
    }

    // Imported shops table
    function renderImportedShops(shops) {
        if (!shops || shops.length === 0) {
            $('#importedShopsTable').html('<tr><td colspan="4" class="text-center text-muted py-4">Chưa nhận từ shop nào</td></tr>');
            $('#importedShopsCount').text('0 shop');
            return;
        }

        let html = '';
        shops.forEach(function(shop) {
            html += `
                <tr>
                    <td>
                        <div class="d-flex align-items-center">
                            <div class="avatar avatar-sm me-2">
                                <span class="avatar-initial bg-label-success rounded-circle">
                                    ${escapeHtml((shop.shop_name || 'S').charAt(0))}
                                </span>
                            </div>
                            <div>
                                <strong>${escapeHtml(shop.shop_name || 'Shop #' + shop.blog_id)}</strong>
                                <br><small class="text-muted">ID: ${shop.blog_id}</small>
                            </div>
                        </div>
                    </td>
                    <td class="text-center"><span class="badge bg-success">${shop.tickets_count}</span></td>
                    <td class="text-center">${shop.products_count}</td>
                    <td class="text-center">
                        <span class="badge bg-success">${shop.approved_count || 0}</span>
                        <span class="badge bg-warning">${shop.pending_count || 0}</span>
                    </td>
                </tr>
            `;
        });

        $('#importedShopsTable').html(html);
        $('#importedShopsCount').text(shops.length + ' shop');
    }

    // Recent transfers
    function renderRecentTransfers(transfers) {
        if (!transfers || transfers.length === 0) {
            $('#recentTransfersTable').html('<tr><td colspan="7" class="text-center text-muted py-4">Chưa có hoạt động nào</td></tr>');
            return;
        }

        let html = '';
        transfers.forEach(function(t) {
            const typeIcon = t.type === 'export' ? 'bx-share text-info' : 'bx-download text-success';
            const typeLabel = t.type === 'export' ? 'Xuất đi' : 'Nhập về';
            const statusBadge = t.status === 'approved'
                ? '<span class="badge bg-success">Đã duyệt</span>'
                : '<span class="badge bg-warning">Chờ duyệt</span>';

            const detailUrl = t.type === 'export'
                ? '<?php echo admin_url('admin.php?page=tgs-shop-management&view=ticket-transfer-export-detail'); ?>&id=' + t.ledger_id
                : '<?php echo admin_url('admin.php?page=tgs-shop-management&view=ticket-transfer-import-detail'); ?>&id=' + t.ledger_id;

            html += `
                <tr>
                    <td><small>${formatDate(t.created_at)}</small></td>
                    <td>
                        <i class="bx ${typeIcon}"></i>
                        <span class="ms-1">${typeLabel}</span>
                    </td>
                    <td><code>${escapeHtml(t.ledger_code)}</code></td>
                    <td>${escapeHtml(t.related_shop_name || 'Shop #' + t.related_blog_id)}</td>
                    <td class="text-center">${t.products_count}</td>
                    <td class="text-center">${statusBadge}</td>
                    <td>
                        <a href="${detailUrl}" class="btn btn-sm btn-outline-primary">
                            <i class="bx bx-show"></i>
                        </a>
                    </td>
                </tr>
            `;
        });

        $('#recentTransfersTable').html(html);
    }

    // Event handlers
    $('#filterPeriod').on('change', function() {
        loadReportData();
    });

    $('#btnRefresh').on('click', function() {
        loadReportData();
    });

    // Helpers
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
    loadReportData();
});
</script>

<style>
.avatar-initial {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
}
.avatar-sm .avatar-initial {
    width: 32px;
    height: 32px;
    font-size: 12px;
}
.avatar-lg .avatar-initial {
    width: 56px;
    height: 56px;
    font-size: 24px;
}
.bg-label-info {
    background-color: rgba(3, 195, 236, 0.16) !important;
    color: #03c3ec !important;
}
.bg-label-success {
    background-color: rgba(113, 221, 55, 0.16) !important;
    color: #71dd37 !important;
}
.bg-label-warning {
    background-color: rgba(255, 171, 0, 0.16) !important;
    color: #ffab00 !important;
}
.bg-label-primary {
    background-color: rgba(105, 108, 255, 0.16) !important;
    color: #696cff !important;
}
</style>
