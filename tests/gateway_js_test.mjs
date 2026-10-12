// web/gateway.js without a browser or a board: frame vectors shared with the tag firmware and bridge/ble_bridge.py,
// then the relay logic with a fake server and a fake USB port.
//   node tests/gateway_js_test.mjs
import {createRequire} from 'node:module';
const require = createRequire(import.meta.url);
const {GatewayProtocol: P, GatewayClient} = require('../web/gateway.js');

let failures = 0;
const check = (label, ok, detail = '') => { console.log((ok ? 'PASS ' : 'FAIL ') + label + (ok || !detail ? '' : ' → ' + JSON.stringify(detail))); if (!ok) failures++; };
const hex = s => P.unhex(s.toUpperCase());

// ---- 1. frames: the same vectors as tests/test_ble_bridge.py and tests/ble_test.cpp
const LOGIN = hex('53430102072a701101000800146217'), ANSWER = hex('5343010309000300000015cd5b0743'), HELLO = hex('53430101000102000000030000000144');
check('LOGIN vector', JSON.stringify(P.decodeUplink(LOGIN)) === JSON.stringify({type:2, seq:7, counter:42, session:70000, student:'00146217'}), P.decodeUplink(LOGIN));
check('ANSWER vector', JSON.stringify(P.decodeUplink(ANSWER)) === JSON.stringify({type:3, seq:9, counter:0, session:3, question:123456789, answer:'C'}), P.decodeUplink(ANSWER));
check('HELLO vector', JSON.stringify(P.decodeUplink(HELLO)) === JSON.stringify({type:1, seq:0, counter:1, session:2, question:3, logged:true, answer:'D'}), P.decodeUplink(HELLO));
check('STATE vector', P.hex(P.encodeDownlink(5, 4, 42, 12, [{addr:[0x11, 0x22, 0x33], kind:2, seq:4, value:66}])) === '5343018005000000042A0000000C00112233020442000000000000');
check('khung sai bị bỏ', P.decodeUplink(hex('5858' + '0102072a701101000800146217')) === null && P.decodeUplink(LOGIN.slice(0, 12)) === null
  && P.decodeUplink(Uint8Array.from([...ANSWER.slice(0, 14), 69])) === null && P.decodeUplink(Uint8Array.from([...LOGIN.slice(0, 11), 0xA0, ...LOGIN.slice(12)])) === null);
check('địa chỉ tag: 3 byte thấp, đảo thứ tự', JSON.stringify(P.lowAddress('NRF-AABBCCE1A2B3')) === JSON.stringify([0xB3, 0xA2, 0xE1]));

// ---- 2. relay logic with a fake server
const calls = [], answers = [];
const server = (action, data) => {
  calls.push([action, data]);
  if (action === 'gateway_sync') return Promise.resolve(server.sync);
  const next = answers.shift();
  return next instanceof Error ? Promise.reject(next) : Promise.resolve(next);
};
const fail = (status, code) => Object.assign(new Error(code), {status, code});
server.sync = {ok:true, mode:'ble', session_id:70000, state:'LOGIN_OPEN', question:null, tags:{}};
const changes = [];
const gw = new GatewayClient(server, info => changes.push(info));
gw.port = {}; gw.uid = 'BRIDGE-0000000000AA';
const flush = () => new Promise(r => setTimeout(r, 20));
const TAG = 'NRF-AABBCCE1A2B3', ME = [0xB3, 0xA2, 0xE1];
const slotsOf = frame => [0, 1].map(i => frame.slice(15 + 6 * i, 21 + 6 * i)).filter(s => s[3]);

await gw.sync();
check('sync đặt trạng thái LOGIN_OPEN', gw.state.session === 70000 && gw.state.state === 2);
answers.push({ok:true, type:'LOGIN_OK'});
gw.onAdvert(TAG, LOGIN); gw.onAdvert(TAG, LOGIN);   // same advert twice: one request
await flush();
check('LOGIN gửi 1 request gateway_login đúng MSSV', calls.filter(c => c[0] === 'gateway_login').length === 1
  && calls.at(-1)[1].student_id === '00146217' && calls.at(-1)[1].device_uid === TAG && calls.at(-1)[1].bridge_uid === gw.uid);
let frame = gw.nextFrame(), slot = slotsOf(frame)[0];
check('khung STATE mang LOGIN_OK cho đúng tag', slot && JSON.stringify([...slot]) === JSON.stringify([...ME, 1, 7, 0]) && frame[8] === 2, P.hex(frame));
const before = calls.length;
gw.onAdvert(TAG, LOGIN); await flush();
check('tag gửi lại gói cũ: trả ACK cũ, không gọi server', calls.length === before && gw.replies.get(TAG).remaining === 10);
let repeats = 0; for (let i = 0; i < 30; i++) if (slotsOf(gw.nextFrame()).length) repeats++;
check('mỗi ACK lặp 10 khung rồi thôi', repeats === 10, repeats);

answers.push(fail(409, 'QUESTION_CLOSED'));
gw.onAdvert(TAG, ANSWER); await flush();
slot = slotsOf(gw.nextFrame())[0];
check('lỗi server → ERROR E6 cho tag', slot && slot[3] === 4 && slot[4] === 9 && slot[5] === 6, slot && [...slot]);
answers.push(fail(409, 'MODE_WIFI'));
gw.onAdvert(TAG, Uint8Array.from([...ANSWER.slice(0, 4), 10, ...ANSWER.slice(5)])); await flush();
check('mã lỗi lạ → E8', gw.replies.get(TAG).slot[2] === 8);

answers.push(fail(0, 'NETWORK'), {ok:true, type:'ANSWER_ACK', answer:'C'});
gw.onAdvert(TAG, Uint8Array.from([...ANSWER.slice(0, 4), 11, ...ANSWER.slice(5)]));
await new Promise(r => setTimeout(r, 1000));
check('mất mạng: tự gửi lại, rồi ANSWER_OK "C"', JSON.stringify(gw.replies.get(TAG).slot) === JSON.stringify([2, 11, 67]), gw.replies.get(TAG).slot);

// ---- 3. a rebooted tag is corrected, a tag that agrees is left alone
gw.replies.clear(); gw.order.length = 0;
server.sync = {ok:true, mode:'ble', session_id:2, state:'QUESTION_ACTIVE', question:{question_id:3, question_no:1}, tags:{[TAG]:{logged:true, answer:'D'}}};
gw.onAdvert(TAG, HELLO); await gw.sync();
check('tag đồng ý với server: không gửi STATUS', !gw.replies.has(TAG));
gw.onAdvert(TAG, Uint8Array.from([...HELLO.slice(0, 14), 0, 0])); await gw.sync(Date.now() + 3000);
check('tag khởi động lại: gửi STATUS = đã đăng nhập + đáp án D', JSON.stringify(gw.replies.get(TAG)?.slot) === JSON.stringify([3, 0, 68]), gw.replies.get(TAG));

gw.replies.clear(); gw.order.length = 0;
server.sync = {...server.sync, state:'FINISHED', question:null, tags:{[TAG]:{logged:true, answer:null, score_tenths:67}}};
await gw.sync();
slot = slotsOf(gw.nextFrame())[0];
check('KẾT THÚC: Tag nhận điểm riêng 6.7/10 dù HELLO còn câu cũ', slot && JSON.stringify([...slot]) === JSON.stringify([...ME, 5, 0, 67]), slot && [...slot]);

// ---- 4. Wi-Fi mode and lost server
server.sync = {...server.sync, mode:'wifi'}; await gw.sync();
check('lớp ở Wi-Fi: tag nhận DISABLED (hiện OF)', gw.nextFrame()[8] === P.DISABLED);
const syncOk = server.sync; server.sync = null;
const realRequest = gw.request; gw.request = () => Promise.reject(fail(502, 'DOWN'));
for (let i = 0; i < 3; i++) await gw.sync();
check('máy chủ không trả lời 3 lần: tag nhận OFFLINE (hiện nS)', gw.nextFrame()[8] === P.OFFLINE && changes.at(-1).message.includes('máy chủ'));
gw.request = realRequest; server.sync = syncOk;
gw.request = () => Promise.reject(fail(401, 'UNAUTHORIZED')); await gw.sync();
check('phiên giáo viên hết: báo đăng nhập lại', changes.at(-1).phase === 'error' && /hết/.test(changes.at(-1).message));
gw.request = realRequest;

// ---- 5. serial lines
gw.uid = ''; gw.onLine('HELLO BRIDGE-0011223344AA'); check('nhận HELLO của gateway', gw.uid === 'BRIDGE-0011223344AA');
const n = gw.tags.size; gw.onLine('TAG XYZ 0102'); gw.onLine('GARBAGE'); check('dòng rác bị bỏ', gw.tags.size === n);

console.log(failures ? `\n${failures} FAIL` : '\nALL PASS');
process.exit(failures ? 1 : 0);
