# SmartReport (DAR Next-Gen) — Project Backlog & Resumption Specification

> **Project Reference**: DAR Next-Gen Enterprise Multi-DB Reporting Platform  
> **Framework Stack**: Laravel 13.42 + Filament 5.11 + Livewire 4.4 + Laravel Boost 2.10  
> **Analytical Engine**: DuckDB 2.0 (Extensions: `postgres`, `mssql`, `oracle_scanner`, `excel`)  
> **Current Baseline Commit**: `c188af2` (`feat(cluster-4): implement filament custom view builder with multi-db modes, param detector and 10-row preview`)  
> **Test Suite Status**: **40 / 40 Passed** (716 assertions, 0 failures, 0 skipped)  
> **Code Style Status**: **Pint Passed** (0 violations)  
> **Repository Location**: `/home/v25050017/project/laravel/DAR/SmartReport`  
> **Backlog Mirror Location**: `.agents/plans/BACKLOG.md`

---

## 1. Executive Summary & Project Status

SmartReport is a next-generation high-performance multi-database reporting and analytics system built on Laravel 13 and Filament 5. It replaces legacy single-database reporting systems by federating heterogeneous databases—**PostgreSQL 16**, **SQL Server 2022**, and **Oracle Database 23ai**—through an in-process **DuckDB 2.0** analytical engine.

### Architectural Core Principles
1. **Zero ETL & Zero Remote Load on Page Navigation**: Remote databases (`pg_north`, `pg_south`, `mssql_erp`, `oracle_hr`) are queried only during initial execution or manual refresh. DuckDB persists the aggregated dataset into an isolated snapshot file (`storage/app/snapshots/{snapshotId}.duckdb`). Subsequent 10-row page turns read exclusively from the local snapshot in `< 1ms` with remote access disabled (`SET enable_external_access = false;`).
2. **Four Universal Multi-DB SELECT Execution Patterns**:
   - **Pattern 1 (Single-DB Select)**: Direct passthrough or attached query to an individual database (`pgsql`, `sqlsrv`, or `oracle`) with bound `@param` parameters.
   - **Pattern 2 (Multi-DB `UNION ALL`)**: Same-schema federation across multiple instances (e.g., goping `pg_north:5432` and `pg_south:5433`), auto-injecting `_source_db` discriminator badges.
   - **Pattern 3 (Cross-Engine 2-DB `JOIN`)**: Cross-vendor JOIN (e.g., Oracle HR employees joined with SQL Server ERP production orders and machine logs).
   - **Pattern 4 (3-Way Enterprise Federation)**: CTE-based enterprise synthesis aggregating Oracle HR, SQL Server ERP, and PostgreSQL North & South contracts with runtime parameters (`@StartDate`, `@EndDate`, `@Plant`, `@DeptCode`).
3. **Native Direct Binary Export**: Excel (`.xlsx` OpenXML) and CSV (`.csv` UTF-8 with BOM) exports are streamed directly out of DuckDB via native `COPY ... TO ... WITH (FORMAT xlsx / csv)` without PHP memory bloating, including automatic ZIP archive chunking (`_part1.xlsx`, `_part2.xlsx`).
4. **Hermetic Administration & RBAC**: Filament admin panel controls Data Sources, Menu Groups, Role Permissions, and Custom Views. System roles (`super_admin`, `admin`, `viewer`) are guarded with immutable protection and fine-grained menu/view filtering.

---

## 2. Detailed Record of Completed & Verified Work (Milestones 1 – 4)

| Cluster / Milestone | Status | Git Commit | Automated Test Suite | Assertion Count | Scope Covered |
| :--- | :---: | :---: | :---: | :---: | :--- |
| **Cluster 1 (M1)** | `VERIFIED` | `259c43c` | `Cluster1EnvironmentAndSeedTest` | 3 tests, 70 assertions | SQLite Schema, 4 Target DBs, Provisioner, 5 Views Seeded |
| **Cluster 2 (M2)** | `VERIFIED` | `b2a507c` | `MultiDbEngineAndExporterTest` | 10 tests, 355 assertions | DuckDB Multi-DB Engine, Snapshots, OpenXML XLSX / CSV Exporter |
| **Cluster 3 (M3)** | `VERIFIED` | `3e28088` | `FilamentAdminResourcesTest` | 13 tests, 144 assertions | Filament Admin (Data Sources, Menus, Roles), RBAC Route Guards |
| **Cluster 4 (M4)** | `VERIFIED` | `c188af2` | `CustomViewBuilderTest` | 12 tests, 145 assertions | Custom View Builder, Auto-Detect `@param`, Live 10-Row Preview |
| **Total Passed** | | | **40 / 40 Tests Passing** | **716 Assertions** | **Zero failures, Zero skipped, Pint clean** |

### 2.1. Cluster 1 (M1): SQLite Schema & Migrations, External DB Provisioning, Baseline Views
- **Target Databases Provisioned with Real Vietnamese Unicode Data**:
  - `pg_north`: PostgreSQL 16 (`etl_postgres`, `127.0.0.1:5432`, DB `source_db`, user `etl_user`) $\rightarrow$ `public.v_epcontract` & `public.v_epcontract_detail` (25 contracts, plant `NORTH`).
  - `pg_south`: PostgreSQL 16 (`dev-postgres`, `127.0.0.1:5433`, DB `app`, user `app`) $\rightarrow$ `public.v_epcontract` & `public.v_epcontract_detail` (20 contracts, plant `SOUTH`).
  - `mssql_erp`: SQL Server 2022 (`dev-mssql`, `127.0.0.1:1433`, DB `erp_production`, user `sa`) $\rightarrow$ `dbo.production_orders` & `dbo.machine_logs` (35 rows).
  - `oracle_hr`: Oracle 23ai Free (`dev-oracle`, `127.0.0.1:1521/FREEPDB1`, user `app`) $\rightarrow$ `APP.EMPLOYEES` & `APP.DEPARTMENTS` (30 employees across departments and cost centers).
- **Core Models & Migrations (`database/database.sqlite`)**:
  - `DataSource`: connection definitions encrypted password, driver constrained strictly to `['sqlsrv', 'pgsql', 'oracle']`.
  - `MenuGroup`: navigation category, icon, sort order, role bindings.
  - `RolePermission`: role codes, system role protection (`is_system`), allowed groups, views, sources.
  - `CustomView`: SQL definitions, `execution_mode` (`single_or_union` vs `federated_join`), dynamic filters JSON, role ACL.
  - `User`: extended with `roles` cast and `isSuperAdmin()` helpers.
- **Service**: `app/Services/Database/ExternalTestDatabaseProvisioner.php` handles automatic DDL and seeding on external instances.
- **Seeder**: `database/seeders/SmartReportSeeder.php` seeds default admin, 4 data sources, 2 menu groups, 3 roles, and 5 baseline views.

### 2.2. Cluster 2 (M2): DuckDB Multi-DB Federation Engine, Snapshot Paging & Exporter
- **Services Implemented**:
  - `app/Services/Sql/SqlParameterExtractor.php`: High-res regex parameter extractor that masks single and double string literals before matching `@param`, extracts default values, and infers input types (`date` with `@today`, `number`, `text`, `select`).
  - `app/Services/Sql/SqlSafetyValidator.php`: Strict security validator restricting queries strictly to `SELECT` / `WITH`. Blocks destructive DDL/DML, system commands, multi-line comment evasions, and DuckDB table functions (`read_csv`, `read_parquet`, `duckdb_tables`, etc.).
  - `app/Services/DuckDb/DuckDbSourceAdapter.php`: Builds native DuckDB connection commands:
    - Postgres: `LOAD postgres; ATTACH '...' AS {alias} (TYPE postgres, READ_ONLY);`
    - SQL Server: `LOAD mssql; ATTACH '...' AS {alias} (TYPE mssql);`
    - Oracle: `LOAD oracle_scanner; CREATE SECRET {secret} (...); ATTACH '{secret}' AS {alias} (TYPE oracle_scanner);`
    - Supports dynamic parameter binding and handles `(@Param IS NULL OR col = @Param)` alongside `@today` date expansion.
  - `app/Services/DuckDb/DuckDbReportEngine.php`: Executes cross-database federation in ephemeral DuckDB sessions, generates snapshot files, isolates remote databases, and closes external connections.
  - `app/Services/DuckDb/SnapshotManager.php`: Reads paginated slices (`getPage($snapshotId, $page, $perPage = 10)`) from `storage/app/snapshots/{snapshotId}.duckdb` with sub-millisecond response and snapshot garbage collection.
  - `app/Services/Export/DuckDbExporter.php`: Direct binary exporter:
    - Native Excel (`.xlsx` OpenXML) via `LOAD excel; COPY (...) TO '...' WITH (FORMAT xlsx, HEADER true, SHEET 'SmartReport');`
    - Native CSV (`.csv`) with UTF-8 BOM (`\xEF\xBB\xBF`).
    - Multi-part ZIP archiving (`_part1.xlsx`, `_part2.xlsx` or `.csv`).

### 2.3. Cluster 3 (M3): Filament Admin Resources & Route RBAC
- **Filament Resources Created**:
  - `app/Filament/Resources/DataSourceResource.php`: Driver selector restricted to `sqlsrv`, `pgsql`, `oracle`. Features live "Kiểm tra kết nối (Test Connection)" action with instant ping and latency measurement in milliseconds.
  - `app/Filament/Resources/MenuGroupResource.php`: Dynamic navigation grouping and order management.
  - `app/Filament/Resources/RolePermissionResource.php`: 3-tier system role management (`super_admin` protected from deletion/mutation).
- **Navigation & Access Control**:
  - `app/Services/Navigation/DynamicSidebarBuilder.php`: Generates dynamic navigation groups and custom view links based on authenticated user roles.
  - Route authorization enforced across all resources via `canAccess()` overrides to ensure hermetic multi-role isolation.

### 2.4. Cluster 4 (M4): Custom View Builder Resource
- **Builder Features (`app/Filament/Resources/CustomViewResource.php`)**:
  - **Dynamic Mode Toggling**: Reactive form switching between `single_or_union` (allowed sources checkboxes & default sources selector) and `federated_join` (repeater for source aliases, data sources, and subquery SQL).
  - **Auto-Detect `@param` Action**: Header and field action scanning SQL for `@param` markers and auto-populating the `filters` repeater (`param_name`, `label`, `input_type`, `default_value`, `is_required`, `options`).
  - **Live 10-Row DuckDB Preview Modal**: Evaluates the view definition directly against the live Docker databases using DuckDB, displays the first 10 rows in an interactive table modal, profiles query execution latency, and lists detected columns.
  - **Dual-Key Normalization**: Full state compatibility for snake_case and camelCase attributes.

---

## 3. Detailed Specifications for Remaining Clusters (Next Session)

```
Remaining Milestones:
├── Cluster 5 (M5): Report Runner (3-Tab UI) & SQL Export Page
└── Cluster 6 (M6): Playwright Browser E2E Testing & Acceptance Screenshots
```

---

### 3.1. Cluster 5 (M5): `ReportRunnerPage` (3-Tab UI) & `SqlExportPage`

#### 3.1.1. Component Architecture & Target Routes
1. **Dynamic Report Runner**:
   - Class: `App\Filament\Pages\ReportRunnerPage` (`app/Filament/Pages/ReportRunnerPage.php`)
   - View: `resources/views/filament/pages/report-runner-page.blade.php`
   - Route: `/admin/reports/{viewCode}`
   - Dynamic Navigation: Linked automatically via `DynamicSidebarBuilder` (`url("/admin/reports/{$view->view_code}")`).
2. **Ad-hoc SQL Export Utility**:
   - Class: `App\Filament\Pages\SqlExportPage` (`app/Filament/Pages/SqlExportPage.php`)
   - View: `resources/views/filament/pages/sql-export-page.blade.php`
   - Route: `/admin/sql-export`
   - Role Guard: Restricted to users with `admin` or `super_admin` role.

#### 3.1.2. `ReportRunnerPage` Specifications
The report runner page is loaded when any report link is clicked in the sidebar. It receives `viewCode` as a route parameter.

##### A. Lifecycle & State Management
- `mount(string $viewCode)`:
  - Finds `CustomView::where('view_code', $viewCode)->firstOrFail()`.
  - Verifies user authorization: checks if `auth()->user()` has access to the view via `DynamicSidebarBuilder::canUserAccessView()`. If unauthorized, aborts with `403`.
  - Sets default selected sources: for `single_or_union`, pre-populates `selectedSources` from `customView->default_sources` (or the first available source in `customView->allowed_sources`).
  - Initializes dynamic filter values: iterates `customView->filters`, maps default values, expands `@today` to `now()->format('Y-m-d')`.
  - If auto-run is desired or upon initial mount, calls `runReport()`.
- Reactive Properties:
  - `string $viewCode`: Code of the active view.
  - `?string $snapshotId = null`: Active DuckDB snapshot identifier.
  - `int $currentPage = 1`: Current pagination page.
  - `int $perPage = 10`: Standard page size (10 rows/page).
  - `int $totalRows = 0`: Total records in the snapshot.
  - `bool $hasNextPage = false`: Flag indicating further pages.
  - `array $columns = []`: List of column names in the result schema.
  - `array $rows = []`: Current 10-row dataset displayed.
  - `array $selectedSources = []`: Selected data sources for `single_or_union` mode.
  - `array $filterValues = []`: Runtime parameter values keyed by parameter name.
  - `float $executionLatencyMs = 0.0`: Total engine execution time in milliseconds.
  - `string $activeTab = 'overview'`: Active tab in 3-tab layout (`overview`, `app_info`, `sql_inspector`).
  - `string $rawSqlText = ''`: Source query string.
  - `string $compiledFederatedSql = ''`: Final compiled DuckDB SQL.

##### B. Tab 1 — `Tổng quan (Overview)`
- **Header Control Bar**:
  - **Multi-DB Source Selector** (visible only when `customView->execution_mode === 'single_or_union'`): Checkbox list or multi-select dropdown enabling users to select 1 or multiple sources (e.g., `pg_north`, `pg_south`).
  - **Dynamic Parameter Filters Form**:
    - Generates form fields dynamically from `customView->filters`:
      - `date`: Filament `DatePicker` (defaulting to current date if `@today`).
      - `text`: Filament `TextInput`.
      - `number`: Filament `TextInput` with numeric validation.
      - `select`: Filament `Select` populated from filter options.
  - **Action Toolbar**:
    - **Nút "Tra cứu" (Run / Search)**: Calls `runReport()`, invokes `DuckDbReportEngine::executeAndCreateSnapshot()`, saves new `snapshotId`, resets `$currentPage = 1`, and loads page 1.
    - **Nút "Làm mới" (Reset)**: Resets filters to defaults and re-runs.
    - **Nút "Xuất Excel (.xlsx)"**: Triggers direct file download of the entire snapshot via `DuckDbExporter::exportExcel()`.
    - **Nút "Xuất CSV (.csv)"**: Triggers direct file download of the entire snapshot via `DuckDbExporter::exportCsv()`.
- **Dynamic Data Grid**:
  - Displays dynamic table columns matching `$columns`.
  - If `_source_db` column is present (auto-injected in `single_or_union`), renders it as an amber/info Badge distinguishing data origin (`pg_north`, `pg_south`, etc.).
  - Handles `NULL` values cleanly (displayed as `—` or muted text).
  - Formats numeric and timestamp values according to metadata.
- **Sub-millisecond Snapshot Pagination**:
  - Displays: "Trang X / Y (Tổng số: Z dòng)".
  - Buttons: `Trang trước` (Previous), `Trang sau` (Next).
  - Clicking pagination calls `loadPage(int $page)` $\rightarrow$ delegates directly to `SnapshotManager::getPage($this->snapshotId, $page, 10)`.
  - **Strict Requirement**: Absolutely ZERO queries sent to PostgreSQL, SQL Server, or Oracle during page navigation.

##### C. Tab 2 — `Application Information`
- Displays read-only system cards:
  - **UI Name**: `customView->view_name`.
  - **UI ID**: `customView->view_code`.
  - **Version**: `customView->version` (e.g. `v1.0.0`).
  - **Execution Mode**: `single_or_union` or `federated_join`.
  - **Menu Group**: Title and code of parent menu.
  - **Data Sources Connected**: Table of involved data sources with Driver (`sqlsrv`, `pgsql`, `oracle`), Host, Port, and Database/Service name.
  - **Snapshot Health**: Active Snapshot ID, Creation Timestamp, Expiration Countdown (TTL 30 mins), and Engine Execution Latency (e.g. `14.25 ms`).

##### D. Tab 3 — `SQL Query` (Live SQL Inspector)
- Displays runtime query transparency:
  - **Applied Criteria**: JSON dump of active `@param` filter values.
  - **Individual Source Queries**: Exact SQL executed against each respective database with parameter values substituted.
  - **DuckDB Federation SQL**: The compiled unified SQL executed inside the DuckDB engine (including `ATTACH`, `CREATE SECRET`, `UNION ALL`, or cross-database `JOIN`).
  - **Copy Action**: One-click "Copy SQL" button for auditing and debugging.

---

#### 3.1.3. `SqlExportPage` Specifications
- Target: `app/Filament/Pages/SqlExportPage.php` + `resources/views/filament/pages/sql-export-page.blade.php`.
- Capabilities:
  - Allows authorized analysts/admins to write raw `SELECT` / `WITH` queries against any registered data source or execute cross-source joins.
  - Form fields:
    - `Data Source`: Select from active `DataSource` records or "Federated (All Sources)".
    - `SQL Statement`: Textarea with SQL syntax highlighting or monospaced font. Validated strictly by `SqlSafetyValidator::validate()`.
    - `Export Format`: Radio/Select for **Excel (`.xlsx`)** or **CSV (`.csv`)**.
    - `Split File (.zip)`: Toggle checkbox.
    - `Chunk Row Threshold`: Options: `10 dòng (Test)`, `100,000 dòng`, `500,000 dòng`, `1,000,000 dòng`.
  - Action: "Thực thi & Tải về" (Execute & Download) streams the generated `.xlsx`, `.csv`, or `.zip` directly as a download response.

#### 3.1.4. Target Files for Cluster 5
- `app/Filament/Pages/ReportRunnerPage.php`
- `resources/views/filament/pages/report-runner-page.blade.php`
- `app/Filament/Pages/SqlExportPage.php`
- `resources/views/filament/pages/sql-export-page.blade.php`
- `tests/Feature/ReportRunnerAndSqlExportTest.php`

#### 3.1.5. Acceptance Verification Commands for Cluster 5
```bash
vendor/bin/pint --dirty --format agent
php artisan test --filter=ReportRunnerAndSqlExportTest
git add -A && git commit -m "feat(cluster-5): build multi-db report runner page with 3-tab inspector and xlsx/csv export pages"
```

---

### 3.2. Cluster 6 (M6): Playwright Browser E2E Testing & Acceptance Screenshots

#### 3.2.1. E2E Test Suite (`tests/e2e/smartreport.spec.ts`)
Cluster 6 uses real browser automation via Playwright (`npx playwright test` or `playwright-cli`) against the live Laravel application served on `http://127.0.0.1:8000`.

##### Scenario 1: Data Sources & Multi-DB Live Connectivity
1. Navigate to `/admin/login`, authenticate as `admin@smartreport.com` / `password`.
2. Navigate to `/admin/data-sources`. Verify the 4 active data sources are listed (`pg_north`, `pg_south`, `mssql_erp`, `oracle_hr`).
3. Open Edit / View on `pg_north`: click "Kiểm tra kết nối (Test Connection)", assert success notification with latency.
4. Click "Kiểm tra kết nối" on `mssql_erp`: assert success notification.
5. Click "Kiểm tra kết nối" on `oracle_hr`: assert success notification.
6. Verify driver dropdown contains exclusively `sqlsrv`, `pgsql`, and `oracle`.

##### Scenario 2: Custom View Builder Lifecycle & Live Preview
1. Navigate to `/admin/custom-views/create`.
2. Fill View Name: `E2E Dynamic Orders`, Code: `e2e-dynamic-orders`, Group: `Sản xuất & Vận hành`.
3. Select Execution Mode: `single_or_union`, choose `mssql_erp`.
4. Enter SQL containing parameters:
   ```sql
   SELECT order_no, contractno, plant, output_qty, unit_cost, shift_date
   FROM dbo.production_orders
   WHERE plant = @Plant AND shift_date >= @StartDate
   ```
5. Click action **"Tự động nhận diện @tham số"**. Verify the `filters` repeater automatically populates 2 items: `@Plant` (text) and `@StartDate` (date).
6. Click action **"Chạy thử SQL (Test)"**. Verify the preview modal opens displaying:
   - Header with execution duration (e.g. `XX ms`).
   - Interactive table containing up to 10 rows from SQL Server.
   - Column headers matching query.
7. Save view. Confirm redirected to `/admin/custom-views` and new item appears in the dynamic sidebar navigation under `Sản xuất & Vận hành`.

##### Scenario 3: Execution of All 4 Multi-DB SELECT Patterns on `ReportRunnerPage`
1. **Pattern 1 — Single-DB Select (SQL Server & Oracle)**:
   - Navigate to `/admin/reports/mssql-production-orders`. Verify 10 rows loaded from SQL Server.
   - Navigate to `/admin/reports/oracle-hr-directory`. Verify 10 rows loaded from Oracle. Verify filtering by department.
2. **Pattern 2 — Multi-DB `UNION ALL` with Snapshot Pagination (PostgreSQL North + South)**:
   - Navigate to `/admin/reports/pg-multi-plant-contracts`.
   - Select only `pg_north`: assert 25 total rows.
   - Select both `pg_north` AND `pg_south`: assert 45 total rows.
   - Verify table displays badge `_source_db` with `pg_north` and `pg_south`.
   - Click "Trang sau" (Page 2): verify rows 11 to 20 load immediately from snapshot.
3. **Pattern 3 — Cross-Engine 2-DB JOIN (Oracle HR + SQL Server ERP)**:
   - Navigate to `/admin/reports/join-oracle-mssql-productivity`.
   - Verify table displays combined columns: employee name & department from Oracle alongside output quantity & unit cost from SQL Server.
4. **Pattern 4 — 3-Way Enterprise Federation (Oracle + SQL Server + PostgreSQL North/South)**:
   - Navigate to `/admin/reports/federated-3way-executive-report`.
   - Verify data aggregated across all 3 DBMS engines.
   - Switch to **Tab 2 (Application Information)**: assert UI Name, Version, all 4 connected sources listed, snapshot ID, and latency.
   - Switch to **Tab 3 (SQL Query / Inspector)**: assert runtime criteria and DuckDB federation query are clearly visible with copy buttons.

##### Scenario 4: Direct Export Downloads & File Integrity Validation
1. On `ReportRunnerPage`, click **"Xuất Excel (.xlsx)"**. Catch browser download.
2. Verify downloaded `.xlsx` is a valid OpenXML spreadsheet (verify using DuckDB `SELECT * FROM read_xlsx('...')` or file size > 5KB).
3. Click **"Xuất CSV (.csv)"**. Verify downloaded `.csv` begins with UTF-8 BOM `\xEF\xBB\xBF` and contains expected header row and row counts.
4. Navigate to `/admin/sql-export`. Enter query, choose format, enable zip splitting (`10 dòng (Test)`), click export, and confirm download of multi-part `.zip` containing chunk files.

##### Scenario 5: Acceptance Screenshot Capture (`docs/screenshots/`)
Playwright script captures high-resolution screenshots into `docs/screenshots/`:
- `01-data-sources-test-connection.png`: Data source management showing live connection tests.
- `02-custom-view-builder-preview.png`: Custom View Builder with parameter detection and 10-row preview modal.
- `03-report-runner-union-pg.png`: Multi-plant contract report showing `_source_db` badges and pagination.
- `04-report-runner-federation-3way.png`: 3-way enterprise federation report grid.
- `05-report-runner-tabs-inspector.png`: Application info and SQL query inspector tabs.
- `06-sql-export-page.png`: Ad-hoc SQL export utility with chunked zip options.

#### 3.2.2. Acceptance Verification Commands for Cluster 6
```bash
php artisan test
npx playwright test
git status --short
git add -A && git commit -m "test(cluster-6): verify multi-db select patterns and xlsx/csv exports with playwright e2e suite"
```

---

## 4. Step-by-Step Activation Protocol for Resuming Development

When resuming the project in the next session, follow this exact sequence:

### Step 1: Environment & Container Health Verification
Ensure all 4 external database containers are up and reachable:
```bash
docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
```
Expected running containers:
- `etl_postgres` on port `5432` (`pg_north`)
- `dev-postgres` on port `5433` (`pg_south`)
- `dev-mssql` on port `1433` (`mssql_erp`)
- `dev-oracle` on port `1521` (`oracle_hr`)

Verify DuckDB 2.0 binary:
```bash
duckdb --version || ./storage/bin/duckdb --version
```

### Step 2: Baseline Regression Check
Run the complete automated test suite to confirm 100% baseline health:
```bash
cd /home/v25050017/project/laravel/DAR/SmartReport
php artisan test
vendor/bin/pint --test
```
Output must report: `40 passed`, `716 assertions`, `0 failures`.

### Step 3: Implement Cluster 5 (`report-ui` worker)
1. Generate `app/Filament/Pages/ReportRunnerPage.php` and `resources/views/filament/pages/report-runner-page.blade.php`.
2. Generate `app/Filament/Pages/SqlExportPage.php` and `resources/views/filament/pages/sql-export-page.blade.php`.
3. Create feature test: `tests/Feature/ReportRunnerAndSqlExportTest.php` covering:
   - Access authorization for `/admin/reports/{viewCode}` and `/admin/sql-export`.
   - Multi-DB source selection and dynamic parameter injection.
   - DuckDB snapshot creation and isolated page-by-page fetching.
   - 3-Tab rendering (`overview`, `app_info`, `sql_inspector`).
   - Excel (`.xlsx`), CSV (`.csv`), and ZIP chunk downloads.
4. Verify code style and run test suite:
   ```bash
   vendor/bin/pint --dirty --format agent
   php artisan test --filter=ReportRunnerAndSqlExportTest
   ```
5. Commit Cluster 5:
   ```bash
   git add -A && git commit -m "feat(cluster-5): build multi-db report runner page with 3-tab inspector and xlsx/csv export pages"
   ```

### Step 4: Implement Cluster 6 (`verifier` worker)
1. Ensure Laravel development server is running:
   ```bash
   php artisan serve --port=8000 &
   ```
2. Create `tests/e2e/smartreport.spec.ts` covering all 4 E2E scenarios.
3. Execute Playwright tests and save screenshots to `docs/screenshots/`:
   ```bash
   npx playwright test
   ```
4. Confirm test pass and verify screenshot files exist.
5. Commit Cluster 6:
   ```bash
   git add -A && git commit -m "test(cluster-6): verify multi-db select patterns and xlsx/csv exports with playwright e2e suite"
   ```

### Step 5: Final Acceptance
Run full regression:
```bash
php artisan test
git status --short
```
Confirm 100% clean repository and zero defects.
