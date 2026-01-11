<?php
/**
 * Plugin Name: TGS Transfer Management
 * Plugin URI: https://bizgpt.vn/
 * Description: Plugin quản lý mua bán nội bộ giữa các shop - Extension của TGS Shop Management
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
        require_once TGS_TRANSFER_PLUGIN_DIR . 'includes/class-tgs-return-ajax.php';
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
        TGS_Return_Ajax::init();
    }

    /**
     * Đăng ký routes cho Transfer
     */
    public function register_routes($routes)
    {
        // Transfer routes (Mua bán nội bộ)
        $transfer_routes = [
            'transfer-export-add' => ['Bán hàng nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/transfer-export-add.php'],
            'ticket-transfer-exports' => ['DS phiếu bán nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/list-export.php'],
            'ticket-transfer-export-detail' => ['Chi tiết phiếu bán nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/detail-export.php'],
            'transfer-pending-imports' => ['Phiếu chờ mua từ shop bán', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/pending-imports.php'],
            'transfer-import-add' => ['Mua hàng nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/transfer-import-add.php'],
            'ticket-transfer-imports' => ['DS phiếu mua nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/list-import.php'],
            'ticket-transfer-import-detail' => ['Chi tiết phiếu mua nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/detail-import.php'],
            'transfer-report' => ['Báo cáo mua bán nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/transfer/transfer-report.php'],
        ];

        // Return routes (Trả hàng nội bộ)
        $return_routes = [
            'return-export-add' => ['Trả hàng nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/return/return-export-add.php'],
            'ticket-return-exports' => ['DS phiếu trả nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/return/list-return-export.php'],
            'ticket-return-export-detail' => ['Chi tiết phiếu trả nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/return/detail-return-export.php'],
            'return-pending-returns' => ['Phiếu chờ nhận hàng trả', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/return/pending-returns.php'],
            'return-import-add' => ['Nhận hàng trả', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/return/return-import-add.php'],
            'ticket-return-imports' => ['DS phiếu nhận hàng trả', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/return/list-return-import.php'],
            'ticket-return-import-detail' => ['Chi tiết phiếu nhận hàng trả', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/return/detail-return-import.php'],
            'return-report' => ['Báo cáo trả hàng nội bộ', TGS_TRANSFER_PLUGIN_DIR . 'admin-views/pages/return/return-report.php'],
        ];

        return array_merge($routes, $transfer_routes, $return_routes);
    }

    /**
     * Render sidebar menu cho Transfer và Return
     */
    public function render_sidebar_menu($current_view)
    {
        // Transfer views (Mua bán nội bộ)
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
        $is_transfer_active = in_array($current_view, $transfer_views) ? 'active open' : '';

        // Return views (Trả hàng nội bộ)
        $return_views = [
            'return-export-add',
            'ticket-return-exports',
            'ticket-return-export-detail',
            'return-pending-returns',
            'return-import-add',
            'ticket-return-imports',
            'ticket-return-import-detail',
            'return-report'
        ];
        $is_return_active = in_array($current_view, $return_views) ? 'active open' : '';
        ?>
        <!-- Mua bán nội bộ - From TGS Transfer Management Plugin -->
        <li class="menu-item <?php echo $is_transfer_active; ?>">
            <a href="javascript:void(0);" class="menu-link menu-toggle">
                <i class="menu-icon tf-icons bx bx-store"></i>
                <div>Mua bán nội bộ</div>
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
                        <div>Bán hàng nội bộ</div>
                    </a>
                </li>
                <li class="menu-item <?php echo in_array($current_view, ['ticket-transfer-exports', 'ticket-transfer-export-detail']) ? 'active' : ''; ?>">
                    <a href="<?php echo tgs_url('ticket-transfer-exports'); ?>" class="menu-link">
                        <i class="bx bx-list-ul me-1"></i>
                        <div>DS phiếu bán nội bộ</div>
                    </a>
                </li>
                <li class="menu-item <?php echo $current_view === 'transfer-pending-imports' ? 'active' : ''; ?>">
                    <a href="<?php echo tgs_url('transfer-pending-imports'); ?>" class="menu-link">
                        <i class="bx bx-time text-warning me-1"></i>
                        <div>Chờ mua từ shop bán</div>
                    </a>
                </li>
                <li class="menu-item <?php echo in_array($current_view, ['ticket-transfer-imports', 'ticket-transfer-import-detail']) ? 'active' : ''; ?>">
                    <a href="<?php echo tgs_url('ticket-transfer-imports'); ?>" class="menu-link">
                        <i class="bx bx-download text-success me-1"></i>
                        <div>DS phiếu mua nội bộ</div>
                    </a>
                </li>
            </ul>
        </li>

        <!-- Trả hàng nội bộ - From TGS Transfer Management Plugin -->
        <li class="menu-item <?php echo $is_return_active; ?>">
            <a href="javascript:void(0);" class="menu-link menu-toggle">
                <i class="menu-icon tf-icons bx bx-undo"></i>
                <div>Trả hàng nội bộ</div>
            </a>
            <ul class="menu-sub">
                <li class="menu-item <?php echo $current_view === 'return-report' ? 'active' : ''; ?>">
                    <a href="<?php echo tgs_url('return-report'); ?>" class="menu-link">
                        <i class="bx bx-bar-chart-alt-2 text-primary me-1"></i>
                        <div>Báo cáo & Thống kê</div>
                    </a>
                </li>
                <li class="menu-item <?php echo $current_view === 'return-export-add' ? 'active' : ''; ?>">
                    <a href="<?php echo tgs_url('return-export-add'); ?>" class="menu-link">
                        <i class="bx bx-undo text-info me-1"></i>
                        <div>Trả hàng nội bộ</div>
                    </a>
                </li>
                <li class="menu-item <?php echo in_array($current_view, ['ticket-return-exports', 'ticket-return-export-detail']) ? 'active' : ''; ?>">
                    <a href="<?php echo tgs_url('ticket-return-exports'); ?>" class="menu-link">
                        <i class="bx bx-list-ul me-1"></i>
                        <div>DS phiếu trả nội bộ</div>
                    </a>
                </li>
                <li class="menu-item <?php echo $current_view === 'return-pending-returns' ? 'active' : ''; ?>">
                    <a href="<?php echo tgs_url('return-pending-returns'); ?>" class="menu-link">
                        <i class="bx bx-time text-warning me-1"></i>
                        <div>Chờ nhận hàng trả</div>
                    </a>
                </li>
                <li class="menu-item <?php echo in_array($current_view, ['ticket-return-imports', 'ticket-return-import-detail']) ? 'active' : ''; ?>">
                    <a href="<?php echo tgs_url('ticket-return-imports'); ?>" class="menu-link">
                        <i class="bx bx-download text-success me-1"></i>
                        <div>DS phiếu nhận hàng trả</div>
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
