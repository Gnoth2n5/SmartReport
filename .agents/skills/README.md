# SmartReport Skills Catalog

This workspace contains **9 curated project skills** tailored specifically for **SmartReport** (Laravel 13 + Filament 5 + SQLite Config + DuckDB 2.0 Multi-DB Federation). Redundant legacy HRM/Vue/Doctrine/Cypress/Dusk skills have been removed.

Do not load every skill by default — load only the skill(s) matching your current task or `/teamwork` worker role.

---

## 1. Skill Routing by `/teamwork` Role & Task

| Worker Role / Task Area | Mandatory Skills to Load |
| :--- | :--- |
| **`engine`** (Laravel Models, SQLite Config Migrations, DuckDB 2.0 Federation, Snapshot Pagination, `.xlsx`/`.csv` Exporter) | [`laravel-best-practices`](laravel-best-practices/SKILL.md) + [`security-best-practices`](security-best-practices/SKILL.md) |
| **`admin-ui`** (Filament Admin Panel, `DataSourceResource`, `MenuGroupResource`, `RolePermissionResource`, `CustomViewResource` Builder) | [`laravel-best-practices`](laravel-best-practices/SKILL.md) + [`tailwindcss-development`](tailwindcss-development/SKILL.md) + [`security-best-practices`](security-best-practices/SKILL.md) |
| **`report-ui`** (`ReportRunnerPage` 3-Tab UI, Multi-DB Selector, Snapshot Grid, `SqlExportPage`) | [`laravel-best-practices`](laravel-best-practices/SKILL.md) + [`tailwindcss-development`](tailwindcss-development/SKILL.md) |
| **`verifier` — Unit / Feature Tests** (PHPUnit tests for DuckDB engine, RBAC, Filament Resources, `.xlsx`/`.csv` export validation) | [`testing-best-practices`](testing-best-practices/SKILL.md) + [`phpunit-skill`](phpunit-skill/SKILL.md) |
| **`verifier` — Browser E2E Verification** (Playwright E2E suite & interactive browser snapshots/screenshots) | [`playwright-cli`](playwright-cli/SKILL.md) + [`testing-best-practices`](testing-best-practices/SKILL.md) |
| **Security Review / SQL Guard / Credential Encryption** | [`security-threat-model`](security-threat-model/SKILL.md) + [`security-best-practices`](security-best-practices/SKILL.md) |
| **Subagent Task Packet Compilation** (When delegating to small/local models) | [`small-agent-task-compiler`](small-agent-task-compiler/SKILL.md) |

---

## 2. Installed Skills Reference

| Skill | Purpose |
| :--- | :--- |
| [`laravel-best-practices`](laravel-best-practices/SKILL.md) | Laravel 13 & PHP 8.3 conventions, Eloquent, Services, Actions, Config, and Artisan workflows (installed via Laravel Boost). |
| [`tailwindcss-development`](tailwindcss-development/SKILL.md) | Tailwind CSS & Filament Blade UI styling for custom pages (`ReportRunnerPage`, `SqlExportPage`, 3-Tab DAR header). |
| [`testing-best-practices`](testing-best-practices/SKILL.md) | Test coverage strategy, test isolation, and assertion patterns (installed via Laravel Boost). |
| [`phpunit-skill`](phpunit-skill/SKILL.md) | PHPUnit 12 unit and feature test authoring for Laravel/Filament components and DuckDB services. |
| [`playwright-cli`](playwright-cli/SKILL.md) | Browser automation, Playwright E2E test execution, snapshots, console/network inspection, and screenshot capture. |
| [`security-best-practices`](security-best-practices/SKILL.md) | Security rules for SQL parameter binding, read-only enforcement, DuckDB `enable_external_access = false`, and encrypted credentials. |
| [`security-threat-model`](security-threat-model/SKILL.md) | Threat modeling for untrusted/admin SQL queries, cross-database federation, snapshot isolation, and RBAC boundaries. |
| [`infer-conventions`](infer-conventions/SKILL.md) | Discover and record codebase conventions (installed via Laravel Boost). |
| [`small-agent-task-compiler`](small-agent-task-compiler/SKILL.md) | Compile invariant-traceable task packets and validate execution receipts for small coding agents. |
