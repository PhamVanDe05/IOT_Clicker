/* Vietnamese / English / Chinese for the public pages.
   Static HTML stays in Vietnamese and is translated in place by exact text match; Vietnamese is restored from the
   original text nodes. Text that JavaScript builds uses I18N.t() and is re-rendered on the 'langchange' event, and its
   containers carry data-noi18n so the walker leaves them alone. Edit translations here only. */
'use strict';
const I18N = (() => {
  const L = [
    // ---- header, announcement ----
    ['Clicker · Bảng giá', 'Clicker · Pricing', 'Clicker · 价格方案'],
    ['Clicker · Đăng nhập', 'Clicker · Sign in', 'Clicker · 登录'],
    ['Clicker · Chính sách quyền riêng tư', 'Clicker · Privacy policy', 'Clicker · 隐私政策'],
    ['Clicker · Điều khoản sử dụng', 'Clicker · Terms of use', 'Clicker · 使用条款'],
    ['Mới:', 'New:', '新功能：'],
    ['Soạn đề bằng AI đang được phát triển.', 'AI quiz authoring is in development.', 'AI 智能出题正在开发中。'],
    ['Xem trước', 'Preview', '抢先看'],
    ['Đóng thông báo', 'Close announcement', '关闭通知'],
    ['Tính năng', 'Features', '功能'],
    ['Giải pháp', 'Solutions', '解决方案'],
    ['Dùng cho', 'Who it’s for', '适用对象'],
    ['Trường phổ thông', 'K-12 schools', '中小学'],
    ['Đại học, cao đẳng', 'Universities & colleges', '大学与学院'],
    ['Đào tạo nghề', 'Vocational training', '职业教育'],
    ['Tình huống', 'Use cases', '使用场景'],
    ['Giảng dạy trực tiếp', 'Live instruction', '课堂教学'],
    ['Kiểm tra, đánh giá', 'Assessment', '测验与评估'],
    ['Ôn tập, luyện tập', 'Practice & review', '练习与复习'],
    ['Tương tác cả lớp', 'Whole-class engagement', '全班互动'],
    ['Vì sao chọn', 'Why Clicker', '为何选择'],
    ['Bảng giá', 'Pricing', '价格'],
    ['Đăng nhập', 'Sign in', '登录'],
    ['Bắt đầu miễn phí', 'Start for free', '免费开始'],
    ['Ngôn ngữ', 'Language', '语言'],
    // ---- hero ----
    ['Vào lớp học?', 'Joining a class?', '要进入课堂？'],
    ['Nhập mã phòng', 'Enter room code', '输入房间码'],
    ['Mã phòng', 'Room code', '房间码'],
    ['Vào phòng', 'Join room', '进入房间'],
    ['Học sinh bấm chọn đáp án.', 'Students click their answer.', '学生按键作答。'],
    ['Kết quả cả lớp hiện ngay.', 'See the whole class instantly.', '全班结果即时呈现。'],
    ['Dạy học hiệu quả thông minh.', 'Teach smarter, more effectively.', '教学更高效、更智能。'],
    ['Xem cách hoạt động', 'See how it works', '了解运作方式'],
    ['Lớp 12A1', 'Class 12A1', '12A1 班'],
    ['· Câu 3/10', '· Question 3/10', '· 第 3/10 题'],
    ['TRỰC TIẾP', 'LIVE', '直播中'],
    ['Câu 3', 'Question 3', '第 3 题'],
    ['BLE advertising dùng bao nhiêu kênh quảng bá?', 'How many advertising channels does BLE use?', 'BLE 广播使用几个广播信道？'],
    ['1 kênh', '1 channel', '1 个信道'], ['2 kênh', '2 channels', '2 个信道'],
    ['3 kênh', '3 channels', '3 个信道'], ['40 kênh', '40 channels', '40 个信道'],
    ['Đã trả lời', 'Answered', '已作答'],
    ['Cả lớp làm đúng', 'Class correct', '全班正确率'],
    ['máy bấm cùng lúc', 'clickers at once', '台答题器同时在线'],
    ['từ lúc bấm đến màn hình', 'from click to screen', '从按键到屏幕'],
    ['bài được chấm tự động', 'graded automatically', '自动评分'],
    ['giây', 'sec', '秒'],
    // ---- features ----
    ['Bớt việc lặp lại,', 'Less repetitive work,', '减少重复工作，'],
    ['dành trọn thời gian để dạy.', 'more time to actually teach.', '把时间留给真正的教学。'],
    ['Phản hồi tức thì', 'Instant feedback', '即时反馈'],
    ['Bấm xong là biết đúng sai, kèm lời giải ngắn. Không phải chờ, không phải hỏi lại.',
      'Students know right away if they are correct, with a short explanation. No waiting, no follow-up.',
      '按下即知对错，并附简短解析。无需等待，无需追问。'],
    ['Chính xác.', 'Correct.', '正确。'],
    ['Kênh 37, 38, 39 dành cho quảng bá.', 'Channels 37, 38 and 39 are for advertising.', '37、38、39 信道用于广播。'],
    ['Báo cáo linh hoạt', 'Flexible reports & exports', '灵活的报告与导出'],
    ['Bảng điểm, phân tích từng câu, phiếu cho từng sinh viên. Xuất Excel một chạm.',
      'Gradebooks, per-question analysis and individual student reports. Export to Excel in one click.',
      '成绩单、逐题分析与学生个人报告，一键导出 Excel。'],
    ['Bảng_điểm_12A1.xlsx', 'Grades_12A1.xlsx', '成绩_12A1.xlsx'],
    ['Chấm tự động, giúp đúng người', 'Automated grading, targeted support', '自动评分，精准辅导'],
    ['Hệ thống tự chấm và chỉ ra ai đang tụt lại. Bạn dành thời gian cho người cần nhất.',
      'Grading is automatic and shows who is falling behind, so you can focus on those who need it most.',
      '系统自动评分并指出落后的学生，让您把时间留给最需要帮助的人。'],
    ['Giỏi nhất: Minh An', 'Top: Minh An', '最佳：Minh An'],
    ['Cần hỗ trợ: 3 bạn', 'Need help: 3 students', '需要帮助：3 人'],
    // ---- AI ----
    ['Soạn đề nhanh hơn với AI', 'Build quizzes faster with AI', '借助 AI 更快出题'],
    ['Tạo đề trong vài giây', 'Create a quiz in seconds', '数秒生成试题'],
    ['Mô tả đề bạn cần, AI soạn sẵn câu hỏi và đáp án.', 'Describe the quiz you need and AI drafts the questions and answers.', '描述您需要的测验，AI 自动生成题目与答案。'],
    ['Bám sát giáo trình', 'Stay on syllabus', '紧扣教材'],
    ['Dựa trên tài liệu bạn đã có để ra câu hỏi đúng bài.', 'Generate on-topic questions from materials you already use.', '基于您已有的教材生成切题的问题。'],
    ['Biến đề cũ thành đề mới', 'Turn old quizzes into new ones', '旧试卷变新题库'],
    ['Đưa file Word hoặc Excel cũ vào, nhận ngay bộ đề dùng được.', 'Drop in an old Word or Excel file and get a ready-to-use quiz.', '上传旧的 Word 或 Excel 文件，立即得到可用的试题。'],
    ['✦ SẮP RA MẮT', '✦ COMING SOON', '✦ 即将推出'],
    ['Bạn mô tả', 'You describe', '您的描述'],
    ['BLE quảng bá trên những kênh nào?', 'Which channels does BLE advertise on?', 'BLE 在哪些信道上广播？'],
    ['MQTT QoS 1 bảo đảm điều gì?', 'What does MQTT QoS 1 guarantee?', 'MQTT QoS 1 保证什么？'],
    ['Vì sao cần prepared statement?', 'Why use prepared statements?', '为什么要用预处理语句？'],
    ['Tài liệu', 'Material', '资料'],
    ['Giáo trình IoT · Chương 4: BLE', 'IoT course · Chapter 4: BLE', '物联网教材 · 第 4 章：BLE'],
    ['12 câu hỏi sát nội dung chương', '12 questions matched to the chapter', '12 道紧扣本章的题目'],
    ['Đáp án kèm lời giải thích', 'Answers with explanations', '答案附解析'],
    ['Chia mức dễ, vừa, khó', 'Easy, medium and hard levels', '分为易、中、难三级'],
    ['de_cu.docx', 'old_quiz.docx', '旧试卷.docx'],
    ['Bộ đề mới', 'New quiz', '新试题'],
    ['Tạo 10 câu trắc nghiệm về BLE và MQTT cho sinh viên năm 3', 'Create 10 multiple-choice questions on BLE and MQTT for third-year students', '为大三学生出 10 道关于 BLE 和 MQTT 的选择题'],
    // ---- use cases, why, CTA ----
    ['Dùng trong mọi khoảnh khắc của tiết học', 'For every moment of the lesson', '适用于课堂的每一刻'],
    ['Hỏi nhanh giữa giờ, cả lớp trả lời trong vài giây.', 'Ask a quick question mid-lesson and get every answer in seconds.', '课中随时提问，全班数秒内作答。'],
    ['Tìm hiểu thêm', 'Learn more', '了解更多'],
    ['Từ bài 15 phút đến giữa kỳ, nộp là có điểm.', 'From 15-minute quizzes to midterms, scores are ready on submit.', '从 15 分钟小测到期中考试，提交即出分。'],
    ['Khởi động đầu giờ, phiếu cuối giờ, ôn trước kỳ thi.', 'Warm-ups, exit tickets and exam review.', '课前热身、课后小结、考前复习。'],
    ['Ai cũng được hỏi, kể cả người ngại giơ tay.', 'Everyone gets asked, even those too shy to raise a hand.', '人人都能参与，连不敢举手的学生也不例外。'],
    ['Vì sao chọn Clicker', 'Why Clicker', '为何选择 Clicker'],
    ['Lý do giáo viên chọn Clicker', 'Why teachers choose Clicker', '教师选择 Clicker 的理由'],
    ['Không còn cảnh giơ tay.', 'No more raised hands.', '告别举手。'],
    ['Ai cũng trả lời, không ai bị bỏ qua.', 'Everyone answers; no one is left out.', '人人作答，无人遗漏。'],
    ['Biết lớp hiểu tới đâu', 'Know what the class understands', '掌握全班理解程度'],
    ['trước khi chuyển sang bài mới.', 'before moving on to the next topic.', '再进入下一课。'],
    ['Chấm 40 bài trong 0 phút.', 'Grade 40 papers in zero minutes.', '0 分钟批改 40 份。'],
    ['Điểm có ngay khi câu hỏi đóng.', 'Scores are ready when the question closes.', '题目关闭即出分。'],
    ['Không cần điện thoại.', 'No phones needed.', '无需手机。'],
    ['Mỗi sinh viên một máy bấm riêng.', 'Every student has their own clicker.', '每位学生一台答题器。'],
    ['Không cần Internet.', 'No Internet needed.', '无需联网。'],
    ['Chạy trên laptop ngay trong lớp.', 'Runs on a laptop right in the classroom.', '在教室内的笔记本上运行。'],
    ['Dữ liệu là của bạn.', 'Your data stays yours.', '数据归您所有。'],
    ['Lưu trên máy, xuất Excel bất cứ lúc nào.', 'Stored locally, exported to Excel any time.', '本地保存，随时导出 Excel。'],
    ['Sẵn sàng cho tiết học tiếp theo?', 'Ready for your next lesson?', '准备好下一堂课了吗？'],
    ['Không cần Internet, không cài gì trên máy sinh viên. Mọi thứ chạy ngay trong lớp.',
      'No Internet needed and nothing to install on student devices. Everything runs right in the classroom.',
      '无需联网，学生设备无需安装任何软件，一切都在教室内运行。'],
    // ---- footer ----
    ['Hệ thống trắc nghiệm bằng máy bấm cho lớp học.', 'Clicker-based quizzes for the classroom.', '基于答题器的课堂测验系统。'],
    ['Giáo viên', 'Teachers', '教师'],
    ['Tổng quan', 'Overview', '概览'],
    ['Soạn đề, nhập Excel', 'Build & import quizzes', '出题与导入'],
    ['Kết quả trực tiếp', 'Live results', '实时结果'],
    ['Báo cáo, xuất Excel', 'Reports & export', '报告与导出'],
    ['Học sinh', 'Students', '学生'],
    ['Vào lớp học', 'Join a class', '进入课堂'],
    ['Máy bấm trên điện thoại', 'Phone clicker', '手机答题器'],
    ['Cách nhập MSSV', 'Signing in with a student ID', '如何用学号登录'],
    ['Nhà trường', 'Schools', '学校'],
    ['Thiết bị hỗ trợ', 'Supported devices', '支持的设备'],
    ['Bảo mật dữ liệu', 'Data security', '数据安全'],
    ['Gói Tổ chức', 'Organization plan', '机构版方案'],
    ['Tài nguyên', 'Resources', '资源'],
    ['Trợ lý AI', 'AI assistant', 'AI 助手'],
    ['Câu hỏi thường gặp', 'Frequently asked questions', '常见问题'],
    ['Hỗ trợ', 'Support', '支持'],
    ['Trung tâm trợ giúp', 'Help center', '帮助中心'],
    ['Điều khoản sử dụng', 'Terms of use', '使用条款'],
    ['Chính sách quyền riêng tư', 'Privacy policy', '隐私政策'],
    ['Xoá dữ liệu', 'Data deletion', '数据删除'],
    ['© 2026 Clicker. Đồ án môn IoT.', '© 2026 Clicker. IoT course project.', '© 2026 Clicker。物联网课程项目。'],
    // ---- pricing (static) ----
    ['Chọn gói', 'Choose the plan', '选择'],
    ['vừa với lớp học.', 'that fits your class.', '适合您课堂的方案。'],
    ['Bắt đầu miễn phí. Nâng cấp khi lớp cần thêm.', 'Start free. Upgrade when your class needs more.', '免费开始，按需升级。'],
    ['Theo tháng', 'Monthly', '按月'], ['Theo năm', 'Yearly', '按年'],
    ['Sinh viên luôn miễn phí', 'Always free for students', '学生永久免费'],
    ['Sinh viên không trả gì, dùng máy bấm hay điện thoại đều được.', 'Students never pay, whether they use a clicker or a phone.', '学生无需付费，答题器或手机均可使用。'],
    ['So sánh', 'Compare', '比较'], ['So sánh các gói', 'Compare plans', '方案对比'],
    ['Miễn phí', 'Free', '免费'], ['Cơ bản', 'Essentials', '基础版'], ['Chuyên nghiệp', 'Pro', '专业版'], ['Tổ chức', 'Organization', '机构版'],
    ['Hỏi đáp', 'FAQ', '问答'],
    ['Bắt đầu miễn phí, nâng cấp khi cần', 'Start free, upgrade when you need to', '免费开始，按需升级'],
    ['Tạo tài khoản trong một phút. Không cần thẻ ngân hàng.', 'Create an account in a minute. No card required.', '一分钟创建账户，无需银行卡。'],
    ['Tạo tài khoản', 'Create account', '创建账户'],
    // ---- pricing (rendered) ----
    ['Cho giáo viên mới bắt đầu.', 'For teachers just getting started.', '适合刚起步的教师。'],
    ['Cho một giáo viên dạy vài lớp.', 'For a teacher with a few classes.', '适合教授几个班级的教师。'],
    ['Cho tổ bộ môn cần công cụ đầy đủ.', 'For departments that need the full toolkit.', '适合需要完整工具的教研组。'],
    ['Cho cả trường hoặc trung tâm đào tạo.', 'For whole schools and training centers.', '适合整个学校或培训中心。'],
    ['Bắt đầu ngay', 'Get started', '立即开始'], ['Dùng thử 14 ngày', 'Try 14 days free', '免费试用 14 天'], ['Liên hệ', 'Contact us', '联系我们'],
    ['Bao gồm', 'Includes', '包含'],
    ['Mọi thứ của Miễn phí, thêm:', 'Everything in Free, plus:', '包含免费版全部功能，另加：'],
    ['Mọi thứ của Cơ bản, thêm:', 'Everything in Essentials, plus:', '包含基础版全部功能，另加：'],
    ['Mọi thứ của Chuyên nghiệp, thêm:', 'Everything in Pro, plus:', '包含专业版全部功能，另加：'],
    ['5 bộ đề', '5 quizzes', '5 套试题'], ['1 phòng', '1 room', '1 个房间'],
    ['40 sinh viên mỗi phòng', '40 students per room', '每个房间 40 名学生'],
    ['Lưu báo cáo 30 ngày', '30-day report history', '报告保存 30 天'], ['Chấm điểm tự động', 'Automatic grading', '自动评分'],
    ['Không giới hạn bộ đề', 'Unlimited quizzes', '试题数量不限'], ['5 phòng', '5 rooms', '5 个房间'],
    ['Lưu báo cáo 90 ngày', '90-day report history', '报告保存 90 天'], ['Tạm dừng hoạt động', 'Pause activities', '暂停活动'],
    ['Báo cáo nâng cao', 'Advanced reports', '高级报告'], ['30 phòng', '30 rooms', '30 个房间'],
    ['20 thư viện dùng chung', '20 shared libraries', '20 个共享题库'], ['300 sinh viên mỗi phòng', '300 students per room', '每个房间 300 名学生'],
    ['Lưu báo cáo không giới hạn', 'Unlimited report history', '报告永久保存'], ['Chèn video vào câu hỏi', 'Embed videos in questions', '在题目中嵌入视频'],
    ['Giá tốt hơn cho mỗi tài khoản', 'Better price per account', '每个账户更优惠'], ['Gộp nhiều tài khoản', 'Multi-account consolidation', '多账户合并'],
    ['Quản lý tài nguyên dùng chung', 'Control of shared resources', '共享资源管理'], ['Người phụ trách riêng', 'Dedicated account manager', '专属客户经理'],
    ['Hỗ trợ ưu tiên', 'Priority support', '优先支持'],
    ['AI tạo câu hỏi', 'AI question generation', 'AI 生成题目'], ['AI nhập đề', 'AI question import', 'AI 导入试题'],
    ['AI phân tích báo cáo', 'AI report analysis', 'AI 报告分析'],
    ['{label}: {n}/tuần', '{label}: {n}/week', '{label}：每周 {n} 次'],
    ['Đáng giá nhất', 'Best value', '最超值'],
    ['/ tháng', '/ month', '/ 月'],
    ['Không cần thẻ ngân hàng', 'No card required', '无需银行卡'],
    ['Báo giá theo số tài khoản', 'Priced by number of accounts', '按账户数量报价'],
    ['Thanh toán {n}đ mỗi năm', 'Billed {n}₫ per year', '每年支付 {n}₫'],
    ['Thanh toán {n}đ mỗi tháng', 'Billed {n}₫ per month', '每月支付 {n}₫'],
    ['{n} tài khoản', '{n} accounts', '{n} 个账户'],
    ['Giảm 10% từ {n} tài khoản', 'Save 10% with {n}+ accounts', '{n} 个及以上账户享 9 折'],
    ['Bớt tài khoản', 'Fewer accounts', '减少账户'], ['Thêm tài khoản', 'More accounts', '增加账户'],
    ['Soạn đề và tương tác', 'Quizzing & engagement', '出题与互动'], ['Trợ lý AI (mỗi tuần)', 'AI assistant (per week)', 'AI 助手（每周）'],
    ['Phản hồi và báo cáo', 'Feedback & reports', '反馈与报告'], ['Thiết bị', 'Devices', '设备'], ['Thư viện', 'Libraries', '题库'],
    ['Quản lý sinh viên', 'Student management', '学生管理'],
    ['Nhiều kiểu hoạt động', 'Multiple activity types', '多种活动类型'], ['Nhập đề từ Excel', 'Import quizzes from Excel', '从 Excel 导入试题'],
    ['Sắp xếp theo thư mục', 'Organize with folders', '文件夹管理'], ['Chạy nhiều phòng cùng lúc', 'Run several rooms at once', '多个房间同时进行'],
    ['Sinh viên mỗi phòng', 'Students per room', '每个房间学生数'], ['Báo cáo Excel và PDF', 'Excel & PDF reports', 'Excel 与 PDF 报告'],
    ['Chấm tay, điểm thành phần', 'Manual grading & partial marks', '人工评分与部分得分'], ['Đáp án kèm lời giải', 'Answer key with explanations', '答案与解析'],
    ['Máy bấm nRF52', 'nRF52 clicker', 'nRF52 答题器'], ['Gateway USB cho tag BLE', 'USB gateway for BLE tags', 'BLE 答题器 USB 网关'], ['Điện thoại', 'Phone', '手机'], ['Chạy không cần Internet', 'Works without Internet', '无需联网运行'],
    ['Thư viện riêng', 'Private library', '私人题库'], ['Thư viện dùng chung', 'Shared libraries', '共享题库'], ['Không giới hạn', 'Unlimited', '不限'],
    ['Nhập danh sách lớp từ Excel', 'Import class rosters from Excel', '从 Excel 导入班级名单'], ['Xem ai đang trực tuyến', 'See who is online', '查看在线学生'],
    ['Gửi kết quả riêng cho từng sinh viên', 'Send each student their own results', '向每位学生发送个人成绩'], ['Hướng dẫn sử dụng', 'User guide', '使用指南'],
    ['Một phòng có tối đa bao nhiêu sinh viên?', 'How many students can join one room?', '一个房间最多可容纳多少名学生？'],
    ['Gói Miễn phí và Cơ bản: 40 sinh viên mỗi phòng, đúng mức hệ thống đã được thử tải. Gói Chuyên nghiệp và Tổ chức: tới 300 sinh viên.',
      'Free and Essentials: 40 students per room, the load the system has been tested at. Pro and Organization: up to 300 students.',
      '免费版与基础版：每个房间 40 名学生，即系统已通过的负载测试规模。专业版与机构版：最多 300 名学生。'],
    ['Lớp học không có Internet thì dùng được không?', 'Can I use it in a classroom without Internet?', '教室没有网络也能使用吗？'],
    ['Được. Máy chủ chạy trên laptop ngay trong lớp, máy bấm kết nối qua BLE hoặc Wi-Fi nội bộ.',
      'Yes. The server runs on a laptop in the classroom, and clickers connect over BLE or local Wi-Fi.',
      '可以。服务器运行在教室内的笔记本上，答题器通过 BLE 或本地 Wi-Fi 连接。'],
    ['Nâng cấp gói thì bộ đề và báo cáo cũ còn không?', 'Do I keep my quizzes and reports when I upgrade?', '升级方案后，原有试题和报告还在吗？'],
    ['Còn nguyên. Bộ đề, danh sách lớp và báo cáo được giữ lại khi bạn đổi gói.', 'Yes. Quizzes, class rosters and reports are kept when you change plans.', '全部保留。更换方案时，试题、班级名单和报告都会保留。'],
    ['Có được dùng thử trước khi trả tiền không?', 'Can I try it before paying?', '付费前可以试用吗？'],
    ['Có. Gói Cơ bản và Chuyên nghiệp được dùng thử 14 ngày. Hết thời gian thử mới tính tiền.',
      'Yes. Essentials and Pro come with a 14-day trial. You are only charged after the trial ends.',
      '可以。基础版和专业版可免费试用 14 天，试用结束后才开始收费。'],
    ['Thanh toán bằng cách nào?', 'How do I pay?', '如何付款？'],
    ['Chuyển khoản ngân hàng hoặc quét mã QR. Gói Tổ chức có thể xuất hoá đơn cho nhà trường.',
      'By bank transfer or QR code. The Organization plan can be invoiced to the school.',
      '可通过银行转账或扫描二维码付款。机构版可为学校开具发票。'],
    ['Mua nhiều tài khoản có rẻ hơn không?', 'Is it cheaper to buy several accounts?', '购买多个账户会更便宜吗？'],
    ['Có. Gói Chuyên nghiệp giảm 10% khi mua từ 2 tài khoản. Từ 10 tài khoản trở lên, hãy chọn gói Tổ chức.',
      'Yes. Pro is 10% off from 2 accounts. For 10 or more accounts, choose the Organization plan.',
      '会。专业版购买 2 个及以上账户享 9 折。10 个及以上账户请选择机构版。'],
    ['Chính sách hoàn tiền thế nào?', 'What is the refund policy?', '退款政策是什么？'],
    ['Hoàn tiền trong 30 ngày kể từ khi thanh toán, tính cả thời gian dùng thử.', 'Refunds within 30 days of payment, including the trial period.', '付款后 30 天内可退款，试用期计算在内。'],
    // ---- login ----
    ['Trang chủ', 'Home', '首页'],
    ['Đăng nhập thành công', 'Signed in', '登录成功'],
    ['Đang mở bảng điều khiển…', 'Opening your dashboard…', '正在打开控制台…'],
    ['Vào bảng điều khiển', 'Go to dashboard', '进入控制台'],
    ['Dùng tài khoản khác', 'Use another account', '使用其他账户'],
    ['Sinh viên', 'Student', '学生'],
    ['Chào mừng trở lại', 'Welcome back', '欢迎回来'],
    ['Đăng nhập để soạn đề và điều khiển lớp học.', 'Sign in to build quizzes and run your class.', '登录以出题并管理课堂。'],
    ['Tạo tài khoản giáo viên', 'Create a teacher account', '创建教师账户'],
    ['Miễn phí, chỉ mất một phút.', 'Free, and it only takes a minute.', '免费，只需一分钟。'],
    ['Tiếp tục với Google', 'Continue with Google', '使用 Google 继续'],
    ['Tiếp tục với Facebook', 'Continue with Facebook', '使用 Facebook 继续'],
    ['hoặc', 'or', '或'],
    ['Tên đăng nhập', 'Username', '用户名'],
    ['Họ và tên (dùng để đăng nhập)', 'Full name (used to sign in)', '姓名（用于登录）'],
    ['Ví dụ: Nguyen Van An', 'e.g. Nguyen Van An', '例如：Nguyen Van An'],
    ['Mật khẩu (8 chữ số)', 'Password (8 digits)', '密码（8 位数字）'],
    ['8 chữ số', '8 digits', '8 位数字'],
    ['Hiện mật khẩu', 'Show password', '显示密码'], ['Ẩn mật khẩu', 'Hide password', '隐藏密码'],
    ['Nhập lại mật khẩu', 'Confirm password', '确认密码'],
    ['Quên mật khẩu?', 'Forgot password?', '忘记密码？'],
    ['Chưa có tài khoản?', 'No account yet?', '还没有账户？'],
    ['Đã có tài khoản?', 'Already have an account?', '已有账户？'],
    ['Tạo tài khoản miễn phí', 'Create a free account', '免费创建账户'],
    ['Sinh viên không cần tài khoản. Đăng nhập bằng MSSV ngay trên máy bấm.', 'Students don’t need an account. Sign in with your student ID on the clicker.', '学生无需账户，直接在答题器上用学号登录。'],
    ['Mở máy bấm.', 'Turn on the clicker.', '打开答题器。'],
    ['Dùng máy bấm của lớp, hoặc mở trang máy bấm trên điện thoại.', 'Use a class clicker, or open the clicker page on your phone.', '使用班级答题器，或在手机上打开答题器页面。'],
    ['Chờ giáo viên mở đăng nhập,', 'Wait for your teacher to open sign-in,', '等待老师开放登录，'],
    ['rồi nhập MSSV và bấm xác nhận.', 'then enter your student ID and confirm.', '然后输入学号并确认。'],
    ['Chọn đáp án', 'Pick your answer', '选择答案'],
    ['khi câu hỏi hiện trên màn hình lớp.', 'when the question appears on the class screen.', '在题目显示于课堂屏幕时。'],
    ['Mở máy bấm trên điện thoại', 'Open the phone clicker', '打开手机答题器'],
    ['Bạn là giáo viên?', 'Are you a teacher?', '您是教师？'],
    ['Đăng nhập tại đây', 'Sign in here', '在此登录'],
    ['Soạn đề, mở phòng và xem kết quả cả lớp ngay trên màn hình.', 'Build quizzes, open a room and see the whole class on screen.', '出题、开房间，全班结果尽在屏幕上。'],
    ['✦ Chấm điểm tự động khi câu hỏi đóng', '✦ Automatic grading when a question closes', '✦ 题目关闭即自动评分'],
    ['✦ Biết ngay câu nào cả lớp làm sai', '✦ Spot the questions the class got wrong', '✦ 立即发现全班答错的题'],
    ['✦ Xuất bảng điểm Excel một chạm', '✦ Export grades to Excel in one click', '✦ 一键导出 Excel 成绩单'],
    ['CÂU 3 · CẢ LỚP', 'QUESTION 3 · CLASS', '第 3 题 · 全班'],
    ['Không cần Internet', 'Works offline', '无需联网'], ['Máy bấm BLE', 'BLE clickers', 'BLE 答题器'], ['Xuất Excel', 'Excel export', '导出 Excel'],
    ['Máy chủ chưa phản hồi. Thử lại sau.', 'The server did not respond. Try again later.', '服务器未响应，请稍后再试。'],
    ['Hãy nhập họ và tên.', 'Please enter your full name.', '请输入姓名。'],
    ['Hãy nhập tên đăng nhập.', 'Please enter your username.', '请输入用户名。'],
    ['Tên cần ít nhất 3 ký tự.', 'The name needs at least 3 characters.', '名称至少需要 3 个字符。'],
    ['Mật khẩu cần đúng 8 chữ số.', 'The password must be exactly 8 digits.', '密码必须为 8 位数字。'],
    ['Hai mật khẩu chưa khớp.', 'The passwords don’t match.', '两次输入的密码不一致。'],
    ['Đang tạo tài khoản', 'Creating account', '正在创建账户'], ['Đang đăng nhập', 'Signing in', '正在登录'],
    ['Tạo tài khoản thành công', 'Account created', '账户创建成功'],
    ['Xin chào, {name}', 'Hello, {name}', '你好，{name}'],
    ['Bạn đang đăng nhập: {name}', 'You’re signed in as {name}', '当前登录：{name}'],
    ['Tiếp tục vào bảng điều khiển, hoặc đổi tài khoản.', 'Continue to your dashboard, or switch accounts.', '继续进入控制台，或切换账户。'],
    ['Mật khẩu do quản trị viên máy chủ đặt lại. Hãy liên hệ người quản lý hệ thống của lớp.',
      'Passwords are reset by the server administrator. Please contact whoever manages your class system.', '密码由服务器管理员重置，请联系课堂系统管理员。'],
    ['Đăng nhập bằng {p} chưa được bật: quản trị viên cần cấu hình ứng dụng {p} trên máy chủ.',
      'Sign-in with {p} is not enabled yet: the administrator needs to configure the {p} app on the server.', '尚未启用 {p} 登录：管理员需要在服务器上配置 {p} 应用。'],
    ['Đang chuyển tới {p}…', 'Redirecting to {p}…', '正在跳转到 {p}…'],
    ['Đăng nhập bằng tài khoản này chưa được bật trên máy chủ.', 'This sign-in method is not enabled on the server.', '服务器尚未启用此登录方式。'],
    ['Bạn đã huỷ đăng nhập.', 'You cancelled the sign-in.', '您已取消登录。'],
    ['Phiên đăng nhập đã hết hạn hoặc không hợp lệ. Hãy thử lại.', 'The sign-in session expired or is invalid. Please try again.', '登录会话已过期或无效，请重试。'],
    ['Không xác minh được tài khoản với nhà cung cấp. Hãy thử lại.', 'We couldn’t verify your account with the provider. Please try again.', '无法与提供方验证您的账户，请重试。'],
    ['Máy chủ không kết nối được Internet để xác minh tài khoản.', 'The server can’t reach the Internet to verify your account.', '服务器无法连接互联网以验证账户。'],
    ['Hãy mở trang bằng http://localhost/clicker hoặc đường dẫn công khai để đăng nhập bằng Google.',
      'Open this page via http://localhost/clicker or the public link to sign in with Google.', '请通过 http://localhost/clicker 或公开链接打开本页，以使用 Google 登录。'],
    ['Không đọc được thông tin tài khoản.', 'Couldn’t read your account details.', '无法读取账户信息。'],
    ['Trang công khai chỉ hỗ trợ HTTPS.', 'The public site only supports HTTPS.', '公开站点仅支持 HTTPS。'],
    ['Gõ tên đăng nhập (hoặc Gmail) vào ô trên rồi bấm lại "Quên mật khẩu?".', 'Type your username (or Gmail) above, then press "Forgot password?" again.', '请先在上方输入用户名（或 Gmail），再点击“忘记密码？”。'],
    ['Đã gửi yêu cầu đặt lại mật khẩu cho quản trị viên. Khi được đặt lại, quản trị viên sẽ báo mật khẩu mới cho bạn. Nếu tài khoản dùng Gmail, chỉ cần bấm "Tiếp tục với Google".', 'Your reset request was sent to the administrator, who will give you a new password. If your account uses Gmail, just press "Continue with Google".', '已向管理员发送重置密码请求，管理员会告诉您新密码。如果账户使用 Gmail，直接点击“使用 Google 继续”即可。'],
    ['Đang tạm dừng đăng ký tài khoản mới. Hãy liên hệ quản trị viên.', 'New sign-ups are paused. Please contact the administrator.', '暂停注册新账户，请联系管理员。'],
    ['Tài khoản: {n}', 'Account: {n}', '账户：{n}'],
    ['Đang chờ quản trị viên cấp phép', 'Waiting for the administrator to approve', '等待管理员批准'],
    ['Giữ trang này mở. Khi được cấp phép, ô đặt mật khẩu mới sẽ tự hiện ra.', 'Keep this page open. Once approved, the new-password form appears by itself.', '请保持此页面打开。获批后会自动出现设置新密码的表单。'],
    ['Huỷ yêu cầu', 'Cancel request', '取消请求'], ['Đặt mật khẩu mới', 'Set a new password', '设置新密码'], ['Quản trị viên đã cấp phép cho', 'The administrator approved', '管理员已批准'],
    ['Mật khẩu mới (8 chữ số)', 'New password (8 digits)', '新密码（8 位数字）'], ['Nhập lại mật khẩu mới', 'Repeat the new password', '再次输入新密码'],
    ['Lưu và đăng nhập', 'Save and sign in', '保存并登录'], ['Yêu cầu không được chấp nhận', 'Request not approved', '请求未获批准'],
    ['Quản trị viên đã từ chối hoặc yêu cầu đã hết hạn. Hãy liên hệ trực tiếp quản trị viên.', 'The administrator declined, or the request expired. Please contact the administrator.', '管理员已拒绝或请求已过期，请直接联系管理员。'],
    ['Quay lại đăng nhập', 'Back to sign in', '返回登录'], ['Mật khẩu cần đúng 8 chữ số.', 'The password must be exactly 8 digits.', '密码必须为 8 位数字。'],
    ['Hai lần nhập mật khẩu không giống nhau.', 'The two passwords do not match.', '两次输入的密码不一致。'],
    ['Tài khoản đã bị khoá. Hãy liên hệ quản trị viên.', 'This account is locked. Please contact the administrator.', '该账户已被锁定，请联系管理员。'],
    ['Thử quá nhiều lần. Hãy chờ vài phút.', 'Too many attempts. Please wait a few minutes.', '尝试次数过多，请稍候几分钟。'],
    ['Máy chủ gặp lỗi khi đăng nhập. Hãy thử lại.', 'The server hit an error while signing in. Please try again.', '登录时服务器出错，请重试。'],
    ['Đăng nhập chưa thành công. Hãy thử lại.', 'Sign-in didn’t succeed. Please try again.', '登录未成功，请重试。'],
    ['Tên đăng nhập hoặc mật khẩu không đúng.', 'Incorrect username or password.', '用户名或密码错误。'],
    ['Tên đăng nhập đã được dùng.', 'That username is already taken.', '该用户名已被使用。'],
    ['Gửi quá nhiều yêu cầu. Hãy thử lại sau.', 'Too many requests. Please try again later.', '请求过多，请稍后再试。'],
    ['Mật khẩu cần đúng 8 chữ số.', 'The password must be exactly 8 digits.', '密码必须为 8 位数字。'],
    // ---- room code ----
    ['Mã phòng gồm 6 chữ số.', 'The room code has 6 digits.', '房间码为 6 位数字。'],
    ['Đang kiểm tra…', 'Checking…', '正在检查…'], ['Đúng phòng! Đang vào…', 'Room found! Joining…', '找到房间！正在进入…'],
    ['Mã phòng không đúng hoặc phòng chưa mở.', 'Wrong code, or the room isn’t open yet.', '房间码错误或房间尚未开放。'],
    ['Bạn nhập sai nhiều lần, chờ một phút rồi thử lại.', 'Too many tries. Wait a minute and try again.', '尝试次数过多，请一分钟后再试。'],
    ['Chưa kết nối được máy chủ, thử lại sau nhé.', 'Couldn’t reach the server. Please try again.', '无法连接服务器，请稍后再试。'],
    // ---- chat assistant ----
    ['Mở trợ lý Clicker', 'Open the Clicker assistant', '打开 Clicker 助手'],
    ['Trợ lý Clicker', 'Clicker assistant', 'Clicker 助手'],
    ['Chưa bật', 'Not enabled', '未启用'], ['Trực tuyến', 'Online', '在线'],
    ['Đóng', 'Close', '关闭'], ['Nhập câu hỏi…', 'Ask a question…', '输入问题…'], ['Gửi', 'Send', '发送'],
    ['Trợ lý AI có thể nhầm. Hãy kiểm tra thông tin quan trọng.', 'The AI assistant can make mistakes. Check important information.', 'AI 助手可能出错，请核实重要信息。'],
    ['Xin chào! Mình là **trợ lý Clicker**. Mình có thể giải thích cách dùng, thiết bị, bảng giá, hoặc giúp bạn bắt đầu buổi kiểm tra đầu tiên.',
      'Hi! I’m the **Clicker assistant**. I can explain how it works, the devices and pricing, or help you run your first quiz.',
      '你好！我是 **Clicker 助手**。我可以介绍使用方法、设备和价格，或帮您开始第一次测验。'],
    ['Xin chào 👋 Mình là **trợ lý Clicker**. Cần giúp gì không?', 'Hi 👋 I’m the **Clicker assistant**. Need a hand?', '你好 👋 我是 **Clicker 助手**，需要帮忙吗？'],
    ['Clicker là gì?', 'What is Clicker?', '什么是 Clicker？'], ['Cần những thiết bị gì?', 'What devices do I need?', '需要哪些设备？'],
    ['Bảng giá thế nào?', 'How much does it cost?', '价格如何？'], ['Làm sao tạo bộ đề?', 'How do I create a quiz?', '如何创建试题？'],
    ['Trợ lý AI chưa được bật trên máy chủ.', 'The AI assistant is not enabled on the server yet.', '服务器尚未启用 AI 助手。'],
    ['Bạn hỏi hơi nhanh, chờ vài phút rồi hỏi tiếp nhé.', 'You’re asking a bit fast. Please wait a few minutes.', '提问有点快，请稍等几分钟。'],
    ['Trợ lý đang bận, thử lại sau ít giây nhé.', 'The assistant is busy. Try again in a few seconds.', '助手正忙，请几秒后再试。'],
    ['Chưa kết nối được trợ lý, thử lại sau nhé.', 'Couldn’t reach the assistant. Please try again later.', '无法连接助手，请稍后再试。'],
    ['Học sinh không cần tài khoản: nhập MSSV ngay trên máy bấm, hoặc', 'Students don’t need an account: enter your student ID on the clicker, or', '学生无需账户：直接在答题器上输入学号，或'],
    ['mở máy bấm trên điện thoại', 'open the phone clicker', '打开手机答题器'],
    ['Dùng Clicker để giảng dạy trực tiếp trên lớp như thế nào?', 'How do I use Clicker for live classroom teaching?', '如何在课堂教学中使用 Clicker？'],
    ['Dùng Clicker để kiểm tra, đánh giá như thế nào?', 'How do I use Clicker for quizzes and assessment?', '如何使用 Clicker 进行测验与评估？'],
    ['Dùng Clicker để ôn tập, luyện tập như thế nào?', 'How do I use Clicker for practice and review?', '如何使用 Clicker 进行练习与复习？'],
    ['Clicker giúp cả lớp cùng tương tác như thế nào?', 'How does Clicker get the whole class engaged?', 'Clicker 如何让全班互动起来？'],
  ];
  const LANGS = { vi: 'Tiếng Việt', en: 'English', zh: '中文' };
  const D = { en: new Map(), zh: new Map() };
  for (const [vi, en, zh] of L) { D.en.set(vi, en); D.zh.set(vi, zh); }
  // Patterns for text built with numbers or names: [RegExp matching the whole Vietnamese text, en, zh] with $1… groups.
  const P = [];
  function add(rows = [], patterns = []) {
    for (const [vi, en, zh] of rows) { D.en.set(vi, en); D.zh.set(vi, zh); }
    for (const [re, en, zh] of patterns) P.push({ re, en, zh });
  }
  function lookup(key) {
    const hit = D[lang].get(key);
    if (hit !== undefined) return hit;
    for (const p of P) {
      const m = key.match(p.re);
      if (m) return p[lang].replace(/\$(\d)/g, (_, i) => { const g = m[i] ?? ''; return D[lang].get(g) ?? g; });
    }
  }

  let lang = 'vi';
  try { const s = localStorage.getItem('clicker-lang'); if (s in LANGS) lang = s; } catch {}
  const q = new URLSearchParams(location.search).get('lang');
  if (q in LANGS) lang = q;

  function t(vi, vars) {
    let s = lang === 'vi' ? vi : (lookup(vi) ?? vi);
    if (vars) for (const k in vars) s = s.split('{' + k + '}').join(vars[k]);
    return s;
  }

  const orig = new WeakMap();
  const ATTRS = ['placeholder', 'aria-label', 'title'];
  function apply(root = document.body) {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, { acceptNode(n) {
      const p = n.parentElement;
      return !p || p.closest('script,style,svg,[data-noi18n]') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT; } });
    for (let n; (n = walker.nextNode());) {
      if (!orig.has(n)) orig.set(n, n.nodeValue);
      const o = orig.get(n), key = o.trim();
      if (!key) continue;
      const tr = lang === 'vi' ? key : lookup(key);
      n.nodeValue = tr === undefined ? o : o.replace(key, tr);
    }
    root.querySelectorAll('[placeholder],[aria-label],[title]').forEach(el => {
      if (el.closest('[data-noi18n]')) return;
      for (const a of ATTRS) {
        if (!el.hasAttribute(a)) continue;
        const k = 'i18n' + a.replace(/-./g, m => m[1].toUpperCase());
        if (el.dataset[k] === undefined) el.dataset[k] = el.getAttribute(a);
        el.setAttribute(a, t(el.dataset[k]));
      }
    });
  }

  let baseTitle = null;
  function set(l) {
    if (!(l in LANGS)) return;
    lang = l;
    try { localStorage.setItem('clicker-lang', l); } catch {}
    document.documentElement.lang = l === 'zh' ? 'zh-CN' : l;
    if (baseTitle === null) baseTitle = document.title;
    document.title = t(baseTitle);
    apply();
    document.querySelectorAll('[data-lang-current]').forEach(e => e.textContent = l === 'zh' ? '中文' : l.toUpperCase());
    document.querySelectorAll('[data-set-lang]').forEach(b => b.setAttribute('aria-current', String(b.dataset.setLang === l)));
    document.dispatchEvent(new CustomEvent('langchange', { detail: l }));
  }

  /* Language menu: <div class="lang" data-lang-menu></div> anywhere on the page */
  function menus() {
    const globe = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg>';
    document.querySelectorAll('[data-lang-menu]').forEach(m => {
      m.setAttribute('data-noi18n', '');
      m.innerHTML = `<button type="button" class="lang-btn" aria-haspopup="true" aria-label="Language">${globe}<b data-lang-current></b>
        <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path d="M9.3 12.95l.7.7L15.66 8l-1.42-1.41L10 10.83 5.76 6.59 4.34 8z"/></svg></button>
        <div class="lang-list" role="menu">${Object.entries(LANGS).map(([k, v]) =>
          `<button type="button" role="menuitemradio" data-set-lang="${k}"><span>${v}</span><i>✓</i></button>`).join('')}</div>`;
      const btn = m.querySelector('.lang-btn');
      btn.addEventListener('click', e => { e.stopPropagation(); m.classList.toggle('open'); });
      m.querySelectorAll('[data-set-lang]').forEach(b => b.addEventListener('click', () => { set(b.dataset.setLang); m.classList.remove('open'); }));
    });
    document.addEventListener('click', () => document.querySelectorAll('[data-lang-menu].open').forEach(m => m.classList.remove('open')));
  }

  function start() { menus(); set(lang); }
  return { t, apply, set, start, add, get lang() { return lang; } };
})();
