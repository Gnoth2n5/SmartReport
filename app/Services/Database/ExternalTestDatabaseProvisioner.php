<?php

namespace App\Services\Database;

use RuntimeException;
use Symfony\Component\Process\Process;

class ExternalTestDatabaseProvisioner
{
    protected string $duckdbBinary;

    public function __construct(?string $duckdbBinary = null)
    {
        $this->duckdbBinary = $duckdbBinary ?? config('smartreport.duckdb_binary', 'duckdb');
    }

    /**
     * Provision schemas and seed realistic sample data across all 4 external databases.
     *
     * @return array<string, int>
     */
    public function provisionAll(): array
    {
        $northCount = $this->provisionPgNorth();
        $southCount = $this->provisionPgSouth();
        $mssqlCount = $this->provisionMssqlErp();
        $oracleCount = $this->provisionOracleHr();

        return [
            'pg_north' => $northCount,
            'pg_south' => $southCount,
            'mssql_erp' => $mssqlCount,
            'oracle_hr' => $oracleCount,
        ];
    }

    /**
     * Provision PostgreSQL North Plant (5432, source_db, etl_user / etl_password).
     *
     * Creates: public.v_epcontract (25 contracts, plant 'NORTH') & public.v_epcontract_detail.
     */
    public function provisionPgNorth(): int
    {
        $sql = <<<'SQL'
LOAD postgres;
ATTACH 'host=127.0.0.1 port=5432 user=etl_user password=etl_password dbname=source_db' AS pg_north (TYPE postgres);

DROP TABLE IF EXISTS pg_north.public.v_epcontract_detail;
DROP TABLE IF EXISTS pg_north.public.v_epcontract;

CREATE TABLE pg_north.public.v_epcontract (
    contract_no VARCHAR PRIMARY KEY,
    customer_name VARCHAR NOT NULL,
    plant VARCHAR NOT NULL,
    total_amount DECIMAL(18,2) NOT NULL,
    currency VARCHAR NOT NULL,
    status VARCHAR NOT NULL,
    sign_date DATE NOT NULL,
    delivery_date DATE,
    notes VARCHAR,
    created_at TIMESTAMP NOT NULL
);

CREATE TABLE pg_north.public.v_epcontract_detail (
    id BIGINT PRIMARY KEY,
    contract_no VARCHAR NOT NULL,
    item_code VARCHAR NOT NULL,
    item_name VARCHAR NOT NULL,
    quantity DECIMAL(18,2) NOT NULL,
    unit_price DECIMAL(18,2) NOT NULL,
    subtotal DECIMAL(18,2) NOT NULL,
    remarks VARCHAR
);

INSERT INTO pg_north.public.v_epcontract VALUES
('HD-N-001', 'Tổng Công Ty May Bắc Giang', 'NORTH', 185000.00, 'USD', 'COMPLETED', '2024-01-05', '2024-02-15', 'Giao hàng đợt 1 qua Cảng Hải Phòng', '2024-01-05 08:30:00'),
('HD-N-002', 'Công ty Dệt May Hà Nội - Hanosimex', 'NORTH', 240000.50, 'USD', 'COMPLETED', '2024-01-12', '2024-02-28', 'Hàng xuất khẩu thị trường Nhật Bản', '2024-01-12 09:15:00'),
('HD-N-003', 'Tập Đoàn May Sông Hồng - Nam Định', 'NORTH', 310500.00, 'USD', 'COMPLETED', '2024-01-20', '2024-03-10', 'Hợp đồng gia công FOB cao cấp', '2024-01-20 10:00:00'),
('HD-N-004', 'Công ty Cổ phần May Đáp Cầu', 'NORTH', 125000.00, 'USD', 'COMPLETED', '2024-02-02', '2024-03-25', NULL, '2024-02-02 11:20:00'),
('HD-N-005', 'Công ty TNHH May Nam Hà Nội', 'NORTH', 98000.00, 'USD', 'IN_PROGRESS', '2024-02-15', '2024-04-10', 'Đang tiến hành dệt nhuộm vải đợt 2', '2024-02-15 14:00:00'),
('HD-N-006', 'Tập đoàn Dệt may Việt Nam - Vinatex', 'NORTH', 450000.00, 'USD', 'COMPLETED', '2024-02-25', '2024-04-20', 'Hợp đồng khung chiến lược năm 2024', '2024-02-25 15:30:00'),
('HD-N-007', 'Công ty Cổ phần May Thăng Long', 'NORTH', 175000.00, 'USD', 'IN_PROGRESS', '2024-03-05', '2024-05-15', 'Kiểm tra chất lượng mẫu pre-production', '2024-03-05 08:45:00'),
('HD-N-008', 'Công ty TNHH May Tiên Sơn Thanh Hóa', 'NORTH', 215000.00, 'USD', 'COMPLETED', '2024-03-18', '2024-05-30', NULL, '2024-03-18 13:10:00'),
('HD-N-009', 'Công ty Cổ phần Dệt May Nam Định', 'NORTH', 160000.00, 'USD', 'IN_PROGRESS', '2024-04-02', '2024-06-15', 'Lô hàng mùa hè theo tiêu chuẩn OEKO-TEX', '2024-04-02 09:30:00'),
('HD-N-010', 'Công ty Cổ phần May Chiến Thắng', 'NORTH', 89000.00, 'USD', 'COMPLETED', '2024-04-15', '2024-06-25', 'Đã thanh lý hợp đồng và đối soát công nợ', '2024-04-15 16:00:00'),
('HD-N-011', 'Công ty Cổ phần May Hải Dương', 'NORTH', 135000.00, 'USD', 'IN_PROGRESS', '2024-05-08', '2024-07-10', 'Vải nhập khẩu từ Hàn Quốc đã về cảng', '2024-05-08 10:45:00'),
('HD-N-012', 'Công ty Cổ phần May Vĩnh Phú', 'NORTH', 110000.00, 'USD', 'COMPLETED', '2024-05-20', '2024-07-28', NULL, '2024-05-20 14:15:00'),
('HD-N-013', 'Công ty TNHH May Tinh Lợi Hải Dương', 'NORTH', 380000.00, 'USD', 'COMPLETED', '2024-06-01', '2024-08-15', 'Đơn hàng xuất khẩu áo jacket sang Mỹ', '2024-06-01 11:00:00'),
('HD-N-014', 'Công ty Cổ phần May Đức Giang', 'NORTH', 260000.00, 'USD', 'IN_PROGRESS', '2024-06-15', '2024-08-30', 'Đang may ráp chuyền xưởng số 2', '2024-06-15 08:30:00'),
('HD-N-015', 'Công ty Cổ phần May Hưng Yên', 'NORTH', 195000.00, 'USD', 'COMPLETED', '2024-07-01', '2024-09-10', 'Bàn giao đầy đủ CO, CQ cho khách hàng', '2024-07-01 15:00:00'),
('HD-N-016', 'Công ty Cổ phần Dệt May Huế - CN Hà Nội', 'NORTH', 145000.00, 'USD', 'IN_PROGRESS', '2024-07-18', '2024-09-25', 'Tiến độ hoàn thành 70%', '2024-07-18 10:20:00'),
('HD-N-017', 'Công ty Cổ phần May Phú Thịnh', 'NORTH', 92000.00, 'USD', 'PENDING', '2024-08-05', NULL, 'Chờ đối tác xác nhận bảng màu phụ liệu', '2024-08-05 13:40:00'),
('HD-N-018', 'Công ty Cổ phần Thời Trang Kowil', 'NORTH', 225000.00, 'USD', 'IN_PROGRESS', '2024-08-20', '2024-10-30', 'Áo sơ mi công sở nam cao cấp Owen', '2024-08-20 09:00:00'),
('HD-N-019', 'Công ty TNHH May Đại Tân Thái Bình', 'NORTH', 170000.00, 'USD', 'COMPLETED', '2024-09-02', '2024-11-10', NULL, '2024-09-02 16:30:00'),
('HD-N-020', 'Công ty Cổ phần May Bắc Ninh', 'NORTH', 155000.00, 'USD', 'IN_PROGRESS', '2024-09-15', '2024-11-25', 'Chạy thử nghiệm dây chuyền tự động hóa', '2024-09-15 11:15:00'),
('HD-N-021', 'Công ty TNHH Quốc Tế Smart Shirts', 'NORTH', 420000.00, 'USD', 'COMPLETED', '2024-10-01', '2024-12-05', 'Đạt chuẩn kiểm định chất lượng khắt khe', '2024-10-01 08:15:00'),
('HD-N-022', 'Công ty Cổ phần May Đông Đô', 'NORTH', 85000.00, 'USD', 'PENDING', '2024-10-15', NULL, 'Chờ ngân hàng phát hành thư tín dụng L/C', '2024-10-15 14:40:00'),
('HD-N-023', 'Công ty TNHH Crystal Martin Việt Nam', 'NORTH', 360000.00, 'USD', 'IN_PROGRESS', '2024-10-25', '2024-12-30', 'Đơn hàng đồ lót phụ nữ xuất khẩu EU', '2024-10-25 10:00:00'),
('HD-N-024', 'Công ty Cổ phần May Hà Phong Bắc Giang', 'NORTH', 290000.00, 'USD', 'CANCELLED', '2024-11-01', NULL, 'Đối tác hủy do thay đổi mẫu mã thiết kế', '2024-11-01 09:30:00'),
('HD-N-025', 'Công ty Cổ phần Dệt May Đông Nam', 'NORTH', 130000.00, 'USD', 'PENDING', '2024-11-10', NULL, 'Ký kết biên bản ghi nhớ chuẩn bị sản xuất', '2024-11-10 15:50:00');

INSERT INTO pg_north.public.v_epcontract_detail VALUES
(1, 'HD-N-001', 'AO-POLO-01', 'Áo Polo Đồng Phục Nam Xuất Khẩu', 5000.00, 20.00, 100000.00, 'Vải CVC 65/35 co giãn 4 chiều'),
(2, 'HD-N-001', 'QUAN-JEAN-01', 'Quần Jean Nam Ống Đứng Tiêu Chuẩn', 4250.00, 20.00, 85000.00, NULL),
(3, 'HD-N-002', 'AO-SOM-01', 'Áo Sơ Mi Nam Công Sở Dài Tay', 8000.00, 18.00, 144000.00, 'Chống nhăn cao cấp'),
(4, 'HD-N-002', 'QUAN-TAY-01', 'Quần Tây Nam Hàn Quốc', 4800.00, 20.00, 96000.50, NULL),
(5, 'HD-N-003', 'AO-KHOAC-01', 'Áo Khoác Gió 3 Lớp Chống Nước', 6210.00, 50.00, 310500.00, 'Khóa kéo YKK chính hãng'),
(6, 'HD-N-004', 'VAI-COT-01', 'Vải Cuộn Cotton 100% Chải Kỹ', 2500.00, 50.00, 125000.00, 'Khổ 1.6m định lượng 220gsm'),
(7, 'HD-N-005', 'AO-THUN-01', 'Áo Thun Cổ Tròn Unisex Cotton', 9800.00, 10.00, 98000.00, NULL),
(8, 'HD-N-006', 'AO-LEN-01', 'Áo Len Dệt Kim Mùa Đông Xuất EU', 15000.00, 30.00, 450000.00, 'Sợi len lông cừu tự nhiên'),
(9, 'HD-N-007', 'QUAN-KHO-01', 'Quần Kaki Túi Hộp Co Giãn', 7000.00, 25.00, 175000.00, NULL),
(10, 'HD-N-008', 'AO-VEST-01', 'Áo Vest Nam Doanh Nhân', 4300.00, 50.00, 215000.00, 'Lót lụa cao cấp chống tĩnh điện'),
(11, 'HD-N-009', 'AO-POLO-02', 'Áo Polo Nữ Thể Thao Quick-Dry', 8000.00, 20.00, 160000.00, NULL),
(12, 'HD-N-010', 'QUAN-SH-01', 'Quần Short Kaki Nam Đi Biển', 8900.00, 10.00, 89000.00, 'In chuyển nhiệt hoa văn'),
(13, 'HD-N-011', 'VAI-DEN-01', 'Vải Denim Co Giãn Dệt Thoi', 4500.00, 30.00, 135000.00, NULL),
(14, 'HD-N-012', 'AO-THUN-02', 'Áo Thun Polo Doanh Nghiệp', 5500.00, 20.00, 110000.00, 'Thêu logo ngực trái'),
(15, 'HD-N-013', 'AO-PARK-01', 'Áo Parka Lông Vũ Xuất Mỹ', 7600.00, 50.00, 380000.00, 'Lông vũ 90/10 chống lạnh cực tốt'),
(16, 'HD-N-014', 'QUAN-BO-01', 'Quần Bò Nam Rách Gối Cá Tính', 10400.00, 25.00, 260000.00, NULL),
(17, 'HD-N-015', 'AO-SO-02', 'Áo Sơ Mi Nam Cổ Tàu Vải Đũi', 6500.00, 30.00, 195000.00, 'Vải đũi tự nhiên thoáng mát'),
(18, 'HD-N-016', 'QUAN-TAY-02', 'Quần Tây Nữ Dáng Suông Công Sở', 5800.00, 25.00, 145000.00, NULL),
(19, 'HD-N-017', 'VAI-KHO-02', 'Vải Khăn Tắm Khách Sạn 5 Sao', 4600.00, 20.00, 92000.00, 'Sợi tre kháng khuẩn tự nhiên'),
(20, 'HD-N-018', 'AO-SOM-03', 'Áo Sơ Mi Kháng Khuẩn Không Cần Ủi', 7500.00, 30.00, 225000.00, 'Công nghệ Easy Care'),
(21, 'HD-N-019', 'AO-THUN-03', 'Áo Thun Tập Gym Thấm Hút Mồ Hôi', 8500.00, 20.00, 170000.00, NULL),
(22, 'HD-N-020', 'QUAN-JEAN-02', 'Quần Jean Nữ Lưng Cao Co Giãn', 6200.00, 25.00, 155000.00, 'Màu xanh vintage wash nhẹ'),
(23, 'HD-N-021', 'AO-SOM-04', 'Áo Sơ Mi Cao Cấp Bông Pima', 10500.00, 40.00, 420000.00, 'Sợi bông Pima thượng hạng'),
(24, 'HD-N-022', 'VAI-COT-02', 'Vải May Đồ Trẻ Em Đạt Tiêu Chuẩn', 4250.00, 20.00, 85000.00, NULL),
(25, 'HD-N-023', 'DO-LOT-01', 'Bộ Nội Y Nữ Không Đường May', 18000.00, 20.00, 360000.00, 'Công nghệ dán nhiệt Seamless'),
(26, 'HD-N-024', 'AO-KHOAC-02', 'Áo Khoác Phao Nam Siêu Nhẹ', 5800.00, 50.00, 290000.00, 'Đơn hàng tạm hoãn do đối tác'),
(27, 'HD-N-025', 'VAI-DET-01', 'Vải Dệt Kim Jacquard Hoa Văn Nổi', 5200.00, 25.00, 130000.00, NULL);
SQL;

        $this->executeDuckDbSql($sql);

        return 25;
    }

    /**
     * Provision PostgreSQL South Plant (5433, app, app / app).
     *
     * Creates: public.v_epcontract (20 contracts, plant 'SOUTH') & public.v_epcontract_detail.
     */
    public function provisionPgSouth(): int
    {
        $sql = <<<'SQL'
LOAD postgres;
ATTACH 'host=127.0.0.1 port=5433 user=app password=app dbname=app' AS pg_south (TYPE postgres);

DROP TABLE IF EXISTS pg_south.public.v_epcontract_detail;
DROP TABLE IF EXISTS pg_south.public.v_epcontract;

CREATE TABLE pg_south.public.v_epcontract (
    contract_no VARCHAR PRIMARY KEY,
    customer_name VARCHAR NOT NULL,
    plant VARCHAR NOT NULL,
    total_amount DECIMAL(18,2) NOT NULL,
    currency VARCHAR NOT NULL,
    status VARCHAR NOT NULL,
    sign_date DATE NOT NULL,
    delivery_date DATE,
    notes VARCHAR,
    created_at TIMESTAMP NOT NULL
);

CREATE TABLE pg_south.public.v_epcontract_detail (
    id BIGINT PRIMARY KEY,
    contract_no VARCHAR NOT NULL,
    item_code VARCHAR NOT NULL,
    item_name VARCHAR NOT NULL,
    quantity DECIMAL(18,2) NOT NULL,
    unit_price DECIMAL(18,2) NOT NULL,
    subtotal DECIMAL(18,2) NOT NULL,
    remarks VARCHAR
);

INSERT INTO pg_south.public.v_epcontract VALUES
('HD-S-001', 'Tổng Công Ty Cổ Phần May Việt Tiến', 'SOUTH', 320000.00, 'USD', 'COMPLETED', '2024-01-10', '2024-02-25', 'Xuất khẩu qua Cảng Cát Lái TP.HCM', '2024-01-10 09:00:00'),
('HD-S-002', 'Công ty Cổ Phần May Nhà Bè - NBC', 'SOUTH', 275000.50, 'USD', 'COMPLETED', '2024-01-18', '2024-03-05', 'Hợp đồng đồng phục doanh nghiệp lớn', '2024-01-18 10:30:00'),
('HD-S-003', 'Tổng Công Ty Cổ Phần Phong Phú', 'SOUTH', 410000.00, 'USD', 'COMPLETED', '2024-02-01', '2024-03-20', 'Chỉ may và vải Jean chất lượng cao', '2024-02-01 11:15:00'),
('HD-S-004', 'Công ty Cổ Phần Dệt May Gia Định', 'SOUTH', 185000.00, 'USD', 'COMPLETED', '2024-02-14', '2024-04-01', NULL, '2024-02-14 14:00:00'),
('HD-S-005', 'Công ty TNHH May Xuất Khẩu Bình Dương', 'SOUTH', 145000.00, 'USD', 'IN_PROGRESS', '2024-03-01', '2024-04-28', 'Dây chuyền cắt tự động đang hoạt động', '2024-03-01 08:30:00'),
('HD-S-006', 'Công ty May Đồng Nai - Donagamex', 'SOUTH', 220000.00, 'USD', 'COMPLETED', '2024-03-15', '2024-05-10', 'Hoàn tất nghiệm thu lô hàng xuất Nhật', '2024-03-15 15:45:00'),
('HD-S-007', 'Công ty Cổ Phần May Phương Đông', 'SOUTH', 160000.00, 'USD', 'IN_PROGRESS', '2024-04-05', '2024-05-25', 'Kiểm tra tỷ lệ co rút sau giặt wash', '2024-04-05 09:40:00'),
('HD-S-008', 'Công ty TNHH Esquel Garment Bình Dương', 'SOUTH', 510000.00, 'USD', 'COMPLETED', '2024-04-20', '2024-06-15', 'Đơn hàng cao cấp nhãn hiệu Banana Republic', '2024-04-20 13:20:00'),
('HD-S-009', 'Công ty TNHH May Thêu Thuận Phương', 'SOUTH', 195000.00, 'USD', 'IN_PROGRESS', '2024-05-02', '2024-06-30', NULL, '2024-05-02 10:15:00'),
('HD-S-010', 'Công ty TNHH Hansoll Vina - Sóng Thần', 'SOUTH', 380000.00, 'USD', 'COMPLETED', '2024-05-18', '2024-07-20', 'Xuất khẩu áo len dệt kim sang Châu Âu', '2024-05-18 16:30:00'),
('HD-S-011', 'Công ty Cổ Phần May Sài Gòn 3', 'SOUTH', 290000.00, 'USD', 'IN_PROGRESS', '2024-06-05', '2024-08-10', 'Dòng sản phẩm quần Jeans xuất khẩu Uniqlo', '2024-06-05 08:15:00'),
('HD-S-012', 'Công ty Cổ Phần Dệt May Thành Công - TCM', 'SOUTH', 460000.00, 'USD', 'COMPLETED', '2024-06-20', '2024-08-25', 'Chuỗi cung ứng khép kín Sợi - Dệt - May', '2024-06-20 11:30:00'),
('HD-S-013', 'Công ty TNHH Gain Lucky Việt Nam Tây Ninh', 'SOUTH', 580000.00, 'USD', 'COMPLETED', '2024-07-08', '2024-09-15', 'Quy mô sản xuất lớn đáp ứng đơn hàng gấp', '2024-07-08 14:10:00'),
('HD-S-014', 'Công ty Cổ Phần May Bình Minh', 'SOUTH', 120000.00, 'USD', 'IN_PROGRESS', '2024-07-25', '2024-09-30', NULL, '2024-07-25 09:00:00'),
('HD-S-015', 'Công ty TNHH Nobland Vietnam TP.HCM', 'SOUTH', 340000.00, 'USD', 'COMPLETED', '2024-08-10', '2024-10-15', 'Giao hàng đúng hẹn tại Tân Cảng Cát Lái', '2024-08-10 15:20:00'),
('HD-S-016', 'Công ty Cổ Phần May Hữu Nghị', 'SOUTH', 175000.00, 'USD', 'PENDING', '2024-08-28', NULL, 'Chờ phê duyệt mẫu vải thử nghiệm', '2024-08-28 10:45:00'),
('HD-S-017', 'Công ty TNHH Pungkook Saigon Two', 'SOUTH', 390000.00, 'USD', 'IN_PROGRESS', '2024-09-12', '2024-11-20', 'Gia công túi xách và balo du lịch xuất Mỹ', '2024-09-12 13:50:00'),
('HD-S-018', 'Công ty Cổ Phần May Vũng Tàu', 'SOUTH', 115000.00, 'USD', 'PENDING', '2024-10-05', NULL, 'Chờ xác nhận định mức phụ liệu từ khách', '2024-10-05 08:30:00'),
('HD-S-019', 'Công ty TNHH Dệt Nhuộm Nam Phương', 'SOUTH', 230000.00, 'USD', 'CANCELLED', '2024-10-20', NULL, 'Khách hàng dời kế hoạch sang quý 1 năm sau', '2024-10-20 16:00:00'),
('HD-S-020', 'Công ty Cổ Phần May Tiền Giang', 'SOUTH', 140000.00, 'USD', 'PENDING', '2024-11-05', NULL, 'Đang thẩm định hợp đồng ngoại thương', '2024-11-05 11:20:00');

INSERT INTO pg_south.public.v_epcontract_detail VALUES
(101, 'HD-S-001', 'AO-SO-S01', 'Áo Sơ Mi Nam Tay Ngắn Thoáng Khí', 16000.00, 20.00, 320000.00, 'Vải sợi tre Bamboo cao cấp'),
(102, 'HD-S-002', 'AO-VEST-S01', 'Bộ Comple Nam Công Sở Việt Nam', 5500.00, 50.00, 275000.50, NULL),
(103, 'HD-S-003', 'VAI-DEN-S01', 'Vải Denim Co Giãn Xuất Khẩu Mỹ', 8200.00, 50.00, 410000.00, 'Nhuộm chàm tự nhiên'),
(104, 'HD-S-004', 'QUAN-KHO-S01', 'Quần Kaki Nam Dáng Slimfit', 7400.00, 25.00, 185000.00, NULL),
(105, 'HD-S-005', 'AO-THUN-S01', 'Áo Thun Polo Cổ Bẻ Cao Cấp', 7250.00, 20.00, 145000.00, 'Bo cổ dệt sọc thể thao'),
(106, 'HD-S-006', 'AO-LEN-S01', 'Áo Dệt Kim Mùa Thu Xuất Khẩu', 11000.00, 20.00, 220000.00, NULL),
(107, 'HD-S-007', 'QUAN-JEAN-S01', 'Quần Jean Nữ Ống Loe Retro', 6400.00, 25.00, 160000.00, 'Wash mềm chống co'),
(108, 'HD-S-008', 'AO-SOM-S02', 'Áo Sơ Mi Nữ Lụa Satin Sang Trọng', 10200.00, 50.00, 510000.00, 'Chuẩn chất lượng xuất khẩu Banana Republic'),
(109, 'HD-S-009', 'AO-THUN-S02', 'Áo Thun Trẻ Em In Hình Thể Thao', 19500.00, 10.00, 195000.00, NULL),
(110, 'HD-S-010', 'AO-KHOAC-S01', 'Áo Gió Thể Thao Chống Tia UV', 19000.00, 20.00, 380000.00, 'Chỉ số UPF 50+ bảo vệ da'),
(111, 'HD-S-011', 'QUAN-BO-S01', 'Quần Bò Nam Phong Cách Vintage', 11600.00, 25.00, 290000.00, NULL),
(112, 'HD-S-012', 'VAI-DET-S01', 'Vải Dệt Kim Thun Spandex Đa Chiều', 9200.00, 50.00, 460000.00, 'Độ đàn hồi vượt trội'),
(113, 'HD-S-013', 'AO-POLO-S01', 'Áo Thun Thể Thao Nam Chạy Bộ', 29000.00, 20.00, 580000.00, 'Công nghệ thấm hút Dri-Fit'),
(114, 'HD-S-014', 'QUAN-TAY-S01', 'Quần Tây Nữ Dáng Baggy Lưng Cao', 4800.00, 25.00, 120000.00, NULL),
(115, 'HD-S-015', 'AO-HOOD-S01', 'Áo Nỉ Hoodie Mùa Đông Nữ Có Nón', 11333.00, 30.00, 340000.00, 'Nỉ bông dày dặn ấm áp'),
(116, 'HD-S-016', 'VAI-COT-S01', 'Vải Cotton Kháng Khuẩn Y Tế', 8750.00, 20.00, 175000.00, NULL),
(117, 'HD-S-017', 'BALO-DU-S01', 'Balo Du Lịch Đa Năng Chống Nước', 13000.00, 30.00, 390000.00, 'Chất liệu vải Cordura siêu bền'),
(118, 'HD-S-018', 'QUAN-SHORT-S01', 'Quần Đùi Nữ Tập Yoga & Gym', 5750.00, 20.00, 115000.00, NULL),
(119, 'HD-S-019', 'VAI-NHUOM-S01', 'Vải Nhuộm Hoạt Tính Màu Pastel', 4600.00, 50.00, 230000.00, 'Hợp đồng dời sang năm sau'),
(120, 'HD-S-020', 'AO-SO-S03', 'Áo Sơ Mi Nam Cổ Trụ Phối Màu', 7000.00, 20.00, 140000.00, NULL);
SQL;

        $this->executeDuckDbSql($sql);

        return 20;
    }

    /**
     * Provision SQL Server ERP (1433, erp_production, sa / DevPassword!123).
     *
     * Creates: dbo.production_orders (35 rows) & dbo.machine_logs.
     */
    public function provisionMssqlErp(): int
    {
        // 1. Ensure database exists via master
        $ensureDbSql = <<<'SQL'
LOAD mssql;
ATTACH 'Server=127.0.0.1,1433;Database=master;User Id=sa;Password=DevPassword!123;TrustServerCertificate=yes' AS mssql_master (TYPE mssql);
SELECT * FROM mssql_scan('mssql_master', 'IF NOT EXISTS (SELECT name FROM sys.databases WHERE name = ''erp_production'') CREATE DATABASE [erp_production]; SELECT 1 AS done;');
SQL;
        $this->executeDuckDbSql($ensureDbSql);

        // 2. Create tables using native T-SQL via mssql_scan to ensure proper SQL Server column data types and indexes
        $createTablesSql = <<<'SQL'
LOAD mssql;
ATTACH 'Server=127.0.0.1,1433;Database=erp_production;User Id=sa;Password=DevPassword!123;TrustServerCertificate=yes' AS mssql_erp (TYPE mssql);

SELECT * FROM mssql_scan('mssql_erp', '
IF OBJECT_ID(''dbo.machine_logs'', ''U'') IS NOT NULL DROP TABLE dbo.machine_logs;
IF OBJECT_ID(''dbo.production_orders'', ''U'') IS NOT NULL DROP TABLE dbo.production_orders;

CREATE TABLE dbo.production_orders (
    order_no NVARCHAR(50) PRIMARY KEY,
    contract_no NVARCHAR(50) NOT NULL,
    plant NVARCHAR(20) NOT NULL,
    emp_no NVARCHAR(20) NOT NULL,
    machine_code NVARCHAR(20) NOT NULL,
    output_qty DECIMAL(18,2) NOT NULL,
    defect_qty DECIMAL(18,2) NOT NULL,
    unit_cost DECIMAL(18,2) NOT NULL,
    defect_reason NVARCHAR(255) NULL,
    shift_date DATE NOT NULL,
    created_at DATETIME2 NOT NULL
);

CREATE TABLE dbo.machine_logs (
    log_id INT PRIMARY KEY,
    machine_code NVARCHAR(20) NOT NULL,
    temperature DECIMAL(8,2) NOT NULL,
    vibration DECIMAL(8,2) NOT NULL,
    operator_notes NVARCHAR(255) NULL,
    logged_at DATETIME2 NOT NULL
);

SELECT 1 AS done;
');
SQL;
        $this->executeDuckDbSql($createTablesSql);

        // 3. Insert realistic production orders (35 rows) & machine logs
        $insertSql = <<<'SQL'
LOAD mssql;
ATTACH 'Server=127.0.0.1,1433;Database=erp_production;User Id=sa;Password=DevPassword!123;TrustServerCertificate=yes' AS mssql_erp (TYPE mssql);

INSERT INTO mssql_erp.dbo.production_orders VALUES
('PO-2024-001', 'HD-N-001', 'NORTH', 'EMP001', 'MC-N01', 2500.00, 15.00, 45000.00, 'Lỗi sợi chỉ may viền cổ', '2024-01-15', '2024-01-15 08:30:00'),
('PO-2024-002', 'HD-N-001', 'NORTH', 'EMP002', 'MC-N02', 2500.00, 0.00, 44500.00, NULL, '2024-01-16', '2024-01-16 08:30:00'),
('PO-2024-003', 'HD-N-002', 'NORTH', 'EMP003', 'MC-N01', 4000.00, 22.00, 52000.00, 'Lỗi đường may túi áo', '2024-01-22', '2024-01-22 09:00:00'),
('PO-2024-004', 'HD-N-002', 'NORTH', 'EMP004', 'MC-N03', 4000.00, 8.00, 51500.00, 'Xơ sợi đầu cuộn vải', '2024-01-23', '2024-01-23 09:15:00'),
('PO-2024-005', 'HD-N-003', 'NORTH', 'EMP005', 'MC-N02', 3105.00, 12.00, 85000.00, 'Khóa kéo bị lệch khớp', '2024-02-05', '2024-02-05 10:00:00'),
('PO-2024-006', 'HD-N-003', 'NORTH', 'EMP001', 'MC-N01', 3105.00, 0.00, 84000.00, NULL, '2024-02-06', '2024-02-06 10:15:00'),
('PO-2024-007', 'HD-N-004', 'NORTH', 'EMP002', 'MC-N03', 1250.00, 5.00, 32000.00, 'Vết dầu bôi trơn máy may', '2024-02-18', '2024-02-18 11:00:00'),
('PO-2024-008', 'HD-N-005', 'NORTH', 'EMP003', 'MC-N01', 4900.00, 30.00, 28000.00, 'Sai kích thước bo tay', '2024-02-28', '2024-02-28 13:45:00'),
('PO-2024-009', 'HD-N-006', 'NORTH', 'EMP004', 'MC-N02', 7500.00, 0.00, 68000.00, NULL, '2024-03-08', '2024-03-08 08:00:00'),
('PO-2024-010', 'HD-N-006', 'NORTH', 'EMP005', 'MC-N03', 7500.00, 18.00, 67500.00, 'Xước mặt vải dệt kim', '2024-03-09', '2024-03-09 08:30:00'),
('PO-2024-011', 'HD-N-007', 'NORTH', 'EMP001', 'MC-N01', 3500.00, 0.00, 48000.00, NULL, '2024-03-20', '2024-03-20 09:30:00'),
('PO-2024-012', 'HD-N-008', 'NORTH', 'EMP002', 'MC-N02', 4300.00, 10.00, 92000.00, 'Mũi may không đều tại ve áo', '2024-04-02', '2024-04-02 10:00:00'),
('PO-2024-013', 'HD-N-009', 'NORTH', 'EMP003', 'MC-N03', 4000.00, 0.00, 41000.00, NULL, '2024-04-18', '2024-04-18 14:00:00'),
('PO-2024-014', 'HD-N-010', 'NORTH', 'EMP004', 'MC-N01', 4450.00, 14.00, 22000.00, 'Đứt chỉ móc dưới máy vắt sổ', '2024-05-02', '2024-05-02 15:30:00'),
('PO-2024-015', 'HD-N-011', 'NORTH', 'EMP005', 'MC-N02', 2250.00, 0.00, 60000.00, NULL, '2024-05-19', '2024-05-19 08:45:00'),
('PO-2024-016', 'HD-N-012', 'NORTH', 'EMP001', 'MC-N03', 2750.00, 6.00, 39000.00, 'Lỗi căn chỉnh cúc áo', '2024-06-05', '2024-06-05 11:20:00'),
('PO-2024-017', 'HD-N-013', 'NORTH', 'EMP002', 'MC-N01', 3800.00, 0.00, 95000.00, NULL, '2024-06-22', '2024-06-22 13:10:00'),
('PO-2024-018', 'HD-N-014', 'NORTH', 'EMP003', 'MC-N02', 5200.00, 25.00, 53000.00, 'Đinh tán túi quần bò bị lệch', '2024-07-08', '2024-07-08 09:00:00'),
('PO-2024-019', 'HD-N-015', 'NORTH', 'EMP004', 'MC-N03', 3250.00, 0.00, 58000.00, NULL, '2024-07-25', '2024-07-25 10:30:00'),
('PO-2024-020', 'HD-N-018', 'NORTH', 'EMP005', 'MC-N01', 3750.00, 8.00, 62000.00, 'Măng sét tay áo bị nhăn', '2024-09-01', '2024-09-01 14:00:00'),
('PO-2024-021', 'HD-S-001', 'SOUTH', 'EMP016', 'MC-S01', 8000.00, 20.00, 42000.00, 'Đường may sườn áo bị rút sợi', '2024-01-25', '2024-01-25 08:30:00'),
('PO-2024-022', 'HD-S-001', 'SOUTH', 'EMP017', 'MC-S02', 8000.00, 0.00, 41800.00, NULL, '2024-01-26', '2024-01-26 08:30:00'),
('PO-2024-023', 'HD-S-002', 'SOUTH', 'EMP018', 'MC-S01', 2750.00, 15.00, 89000.00, 'Lỗi ép mex cổ áo vest', '2024-02-10', '2024-02-10 09:15:00'),
('PO-2024-024', 'HD-S-003', 'SOUTH', 'EMP019', 'MC-S03', 4100.00, 0.00, 75000.00, NULL, '2024-02-25', '2024-02-25 10:45:00'),
('PO-2024-025', 'HD-S-004', 'SOUTH', 'EMP020', 'MC-S02', 3700.00, 12.00, 48000.00, 'Độ co giãn vải sau may chưa đạt', '2024-03-12', '2024-03-12 11:30:00'),
('PO-2024-026', 'HD-S-005', 'SOUTH', 'EMP016', 'MC-S01', 3625.00, 0.00, 39500.00, NULL, '2024-03-28', '2024-03-28 13:00:00'),
('PO-2024-027', 'HD-S-006', 'SOUTH', 'EMP017', 'MC-S03', 5500.00, 18.00, 52000.00, 'Lỗi dệt kim tại vai áo', '2024-04-10', '2024-04-10 14:15:00'),
('PO-2024-028', 'HD-S-007', 'SOUTH', 'EMP018', 'MC-S02', 3200.00, 0.00, 49000.00, NULL, '2024-04-26', '2024-04-26 15:30:00'),
('PO-2024-029', 'HD-S-008', 'SOUTH', 'EMP019', 'MC-S01', 5100.00, 10.00, 98000.00, 'Lỗi chỉ thêu nhãn hiệu cổ áo', '2024-05-12', '2024-05-12 08:30:00'),
('PO-2024-030', 'HD-S-009', 'SOUTH', 'EMP020', 'MC-S03', 9750.00, 0.00, 21000.00, NULL, '2024-05-28', '2024-05-28 10:00:00'),
('PO-2024-031', 'HD-S-010', 'SOUTH', 'EMP016', 'MC-S02', 9500.00, 22.00, 46000.00, 'Khóa kéo áo gió bị sít', '2024-06-14', '2024-06-14 11:20:00'),
('PO-2024-032', 'HD-S-011', 'SOUTH', 'EMP017', 'MC-S01', 5800.00, 0.00, 56000.00, NULL, '2024-06-30', '2024-06-30 13:40:00'),
('PO-2024-033', 'HD-S-012', 'SOUTH', 'EMP018', 'MC-S03', 4600.00, 14.00, 82000.00, 'Màu nhuộm bị lệch 0.5 tone', '2024-07-15', '2024-07-15 15:00:00'),
('PO-2024-034', 'HD-S-013', 'SOUTH', 'EMP019', 'MC-S02', 14500.00, 0.00, 41000.00, NULL, '2024-08-02', '2024-08-02 08:45:00'),
('PO-2024-035', 'HD-S-015', 'SOUTH', 'EMP020', 'MC-S01', 5666.00, 16.00, 65000.00, 'Dây rút nón áo hoodie bị sờn', '2024-08-25', '2024-08-25 10:15:00');

INSERT INTO mssql_erp.dbo.machine_logs VALUES
(1, 'MC-N01', 52.50, 1.80, 'Máy may công nghiệp Juki hoạt động êm ái', '2024-01-15 08:00:00'),
(2, 'MC-N01', 68.20, 2.40, 'Nhiệt độ động cơ tăng nhẹ ca chiều', '2024-01-15 16:00:00'),
(3, 'MC-N02', 48.00, 1.50, NULL, '2024-01-16 08:00:00'),
(4, 'MC-N03', 61.50, 2.90, 'Cần kiểm tra dây curoa truyền động', '2024-01-23 09:00:00'),
(5, 'MC-S01', 55.00, 2.10, 'Đã bảo dưỡng định kỳ 200 giờ may', '2024-01-25 08:00:00'),
(6, 'MC-S02', 50.20, 1.60, NULL, '2024-01-26 08:00:00'),
(7, 'MC-S03', 72.80, 3.40, 'Cảnh báo nhiệt độ đầu kim máy dệt', '2024-02-25 10:00:00'),
(8, 'MC-N01', 53.10, 1.90, 'Vận hành ổn định ca sáng', '2024-03-08 08:00:00');
SQL;

        $this->executeDuckDbSql($insertSql);

        return 35;
    }

    /**
     * Provision Oracle HR (1521, FREEPDB1, APP, app / app).
     *
     * Creates: APP.DEPARTMENTS & APP.EMPLOYEES (30 employees).
     */
    public function provisionOracleHr(): int
    {
        $sql = <<<'SQL'
LOAD oracle_scanner;
CREATE SECRET IF NOT EXISTS __sec_oracle_hr_prov (TYPE oracle, DSN '127.0.0.1:1521/FREEPDB1', USER 'app', PASSWORD 'app');
ATTACH '__sec_oracle_hr_prov' AS oracle_hr (TYPE oracle_scanner);

DROP TABLE IF EXISTS oracle_hr.APP.EMPLOYEES;
DROP TABLE IF EXISTS oracle_hr.APP.DEPARTMENTS;

CREATE TABLE oracle_hr.APP.DEPARTMENTS (
    DEPT_CODE VARCHAR PRIMARY KEY,
    DEPT_NAME VARCHAR NOT NULL,
    COST_CENTER VARCHAR NOT NULL
);

CREATE TABLE oracle_hr.APP.EMPLOYEES (
    EMP_NO VARCHAR PRIMARY KEY,
    FULL_NAME VARCHAR NOT NULL,
    DEPT_CODE VARCHAR NOT NULL,
    PLANT VARCHAR NOT NULL,
    BASE_SALARY DECIMAL(18,2) NOT NULL,
    HIRE_DATE DATE NOT NULL,
    STATUS VARCHAR NOT NULL,
    NOTES VARCHAR
);

INSERT INTO oracle_hr.APP.DEPARTMENTS VALUES
('PRD', 'Phòng Sản Xuất & May Mặc', 'CC-PRD-01'),
('QA', 'Phòng Quản Lý Chất Lượng (QC/QA)', 'CC-QA-02'),
('TECH', 'Phòng Kỹ Thuật Cơ Điện & Bảo Trì', 'CC-TECH-03'),
('LOG', 'Phòng Xuất Nhập Khẩu & Kho Vận', 'CC-LOG-04'),
('EXEC', 'Ban Giám Đốc & Khối Điều Hành', 'CC-EXEC-05');

INSERT INTO oracle_hr.APP.EMPLOYEES VALUES
('EMP001', 'Nguyễn Văn An', 'PRD', 'NORTH', 18500000.00, '2020-03-15', 'ACTIVE', 'Trưởng ca may chuyền 1 nhà máy phía Bắc'),
('EMP002', 'Trần Thị Mai', 'PRD', 'NORTH', 16200000.00, '2021-05-10', 'ACTIVE', 'Kỹ thuật viên may mẫu chuyền vest'),
('EMP003', 'Lê Hoàng Long', 'PRD', 'NORTH', 15800000.00, '2021-08-20', 'ACTIVE', 'Tổ phó tổ cắt tự động vải dệt kim'),
('EMP004', 'Phạm Minh Đức', 'PRD', 'NORTH', 17000000.00, '2020-11-05', 'ACTIVE', 'Chuyên viên định mức may công nghiệp'),
('EMP005', 'Hoàng Kim Oanh', 'PRD', 'NORTH', 16500000.00, '2022-01-12', 'ACTIVE', NULL),
('EMP006', 'Vũ Đức Thắng', 'QA', 'NORTH', 19000000.00, '2020-06-01', 'ACTIVE', 'Trưởng phòng quản lý chất lượng QA Bắc'),
('EMP007', 'Đặng Thu Trang', 'QA', 'NORTH', 14500000.00, '2022-04-15', 'ACTIVE', 'Chuyên viên kiểm định nguyên phụ liệu may'),
('EMP008', 'Bùi Anh Tuấn', 'TECH', 'NORTH', 21000000.00, '2019-09-01', 'ACTIVE', 'Kỹ sư trưởng bảo trì máy may Juki & Brother'),
('EMP009', 'Ngô Thị Bích', 'TECH', 'NORTH', 13800000.00, '2023-02-20', 'ACTIVE', NULL),
('EMP010', 'Dương Quốc Cường', 'LOG', 'NORTH', 17500000.00, '2021-03-10', 'ACTIVE', 'Điều phối viên giao nhận Cảng Hải Phòng'),
('EMP011', 'Đỗ Hồng Nhung', 'LOG', 'NORTH', 15200000.00, '2022-07-01', 'ACTIVE', 'Chuyên viên thủ tục hải quan xuất khẩu'),
('EMP012', 'Hồ Trọng Nghĩa', 'EXEC', 'NORTH', 42000000.00, '2018-01-15', 'ACTIVE', 'Giám đốc Điều hành Nhà máy Phía Bắc'),
('EMP013', 'Lý Lan Anh', 'PRD', 'NORTH', 14200000.00, '2023-05-10', 'ON_LEAVE', 'Nghỉ thai sản theo chế độ'),
('EMP014', 'Trịnh Văn Hải', 'TECH', 'NORTH', 16800000.00, '2021-10-18', 'ACTIVE', 'Kỹ thuật viên điện lạnh công nghiệp'),
('EMP015', 'Mai Thanh Tùng', 'QA', 'NORTH', 13500000.00, '2023-08-01', 'RESIGNED', 'Đã nghỉ việc chuyển công tác khác'),
('EMP016', 'Phan Gia Bảo', 'PRD', 'SOUTH', 18800000.00, '2020-04-01', 'ACTIVE', 'Quản đốc xưởng may 1 nhà máy phía Nam'),
('EMP017', 'Võ Thị Lệ', 'PRD', 'SOUTH', 16400000.00, '2021-06-15', 'ACTIVE', 'Trưởng chuyền may sơ mi xuất khẩu'),
('EMP018', 'Đoàn Ngọc Sơn', 'PRD', 'SOUTH', 16000000.00, '2021-09-05', 'ACTIVE', 'Chuyền trưởng chuyền đồ lót seamless'),
('EMP019', 'Lương Hữu Phước', 'PRD', 'SOUTH', 17200000.00, '2020-12-10', 'ACTIVE', 'Kỹ thuật viên vận hành máy dệt tròn'),
('EMP020', 'Cao Thùy Linh', 'PRD', 'SOUTH', 15500000.00, '2022-02-28', 'ACTIVE', NULL),
('EMP021', 'Tạ Minh Khang', 'QA', 'SOUTH', 19500000.00, '2019-11-15', 'ACTIVE', 'Trưởng bộ phận QC Cảng Cát Lái'),
('EMP022', 'Đinh Bảo Ngọc', 'QA', 'SOUTH', 14800000.00, '2022-05-20', 'ACTIVE', 'Kiểm hóa viên thành phẩm áo khoác'),
('EMP023', 'Châu Vĩnh Phát', 'TECH', 'SOUTH', 22000000.00, '2019-07-01', 'ACTIVE', 'Kỹ sư cơ điện tử tự động hóa nhà máy Nam'),
('EMP024', 'Trương Quang Vinh', 'TECH', 'SOUTH', 16500000.00, '2021-11-01', 'ACTIVE', NULL),
('EMP025', 'Lâm Mỹ Duyên', 'LOG', 'SOUTH', 18000000.00, '2021-04-12', 'ACTIVE', 'Trưởng phòng Logistics khu vực phía Nam'),
('EMP026', 'Quách Thái Dương', 'LOG', 'SOUTH', 15600000.00, '2022-08-15', 'ACTIVE', 'Nhân viên chứng từ xuất nhập khẩu'),
('EMP027', 'Huỳnh Ngọc Ánh', 'EXEC', 'SOUTH', 45000000.00, '2018-05-01', 'ACTIVE', 'Giám đốc Điều hành Nhà máy Phía Nam'),
('EMP028', 'Tô Hiếu Nghĩa', 'PRD', 'SOUTH', 14500000.00, '2023-03-01', 'ACTIVE', 'Thợ may bậc 5/7 may áo polo'),
('EMP029', 'Diệp Tố Uyên', 'QA', 'SOUTH', 14000000.00, '2023-06-15', 'ON_LEAVE', 'Nghỉ phép chế độ gia đình'),
('EMP030', 'Phùng Tuấn Kiệt', 'TECH', 'SOUTH', 13900000.00, '2023-09-01', 'RESIGNED', 'Chuyển định cư nước ngoài');
SQL;

        $this->executeDuckDbSql($sql);

        return 30;
    }

    /**
     * Execute a DuckDB SQL script via the CLI process.
     */
    protected function executeDuckDbSql(string $sql): string
    {
        $command = [$this->duckdbBinary, '-batch', '-light-mode'];

        $home = getenv('HOME');
        if (! is_string($home) || $home === '') {
            $pw = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
                ? posix_getpwuid(posix_geteuid())
                : null;
            $home = is_array($pw) && ! empty($pw['dir']) ? (string) $pw['dir'] : sys_get_temp_dir();
        }

        $process = new Process($command, null, ['HOME' => $home]);
        $process->setInput($sql);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                "DuckDB execution failed (Exit code {$process->getExitCode()}):\n".
                "STDOUT: {$process->getOutput()}\n".
                "STDERR: {$process->getErrorOutput()}"
            );
        }

        return $process->getOutput();
    }
}
