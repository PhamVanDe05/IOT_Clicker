<?php
// Preview account on the TEST database for looking at UI changes: 3 classes from du-lieu-mau/lop and 3 question sets.
//   bash tools/dev/serve_test.sh && php tools/dev/seed_preview.php      (prints nothing secret; login in .local/preview-login.txt)
//   php tools/dev/seed_preview.php --remove
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$c = require "$root/.local/test-config.php";
if (!str_contains($c['dsn'], 'smart_clicker_test')) exit("Refusing: not the test database\n");
require "$root/web/lib.php";
$db = new PDO($c['dsn'], $c['db_user'], $c['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
const USER = 'xemtruoc';
$old = $db->query("SELECT id FROM teacher_account WHERE username='" . USER . "'")->fetchColumn();
if ($old) { $db->beginTransaction(); release_room_of($db, (int)$old); delete_teacher($db, (int)$old); $db->commit(); }
if (in_array('--remove', $argv, true)) { @unlink("$root/.local/preview-login.txt"); exit("removed\n"); }

$pass = (string)random_int(10000000, 99999999);
$db->prepare('INSERT INTO teacher_account(username,password_hash) VALUES(?,?)')->execute([USER, password_hash($pass, PASSWORD_DEFAULT)]);
$id = (int)$db->lastInsertId();
$db->prepare('INSERT INTO teacher_profile(teacher_id,full_name,gender,school,subject,grades) VALUES(?,?,?,?,?,?)')
   ->execute([$id, 'Nguyễn Duy', 'nam', 'ĐH Sư phạm Kỹ thuật', 'IoT', 'Đại học']);
file_put_contents("$root/.local/preview-login.txt", "Xem trước (DB thử): http://127.0.0.1:8099/clicker/landing/login.html\nTên: " . USER . "\nMật khẩu: $pass\n");
chmod("$root/.local/preview-login.txt", 0600);

$base = 'http://127.0.0.1:8099/clicker/api.php'; $cookie = ''; $csrf = '';
$post = function (array $body) use ($base, &$cookie, &$csrf): array {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'content' => json_encode($body),
        'header' => "Content-Type: application/json\r\n" . ($cookie ? "Cookie: $cookie\r\n" : '') . ($csrf ? "X-CSRF-Token: $csrf\r\n" : '')]]);
    $raw = file_get_contents($base, false, $ctx);
    foreach ($http_response_header as $h) if (preg_match('/^Set-Cookie: (clicker_teacher=[^;]+)/i', $h, $m)) $cookie = $m[1];
    return json_decode((string)$raw, true) ?? [];
};
$db->exec('DELETE FROM request_limit');
$csrf = $post(['action' => 'teacher_login', 'username' => USER, 'password' => $pass])['csrf'] ?? '';
foreach (glob("$root/du-lieu-mau/lop/*.csv") as $file) {
    $rows = array_map('str_getcsv', file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    array_shift($rows);
    $post(['action' => 'import_students', 'students' => array_map(fn($r) => ['student_no' => $r[0], 'student_id' => $r[1], 'student_name' => $r[2], 'class_id' => $r[3]], $rows)]);
}
$sets = [
    'IoT và mạng cơ bản' => [['MQTT dùng mô hình nào?', 'Hỏi – đáp', 'Publish/subscribe qua broker', 'Ngang hàng', 'Quảng bá', 'B'],
        ['Cổng mặc định của MQTT không mã hoá?', '80', '443', '1883', '5683', 'C'], ['CoAP chạy trên giao thức vận chuyển nào?', 'TCP', 'UDP', 'QUIC', 'SCTP', 'B'],
        ['HTTP mặc định dùng cổng nào?', '21', '25', '80', '110', 'C'], ['Thiết bị nào làm trung gian trong MQTT?', 'Router', 'Broker', 'Switch', 'Gateway DNS', 'B']],
    'Bluetooth Low Energy' => [['BLE hoạt động ở băng tần nào?', '900 MHz', '2,4 GHz', '5 GHz', '60 GHz', 'B'], ['BLE có bao nhiêu kênh quảng bá?', '1', '3', '37', '40', 'B'],
        ['Gói quảng bá BLE 4 chứa tối đa bao nhiêu byte?', '20', '31', '64', '255', 'B'], ['GATT tổ chức dữ liệu theo gì?', 'Bảng SQL', 'Service và Characteristic', 'Topic', 'URL', 'B']],
    'Điện tử cơ bản' => [['Đơn vị điện trở là gì?', 'Volt', 'Ampe', 'Ohm', 'Watt', 'C'], ['IC 74HC595 là loại gì?', 'Bộ so sánh', 'Thanh ghi dịch', 'Bộ nhớ', 'Vi điều khiển', 'B'],
        ['LED anode chung sáng khi chân đoạn ở mức nào?', 'Cao', 'Thấp', 'Thả nổi', 'PWM 50%', 'B']],
];
foreach ($sets as $title => $qs) $post(['action' => 'import_questions', 'title' => $title, 'commit' => true,
    'rows' => array_map(fn($q) => ['question_text' => $q[0], 'answer_A' => $q[1], 'answer_B' => $q[2], 'answer_C' => $q[3], 'answer_D' => $q[4], 'correct_answer' => $q[5]], $qs)]);
$db->exec('DELETE FROM request_limit');
echo "ok: " . count(glob("$root/du-lieu-mau/lop/*.csv")) . " lớp, " . count($sets) . " bộ đề (đăng nhập: .local/preview-login.txt)\n";
