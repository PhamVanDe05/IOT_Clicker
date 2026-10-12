# Clicker: tài liệu dự án

Hệ thống trả lời trắc nghiệm trong lớp. Giáo viên soạn đề, mở phòng và điều khiển trên web; sinh viên trả lời bằng
điện thoại / laptop (chế độ **Wi-Fi**) hoặc máy bấm **Student Tag** nRF52840 (chế độ **BLE**). Máy chủ PHP + MariaDB
chạy trên laptop của Duy, không dùng cloud. Nhiều giáo viên dạy cùng lúc được, mỗi người một phòng.

> Đây là **file tài liệu duy nhất** của dự án. Đổi chức năng web thì sửa
> file này và thêm một dòng vào **Nhật ký thay đổi** ở cuối.

| Ai | Link |
|---|---|
| Mọi máy, mọi mạng | https://clicker.tailbc421d.ts.net/clicker/ (trang ngoài: `…/clicker/landing/`) |
| Cùng Wi-Fi với laptop | `http://<IP-laptop>/clicker/` (`bash tools/check_web.sh` in IP) |
| Sinh viên | `…/clicker/phone.html` (hoặc gõ mã phòng ở trang ngoài) |

---

## 1. Dùng web (giáo viên)

**Một buổi học:** Chuẩn bị (① Lớp học → ② Bộ đề → ③ Phòng, Mở đăng nhập) → sinh viên vào bằng mã phòng hoặc Student Tag
→ Trực tiếp (Bắt đầu, NEXT, chiếu, xem %) → Phân tích → Kết quả (bảng điểm, xuất Excel).

### 1.1 Tài khoản
- Đăng nhập bằng **Google** hoặc tên + mật khẩu **8 số**. Chỉ giáo viên có tài khoản; sinh viên chỉ dùng MSSV.
- Lần đầu phải điền **hồ sơ** (họ tên, giới tính, trường, môn, khối). Góc trên phải: ảnh + tên → *Hồ sơ của bạn*, *Cài đặt*
  (giao diện Sáng / Tối / Theo hệ thống, đổi mật khẩu), *Đăng xuất*. Ngôn ngữ **VI / EN / 中文**.
- **Quên mật khẩu:** trang đăng nhập → *Quên mật khẩu?* → màn chờ → quản trị viên bấm *Cấp phép* (chuông 🔔) → đặt mật khẩu
  mới trên chính trình duyệt đó (hiệu lực 30 phút).

### 1.2 Chuẩn bị (menu **Chuẩn bị**, thanh 3 bước)
Mỗi bước: thẻ **thêm** bên trái, **danh sách của bạn** bên phải. Bấm **Chọn** ở một dòng thì dòng đó tô xanh
**✓ Đang chọn** và được điền sẵn khi tạo phòng; danh sách hơn 5 mục có ô **Tìm…**. Thông báo hiện nổi góc phải rồi tự ẩn.

**① Lớp học**
- *Từ file*: CSV/Excel, cột `STT, MSSV, Họ tên, Lớp` (tên cột không cần đúng dấu). MSSV chỉ gồm chữ số (≤ 20), để cột
  dạng **Text** trong Excel để giữ số 0 đầu; CSV lưu UTF-8; tối đa 1.000 dòng. Mẫu: `examples/students.csv`.
- *Tự đăng ký*: chỉ đặt tên lớp; sinh viên gõ mã phòng, tự nhập họ tên + MSSV, bạn duyệt ✓ / ✗ (Duyệt tất cả). Danh sách tự
  đánh STT theo MSSV tăng dần. Chỉ dùng ở chế độ Wi-Fi (tag BLE cần danh sách có sẵn). *Tải CSV* lấy danh sách mới nhất.

**② Bộ đề** (4 lựa chọn A–D, 1 đáp án đúng; 1–200 câu). Thẻ *Thêm câu hỏi* có 3 cách, cách nào cũng có ô **Lưu vào**
(bộ đề mới hoặc thêm vào bộ đề có sẵn):
- **File / Excel**: cột `STT, Câu hỏi, A, B, C, D, Đáp án` (*Tải mẫu Excel*), hoặc dán thẳng từ Excel, hoặc file TXT
  (`[QUESTIONS]` gồm `Q1` + câu hỏi + `A.`…`D.`, rồi `[ANSWER_KEY]` gồm `Q1=A`…; mẫu `examples/exam.txt`).
  *Kiểm tra & xem trước* tô đỏ dòng lỗi kèm lý do; hết lỗi mới *Lưu bộ đề*.
- **Soạn tay**: gõ câu hỏi + 4 lựa chọn, bấm ô A/B/C/D chọn đáp án đúng → *Lưu câu hỏi* (Ctrl+Enter). Câu đầu tạo bộ đề, các
  câu sau vào tiếp.
- **Tạo bằng AI**: dán nội dung môn học (≤ ~120.000 ký tự) hoặc file `.txt/.md/.csv`, chọn số câu (1–40), độ khó (Dễ /
  Trung bình / Khó / Nâng cao / Trộn đều), ngôn ngữ, yêu cầu thêm → AI soạn câu chỉ từ nội dung đó, kèm chủ đề, độ khó,
  lời giải thích → bỏ tick câu không ưng → Lưu. Tối đa 15 lần/giờ/giáo viên. Luôn đọc lại trước khi lưu.
- **Sửa**: *Sửa câu* ở bộ đề → bấm A/B/C/D đổi đáp án ngay, *Sửa* nội dung, *＋ Thêm câu hỏi*, đổi tên, xoá câu chưa dùng.
  Đổi đáp án của câu đã dùng thì các buổi cũ **tự chấm lại**; câu đã dùng không xoá được.
- Dữ liệu thử: `du-lieu-mau/lop/*.csv` (3 lớp giả) và `du-lieu-mau/de/*.xlsx` (3 đề); `examples/de-iot-co-loi-thu.xlsx`
  cố tình sai 2 dòng để xem khung báo lỗi.

**③ Phòng**
- Gõ tên buổi, chọn lớp, bộ đề, hình thức làm bài, số giây mỗi câu → *Tạo session* → *Mở đăng nhập* → đọc **MÃ PHÒNG** 6 số.
- Mỗi phòng có **mã riêng** và **chế độ riêng**: công tắc **Wi-Fi / BLE** ở thanh trên là của phòng bạn (phòng mới theo chế độ
  đang chọn; chỉ đổi được khi chưa mở đăng nhập / chưa có câu chạy).
- Mỗi giáo viên một phòng chạy; tạo phòng mới chỉ kết thúc phòng cũ **của bạn**. Phòng tự kết thúc khi đăng xuất hoặc
  30 phút không thao tác. *Kiểm tra lại* tạo lượt mới cùng lớp và đề (mã phòng mới).
- *Ai đã vào phòng?*: xanh = đã vào (loại máy: Điện thoại / nRF / Tag mô phỏng), đỏ = chưa vào. Khung *Chờ duyệt* cho người
  tự đăng ký.

### 1.3 Sinh viên vào lớp
| Máy | Cách vào |
|---|---|
| Điện thoại / laptop (phòng Wi-Fi) | Mở trang sinh viên → gõ **mã phòng** → MSSV (chưa có trong lớp thì thêm họ tên, chờ duyệt) → màn chờ xanh → bấm A/B/C/D → *Gửi*. Gửi xong hiện nhãn nhỏ "✓ Đã gửi B". |
| Student Tag thật (phòng BLE) | **Không nhập mã phòng.** Tag hiện `Lo` → gõ **MSSV** → OK → `Hi` → đứng `rd` chờ giáo viên bấm Bắt đầu. Xem 1.6. |
| Tag mô phỏng (phòng BLE) | Gõ mã phòng ở trang sinh viên → trang thành một Student Tag giống thật, **gửi thật** (để thử khi chưa đủ tag). |

### 1.4 Hai hình thức làm bài
| | Giáo viên điều khiển | Sinh viên tự làm (chỉ Wi-Fi) |
|---|---|---|
| Sang câu | NEXT, hoặc AUTO đủ số giây mỗi câu | Trả lời xong là sang câu kế; quá số giây chưa chọn thì tự sang |
| Màn chiếu | Câu hỏi to, *Hiện kết quả* % | Tiến độ từng người + đồng hồ cả bài (số câu × số giây) |
| Kết thúc | KẾT THÚC | Hết giờ cả bài hoặc mọi người xong thì tự kết thúc; KẾT THÚC bấm được bất cứ lúc nào |

Máy sinh viên thấy câu hỏi + 4 đáp án, không bao giờ thấy đáp án đúng. Kết thúc thì máy hiện điểm "x/10".
Điểm /10 = số câu đúng ÷ tổng số câu × 10 (câu chưa trả lời tính 0).

### 1.5 Trực tiếp, Phân tích, Kết quả
- **Bắt đầu** (đóng đăng nhập, mở câu đầu chưa làm) · **NEXT** · **KẾT THÚC** (nút đỏ) · **AUTO** · **Hiện kết quả** (thanh %,
  đáp án đúng ✓) · **Sửa câu này** · **Chế độ trình chiếu** (toàn màn hình cho TV, chữ tự co cho vừa). Khung phải: số đã trả
  lời, đúng/sai, phân bố A–D, thiết bị trong phòng.
- **Phân tích**: % đúng từng câu, câu sai nhiều nhất. **Kết quả**: bảng theo STT, ô xanh đúng / đỏ sai, cột Điểm /10,
  *Privacy* ẩn tên/MSSV/đáp án (bấm tiêu đề cột để hiện), *Xuất CSV / Excel*.

### 1.6 Chế độ BLE: Gateway USB và Student Tag
- Tag nói chuyện bằng quảng bá BLE với **Gateway USB** (1 nRF52840 SuperMini nạp `firmware/ClickerGateway`) cắm vào máy tính
  giáo viên; trang web nói với gateway qua Web Serial và gọi máy chủ bằng phiên giáo viên. Tag **đi theo phòng của giáo viên
  đang mở trang có gateway**, nên mỗi lớp cắm gateway riêng là không lẫn nhau, và máy chủ không cần ở trong lớp.
- Mỗi buổi: bật **BLE** → tab **Thiết bị** → *Kết nối Gateway* → chọn cổng nRF52840 (chỉ lần đầu; sau đó tự kết nối, rút ra
  cắm lại cũng tự nối) → thẻ hiện *Đã kết nối*, số tag nghe thấy, số gói đã chuyển. Cần **Chrome hoặc Edge trên máy tính** và
  trang `https://` (hoặc `localhost`). **Giữ tab mở** suốt buổi (chuyển tab được; đóng tab thì web hỏi lại).
- Màn LED: `--` chưa có phòng · `Lo` nhập MSSV · `Hi` vào được · `rd` chờ câu · số câu (vd ` 7`) · ` b` đang chọn B ·
  `b=` đã lưu · khi **KẾT THÚC**, hiện điểm cá nhân /10 trong 5 giây (LED 7 đoạn làm tròn số nguyên, OLED nếu có hiện 1 chữ số thập phân), sau đó hiện `En` · `LC` đăng nhập đã đóng · `nC` không nghe thấy gateway · `nS` gateway mất máy chủ / tab đóng ·
  `OF` phòng đang Wi-Fi. Phím `1–4` = A–D, `←` xoá, OK gửi.
- Lỗi: `E1` chưa mở đăng nhập · `E2` MSSV không có trong lớp của phòng (đừng gõ mã phòng vào tag) · `E3` MSSV đang ở tag
  khác · `E4` tag đã gắn MSSV khác · `E5` chưa đăng nhập · `E6` câu đã đóng · `E7` đáp án đã lưu · `E8` máy chủ từ chối.
- **Tag mô phỏng** gửi thật như tag phần cứng (ghi "Tag mô phỏng" trong danh sách). Dạy thật bằng tag thì tắt ở
  *Quản trị → Cài đặt → Máy bấm mô phỏng trên điện thoại (BLE)*.

### 1.7 Quản trị (tài khoản quản trị viên; đăng nhập trong `.local/admin-login.txt`)
| Thẻ | Làm gì |
|---|---|
| Tổng quan | **Phòng đang chạy** (mọi giáo viên, chế độ, số SV; nút *Kết thúc*), danh sách giáo viên: bấm để xem lớp, bộ đề, hồ sơ |
| Giáo viên | Chấm xanh = online; tạo tài khoản, đặt lại mật khẩu, khoá, cho làm quản trị, xem dữ liệu (chỉ đọc), xoá nhiều (gõ `XOA n`) |
| Thiết bị | Đặt tên, chặn / bỏ chặn, xoá (máy có điểm được giữ lại), lọc theo loại |
| Báo cáo | Điểm trung bình theo giáo viên / lớp / bộ đề, top câu sai, xuất Excel |
| Nhật ký | Ai làm gì lúc nào; chọn để xoá |
| Cài đặt | Công tắc: đăng ký mới, trợ lý AI, sinh viên tự làm, tự đăng ký, tag mô phỏng; tự dọn nhật ký cũ (30–365 ngày) |

Nút *Sao lưu database* tải file `.sql.gz`. Chuông 🔔: yêu cầu quên mật khẩu chờ cấp phép.

### 1.8 Trợ lý AI
Bong bóng **C** góc dưới phải trả lời cách dùng, thiết bị, bảng giá. Mô hình: `sudo bash tools/setup_ai.sh` (trợ lý) và
`sudo bash tools/setup_quiz_ai.sh` (tạo đề; để trống = Gemini, hoặc địa chỉ máy chủ kiểu OpenAI như vLLM / Ollama / LM Studio).

---

## 2. Phần cứng

| Phần | Nội dung |
|---|---|
| Student Tag | nRF52840 SuperMini, bàn phím 3 × 4 (`1 2 3 4 / 5 6 7 8 / ← 9 0 OK`), 2 LED 7 đoạn HDSP-7501 qua 2 × 74HC595, motor rung, 2 pin AAA. Nạp `firmware/ClickerTag/ClickerTag.ino` cho Tag đang dùng ClickerTag; bản có OLED dùng `firmware/StudentTagNRF52/StudentTagNRF52.ino` |
| Gateway USB | 1 nRF52840 SuperMini cắm USB. Firmware `firmware/ClickerGateway/` |
| Mạch, vỏ | `hardware/pcb/` (KiCad, Gerber, BOM), `hardware/case/` (vỏ tag V3 + vỏ gateway: `.scad` gốc, `stl/` để in, `step/` cho SolidWorks; STEP không đưa vào git) |

- **Nạp firmware** (Duy tự nạp): Arduino IDE → Boards Manager cài **Adafruit nRF52** → board **Adafruit ItsyBitsy nRF52840
  Express** → Upload. Nạp xong đóng Serial Monitor (đang mở thì web không mở được cổng USB).
- **Thử bàn phím**: nạp `firmware/TestKeypadNRF52`, Serial 115200 in từng phím bấm kèm chân hàng/cột.
- **Lỗi mạch lô đầu**: chân 12 RCLK của 2 con 74HC595 phải nối `lat` (J2-8), chân 10 SRCLR nối 3V (mạch đặt lần đầu nối
  ngược nên LED không chốt được, phải câu dây).
- **LED mờ**: mỗi thanh LED qua điện trở 470 Ω với pin ~3 V chỉ được ~1–2 mA. Muốn sáng rõ: R1–R15 xuống 100–150 Ω và dùng
  pin mới. Firmware đã bật LED hết mức.
- Chưa có số đo thật: tầm BLE, dòng tiêu thụ, thời gian ACK khi 40 tag bấm cùng lúc.

---

## 3. Chạy và bảo trì máy chủ

Apache, MariaDB và Tailscale tự chạy khi bật máy. Kiểm tra nhanh: `bash ~/clicker/tools/check_web.sh` (dòng ✘ in sẵn lệnh sửa).
Muốn web chạy lâu: cắm sạc, tắt ngủ máy (Settings → Power → Automatic Suspend: Off).

| Triệu chứng | Làm gì |
|---|---|
| Không mở được trang nào | `sudo systemctl restart apache2 mariadb` |
| Đăng nhập báo lỗi máy chủ | `sudo systemctl restart mariadb`; xem `sudo tail -30 /var/log/apache2/error.log` |
| Máy cùng Wi-Fi không vào được | `sudo ufw allow 80/tcp` |
| Link công khai chết (thường sau khi đổi Wi-Fi) | `systemctl --user restart tailscaled`, đợi 1–2 phút; vẫn chết thì `~/.local/bin/tailscale funnel --bg --set-path=/clicker http://127.0.0.1:80/clicker` |
| Nút Google / trợ lý AI lỗi | `grep "Clicker OAuth\|Clicker assistant" /var/log/apache2/error.log \| tail`; sửa khoá: `sudo bash tools/setup_oauth.sh` / `setup_ai.sh` |
| Lỗi ngẫu nhiên, log "No space left on device" | Ổ đĩa đầy |

**Khoá và cấu hình**: `/etc/clicker/prod-config.php` (chỉ đọc bằng sudo). Không dán khoá vào chat, không đưa lên git.

**Database** (MariaDB): `smart_clicker` = dữ liệu thật · `smart_clicker_test` = cho test. Nâng cấp (tự sao lưu trước, chỉ
thêm bảng/cột, chạy lại được): `sudo bash tools/setup_db.sh`. Sao lưu tay: `php tools/backup.php` (vào `.local/backups/`;
service `clicker-backup.timer` sao lưu mỗi ngày 03:00). Xoá 3 database cũ không dùng: `sudo bash tools/cleanup_old_db.sh`.

**Đưa code lên web** (bản gốc `web/`, Apache chạy bản sao `/var/www/html/clicker/`, không sửa trực tiếp bản sao):
```bash
cd ~/clicker
cp -a /var/www/html/clicker .local/www-backup-$(date +%Y%m%d-%H%M)     # sao lưu bản đang chạy
cp web/*.php web/*.js web/*.html web/*.css /var/www/html/clicker/ && cp -a web/landing/. /var/www/html/clicker/landing/
bash tools/check_web.sh && git add -A && git commit -m "…"
```
Mỗi lần đưa lên: tăng `?v=…` của file CSS/JS đã sửa trong `index.html` / `phone.html`, và tăng `WEB_VERSION` (cùng một chuỗi)
ở `web/lib.php`, `web/app.js`, `web/phone.js`: trang đang mở sẽ tự tải lại bản mới (Apache không gửi header chống cache).

---

## 4. Kỹ thuật

```text
Điện thoại / laptop ── phone.html + mã phòng ──▶ phone-api.php ─┐
Student Tag ══BLE══ Gateway USB ══Web Serial══ tab giáo viên ───┼──▶ api.php + lib.php ──▶ MariaDB
Web giáo viên (index.html, app.js…) ────────────────────────────┘
```

| Thư mục | Nội dung |
|---|---|
| `web/` | Web giáo viên (`index.html`, `app.js`, `theme.css`…), API (`api.php`, `lib.php`, `ai.php`, `room.php`, `phone-api.php`), trang sinh viên (`phone.*`), gateway (`gateway.js`) |
| `web/landing/` | Trang ngoài: giới thiệu, bảng giá, đăng nhập (Google), trợ lý AI |
| `firmware/`, `hardware/` | Tag, gateway, thử bàn phím; mạch và vỏ |
| `tests/` | Test tự động (DB test) · `tools/` script cài đặt, nâng cấp DB, sao lưu, `dev/` (máy chủ thử, chụp màn hình) |
| `legacy/` | ESP32, bridge Python, ESPTouch (ngừng dùng 12/10/2026; máy chủ trả `410 DEVICE_REMOVED`) |
| `examples/`, `du-lieu-mau/` | File mẫu |
| `image/` | Bản mạch/vỏ tự sinh đời trước (`image/StudentTag`), ảnh mẫu; bản dùng thật ở `hardware/` |
| `.local/` | Mật khẩu, cấu hình test, sao lưu (bí mật, không có trong git) |

**Database** (`schema.sql`, 17 bảng): tài khoản `teacher_account`, `teacher_profile`; dữ liệu giáo viên `student`,
`teacher_class`, `exam`, `question`; phòng `classroom_session` (mỗi phòng một dòng: trạng thái, câu đang mở, AUTO, `pacing`,
`mode` Wi-Fi/BLE), `session_student` (danh sách lớp chép lúc tạo phòng + thiết bị đã đăng nhập), `session_question`,
`student_response`, `join_request`; hệ thống `device`, `app_state` (khoá chung của máy chủ), `request_limit`, `app_setting`,
`audit_log`, `password_request`. Chỉ nhóm phòng lớn dần (~150 KB cho 40 SV × 20 câu).

**Quy tắc không được phá**
- Máy chủ ghi DB rồi mới ACK; thiết bị gửi lại cùng gói tới khi có ACK, gói trùng trả `duplicate` (không ghi thêm).
- Lệnh giáo viên khoá `app_state` FOR UPDATE; đăng nhập / trả lời của thiết bị khoá chia sẻ; bước AUTO chạy trong
  transaction riêng trước đó. INSERT trùng khoá dùng `insert_once()` (409, không 500).
- Đáp án được nhận tới 1,5 s sau khi câu đóng. AUTO không có tiến trình hẹn giờ: request nào gặp hạn thì chuyển câu.
- Phòng đang chạy = phòng chưa `FINISHED`; mỗi giáo viên tối đa 1 phòng. Điện thoại chỉ vào phòng có mã nó gửi (hoặc phòng
  nó đã ngồi); gateway chỉ dùng phòng của giáo viên đang đăng nhập. Màn chiếu không bao giờ lộ đáp án đúng trước khi bấm.

**Gói BLE** (Manufacturer Data, công ty `0xFFFF`, mở đầu `S C 01`, số little-endian, ≤ 31 byte; định nghĩa ở
`firmware/StudentTagNRF52/protocol.h`, vector kiểm thử trong `tests/gateway_js_test.mjs`):
- Tag → gateway: `HELLO` (nhịp tim: phòng, câu, đã đăng nhập, đáp án đã lưu), `LOGIN` (MSSV nén 4 bit/số), `ANSWER`.
  `seq` giữ nguyên khi gửi lại, `counter` tăng mỗi lần phát.
- Gateway → mọi tag: `STATE` 27 byte (phòng, trạng thái, câu, số câu + 2 ô trả lời theo 3 byte cuối MAC tag:
  `LOGIN_OK`, `ANSWER_OK`, `STATUS` sửa điều tag tin sai, `ERROR` 1–8). Web gửi 1 khung mỗi 120 ms, mỗi ACK lặp 10 khung.

**Test** (không cần phần cứng, chạy trên DB test, chạy đủ trước khi đưa lên):
```bash
for t in flow multi_room security room_code question_edit self_join self_paced admin profile oauth_account ai_quiz gateway ble_sim; do php tests/${t}_test.php | tail -1; done
node tests/gateway_js_test.mjs && node tests/ui_login_test.mjs
g++ -std=c++17 -I firmware/StudentTagNRF52 tests/ble_test.cpp -o /tmp/bt && /tmp/bt    # logic tag (tương tự board_test.cpp)
```
Xem giao diện trên DB test: `bash tools/dev/serve_test.sh` + `php tools/dev/seed_preview.php` (tài khoản `xemtruoc`, mật khẩu
trong `.local/preview-login.txt`) → http://127.0.0.1:8099/clicker/; chụp so sánh mọi trang: `bash tools/dev/snap_all.sh <thư-mục>`.

---

## 5. Nhật ký thay đổi

| Ngày | Thay đổi |
|---|---|
| 26–30/09/2026 | Bản gốc (ESP32, bridge BLE, điện thoại), trang ngoài + đăng nhập Google, trợ lý AI Gemini, link công khai Tailscale Funnel. |
| 01/10/2026 | Giao diện mới, menu dọc, 3 ngôn ngữ; mã phòng 6 số; nhập đề Excel/CSV/dán + xem trước lỗi; trình soạn bộ đề (đổi đáp án tự chấm lại); Hiện kết quả, Sửa câu này. |
| 02/10/2026 | Sinh viên tự đăng ký + duyệt; màn trình chiếu cho TV; trang sinh viên mới; ô kết quả xanh/đỏ; hình thức Sinh viên tự làm, điểm /10; dữ liệu mẫu `du-lieu-mau/`. |
| 03/10/2026 | Trang Quản trị (tài khoản, thiết bị, báo cáo, nhật ký, cài đặt, sao lưu), quên mật khẩu chờ cấp phép + chuông; hồ sơ giáo viên, ảnh, giao diện tối; chấm online; xoá nhiều; Tạo đề bằng AI. |
| 12/10/2026 | Gateway USB cho BLE; tab Chuẩn bị (Lớp học → Bộ đề → Phòng), Soạn tay, giao diện bớt chữ; KẾT THÚC nút đỏ; nhãn "✓ Đã gửi" nhỏ; tag mô phỏng gửi thật; dòng đang chọn tô xanh + ô tìm; trang tự tải lại khi có bản mới; **nhiều phòng cùng lúc** (mã và chế độ theo từng phòng), bỏ ESP32 + bridge Python (cần `sudo bash tools/setup_db.sh`). |
| 12/10/2026 | KẾT THÚC gửi điểm riêng tới từng Student Tag đã đăng nhập; Privacy vẫn chỉ ẩn dữ liệu trên bảng giáo viên. |
| 13/10/2026 | Dọn code (bỏ hàm, CSS, bản dịch không còn dùng; không đổi chức năng, ảnh chụp mọi trang giống hệt trước); gộp 12 file tài liệu thành file này. |
