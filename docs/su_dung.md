# Hướng dẫn sử dụng Smart Classroom Clicker

Giảng viên nhập danh sách lớp và bộ đề, tạo phòng kiểm tra, điều khiển từng câu trên web; sinh viên đăng nhập bằng MSSV và trả lời bằng thiết bị (tag nRF52840, ESP32 hoặc điện thoại). Tổng quan dự án: [project.md](project.md).

## 1. Mở web và đăng nhập

| Người dùng | Địa chỉ |
|---|---|
| Giảng viên | <http://clicker.local/clicker/> (trên chính laptop server cũng mở được <http://localhost/clicker/>) |
| Sinh viên dùng điện thoại | Liên kết trong mục **Cách sinh viên tham gia** của trang giáo viên, dạng `http://<IP-laptop>/clicker/phone.html` |

- Mỗi giáo viên bấm **Đăng ký**, tự chọn tên đăng nhập và mật khẩu đúng **8 chữ số**. Sau đó dùng chính tên và mật khẩu này để đăng nhập. Mỗi người chỉ thấy lớp, bộ đề, phiên và kết quả của mình. Sinh viên không cần tài khoản giáo viên, chỉ nhập MSSV khi phiên mở đăng nhập.
- Chủ máy dùng tài khoản `Pham Van De` trên chính laptop qua <http://clicker.local/clicker/> hoặc <http://localhost/clicker/>; xem mật khẩu trong `.local/host-login.txt`. Dữ liệu cũ thuộc tài khoản này. Tài khoản chủ không đăng nhập qua link Internet; mật khẩu chung trước đây không còn dùng.
- Sau 8 lần thử đăng nhập trong 15 phút từ cùng IP, chờ hết khoảng giới hạn. Phiên giáo viên hết hạn sau 60 phút không thao tác hoặc 8 giờ từ lúc đăng nhập; khi hết hạn, đăng nhập lại.
- Laptop, điện thoại và thiết bị phải ở **cùng một mạng Wi-Fi**. Không nhập `localhost` trên điện thoại (địa chỉ đó trỏ về chính điện thoại).
- 40 thiết bị trở lên cần router/access point thật; hotspot điện thoại thường chỉ cho khoảng 5–10 máy kết nối.

## 1b. Dùng từ xa (gửi cho người khác)

Web chạy trên laptop của bạn và được mở ra internet qua **Tailscale Funnel** (miễn phí), link cố định:

| Ai | Link |
|---|---|
| Giảng viên ở xa | <https://<link-cong-khai>/clicker/> |
| Điện thoại sinh viên ở xa | <https://<link-cong-khai>/clicker/phone.html> |

Chỉ thư mục `/clicker` được mở; các trang khác trên máy không ra internet. Mỗi giáo viên tự giữ tài khoản của mình; không gửi mật khẩu chung với link.

Khi dùng từ xa, chỉ mở link `https://` ở trên. Nếu điện thoại từng dùng link cũ, tải lại trang điện thoại để nhận cookie thiết bị mới.

Điều kiện: laptop **bật, không ngủ, đã đăng nhập vào tài khoản** (service `tailscaled` chạy theo tài khoản người dùng), có internet. Mở qua link từ xa thì tab **Cấu hình Wi-Fi** tự ẩn vì không dùng được từ xa.

**Hướng dẫn gửi người dùng ở xa (ESP32 đã nạp sẵn firmware):**

1. Bật hotspot điện thoại **2,4 GHz**, laptop vào hotspot đó.
2. Giữ **BACK 5 giây** trên các ESP32 → OLED hiện `Clicker-xxxx`.
3. Dùng **laptop** (không dùng điện thoại đang phát hotspot) vào Wi-Fi `Clicker-xxxx` → mở `http://192.168.4.1` → chọn hotspot, nhập mật khẩu (ô link server đã điền sẵn) → **Lưu và kết nối** → chờ báo thành công.
4. Laptop quay lại hotspot → mở link giảng viên → đăng nhập → nhập danh sách lớp và bộ đề (mục 2–4) → tạo phòng, làm bài (mục 6).

## 2. File danh sách sinh viên

File `.csv` hoặc `.xlsx`, hàng đầu là tên cột, gồm **đúng bốn cột**:

| STT | MSSV | Họ tên | Lớp |
|---:|---|---|---|
| 1 | 23146215 | Nguyễn Văn A | 12A1 |
| 2 | 23146216 | Trần Văn B | 12A1 |
| 3 | 00146217 | Lê Văn C | 12A1 |

File mẫu: [examples/students.csv](../examples/students.csv) (trên web: **Tải danh sách mẫu**).

Quy tắc:

- `STT` là số nguyên dương. `MSSV` chỉ gồm chữ số, tối đa 20 chữ số, không trùng trong cùng file.
- `Họ tên` và `Lớp` không để trống. Một file có thể chứa nhiều lớp; khi tạo phòng chỉ chọn lớp cần dùng.
- Trong Excel, **định dạng cột MSSV là Text trước khi nhập số**, nếu không Excel sẽ xóa số `0` ở đầu và web không khôi phục được.
- CSV lưu bằng **UTF-8** để giữ dấu tiếng Việt. File tối đa 1 MB, tối đa 1.000 sinh viên mỗi lần nhập.
- Nhập lại một MSSV đã có sẽ cập nhật tên/STT/lớp trong danh sách chung; phòng **đã tạo** vẫn giữ danh sách cũ của nó.

## 3. File câu hỏi và đáp án

File `.txt`, lưu bằng **UTF-8**, có đúng hai phần theo thứ tự `[QUESTIONS]` rồi `[ANSWER_KEY]`:

```text
[QUESTIONS]

Q1
Điện áp có đơn vị là gì?
A. Volt
B. Ampere
C. Ohm
D. Watt

Q2
Điện trở có đơn vị là gì?
A. Volt
B. Ampere
C. Ohm
D. Watt

Q3
Dòng điện có đơn vị là gì?
A. Watt
B. Ampere
C. Volt
D. Ohm

[ANSWER_KEY]
Q1=A
Q2=C
Q3=B
```

File mẫu: [examples/exam.txt](../examples/exam.txt).

Quy tắc để file được chấp nhận:

1. Mỗi câu bắt đầu bằng dòng `Q1`, `Q2`, `Q3`… Mã câu là số nguyên dương, không trùng. Từ **1 đến 200 câu**.
2. Sau dòng `Qn` là nội dung câu hỏi, rồi đủ bốn dòng bắt đầu bằng `A.`, `B.`, `C.`, `D.`. Không để trống câu hỏi hay lựa chọn.
3. Trong `[ANSWER_KEY]`, **mỗi** câu có đúng một dòng `Qn=A`, `Qn=B`, `Qn=C` hoặc `Qn=D` (viết hoa).
4. Không có đáp án cho câu không tồn tại, không lặp mã câu hay nhãn lựa chọn. Nên đánh số liên tiếp từ Q1 và xếp A → D.
5. File tối đa 500 KB. File lỗi thì web báo lỗi và **không lưu nửa bộ đề**.

**Điểm mỗi câu nhập trên web khi import**, không ghi trong file: lớn hơn 0, tối đa `999.99`, tối đa hai chữ số thập phân; áp dụng cho mọi câu của bộ đề. Ví dụ `0.5` điểm/câu thì 20 câu đúng được 10 điểm.

Ví dụ **sai** (có Q1 và Q2 nhưng thiếu đáp án Q2):

```text
[ANSWER_KEY]
Q1=A
```

Muốn sửa đề đã nhập: sửa file rồi **nhập lại thành bộ đề mới**, chọn bộ đề mới khi tạo phòng tiếp theo.

## 4. Nhập dữ liệu lên web

Vào **Chuẩn bị dữ liệu**:

1. Thẻ **Danh sách sinh viên**: chọn file CSV/Excel → **Nhập sinh viên**.
2. Thẻ **Ngân hàng câu hỏi**: điền tên bộ đề, chọn file `.txt`, nhập **Điểm mỗi câu** → **Nhập bộ đề**.

Web báo số sinh viên/số câu đã nhập.

## 5. Cấp Wi-Fi cho ESP32 (chỉ bản thử ESP32)

ESP32 có hai cách nhận Wi-Fi. Chọn theo vị trí của máy chạy web (server):

| Server ở đâu | Thao tác trên ESP32 | Nhập mật khẩu Wi-Fi ở đâu |
|---|---|---|
| **Cùng Wi-Fi** với ESP32 (laptop chạy web ngay tại lớp) | Giữ **OK 5 giây** | Tab **Cấu hình Wi-Fi** trên web — một lần cho mọi board |
| **Ở xa** (mở web qua link internet) | Giữ **BACK 5 giây** | Wi-Fi cài đặt `Clicker-xxxx` do chính ESP32 phát — cài một board, các board khác tự nhận |

Lần nhấn đầu của thao tác giữ vẫn được tính là một lần OK/BACK bình thường (ví dụ xóa một số MSSV đang nhập). Board chỉ đổi chế độ khi bạn giữ nút: ở chế độ cài đặt, board **đợi tới khi vào được Wi-Fi mới thoát**; muốn hủy thì **giữ lại đúng nút đó 5 giây**, board quay về Wi-Fi đã lưu. Khi không vào được Wi-Fi đã lưu, board chỉ hiện `Connecting Wi-Fi...` và tự thử lại, không tự nhảy sang chế độ khác.

### 5a. Server cùng Wi-Fi: giữ OK 5 giây

1. Laptop vào hotspot/router **2,4 GHz** mà ESP32 sẽ dùng.
2. Bật ESP32. Board mới (chưa có Wi-Fi) tự vào chế độ này; các lần sau giữ nút **OK 5 giây**. OLED hiện `SETUP (web ESPTouch)`; giữ OK 5 giây lần nữa để hủy.
3. Trên web, mở **Cấu hình Wi-Fi**. Web tự điền tên Wi-Fi và IP laptop; chỉ cần nhập **mật khẩu Wi-Fi** → **Gửi cấu hình**. Một lần gửi (khoảng 30 giây) cấp cho mọi ESP32 đang chờ.
4. Web liệt kê các ESP32 đã nhận; OLED hiện `Wi-Fi ready`. ESP32 tự tìm lại laptop qua tên `clicker.local` khi IP laptop đổi.

Nếu web báo chưa đọc đủ thông tin mạng, mục **Nâng cao** tự mở để nhập BSSID (`iwgetid -a -r`) và IP laptop (`ip -4 addr`).

### 5b. Server ở xa: giữ BACK 5 giây

1. Bật hotspot/Wi-Fi **2,4 GHz** mà ESP32 sẽ dùng (ví dụ hotspot 4G điện thoại) và **giữ nó bật** suốt lúc cài.
2. Giữ **BACK 5 giây** trên **tất cả** các board cần cài. OLED hiện `SETUP WI-FI` và tên Wi-Fi `Clicker-xxxx`.
3. Dùng **laptop** (không dùng điện thoại đang phát hotspot) vào Wi-Fi `Clicker-xxxx` của **một** board bất kỳ. Trang cài đặt tự mở; nếu không, gõ `192.168.4.1`.
4. Chọn hotspot trong danh sách (hoặc gõ tên), nhập mật khẩu, kiểm tra ô **Link server** (điền sẵn link được giao) → **Lưu và kết nối**.
5. Trang báo kết quả trong khoảng 20 giây: vào được Wi-Fi chưa, gọi được server chưa. Sai mật khẩu thì sửa và lưu lại.
6. Thành công thì board đó hiện `Sharing to other boards (20 s)` và **tự gửi Wi-Fi cho các board còn lại** đang ở bước 2. Sau đó mọi board vào mạng.
7. Laptop quay lại hotspot, mở link web và dùng bình thường.

Giữ BACK 5 giây lần nữa để hủy chế độ cài đặt. Chỉ các board nạp **cùng bản firmware** (cùng khóa thiết bị) mới nhận Wi-Fi của nhau; nội dung chia sẻ được mã hóa.

### Nạp firmware

Mở `firmware/SmartClickerESP32/SmartClickerESP32.ino` bằng `sh tools/open_arduino_ide.sh`, chọn **ESP32 Dev Module** và cổng `/dev/ttyUSB0`, bấm **Upload**.

## 6. Một buổi kiểm tra

1. **Tạo phòng** (tab **Phòng kiểm tra**): điền tên buổi, chọn lớp, bộ đề và số giây mỗi câu → **Tạo session**. Mỗi phòng giữ bản chụp danh sách lớp riêng. Tạo phòng mới sẽ tự kết thúc phòng đang chạy (nếu phòng đó đang chạy AUTO thì phải dừng AUTO trước).
2. **Mở đăng nhập**: sinh viên nhập MSSV trên thiết bị. Thẻ **Sinh viên trong phòng** hiện người đã vào (**xanh**, kèm loại thiết bị ESP32 / nRF / Điện thoại) và chưa vào (**đỏ**).
3. Tab **Câu hỏi trực tiếp**, chọn một trong hai cách:
   - **Bắt đầu**: đóng đăng nhập và mở câu đầu tiên chưa làm. Bấm **NEXT** để sang câu kế tiếp.
   - **AUTO**: đóng đăng nhập, mỗi câu mở theo số giây đã nhập rồi tự sang câu sau; hết câu cuối tự kết thúc buổi. Trong lúc AUTO chạy, các nút tay bị khóa; bấm **AUTO** lần nữa để dừng (câu đang mở vẫn mở).
4. **KẾT THÚC**: đóng câu đang mở và kết thúc buổi.

Lưu ý:

- Sau khi bấm Bắt đầu/AUTO, sinh viên đến muộn **không đăng nhập được** nữa.
- Màn hình câu hỏi trực tiếp **không bao giờ hiện đáp án đúng**, an toàn khi chiếu lên máy chiếu (**Chế độ trình chiếu**). Nút 👁 ẩn/hiện thẻ Thống kê trực tiếp.
- Ô chọn câu và **PREVIOUS** chỉ để xem lại khi không có câu đang mở; không mở lại câu đã làm.
- Đáp án gửi trong vòng 1,5 giây sau khi câu đóng vẫn được tính.
- **Kiểm tra lại**: chọn một phòng đã kết thúc → **Kiểm tra lại · mở đăng nhập**. Hệ thống tạo lượt mới cùng lớp, bộ đề và số giây mỗi câu (tên thêm `· lần N`), làm lại từ Q1. Kết quả lượt trước vẫn xem được theo quy tắc mới: số câu đúng / tổng số câu đề.

## 7. Sinh viên trả lời

| Thiết bị | Đăng nhập | Trả lời |
|---|---|---|
| Điện thoại | Bấm `0–9` nhập MSSV → **CONFIRM** (**Xóa MSSV** nếu nhập sai) | `1=A`, `2=B`, `3=C`, `4=D` → **CONFIRM**, chờ **ĐÃ LƯU** |
| ESP32 (đang đấu 3 nút: `1`, BACK, OK) | Nhập MSSV, BACK xóa, OK gửi. Với 3 nút chỉ nhập được số `1` (MSSV thử `111111111`) | `1` chọn A, OK gửi; màn hình hiện `Sending A...` rồi `SAVED` |
| Tag nRF52840 | Nhập MSSV (LED hiện 2 số cuối), OK gửi (rung nhẹ); `Hi` là thành công | `1–4` chọn A–D (LED ` C`), OK gửi (rung nhẹ); `C=` là đã lưu |

- Có thể đổi lựa chọn **trước khi gửi**. Đáp án đã lưu thì không đổi được.
- Chưa có xác nhận thì thiết bị tự gửi lại; không lo bị tính hai lần.
- Mỗi trình duyệt điện thoại là một thiết bị (`PHONE-…`); hai tab cùng trình duyệt dùng chung. Xóa cookie có thể đổi mã thiết bị.

**Mã hiển thị trên tag nRF (2 LED 7 đoạn):**

Hiện tag chạy chế độ **1 đèn** (`ONE_DIGIT = true` trong `ClickerTag.ino`): chỉ đèn bên trái sáng. Mã 1 ký tự (câu 1–9, đáp án đã chọn) đứng yên; mã 2 ký tự hiện lần lượt từng ký tự, mỗi vòng 1 s (ví dụ `L` → `o`, `1` → `2`).

| LED | Ý nghĩa |
|---|---|
| `88` rồi `--` | Khởi động: sáng hết để thử đèn, rồi chờ gateway |
| `nC` / `nS` | Mất sóng gateway quá 5 s / gateway không tới được server |
| `OF` | Web đang ở chế độ Wi-Fi, tag tạm dừng |
| `--` / `Lo` / `rd` | Chưa có buổi / đang mở đăng nhập / đã đăng nhập, chờ câu |
| ` 7` / `12` | Câu đang mở, chưa chọn; câu dưới 10 tắt số hàng chục (rung nhẹ 1 cái khi câu mới tới) |
| ` C` / `C=` | Đã chọn C chưa gửi (nhấp nháy khi đang gửi) / server đã lưu C |
| `En` | Buổi đã kết thúc |
| `E1`–`E9` | Lỗi: E1 đã đóng đăng nhập, E2 MSSV không thuộc lớp, E3 MSSV đã dùng ở thiết bị khác, E4 tag đã gắn MSSV khác, E5 mất đăng nhập, E6 câu đã đóng, E7 đáp án đã lưu khác, E9 khóa thiết bị của bridge sai |

Chế độ Wi-Fi/BLE đổi bằng công tắc ở góc trên web (không đổi được khi đang mở đăng nhập hoặc có câu đang mở).

## 8. Kết quả, phân tích, xuất file

- **Phân tích câu hỏi**: chọn từng câu để xem phân bố A/B/C/D, đúng/sai, tỷ lệ đúng; câu sai nhiều nhất được đánh dấu, **Sắp xếp: sai nhiều** đưa câu khó lên đầu. Đáp án đúng chỉ hiện khi bấm **XEM ĐÁP ÁN**.
- **Bảng kết quả**: cả lớp theo STT, người chưa tham gia tô đỏ.
  - **Privacy bật sẵn**: MSSV, họ tên hiện `********`, đáp án hiện `*`. Bấm tiêu đề cột **MSSV**, **Họ tên** hoặc từng cột **Q** (○ → ◉) để hiện riêng cột đó; tắt **Privacy** để hiện tất cả.
  - Mỗi câu một cột; câu đang mở ghi `Q… · đang mở` và cập nhật trực tiếp.
  - Điểm tự tính theo các câu đã đóng, không cần bấm chấm. Sau khi **KẾT THÚC**, bấm **Xuất CSV** hoặc **Xuất Excel** (file xuất luôn chứa dữ liệu thật).
- Chỉ tính các câu đã mở và đã đóng. Câu không trả lời được 0 điểm và tính vào cột “Chưa trả lời”.

**Xóa dữ liệu:** nút `×` cạnh ô chọn chỉ bỏ lựa chọn. Nút **Xóa** (lớp, sinh viên, bộ đề, phòng) cần bấm **XÁC NHẬN XÓA** và xóa vĩnh viễn cả kết quả liên quan: xóa lớp/bộ đề sẽ xóa các phòng dùng chúng. Xuất kết quả cần giữ trước khi xóa.

## 9. Gặp vấn đề

| Hiện tượng | Cách xử lý |
|---|---|
| Không mở được `clicker.local` | Kiểm tra laptop đang vào Wi-Fi; một số máy Android cũ không hiểu tên `.local`, dùng IP laptop |
| Web vẫn giao diện cũ | Tải lại trang (Ctrl+Shift+R) |
| Điện thoại hiện “Chờ mở đăng nhập” | Giảng viên chọn đúng phòng và bấm **Mở đăng nhập** |
| MSSV bị từ chối | Kiểm tra MSSV có trong đúng lớp, còn số `0` đầu, chưa dùng ở thiết bị khác trong buổi |
| Sinh viên đến muộn không vào được | Đăng nhập đã đóng khi bấm Bắt đầu/AUTO |
| Không tạo được phòng mới | Phòng hiện tại đang chạy AUTO: bấm AUTO để dừng rồi tạo lại |
| Nhiều thiết bị không vào được mạng | Hotspot điện thoại giới hạn số máy; dùng router/access point |
| ESP32 hiện `Server offline` | Sau vài giây board tự tìm server: `clicker.local` (cùng Wi-Fi với laptop), rồi link công khai `https://<link-cong-khai>` (Wi-Fi có internet); tìm được thì OLED báo `Server found`. Vẫn offline: laptop đang tắt/ngủ, hoặc Wi-Fi không có internet |
| Trang `Clicker-xxxx` báo không vào được Wi-Fi | Sai mật khẩu, hotspot tắt hoặc là mạng 5 GHz; sửa rồi lưu lại |
| Board khác không nhận Wi-Fi được chia sẻ | Board đó phải đang hiện `SETUP WI-FI` (giữ BACK 5 giây) trước khi bấm Lưu, và nạp cùng bản firmware |
| Tag hiện `nC` | Bridge BLE chưa chạy: `systemctl --user start clicker-ble-bridge` |
| File Excel mất số `0` của MSSV | Định dạng cột MSSV là Text, nhập lại số đầy đủ |
| Điểm bằng 0 | Câu phải đã được đóng (NEXT/KẾT THÚC) và thiết bị đã hiện đã lưu |

## 10. Mở bản thiết kế vỏ (Student Tag V3 và gateway BLE)

| Bản | File gốc | File in (STL) | File CAD (STEP) |
|---|---|---|---|
| Tag V3 (board Student Remote 50×75) | `hardware/case/tag/student_tag_case_v3.scad` | `hardware/case/tag/stl/` | `hardware/case/tag/step/` |
| Gateway BLE | `hardware/case/gateway/gateway_case.scad` | `hardware/case/gateway/stl/` | `hardware/case/gateway/step/` |

- Sửa thiết kế: mở file `.scad` bằng OpenSCAD (`openscad hardware/case/tag/student_tag_case_v3.scad`), F5 xem, F6 dựng khối.
- Gửi đi in: `case_v3/v3/stl.zip` (16 file STL + `HUONG_DAN_IN.md`), hoặc cả thư mục `case_v3/v3/`.
- Mở trên SolidWorks: các file `.step` trong `case_v3/v3/step/`, chèn vào Assembly tại gốc tọa độ là khớp.
- Sửa `.scad` xong thì kiểm tra rồi xuất lại: `/usr/bin/python3 tests/case_test.py v3` rồi `/usr/bin/python3 "image/Student Remote/case_v3/export_v3.py"`.

### Vỏ gateway BLE

- Dáng viên sỏi 44,2 × 21,6 mm cho 1 con nRF52840 SuperMini đã hàn chân header (chân chĩa xuống); dày 17,2 mm với chân
  dài 8,5 mm, 12,7 mm nếu cắt chân còn 4 mm. Mặt trên: chấm sáng đèn báo, lỗ chọc reset, chữ CLICKER, biểu tượng sóng;
  đuôi có lỗ dây đeo Ø3,2; 2 nửa ghép bằng 4 lẫy, không vít.
- Cắm vào laptop cần thêm đầu chuyển USB-C đực–đực thẳng (có truyền dữ liệu), hoặc C cái – A đực nếu laptop chỉ có USB-A.
- Đã xuất STL/STEP (05/10/2026) với số tạm: `pins_below` 8,5 mm, đèn `led_at` (5,5; −6,6), reset `reset_at` (10; 5,6).
  Đo khác thì sửa `.scad`, chạy kiểm tra rồi `/usr/bin/python3 "image/Student Remote/Gateway/export_gateway.py"`.
- Kiểm tra: `/usr/bin/python3 tests/gateway_case_test.py` (11 kiểm tra: không chạm module/header/USB-C, phích cắm vào hết,
  lẫy giữ, module không xê dịch).
