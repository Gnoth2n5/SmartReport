<?php

namespace Database\Seeders;

use App\Models\CustomView;
use App\Models\DataSource;
use App\Models\MenuGroup;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Database\ExternalTestDatabaseProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SmartReportSeeder extends Seeder
{
    /**
     * Controls whether external Docker databases (PostgreSQL, SQL Server, Oracle)
     * should be provisioned with realistic sample tables.
     */
    public static bool $seedExternal = true;

    /**
     * Cache flag to ensure external databases are provisioned at most once
     * per PHP execution lifecycle (e.g. across test suites).
     */
    protected static bool $alreadyProvisioned = false;

    /**
     * Reset provision state (useful for test teardown or explicit re-provisioning).
     */
    public static function resetProvisionState(): void
    {
        static::$alreadyProvisioned = false;
        static::$seedExternal = true;
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Provision schemas and realistic sample data on all 4 external Docker DBs
        if (static::$seedExternal && ! static::$alreadyProvisioned) {
            $provisioner = new ExternalTestDatabaseProvisioner;
            $provisioner->provisionAll();
            static::$alreadyProvisioned = true;
        }

        // 2. Seed SQLite models
        $this->seedSqliteModels();
    }

    /**
     * Seed all SQLite metadata models (DataSources, MenuGroups, RolePermissions, CustomViews, Users).
     */
    public function seedSqliteModels(): void
    {
        $this->seedDataSources();
        $this->seedMenuGroups();
        $this->seedRolePermissions();
        $this->seedCustomViews();
        $this->seedUsers();
    }

    public function seedDataSources(): void
    {
        $dataSources = [
            [
                'code' => 'pg_north',
                'name' => 'PostgreSQL Nhà Máy Phía Bắc (Hà Nội)',
                'driver' => DataSource::DRIVER_PGSQL,
                'host' => '127.0.0.1',
                'port' => 5432,
                'database_name' => 'source_db',
                'service_name' => null,
                'default_schema' => 'public',
                'username' => 'etl_user',
                'password' => 'etl_password',
                'options' => ['charset' => 'utf8', 'sslmode' => 'prefer'],
                'is_read_only' => true,
                'is_active' => true,
            ],
            [
                'code' => 'pg_south',
                'name' => 'PostgreSQL Nhà Máy Phía Nam (TP.HCM)',
                'driver' => DataSource::DRIVER_PGSQL,
                'host' => '127.0.0.1',
                'port' => 5433,
                'database_name' => 'app',
                'service_name' => null,
                'default_schema' => 'public',
                'username' => 'app',
                'password' => 'app',
                'options' => ['charset' => 'utf8', 'sslmode' => 'prefer'],
                'is_read_only' => true,
                'is_active' => true,
            ],
            [
                'code' => 'mssql_erp',
                'name' => 'SQL Server ERP Phân Xưởng & Sản Xuất',
                'driver' => DataSource::DRIVER_SQLSRV,
                'host' => '127.0.0.1',
                'port' => 1433,
                'database_name' => 'erp_production',
                'service_name' => null,
                'default_schema' => 'dbo',
                'username' => 'sa',
                'password' => 'DevPassword!123',
                'options' => ['trust_server_certificate' => true],
                'is_read_only' => true,
                'is_active' => true,
            ],
            [
                'code' => 'oracle_hr',
                'name' => 'Oracle 23ai Quản Trị Nhân Sự & Tiền Lương',
                'driver' => DataSource::DRIVER_ORACLE,
                'host' => '127.0.0.1',
                'port' => 1521,
                'database_name' => null,
                'service_name' => 'FREEPDB1',
                'default_schema' => 'APP',
                'username' => 'app',
                'password' => 'app',
                'options' => ['service_name' => 'FREEPDB1'],
                'is_read_only' => true,
                'is_active' => true,
            ],
        ];

        foreach ($dataSources as $ds) {
            DataSource::updateOrCreate(['code' => $ds['code']], $ds);
        }
    }

    public function seedMenuGroups(): void
    {
        $menuGroups = [
            [
                'group_code' => 'manufacturing',
                'title' => 'Sản Xuất & Vận Hành',
                'icon' => 'heroicon-o-wrench-screwdriver',
                'sort_order' => 10,
                'roles' => ['super_admin', 'report_viewer'],
                'is_active' => true,
            ],
            [
                'group_code' => 'logistics',
                'title' => 'Xuất Nhập Khẩu & Hợp Đồng',
                'icon' => 'heroicon-o-truck',
                'sort_order' => 20,
                'roles' => ['super_admin', 'report_viewer'],
                'is_active' => true,
            ],
            [
                'group_code' => 'executive',
                'title' => 'Báo Cáo Điều Hành & Nhân Sự',
                'icon' => 'heroicon-o-chart-bar-square',
                'sort_order' => 30,
                'roles' => ['super_admin'],
                'is_active' => true,
            ],
        ];

        foreach ($menuGroups as $mg) {
            MenuGroup::updateOrCreate(['group_code' => $mg['group_code']], $mg);
        }
    }

    public function seedRolePermissions(): void
    {
        $roles = [
            [
                'role_code' => 'super_admin',
                'role_name' => 'Quản trị viên cấp cao',
                'description' => 'Toàn quyền quản trị hệ thống và xem tất cả báo cáo',
                'is_system' => true,
                'allowed_groups' => ['*'],
                'allowed_views' => ['*'],
                'allowed_sources' => ['*'],
            ],
            [
                'role_code' => 'report_viewer',
                'role_name' => 'Nhân viên xem báo cáo',
                'description' => 'Xem các báo cáo sản xuất và logistics cơ bản',
                'is_system' => false,
                'allowed_groups' => ['manufacturing', 'logistics'],
                'allowed_views' => ['pg-multi-plant-contracts', 'mssql-production-orders', 'oracle-hr-directory'],
                'allowed_sources' => ['pg_north', 'pg_south', 'mssql_erp', 'oracle_hr'],
            ],
        ];

        foreach ($roles as $role) {
            RolePermission::updateOrCreate(['role_code' => $role['role_code']], $role);
        }
    }

    public function seedCustomViews(): void
    {
        $customViews = [
            // Pattern 1: Single-DB or Multi-DB UNION ALL on PostgreSQL
            [
                'view_code' => 'pg-multi-plant-contracts',
                'view_name' => 'Hợp Đồng Xuất Khẩu Đa Chi Nhánh (Bắc & Nam)',
                'menu_group_code' => 'logistics',
                'execution_mode' => CustomView::MODE_SINGLE_OR_UNION,
                'allowed_sources' => ['pg_north', 'pg_south'],
                'default_sources' => ['pg_north'],
                'source_queries' => null,
                'sql_text' => 'SELECT c.contract_no, c.customer_name, c.plant, c.total_amount, c.currency, c.status, c.sign_date, c.delivery_date, c.notes FROM public.v_epcontract c WHERE (@Status IS NULL OR c.status = @Status) AND (@StartDate IS NULL OR c.sign_date >= @StartDate) AND (@EndDate IS NULL OR c.sign_date <= @EndDate) ORDER BY c.sign_date DESC',
                'filters' => [
                    [
                        'name' => 'Status',
                        'label' => 'Trạng thái',
                        'type' => 'select',
                        'options' => [
                            'COMPLETED' => 'Hoàn tất',
                            'IN_PROGRESS' => 'Đang thực hiện',
                            'PENDING' => 'Chờ duyệt',
                            'CANCELLED' => 'Đã hủy',
                        ],
                    ],
                    [
                        'name' => 'StartDate',
                        'label' => 'Từ ngày ký',
                        'type' => 'date',
                    ],
                    [
                        'name' => 'EndDate',
                        'label' => 'Đến ngày ký',
                        'type' => 'date',
                    ],
                ],
                'roles' => ['super_admin', 'report_viewer'],
                'description' => 'Tra cứu danh sách hợp đồng may mặc và vải xuất khẩu, hỗ trợ gộp 2 chi nhánh Bắc và Nam (UNION ALL).',
                'version' => '1.0.0',
                'is_active' => true,
            ],
            // Pattern 2: Single-DB on SQL Server
            [
                'view_code' => 'mssql-production-orders',
                'view_name' => 'Tiến Độ Lệnh Sản Xuất & Giờ Máy (SQL Server ERP)',
                'menu_group_code' => 'manufacturing',
                'execution_mode' => CustomView::MODE_SINGLE_OR_UNION,
                'allowed_sources' => ['mssql_erp'],
                'default_sources' => ['mssql_erp'],
                'source_queries' => null,
                'sql_text' => 'SELECT po.order_no, po.contract_no, po.plant, po.emp_no, po.machine_code, po.output_qty, po.defect_qty, po.unit_cost, po.defect_reason, po.shift_date FROM dbo.production_orders po WHERE (@Plant IS NULL OR po.plant = @Plant) AND (@StartDate IS NULL OR po.shift_date >= @StartDate) AND (@EndDate IS NULL OR po.shift_date <= @EndDate) ORDER BY po.shift_date DESC',
                'filters' => [
                    [
                        'name' => 'Plant',
                        'label' => 'Nhà máy',
                        'type' => 'select',
                        'options' => [
                            'NORTH' => 'Miền Bắc',
                            'SOUTH' => 'Miền Nam',
                        ],
                    ],
                    [
                        'name' => 'StartDate',
                        'label' => 'Từ ngày ca',
                        'type' => 'date',
                    ],
                    [
                        'name' => 'EndDate',
                        'label' => 'Đến ngày ca',
                        'type' => 'date',
                    ],
                ],
                'roles' => ['super_admin', 'report_viewer'],
                'description' => 'Theo dõi sản lượng đầu ra, tỷ lệ phế phẩm và chi phí sản xuất theo từng máy và nhân công trên SQL Server.',
                'version' => '1.0.0',
                'is_active' => true,
            ],
            // Pattern 3: Single-DB on Oracle
            [
                'view_code' => 'oracle-hr-directory',
                'view_name' => 'Hồ Sơ Nhân Sự & Bậc Lương Nhà Máy (Oracle 23ai)',
                'menu_group_code' => 'executive',
                'execution_mode' => CustomView::MODE_SINGLE_OR_UNION,
                'allowed_sources' => ['oracle_hr'],
                'default_sources' => ['oracle_hr'],
                'source_queries' => null,
                'sql_text' => 'SELECT e.EMP_NO, e.FULL_NAME, d.DEPT_NAME, e.PLANT, e.BASE_SALARY, e.HIRE_DATE, e.STATUS, e.NOTES FROM APP.EMPLOYEES e INNER JOIN APP.DEPARTMENTS d ON e.DEPT_CODE = d.DEPT_CODE WHERE (@DeptCode IS NULL OR e.DEPT_CODE = @DeptCode) AND (@Status IS NULL OR e.STATUS = @Status) ORDER BY e.BASE_SALARY DESC',
                'filters' => [
                    [
                        'name' => 'DeptCode',
                        'label' => 'Phòng ban',
                        'type' => 'select',
                        'options' => [
                            'PRD' => 'Sản xuất & May mặc',
                            'QA' => 'Quản lý chất lượng',
                            'TECH' => 'Kỹ thuật bảo trì',
                            'LOG' => 'Logistics & Kho vận',
                            'EXEC' => 'Ban giám đốc',
                        ],
                    ],
                    [
                        'name' => 'Status',
                        'label' => 'Tình trạng',
                        'type' => 'select',
                        'options' => [
                            'ACTIVE' => 'Đang làm việc',
                            'ON_LEAVE' => 'Nghỉ chế độ',
                            'RESIGNED' => 'Đã nghỉ việc',
                        ],
                    ],
                ],
                'roles' => ['super_admin', 'report_viewer'],
                'description' => 'Danh bạ nhân viên, phân loại phòng ban, chi nhánh và thang bậc lương khai thác trực tiếp từ Oracle.',
                'version' => '1.0.0',
                'is_active' => true,
            ],
            // Pattern 4: Cross-Engine 2-DB JOIN (Oracle HR + SQL Server ERP)
            [
                'view_code' => 'join-oracle-mssql-productivity',
                'view_name' => 'Hiệu Suất Sản Xuất Theo Nhân Sự & Phòng Ban (Oracle + SQL Server)',
                'menu_group_code' => 'manufacturing',
                'execution_mode' => CustomView::MODE_FEDERATED_JOIN,
                'allowed_sources' => ['oracle_hr', 'mssql_erp'],
                'default_sources' => ['oracle_hr', 'mssql_erp'],
                'source_queries' => [
                    [
                        'alias' => 'emp',
                        'source_code' => 'oracle_hr',
                        'sql_text' => 'SELECT EMP_NO, FULL_NAME, DEPT_CODE, PLANT, BASE_SALARY FROM APP.EMPLOYEES WHERE (@Plant IS NULL OR PLANT = @Plant)',
                    ],
                    [
                        'alias' => 'orders',
                        'source_code' => 'mssql_erp',
                        'sql_text' => 'SELECT emp_no, order_no, output_qty, defect_qty, unit_cost, shift_date FROM dbo.production_orders WHERE (@StartDate IS NULL OR shift_date >= @StartDate) AND (@EndDate IS NULL OR shift_date <= @EndDate)',
                    ],
                ],
                'sql_text' => 'SELECT e.EMP_NO, e.FULL_NAME, e.DEPT_CODE, e.PLANT, count(o.order_no) AS total_orders, sum(o.output_qty) AS total_output, sum(o.defect_qty) AS total_defect, round(avg(o.unit_cost), 2) AS avg_unit_cost, e.BASE_SALARY FROM emp e INNER JOIN orders o ON e.EMP_NO = o.emp_no GROUP BY e.EMP_NO, e.FULL_NAME, e.DEPT_CODE, e.PLANT, e.BASE_SALARY ORDER BY total_output DESC',
                'filters' => [
                    [
                        'name' => 'Plant',
                        'label' => 'Nhà máy',
                        'type' => 'select',
                        'options' => [
                            'NORTH' => 'Miền Bắc',
                            'SOUTH' => 'Miền Nam',
                        ],
                    ],
                    [
                        'name' => 'StartDate',
                        'label' => 'Từ ngày',
                        'type' => 'date',
                    ],
                    [
                        'name' => 'EndDate',
                        'label' => 'Đến ngày',
                        'type' => 'date',
                    ],
                ],
                'roles' => ['super_admin'],
                'description' => 'Cross-Engine JOIN giữa Oracle HR (thông tin nhân viên, bậc lương) và SQL Server ERP (lệnh sản xuất, sản lượng, phế phẩm).',
                'version' => '1.0.0',
                'is_active' => true,
            ],
            // Pattern 5: 3-Way Enterprise Federation (Postgres + SQL Server + Oracle)
            [
                'view_code' => 'federated-3way-executive-report',
                'view_name' => 'Báo Cáo Tổng Hợp Điều Hành Doanh Nghiệp 3 Hệ DB (Oracle + SQL Server + Postgres)',
                'menu_group_code' => 'executive',
                'execution_mode' => CustomView::MODE_FEDERATED_JOIN,
                'allowed_sources' => ['pg_north', 'pg_south', 'mssql_erp', 'oracle_hr'],
                'default_sources' => ['pg_north', 'pg_south', 'mssql_erp', 'oracle_hr'],
                'source_queries' => [
                    [
                        'alias' => 'contracts_north',
                        'source_code' => 'pg_north',
                        'sql_text' => "SELECT contract_no, customer_name, total_amount, sign_date, 'NORTH' as plant FROM public.v_epcontract WHERE (@StartDate IS NULL OR sign_date >= @StartDate) AND (@EndDate IS NULL OR sign_date <= @EndDate)",
                    ],
                    [
                        'alias' => 'contracts_south',
                        'source_code' => 'pg_south',
                        'sql_text' => "SELECT contract_no, customer_name, total_amount, sign_date, 'SOUTH' as plant FROM public.v_epcontract WHERE (@StartDate IS NULL OR sign_date >= @StartDate) AND (@EndDate IS NULL OR sign_date <= @EndDate)",
                    ],
                    [
                        'alias' => 'mfg',
                        'source_code' => 'mssql_erp',
                        'sql_text' => 'SELECT plant, count(order_no) as total_orders, sum(output_qty) as total_output, sum(defect_qty) as total_defect, sum(output_qty * unit_cost) as total_prod_value FROM dbo.production_orders WHERE (@StartDate IS NULL OR shift_date >= @StartDate) AND (@EndDate IS NULL OR shift_date <= @EndDate) GROUP BY plant',
                    ],
                    [
                        'alias' => 'hr',
                        'source_code' => 'oracle_hr',
                        'sql_text' => 'SELECT PLANT, count(*) as headcount, sum(BASE_SALARY) as total_payroll FROM APP.EMPLOYEES GROUP BY PLANT',
                    ],
                ],
                'sql_text' => 'WITH all_contracts AS (SELECT * FROM contracts_north UNION ALL SELECT * FROM contracts_south), contract_summary AS (SELECT plant, count(*) AS contract_count, sum(total_amount) AS total_contract_value FROM all_contracts GROUP BY plant) SELECT c.plant, c.contract_count, c.total_contract_value, coalesce(m.total_orders, 0) AS total_orders, coalesce(m.total_output, 0) AS total_output, coalesce(m.total_defect, 0) AS total_defect, coalesce(m.total_prod_value, 0) AS total_prod_value, coalesce(h.headcount, 0) AS headcount, coalesce(h.total_payroll, 0) AS total_payroll FROM contract_summary c LEFT JOIN mfg m ON c.plant = m.plant LEFT JOIN hr h ON c.plant = h.PLANT ORDER BY c.plant ASC',
                'filters' => [
                    [
                        'name' => 'StartDate',
                        'label' => 'Từ ngày',
                        'type' => 'date',
                    ],
                    [
                        'name' => 'EndDate',
                        'label' => 'Đến ngày',
                        'type' => 'date',
                    ],
                ],
                'roles' => ['super_admin'],
                'description' => '3-Way Enterprise Federation CTE kết hợp hợp đồng ngoại thương (Postgres North & South), lệnh sản xuất (SQL Server ERP) và nhân sự (Oracle HR).',
                'version' => '1.0.0',
                'is_active' => true,
            ],
        ];

        foreach ($customViews as $view) {
            CustomView::updateOrCreate(['view_code' => $view['view_code']], $view);
        }
    }

    public function seedUsers(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@smartreport.test'],
            [
                'name' => 'Super Administrator',
                'password' => Hash::make('password'),
                'roles' => ['super_admin'],
            ]
        );

        User::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => Hash::make('password'),
                'roles' => ['super_admin'],
            ]
        );
    }
}
