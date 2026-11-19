# E2E Test Infra: SmartReport (DAR Next-Gen)

## Test Philosophy
- Opaque-box, requirement-driven. No dependency on implementation design.
- Methodology: Category-Partition + Boundary Value Analysis (BVA) + Pairwise Combinatorial Testing + Real-World Workload Testing.
- Executed against 4 live Docker databases: `pg_north:5432`, `pg_south:5433`, `mssql_erp:1433`, `oracle_hr:1521`.

## Feature Inventory
| # | Feature | Source (Requirement) | Tier 1 (Feature) | Tier 2 (Boundary) | Tier 3 (Pairwise) | Tier 4 (Real-World) |
|---|---------|----------------------|:----------------:|:-----------------:|:-----------------:|:-------------------:|
| 1 | SQLite Schema & 4 DB Provisioning | R1 | 5 | 5 | ✓ | ✓ |
| 2 | DuckDB Federation Engine & 4 Drivers | R2 | 5 | 5 | ✓ | ✓ |
| 3 | Snapshot Pagination 10 rows/page | R2 | 5 | 5 | ✓ | ✓ |
| 4 | OpenXML .xlsx, CSV BOM & Zip Exporter | R2 | 5 | 5 | ✓ | ✓ |
| 5 | Filament Data Sources, Menu & Roles | R3 | 5 | 5 | ✓ | ✓ |
| 6 | Custom View Builder & Preview 10 rows | R4 | 5 | 5 | ✓ | ✓ |
| 7 | ReportRunnerPage 3-Tab UI & Multi-DB | R5 | 5 | 5 | ✓ | ✓ |
| 8 | Playwright E2E & Final Verification | R6 | 5 | 5 | ✓ | ✓ |

## Test Architecture
- **PHPUnit Feature Tests**:
  - `tests/Feature/Cluster1EnvironmentAndSeedTest.php`
  - `tests/Feature/MultiDbEngineAndExporterTest.php`
  - `tests/Feature/FilamentAdminResourcesTest.php`
  - `tests/Feature/CustomViewBuilderTest.php`
  - `tests/Feature/ReportRunnerAndSqlExportTest.php`
  - Runner: `php artisan test --filter=<TestClass>`
- **Playwright E2E Tests**:
  - `tests/e2e/smartreport.spec.ts`
  - Runner: `npx playwright test` / `playwright-cli`
  - Browser: Chromium (Google Chrome) against live `php artisan serve` at `127.0.0.1:18731`

## Real-World Application Scenarios (Tier 4)
| # | Scenario | Features Exercised | Complexity |
|---|----------|--------------------|------------|
| 1 | Single-DB SQL Server Production Orders Inspection | MSSQL adapter, parameter binding, snapshot pagination | Medium |
| 2 | Multi-Plant PostgreSQL Union All Export | Multi-DB selector, `_source_db` tag, 10-row page 2, OpenXML .xlsx export | High |
| 3 | Cross-Engine 2-DB JOIN (Oracle HR + SQL Server ERP) | Oracle secret attach, MSSQL attach, CTE JOIN, live preview | High |
| 4 | 3-Way Federation Executive Summary | PostgreSQL North/South + MSSQL + Oracle, 3-tab inspector, CSV UTF-8 BOM download | Very High |
| 5 | Ad-hoc SQL Export with Multi-Part Zip Splitting | DuckDB Exporter, chunking, ZipArchive packaging | Medium |

## Coverage Thresholds
- Tier 1: ≥5 happy-path test assertions per feature
- Tier 2: ≥5 boundary/edge cases (empty results, NULL parameter handling, `@today`, invalid drivers)
- Tier 3: Pairwise coverage across drivers (`pgsql` × `sqlsrv`, `oracle` × `sqlsrv`, `pgsql` × `oracle`)
- Tier 4: ≥5 realistic multi-database reporting scenarios

## Test isolation

PHPUnit uses a separate temporary storage directory per test and SQLite in memory.
Playwright creates a temporary SQLite database, file cache and storage directory,
starts its own server on port 18731, then removes its data after the run.
Both suites read the existing external DB fixtures and never provision them implicitly.
To reset sample tables explicitly, run `php artisan smartreport:provision-test-databases --no-interaction`.
Browser screenshots are saved under the ignored `test-results/` directory.
