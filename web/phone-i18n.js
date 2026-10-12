/* English and Chinese for the student page (phone.html + phone.js). Runs on landing/i18n.js: phone.js writes
   Vietnamese and every inserted text is translated by the observer at the bottom. Rows: [Vietnamese, English, Chinese]. */
'use strict';
I18N.add([
  ['Clicker · Vào lớp học', 'Clicker · Join a class', 'Clicker · 进入课堂'],
  ['Đang kết nối…', 'Connecting…', '正在连接…'], ['Mạng chậm, đang kết nối lại…', 'Slow network, reconnecting…', '网络较慢，正在重新连接…'],
  ['Vào lớp học', 'Join a class', '进入课堂'], ['Nhập mã phòng 6 số giảng viên đang chiếu.', 'Enter the 6-digit room code on the teacher’s screen.', '输入老师屏幕上的 6 位房间码。'],
  ['Mã phòng', 'Room code', '房间码'], ['Vào phòng', 'Join room', '进入房间'],
  ['Thông tin của bạn', 'Your details', '您的信息'], ['MSSV', 'Student ID', '学号'], ['Họ và tên', 'Full name', '姓名'], ['Lớp', 'Class', '班级'],
  ['Ví dụ: 23146101', 'e.g. 23146101', '例如：23146101'], ['Ví dụ: Nguyễn Văn An', 'e.g. Nguyen Van An', '例如：阮文安'],
  ['Đã có trong danh sách lớp thì chỉ cần MSSV. Chưa có thì điền thêm họ tên, giảng viên duyệt là vào được.', 'If you are on the class list, your student ID is enough. If not, add your name and the teacher will approve you.', '如果已在班级名单中，只需学号；否则请填写姓名，老师批准后即可进入。'],
  ['Vào lớp', 'Join', '进入'], ['Đáp án', 'Answers', '答案'], ['Gửi đáp án', 'Send answer', '提交答案'],
  ['Đợi giảng viên', 'Waiting for the teacher', '等待老师'], ['Đợi giảng viên duyệt', 'Waiting for the teacher’s approval', '等待老师批准'],
  ['Đang vào lớp…', 'Joining…', '正在进入…'], ['Đã vào lớp', 'You’re in', '已进入课堂'], ['Chờ giảng viên bắt đầu câu hỏi…', 'Waiting for the teacher to start…', '等待老师开始答题…'],
  ['Chờ câu hỏi tiếp theo…', 'Waiting for the next question…', '等待下一题…'], ['Giữ trang này mở.', 'Keep this page open.', '请保持此页面打开。'],
  ['Chờ giảng viên mở phòng', 'Waiting for the teacher to open the room', '等待老师开放房间'], ['Phòng chưa sẵn sàng.', 'The room is not ready yet.', '房间尚未就绪。'],
  ['Chờ giảng viên mở đăng nhập', 'Waiting for the teacher to open sign-in', '等待老师开放登录'], ['Phòng đã tạo, sắp mở đăng nhập.', 'The room is created; sign-in opens soon.', '房间已创建，即将开放登录。'],
  ['Buổi học đang diễn ra, đăng nhập đã đóng. Hãy báo giảng viên.', 'The quiz has started and sign-in is closed. Please tell your teacher.', '测验已开始，登录已关闭，请告知老师。'],
  ['Buổi học đã kết thúc', 'The class has ended', '课堂已结束'], ['Bài kiểm tra đã kết thúc', 'The quiz has ended', '测验已结束'], ['Bạn đã làm xong', 'You’re done', '您已完成'],
  ['Cảm ơn bạn đã tham gia. Giảng viên sẽ thông báo kết quả.', 'Thanks for taking part. Your teacher will share the results.', '感谢参与，老师会公布结果。'],
  ['Mã phòng gồm 6 chữ số.', 'The room code has 6 digits.', '房间码为 6 位数字。'], ['Mã phòng không đúng hoặc phòng chưa mở.', 'Wrong code, or the room isn’t open yet.', '房间码错误或房间尚未开放。'],
  ['Bạn nhập sai nhiều lần, chờ một phút rồi thử lại.', 'Too many tries. Wait a minute and try again.', '尝试次数过多，请一分钟后再试。'],
  ['Phòng đã đổi. Hãy nhập mã phòng mới.', 'The room changed. Enter the new room code.', '房间已更换，请输入新房间码。'], ['MSSV chỉ gồm chữ số.', 'The student ID has digits only.', '学号只能是数字。'],
  ['Bạn chưa có trong danh sách lớp. Điền họ và tên để xin vào lớp.', 'You are not on the class list. Enter your full name to ask to join.', '您不在班级名单中，请填写姓名申请加入。'],
  ['Không cấp được mã thiết bị.', 'Could not get a device ID.', '无法获取设备编号。'], ['Server chưa xử lý được.', 'The server could not handle that.', '服务器无法处理。'],
  ['Xác nhận không khớp. Đang thử lại…', 'Confirmation mismatch. Retrying…', '确认不一致，正在重试…'],
  ['Chưa mở hoặc đã đóng đăng nhập.', 'Sign-in is not open or already closed.', '登录未开放或已关闭。'], ['MSSV đã có trong lớp, hãy đăng nhập bằng MSSV.', 'This ID is already on the list; sign in with it.', '该学号已在名单中，请直接用学号登录。'],
  ['MSSV này đang chờ duyệt trên thiết bị khác.', 'This ID is waiting for approval on another device.', '该学号正在另一台设备上等待批准。'], ['Họ tên không hợp lệ.', 'Invalid name.', '姓名无效。'],
  ['MSSV đã đăng nhập trên thiết bị khác.', 'This ID is signed in on another device.', '该学号已在另一台设备登录。'], ['Thiết bị đã gắn với MSSV khác trong buổi này.', 'This device is already used by another ID in this quiz.', '本设备在本次测验中已绑定其他学号。'],
  ['Câu hỏi chưa mở hoặc đã đóng.', 'The question is not open or already closed.', '题目未开放或已关闭。'], ['Đáp án đã lưu, không thể đổi.', 'Your answer is saved and can’t be changed.', '答案已保存，无法修改。'],
  ['Thiết bị này đã bị quản trị viên chặn.', 'This device was blocked by the administrator.', '此设备已被管理员屏蔽。'],
  // BLE model
  ['Lớp đang dùng máy bấm Student Tag (BLE).', 'This class uses Student Tag clickers (BLE).', '本班使用 Student Tag 答题器（BLE）。'],
  ['Đây là máy mô phỏng để xem cách bấm, đáp án', 'This is a practice model to show how to press; answers are', '这是演示用的模拟器，答案'], ['không', 'not', '不会'],
  ['được gửi lên lớp.', 'sent to the class.', '发送到课堂。'], ['Màn LED', 'LED display', 'LED 显示屏'], ['Chạy lại mô phỏng', 'Restart the model', '重新开始模拟'],
  ['Nhập MSSV bằng phím số rồi bấm OK', 'Type your ID on the keypad, then press OK', '用数字键输入学号后按 OK'], ['Đang gửi MSSV…', 'Sending ID…', '正在发送学号…'],
  ['Đang gửi đáp án…', 'Sending answer…', '正在发送答案…'], ['Đã vào phòng', 'Joined the room', '已进入房间'], ['Chờ câu hỏi', 'Waiting for a question', '等待题目'],
  ['Bấm OK để gửi', 'Press OK to send', '按 OK 发送'],
  ['Lớp đang dùng Student Tag (BLE). Đây là tag mô phỏng: bấm như tag thật, đáp án được gửi lên lớp.', 'This class uses Student Tags (BLE). This is a simulated tag: press it like the real one, answers are sent to the class.', '本班使用 Student Tag（BLE）。这是模拟答题器：像真机一样按键，答案会发送到课堂。'],
  ['Quản trị viên đã tắt tag mô phỏng.', 'The administrator has turned off simulated tags.', '管理员已关闭模拟答题器。'], ['Mất kết nối máy chủ', 'Server connection lost', '与服务器断开'],
  ['Chờ giảng viên mở phòng', 'Waiting for the teacher to open a room', '等待老师开放房间'], ['Đăng nhập đã đóng', 'Sign-in is closed', '登录已关闭'], ['Đã vào lớp', 'Joined the class', '已进入课堂'], ['Đã gửi đáp án', 'Answer sent', '答案已发送'], ['Kết thúc', 'Finished', '结束'],
], [
  [/^Câu (\d+)$/, 'Question $1', '第 $1 题'], [/^Câu (\d+)\/(\d+)$/, 'Question $1/$2', '第 $1/$2 题'], [/^(\d+) giây$/, '$1 s', '$1 秒'],
  [/^Còn (\d+:\d+) cho cả bài$/, '$1 left for the whole quiz', '整份测验剩余 $1'], [/^Gửi đáp án ([A-D])$/, 'Send answer $1', '提交答案 $1'],
  [/^Đang gửi đáp án ([A-D])…$/, 'Sending answer $1…', '正在提交答案 $1…'], [/^Đã gửi đáp án ([A-D]) ✓$/, 'Answer $1 sent ✓', '答案 $1 已提交 ✓'], [/^✓ Đã gửi ([A-D])$/, '✓ Sent $1', '✓ 已提交 $1'],
  [/^MSSV (\d+)$/, 'Student ID $1', '学号 $1'], [/^Lỗi E(\d)$/, 'Error E$1', '错误 E$1'], [/^(.*) · MSSV (\d+)$/, '$1 · ID $2', '$1 · 学号 $2'], [/^Lớp (.+)$/, 'Class $1', '$1 班'], [/^(.+) · MSSV (\d+) · lớp (.+)$/, '$1 · ID $2 · class $3', '$1 · 学号 $2 · $3 班'],
  [/^([\d,]+)\/10 điểm$/, '$1/10 points', '$1/10 分'], [/^Đúng (\d+)\/(\d+) câu · đã trả lời (\d+)\/(\d+)\. Câu chưa trả lời tính 0 điểm\.$/, '$1/$2 correct · $3/$4 answered. Unanswered questions score 0.', '答对 $1/$2 题 · 已答 $3/$4。未作答计 0 分。'],
  [/^Câu (\d+): chọn 1–4 \(= A–D\)$/, 'Question $1: press 1–4 (= A–D)', '第 $1 题：按 1–4（= A–D）'],
]);
document.addEventListener('DOMContentLoaded', () => {
  I18N.start();
  new MutationObserver(records => {
    if (I18N.lang === 'vi') return;
    for (const r of records) {
      if (r.type === 'characterData' && r.target.parentElement) { I18N.apply(r.target.parentElement); continue; }
      for (const n of r.addedNodes) {
        if (n.nodeType === 1) I18N.apply(n);
        else if (n.nodeType === 3 && n.parentElement) I18N.apply(n.parentElement);
      }
    }
  }).observe(document.body, { childList: true, subtree: true });
});
