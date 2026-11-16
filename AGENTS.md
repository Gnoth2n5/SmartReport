# SmartReport — Project Agent Instructions (`AGENTS.md`)

## 1. Project Identity & Core Architecture

**SmartReport** (`/home/v25050017/project/laravel/DAR/SmartReport`) is a dynamic Multi-Database SQL Reporting & Federation platform built with **Laravel 13**, **Filament 5**, **SQLite (internal config store)**, and **DuckDB 2.0 (Federated Query & Snapshot Engine)**.

- **Internal Configuration Database (`DB_CONNECTION=sqlite`)**:
  - Stored in `database/database.sqlite`.
  - Holds `users`, `data_sources`, `menu_groups`, `role_permissions`, and `custom_views` (SQL definitions, source mappings, and filter schemas).
- **Allowed External Report Target Drivers (Strictly 3 Drivers)**:
  1. `sqlsrv` (**Microsoft SQL Server**) — connected via DuckDB 2.0 community extension `mssql` (native TDS 7.4):
     - Catalog attach: `LOAD mssql; ATTACH 'Server=127.0.0.1,1433;Database=erp_production;User Id=sa;Password=DevPassword!123;TrustServerCertificate=yes' AS ms (TYPE mssql);`
     - Native T-SQL passthrough: `SELECT * FROM mssql_scan('ms', 'SELECT ...')` or direct table query `SELECT * FROM ms.dbo.table_name`.
  2. `pgsql` (**PostgreSQL**) — connected via DuckDB 2.0 core extension `postgres`:
     - Catalog attach: `LOAD postgres; ATTACH 'host=127.0.0.1 port=5432 user=etl_user password=etl_password dbname=source_db' AS pg (TYPE postgres, READ_ONLY);`
     - Native SQL passthrough: `SELECT * FROM postgres_query('pg', 'SELECT ...')` or direct table query `SELECT * FROM pg.public.table_name`.
  3. `oracle` (**Oracle Database**) — connected via DuckDB 2.0 community extension `oracle_scanner` (native TNS/TTC):
     - Secret & Attach: `LOAD oracle_scanner; CREATE SECRET ora_sec (TYPE oracle, DSN '127.0.0.1:1521/FREEPDB1', USER 'app', PASSWORD 'app'); ATTACH 'ora_sec' AS ora (TYPE oracle_scanner);`
     - Native Oracle SQL passthrough: `SELECT * FROM oracle_query('ora_sec', 'SELECT ... FROM DUAL')` or direct table query `SELECT * FROM ora.APP.TABLE_NAME`.
- **DuckDB 2.0 Binary & Invocation Rules**:
  - Installed globally at `/home/v25050017/.local/share/mise/installs/node/22.23.1/bin/duckdb` (`duckdb` on `PATH`) and locally at `storage/bin/duckdb` (`v2.0.0`) with pre-installed extensions: `postgres`, `mssql`, `oracle_scanner`, `excel`.
  - **CRITICAL CLI FLAG**: Always invoke `duckdb` with `-batch -light-mode` (e.g., `duckdb -batch -light-mode -json ...`) to prevent the 5-second terminal background color detection timeout in non-TTY subprocesses!
- **Snapshot Pagination & Security Boundary**:
  - Never re-query external databases (`sqlsrv`, `pgsql`, `oracle`) when paginating (page 2, 3...) or exporting!
  - Every report search materializes results into a session/query-scoped snapshot file at `storage/app/snapshots/{snapshot_id}.duckdb` with a deterministic `__row_id` column and `report_meta` table.
  - Before running user/federation SQL on staged data, detach external catalogs and run `SET enable_external_access = false;` to prevent arbitrary local file reads.
  - Pagination reads 11 rows (`LIMIT 11 OFFSET ?`) from the snapshot to display 10 rows per page and detect `has_next_page`.
- **Export Standards (Strictly 2 Formats: Real `.xlsx` & UTF-8 `.csv`)**:
  - **Excel (`.xlsx`)**: Must use DuckDB 2.0's core `excel` extension (`LOAD excel; COPY (...) TO '...xlsx' WITH (FORMAT xlsx, HEADER true, SHEET 'SmartReport');`) producing a genuine OpenXML `.xlsx` binary (never fake HTML `.xls`).
  - **CSV (`.csv`)**: Must use `COPY (...) TO '...csv' WITH (FORMAT csv, HEADER true, DELIMITER ',')` with UTF-8 BOM (`\xEF\xBB\xBF`) prepended for Excel compatibility.
  - Both formats must support automatic multi-part splitting (`_part1`, `_part2`...) packaged into a `.zip` archive when row count exceeds `splitRows`.

---

## 2. WSL & `docker-db` Test Databases (Do NOT Use Legacy DAR SQL)

Do **not** import or use legacy SQL queries from `../DAR/App_Data/custom-views.json`. All development and automated testing must use the real enterprise databases running in this WSL environment (`/home/v25050017/docker-db/compose.yml`):

| Source Code | Engine | Host : Port | Database / Service | Credentials | Purpose in Tests |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `pg_north` | PostgreSQL 16 (`etl_postgres`) | `127.0.0.1:5432` | `source_db` | `etl_user` / `etl_password` | Plant North contracts & shipment details (`public.v_epcontract`, `public.v_epcontract_detail`) |
| `pg_south` | PostgreSQL 16 (`dev-postgres`) | `127.0.0.1:5433` | `app` | `app` / `app` | Plant South contracts & shipment details (`public.v_epcontract`, `public.v_epcontract_detail`) |
| `mssql_erp` | SQL Server 2022 (`dev-mssql`) | `127.0.0.1:1433` | `erp_production` | `sa` / `DevPassword!123` | Production work orders & machine shift logs (`dbo.production_orders`, `dbo.machine_logs`) |
| `oracle_hr` | Oracle 23ai Free (`dev-oracle`) | `127.0.0.1:1521` | `FREEPDB1` | `app` / `app` (or `system` / `oracle`) | HR employees & department cost centers (`APP.EMPLOYEES`, `APP.DEPARTMENTS`) |

---

## 3. Mandatory Skill Activation & `/teamwork` Routing

Catalog: [`.agents/skills/README.md`](.agents/skills/README.md). Load only the skills required for your role:

| Role / Task | Skills to Activate (`.agents/skills/<name>/SKILL.md`) |
| :--- | :--- |
| **`engine`** (DuckDB Engine, Snapshot, `.xlsx`/`.csv` Exporter, Migrations) | `laravel-best-practices` + `security-best-practices` |
| **`admin-ui`** (Filament Resources: Data Sources, Menu, Roles, CustomView Builder) | `laravel-best-practices` + `tailwindcss-development` + `security-best-practices` |
| **`report-ui`** (`ReportRunnerPage` 3-Tab UI, Multi-DB Selector, `SqlExportPage`) | `laravel-best-practices` + `tailwindcss-development` |
| **`verifier` (PHPUnit / Integration)** | `testing-best-practices` + `phpunit-skill` |
| **`verifier` (Browser E2E / UI)** | `playwright-cli` + `testing-best-practices` |
| **Security & Threat Review** | `security-threat-model` + `security-best-practices` |
| **Small-Model Task Compilation** | `small-agent-task-compiler` |

---

## 4. Ponytail — Lazy Senior Dev Rules

1. **YAGNI**: Do not build unrequested abstractions, interfaces with one implementation, or speculative background queues.
2. **Reuse before writing**: Use Filament 5 native Forms/Tables/Actions/Notifications and DuckDB 2.0 native `ATTACH` / `COPY ... FORMAT xlsx` / `COPY ... FORMAT csv` instead of writing custom parsers or spreadsheet builders.
3. **Commit Gate per Cluster**: Every work cluster must end with passing tests (`php artisan test`, `vendor/bin/pint --dirty --format agent`) and a clean `git commit` before starting the next cluster.
4. **Reuse Subagents**: In `/teamwork`, keep the 4 core workers (`engine`, `admin-ui`, `report-ui`, `verifier`) alive across clusters and assign follow-up work via `@worker-name` rather than killing and re-spawning.

---

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.3 (Laravel 13 + Filament 5 + Livewire 4). Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `.agents/skills/*`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- Follow all existing code conventions used in this application.
- Use descriptive names for variables and methods.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not leave temporary verification scripts behind when PHPUnit and Playwright tests cover that functionality and prove they work.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build`.

=== boost rules ===

# Laravel Boost

## Tools

- Prefer Boost MCP tools (`database-query`, `database-schema`, `search-docs`, `tinker`, `list-routes`, `get-absolute-url`) when available, or standard `php artisan` commands.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion.
- Use explicit return type declarations and type hints for all method parameters.
- Use TitleCase for Enum keys.

=== laravel/core rules ===

# Do Things the Laravel Way

- Pass `--no-interaction` to all Artisan commands.
- Run `vendor/bin/pint --dirty --format agent` before committing PHP changes.
- Run `php artisan test --compact` (or `--filter=...`) to verify changes.

</laravel-boost-guidelines>
