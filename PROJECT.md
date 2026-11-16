# Project: SmartReport (DAR Next-Gen)

## Architecture
SmartReport is a multi-database analytics and reporting platform built with Laravel 13, Filament 5, and DuckDB 2.0.
- **SQLite Config Store**: Local database `database/database.sqlite` stores system metadata, data source connection configurations, menu hierarchies, RBAC role permissions, and custom view SQL definitions.
- **DuckDB 2.0 Federation Engine**: Embedded analytical engine supporting 3 external database drivers (`pgsql`, `sqlsrv`, `oracle`) using native extensions (`postgres`, `mssql`, `oracle_scanner`, `excel`). DuckDB attaches to external databases, extracts query results into detached local snapshots (`storage/app/snapshots/{id}.duckdb`), locks external filesystem access (`enable_external_access = false`), and supports high-speed 10-row pagination (`LIMIT 11 OFFSET ?`) without re-querying external source databases.
- **Export Pipeline**: Generates true OpenXML `.xlsx` spreadsheets (via DuckDB's native `excel` extension), UTF-8 BOM `.csv` files, and multi-part `.zip` archives.
- **Filament Admin Panel**: Dynamic administrative interface providing CRUD for Data Sources, Menu Groups, Role Permissions, and Custom View Builder with auto-parameter detection and 10-row query testing.
- **Reporting Engine UI**: Interactive 3-tab live runner (`ReportRunnerPage`) supporting multi-DB selection, dynamic filter controls, snapshot pagination with `_source_db` badge, application diagnostics, and live SQL inspection.
- **End-to-End Test Suite**: Comprehensive browser-level verification using Playwright / Chrome MCP exercising all 4 SELECT patterns and export formats against 4 live Docker databases.

## Feature Inventory
| # | Feature | Description | Milestone | Source |
|---|---------|-------------|-----------|--------|
| 1 | SmartReport Config | `config/smartreport.php` with allowed drivers (`sqlsrv`, `pgsql`, `oracle`), duckdb binary, snapshot settings | M1 | survey |
| 2 | DataSource Model & Migration | SQLite schema and Eloquent model for external DB credentials with encrypted password cast | M1 | survey |
| 3 | MenuGroup Model & Migration | SQLite schema and Eloquent model for navigation groups and role mappings | M1 | survey |
| 4 | RolePermission Model & Migration | SQLite schema and Eloquent model for RBAC mapping to groups, views, and data sources | M1 | survey |
| 5 | CustomView Model & Migration | SQLite schema and Eloquent model for report definitions, execution modes, and filter schemas | M1 | survey |
| 6 | User Model RBAC Support | Extend `User` model with roles support to interface with RolePermission and DynamicSidebarBuilder | M1 | survey |
| 7 | External DB Provisioner | `ExternalTestDatabaseProvisioner.php` to initialize schemas & rich realistic test data across 4 Docker DBs | M1 | survey |
| 8 | Seed 5 Reference Custom Views | Seed 5 predefined views covering all 4 SELECT patterns into SQLite | M1 | survey |
| 9 | Cluster 1 Test & Commit Gate | `Cluster1EnvironmentAndSeedTest` passing 100% and Git commit gate | M1 | survey |
| 10 | SqlParameterExtractor | Scans raw SQL for `@param` tokens and infers input types (`date`, `number`, `text`, `select`) | M2 | survey |
| 11 | SqlSafetyValidator | Enforces read-only statements (`SELECT`, `WITH`) and strictly blocks DDL, DML, filesystem, and system keywords | M2 | survey |
| 12 | DuckDbSourceAdapter | Generates `ATTACH`/`CREATE SECRET` commands and executes queries for `pgsql`, `sqlsrv`, `oracle` | M2 | survey |
| 13 | Native Dialect Passthrough | Supports `postgres_query`, `mssql_scan`, and `oracle_query` passthrough queries | M2 | survey |
| 14 | DuckDbReportEngine & Snapshot | Stages source queries, detaches catalogs, locks sandbox, and writes snapshot to `.duckdb` file | M2 | survey |
| 15 | Snapshot Paging | Reads 10 rows/page from snapshot file (`LIMIT 11 OFFSET ?`) with zero re-queries to external DBs | M2 | survey |
| 16 | OpenXML Excel Exporter | Generates genuine `.xlsx` spreadsheet using DuckDB `excel` extension (`LOAD excel; COPY ... FORMAT xlsx`) | M2 | survey |
| 17 | UTF-8 BOM CSV Exporter | Generates CSV with `\xEF\xBB\xBF` UTF-8 BOM prefix for Vietnamese Unicode compatibility in Excel | M2 | survey |
| 18 | Multi-Part Zip Exporter | Splits large exports into chunked files and archives them into `.zip` format | M2 | survey |
| 19 | Cluster 2 Test & Commit Gate | `MultiDbEngineAndExporterTest` passing 100% and Git commit gate | M2 | survey |
| 20 | DynamicSidebarBuilder | Dynamically populates Filament sidebar navigation based on active user roles and permissions | M3 | survey |
| 21 | DataSourceResource | Filament CRUD for data sources strictly restricted to 3 drivers: `sqlsrv`, `pgsql`, `oracle` | M3 | survey |
| 22 | Live Test Connection Action | Filament Action testing live DuckDB connectivity to target DB and reporting latency in ms | M3 | survey |
| 23 | MenuGroupResource | Filament CRUD for managing menu categories and sort order | M3 | survey |
| 24 | RolePermissionResource | Filament CRUD for managing RBAC roles and permissions | M3 | survey |
| 25 | Cluster 3 Test & Commit Gate | `FilamentAdminResourcesTest` passing 100% and Git commit gate | M3 | survey |
| 26 | CustomViewResource Form | Custom view configuration supporting `single_or_union` and `federated_join` execution modes | M4 | survey |
| 27 | Auto-Detect Parameters Action | Filament Action scanning SQL to auto-populate the filters repeater schema | M4 | survey |
| 28 | Test SQL 10-Row Preview Action | Filament Action executing query via DuckDB and displaying first 10 rows, columns, and metrics modal | M4 | survey |
| 29 | Cluster 4 Test & Commit Gate | `CustomViewBuilderTest` passing 100% and Git commit gate | M4 | survey |
| 30 | ReportRunnerPage | Live report viewer at `/admin/reports/{viewCode}` with 3-tab layout | M5 | survey |
| 31 | Tab 1: Overview & Controls | Multi-DB selector, dynamic filter form, snapshot table (10 rows/page), `_source_db` badge, export triggers | M5 | survey |
| 32 | Tab 2: Application Information | Diagnostic metadata: UI Name, ID, Version, Mode, Target DBs, Timestamp, Snapshot ID | M5 | survey |
| 33 | Tab 3: Live SQL Inspector | Real-time criteria inspector: filters, parameters, native source SQL, and DuckDB federated SQL | M5 | survey |
| 34 | SqlExportPage | Standalone ad-hoc query export page at `/admin/sql-export` with `.xlsx`, `.csv`, and `.zip` options | M5 | survey |
| 35 | Cluster 5 Test & Commit Gate | `ReportRunnerAndSqlExportTest` passing 100% and Git commit gate | M5 | survey |
| 36 | Playwright E2E 1 | Verify Test Connection succeeds on all 4 DB sources (`pg_north`, `pg_south`, `mssql_erp`, `oracle_hr`) | M6 | survey |
| 37 | Playwright E2E 2 | Verify Custom View creation with auto-detect @params and live 10-row preview | M6 | survey |
| 38 | Playwright E2E 3 | Verify all 4 SELECT patterns (Single-DB, Multi-DB UNION ALL with page 2 snapshot paging, Cross-Engine JOIN, 3-Way Federation) | M6 | survey |
| 39 | Playwright E2E 4 | Verify download and validation of OpenXML `.xlsx` (via DuckDB `read_xlsx`), `.csv`, and `.zip` files | M6 | survey |
| 40 | Cluster 6 Screenshots & Final Gate | Save acceptance screenshots in `docs/screenshots/`, pass all tests, and clean working tree | M6 | survey |

## Milestones
| # | Name | Scope | Dependencies | Status |
|---|------|-------|-------------|--------|
| M1 | Cluster 1: SQLite Schema, Dữ Liệu Thật 4 DB & Commit Gate | Features 1-9: `config/smartreport.php`, Migrations & Models (`DataSource`, `MenuGroup`, `RolePermission`, `CustomView`, `User`), `ExternalTestDatabaseProvisioner`, seed 5 custom views, `Cluster1EnvironmentAndSeedTest` | None | DONE |
| M2 | Cluster 2: DuckDB Multi-DB Federation Engine, Snapshot Paging & Exporter | Features 10-19: `SqlParameterExtractor`, `SqlSafetyValidator`, `DuckDbSourceAdapter`, `DuckDbReportEngine`, `SnapshotManager`, `DuckDbExporter`, `MultiDbEngineAndExporterTest` | M1 | DONE |
| M3 | Cluster 3: Filament Admin Resources | Features 20-25: `DynamicSidebarBuilder`, `DataSourceResource` (3 drivers + test connection action), `MenuGroupResource`, `RolePermissionResource`, `FilamentAdminResourcesTest` | M1 | DONE |
| M4 | Cluster 4: Custom View Builder | Features 26-29: `CustomViewResource` (`single_or_union` & `federated_join`), Auto-detect `@param`, Test SQL 10-row preview action, `CustomViewBuilderTest` | M2, M3 | PLANNED |
| M5 | Cluster 5: Report Runner & SQL Export Pages | Features 30-35: `ReportRunnerPage` (3-tab UI, multi-DB selector, snapshot pagination), `SqlExportPage`, `ReportRunnerAndSqlExportTest` | M2, M4 | PLANNED |
| M6 | Cluster 6: Playwright E2E & Final Verification | Features 36-40: `tests/e2e/smartreport.spec.ts` (E2E 1-4), acceptance screenshots in `docs/screenshots/`, full test suite execution, final commit gate | M1-M5 | PLANNED |

## Interface Contracts
### DuckDB Engine ↔ Database Drivers
- PostgreSQL (`pgsql`): `ATTACH 'host={host} port={port} user={username} password={password} dbname={database_name}' AS {alias} (TYPE postgres, READ_ONLY);`
- SQL Server (`sqlsrv`): `ATTACH 'Server={host},{port};Database={database_name};User Id={username};Password={password};TrustServerCertificate=yes' AS {alias} (TYPE mssql);`
- Oracle (`oracle`):
  `CREATE SECRET __sec_{code} (TYPE oracle, DSN '{host}:{port}/{service_name}', USER '{username}', PASSWORD '{password}');`
  `ATTACH '__sec_{code}' AS {alias} (TYPE oracle_scanner);`

### DuckDbReportEngine ↔ SnapshotManager
- `executeAndCreateSnapshot(CustomView $view, array $selectedSourceCodes, array $params): string` -> returns `snapshotId` (UUID string).
- Materialized tables inside `storage/app/snapshots/{snapshotId}.duckdb`:
  - `report_result`: Contains query result columns + `__row_id INTEGER PRIMARY KEY`.
  - `report_meta`: `snapshot_id VARCHAR`, `view_code VARCHAR`, `total_rows BIGINT`, `created_at TIMESTAMP`, `columns JSON`.
- `getPage(string $snapshotId, int $page, int $perPage = 10): array` -> returns `['rows' => array, 'totalRows' => int, 'page' => int, 'perPage' => int, 'totalPages' => int, 'hasNextPage' => bool]`.

### Exporter Interface
- `exportExcel(string $snapshotId, string $targetPath): string` (Uses DuckDB `excel` extension).
- `exportCsv(string $snapshotId, string $targetPath): string` (Prefixed with UTF-8 BOM `\xEF\xBB\xBF`).
- `exportZip(string $snapshotId, string $format, int $splitRows, string $targetPath): string`.

## Code Layout
- `config/smartreport.php` — Configuration file
- `app/Models/` — `DataSource.php`, `MenuGroup.php`, `RolePermission.php`, `CustomView.php`, `User.php`
- `database/migrations/` — SQLite migrations for above models
- `database/seeders/` — `SmartReportSeeder.php`, `DatabaseSeeder.php`
- `app/Services/Database/` — `ExternalTestDatabaseProvisioner.php`
- `app/Services/Sql/` — `SqlParameterExtractor.php`, `SqlSafetyValidator.php`
- `app/Services/DuckDb/` — `DuckDbSourceAdapter.php`, `DuckDbReportEngine.php`, `SnapshotManager.php`
- `app/Services/Export/` — `DuckDbExporter.php`
- `app/Services/Navigation/` — `DynamicSidebarBuilder.php`
- `app/Filament/Resources/` — `DataSourceResource.php`, `MenuGroupResource.php`, `RolePermissionResource.php`, `CustomViewResource.php` (+ Resource Pages)
- `app/Filament/Pages/` — `ReportRunnerPage.php`, `SqlExportPage.php`
- `resources/views/filament/pages/` — `report-runner-page.blade.php`, `sql-export-page.blade.php`
- `tests/Feature/` — `Cluster1EnvironmentAndSeedTest.php`, `MultiDbEngineAndExporterTest.php`, `FilamentAdminResourcesTest.php`, `CustomViewBuilderTest.php`, `ReportRunnerAndSqlExportTest.php`
- `tests/e2e/` — `smartreport.spec.ts`
- `docs/screenshots/` — Acceptance screenshots
