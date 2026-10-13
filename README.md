# Classroom Clicker

Hệ thống trả lời trắc nghiệm trong lớp: sinh viên bấm đáp án trên tag cầm tay, giáo viên xem kết quả cả lớp ngay trên web.

![Sơ đồ khối](docs/images/so_do_khoi.png)

## Cách hoạt động

1. Tag bấm **OK** thì phát gói `ANSWER` qua BLE, phát lại liên tục tới khi nhận ACK.
2. Gateway nghe gói, chuyển lên web qua USB Serial.
3. Web (Chrome/Edge, Web Serial) gọi `api.php`.
4. Server **lưu database xong mới trả ACK**; gói trùng không bị ghi 2 lần.
5. ACK đi ngược xuống trong khung `STATE`; tag thấy đúng MAC của mình thì dừng phát và hiện `b=`.

![Một câu trả lời và ACK](docs/images/so_do_ack.png)

![Ô ACK 6 byte](docs/images/ack_6_byte.png)

Định dạng từng byte: [`firmware/ClickerTag/protocol.h`](firmware/ClickerTag/protocol.h).

## Phần cứng

- **Tag:** nRF52840 SuperMini, bàn phím 3×4, 2 LED 7 đoạn qua 2×74HC595, motor rung, pin AAA.
- **Gateway:** nRF52840 SuperMini cắm USB laptop.

<table>
  <tr>
    <td align="center"><img src="docs/images/tag_case.png" width="260"><br>Vỏ tag</td>
    <td align="center"><img src="docs/images/tag_bottom.png" width="260"><br>Đáy tag</td>
    <td align="center"><img src="docs/images/gateway_case.png" width="260"><br>Vỏ gateway</td>
  </tr>
  <tr>
    <td align="center"><img src="docs/images/gateway_bottom.png" width="260"><br>Đáy gateway</td>
    <td align="center"><img src="docs/images/pcb_top_3d.jpg" width="260"><br>PCB tag, mặt trên</td>
    <td align="center"><img src="docs/images/pcb_bottom_3d.jpg" width="260"><br>PCB tag, mặt dưới</td>
  </tr>
  <tr>
    <td colspan="3" align="center"><img src="docs/images/schematic.png" width="800"><br>Sơ đồ nguyên lý</td>
  </tr>
</table>

### Màn hình tag trong một buổi

![Luồng màn hình tag](docs/images/luong_man_hinh_tag.png)

Bảng mã đèn và mã lỗi đầy đủ: [docs/su_dung.md](docs/su_dung.md).

## Giao diện web

<table>
  <tr>
    <td align="center"><img src="docs/images/landing.jpg" width="420"><br>Trang chủ</td>
    <td align="center"><img src="docs/images/login.jpg" width="420"><br>Đăng nhập</td>
  </tr>
  <tr>
    <td align="center"><img src="docs/images/overview.jpg" width="420"><br>Tổng quan</td>
    <td align="center"><img src="docs/images/prepare.jpg" width="420"><br>Chuẩn bị lớp và bộ đề</td>
  </tr>
  <tr>
    <td align="center"><img src="docs/images/room.jpg" width="420"><br>Tạo phòng, mã phòng</td>
    <td align="center"><img src="docs/images/live.jpg" width="420"><br>Trực tiếp: câu hỏi và biểu đồ đáp án</td>
  </tr>
  <tr>
    <td align="center"><img src="docs/images/analysis-section.jpg" width="420"><br>Phân tích câu hỏi</td>
    <td align="center"><img src="docs/images/results-section.jpg" width="420"><br>Bảng kết quả</td>
  </tr>
</table>

## Thư mục

| Thư mục | Nội dung |
|---|---|
| `web/` | Web giáo viên và API |
| `schema.sql` | Cấu trúc database |
| `config.example.php` | Mẫu file cấu hình |
| `examples/` | Danh sách lớp và đề mẫu |
| `firmware/ClickerTag/` | Firmware tag sinh viên |
| `firmware/ClickerGateway/` | Firmware gateway cắm USB laptop |
| `hardware/` | PCB (KiCad, Gerber, BOM) và vỏ in 3D tag, gateway |
| `docs/su_dung.md` | Hướng dẫn sử dụng, bảng mã đèn và mã lỗi |

## Cài đặt nhanh

**Server**

1. Import `schema.sql` vào MariaDB.
2. Chép `config.example.php` ra ngoài thư mục web, điền database và khóa; trỏ biến môi trường `CLICKER_CONFIG` tới file đó.
3. Đặt `web/` dưới Apache, mở <http://localhost/clicker/> bằng Chrome hoặc Edge.

**Firmware**: Arduino IDE, core **Adafruit nRF52**, board **Adafruit ItsyBitsy nRF52840 Express**. Nạp `firmware/ClickerTag/` vào tag, `firmware/ClickerGateway/` vào gateway.

Hướng dẫn một buổi kiểm tra: [docs/su_dung.md](docs/su_dung.md).
