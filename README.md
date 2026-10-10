# Classroom Clicker

Hệ thống trả lời trắc nghiệm trong lớp. Giáo viên điều khiển trên web, sinh viên bấm đáp án trên tag cầm tay. Server PHP + MariaDB chạy trên laptop giáo viên, không cần cloud.

```
Tag (nRF52840) ⇄ BLE ⇄ Gateway USB (nRF52840) ⇄ Web Serial ⇄ Web giáo viên (Chrome) ⇄ api.php ⇄ MariaDB
```

## Thư mục

| Thư mục | Nội dung |
|---|---|
| `web/` | Web giáo viên và API (PHP, JS thuần) |
| `schema.sql` | Cấu trúc database |
| `config.example.php` | Mẫu file cấu hình |
| `examples/` | Danh sách lớp và đề mẫu để import |
| `firmware/ClickerTag/` | Firmware tag sinh viên |
| `firmware/ClickerGateway/` | Firmware gateway cắm USB laptop |
| `hardware/pcb/` | Sơ đồ nguyên lý, PCB (KiCad), Gerber, BOM |
| `hardware/case/` | Vỏ in 3D tag và gateway: file gốc OpenSCAD, STL để in, STEP mở bằng SolidWorks |
| `docs/` | Hướng dẫn sử dụng, mô tả dự án |

## Cài đặt nhanh

1. **Server:** Apache + PHP + MariaDB. Copy `web/` vào `/var/www/html/clicker/`, tạo DB từ `schema.sql`.
2. **Cấu hình:** copy `config.example.php` ra ngoài thư mục web, điền thông tin DB, đặt biến môi trường `CLICKER_CONFIG` trỏ tới file đó.
3. **Firmware:** Arduino IDE, core **Adafruit nRF52**, board **Adafruit ItsyBitsy nRF52840 Express** (nRF52840 SuperMini). Nạp `ClickerGateway.ino` vào board gateway, `ClickerTag.ino` vào board tag.
4. **Chạy:** mở `http://localhost/clicker/` bằng Chrome hoặc Edge, đăng nhập giáo viên, chọn chế độ BLE, cắm gateway và bấm **Kết nối Gateway** (lần đầu). Web Serial chỉ chạy trên `localhost` hoặc HTTPS.

Cách dùng chi tiết và bảng mã hiển thị trên tag: [docs/su_dung.md](docs/su_dung.md).

## Phần cứng tag

- nRF52840 SuperMini, bàn phím 3 × 4, 2 LED 7 đoạn anode chung qua 2 × 74HC595, motor rung, 2 pin AAA.
