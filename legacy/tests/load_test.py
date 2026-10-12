#!/usr/bin/env python3
"""Load test: N clickers in one room against the test database (never the live one).

    python3 tests/load_test.py [--devices 40] [--poll 0.4] [--drop 0.15] [--workers 16]

Each simulated device polls like the firmware, logs in, answers every question with
retry-until-ACK, and ignores a share of ACKs (--drop) to force duplicate sends.
Question 1 is a burst: every device presses OK at the same instant. At the end the
database must hold exactly one response per student per question.
"""
import argparse, hashlib, hmac, json, os, random, secrets, shutil, statistics, subprocess, sys, tempfile, threading, time
import urllib.request, urllib.error
from http.cookiejar import CookieJar

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def php(code):
    return subprocess.run(['php', '-r', code], capture_output=True, text=True, check=True, cwd=ROOT).stdout


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--devices', type=int, default=40)
    ap.add_argument('--poll', type=float, default=0.4)
    ap.add_argument('--drop', type=float, default=0.15)
    ap.add_argument('--questions', type=int, default=4)
    ap.add_argument('--workers', type=int, default=16)
    args = ap.parse_args()

    cfg = json.loads(php('$c=require ".local/test-config.php"; echo json_encode($c);'))
    if 'smart_clicker_test' not in cfg['dsn']:
        sys.exit('Refusing: test config must point at smart_clicker_test')
    tmp = tempfile.mkdtemp(prefix='clicker-load-')
    os.symlink(os.path.join(ROOT, 'web'), os.path.join(tmp, 'clicker'))
    password = str(secrets.randbelow(90000000) + 10000000)
    username = 'LOAD_' + secrets.token_hex(4)
    php(f'$c=require ".local/test-config.php"; $d=new PDO($c["dsn"],$c["db_user"],$c["db_password"]);'
        f'$d->prepare("INSERT INTO teacher_account(username,password_hash) VALUES(?,?)")->execute(["{username}",password_hash("{password}",PASSWORD_DEFAULT)]);'
        f'file_put_contents("{tmp}/config.php","<?php return ".var_export($c,true).";");')
    port = random.randint(20000, 40000)
    env = dict(os.environ, CLICKER_CONFIG=f'{tmp}/config.php', PHP_CLI_SERVER_WORKERS=str(args.workers))
    server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', tmp], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    time.sleep(0.6)
    url = f'http://127.0.0.1:{port}/clicker/api.php'
    stats = {'lat': [], 'errors': {}, 'requests': 0, 'by': {}}
    lock = threading.Lock()

    def call(body, headers, opener=None, label=None):
        req = urllib.request.Request(url, json.dumps(body).encode(), {'Content-Type': 'application/json', **headers})
        start = time.perf_counter()
        try:
            with (opener.open if opener else urllib.request.urlopen)(req, timeout=5) as r:
                status, data = r.status, json.loads(r.read())
        except urllib.error.HTTPError as e:
            status, data = e.code, json.loads(e.read() or b'{}')
        except Exception as e:  # network-level failure counts as an error, device retries
            status, data = 0, {'code': type(e).__name__}
        ms = (time.perf_counter() - start) * 1000
        with lock:
            stats['requests'] += 1
            # A device's first contact (40 boards powering on together) is reported apart as warm-up.
            if label: stats['by'].setdefault(label, []).append(ms)
            elif body['action'] in ('poll', 'answer', 'login'): stats['lat'].append(ms); stats['by'].setdefault(body['action'], []).append(ms)
            if status >= 500 or status == 0: stats['errors'][data.get('code', status)] = stats['errors'].get(data.get('code', status), 0) + 1
        return status, data

    jar = CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    csrf = {}
    def teacher(action, **data):
        return call({'action': action, **data}, {'X-CSRF-Token': csrf.get('t', '')}, opener)[1]
    def get(action, **q):
        with opener.open(f'{url}?action={action}&' + '&'.join(f'{k}={v}' for k, v in q.items()), timeout=5) as r:
            return json.loads(r.read())

    try:
        csrf['t'] = teacher('teacher_login', username=username, password=password)['csrf']
        cls = f'LOAD{random.randint(1000, 9999)}'
        ids = [f'97{random.randint(10**6, 10**7 - 1)}{i:02d}' for i in range(args.devices)]
        teacher('import_students', students=[{'student_no': str(i + 1), 'student_id': s, 'student_name': f'Load {i + 1}', 'class_id': cls} for i, s in enumerate(ids)])
        exam = '[QUESTIONS]\n' + ''.join(f'\nQ{q}\nCâu {q}?\nA. a\nB. b\nC. c\nD. d\n' for q in range(1, args.questions + 1))
        exam += '\n[ANSWER_KEY]\n' + ''.join(f'Q{q}=A\n' for q in range(1, args.questions + 1))
        exam_id = teacher('import_exam', title=f'Load {cls}', text=exam)['exam_id']
        sid = teacher('create_session', title=f'Load {cls}', class_id=cls, exam_id=exam_id)['session_id']
        teacher('open_login', session_id=sid)
        php('$c=require ".local/test-config.php"; $d=new PDO($c["dsn"],$c["db_user"],$c["db_password"]);'
            f'for($i=0;$i<{args.devices};$i++){{$u=sprintf("LOADDEV-%02d",$i);$k=hash_hmac("sha256",$u,$c["device_key"]);'
            '$d->prepare("INSERT INTO device(device_uid,kind,key_hash) VALUES(?,?,?) ON DUPLICATE KEY UPDATE kind=VALUES(kind),key_hash=VALUES(key_hash),revoked_at=NULL")->execute([$u,"esp",hash("sha256",$k)]);}')

        acked = {}  # (device, question_id) -> answer the device saw ACKed
        burst = threading.Barrier(args.devices)
        stop = threading.Event()

        def device(i):
            uid = f'LOADDEV-{i:02d}'
            key = {'X-Device-Key': hmac.new(cfg['device_key'].encode(), uid.encode(), hashlib.sha256).hexdigest()}
            logged, answered, first, warm = False, set(), True, False
            while not stop.is_set():
                _, p = call({'action': 'poll', 'device_uid': uid}, key, label=None if warm else 'khởi động')
                warm = True
                if p.get('state') == 'LOGIN_OPEN' and not logged:
                    st, r = call({'action': 'login', 'device_uid': uid, 'session_id': sid, 'student_id': ids[i]}, key)
                    logged = r.get('ok', False)
                q = p.get('question')
                if logged and q and q['question_id'] not in answered and not q.get('accepted_answer'):
                    if first:  # Q1: all 40 press OK in the same instant
                        first = False
                        try: burst.wait(timeout=10)
                        except threading.BrokenBarrierError: pass
                    else:
                        time.sleep(random.uniform(0, 1.5))
                    answer = random.choice('ABCD')
                    while not stop.is_set():  # firmware retry loop: same packet until ACK
                        st, r = call({'action': 'answer', 'device_uid': uid, 'session_id': sid, 'question_id': q['question_id'], 'answer': answer}, key)
                        if r.get('type') == 'ANSWER_ACK' and random.random() >= args.drop:
                            with lock: acked[(uid, q['question_id'])] = answer
                            answered.add(q['question_id']); break
                        if 400 <= st < 500 and st not in (408, 429): answered.add(q['question_id']); break
                        time.sleep(0.2)
                time.sleep(args.poll)

        threads = [threading.Thread(target=device, args=(i,), daemon=True) for i in range(args.devices)]
        t0 = time.time()
        for t in threads: t.start()
        deadline = time.time() + 20
        while time.time() < deadline and get('dashboard', session_id=sid)['logged_in'] < args.devices: time.sleep(0.3)
        logged_in = get('dashboard', session_id=sid)['logged_in']
        teacher('start_question', session_id=sid)
        for q in range(args.questions):
            deadline = time.time() + 15
            while time.time() < deadline:
                d = get('dashboard', session_id=sid)
                if d['answered'] >= args.devices: break
                time.sleep(0.3)
            time.sleep(0.5)  # let dropped-ACK retries land on the open question
            teacher('next_question' if q < args.questions - 1 else 'close_question', session_id=sid)
        stop.set()
        for t in threads: t.join(timeout=5)
        elapsed = time.time() - t0

        d = get('dashboard', session_id=sid)
        per_q = {row['question_no']: row['answered'] for row in d['analysis']}
        report = {r['student_id']: r['answers'] for r in d['report']['rows']}
        # every ACK the device saw must be exactly what the database holds
        uid_student = {f'LOADDEV-{i:02d}': s for i, s in enumerate(ids)}
        qno = {q['id']: q['question_no'] for q in d['questions']}
        mismatched = [k for k, a in acked.items() if report[uid_student[k[0]]].get(f'Q{qno[k[1]]}') != a]
        lat = sorted(stats['lat'])
        pct = lambda p: lat[min(len(lat) - 1, int(len(lat) * p))]
        print(f'Thiết bị: {args.devices} · poll {args.poll}s · bỏ ACK {args.drop:.0%} · {args.workers} PHP worker')
        print(f'Đăng nhập: {logged_in}/{args.devices}')
        print(f'Request: {stats["requests"]} trong {elapsed:.1f}s (~{stats["requests"] / elapsed:.0f} req/s)')
        print(f'Độ trễ khi đang dùng: p50 {statistics.median(lat):.1f} ms · p95 {pct(.95):.1f} ms · p99 {pct(.99):.1f} ms · max {lat[-1]:.1f} ms')
        for action, values in sorted(stats['by'].items()):
            values.sort(); print(f'  {action:6} n={len(values):4} p50 {statistics.median(values):6.1f} · p99 {values[min(len(values) - 1, int(len(values) * .99))]:6.1f} · max {values[-1]:6.1f} ms')
        print(f'Lỗi mạng/5xx: {stats["errors"] or 0}')
        print(f'Đáp án lưu mỗi câu: {per_q}')
        print(f'ACK thiết bị nhận: {len(acked)} · khác DB: {len(mismatched)}')
        ok = logged_in == args.devices and all(v == args.devices for v in per_q.values()) and len(per_q) == args.questions and not mismatched and not stats['errors']
        print('KẾT QUẢ:', 'PASS — không mất, không trùng, không sai' if ok else 'FAIL')

        # cleanup this run from the test database
        teacher('remove_session', session_id=sid, confirmed=True)
        teacher('remove_exam', exam_id=exam_id, confirmed=True)
        teacher('remove_class', class_id=cls, confirmed=True)
        php('$c=require ".local/test-config.php"; $db=new PDO($c["dsn"],$c["db_user"],$c["db_password"]); $db->exec("DELETE FROM device WHERE device_uid LIKE \'LOADDEV-%\'");')
        php(f'$c=require ".local/test-config.php"; $db=new PDO($c["dsn"],$c["db_user"],$c["db_password"]); $db->prepare("DELETE FROM teacher_account WHERE username=?")->execute(["{username}"]);')
        return 0 if ok else 1
    finally:
        server.terminate()
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == '__main__':
    sys.exit(main())
