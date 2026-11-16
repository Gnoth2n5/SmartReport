# Đánh giá tính khả thi SmartReport

Ngày đánh giá: 12/11/2026.

Khả thi: có thể giữ cách sử dụng của DAR — tạo báo cáo bằng SQL, cấu hình bộ lọc, phân quyền, xem bảng và xuất dữ liệu — rồi thêm SELECT từ nhiều nguồn và JOIN trong DuckDB. Phần cần thiết kế lại là thực thi truy vấn và phân trang. Đổi connection string của DAR là chưa đủ.

Yêu cầu UX đã xác nhận: khoảng 10 dòng mỗi trang, có thể lấy thêm dữ liệu để phân trang. Chưa có số liệu về số dòng đọc từ từng nguồn, độ rộng bản ghi, số người dùng đồng thời hoặc yêu cầu độ mới.

## 1. Căn cứ từ mã DAR

SmartReport chưa có mã ứng dụng trước lần đánh giá này. DAR là website ASP.NET Web Forms/VB.NET, sử dụng HTMX và Bootstrap.

| Phần hiện có | Căn cứ | Ý nghĩa khi sao chép |
|---|---|---|
| Thực thi SQL | [DbExecutor.vb](../App_Code/Infrastructure/Db/DbExecutor.vb), dòng 7–36 | Cố định `ConnStr`, `SqlConnection`, `SqlParameter`; chỉ phục vụ SQL Server |
| Định nghĩa báo cáo | [CustomViewDefinition.vb](../App_Code/Models/Custom/CustomViewDefinition.vb), dòng 5–14 | Đã có tên, SQL, bộ lọc, quyền; cần thêm danh sách nguồn và SQL JOIN |
| Lưu cấu hình và đồng bộ menu | [CustomViewService.vb](../App_Code/Services/Custom/CustomViewService.vb) | Có thể giữ phần JSON, cache và menu; kiểm tra SQL/tham số phải theo engine |
| Tìm kiếm, xem trước, xuất | [CustomFragmentHandler.vb](../App_Code/Infrastructure/Web/Fragments/CustomFragmentHandler.vb), dòng 66–85, 140–170, 194–240 | Cả ba luồng đang gọi SQL Server và tải toàn bộ kết quả |
| Phân trang | [HtmxFragmentRenderer.vb](../App_Code/Infrastructure/Web/HtmxFragmentRenderer.vb), dòng 24–35 | Cắt trang từ DataTable đã tải; chuyển trang gọi lại truy vấn nguồn |
| Xuất SQL trực tiếp | [CsvExporter.vb](../App_Code/Infrastructure/Export/CsvExporter.vb), dòng 217–239 | Tự mở SqlConnection, bỏ qua DbExecutor; phải đưa vào cùng đường chạy báo cáo |
| Xác thực | [AuthSessionService.vb](../App_Code/Services/Security/AuthSessionService.vb), dòng 78–82 | Cũng dùng DbExecutor để đọc tài khoản; cần giữ kết nối xác thực riêng với nguồn báo cáo |

Có 14 custom view. Trong số đó, 12 dùng tham số `@`, 6 dùng `CONVERT`, 4 dùng `COLLATE`, 3 dùng `ISNULL`. Các câu SQL cần được kiểm tra theo dialect của engine thực sự chạy chúng. Không chuyển toàn bộ bằng thay chuỗi hoặc regex.

Web.config tham chiếu assembly .NET 3.5, nhưng chưa đủ để xác định CLR/.NET Framework đang chạy trên IIS.

## 2. DuckDB kết nối được những nguồn nào?

| Nguồn | Đường khả thi | Điều kiện |
|---|---|---|
| PostgreSQL | Core extension `postgres`: ATTACH, đọc bảng, hoặc chạy SQL nguồn | Cấu hình chỉ đọc; đo lượng dữ liệu thực sự chuyển về |
| Oracle | Core extension `odbc`/`odbc_scanner`, chạy SELECT trên Oracle | Cài Oracle ODBC driver/client phù hợp với máy chạy |
| Oracle, phương án khác | Community extension `oracle` hoặc `oracle_scanner` | Kiểm chứng compatibility, authentication, types và độ ổn định của phiên bản chọn |
| MySQL, SQLite | Có core extension tương ứng | Kiểm tra schema/types và quyền theo báo cáo cụ thể |
| SQL Server và nguồn ODBC khác | Core extension `odbc` | Cần driver của từng DB; hỗ trợ không đồng nghĩa mọi kiểu dữ liệu đều dùng được |

Các khả năng này được xác nhận từ [danh sách core extension](https://github.com/duckdb/duckdb-web/blob/main/docs/2.0/core_extensions/overview.md), [PostgreSQL](https://github.com/duckdb/duckdb-web/blob/main/docs/2.0/core_extensions/postgres/overview.md), [ODBC scanner](https://github.com/duckdb/duckdb-web/blob/main/docs/2.0/core_extensions/odbc/overview.md) và registry community cho [oracle](https://github.com/duckdb/community-extensions/blob/main/extensions/oracle/description.yml), [oracle_scanner](https://github.com/duckdb/community-extensions/blob/main/extensions/oracle_scanner/description.yml). Đây là kiểm tra tài liệu, chưa phải kiểm tra kết nối thực tế.

Ví dụ giới hạn cụ thể: bảng kiểm chứng Oracle của ODBC scanner chưa đánh dấu hỗ trợ LONG, LONG RAW, ROWID, UROWID, BFILE. Cần đối chiếu schema thật trước khi chốt. [Ma trận kiểu Oracle](https://github.com/duckdb/odbc-scanner/blob/main/test/sql/oracle/README.md).

## 3. Giữ Web Forms hay chuyển nền tảng?

Đường ít thay đổi nhất cần thử trước là giữ Web Forms/VB.NET cho SmartReport, sử dụng `System.Data.Odbc` gọi DuckDB qua DuckDB ODBC driver. API này có trong .NET Framework và DuckDB có driver Windows chính thức. Cần kiểm tra trên Windows/IIS, kiến trúc x64, quyền của application pool, tham số và kiểu dữ liệu. Khả năng này là suy luận từ hai API được tài liệu hóa, chưa có thử nghiệm tích hợp tại môi trường triển khai. [Microsoft OdbcConnection](https://learn.microsoft.com/en-us/dotnet/api/system.data.odbc.odbcconnection?view=netframework-4.8.1), [DuckDB ODBC trên Windows](https://github.com/duckdb/duckdb-web/blob/main/docs/2.0/clients/odbc/windows.md).

Cần phân biệt hai thành phần: DuckDB ODBC **driver** để VB.NET gọi DuckDB; DuckDB ODBC **extension** để DuckDB đọc Oracle hoặc DB khác. [Giải thích hai chiều kết nối](https://github.com/duckdb/duckdb-web/blob/main/docs/2.0/clients/odbc/overview.md).

Nếu chọn .NET hiện đại, có thể dùng DuckDB.NET và các driver DB mới để stream SELECT vào DuckDB qua appender. Theo ràng buộc bổ sung của người dùng, phương án không dựa vào mã hoặc provider từ ETLAPP.

Gói phát hành `DuckDB.NET.Data.Full 1.5.6.1` đã được tải và kiểm tra: chỉ có target `net8.0`, `net10.0`, nên không gắn trực tiếp vào Web Forms/.NET Framework. Chọn đường này cần chuyển phần server hoặc đặt một tiến trình thực thi riêng. [Gói NuGet](https://www.nuget.org/packages/DuckDB.NET.Data.Full/1.5.6.1), [cấu hình build của maintainer](https://github.com/Giorgi/DuckDB.NET/blob/main/Directory.Build.props).

## 4. Luồng báo cáo và phân trang đề xuất

1. Admin chọn các nguồn đã được cấu hình, đặt alias và SQL SELECT cho từng nguồn; khai báo SQL JOIN trong DuckDB. Định nghĩa chỉ lưu source ID, không lưu mật khẩu cùng JSON báo cáo.
2. Khi người dùng tìm kiếm, server kiểm tra quyền và tham số, thực thi SELECT đã lọc tại các DB nguồn. Lấy đúng cột cần JOIN/hiển thị, có giới hạn tài nguyên và thời gian.
3. DuckDB nhận dữ liệu hoặc đọc qua extension, JOIN rồi tạo một kết quả tạm cho lần tìm kiếm đó. Nếu vượt ngưỡng, yêu cầu thu hẹp điều kiện; không âm thầm cắt nguồn rồi báo kết quả đầy đủ.
4. Phân trang trên kết quả tạm: lấy tối đa 11 dòng, hiển thị 10; dòng thứ 11 xác định có trang tiếp theo. Có ORDER BY với khóa phân biệt các dòng kết quả, hoặc ID nội bộ cố định của snapshot.
5. Chuyển trang và xuất dùng cùng kết quả tạm. Snapshot phải tồn tại qua nhiều HTTP request: giữ tài nguyên do server quản lý hoặc file riêng cho lần chạy; mở một kết nối `:memory:` mới ở mỗi trang sẽ mất dữ liệu cũ. Đổi bộ lọc hoặc làm mới tạo lần tìm kiếm mới; snapshot có thời hạn, gắn với quyền/người dùng và phiên bản báo cáo.

Nếu cần giữ tổng số dòng/tổng trang như DAR, đếm trên kết quả tạm trong DuckDB. Không truy vấn lại Oracle/PostgreSQL chỉ để biết có trang tiếp theo.

Frontend chỉ cần nhận 10 dòng. DuckDB/server có thể vẫn phải đọc rất nhiều dòng để trả ra 10 dòng JOIN, GROUP BY hoặc ORDER BY đúng. `LIMIT` là giới hạn kết quả; optimizer có thể đẩy một số xử lý xuống nguồn, nhưng không bảo đảm mọi cross-source JOIN chỉ lấy 10 dòng. Giới hạn tùy ý từng nguồn trước JOIN thay đổi tập kết quả. [Ngữ nghĩa LIMIT/OFFSET](https://github.com/duckdb/duckdb-web/blob/main/docs/2.0/sql/query_syntax/limit.md).

Nếu sau benchmark lượng đọc ban đầu vẫn quá lớn, bước tiếp theo là snapshot theo lịch hoặc tối ưu từng báo cáo. Chưa có căn cứ để xây lịch đồng bộ cho bản đầu.

## 5. Phần phải thay đổi và rủi ro thực tế

- Model và builder: danh sách source ID/alias/SQL, SQL JOIN và điều kiện lọc; phân quyền nguồn ở server.
- Executor báo cáo dùng chung cho chạy thử, tìm kiếm và xuất; binding tham số theo provider/engine, không nối giá trị người dùng vào SQL.
- Renderer nhận dữ liệu của một trang và metadata; không dùng `DataTable.Rows.Count` của toàn bộ nguồn làm cơ chế phân trang.
- Authentication giữ kho tài khoản riêng. Credentials nguồn nằm ở cấu hình server; không gửi connection string ra trình duyệt hoặc log lỗi nguyên văn.
- Kiểm tra lại mọi nơi gọi `ValidateSelectSql`, `ExtractSqlParameters`, DbExecutor và các script kiểm tra phụ thuộc SqlClient khi triển khai.

Bộ chặn SQL bằng regex hiện có không đủ làm ranh giới bảo mật cho DuckDB. Một SELECT có thể đọc file, truy cập mạng hoặc gọi chức năng extension. Cần nguồn dùng tài khoản chỉ đọc, giới hạn các nguồn/extension, bind tham số và quyền hệ điều hành phù hợp. Nếu cho người không đáng tin cung cấp SQL tùy ý, cần sandbox thực sự; prepared statement chỉ bảo vệ giá trị tham số. [Hướng dẫn bảo mật DuckDB](https://github.com/duckdb/duckdb-web/blob/main/docs/2.0/operations_manual/securing_duckdb/overview.md).

Chuẩn hóa theo schema thật: Oracle NUMBER/DATE, PostgreSQL numeric/timestamp, số thập phân vượt khả năng .NET decimal, timezone, NULL/chuỗi rỗng, Unicode và tên cột. JOIN nhiều nguồn không tự bảo đảm tất cả DB được đọc tại cùng một thời điểm; lưu thời gian đọc của từng nguồn nếu báo cáo cần giải thích độ mới.

Trong đường embedded, tránh nhiều IIS worker/instance cùng ghi một file DuckDB. Một tiến trình sở hữu snapshot hoặc mỗi lần chạy có dữ liệu độc lập là đủ cho bản đầu. DuckDB 2.0 có giao thức remote cho nhu cầu nhiều tiến trình, nhưng chưa cần đưa vào phạm vi này. [Concurrency](https://github.com/duckdb/duckdb-web/blob/main/docs/2.0/connect/concurrency.md).

## 6. Đã kiểm chứng và điều kiện chốt

Đã chạy self-check tại `/tmp/dar-smartreport-poc/Check.csproj`, qua .NET 10 và gói DuckDB.NET.Data.Full 1.5.6.1. Native engine thực tế là v1.5.6; không coi kết quả này là kiểm thử engine 2.0 hoặc driver ODBC.

Kết quả: PASS. Hai file DuckDB giả lập độc lập được ATTACH chỉ đọc; JOIN tạo 33 dòng, gồm trường hợp khóa phía phải trùng. Các trang 10 dòng với 1 dòng nhìn trước phủ đủ 33 dòng, không lặp. Giữ chính xác decimal `900719925474099.01`, NULL, tiếng Việt và timestamp. Check riêng xác nhận cắt nguồn trước JOIN làm thiếu kết quả. Sau staging và ngắt nguồn, thao tác đọc file bị từ chối khi tắt external access; đây chỉ là một check giới hạn, không phải chứng nhận sandbox.

Để chốt triển khai, chạy một báo cáo thật qua Oracle + PostgreSQL trên môi trường đích và kiểm tra:

1. Kết nối, authentication/TLS, binding tham số và schema/type mapping.
2. JOIN đúng với khóa trùng, NULL, ngày giờ và số thập phân; so kết quả với bộ dữ liệu đối chiếu.
3. Trang 10 dòng và xuất cùng một snapshot; chuyển trang không gọi lại DB nguồn.
4. Đo thời gian trang đầu, số dòng/byte chuyển từ nguồn, RAM/disk và vài người dùng đồng thời; kiểm tra hủy/timeout.
5. Người dùng không đọc được nguồn hoặc snapshot ngoài quyền; driver chạy đúng dưới IIS nếu giữ Web Forms.

Kết luận cập nhật: với ưu tiên Filament để giảm công sức làm giao diện quản trị, sửa logic PHP thường xuyên và xây bằng tài nguyên mới, đề xuất Laravel + Filament trên IIS. Khả năng PHP → DuckDB → Oracle/PostgreSQL vẫn cần thử nghiệm thực tế; xem mục 8.

## 7. Chọn nền tảng khi chỉ cần tính năng tương tự, triển khai IIS

So sánh này ghi lại căn cứ vận hành trên IIS và tự tích hợp DuckDB. Khi đưa thêm Filament và ưu tiên giảm công sức quản trị vào quyết định, đề xuất cuối cùng chuyển sang Laravel + Filament ở mục 8. .NET Framework/Web Forms và ASP.NET Core vẫn là hai nền tảng khác nhau.

| Tiêu chí | Laravel | .NET Framework / Web Forms | ASP.NET Core / .NET 10 |
|---|---|---|---|
| Triển khai IIS | PHP/FastCGI, routing về public/index.php, extensions và quyền ghi | Tích hợp với IIS, gần cách triển khai DAR hiện tại | Hosting Bundle/ASP.NET Core Module và publish ứng dụng |
| Tái sử dụng DAR | Viết lại phần server | Giữ được nhiều VB.NET/ASPX/ASCX nhất | Viết lại phần Web Forms, giữ HTML/HTMX/Bootstrap và thiết kế chức năng |
| DuckDB | Có thể dùng PDO_ODBC và driver; cần phần tích hợp SQL ngoài các DB driver Laravel mặc định | System.Data.Odbc + DuckDB ODBC driver; chưa kiểm tra tại IIS | Có DuckDB.NET nhắm net8/net10; self-check local đã chạy |
| Giá trị chính ở dự án này | Phù hợp nếu đội ngũ mạnh PHP/Laravel hoặc có nhiều mã nghiệp vụ Laravel để dùng lại | Phù hợp khi cần sao chép DAR nhanh hoặc phải giữ legacy | Phù hợp khi xây SmartReport mới với chức năng tương tự và chạy trên IIS |

ASP.NET Core có tài liệu triển khai IIS qua Hosting Bundle và hỗ trợ in-process hosting. .NET 10 là LTS, còn được hỗ trợ đến 14/11/2028 theo chính sách kiểm tra ngày 12/11/2026. [.NET trên IIS](https://learn.microsoft.com/en-us/aspnet/core/host-and-deploy/iis/?view=aspnetcore-10.0), [vòng đời .NET](https://dotnet.microsoft.com/en-us/platform/support/policy/dotnet-core).

.NET Framework 4.x vẫn có hỗ trợ theo vòng đời của Windows tương ứng. Lý do ưu tiên .NET hiện đại ở đây là triển khai ứng dụng mới và tương thích gói DuckDB.NET hiện hành, không phải giả định .NET Framework 4.8 đã hết hỗ trợ. [Chính sách .NET Framework](https://learn.microsoft.com/en-us/lifecycle/faq/dotnet-framework).

Laravel có thể chạy trên IIS: PHP thông qua FastCGI; ứng dụng phải routing về public/index.php và có quyền ghi cho storage/bootstrap/cache. PHP có PDO_ODBC, nên Laravel không bắt buộc phải dùng một dịch vụ khác chỉ để gọi DuckDB. Tuy nhiên, cần xây phần tích hợp đó; DuckDB và Oracle không nằm trong danh sách driver database mặc định của Laravel. [PHP/FastCGI trên IIS](https://learn.microsoft.com/en-us/iis/configuration/system.webserver/fastcgi/), [Laravel deployment](https://laravel.com/framework/docs/13.x/deployment), [PDO_ODBC](https://www.php.net/manual/en/ref.pdo-odbc.php), [Laravel database](https://laravel.com/framework/docs/13.x/database).

Thư mục `/home/v25050017/project/laravel/DAR/DAR-Laravel` được kiểm tra ở mức cấu hình và app/routes: hiện có controller, User model và route mặc định, chưa có dịch vụ báo cáo tương ứng DAR hoặc tích hợp DuckDB. Vì vậy chưa có căn cứ chọn Laravel dựa trên việc đã có sẵn chức năng.

Phương án nếu chọn .NET: một ứng dụng ASP.NET Core với MVC hoặc Razor Pages, giao diện HTMX/Bootstrap, provider đọc Oracle/PostgreSQL, DuckDB.NET để JOIN và kết quả tạm phân trang 10 dòng. HTML có thể dùng lại sau điều chỉnh; lifecycle, control và code-behind Web Forms phải chuyển sang cách xử lý của ASP.NET Core. Giữ phạm vi ở tính năng báo cáo đã yêu cầu.

Nếu cần tận dụng mã Web Forms, .NET Framework thuận lợi hơn cho việc sao chép DAR. Nếu tự xây giao diện và ưu tiên vận hành .NET trên IIS, ASP.NET Core là ứng viên phù hợp. Khi dùng Filament cho giao diện quản trị và chỉ cần chức năng tương tự, Laravel có lợi thế xây dựng cụ thể; xem mục 8.

## 8. Quyết định cập nhật: Laravel + Filament, dùng tài nguyên mới

Người dùng bổ sung hai ưu tiên: Filament giảm công sức xây dựng màn hình, PHP cho phép sửa logic không qua bước biên dịch ứng dụng; chỉ dùng tài nguyên mới và loại ETLAPP khỏi căn cứ tương thích/tái sử dụng. Không kiểm tra thêm ETLAPP.

Theo các ưu tiên này, đề xuất Laravel + Filament. Đây là đánh giá về công sức phát triển và vận hành theo mục tiêu người dùng, chưa phải so sánh hiệu năng. .NET vẫn có đường triển khai thuận lợi trên IIS, nhưng chưa có căn cứ cho rằng lợi ích đó lớn hơn công sức giao diện Filament tiết kiệm được ở dự án này.

Filament Resources cung cấp Forms, Tables và Actions cho các model cấu hình: nguồn dữ liệu, báo cáo, bộ lọc, người dùng. Quyền truy cập phải được định nghĩa qua policies và kiểm tra ở server. [Filament Resources](https://filamentphp.com/docs/5.x/resources/overview).

Trang chạy báo cáo có thể dùng custom page và bảng custom data nhận kết quả DuckDB. Sorting, filtering, pagination và quyền dữ liệu tùy chỉnh do ứng dụng xử lý; Filament không tự chạy các phần đó hoặc tự áp dụng model policies cho array records. [Custom pages](https://filamentphp.com/docs/5.x/navigation/custom-pages), [Custom data tables](https://filamentphp.com/docs/5.x/tables/custom-data).

Bản đầu dùng một ứng dụng Laravel + Filament trên IIS/PHP FastCGI, một kho cấu hình ứng dụng, và DuckDB cho xử lý báo cáo. Thử PDO_ODBC với DuckDB ODBC driver trước, kết nối Oracle/PostgreSQL bằng extension hoặc driver phù hợp. PHP trên Windows có PDO_ODBC dùng Windows ODBC Driver Manager. Đây là đường tích hợp khả thi từ tài liệu, chưa có self-check PHP/Windows tại môi trường đích. [PHP PDO_ODBC](https://www.php.net/manual/en/ref.pdo-odbc.php).

Nếu logic là SQL, bộ lọc hoặc cấu hình báo cáo được lưu trong kho cấu hình, cả Laravel và .NET đều có thể thay đổi mà không build lại ứng dụng. Lợi thế không biên dịch của PHP nằm ở việc sửa mã backend PHP thuần. Không cần biên dịch lại backend khi sửa logic PHP thuần. Tuy nhiên production vẫn cần quy trình deploy cập nhật Laravel/Filament caches, OPcache và worker nếu có. Khi OPcache không kiểm tra timestamp, thay file PHP chưa làm code mới có hiệu lực đến khi invalidation/reset hoặc restart đúng tiến trình. Sửa frontend/assets hoặc dependencies có thể cần bước build/install tương ứng. [Laravel deployment](https://laravel.com/framework/docs/13.x/deployment), [Filament deployment](https://filamentphp.com/docs/5.x/deployment), [OPcache](https://www.php.net/manual/en/opcache.configuration.php).

Điều kiện chốt nhỏ nhất: PHP chạy dưới đúng IIS identity kết nối DuckDB, đọc một SELECT Oracle và một SELECT PostgreSQL, JOIN đúng, trả trang 10 dòng với giá trị tiền/ngày giờ chính xác, chuyển trang cùng snapshot và xuất được kết quả. Kiểm tra reload code PHP sau deploy theo cấu hình cache thực tế. Nếu đường này đáp ứng nhu cầu, chưa có lý do kỹ thuật bắt buộc chuyển sang .NET.

Chỉ thêm tài liệu đánh giá và ghi chú kế hoạch; chưa sửa mã DAR hay xây SmartReport. Chưa chạy Oracle/PostgreSQL thật, DuckDB ODBC trên Windows/IIS, benchmark dữ liệu thực hoặc kiểm thử bảo mật toàn diện.
