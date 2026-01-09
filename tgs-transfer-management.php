<?php
/**
 * Plugin Name: TGS Transfer Management
 * Plugin URI: https://bizgpt.vn/
 * Description: Plugin quản lý chuyển kho liên shop (xuất từ mẹ xuống con, từ con nhận mẹ) - Extension của TGS Shop Management
 * Version: 1.0.0
 * Author: BIZGPT_AI
 * Author URI: https://bizgpt.vn/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tgs-transfer-management
 * Domain Path: /languages
 * Requires Plugins: tgs_shop_management
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('TGS_TRANSFER_VERSION', '1.0.0');
define('TGS_TRANSFER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TGS_TRANSFER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TGS_TRANSFER_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Class chính của plugin TGS Transfer Management
 */
class TGS_Transfer_Management
{
    private static $instance = null;

    /**
     * Singleton instance
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        // Kiểm tra plugin phụ thuộc
        add_action('plugins_loaded', [$this, 'check_dependencies']);
    }

    /**
     * Kiểm tra plugin TGS Shop Management đã được kích hoạt chưa
     */
    public function check_dependencies()
    {
        // Kiểm tra plugin gốc có active không
        if (!class_exists('TGS_Shop_Management')) {
            add_action('admin_notices', [$this, 'show_dependency_notice']);
            return;
        }

        // Plugin gốc đã active, khởi tạo
        $this->init();
    }

    /**
     * Hiển thị thông báo nếu thiếu plugin phụ thuộc
     */
    public function show_dependency_notice()
    {
        ?>
        <div class="notice notice-error">
            <p>
                <strong>TGS Transfer Management</strong> yêu cầu plugin
                <strong>TGS Shop Management</strong> phải được kích hoạt trước.
            </p>
        </div>
        <?php
    }

    /**
     * Khởi tạo plugin
     */
    public function init()
    {
        // Load các file cần thiết
        $this->load_dependencies();

        // Đăng ký hooks
        $this->register_hooks();
    }

    /**
     * Load các file dependencies
     */
    private function load_dependencies()
    {
        require_once TGS_TRANSFER_PLUGIN_DIR . 'includes/class-tgs-transfer-ajax.php';
    }

    /**
     * Đăng ký các hooks
     */
    private function register_hooks()
    {
        // Hook vào routes của plugin gốc
        add_filter('tgs_shop_dashboard_routes', [$this, 'register_routes']);

        // Hook vào sidebar menu
        add_action('tgs_shop_sidebar_menu', [$this, 'render_sidebar_menu']);

        // Khởi tạo AJAX handlers
        TGS_Transfer_Ajax::init();
    }

    /**
     * Đăng ký routes cho Transfer
     */
    public function register_routes($routes)
    {
        $transfer_routes = [
            'transfer-export-add' => ['Xuất hàng đến shop con', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/transfer-export-add.php'],
            'ticket-transfer-exports' => ['Phiếu xuất đến shop con', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/list-export.php'],
            'ticket-transfer-export-detail' => ['Chi tiết phiếu xuất đến shop', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/detail-export.php'],
            'transfer-pending-imports' => ['Phiếu chờ nhận từ shop mẹ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/pending-imports.php'],
            'transfer-import-add' => ['Nhận hàng từ shop mẹ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/transfer-import-add.php'],
            'ticket-transfer-imports' => ['Phiếu nhập từ shop mẹ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/list-import.php'],
            'ticket-transfer-import-detail' => ['Chi tiết phiếu nhập từ shop mẹ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/detail-import.php'],
            'transfer-report' => ['Báo cáo chuyển kho liên shop', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/transfer-report.php'],
        ];

        return array_merge($routes, $transfer_routes);
    }

    /**
     * Render sidebar menu cho Transfer
     */
    public function render_sidebar_menu($current_view)
    {
        $transfer_views = [
            'transfer-export-add',
            'ticket-transfer-exports',
            'ticket-transfer-export-detail',
            'transfer-pending-imports',
            'transfer-import-add',
            'ticket-transfer-imports',
            'ticket-transfer-import-detail',
            'transfer-report'
        ];
        $is_active = in_array($current_view, $transfer_views) ? 'active open' : '';
        ?>
        <!-- Xuất nhập giữa các shop (Multisite) - From TGS Transfer Management Plugin -->
        <li class="menu-item <?php echo $is_active; ?>">
            <a href="javascript:void(0);" class="menu-link menu-toggle">
                <i class="menu-icon tf-icons bx bx-store"></i>
                <div>Chuyển kho liên shop</div>
            </a>
            <ul class="menu-sub">
                <li class="menu-item <?php echo $current_view === 'transfer-report' ? 'active' : ''; ?>">
                    <a href="<?php echo tgs_url('transfer-report'); ?>" class="menu-link">
                        <i class="bx bx-bar-chart-alt-2 text-primary me-1"></i>
                        <div>Báo cáo & Thống kê</div>
                    </a>
                </li>
                <li class="menu-item <?php echo $current_view === 'transfer-export-add' ? 'active' : ''; ?>">
                    <a href="<?php echo tgs_url('transfer-export-add'); ?>" class="menu-link">
                        <i class="bx bx-share text-info me-1"></i>
                        <div>Xuất đến shop con</div>
                    </a>
                </li>
                <li class="menu-item <?php echo in_array($current_view, ['ticket-transfer-exports', 'ticket-transfer-export-detail']) ? 'active' : ''; ?>">
                    <a href="<?php echo tgs_url('ticket-transfer-exports'); ?>" class="menu-link">
                        <i class="bx bx-list-ul me-1"></i>
                        <div>DS phiếu xuất đi</div>
                    </a>
                </li>
                <li class="menu-item <?php echo $current_view === 'transfer-pending-imports' ? 'active' : ''; ?>">
                    <a href="<?php echo tgs_url('transfer-pending-imports'); ?>" class="menu-link">
                        <i class="bx bx-time text-warning me-1"></i>
                        <div>Chờ nhận từ shop mẹ</div>
                    </a>
                </li>
                <li class="menu-item <?php echo in_array($current_view, ['ticket-transfer-imports', 'ticket-transfer-import-detail']) ? 'active' : ''; ?>">
                    <a href="<?php echo tgs_url('ticket-transfer-imports'); ?>" class="menu-link">
                        <i class="bx bx-download text-success me-1"></i>
                        <div>DS phiếu nhập về</div>
                    </a>
                </li>
            </ul>
        </li>
        <?php
    }
}

/**
 * Khởi tạo plugin
 */
function tgs_transfer_management_init()
{
    return TGS_Transfer_Management::get_instance();
}

tgs_transfer_management_init();
