# Kế Hoạch Triển Khai SmartReport (DAR Next-Gen): Laravel 13 + Filament 5 + Multi-DB (SQL Server, PostgreSQL, Oracle) + DuckDB 2.0

> **Trạng thái nền tảng (Đã cài đặt & kiểm chứng sẵn sàng)**:
> - **Framework**: Laravel 13.42 + Filament 5.11 + Livewire 4.4 + Laravel Boost 2.10 đã được cài đặt tại `/home/v25050017/project/laravel/DAR/SmartReport`.
> - **SQLite Config Store**: `database/database.sqlite` lưu cấu hình hệ thống, danh sách kết nối DB, phân quyền Menu/Role và các câu lệnh SQL của Custom View.
> - **DuckDB 2.0 Engine**: Đã cài tại `duckdb` (global `PATH`) & `storage/bin/duckdb` cùng 4 extensions: `postgres`, `mssql` (native TDS 7.4), `oracle_scanner` (native TNS/TTC), và `excel` (OpenXML `.xlsx`). *(Lưu ý: luôn gọi `duckdb -batch -light-mode` để tránh chờ 5s đọc màu terminal).*
> - **Hệ thống 4 Database Đích Đang Chạy Sẵn (`docker-db` & WSL)**:
>   1. `pg_north`: PostgreSQL 16 (`etl_postgres`, `127.0.0.1:5432`, DB `source_db`, User `etl_user` / `etl_password`)
>   2. `pg_south`: PostgreSQL 16 (`dev-postgres`, `127.0.0.1:5433`, DB `app`, User `app` / `app`)
>   3. `mssql_erp`: SQL Server 2022 (`dev-mssql`, `127.0.0.1:1433`, DB `erp_production`, User `sa` / `DevPassword!123`)
>   4. `oracle_hr`: Oracle 23ai Free (`dev-oracle`, `127.0.0.1:1521`, Service `FREEPDB1`, User `app` / `app`)
> - **Skills & Agent Rules**: Đã loại bỏ 34 skill thừa của dự án cũ, giữ lại đúng **9 skills tinh gọn** tại `.agents/skills/` và cấu hình đầy đủ trong [AGENTS.md](../../AGENTS.md).

---

## 1. Quy Tắc Điều Phối `/teamwork`, Áp Dụng Skill & Tái Sử Dụng Subagent

Khi chạy lệnh `/teamwork` dựa trên kế hoạch này, **Coordinator** bắt buộc tuân thủ các nguyên tắc sau:

### 1.1. Roster 4 Worker Chuyên Trách, Tái Sử Dụng & Áp Dụng Đúng Skill
Không tạo mới rồi `kill` subagent/worker qua từng bước nhỏ. Khởi tạo **4 worker chuyên trách**, yêu cầu mỗi worker đọc `AGENTS.md` + kích hoạt đúng các **Skills** được phân công trong `.agents/skills/`, và **tái sử dụng xuyên suốt 6 Cụm công việc** qua `@tên-worker`:

| Tên Worker (`name`) | Skills Bắt Buộc Kích Hoạt (`.agents/skills/<skill>/SKILL.md`) | Vai trò xuyên suốt | Tái sử dụng ở các Cụm |
| :--- | :--- | :--- | :--- |
| `engine` | `laravel-best-practices` + `security-best-practices` + `security-threat-model` | Phụ trách DB Migrations (SQLite), nạp dữ liệu mẫu lên 4 DB (`pg_north`, `pg_south`, `mssql_erp`, `oracle_hr`), xây dựng `DuckDbSourceAdapter`, `DuckDbReportEngine`, `SnapshotManager`, `DuckDbExporter` (`.xlsx` & `.csv` + `.zip`). | Cụm 1, Cụm 2, Cụm 5 |
| `admin-ui` | `laravel-best-practices` + `tailwindcss-development` + `security-best-practices` | Phụ trách Filament Admin Panel, RBAC, Dynamic Sidebar Navigation, `DataSourceResource` (chỉ cho phép 3 loại: `sqlsrv`, `pgsql`, `oracle`), `MenuGroupResource`, `RolePermissionResource`, `CustomViewResource` (Builder + Auto-detect `@param` + Test SQL 10 dòng). | Cụm 3, Cụm 4 |
| `report-ui` | `laravel-best-practices` + `tailwindcss-development` | Phụ trách trang chạy báo cáo động `ReportRunnerPage` (Multi-DB Selector, 3-Tab Header: `Tổng quan` / `Application Information` / `SQL Query`, phân trang 10 dòng từ DuckDB Snapshot) và `SqlExportPage` (xuất `.xlsx` / `.csv` + chia part `.zip`). | Cụm 4, Cụm 5 |
| `verifier` | `testing-best-practices` + `phpunit-skill` + `playwright-cli` | Phụ trách kiểm tra độc lập từng Cụm (`php artisan test`, `vendor/bin/pint --dirty --format agent`, kiểm tra binary `.xlsx`/`.csv`, `git status`) và chạy **Playwright CLI / Chrome MCP E2E Tests** trên trình duyệt thật ở Cụm 6. | Cụm 1 $\rightarrow$ Cụm 6 |

> [!IMPORTANT]
> - Nếu một worker cần chia nhỏ tác vụ cho model nhỏ (`local-8b`/`qwen-8b`), bắt buộc kích hoạt thêm skill `small-agent-task-compiler`.
> - Hai worker không bao giờ sửa chung 1 file tại cùng một thời điểm.

### 1.2. Cổng Kiểm Duyệt & Git Commit Bắt Buộc Sau Mỗi Cụm Công Việc (Commit Gate)
Mỗi Cụm công việc (Work Cluster) chỉ được coi là hoàn tất (`[done]`) khi:
1. Chạy `vendor/bin/pint --dirty --format agent` để chuẩn hóa code style.
2. Toàn bộ lệnh kiểm thử (Acceptance Checks) của Cụm đó chạy `PASS` (0 lỗi, 0 test bị skip).
3. `verifier` xác nhận kết quả trên board.
4. Thực hiện **Git Commit** ngay tại `/home/v25050017/project/laravel/DAR/SmartReport` theo đúng chuẩn Conventional Commits ở cuối mỗi Cụm, đảm bảo `git status --short` sạch hoàn toàn trước khi chuyển sang Cụm tiếp theo.

---

## 2. Kiến Trúc Kết Nối DuckDB 2.0 & 4 Kiểu `SELECT` Đa Nguồn

### 2.1. Cú Pháp Kết Nối 3 Hệ Quản Trị Trong DuckDB 2.0 (Đã Kiểm Chứng Thực Tế)
Luôn gọi `duckdb -batch -light-mode`:
1. **PostgreSQL (`pgsql`)**:
   - Attach: `LOAD postgres; ATTACH 'host=127.0.0.1 port=5432 user=etl_user password=etl_password dbname=source_db' AS pg_north (TYPE postgres, READ_ONLY);`
   - Truy vấn bảng: `SELECT * FROM pg_north.public.v_epcontract`
   - Truy vấn passthrough SQL gốc: `SELECT * FROM postgres_query('pg_north', 'SELECT ...')`
2. **SQL Server (`sqlsrv`)**:
   - Attach: `LOAD mssql; ATTACH 'Server=127.0.0.1,1433;Database=erp_production;User Id=sa;Password=DevPassword!123;TrustServerCertificate=yes' AS mssql_erp (TYPE mssql);`
   - Truy vấn bảng: `SELECT * FROM mssql_erp.dbo.production_orders`
   - Truy vấn passthrough T-SQL gốc: `SELECT * FROM mssql_scan('mssql_erp', 'SELECT ...')`
3. **Oracle (`oracle`)**:
   - Secret & Attach:
     ```sql
     LOAD oracle_scanner;
     CREATE SECRET __sec_oracle_hr (TYPE oracle, DSN '127.0.0.1:1521/FREEPDB1', USER 'app', PASSWORD 'app');
     ATTACH '__sec_oracle_hr' AS oracle_hr (TYPE oracle_scanner);
     ```
   - Truy vấn bảng: `SELECT * FROM oracle_hr.APP.EMPLOYEES`
   - Truy vấn passthrough Oracle SQL gốc: `SELECT * FROM oracle_query('__sec_oracle_hr', 'SELECT ...')`

### 2.2. Bốn Kiểu `SELECT` Lên Các Loại DB Được Thiết Kế & Kiểm Thử (Không Dùng SQL Cũ Của DAR)
1. **Kiểu 1 — Single-DB Select (Truy vấn đơn nguồn trên từng loại DB: `pgsql`, `sqlsrv`, `oracle`)**:
   - Chạy qua bảng `ATTACH` hoặc hàm passthrough (`postgres_query`, `mssql_scan`, `oracle_query`) có bind tham số `@param`.
2. **Kiểu 2 — Multi-DB Selector `UNION ALL` (Chọn nhiều DB cùng cấu trúc trên giao diện)**:
   - Ví dụ: Báo cáo hợp đồng xuất khẩu cho phép chọn 1 hoặc gộp cả 2 DB PostgreSQL (`pg_north` cổng `5432` và `pg_south` cổng `5433`), tự động gắn cột `_source_db` để hiển thị chung trên 1 bảng.
3. **Kiểu 3 — Cross-Engine 2-DB `JOIN` (Nối dữ liệu giữa 2 hệ quản trị khác loại)**:
   - Ví dụ: Lấy bảng Nhân sự & Bậc lương từ **Oracle (`oracle_hr`)** `JOIN` với bảng Lệnh sản xuất & Giờ công máy từ **SQL Server (`mssql_erp`)**.
4. **Kiểu 4 — 3-Way Enterprise Federation (`Oracle` + `SQL Server` + `PostgreSQL` CTE / Aggregation)**:
   - Truy vấn tổng hợp CTE (`WITH ...`) kết hợp dữ liệu từ cả 3 hệ quản trị: Nhân sự/Phòng ban (**Oracle**) + Lệnh sản xuất (**SQL Server**) + Hợp đồng xuất hàng (**PostgreSQL North & South**), lọc theo `@StartDate`, `@EndDate`, `@Plant`, `@DeptCode`.

---

## 3. Chi Tiết 6 Cụm Công Việc (Work Clusters)

---

### Cụm 1: Thiết Lập Schema SQLite, Seed Dữ Liệu Thật Lên 4 DB (`Postgres`, `SQL Server`, `Oracle`) & Khởi Tạo Git
**Worker phụ trách**: `engine` (Skills: `laravel-best-practices`, `security-best-practices`) | **Verifier**: `verifier`

#### Mục tiêu
1. Đảm bảo Git repository tại `/home/v25050017/project/laravel/DAR/SmartReport` sạch sẽ.
2. Tạo cấu hình `config/smartreport.php` và các Migrations trên SQLite (`database/database.sqlite`) cho `data_sources`, `menu_groups`, `role_permissions`, `custom_views`, và phân quyền trên `users`.
3. Dùng chính DuckDB 2.0 (hoặc client CLI trong container) khởi tạo schema + nạp dữ liệu mẫu phong phú (tiếng Việt Unicode, `NUMERIC(18,2)`, `TIMESTAMP`, `NULL`) lên cả 4 DB đang chạy trong `docker-db` (`pg_north:5432`, `pg_south:5433`, `mssql_erp:1433`, `oracle_hr:1521`).
4. Seed 4 `DataSource`, các nhóm Menu, Roles, và **5 Custom Views mẫu đại diện cho 4 kiểu `SELECT`** vào `database/database.sqlite`.

#### Các bước thực hiện & Files
1. **[NEW] `config/smartreport.php`**:
   - `allowed_drivers`: `['sqlsrv' => 'SQL Server', 'pgsql' => 'PostgreSQL', 'oracle' => 'Oracle']`.
   - `duckdb_binary`: `env('DUCKDB_BINARY', 'duckdb')` (fallback `storage_path('bin/duckdb')`).
   - `snapshot_dir`: `storage_path('app/snapshots')`, `snapshot_ttl_minutes`: `30`, `page_size`: `10`.
2. **[NEW] Migrations & Models (`DataSource`, `MenuGroup`, `RolePermission`, `CustomView`, `User`)**:
   - `data_sources`: `code` (unique), `name`, `driver` (`sqlsrv|pgsql|oracle`), `host`, `port`, `database_name`, `service_name`, `default_schema`, `username`, `password` (encrypted cast), `options` (json), `is_read_only` (bool), `is_active` (bool).
   - `menu_groups`: `group_code` (unique), `title`, `icon`, `sort_order`, `roles` (json), `is_active` (bool).
   - `role_permissions`: `role_code` (unique), `role_name`, `description`, `is_system` (bool), `allowed_groups` (json), `allowed_views` (json), `allowed_sources` (json).
   - `custom_views`: `view_code` (unique), `view_name`, `menu_group_code`, `execution_mode` (`single_or_union` | `federated_join`), `allowed_sources` (json), `default_sources` (json), `source_queries` (json), `sql_text`, `filters` (json), `roles` (json), `description`, `version`, `is_active` (bool).
3. **[NEW] `app/Services/Database/ExternalTestDatabaseProvisioner.php` & `database/seeders/SmartReportSeeder.php`**:
   - Tự động khởi tạo bảng và dữ liệu mẫu trên 4 DB Docker:
     - **PostgreSQL #1 (`pg_north`, `127.0.0.1:5432`, db `source_db`)**: Bảng `public.v_epcontract` & `public.v_epcontract_detail` (25 hợp đồng nhà máy `NORTH`).
     - **PostgreSQL #2 (`pg_south`, `127.0.0.1:5433`, db `app`)**: Bảng `public.v_epcontract` & `public.v_epcontract_detail` (20 hợp đồng nhà máy `SOUTH`).
     - **SQL Server (`mssql_erp`, `127.0.0.1:1433`, db `erp_production`)**: Bảng `dbo.production_orders` (`order_no`, `contractno`, `plant`, `emp_no`, `machine_code`, `output_qty`, `defect_qty`, `unit_cost`, `shift_date`) & `dbo.machine_logs` (35 dòng).
     - **Oracle (`oracle_hr`, `127.0.0.1:1521/FREEPDB1`, schema `APP`)**: Bảng `APP.EMPLOYEES` (`EMP_NO`, `FULL_NAME`, `DEPT_CODE`, `PLANT`, `BASE_SALARY`, `HIRE_DATE`, `STATUS`) & `APP.DEPARTMENTS` (`DEPT_CODE`, `DEPT_NAME`, `COST_CENTER`) (30 nhân viên).
   - Seed 5 Custom Views mẫu vào SQLite:
     1. `pg-multi-plant-contracts` (`single_or_union` trên `[pg_north, pg_south]`): Gộp hợp đồng 2 nhà máy Bắc & Nam.
     2. `mssql-production-orders` (`single_or_union` trên `[mssql_erp]`): Tra cứu lệnh sản xuất trên SQL Server.
     3. `oracle-hr-directory` (`single_or_union` trên `[oracle_hr]`): Tra cứu nhân sự & lương trên Oracle.
     4. `join-oracle-mssql-productivity` (`federated_join` nối `oracle_hr` + `mssql_erp`): Hiệu suất sản xuất theo nhân viên & phòng ban.
     5. `federated-3way-executive-report` (`federated_join` nối `pg_north` + `pg_south` + `mssql_erp` + `oracle_hr`): Báo cáo tổng hợp điều hành xuyên 3 hệ quản trị DB.

#### Kiểm tra nghiệm thu (Acceptance Checks) & Commit Gate Cụm 1
```bash
php artisan migrate:fresh --seed --no-interaction
vendor/bin/pint --dirty --format agent
php artisan test --filter=Cluster1EnvironmentAndSeedTest
git add -A && git commit -m "feat(cluster-1): setup sqlite schema, provision postgres/mssql/oracle test data and seed 5 multi-db views"
```

---

### Cụm 2: Xây Dựng DuckDB Multi-DB Federation Engine, Snapshot Pagination & Bộ Export Chuẩn `.xlsx` / `.csv`
**Worker phụ trách**: `engine` (Skills: `laravel-best-practices`, `security-best-practices`, `security-threat-model`) | **Verifier**: `verifier` (Skills: `testing-best-practices`, `phpunit-skill`)

#### Mục tiêu
Xây dựng lõi thực thi truy vấn của SmartReport qua DuckDB 2.0 hỗ trợ kết nối tới **SQL Server, PostgreSQL, Oracle**, hỗ trợ cả 4 kiểu `SELECT`, cơ chế lưu Snapshot phân trang 10 dòng/trang, và nâng cấp `DuckDbExporter` xuất chính xác **Excel (`.xlsx` OpenXML)** và **CSV (`.csv` UTF-8 BOM)** (có tùy chọn chia part `.zip`).

#### Các bước thực hiện & Files
1. **[NEW] `app/Services/Sql/SqlParameterExtractor.php` & `app/Services/Sql/SqlSafetyValidator.php`**:
   - `SqlParameterExtractor`: Tự động nhận diện các tham số `@param` trong `sql_text` và `source_queries[*].sql_text`, gợi ý kiểu ô nhập (`date` với `@today`, `number`, `text`, `select`).
   - `SqlSafetyValidator`: Chỉ cho phép câu lệnh đọc (`SELECT` / `WITH`), chặn mọi từ khóa ghi/hệ thống (`INSERT`, `UPDATE`, `DELETE`, `DROP`, `ALTER`, `TRUNCATE`, `EXEC`, `COPY`, `ATTACH`, `INSTALL`, `LOAD`, `read_csv`, `read_parquet`...) trong câu SQL do user nhập.
2. **[NEW] `app/Services/DuckDb/DuckDbSourceAdapter.php`**:
   - Sinh lệnh `ATTACH` / `CREATE SECRET` và thực thi truy vấn đọc dữ liệu cho 3 loại driver (`pgsql`, `sqlsrv`, `oracle`).
   - Hỗ trợ bind tham số `@param` an toàn (bao gồm xử lý `@today` và giá trị `NULL` cho mệnh đề `(@Param IS NULL OR col = @Param)`).
   - Hỗ trợ cả truy vấn qua bảng `ATTACH` (`{alias}.{schema}.{table}`) lẫn truy vấn passthrough (`postgres_query`, `mssql_scan`, `oracle_query`).
3. **[NEW] `app/Services/DuckDb/DuckDbReportEngine.php` & `app/Services/DuckDb/SnapshotManager.php`**:
   - **Tạo Snapshot (`executeAndCreateSnapshot`)**:
     1. Đọc dữ liệu từ các DB nguồn được chọn vào bảng tạm trong phiên DuckDB, sau đó `DETACH` các DB nguồn và khóa truy cập ngoài (`SET enable_external_access = false;`).
     2. Chạy câu lệnh tổng hợp (`UNION ALL` kèm cột `_source_db` cho `single_or_union`, hoặc câu lệnh `federated_join`) lưu vào bảng `report_result` (có cột `__row_id`) và `report_meta` trong file `storage/app/snapshots/{snapshotId}.duckdb`.
   - **Đọc phân trang (`getPage(string $snapshotId, int $page, int $perPage = 10)`)**:
     - Đọc trực tiếp từ file `{snapshotId}.duckdb` (`LIMIT 11 OFFSET ?` để trả 10 dòng + cờ `hasNextPage` + `totalRows` từ `report_meta`). Không gọi lại PostgreSQL / SQL Server / Oracle khi chuyển trang!
4. **[NEW] `app/Services/Export/DuckDbExporter.php` (Chuẩn `.xlsx` OpenXML và `.csv` UTF-8 BOM)**:
   - **Excel chuẩn `.xlsx` (`exportExcel`)**:
     - Dùng core extension `excel` của DuckDB 2.0:
       `LOAD excel; COPY (SELECT * EXCLUDE (__row_id) FROM report_result ORDER BY __row_id) TO '/path/file.xlsx' WITH (FORMAT xlsx, HEADER true, SHEET 'SmartReport');`
   - **CSV chuẩn UTF-8 BOM (`exportCsv`)**:
     - Dùng `COPY (...) TO '/path/file.csv' WITH (FORMAT csv, HEADER true, DELIMITER ',')` + tiền tố BOM `\xEF\xBB\xBF`.
   - **Chia nhỏ file (`.zip`) cho cả `.xlsx` và `.csv`**:
     - Khi `splitFile = true` và `totalRows > splitRows`, tự động chia thành `_part1.xlsx`, `_part2.xlsx` (hoặc `_part1.csv`, `_part2.csv`) và nén thành `.zip`.

#### Kiểm tra nghiệm thu (Acceptance Checks) & Commit Gate Cụm 2
Viết bộ test toàn diện tại `tests/Feature/MultiDbEngineAndExporterTest.php` kiểm chứng cả 4 kiểu `SELECT`, phân trang Snapshot (0 query lại nguồn ở trang 2), và kiểm tra cấu trúc binary OpenXML `.xlsx` (đọc lại bằng `read_xlsx` trong DuckDB khớp 100% dữ liệu) + `.csv` + `.zip`:
```bash
vendor/bin/pint --dirty --format agent
php artisan test --filter=MultiDbEngineAndExporterTest
git add -A && git commit -m "feat(cluster-2): implement duckdb multi-db engine for pgsql/sqlsrv/oracle, snapshot paging and real xlsx/csv exporter"
```

---

### Cụm 3: Hệ Thống Quản Trị Filament: Data Sources (`sqlsrv`, `pgsql`, `oracle`), Menu Groups & Role Permissions
**Worker phụ trách**: `admin-ui` (Skills: `laravel-best-practices`, `tailwindcss-development`, `security-best-practices`) | **Verifier**: `verifier`

#### Mục tiêu
Xây dựng Filament Admin Panel đọc/ghi cấu hình trên SQLite, thanh Sidebar tự động đồng bộ từ `MenuGroup` + `CustomView` theo quyền của user, và 3 Filament Resources quản trị: **Quản lý Kết nối DB (`DataSourceResource`)**, **Quản lý Nhóm Menu (`MenuGroupResource`)**, **Phân quyền Vai trò (`RolePermissionResource`)**.

#### Các bước thực hiện & Files
1. **[MODIFY] `app/Providers/Filament/AdminPanelProvider.php` & [NEW] `app/Services/Navigation/DynamicSidebarBuilder.php`**:
   - Tự động hiển thị các nhóm menu và các `CustomView` trên thanh Sidebar theo quyền của user đăng nhập.
2. **[NEW] `app/Filament/Resources/DataSourceResource.php`**:
   - Ô chọn `driver` giới hạn đúng 3 loại kết nối được phép: `sqlsrv` (SQL Server), `pgsql` (PostgreSQL), `oracle` (Oracle Database).
   - Nút Action **"Kiểm tra kết nối (Test Connection)"**: Dùng DuckDB thử kết nối trực tiếp tới SQL Server / PostgreSQL / Oracle và hiển thị thông báo kết quả kèm độ trễ (ms).
3. **[NEW] `app/Filament/Resources/MenuGroupResource.php` & `app/Filament/Resources/RolePermissionResource.php`**:
   - Quản lý nhóm menu và phân quyền truy cập (`allowed_groups`, `allowed_views`, `allowed_sources`).

#### Kiểm tra nghiệm thu (Acceptance Checks) & Commit Gate Cụm 3
```bash
vendor/bin/pint --dirty --format agent
php artisan test --filter=FilamentAdminResourcesTest
git add -A && git commit -m "feat(cluster-3): add filament resources for sqlsrv/pgsql/oracle data sources, menu groups and role permissions"
```

---

### Cụm 4: Custom View Builder (`CustomViewResource`) Hỗ Trợ Đa Nguồn, Auto-Detect `@Param` & Test SQL 10 Dòng
**Worker phụ trách**: `admin-ui` (Skills: `laravel-best-practices`, `tailwindcss-development`, `security-best-practices`) | **Verifier**: `verifier`

#### Mục tiêu
Xây dựng màn hình thiết kế báo cáo SQL động (`CustomViewResource`) lưu vào SQLite, hỗ trợ cấu hình chọn nhiều DB (`single_or_union`) hoặc JOIN nhiều DB khác hệ quản trị (`federated_join`), tích hợp nút **"Tự động nhận diện @tham số"** và **"Chạy thử SQL (Preview 10 dòng)"** thực thi trực tiếp qua DuckDB lên các DB PostgreSQL / SQL Server / Oracle.

#### Các bước thực hiện & Files
1. **[NEW] `app/Filament/Resources/CustomViewResource.php` (+ Pages)**:
   - Cấu hình `execution_mode` (`single_or_union` với `allowed_sources` & `default_sources`, hoặc `federated_join` với Repeater `source_queries`).
   - Action **"Tự động nhận diện @tham số"**: Quét câu SQL và tự động thêm dòng vào Repeater `filters` (`param_name`, `label`, `input_type`: `text|date|number|select`, `default_value`, `is_required`, `options`).
   - Action **"Chạy thử SQL (Test)"**: Chạy thử qua DuckDB và hiển thị ngay bảng xem trước **10 dòng đầu tiên**, tổng số dòng, danh sách cột và thời gian thực thi.

#### Kiểm tra nghiệm thu (Acceptance Checks) & Commit Gate Cụm 4
```bash
vendor/bin/pint --dirty --format agent
php artisan test --filter=CustomViewBuilderTest
git add -A && git commit -m "feat(cluster-4): implement filament custom view builder with multi-db modes, param detector and 10-row preview"
```

---

### Cụm 5: Màn Hình Chạy Báo Cáo (`ReportRunnerPage` 3-Tab UI) & Trang Xuất File (`SqlExportPage` Chuẩn `.xlsx` / `.csv`)
**Worker phụ trách**: `report-ui` (Skills: `laravel-best-practices`, `tailwindcss-development`) | **Verifier**: `verifier`

#### Mục tiêu
Xây dựng màn hình hiển thị báo cáo động (`ReportRunnerPage` tại `/admin/reports/{viewCode}`) với bộ chọn nhiều DB nguồn trực tiếp trên giao diện, 3 Tab thông tin (`Tổng quan`, `Application information`, `SQL Query`), phân trang 10 dòng từ DuckDB Snapshot, và nút xuất chính xác 2 định dạng **Excel (`.xlsx`)** và **CSV (`.csv`)** cùng trang `SqlExportPage`.

#### Các bước thực hiện & Files
1. **[NEW] `app/Filament/Pages/ReportRunnerPage.php` & `resources/views/filament/pages/report-runner-page.blade.php`**:
   - **Tab 1 — `Tổng quan (Overview)`**:
     - Ô **Chọn Database hiển thị (Multi-Select / Checkboxes)** + các ô điều kiện lọc động + nút **Tra cứu**, **Xuất Excel (`.xlsx`)**, **Xuất CSV (`.csv`)**, **Làm mới**.
     - Lưới dữ liệu động hiển thị 10 dòng/trang từ DuckDB Snapshot, badge hiển thị `_source_db`, thanh chuyển trang không gọi lại DB nguồn.
   - **Tab 2 — `Application information`**: Hiển thị `UI Name`, `UI ID`, `Version`, `Execution Mode`, các DB nguồn (`PostgreSQL`, `SQL Server`, `Oracle`) kèm timestamp đọc dữ liệu và mã Snapshot.
   - **Tab 3 — `SQL Query` (Live SQL Inspector)**: Tự động cập nhật `Use Criteria`, `@Parameters`, câu SQL nguồn và câu SQL liên hợp trong DuckDB.
2. **[NEW] `app/Filament/Pages/SqlExportPage.php` & `resources/views/filament/pages/sql-export-page.blade.php`**:
   - Cho phép chọn DB nguồn (`PostgreSQL`, `SQL Server`, `Oracle` hoặc liên hợp), nhập câu lệnh `SELECT / WITH`, chọn định dạng **Excel (`.xlsx`)** hoặc **CSV (`.csv`)**, và bật/tắt chia nhỏ file `.zip` (`10 dòng (Test)`, `100.000 dòng`, `500.000 dòng`, `1.000.000 dòng`).

#### Kiểm tra nghiệm thu (Acceptance Checks) & Commit Gate Cụm 5
```bash
vendor/bin/pint --dirty --format agent
php artisan test --filter=ReportRunnerAndSqlExportTest
git add -A && git commit -m "feat(cluster-5): build multi-db report runner page with 3-tab inspector and xlsx/csv export pages"
```

---

### Cụm 6: Kiểm Thử Trình Duyệt End-to-End Bằng Playwright CLI / Chrome MCP & Nghiệm Thu
**Worker phụ trách**: `verifier` (Skills: `playwright-cli`, `testing-best-practices`, `phpunit-skill`)

#### Mục tiêu
Kiểm thử toàn diện ứng dụng đang chạy thật trên trình duyệt bằng **Playwright (`npx playwright test` & `playwright-cli`)** / **Chrome MCP**, kiểm chứng kết nối thực tế tới cả **PostgreSQL, SQL Server, Oracle**, kiểm chứng cả 4 kiểu `SELECT` đa nguồn và kiểm chứng file tải về đúng chuẩn **`.xlsx`** và **`.csv`**.

#### Các kịch bản kiểm thử E2E bắt buộc (`tests/e2e/smartreport.spec.ts`)
1. **E2E 1 — Kiểm tra Kết nối 3 Hệ Quản Trị (`PostgreSQL`, `SQL Server`, `Oracle`)**:
   - Đăng nhập `/admin`, mở `Data Sources`, xác nhận chỉ cho phép 3 driver (`sqlsrv`, `pgsql`, `oracle`) và bấm `Test Connection` thành công trên cả 4 nguồn (`pg_north`, `pg_south`, `mssql_erp`, `oracle_hr`).
2. **E2E 2 — Tạo Custom View Mới Với Auto-Detect `@Param` & Preview 10 Dòng**:
   - Tạo View mới trong `Custom Views`, nhập SQL có `@StartDate`, `@Plant`, bấm **"Tự động nhận diện @tham số"**, bấm **"Chạy thử SQL (Test)"** xem trước 10 dòng, lưu lại và xác nhận xuất hiện trên Sidebar.
3. **E2E 3 — Thử Nghiệm 4 Cách `SELECT` Lên Các Loại DB Trên `ReportRunnerPage`**:
   - **Cách 1 (Single-DB trên SQL Server & Oracle)**: Mở `mssql-production-orders` và `oracle-hr-directory`, kiểm tra lọc và hiển thị 10 dòng/trang.
   - **Cách 2 (Multi-DB `UNION ALL` trên 2 DB PostgreSQL)**: Mở `pg-multi-plant-contracts`, chọn `pg_north` (25 dòng) rồi chọn gộp `pg_north` + `pg_south` (45 dòng, có badge `_source_db`), chuyển sang Trang 2 từ Snapshot.
   - **Cách 3 (Cross-Engine 2-DB `JOIN` Oracle + SQL Server)**: Mở `join-oracle-mssql-productivity`, kiểm tra kết quả `JOIN` giữa Oracle và SQL Server.
   - **Cách 4 (3-Way Federation `Oracle + SQL Server + PostgreSQL`)**: Mở `federated-3way-executive-report`, kiểm tra kết quả tổng hợp xuyên 3 hệ DB và kiểm tra 2 tab `Application information` & `SQL Query`.
4. **E2E 4 — Kiểm Tra Tải File Xuất Chuẩn `.xlsx` và `.csv` (+ Chia nhỏ `.zip`)**:
   - Bấm **Xuất Excel (`.xlsx`)** và **Xuất CSV (`.csv`)**, xác nhận file `.xlsx` tải về đọc lại được bằng DuckDB `read_xlsx` và file `.csv` / `.zip` đúng dữ liệu.
5. **Chụp ảnh màn hình nghiệm thu** bằng `playwright-cli` / Chrome MCP lưu vào `docs/screenshots/`.

#### Kiểm tra nghiệm thu (Acceptance Checks) & Commit Gate Cụm 6
```bash
php artisan test
npx playwright test
git status --short
git add -A && git commit -m "test(cluster-6): verify multi-db select patterns and xlsx/csv exports with playwright e2e suite"
```

---

## 4. Tóm Tắt Lộ Trình Git Commits (Dành Cho `/teamwork` Theo Dõi)

| Thứ tự | Cụm công việc | Commit Message chuẩn |
| :---: | :--- | :--- |
| **0** | Baseline: Laravel 13 + Filament 5 + Laravel Boost + DuckDB 2.0 + Skills & Plan | `chore: initialize laravel 13, filament 5, laravel boost, duckdb 2.0 and project plan` |
| **1** | Cụm 1: SQLite Schema + Seed Dữ Liệu Thật Lên 4 DB (`pgsql`, `sqlsrv`, `oracle`) | `feat(cluster-1): setup sqlite schema, provision postgres/mssql/oracle test data and seed 5 multi-db views` |
| **2** | Cụm 2: DuckDB Multi-DB Engine + Snapshot Pagination + Chuẩn `.xlsx` / `.csv` Exporter | `feat(cluster-2): implement duckdb multi-db engine for pgsql/sqlsrv/oracle, snapshot paging and real xlsx/csv exporter` |
| **3** | Cụm 3: Filament Admin Resources (`DataSourceResource` cho 3 hệ DB, Menu, Role) | `feat(cluster-3): add filament resources for sqlsrv/pgsql/oracle data sources, menu groups and role permissions` |
| **4** | Cụm 4: Custom View Builder + Auto-detect `@param` + Live 10-Row Preview | `feat(cluster-4): implement filament custom view builder with multi-db modes, param detector and 10-row preview` |
| **5** | Cụm 5: Report Runner (Multi-DB Selector, 3-Tab UI) & SQL Export (`.xlsx` / `.csv` / `.zip`) | `feat(cluster-5): build multi-db report runner page with 3-tab inspector and xlsx/csv export pages` |
| **6** | Cụm 6: Playwright / Chrome MCP E2E Verification trên cả 3 hệ DB & 2 định dạng Export | `test(cluster-6): verify multi-db select patterns and xlsx/csv exports with playwright e2e suite` |
