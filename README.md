# SmartReport (DAR Next-Gen)

Nền tảng báo cáo và phân tích dữ liệu đa nguồn (Multi-Database Analytics & Federation Platform) xây dựng trên **Laravel 13**, **Filament 5**, và **DuckDB 2.0**.

## Kiến trúc & Tính năng chính

- **SQLite Metadata Store** (`database/database.sqlite`): Lưu trữ cấu hình nguồn dữ liệu (mật khẩu mã hóa AES-256), nhóm menu, phân quyền RBAC (`RolePermission`), và định nghĩa báo cáo (`CustomView`).
- **DuckDB 2.0 Federation Engine**: Kết nối trực tiếp 3 hệ quản trị CSDL doanh nghiệp (`pgsql`, `sqlsrv`, `oracle`) qua các extension gốc (`postgres`, `mssql`, `oracle_scanner`). Hỗ trợ 2 chế độ thực thi:
  - `single_or_union`: Truy vấn 1 DB hoặc gộp nhiều DB cùng cấu trúc (`UNION ALL` tự động gắn nhãn `_source_db`).
  - `federated_join`: Kết nối chéo (Cross-Engine JOIN / CTE) giữa PostgreSQL, SQL Server và Oracle trong cùng một báo cáo.
- **Detached Snapshot & Zero-Requery Pagination**: Kết quả truy vấn được materialize vào file snapshot độc lập (`storage/app/snapshots/{uuid}.duckdb`), ngắt kết nối DB ngoài (`DETACH`) và khóa truy cập hệ thống file (`SET enable_external_access = false`). Phân trang 10 dòng/trang đọc trực tiếp từ snapshot với độ trễ mili-giây và 0 truy vấn lặp lại vào DB nguồn.
- **SQL Safety & Parameter Extractor**:
  - `SqlSafetyValidator`: Tokenizer tuần tự chặn tuyệt đối DDL/DML, stacked queries, comment/quote desync và các hàm đọc/ghi file của DuckDB (`read_*`, `*_scan`, `COPY`, `getenv`, `duckdb_secrets`).
  - `SqlParameterExtractor`: Tự động quét biến `@param` (hỗ trợ `@today`) và suy luận kiểu bộ lọc (`date`, `number`, `select`, `text`).
- **Export Pipeline**: Xuất trực tiếp từ snapshot sang OpenXML Excel (`.xlsx` qua extension `excel`), CSV có UTF-8 BOM (`\xEF\xBB\xBF` hiển thị chuẩn tiếng Việt trên Excel), hoặc chia nhỏ nhiều file đóng gói `.zip`.
- **Giao diện Quản trị & Khai thác (Filament 5)**:
  - Quản lý `DataSource` (kèm nút kiểm tra kết nối & đo latency trực tiếp), `MenuGroup`, `RolePermission`, `CustomView` (nhận diện `@param` tự động & chạy thử 10 dòng).
  - Sidebar động theo quyền người dùng (`DynamicSidebarBuilder`).
  - `ReportRunnerPage` (`/admin/reports/{viewCode}`): Giao diện 3 tab (*Tổng quan*, *Application Information*, *SQL Query Inspector* tự động che mật khẩu).
  - `SqlExportPage` (`/admin/sql-export`): Xuất dữ liệu Ad-hoc SQL ra `.xlsx`, `.csv`, `.zip`.

## Yêu cầu hệ thống

- PHP `^8.3` (extensions: `sqlite3`, `zip`, `mbstring`, `xml`, `curl`, `intl`)
- Composer & Node.js / NPM
- DuckDB CLI `v2.0.0` (đặt tại `storage/bin/duckdb` hoặc cấu hình qua biến môi trường `DUCKDB_BINARY`) with extensions: `postgres`, `mssql`, `oracle_scanner`, `excel`
- (Tùy chọn cho môi trường test/demo) 4 Docker Database containers:
  - `pg_north`: PostgreSQL (`127.0.0.1:5432`, db `source_db`, user `etl_user` / `etl_password`)
  - `pg_south`: PostgreSQL (`127.0.0.1:5433`, db `app`, user `app` / `app`)
  - `mssql_erp`: SQL Server (`127.0.0.1:1433`, db `erp_production`, user `sa` / `DevPassword!123`)
  - `oracle_hr`: Oracle 23ai (`127.0.0.1:1521`, service `FREEPDB1`, schema `APP`, user `app` / `app`)

## Cài đặt & Khởi chạy

```bash
# 1. Cài đặt dependencies, tạo .env, migrate SQLite và build assets
composer setup
composer check-platform-reqs

# 2. Seed cấu hình mẫu (không sửa dữ liệu DB ngoài)
php artisan db:seed --no-interaction

# Chỉ chạy khi cần khởi tạo/reset bảng mẫu trên 4 Docker DB
php artisan smartreport:provision-test-databases --no-interaction

# 3. Khởi chạy server phát triển (http://127.0.0.1:8000/admin)
composer dev
```

### Tài khoản mặc định

| Email | Mật khẩu | Vai trò |
|---|---|---|
| `admin@smartreport.test` | `password` | `super_admin` (Toàn quyền quản trị & báo cáo) |

## Cấu trúc thư mục chính

```text
app/
├── Filament/
│   ├── Pages/              # ReportRunnerPage (3-tab viewer), SqlExportPage (ad-hoc export)
│   └── Resources/          # DataSource, MenuGroup, RolePermission, CustomView resources
├── Models/                 # DataSource, MenuGroup, RolePermission, CustomView, User
└── Services/
    ├── Database/           # ExternalTestDatabaseProvisioner (provision 4 Docker DBs)
    ├── DuckDb/             # DuckDbSourceAdapter, DuckDbReportEngine, SnapshotManager
    ├── Export/             # DuckDbExporter (.xlsx, UTF-8 BOM .csv, chunked .zip)
    ├── Navigation/         # DynamicSidebarBuilder (menu động theo RBAC)
    └── Sql/                # SqlSafetyValidator, SqlParameterExtractor
config/
└── smartreport.php         # Cấu hình đường dẫn DuckDB binary, thư mục snapshot, TTL, phân trang
```

## Kiểm thử (Testing)

```bash
# Chạy toàn bộ Feature & Unit tests (PHPUnit)
php artisan test

# Chạy kiểm thử theo từng cụm tính năng
php artisan test --filter=Cluster1EnvironmentAndSeedTest
php artisan test --filter=MultiDbEngineAndExporterTest
php artisan test --filter=FilamentAdminResourcesTest
php artisan test --filter=CustomViewBuilderTest
php artisan test --filter=ReportRunnerAndSqlExportTest

# Chạy bộ kiểm thử E2E trên trình duyệt (Playwright)
npx playwright test
```

## Dọn snapshot hết hạn

Ứng dụng từ chối phân trang/xuất snapshot hết hạn. Scheduler dọn file cũ mỗi 5 phút.
Chạy scheduler trong môi trường phát triển bằng `php artisan schedule:work --no-interaction`,
hoặc thêm `php artisan schedule:run --no-interaction` vào cron mỗi phút khi triển khai.

## Cập nhật ảnh bằng chứng SIT

Ảnh SIT được lưu trong vùng `storage/app/private/sit-evidence` và chỉ được phục vụ
qua đợt test có quyền truy cập. Khi cập nhật bản cũ, chạy
`php artisan migrate --no-interaction` rồi `php artisan smartreport:secure-sit-evidence --no-interaction`
để chuyển ảnh công khai sang vùng riêng; đường dẫn đã lưu và ảnh dùng chung khi retest được giữ nguyên.
