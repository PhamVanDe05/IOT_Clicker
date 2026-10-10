# Smart Classroom Clicker — dự án và pipeline

Hệ thống trả lời trắc nghiệm không dây cho lớp học (Audience Response System). Cách dùng: [su_dung.md](su_dung.md).

## 1. Mục tiêu

- Mỗi sinh viên cầm một clicker. **Thiết bị không gắn cố định với sinh viên**: đầu mỗi buổi, sinh viên nhập MSSV; server tạo mapping tạm thời `thiết bị (UID) ↔ MSSV ↔ buổi học`. Buổi sau, cùng thiết bị có thể thuộc người khác.
- Thiết bị nhận số câu, cho chọn A/B/C/D và gửi về server. Nội dung câu hỏi chỉ hiện trên web/máy chiếu.
- Server lưu mọi câu trả lời, đối chiếu bảng đáp án, tính điểm, tự điền điểm vào danh sách và xuất CSV/Excel.
- Quy mô: **ít nhất 40 thiết bị mỗi lớp**, không mất và không trùng đáp án.

## 2. Phần cứng

| Phiên bản | Phần cứng | Trạng thái |
|---|---|---|
| **Sản phẩm cuối** | PCB Student Remote (`image/`): [nRF52840 ProMini](https://dientutuyetnga.com/products/mach-phat-trien-nrf52840-supermini-promicro-ble), ma trận 12 phím 3×4 có diode (`1 2 3 4 / 5 6 7 8 / BACK 9 0 OK`), 2 LED 7 đoạn anode chung HDSP-7501 qua 2×74HC595, motor rung (AO3400A), pin; BLE advertising tới Bluetooth của laptop | Firmware theo PCB đã biên dịch + test PC; **chưa có phần cứng thật** |
| Bản thử | ESP32 Dev Module + [OLED I²C](https://dientutuyetnga.com/products/man-hinh-oled-0-96-inch-giao-tiep-i2c-1-ma) SH1106G 128×64 (SDA21/SCL22, `0x3C`); Wi-Fi. Đang đấu 3 nút: GPIO34=`1`, GPIO35=BACK, GPIO32=OK (kéo lên 10 kΩ, mức thấp khi nhấn) | Firmware tối ưu độ trễ đã biên dịch; **chưa kiểm chứng trên board** |
| Bản thử | Điện thoại (trình duyệt) | Chạy được |

Cả ba loại dùng **chung server và database**; đổi phần cứng không đổi cấu trúc dữ liệu.

**Hai chế độ phím** (dùng chung một bộ phím số):

- *Đăng nhập*: phím 0–9 nhập MSSV, BACK xóa, OK gửi.
- *Câu hỏi*: chỉ `1=A`, `2=B`, `3=C`, `4=D`; phím khác bị bỏ qua. OK gửi; đổi lựa chọn được cho tới khi gửi.

## 3. Kiến trúc

```text
Tag nRF52840 ──BLE advertising──▶ bridge/ble_bridge.py ─┐
ESP32 ──────────Wi-Fi HTTP─────────────────────────────┼──▶ web/api.php + lib.php ──▶ MariaDB
Điện thoại ──phone.html → phone-api.php────────────────┘            ▲
Web giảng viên (index.html + app.js) ───────────────────────────────┘
```

- Server: Apache + PHP trên laptop giảng viên, MariaDB `smart_clicker`. Web phục vụ ở `/var/www/html/clicker/`, mở bằng `http://clicker.local/clicker/` (tên do service `clicker-mdns` phát qua Avahi).
- Thiết bị **poll** server (ESP32 0,4 s; điện thoại 1 s); bridge BLE đồng bộ mọi tag mỗi giây.
- Công tắc **Wi-Fi ↔ BLE** trên web (`app_state.mode`): thiết bị sai chế độ bị từ chối.

**Dữ liệu chính (11 bảng, 4 module; sơ đồ và từng cột: [HE_THONG.md mục 5](../HE_THONG.md)):** `teacher_account` (tài khoản giáo viên); `student` (danh sách riêng theo giáo viên), `exam` / `question` (bộ đề, đáp án đúng và điểm mỗi câu); `classroom_session` (phòng, trạng thái, câu hiện tại, AUTO), `session_student` (bản chụp lớp khi tạo phòng + board mỗi sinh viên đăng nhập; mỗi MSSV và mỗi board tối đa một cặp), `session_question` (thời điểm mở/đóng từng câu), `student_response` (một đáp án cho mỗi sinh viên mỗi câu; điểm tính từ bảng này); `device` (thiết bị + khóa board), `app_state`, `request_limit`. Mỗi lúc chỉ có một phòng hoạt động trên máy chủ vì ESP32 hiện chưa chọn phòng. Schema: [`schema.sql`](../schema.sql).

**Quy tắc bảo đảm không mất dữ liệu:**

- Server **lưu vào database rồi mới trả ACK**. Thiết bị giữ gói chưa ACK và gửi lại đúng gói đó; server nhận gói trùng thì trả `duplicate`, không ghi thêm.
- Lệnh giáo viên được xử lý tuần tự; đăng nhập/trả lời của thiết bị xử lý song song nhưng không bao giờ lọt vào câu đã đóng.
- Đáp án tới trong 1,5 giây sau khi câu đóng vẫn được nhận.

## 4. Luồng một buổi

```text
Nhập danh sách lớp + bộ đề
  → Tạo phòng (bản chụp lớp; phòng cũ tự kết thúc)
  → Mở đăng nhập → thiết bị gửi UID + MSSV → session_student.device_uid
  → Bắt đầu / AUTO (tự đóng đăng nhập, mở câu đầu tiên chưa làm)
  → thiết bị nhận số câu → chọn 1–4 → OK → server lưu → ACK
  → NEXT … (AUTO: 30 s/câu)  → web cập nhật trực tiếp
  → KẾT THÚC (đóng câu + kết thúc buổi; AUTO tự kết thúc sau câu cuối)
  → Xuất CSV/Excel (điểm tự tính theo các câu đã đóng)
  → (tùy chọn) Kiểm tra lại: lượt mới cùng lớp/đề, giữ kết quả cũ
```

## 5. Pipeline triển khai

| # | Bước | Kết quả cần đạt | Trạng thái |
|---|---|---|---|
| 1 | Hợp đồng dữ liệu và trạng thái | UID, MSSV, session, question, login/answer/ACK dùng chung cho firmware, server, web | Xong |
| 2 | Database | Ràng buộc mapping và một đáp án mỗi câu; gói gửi lại không tạo dòng trùng | Xong |
| 3 | Nhập danh sách sinh viên | CSV/Excel, giữ MSSV dạng chuỗi, báo dòng lỗi | Xong |
| 4 | Nhập bộ đề | TXT `[QUESTIONS]` + `[ANSWER_KEY]`, kiểm tra đủ trước khi lưu, điểm mỗi câu | Xong |
| 5 | Thiết bị thử | ESP32 + OLED + phím, Wi-Fi, UID | Code xong; board mới xác nhận OLED/phím bằng sketch thử |
| 6 | Tạo phòng, mở đăng nhập | Phòng có bản chụp lớp; thiết bị chuyển sang nhập MSSV | Xong |
| 7 | Đăng nhập sinh viên | Kiểm tra MSSV thuộc lớp, mapping chưa bị chiếm | Xong |
| 8 | Phát câu hỏi | Bắt đầu/NEXT/AUTO; thiết bị chưa đăng nhập không được trả lời | Xong |
| 9 | Chọn, gửi, lưu đáp án | Lưu rồi mới ACK; đáp án đã lưu không đổi được | Xong |
| 10 | Mất kết nối, cập nhật trực tiếp | Gửi lại không trùng; web cập nhật mỗi giây | Xong |
| 11 | Đóng câu, chấm điểm | KẾT THÚC; tự điền điểm; câu không trả lời 0 điểm | Xong |
| 12 | Xuất file, kiểm thử toàn luồng | CSV/Excel; test luồng và test tải 40/80 thiết bị | Xong |
| 13 | Phần cứng cuối nRF52840 | BLE qua bridge, LED 7 đoạn, rung; cùng server/database | Code + test PC xong; **chờ phần cứng** |
| 14 | Kết nối dễ dùng | Tên `clicker.local`, cấp Wi-Fi ESPTouch chỉ cần mật khẩu | Xong phần web; chưa thử cấp Wi-Fi trên board |
| 15 | Điều khiển lớp | AUTO 30 s/câu, Privacy bảng điểm theo cột, Kiểm tra lại | Xong |

**Mốc hoàn thành:** một sinh viên cầm bất kỳ thiết bị nào, đăng nhập đúng phòng, trả lời và thấy điểm trong file xuất; gửi lại không bị tính hai lần.

## 6. Kiểm thử

Chạy trong thư mục `clicker/` (không cần phần cứng; dùng DB `smart_clicker_test`):

| Lệnh | Kiểm tra |
|---|---|
| `php tests/flow_test.php` | Toàn luồng phòng qua HTTP thật (37 bước) |
| `python3 tests/load_test.py --devices 40` | 40 thiết bị, cả lớp bấm cùng lúc, bỏ 15% ACK, đối chiếu DB |
| `g++ -std=c++17 tests/controller_test.cpp -o /tmp/ct && /tmp/ct` | Logic phím/đăng nhập/ACK của ESP32 |
| `g++ -std=c++11 tests/ble_test.cpp -o /tmp/bt && /tmp/bt` | Logic tag nRF |
| `python3 -m unittest tests/test_ble_bridge.py` | Bridge BLE |
| `php tests/esptouch_test.php` | Gói cấp Wi-Fi |

Kết quả ngày 26/09/2026 (PHP server 40–64 worker và MariaDB cùng máy, poll 0,4 s): 40 thiết bị không mất/trùng/sai, lưu đáp án p99 ≈ 44 ms; 80 thiết bị p99 ≈ 72 ms.

## 7. Còn mở

1. Nạp firmware ESP32 mới, đo độ trễ thật; thử cấp Wi-Fi, khởi động lại, đăng nhập và trả lời trên board.
2. Làm tag nRF52840 thật: đo tầm BLE, dòng tiêu thụ, thời gian ACK khi 40 tag cùng bấm (thiết kế hiện tại ≈ 2–3 s; có thể cải thiện bằng extended advertising BT5). PCB đã đặt: `image/Student Remote/` (50×75 mm, SuperMini hàn sát board). **Lỗi trên board đã đặt:** 2 con 74HC595 nối RCLK (chân 12) xuống GND và SRCLR (chân 10) vào `lat` như sơ đồ mẫu `mau.png`, nên LED 7 đoạn không chốt được; cần sửa board trước khi dùng màn 7 đoạn. Vỏ tag V3 và vỏ gateway ở `image/Student Remote/case_v3/` và `Gateway/`. (Bản schematic/PCB 50×80 sinh tự động trước đây đã xóa ngày 05/10/2026.)

   | Tín hiệu | Header ProMini | GPIO | Ghi chú |
   |---|---|---|---|
   | `data` / `clk` / `lat` (74HC595) | J1-13 / J2-7 / J2-8 | P0.09 / P0.20 / P0.17 | P0.09 là chân NFC, firmware tự chuyển sang GPIO lần đầu |
   | `row1..3` | J1-10 / 11 / 12 | P1.13 / P1.11 / P0.10 | đọc, kéo lên bên trong; P0.10 là chân NFC |
   | `col1..4` | J1-9 / 8 / 7 / 6 | P1.15 / P0.02 / P0.29 / P0.31 | quét: kéo từng cột xuống thấp |
   | `mot` | J2-6 | P0.22 | mức cao = motor chạy |

   Ánh xạ header → GPIO suy ra từ pinout ProMicro/nice!nano (khớp GND ở J1-3, J2-9/10 và 3V ở J1-5); cần đối chiếu chữ in trên board thật. Logic LED/phím có test PC `tests/board_test.cpp`.
3. Router/access point cho 40 thiết bị Wi-Fi.
4. Quyết định có cho sinh viên đến muộn đăng nhập sau khi Bắt đầu hay không.
5. Chuyển dữ liệu sang database `IOT` (`tools/migrate_to_iot.php`) — chưa chạy.
