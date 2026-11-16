# Findings

- DAR is an ASP.NET Web Forms/VB.NET website, no solution/project file found.
- DbExecutor uses System.Data.SqlClient and fixed ConnectionStrings("ConnStr"). Report, preview, export, and authentication callers are coupled to SQL Server.
- UI, custom report JSON definitions, filter controls and role/menu services exist.
- SmartReport directory exists; contents not yet inspected.
- Technical documentation is being verified over HTTPS because the web search tool endpoint failed.

- SmartReport was empty before assessment notes.
- Search loads the complete DataTable, then HtmxFragmentRenderer clones rows for a page; each paging request runs report query again. SQL preview also fetches full result before taking 10 rows.
- CsvExporter.ExportSqlQuery bypasses DbExecutor and opens SqlConnection directly.
- CustomViewDefinition has one SqlText and no sources or join SQL.
- Web.config references .NET 3.5 assemblies; actual IIS runtime cannot be determined from repository.
- duckdb.org pages returned 403 over direct HTTPS. GitHub raw README for official DuckDB.NET maintainer succeeded; use primary GitHub sources for verification.

- User clarified UX: about 10 rows per page, fetch enough for pagination if possible. Distinguish output page size from source scan/transfer volume; arbitrary source limits can change join correctness.
- 14 existing custom views; 12 use @ parameters, 6 CONVERT, 4 COLLATE, 3 ISNULL. These dialect assumptions need explicit migration, not automated universal translation.
- No DuckDB/Python DB connectors installed in this workspace runtime. dotnet CLI is available.

- Verified current DuckDB release via GitHub API: v2.0.0 published 2026-10-21, not prerelease. Primary docs under docs/2.0.
- Official core postgres supports direct attached reads and READ_ONLY; repeated queries reread PostgreSQL unless staged locally. Filter pushdown documented; cross-source transfer is workload-dependent.
- Official core odbc/odbc_scanner supports Oracle and SQL Server (Tier 1 DBMS type coverage), custom source SQL and bound parameters. Requires installed DB ODBC driver and unixODBC on Linux/macOS. Not the same as the ODBC client for connecting TO DuckDB.
- Community oracle (cofin) requires Instant Client when connecting; oracle_scanner (krokozyab) advertises protocol-only connection, v0.6.2 for DuckDB2.0 and v0.4.0 for1.5.6. Treat advertised features as documentation, not verified deployment.
- DuckDB.NET current main targets net8.0/net10.0 and release notes refer to engine1.5.6. Verify published NuGet separately, do not equate main or latest engine with compatible package.
- ETLAPP sibling has Oracle.ManagedDataAccess.Core, Npgsql, MySQL providers and DbConnectionFactory (net10.0). Reuse connection providers if selecting modern .NET; do not inherit write/load/scheduling engine just for read reports.

- Published NuGet DuckDB.NET.Data.Full 1.5.6.1 verified by downloading package: lib/net8.0 and lib/net10.0 only. Therefore current package cannot be directly used from DAR Web Forms/.NET Framework. Full package includes native engine through bindings dependency.
- Oracle official ODBC type matrix supports NUMBER, VARCHAR2/NVARCHAR2, temporal and LOB types, but LONG/LONG RAW/ROWID/UROWID/BFILE are unchecked. Use actual source schema in acceptance test.
- .NET smoke check created only under /tmp/dar-smartreport-poc; no app source/dependency added to DAR.

- Important correction: DuckDB.NET current package not supporting Framework does NOT require a new service. Official DuckDB ODBC client supports Windows; System.Data.Odbc.OdbcConnection is available in .NET Framework. Web Forms can plausibly reach DuckDB through this standard API and installed driver. This route was documented, not executed on Windows/IIS.
- Prefer evaluating Web Forms + DuckDB ODBC first when copying DAR is the objective; modern .NET + existing ETLAPP connection providers is an alternative if ODBC deployment or workload tests fail.
- .NET smoke check PASSED using published DuckDB.NET.Data.Full1.5.6.1, native engine v1.5.6. Two attached local DuckDB fixtures, 33 joined rows including duplicate-key expansion; 10-row pages with 11-row lookahead covered every output row once; exact decimal900719925474099.01, NULL, Unicode and timestamp preserved. External file read refused after sources detached and external access disabled. No actual Oracle/PostgreSQL, ODBC, network, timeout, or complete sandbox check.

## Follow-up: platform choice on IIS
- User now permits keeping only similar features, removing a requirement to preserve Web Forms code.
- Laravel sibling DAR-Laravel currently has skeleton controller, User model and welcome route, no custom report services or DuckDB integration found in app/routes/config. Composer requires Laravel13.8/PHP8.3. Existing project is not evidence of completed DAR functionality.
- Official ASP.NET Core IIS docs support Hosting Bundle/ANCM and in-process hosting. Current Microsoft policy marks .NET10 LTS supported through2028-11-14.
- .NET Framework4.x support follows supported Windows lifecycle; do not claim4.8 unsupported. Its value here is reusing current DAR server code, now optional.
- Laravel13 deployment docs require routing through public/index.php, write permissions for storage/bootstrap/cache and restart of long-running services after deploy. PHP IIS FastCGI old article URL404; verify current FastCGI configuration docs instead.

- Verified PHP/FastCGI IIS support from current Microsoft configuration docs and PDO_ODBC from PHP manual. Laravel can reach DuckDB over ODBC; no need to invent a mandatory sidecar or claim PHP cannot use DuckDB.
- Recommendation after clarification: modern ASP.NET Core/.NET10 LTS (IIS + DuckDB.NET + reuse ETLAPP provider pattern), while Framework/Web Forms remains shortest for direct DAR code reuse. No application implementation requested or performed.

## Filament clarification
- User prioritizes Filament productivity and PHP logic updates without application compilation; use fresh resources only and exclude ETLAPP. No further ETLAPP inspection.
- Verified current Filament5 docs: Eloquent Resources generate CRUD forms/tables, custom pages use Livewire, custom data tables support non-Eloquent array records. Custom sorting/filtering/pagination and source/owner authorization remain application responsibilities.
- PDO_ODBC Windows documented by PHP; Laravel caches, Filament component caches and OPcache/long-running workers affect code deployment despite no PHP compilation step.
- Recommendation changed to Laravel + Filament based on clarified development priorities. PHP-to-DuckDB on IIS remains untested; earlier .NET smoke is not evidence for PHP compatibility.
- Web search service still404; official Filament panels/users path404; use Resources/custom pages/custom data docs instead.
