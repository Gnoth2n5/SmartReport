# Kế Hoạch Triển Khai SmartReport (DAR Next-Gen): Laravel + Filament + Multi-DB (SQL Server, PostgreSQL, Oracle) + DuckDB

> **Mục tiêu**: Xây dựng ứng dụng **SmartReport** tại `/home/v25050017/project/laravel/DAR/SmartReport` bằng **Laravel + Filament + DuckDB 2.0**:
> - **SQLite nội bộ (`database/database.sqlite`)**: Lưu toàn bộ cấu hình hệ thống, danh sách kết nối DB, phân quyền Menu/Role và các định nghĩa Custom View SQL.
> - **3 Hệ quản trị DB đích được phép kết nối**: **SQL Server (`sqlsrv`)**, **PostgreSQL (`pgsql`)**, và **Oracle (`oracle`)** — kết nối trực tiếp qua các native extensions của DuckDB 2.0 (`mssql`, `postgres`, `oracle_scanner`) mà không phụ thuộc driver PDO/ODBC rời trên máy chủ.
> - **Không dùng lại các câu SQL cũ của DAR**: Tận dụng 2 container PostgreSQL đang chạy sẵn trên WSL (`etl_postgres:5432`, `dev-postgres:5433`) và khởi tạo thêm container Docker cho **SQL Server 2022** (`1433`) và **Oracle 23ai Free** (`1521`) để kiểm thử đa dạng các kiểu `SELECT` (Single-DB, Multi-DB `UNION ALL`, Cross-Engine 2-DB `JOIN`, 3-Way Federation `Oracle + SQL Server + PostgreSQL`, và Native Dialect Passthrough).
> - **Nâng cấp bộ Export**: Xuất chính xác 2 định dạng chuẩn công nghiệp là **Excel thật (`.xlsx` OpenXML qua DuckDB `excel` extension)** và **CSV chuẩn UTF-8 BOM (`.csv`)**, hỗ trợ tự động chia nhỏ file và nén `.zip` khi dữ liệu lớn.

---

## 1. Quy Tắc Điều Phối `/teamwork` (Swarm Execution & Subagent Reuse)

Khi chạy lệnh `/teamwork` dựa trên kế hoạch này, **Coordinator** bắt buộc tuân thủ các nguyên tắc sau:

### 1.1. Roster Agent Tinh Gọn & Tái Sử Dụng (Subagent Reuse)
Không tạo mới rồi `kill` subagent/worker qua từng bước nhỏ. Khởi tạo **4 worker chuyên trách** và **tái sử dụng xuyên suốt 6 Cụm công việc (Clusters)** bằng cách giao việc tiếp qua `@tên-worker` trên message board (hoặc `send_message`):

| Tên Worker (`name`) | Vai trò xuyên suốt | Tái sử dụng ở các Cụm |
| :--- | :--- | :--- |
| `engine` | Phụ trách khởi tạo Laravel + SQLite config DB, Docker DBs (`mssql`, `oracle` + kết nối `postgres` có sẵn), cài đặt DuckDB 2.0 + extensions (`postgres`, `mssql`, `oracle_scanner`, `excel`), xây dựng `DuckDbReportEngine`, `SnapshotManager`, `DuckDbExporter` (`.xlsx` & `.csv`) và Unit/Integration Tests. | Cụm 1, Cụm 2, Cụm 5 |
| `admin-ui` | Phụ trách Filament Admin Panel, Auth/RBAC, Dynamic Sidebar Navigation, `DataSourceResource` (chỉ cho phép 3 loại: `sqlsrv`, `pgsql`, `oracle`), `MenuGroupResource`, `RolePermissionResource`, `CustomViewResource` (Builder + Auto-detect `@param` + Test SQL 10 dòng). | Cụm 3, Cụm 4 |
| `report-ui` | Phụ trách trang chạy báo cáo động `ReportRunnerPage` (Multi-DB Selector, 3-Tab Header: `Tổng quan` / `Application Information` / `SQL Query`, phân trang 10 dòng từ DuckDB Snapshot) và `SqlExportPage` (xuất `.xlsx` / `.csv` + chia part `.zip`). | Cụm 4, Cụm 5 |
| `verifier` | Phụ trách kiểm tra độc lập từng Cụm (chạy PHPUnit/Artisan test, kiểm tra cấu trúc file `.xlsx`/`.csv`, kiểm tra `git status`/`git diff`) và chạy **Playwright CLI / Chrome MCP E2E Tests** trên trình duyệt thật ở Cụm 6. | Cụm 1 $\rightarrow$ Cụm 6 |

> [!IMPORTANT]
> **Quy tắc tránh xung đột file**: Ở mỗi Cụm công việc, các file được phân chia rõ theo allowlist cho từng worker. Hai worker không bao giờ sửa chung 1 file tại cùng một thời điểm.

### 1.2. Cổng Kiểm Duyệt & Git Commit Bắt Buộc Sau Mỗi Cụm Công Việc (Commit Gate)
Mỗi Cụm công việc (Work Cluster) chỉ được coi là hoàn tất (`[done]`) khi:
1. Toàn bộ lệnh kiểm thử (Acceptance Checks) của Cụm đó chạy `PASS` (0 lỗi, 0 test bị skip).
2. `verifier` xác nhận kết quả trên board.
3. Thực hiện **Git Commit** ngay tại `/home/v25050017/project/laravel/DAR/SmartReport` theo đúng chuẩn Conventional Commits ở cuối mỗi Cụm, đảm bảo `git status --short` sạch hoàn toàn trước khi chuyển sang Cụm tiếp theo.

---

## 2. Kiến Trúc Tổng Thể & 4 Kiểu Truy Vấn Đa Nguồn

```mermaid
flowchart LR
    subgraph AppConfig["SQLite Config DB (database.sqlite)"]
        CfgTables["users | data_sources\nmenu_groups | role_permissions\ncustom_views (SQL & Filters)"]
    end

    subgraph UI["Laravel + Filament UI"]
        Builder["CustomViewResource\n(SQL + @Param Detector + 10-Row Preview)"]
        Sources["DataSourceResource\n(Driver: sqlsrv | pgsql | oracle)"]
        Runner["ReportRunnerPage\n(Multi-DB Selector + 3-Tab UI + 10-Row Grid)"]
        Exporter["DuckDbExporter & SqlExportPage\n(Real .xlsx OpenXML & UTF-8 .csv + Split .zip)"]
    end

    subgraph DuckDB["DuckDB 2.0 Engine & Extensions"]
        Ext["Extensions:\n- postgres (Core)\n- mssql (Community TDS)\n- oracle_scanner (Community TNS)\n- excel (Core XLSX)"]
        Snapshot["Snapshot Storage\n(storage/app/snapshots/*.duckdb)"]
    end

    subgraph WSL_DBs["WSL / Docker Enterprise Databases"]
        PG1[("PostgreSQL #1 (etl_postgres:5432)\ndb: source_db (Plant North)")]
        PG2[("PostgreSQL #2 (dev-postgres:5433)\ndb: app (Plant South)")]
        MSSQL[("SQL Server 2022 (Docker:1433)\ndb: erp_production")]
        ORA[("Oracle 23ai Free (Docker:1521)\nservice: FREEPDB1 (HR & Finance)")]
    end

    CfgTables <--> UI
    Builder --> Ext
    Runner --> Ext
    Ext -->|ATTACH TYPE postgres / postgres_query| PG1
    Ext -->|ATTACH TYPE postgres / postgres_query| PG2
    Ext -->|ATTACH TYPE mssql| MSSQL
    Ext -->|ATTACH TYPE oracle_scanner / oracle_query| ORA
    Ext -->|Materialize & Lock External Access| Snapshot
    Snapshot -->|LIMIT 11 OFFSET k (10 rows/page)| Runner
    Snapshot -->|COPY TO FORMAT xlsx / csv| Exporter
```

### 4 Kiểu `SELECT` Lên Các Loại DB Được Thiết Kế & Kiểm Thử
1. **Kiểu 1 — Single-DB Select (Truy vấn đơn nguồn trên từng loại DB: `pgsql`, `sqlsrv`, `oracle`)**:
   - Truy vấn trực tiếp qua catalog đã `ATTACH` (VD: `SELECT * FROM db.schema.table WHERE ...`) hoặc qua hàm **Native SQL Passthrough** (`postgres_query`, `oracle_query`) có bind tham số `@param`.
2. **Kiểu 2 — Multi-DB Selector `UNION ALL` (Chọn nhiều DB cùng cấu trúc trên giao diện)**:
   - Ví dụ: Báo cáo hợp đồng & sản lượng cho phép chọn 1 hoặc gộp cả 2 DB PostgreSQL (`pg_north` tại cổng `5432` và `pg_south` tại cổng `5433`), tự động gắn cột `_source_db` để so sánh/hiển thị chung trên 1 lưới dữ liệu.
3. **Kiểu 3 — Cross-Engine 2-DB `JOIN` (Nối dữ liệu giữa 2 hệ quản trị khác loại)**:
   - Ví dụ: Lấy danh sách Nhân sự & Bậc lương từ **Oracle (`ora_hr`)** `JOIN` với Nhật ký Lệnh sản xuất & Giờ công máy từ **SQL Server (`mssql_erp`)**.
4. **Kiểu 4 — 3-Way Enterprise Federation (`Oracle` + `SQL Server` + `PostgreSQL` CTE / Aggregation)**:
   - Truy vấn tổng hợp sử dụng CTE (`WITH ...`) kết hợp dữ liệu từ cả 3 hệ quản trị: Nhân sự/Phòng ban (**Oracle**) + Lệnh sản xuất (**SQL Server**) + Hợp đồng xuất hàng (**PostgreSQL North & South**), lọc theo `@StartDate`, `@EndDate`, `@Plant`, `@DeptCode`.

---

## 3. Chi Tiết 6 Cụm Công Việc (Work Clusters)

---

### Cụm 1: Khởi Tạo Git, Laravel + SQLite Config, Cài Đặt DuckDB 2.0 + Extensions & Môi Trường Multi-DB (Postgres, SQL Server, Oracle)
**Worker phụ trách**: `engine` | **Verifier**: `verifier`

#### Mục tiêu
1. Khởi tạo Git repository tại `/home/v25050017/project/laravel/DAR/SmartReport`.
2. Khởi tạo Laravel + Filament sử dụng **SQLite (`database/database.sqlite`)** để lưu cấu hình ứng dụng, danh sách nguồn DB, phân quyền và các câu lệnh SQL của Custom View.
3. Cài đặt **DuckDB 2.0 CLI** vào `storage/bin/duckdb` và cài sẵn 4 extensions: `postgres` (core), `excel` (core), `mssql` (community), `oracle_scanner` (community).
4. Tận dụng 2 container PostgreSQL có sẵn trong WSL (`etl_postgres` cổng `5432`, `dev-postgres` cổng `5433`) và khởi tạo `docker-compose.test-dbs.yml` cho **SQL Server 2022** (cổng `1433`) và **Oracle 23ai Free** (cổng `1521`), nạp dữ liệu mẫu phong phú (tiếng Việt Unicode, số thập phân `NUMERIC(18,2)`, ngày giờ `TIMESTAMP`, `NULL`) trên cả 4 database thuộc 3 hệ quản trị.

#### Các bước thực hiện & Files
1. **[NEW] Khởi tạo Git & Laravel + Filament Project tại `/home/v25050017/project/laravel/DAR/SmartReport`**:
   - Giữ nguyên `.agents/`, `.planning/`, `FEASIBILITY.md`.
   - Cấu hình `.env` sử dụng `DB_CONNECTION=sqlite` (`database/database.sqlite`) cho toàn bộ bảng cấu hình của SmartReport.
   - Cấu hình `.gitignore` (bỏ qua `/vendor`, `/node_modules`, `/storage/app/snapshots/*.duckdb`, `/storage/app/exports/*`, `/storage/bin/duckdb`, `.playwright-cli`).
2. **[NEW] `scripts/install-duckdb.sh` & `config/smartreport.php`**:
   - Tải DuckDB v2.0.0 CLI (`duckdb_cli-linux-amd64.zip`) vào `storage/bin/duckdb` và chạy lệnh cài đặt sẵn các extensions:
     ```sql
     INSTALL postgres;
     INSTALL excel;
     INSTALL mssql FROM community;
     INSTALL oracle_scanner FROM community;
     ```
   - Cấu hình `config/smartreport.php`:
     - `allowed_drivers`: `['sqlsrv' => 'SQL Server', 'pgsql' => 'PostgreSQL', 'oracle' => 'Oracle']` (chỉ cho phép đúng 3 loại DB kết nối theo yêu cầu).
     - `duckdb_binary`: `storage_path('bin/duckdb')`.
     - `snapshot_dir`: `storage_path('app/snapshots')`, `snapshot_ttl_minutes`: `30`, `page_size`: `10`.
3. **[NEW] `docker-compose.test-dbs.yml` & `scripts/setup-test-databases.sh`**:
   - Khai báo và khởi chạy 2 container bổ sung bên cạnh 2 container Postgres đang chạy sẵn (`etl_postgres:5432`, `dev-postgres:5433`):
     - `smartreport_mssql`: `mcr.microsoft.com/mssql/server:2022-latest` (Port `1433`, User `sa`, Pass `SmartReport!2026`, DB `erp_production`).
     - `smartreport_oracle`: `gvenzl/oracle-free:23-slim-faststart` (Port `1521`, Service `FREEPDB1`, User `hr_user`, Pass `hr_password`).
   - Script `scripts/setup-test-databases.sh` nạp dữ liệu mẫu đồng bộ giữa các DB để test 4 kiểu SELECT:
     - **PostgreSQL #1 (`etl_postgres:5432`, db `source_db` — Plant `NORTH`)**: Bảng `v_epcontract` & `v_epcontract_detail` (25 hợp đồng & chi tiết lô hàng của nhà máy miền Bắc).
     - **PostgreSQL #2 (`dev-postgres:5433`, db `app` — Plant `SOUTH`)**: Bảng `v_epcontract` & `v_epcontract_detail` (20 hợp đồng & chi tiết lô hàng của nhà máy miền Nam).
     - **SQL Server (`smartreport_mssql:1433`, db `erp_production`)**: Bảng `dbo.production_orders` (lệnh sản xuất gắn với `contractno`, `plant`, `emp_no`, `machine_code`, `output_qty`, `defect_qty`, `shift_date`) và `dbo.machine_logs` (35 bản ghi).
     - **Oracle (`smartreport_oracle:1521/FREEPDB1`, schema `HR_USER`)**: Bảng `HR_USER.EMPLOYEES` (`EMP_NO`, `FULL_NAME`, `DEPT_CODE`, `PLANT`, `BASE_SALARY`, `HIRE_DATE`, `STATUS`) và `HR_USER.DEPARTMENTS` (`DEPT_CODE`, `DEPT_NAME`, `COST_CENTER`) (30 nhân viên).
4. **[NEW] Migrations & `database/seeders/SmartReportSeeder.php` trên SQLite**:
   - Tạo các bảng cấu hình trong `database/database.sqlite`:
     - `data_sources`: `code`, `name`, `driver` (`enum: sqlsrv, pgsql, oracle`), `host`, `port`, `database_name`, `service_name`, `username`, `password` (encrypted), `default_schema`, `options` (json), `is_read_only` (default `true`), `is_active` (default `true`).
     - `menu_groups`: `group_code`, `title`, `icon`, `sort_order`, `roles` (json), `is_active`.
     - `role_permissions`: `role_code`, `role_name`, `description`, `is_system`, `allowed_groups` (json), `allowed_views` (json), `allowed_sources` (json).
     - `custom_views`: `view_code`, `view_name`, `menu_group_code`, `execution_mode` (`single_or_union` | `federated_join`), `allowed_sources` (json), `default_sources` (json), `source_queries` (json), `sql_text`, `filters` (json), `roles` (json), `description`, `version`, `is_active`.
   - Seed 4 `DataSource` (`pg_north`, `pg_south`, `mssql_erp`, `oracle_hr`), các nhóm Menu (`contracts`, `production`, `hr_finance`, `federated_bi`, `admin`), Roles (`Admin`, `Analyst`, `PlantManager`), và **5 Custom Views mẫu đại diện cho các kiểu SELECT khác nhau** (không dùng SQL của DAR cũ):
     1. `pg-multi-plant-contracts`: Chế độ `single_or_union` trên `[pg_north, pg_south]` — cho phép chọn 1 hoặc cả 2 DB PostgreSQL để xem & gộp hợp đồng (`v_epcontract` JOIN `v_epcontract_detail`).
     2. `mssql-production-orders`: Truy vấn SQL Server (`mssql_erp`) lọc theo `@StartDate`, `@EndDate`, `@Plant`, `@MachineCode`.
     3. `oracle-hr-directory`: Truy vấn Oracle (`oracle_hr`) lấy danh sách nhân sự & quỹ lương theo `@DeptCode`, `@Plant`.
     4. `join-oracle-mssql-productivity`: Chế độ `federated_join` nối bảng Nhân sự từ **Oracle (`oracle_hr`)** với bảng Lệnh sản xuất từ **SQL Server (`mssql_erp`)** theo `EMP_NO`.
     5. `federated-3way-executive-report`: Chế độ `federated_join` kết hợp cả **3 hệ quản trị (`PostgreSQL` + `SQL Server` + `Oracle`)** tính tổng sản lượng, giá trị hợp đồng và nhân sự phụ trách theo từng lô hàng.

#### Kiểm tra nghiệm thu (Acceptance Checks) & Commit Gate Cụm 1
```bash
storage/bin/duckdb -c "SELECT extension_name, installed FROM duckdb_extensions() WHERE extension_name IN ('postgres', 'mssql', 'oracle_scanner', 'excel');"
php artisan migrate:fresh --seed
php artisan test --filter=Cluster1EnvironmentAndConnectionsTest
git add -A && git commit -m "feat(cluster-1): setup laravel sqlite config, duckdb 2.0 extensions and postgres/mssql/oracle test databases"
```

---

### Cụm 2: Xây Dựng DuckDB Multi-DB Federation Engine, Snapshot Pagination & Bộ Export Chuẩn `.xlsx` / `.csv`
**Worker phụ trách**: `engine` | **Verifier**: `verifier`

#### Mục tiêu
Xây dựng lõi thực thi truy vấn của SmartReport qua DuckDB 2.0 hỗ trợ kết nối tới **SQL Server, PostgreSQL, Oracle**, hỗ trợ nhiều phương thức `SELECT` (Catalog Attach, Native Passthrough `postgres_query`/`oracle_query`, Multi-DB `UNION ALL`, Cross-Engine `JOIN`), cơ chế lưu Snapshot phân trang 10 dòng/trang, và nâng cấp `DuckDbExporter` xuất chính xác **Excel (`.xlsx` OpenXML)** và **CSV (`.csv` UTF-8 BOM)** (có tùy chọn chia part `.zip`).

#### Các bước thực hiện & Files
1. **[NEW] `app/Services/Sql/SqlParameterExtractor.php` & `app/Services/Sql/SqlSafetyValidator.php`**:
   - `SqlParameterExtractor`: Tự động nhận diện các tham số `@param` trong `sql_text` và `source_queries[*].sql_text`, gợi ý kiểu ô nhập (`date` với `@today`, `number`, `text`, `select`).
   - `SqlSafetyValidator`: Đảm bảo chỉ cho phép câu lệnh đọc (`SELECT` / `WITH`), chặn mọi từ khóa DDL/DML (`INSERT`, `UPDATE`, `DELETE`, `DROP`, `ALTER`, `TRUNCATE`, `EXEC`, `COPY`, `ATTACH`, `INSTALL`, `LOAD`, `read_csv`, `read_parquet`...) trong câu SQL của người dùng.
2. **[NEW] `app/Services/DuckDb/DuckDbSourceAdapter.php`**:
   - Sinh lệnh kết nối an toàn trong phiên DuckDB cho 3 loại driver:
     - **PostgreSQL (`pgsql`)**:
       `LOAD postgres; ATTACH 'host=... port=... user=... password=... dbname=...' AS {alias} (TYPE postgres, READ_ONLY);`
     - **SQL Server (`sqlsrv`)**:
       `LOAD mssql; ATTACH 'Server=...,...;Database=...;User Id=...;Password=...;TrustServerCertificate=yes' AS {alias} (TYPE mssql, READ_ONLY);`
     - **Oracle (`oracle`)**:
       `LOAD oracle_scanner; CREATE SECRET __sec_{alias} (TYPE oracle, DSN 'host:port/service', USER '...', PASSWORD '...'); ATTACH '__sec_{alias}' AS {alias} (TYPE oracle_scanner);`
   - Hỗ trợ **parameter binding an toàn** cho `@param`: chuyển đổi và escape giá trị thông qua `PREPARE` / literal quote chuẩn theo kiểu dữ liệu (`DATE`, `NUMERIC`, `VARCHAR`, `NULL` khi rỗng để mệnh đề `(@Param IS NULL OR col = @Param)` hoạt động trên cả 3 hệ DB).
   - Hỗ trợ cả 2 cách đọc dữ liệu từ nguồn vào bảng tạm DuckDB (`CREATE TEMP TABLE src_{alias} AS ...`):
     - Cách A: Truy vấn qua catalog đã `ATTACH` (`SELECT ... FROM {alias}.{schema}.{table}`).
     - Cách B: Truy vấn passthrough bằng dialect gốc của DB đích (`postgres_query('{alias}', '...')`, `oracle_query('__sec_{alias}', '...')`, hoặc subquery trên `{alias}` của `mssql`).
3. **[NEW] `app/Services/DuckDb/DuckDbReportEngine.php` & `app/Services/DuckDb/SnapshotManager.php`**:
   - **Tạo Snapshot (`executeAndCreateSnapshot`)**:
     1. Sau khi đọc dữ liệu từ các DB nguồn được chọn (`pg_north`, `pg_south`, `mssql_erp`, `oracle_hr`) vào các bảng staging trong phiên DuckDB, thực hiện `DETACH` các DB nguồn và khóa truy cập ngoài:
        `SET enable_external_access = false;`
     2. Chạy câu lệnh tổng hợp (`UNION ALL` kèm cột `_source_db` cho chế độ `single_or_union`, hoặc câu lệnh `federation_sql` cho chế độ `federated_join`) và ghi xuống bảng `report_result` (có cột thứ tự `__row_id`) cùng bảng `report_meta` trong file `storage/app/snapshots/{snapshotId}.duckdb`.
   - **Đọc phân trang (`getPage(string $snapshotId, int $page, int $perPage = 10)`)**:
     - Mở file `{snapshotId}.duckdb` ở chế độ `READ_ONLY`, lấy `LIMIT 11 OFFSET ?` (hiển thị 10 dòng, dòng thứ 11 xác nhận có trang tiếp theo) + `total_rows` từ `report_meta`.
     - Chuyển trang hoàn toàn không gọi lại PostgreSQL, SQL Server hay Oracle!
4. **[NEW] `app/Services/Export/DuckDbExporter.php` (Nâng cấp chuẩn `.xlsx` và `.csv`)**:
   - **Xuất Excel chuẩn `.xlsx` (`exportExcel`)**:
     - Sử dụng extension `excel` chính thức của DuckDB 2.0:
       ```sql
       LOAD excel;
       COPY (SELECT * EXCLUDE (__row_id) FROM report_result ORDER BY __row_id)
       TO '/path/to/file.xlsx' WITH (FORMAT xlsx, HEADER true, SHEET 'SmartReport');
       ```
     - File sinh ra là **OpenXML `.xlsx` thật 100%** (binary ZIP chứa `xl/workbook.xml`, `xl/worksheets/sheet1.xml`, giữ chuẩn kiểu số `NUMERIC`, ngày tháng và tiếng Việt), trả về header `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`.
   - **Xuất CSV chuẩn UTF-8 BOM (`exportCsv`)**:
     - Sử dụng `COPY (SELECT * EXCLUDE (__row_id) FROM report_result ORDER BY __row_id) TO '/path/to/file.csv' WITH (FORMAT csv, HEADER true, DELIMITER ',')`, chèn BOM `\xEF\xBB\xBF` ở đầu file để mở bằng Excel không lỗi tiếng Việt, trả về header `Content-Type: text/csv; charset=UTF-8`.
   - **Hỗ trợ Chia nhỏ file (`.zip`) cho cả `.xlsx` và `.csv`**:
     - Khi bật `splitFile = true` và `totalRows > splitRows`, tự động xuất từng phân đoạn (`LIMIT {splitRows} OFFSET {offset}`) thành các file `_part1.xlsx`, `_part2.xlsx` (hoặc `_part1.csv`, `_part2.csv`) và nén thành 1 file `.zip`.

#### Kiểm tra nghiệm thu (Acceptance Checks) & Commit Gate Cụm 2
Viết bộ test toàn diện tại `tests/Feature/MultiDbEngineAndExporterTest.php` kiểm chứng trực tiếp trên các DB thật:
- Test Kiểu 1 (Single-DB): Chạy `SELECT` trên từng loại DB (`PostgreSQL`, `SQL Server`, `Oracle`) trả về đúng số dòng và kiểu dữ liệu.
- Test Kiểu 2 (Multi-DB `UNION ALL`): Chọn `pg_north` (25 dòng) vs Chọn `pg_north` + `pg_south` (45 dòng, có cột `_source_db`).
- Test Kiểu 3 (2-Way Cross-Engine `JOIN`): `JOIN` giữa `Oracle` (`HR_USER.EMPLOYEES`) và `SQL Server` (`dbo.production_orders`).
- Test Kiểu 4 (3-Way Enterprise Federation): `JOIN` cả 3 hệ quản trị `Oracle` + `SQL Server` + `PostgreSQL` trong cùng 1 báo cáo.
- Test Snapshot Pagination: Phân trang 10 dòng/trang đọc từ file `.duckdb` mà không truy vấn lại các DB nguồn.
- Test Export `.xlsx` & `.csv`: Kiểm tra file `.xlsx` xuất ra có chữ ký ZIP/OpenXML hợp lệ (`PK\x03\x04`, chứa `xl/worksheets/sheet1.xml`) và đọc ngược lại bằng `SELECT * FROM read_xlsx(...)` trong DuckDB khớp 100% số dòng/cột; kiểm tra file `.csv` và file `.zip` chia part khớp dữ liệu.

```bash
php artisan test --filter=MultiDbEngineAndExporterTest
git add -A && git commit -m "feat(cluster-2): implement duckdb multi-db engine for pgsql/sqlsrv/oracle, snapshot paging and real xlsx/csv exporter"
```

---

### Cụm 3: Hệ Thống Quản Trị Filament: Data Sources (`sqlsrv`, `pgsql`, `oracle`), Menu Groups & Role Permissions
**Worker phụ trách**: `admin-ui` | **Verifier**: `verifier`

#### Mục tiêu
Xây dựng Filament Admin Panel đọc/ghi cấu hình trên SQLite, thanh Sidebar tự động đồng bộ từ `MenuGroup` + `CustomView` theo quyền của user, và 3 Filament Resources quản trị: **Quản lý Kết nối DB (`DataSourceResource`)**, **Quản lý Nhóm Menu (`MenuGroupResource`)**, **Phân quyền Vai trò (`RolePermissionResource`)**.

#### Các bước thực hiện & Files
1. **[MODIFY] `app/Providers/Filament/AdminPanelProvider.php` & [NEW] `app/Services/Navigation/DynamicSidebarBuilder.php`**:
   - Tự động hiển thị các nhóm menu và các `CustomView` trên thanh Sidebar theo quyền của user đăng nhập.
2. **[NEW] `app/Filament/Resources/DataSourceResource.php`**:
   - Ô chọn `driver` giới hạn đúng 3 loại kết nối được phép:
     - `sqlsrv` (Microsoft SQL Server)
     - `pgsql` (PostgreSQL)
     - `oracle` (Oracle Database)
   - Form cấu hình động theo `driver` (`host`, `port` mặc định `1433`/`5432`/`1521`, `database_name` hoặc `service_name` cho Oracle, `default_schema`, `username`, `password` mã hóa).
   - Nút Action **"Kiểm tra kết nối (Test Connection)"**: Dùng DuckDB thử `ATTACH` và chạy `SELECT 1` trực tiếp tới SQL Server / PostgreSQL / Oracle, hiển thị thông báo kết nối thành công kèm thời gian phản hồi (ms).
3. **[NEW] `app/Filament/Resources/MenuGroupResource.php` & `app/Filament/Resources/RolePermissionResource.php`**:
   - Quản lý các nhóm menu, phân quyền truy cập theo nhóm menu (`allowed_groups`), theo từng view (`allowed_views`), và theo từng nguồn DB (`allowed_sources`).

#### Kiểm tra nghiệm thu (Acceptance Checks) & Commit Gate Cụm 3
```bash
php artisan test --filter=FilamentAdminResourcesTest
git add -A && git commit -m "feat(cluster-3): add filament resources for sqlsrv/pgsql/oracle data sources, menu groups and role permissions"
```

---

### Cụm 4: Custom View Builder (`CustomViewResource`) Hỗ Trợ Đa Nguồn, Auto-Detect `@Param` & Test SQL 10 Dòng
**Worker phụ trách**: `admin-ui` | **Verifier**: `verifier`

#### Mục tiêu
Xây dựng màn hình thiết kế báo cáo SQL động (`CustomViewResource`) lưu vào SQLite, hỗ trợ cấu hình chọn nhiều DB (`single_or_union`) hoặc JOIN nhiều DB khác hệ quản trị (`federated_join`), tích hợp nút **"Tự động nhận diện @tham số"** và **"Chạy thử SQL (Preview 10 dòng)"** thực thi trực tiếp qua DuckDB lên các DB PostgreSQL / SQL Server / Oracle.

#### Các bước thực hiện & Files
1. **[NEW] `app/Filament/Resources/CustomViewResource.php` (+ Pages `ListCustomViews`, `CreateCustomView`, `EditCustomView`)**:
   - **Khối 1 — Định danh & Phân quyền**: `view_code`, `view_name`, `menu_group_code`, `roles`, `description`.
   - **Khối 2 — Chế độ Truy vấn & Chọn DB Nguồn**:
     - `single_or_union` (**Truy vấn 1 DB hoặc gộp `UNION ALL` nhiều DB cùng cấu trúc**): Chọn danh sách `allowed_sources` (VD: chọn cả `pg_north` và `pg_south`), `default_sources`, và nhập câu lệnh `sql_text`.
     - `federated_join` (**Truy vấn liên hợp `JOIN` nhiều DB khác loại trong DuckDB**): Khai báo danh sách `source_queries` (Repeater gồm: `alias`, `data_source_code` chọn từ `pg_north` / `pg_south` / `mssql_erp` / `oracle_hr`, và `sql_text` tùy chọn nếu muốn lọc trước ở nguồn) + ô nhập `sql_text` (câu lệnh DuckDB SQL nối các bảng/alias từ nhiều DB).
   - **Khối 3 — Tự động nhận diện `@tham số` & Bộ lọc (`filters` Repeater)**:
     - Nút Action **"Tự động nhận diện @tham số"**: Quét câu SQL, tự động điền các tham số `@StartDate`, `@EndDate`, `@Plant`, `@DeptCode`... vào bảng `filters` (`param_name`, `label`, `input_type`: `text|date|number|select`, `default_value`, `is_required`, `options`).
   - **Khối 4 — Chạy thử SQL (Preview 10 dòng)**:
     - Nút Action **"Chạy thử SQL (Test)"**: Chạy truy vấn thực tế qua DuckDB và render bảng xem trước **10 dòng đầu tiên**, danh sách cột, tổng số dòng và thời gian chạy ngay trong Builder.

#### Kiểm tra nghiệm thu (Acceptance Checks) & Commit Gate Cụm 4
```bash
php artisan test --filter=CustomViewBuilderTest
git add -A && git commit -m "feat(cluster-4): implement filament custom view builder with multi-db modes, param detector and 10-row preview"
```

---

### Cụm 5: Màn Hình Chạy Báo Cáo (`ReportRunnerPage` 3-Tab UI) & Trang Xuất File (`SqlExportPage` Chuẩn `.xlsx` / `.csv`)
**Worker phụ trách**: `report-ui` (phối hợp `engine`) | **Verifier**: `verifier`

#### Mục tiêu
Xây dựng màn hình hiển thị báo cáo động (`ReportRunnerPage` tại `/admin/reports/{viewCode}`) với bộ chọn nhiều DB nguồn trực tiếp trên giao diện, 3 Tab thông tin (`Tổng quan`, `Application information`, `SQL Query`), phân trang 10 dòng từ DuckDB Snapshot, và nút xuất chính xác 2 định dạng **Excel (`.xlsx`)** và **CSV (`.csv`)** cùng trang `SqlExportPage`.

#### Các bước thực hiện & Files
1. **[NEW] `app/Filament/Pages/ReportRunnerPage.php` & `resources/views/filament/pages/report-runner-page.blade.php`**:
   - **Tab 1 — `Tổng quan (Overview)`**:
     - **Bộ lọc & Chọn Database hiển thị**:
       - Cho phép người dùng chọn 1 hoặc nhiều Database từ danh sách nguồn của báo cáo (VD: chọn `pg_north`, `pg_south`, hoặc xem danh sách các DB `oracle_hr` + `mssql_erp` đang tham gia `JOIN`).
       - Các ô điều kiện lọc động (`date`, `text`, `number`, `select`).
       - Các nút thao tác: **Tra cứu**, **Xuất Excel (`.xlsx`)**, **Xuất CSV (`.csv`)**, **Làm mới**.
     - **Bảng kết quả & Phân trang 10 dòng**:
       - Hiển thị 10 dòng mỗi trang từ DuckDB Snapshot, có badge hiển thị DB nguồn (`_source_db`), tổng số bản ghi, thời gian truy vấn và thanh chuyển trang không cần query lại DB nguồn.
   - **Tab 2 — `Application information`**:
     - Hiển thị `UI Name`, `UI ID`, `Version`, `Execution Mode`, danh sách các DB nguồn (`PostgreSQL`, `SQL Server`, `Oracle`) kèm thời điểm đọc dữ liệu và mã Snapshot.
   - **Tab 3 — `SQL Query` (Live SQL Inspector)**:
     - Tự động cập nhật `Use Criteria` (bao gồm các DB đang tick chọn), `@Parameters` và câu lệnh SQL thực thi khi người dùng thay đổi bộ lọc.
2. **[NEW] `app/Filament/Pages/SqlExportPage.php` & `resources/views/filament/pages/sql-export-page.blade.php`**:
   - Cho phép Admin chọn DB nguồn (`PostgreSQL`, `SQL Server`, `Oracle` hoặc truy vấn liên hợp nhiều DB), nhập câu lệnh `SELECT / WITH`, chọn định dạng xuất:
     - **Excel chuẩn (`.xlsx`)**
     - **CSV chuẩn UTF-8 (`.csv`)**
   - Hỗ trợ tùy chọn **"Tự động chia nhỏ file và nén `.zip`"** khi vượt ngưỡng dòng (`10 dòng (Test)`, `100.000 dòng`, `500.000 dòng`, `1.000.000 dòng`).

#### Kiểm tra nghiệm thu (Acceptance Checks) & Commit Gate Cụm 5
```bash
php artisan test --filter=ReportRunnerAndSqlExportTest
git add -A && git commit -m "feat(cluster-5): build multi-db report runner page with 3-tab inspector and xlsx/csv export pages"
```

---

### Cụm 6: Kiểm Thử Trình Duyệt End-to-End Bằng Playwright CLI / Chrome MCP & Nghiệm Thu
**Worker phụ trách**: `verifier` (phối hợp `admin-ui` / `report-ui` nếu cần chỉnh sửa)

#### Mục tiêu
Kiểm thử toàn diện ứng dụng đang chạy thật trên trình duyệt bằng **Playwright (`npx playwright test` & `playwright-cli`)** / **Chrome MCP**, kiểm chứng kết nối thực tế tới cả **PostgreSQL, SQL Server, Oracle**, kiểm chứng các cách `SELECT` đa nguồn và kiểm chứng file tải về đúng chuẩn **`.xlsx`** và **`.csv`**.

#### Các kịch bản kiểm thử E2E bắt buộc (`tests/e2e/smartreport.spec.ts`)
1. **E2E 1 — Kiểm tra Kết nối 3 Hệ Quản Trị (`PostgreSQL`, `SQL Server`, `Oracle`)**:
   - Đăng nhập `/admin`, mở `Data Sources`, xác nhận chỉ cho phép 3 driver (`sqlsrv`, `pgsql`, `oracle`).
   - Bấm `Test Connection` trên cả 4 nguồn (`pg_north`, `pg_south`, `mssql_erp`, `oracle_hr`) và xác nhận thông báo kết nối thành công.
2. **E2E 2 — Tạo Custom View Mới Với Auto-Detect `@Param` & Preview 10 Dòng**:
   - Vào `Custom Views -> Create`, tạo một báo cáo mới kết nối DB, nhập câu lệnh SQL có `@StartDate`, `@Plant`, bấm **"Tự động nhận diện @tham số"**, bấm **"Chạy thử SQL (Test)"** xem trước 10 dòng, lưu lại và kiểm tra View xuất hiện ngay trên Sidebar.
3. **E2E 3 — Thử Nghiệm Nhiều Cách `SELECT` Lên Các Loại DB Trên `ReportRunnerPage`**:
   - **Cách 1 (Single-DB trên SQL Server & Oracle)**: Mở báo cáo `mssql-production-orders` và `oracle-hr-directory`, lọc theo `@Plant` / `@DeptCode`, kiểm tra kết quả hiển thị đúng 10 dòng/trang.
   - **Cách 2 (Multi-DB `UNION ALL` trên 2 DB PostgreSQL)**: Mở `pg-multi-plant-contracts`, đầu tiên chỉ chọn `pg_north` (ra 25 dòng), sau đó tick chọn cả `pg_north` + `pg_south` (ra 45 dòng, có cột `_source_db` phân biệt `pg_north` và `pg_south`), bấm sang Trang 2 xác nhận phân trang mượt từ Snapshot.
   - **Cách 3 (Cross-Engine 2-DB `JOIN` Oracle + SQL Server)**: Mở `join-oracle-mssql-productivity`, xác nhận bảng kết quả ghép đúng tên nhân viên từ Oracle với sản lượng máy từ SQL Server.
   - **Cách 4 (3-Way Federation `Oracle + SQL Server + PostgreSQL`)**: Mở `federated-3way-executive-report`, kiểm tra kết quả tổng hợp từ cả 3 loại DB và kiểm tra nội dung 2 tab `Application information` & `SQL Query`.
4. **E2E 4 — Kiểm Tra Tải File Xuất Chuẩn `.xlsx` và `.csv` (+ Chia nhỏ `.zip`)**:
   - Trên màn hình báo cáo và màn hình `SQL Export`, bấm **Xuất Excel (`.xlsx`)** và **Xuất CSV (`.csv`)**.
   - Xác nhận bằng Playwright download event rằng file `.xlsx` tải về là file OpenXML hợp lệ (đọc lại được bằng DuckDB `read_xlsx`) và file `.csv` có đầy đủ header + dữ liệu UTF-8 chính xác, cũng như chế độ chia nhỏ file xuất ra đúng `.zip`.
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
| **1** | Cụm 1: Laravel + SQLite Config + DuckDB 2.0 Extensions + Docker DBs (`pgsql`, `sqlsrv`, `oracle`) | `feat(cluster-1): setup laravel sqlite config, duckdb 2.0 extensions and postgres/mssql/oracle test databases` |
| **2** | Cụm 2: DuckDB Multi-DB Engine + Snapshot Pagination + Chuẩn `.xlsx` / `.csv` Exporter | `feat(cluster-2): implement duckdb multi-db engine for pgsql/sqlsrv/oracle, snapshot paging and real xlsx/csv exporter` |
| **3** | Cụm 3: Filament Admin Resources (`DataSourceResource` cho 3 hệ DB, Menu, Role) | `feat(cluster-3): add filament resources for sqlsrv/pgsql/oracle data sources, menu groups and role permissions` |
| **4** | Cụm 4: Custom View Builder + Auto-detect `@param` + Live 10-Row Preview | `feat(cluster-4): implement filament custom view builder with multi-db modes, param detector and 10-row preview` |
| **5** | Cụm 5: Report Runner (Multi-DB Selector, 3-Tab UI) & SQL Export (`.xlsx` / `.csv` / `.zip`) | `feat(cluster-5): build multi-db report runner page with 3-tab inspector and xlsx/csv export pages` |
| **6** | Cụm 6: Playwright / Chrome MCP E2E Verification trên cả 3 hệ DB & 2 định dạng Export | `test(cluster-6): verify multi-db select patterns and xlsx/csv exports with playwright e2e suite` |
