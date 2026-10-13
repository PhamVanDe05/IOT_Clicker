# Hướng dẫn sử dụng Classroom Clicker

Giáo viên nhập lớp và bộ đề trên web, mở phòng, điều khiển từng câu. Sinh viên đăng nhập bằng MSSV và bấm đáp án trên **tag**. Tag nói chuyện với **gateway** cắm USB laptop; web lưu mọi thứ vào database trên laptop.

## 1. Các link

| Dùng để | Link |
|---|---|
| Web giáo viên, trên laptop chạy server | <http://localhost/clicker/> |
| Web giáo viên, từ máy khác (qua Internet) | `https://<link-cong-khai>/clicker/` |
| Điện thoại sinh viên (bản thử) | `…/clicker/phone.html` (link hiện trên trang giáo viên) |
| Mã nguồn | <https://github.com/PhamVanDe05/IOT_Clicker> |

- Dùng **Chrome hoặc Edge** trên máy tính. Gateway USB chỉ chạy trên `localhost` hoặc link `https://`; `http://clicker.local` không dùng được USB.
- Link Internet chỉ chạy khi laptop server đang bật, không ngủ, có mạng.
- Mỗi giáo viên tự **Đăng ký**: tên đăng nhập và mật khẩu đúng 8 chữ số. Mỗi người chỉ thấy dữ liệu của mình.

## 2. Luồng hoạt động

```text
Tag ──BLE──▶ Gateway ──USB──▶ Web (Chrome) ──HTTP──▶ api.php ──▶ MariaDB
    ◀──BLE──         ◀──USB──              ◀──HTTP──
```

1. Tag bấm OK thì phát gói qua BLE, phát lại liên tục tới khi nhận **ACK**.
2. Gateway nghe gói, gửi lên web qua USB.
3. Web gọi server; server **lưu database xong mới trả ACK**. Gói trùng không bị ghi 2 lần.
4. Web gửi ACK xuống gateway, gateway phát cho cả lớp, tag thấy đúng MAC của mình thì dừng.
5. Mỗi 0,5 s web hỏi server trạng thái phòng (mở đăng nhập, câu mấy, kết thúc) và phát xuống mọi tag.

Định dạng từng byte của gói: `firmware/ClickerTag/protocol.h`.

## 3. Chuẩn bị (làm 1 lần)

**Danh sách lớp** `.csv` hoặc `.xlsx`, 4 cột:

| STT | MSSV | Họ tên | Lớp |
|---:|---|---|---|
| 1 | 23146215 | Nguyễn Văn A | 12A1 |

MSSV chỉ gồm chữ số. Trong Excel, đặt cột MSSV là **Text** trước khi nhập để giữ số `0` đầu. CSV lưu UTF-8. Mẫu: `examples/students.csv`.

**Bộ đề** `.txt`, UTF-8:

```text
[QUESTIONS]
Q1
Điện áp có đơn vị là gì?
A. Volt
B. Ampere
C. Ohm
D. Watt

[ANSWER_KEY]
Q1=A
```

Mỗi câu đủ 4 lựa chọn A–D, mỗi câu có đúng 1 dòng đáp án. Tối đa 200 câu. Mẫu: `examples/exam.txt`.

Trên web: **Chuẩn bị dữ liệu** → nhập danh sách lớp, nhập bộ đề.

## 4. Một buổi kiểm tra

1. Cắm **gateway** vào laptop, mở web giáo viên, chọn chế độ **BLE**. Lần đầu bấm **Kết nối Gateway** và chọn cổng gateway; những lần sau cắm vào là web tự kết nối. Web phải hiện **Đã kết nối BRIDGE-…**. Giữ tab này mở suốt buổi.
2. **Tạo phòng**: tên buổi, lớp, bộ đề, số giây mỗi câu.
3. **Mở đăng nhập**: tag hiện `Lo`. Sinh viên gõ MSSV, bấm **OK**, chờ `Hi`.
4. **Bắt đầu** (bấm NEXT để sang câu) hoặc **AUTO** (tự sang câu theo số giây). Tag rung nhẹ và hiện số câu.
5. Sinh viên bấm `1`=A, `2`=B, `3`=C, `4`=D, rồi **OK**. Thấy `A=` là đã lưu.
6. **KẾT THÚC** (hoặc hết giờ câu cuối ở AUTO): tag hiện điểm 5 giây, `En` 3 giây rồi tắt đèn.
7. Xem **Bảng kết quả**, **Phân tích câu hỏi**; **Xuất CSV/Excel**.

Lưu ý: bấm Bắt đầu/AUTO thì đăng nhập đóng, người đến muộn không vào được. Màn hình câu hỏi không bao giờ hiện đáp án đúng, chiếu lên máy chiếu được.

## 5. Bảng mã đèn trên tag

2 đèn: trái là hàng chục, phải là hàng đơn vị. Mã 1 ký tự chỉ sáng **đèn phải**. `B`, `D` hiện thành `b`, `d`.

| Đèn | Nghĩa | Làm gì |
|---|---|---|
| Nét chạy vòng quanh 2 đèn (khoảng 5 s) | Vừa bật nguồn | Chờ |
| `--` | Chưa nghe gateway, hoặc chưa có phòng | Giáo viên kết nối gateway, tạo phòng |
| `Lo` | Đang mở đăng nhập | Gõ MSSV |
| `17` | 2 số cuối MSSV đang gõ | BACK xóa, OK gửi |
| `17` nhấp nháy | Đang gửi MSSV | Chờ |
| `Hi` | Đăng nhập thành công | Chờ câu hỏi |
| `LC` | Chưa đăng nhập mà đăng nhập đã đóng | Báo giáo viên |
| `rd` | Đã đăng nhập, chờ câu | Chờ |
| ` 3`, `12` | Câu đang mở (rung nhẹ khi câu mới tới) | Bấm `1`–`4` |
| ` b` | Đã chọn B, **chưa gửi** | Đổi bằng `1`–`4`, BACK bỏ chọn, OK gửi |
| ` b` nhấp nháy | Đang gửi (rung nhẹ lúc bấm OK) | Chờ |
| `b=` | **Server đã lưu B** | Xong câu này |
| Điểm, ví dụ `97` | Bài kết thúc, điểm thang 10, hiện 5 giây | Xem điểm |
| `En` | Kết thúc buổi, 3 giây rồi tắt đèn | Bấm phím để bật lại |
| `nC` | Mất sóng gateway quá 5 giây | Lại gần, kiểm tra gateway |
| `nS` | Gateway không tới được server (tab web đóng, mất mạng) | Giáo viên mở lại web, kết nối gateway |
| `OF` | Web chưa chọn chế độ BLE | Giáo viên chọn BLE trên web |
| Đèn tắt | 15 giây không bấm phím (tiết kiệm pin) | Bấm phím bất kỳ; lần bấm này không tính |

**Cách đọc điểm:** điểm nguyên hiện đúng số (`10` → `10`, `5` → ` 5`). Điểm lẻ lấy 2 chữ số đầu, cắt bỏ phần sau, không làm tròn: `9.75` → `97`, `7.5` → `75`, `0.5` → `05`. Điểm = số câu đúng / tổng số câu × 10.

**Rung nhẹ** chỉ khi bấm OK gửi đi và khi có câu mới. Rung không có nghĩa là đã lưu; phải thấy `Hi` hoặc `b=`.

## 6. Bảng mã lỗi trên tag

`E` + số hiện 1,5 giây rồi quay lại màn hình trước. Tag không rung khi báo lỗi.

| Đèn | Nghĩa | Cách xử lý |
|---|---|---|
| `E1` | Đăng nhập đã đóng hoặc chưa mở | Giáo viên mở đăng nhập |
| `E2` | MSSV không có trong lớp (gõ sai) | BACK sửa, OK gửi lại |
| `E3` | MSSV đang dùng ở tag khác | Dùng tag đã đăng nhập |
| `E4` | Tag này đã gắn với MSSV khác | Dùng đúng tag của mình |
| `E5` | Mất đăng nhập | Đăng nhập lại khi phòng mở |
| `E6` | Câu đã đóng, gửi trễ | Trả lời câu đang mở |
| `E7` | Câu này đã lưu đáp án khác | Không đổi được |
| `E8` | Server từ chối (phòng đã kết thúc, phiên giáo viên lỗi…) | Giáo viên kiểm tra web |
| `E9` | Lỗi gateway | Kết nối lại gateway |

## 7. Thông báo gateway trên web

| Thông báo | Làm gì |
|---|---|
| `Đã kết nối BRIDGE-…` | Ổn |
| `Web Serial cần HTTPS…` | Mở bằng `localhost` hoặc link `https://` |
| `Không mở được cổng USB…` | Đóng Serial Monitor của Arduino và tab khác đang giữ gateway |
| `Cổng USB không trả lời như Gateway Clicker.` | Chọn nhầm cổng tag; chọn cổng gateway |
| `Gateway nối USB; server không phản hồi…` | Kiểm tra mạng, đăng nhập lại |
| `Mất kết nối Gateway…` | Cắm lại cáp; web tự kết nối lại |

## 8. Gặp vấn đề

| Hiện tượng | Cách xử lý |
|---|---|
| Web vẫn giao diện cũ | Ctrl+Shift+R |
| MSSV bị từ chối | Kiểm tra đúng lớp, còn số `0` đầu |
| Không tạo được phòng mới | Đang chạy AUTO: bấm AUTO để dừng |
| Tag nhấp nháy mãi không ra `=` | Chưa nhận ACK: kiểm tra tab web còn mở, gateway đã kết nối |
| Điểm bằng 0 | Câu phải được đóng (NEXT/KẾT THÚC) và tag đã hiện `=` |
| 2 board cùng cắm, chọn nhầm cổng | Gateway có serial đuôi `…722E`, tag đuôi `…0B52` |

## 9. Firmware và phần cứng

| Phần | File | Nạp vào |
|---|---|---|
| Tag | `firmware/ClickerTag/ClickerTag.ino` (kèm `config.h`, `protocol.h`, `tag_logic.h`) | Board tag |
| Gateway | `firmware/ClickerGateway/ClickerGateway.ino` | Board gateway |

Arduino IDE, core **Adafruit nRF52**, board **Adafruit ItsyBitsy nRF52840 Express**. Chỉnh thời gian rung, thời gian hiện điểm, tắt đèn… trong `firmware/ClickerTag/config.h`.

PCB: `hardware/pcb/` (KiCad, Gerber, BOM). Vỏ in 3D: `hardware/case/tag/` và `hardware/case/gateway/`: file gốc OpenSCAD `.scad` và STL để in.
