/* Gateway USB: an nRF52840 SuperMini (firmware/ClickerGateway) on the teacher laptop's USB relays the Student Tags'
   BLE adverts to this page over Web Serial; this signed-in tab calls api.php (gateway_sync / gateway_login /
   gateway_answer) and sends each tag's ACK back down. Same frames as the tag firmware
   (firmware/StudentTagNRF52/protocol.h); vectors pinned in tests/gateway_js_test.mjs.

     page -> gateway   HELLO                 gateway answers HELLO BRIDGE-<MAC>
     page -> gateway   FRAME <27-byte hex>   STATE frame advertised to every tag (sent every 120 ms)
     gateway -> page   TAG <MAC> <hex>       one tag advert (HELLO, LOGIN or ANSWER), repeats already filtered

   The gateway falls back to OFFLINE (tags show "nS") when no FRAME arrives for 3 s, so the timers run in a worker
   (gateway-timer.js): Chrome slows the timers of a hidden tab to once a second, then once a minute. */
'use strict';
const GatewayProtocol = (() => {
  const HELLO = 1, LOGIN = 2, ANSWER = 3, STATE = 0x80;
  const STATES = {WAIT_SESSION:0, CREATED:1, LOGIN_OPEN:2, READY:3, QUESTION_ACTIVE:4, FINISHED:5};
  const DISABLED = 6, OFFLINE = 0xFF;
  const KIND = {LOGIN_OK:1, ANSWER_OK:2, STATUS:3, ERROR:4, SCORE:5};
  const ERRORS = {LOGIN_CLOSED:1, STUDENT_NOT_FOUND:2, STUDENT_IN_USE:3, DEVICE_IN_USE:4, LOGIN_REQUIRED:5, QUESTION_CLOSED:6, ANSWER_LOCKED:7};
  const E_REJECTED = 8;
  const u32 = (b, at) => (b[at] | b[at + 1] << 8 | b[at + 2] << 16 | b[at + 3] << 24) >>> 0;

  // Tag advert (bytes after the company ID) -> {type, seq, counter, session, ...}, or null when malformed.
  function decodeUplink(b) {
    if (b.length < 10 || b[0] !== 0x53 || b[1] !== 0x43 || b[2] !== 1) return null;
    const f = {type:b[3], seq:b[4], counter:b[5], session:u32(b, 6)};
    if (f.type === HELLO && b.length >= 16) return {...f, question:u32(b, 10), logged:!!b[14], answer:b[15] ? String.fromCharCode(b[15]) : ''};
    if (f.type === ANSWER && b.length >= 15) return b[14] >= 65 && b[14] <= 68 ? {...f, question:u32(b, 10), answer:String.fromCharCode(b[14])} : null;
    if (f.type !== LOGIN || b.length < 11) return null;
    const n = b[10];
    if (!n || n > 20 || b.length < 11 + Math.ceil(n / 2)) return null;
    let student = '';
    for (let i = 0; i < n; i++) {
      const d = (b[11 + (i >> 1)] >> (i % 2 ? 0 : 4)) & 15;
      if (d > 9) return null;
      student += d;
    }
    return {...f, student};
  }
  // STATE frame (27 bytes, no company ID); slots = up to 2 {addr:[3 bytes, least significant first], kind, seq, value}.
  function encodeDownlink(session, state, question, questionNo, slots = []) {
    const b = new Uint8Array(27), v = new DataView(b.buffer);
    b.set([0x53, 0x43, 1, STATE]);
    v.setUint32(4, session >>> 0, true); b[8] = state; v.setUint32(9, question >>> 0, true); v.setUint16(13, questionNo & 0xFFFF, true);
    slots.slice(0, 2).forEach((s, i) => b.set([...s.addr, s.kind, s.seq, s.value], 15 + 6 * i));
    return b;
  }
  // "NRF-AABBCCDDEEFF" (most significant first, as the gateway prints the MAC) -> its 3 low bytes, least significant first.
  const lowAddress = uid => uid.slice(-6).match(/../g).map(h => parseInt(h, 16)).reverse();
  const hex = bytes => [...bytes].map(n => n.toString(16).padStart(2, '0')).join('').toUpperCase();
  const unhex = text => Uint8Array.from(text.match(/../g).map(h => parseInt(h, 16)));
  return {HELLO, LOGIN, ANSWER, STATES, DISABLED, OFFLINE, KIND, ERRORS, E_REJECTED, decodeUplink, encodeDownlink, lowAddress, hex, unhex};
})();

class GatewayClient {
  static VENDOR = 0x239A;      // Adafruit nRF52 bootloader / core (SuperMini flashed as ItsyBitsy nRF52840)
  static TICK_MS = 120;        // one STATE frame; each carries 2 reply slots
  static SYNC_MS = 1000;       // gateway_sync: room state + heartbeat of the tags heard
  static TAG_ONLINE_MS = 8000;
  static REPEATS = 10;         // adverts per reply; a tag that misses all of them simply sends again

  /* request(action, data) -> Promise of the API reply; rejects with an Error carrying .status and .code.
     onChange({phase, uid, message, tags, relayed}) -> phase: off | connecting | on | error. */
  constructor(request, onChange) {
    this.request = request; this.onChange = onChange;
    this.port = null; this.uid = ''; this.phase = 'off'; this.message = '';
    this.epoch = 0; this.relayed = 0;
    this.reset();
    this.timer = this.makeTimer();
  }
  reset() {
    this.tags = new Map();       // uid -> {seen, hello}
    this.done = new Map();       // job key -> reply slot, so a resent advert gets the same answer without a new request
    this.pending = new Set(); this.queue = []; this.running = 0;
    this.replies = new Map(); this.order = [];
    this.state = {session:0, state:GatewayProtocol.OFFLINE, question:0, questionNo:0, mode:'ble'};
    this.failures = 0; this.syncing = false; this.sending = false; this.text = '';
  }
  static supported() { return 'serial' in navigator && isSecureContext; }

  makeTimer() {
    const handlers = {tick:() => this.tick(), sync:() => this.sync()};
    try {
      const worker = new Worker('gateway-timer.js');
      worker.onmessage = e => handlers[e.data]?.();
      return {start:(name, ms) => worker.postMessage({name, ms}), stop:name => worker.postMessage({name, ms:0})};
    } catch {
      const ids = {};   // no worker: plain timers (fine while the tab stays visible)
      return {start:(name, ms) => { clearInterval(ids[name]); ids[name] = setInterval(handlers[name], ms); }, stop:name => clearInterval(ids[name])};
    }
  }
  set(phase, message) {
    this.phase = phase; this.message = message;
    const now = Date.now(), tags = [...this.tags.values()].filter(t => now - t.seen < GatewayClient.TAG_ONLINE_MS).length;
    this.onChange({phase, uid:this.uid, message, tags, relayed:this.relayed});
  }

  // A port this site was already allowed to open (no prompt): used on page load and when the gateway is plugged in.
  async autoConnect() {
    if (this.port || this.phase === 'connecting' || !GatewayClient.supported()) return false;
    for (const port of await navigator.serial.getPorts()) {
      if (port.getInfo().usbVendorId !== GatewayClient.VENDOR) continue;
      try { await this.connect(port); return true; } catch { /* another board of the same maker */ }
    }
    if (this.phase !== 'error') this.set('off', 'Chưa kết nối. Cắm Gateway vào USB rồi bấm Kết nối Gateway.');
    return false;
  }
  async connectPrompt() {
    if (!isSecureContext) throw new Error('Gateway USB cần trang https:// (hoặc http://localhost trên máy chủ).');
    if (!('serial' in navigator)) throw new Error('Trình duyệt này chưa hỗ trợ Gateway USB. Dùng Chrome hoặc Edge trên máy tính.');
    const port = await navigator.serial.requestPort({filters:[{usbVendorId:GatewayClient.VENDOR}]});
    await this.connect(port);
  }
  async connect(port) {
    if (this.port || this.phase === 'connecting') return;
    this.set('connecting', 'Đang nhận diện Gateway…');
    try {
      try { await port.open({baudRate:115200}); }
      catch (error) { throw new Error('Không mở được cổng USB. Đóng Arduino Serial Monitor hoặc tab Clicker khác đang giữ Gateway rồi thử lại.'); }
      this.port = port; this.uid = '';
      await port.setSignals({dataTerminalReady:true}).catch(() => {});
      this.writer = port.writable.getWriter();
      const hello = new Promise(resolve => { this.helloDone = resolve; });
      this.readTask = this.readLoop(this.epoch);
      for (let i = 0; i < 8 && !this.uid; i++) {
        await this.write('HELLO');
        await Promise.race([hello, new Promise(r => setTimeout(r, 700))]);
      }
      if (!this.uid) throw new Error('Thiết bị USB này không trả lời như Gateway Clicker (kiểm tra đã nạp ClickerGateway chưa).');
      this.timer.start('tick', GatewayClient.TICK_MS); this.timer.start('sync', GatewayClient.SYNC_MS);
      this.set('on', 'Đã kết nối. Giữ tab Clicker này mở trong suốt buổi học.');
      await this.sync();
    } catch (error) {
      await this.disconnect('error', error.message);
      throw error;
    }
  }
  async disconnect(phase = 'off', message = 'Đã ngắt Gateway.') {
    this.epoch++;
    this.timer.stop('tick'); this.timer.stop('sync');
    const port = this.port;
    this.port = null; this.uid = ''; this.helloDone = null;
    try { await this.reader?.cancel(); } catch {}
    try { await this.readTask; } catch {}
    try { this.writer?.releaseLock(); } catch {}
    try { await port?.close(); } catch {}
    this.reader = this.writer = this.readTask = null;
    this.reset();
    this.set(phase, message);
  }
  async write(line) {
    if (!this.writer) throw new Error('Gateway đã ngắt kết nối.');
    await this.writer.write(new TextEncoder().encode(line + '\n'));
  }
  async readLoop(epoch) {
    let lost = 'Mất kết nối Gateway (rút cáp?). Cắm lại là web tự kết nối.';
    try {
      this.reader = this.port.readable.getReader();
      const decoder = new TextDecoder();
      while (this.port && epoch === this.epoch) {
        const {value, done} = await this.reader.read();
        if (done) break;
        this.text += decoder.decode(value, {stream:true});
        if (this.text.length > 8192) this.text = '';
        let end;
        while ((end = this.text.indexOf('\n')) >= 0) {
          const line = this.text.slice(0, end).trim();
          this.text = this.text.slice(end + 1);
          this.onLine(line);
        }
      }
    } catch (error) { lost = 'Mất kết nối Gateway: ' + error.message; }
    finally {
      try { this.reader?.releaseLock(); } catch {}
      this.reader = null;
      if (this.port && epoch === this.epoch) this.disconnect('error', lost);
    }
  }

  onLine(line) {
    const hello = /^HELLO (BRIDGE-[A-F0-9]{12})$/.exec(line);
    if (hello) { this.uid = hello[1]; this.helloDone?.(); return; }
    const m = /^TAG ([A-F0-9]{12}) ([A-F0-9]{20,42})$/.exec(line);
    if (m && this.uid) this.onAdvert('NRF-' + m[1], GatewayProtocol.unhex(m[2]));
  }
  onAdvert(uid, bytes, now = Date.now()) {
    const f = GatewayProtocol.decodeUplink(bytes);
    if (!f) return;
    let tag = this.tags.get(uid);
    if (!tag) {
      if (this.tags.size >= 200) for (const [old, t] of this.tags) if (now - t.seen > GatewayClient.TAG_ONLINE_MS) this.tags.delete(old);
      if (this.tags.size >= 200) return;
      this.tags.set(uid, tag = {seen:now, hello:null, statusAt:0});
    }
    tag.seen = now;
    if (f.type === GatewayProtocol.HELLO) { tag.hello = f; return; }
    if (!f.seq) return;
    const login = f.type === GatewayProtocol.LOGIN;
    const payload = {bridge_uid:this.uid, device_uid:uid, session_id:f.session,
      ...(login ? {student_id:f.student} : {question_id:f.question, answer:f.answer})};
    const key = `${uid}:${f.type}:${f.seq}:${f.session}:${f.question || 0}`;
    if (this.done.has(key)) { this.pushReply(uid, this.done.get(key)); return; }
    if (this.pending.has(key)) return;
    this.pending.add(key);
    this.queue.push({key, uid, seq:f.seq, login, payload});
    this.runJobs();
  }
  runJobs() {
    while (this.running < 4 && this.queue.length && this.port) {
      const job = this.queue.shift(), epoch = this.epoch;
      this.running++;
      this.request(job.login ? 'gateway_login' : 'gateway_answer', job.payload).then(() => {
        if (epoch !== this.epoch) return;
        const K = GatewayProtocol.KIND;
        this.finish(job, job.login ? [K.LOGIN_OK, job.seq, 0] : [K.ANSWER_OK, job.seq, job.payload.answer.charCodeAt(0)]);
        this.relayed++;
      }).catch(error => {
        if (epoch !== this.epoch) return;
        if (error.status === 401) { this.pending.delete(job.key); this.set('error', 'Phiên đăng nhập giáo viên đã hết. Đăng nhập lại để Gateway chạy tiếp.'); return; }
        if (error.status >= 400 && error.status < 500 && ![408, 429].includes(error.status)) {
          this.finish(job, [GatewayProtocol.KIND.ERROR, job.seq, GatewayProtocol.ERRORS[error.code] || GatewayProtocol.E_REJECTED]);
        } else setTimeout(() => {   // network or server hiccup: try the same request again
          if (epoch === this.epoch && this.pending.has(job.key)) { this.queue.push(job); this.runJobs(); }
        }, 800);
      }).finally(() => { if (epoch === this.epoch) { this.running--; this.runJobs(); } });
    }
  }
  finish(job, slot) {
    this.done.set(job.key, slot);
    if (this.done.size > 2000) this.done.delete(this.done.keys().next().value);
    this.pending.delete(job.key);
    this.pushReply(job.uid, slot);
  }
  pushReply(uid, slot) {
    if (!this.replies.has(uid)) this.order.push(uid);
    this.replies.set(uid, {slot, remaining:GatewayClient.REPEATS});
  }

  async sync(now = Date.now()) {
    if (!this.port || !this.uid || this.syncing) return;
    this.syncing = true;
    const epoch = this.epoch;
    try {
      const heard = [...this.tags].filter(([, t]) => now - t.seen < GatewayClient.TAG_ONLINE_MS).map(([uid]) => uid);
      const data = await this.request('gateway_sync', {bridge_uid:this.uid, tags:heard});
      if (epoch !== this.epoch) return;
      this.failures = 0;
      const q = data.question || {};
      this.state = {session:Number(data.session_id) || 0, state:GatewayProtocol.STATES[data.state] ?? 0,
        question:Number(q.question_id) || 0, questionNo:Number(q.question_no) || 0, mode:data.mode};
      // A tag that rebooted or missed its ACK believes something else than the server: send it the truth.
      for (const [uid, tag] of this.tags) {
        if (now - tag.seen > 30000) { this.tags.delete(uid); continue; }
        const truth = data.tags?.[uid], h = tag.hello;
        if (!truth || !h || data.mode !== 'ble' || h.session !== this.state.session
            || [...this.pending].some(key => key.startsWith(uid + ':'))) continue;
        if (data.state === 'FINISHED') {
          if (Number.isInteger(truth.score_tenths) && truth.score_tenths >= 0 && truth.score_tenths <= 100)
            this.pushReply(uid, [GatewayProtocol.KIND.SCORE, 0, truth.score_tenths]);
          continue;
        }
        if (h.question !== this.state.question) continue;
        const value = truth.logged ? (truth.answer ? truth.answer.charCodeAt(0) : 76) : 0;
        const belief = h.logged ? (h.answer ? h.answer.charCodeAt(0) : 76) : 0;
        if (value !== belief && now - tag.statusAt >= 2000) { tag.statusAt = now; this.pushReply(uid, [GatewayProtocol.KIND.STATUS, 0, value]); }
      }
      this.set('on', data.mode === 'ble' ? 'Đã kết nối. Giữ tab Clicker này mở trong suốt buổi học.' : 'Đã kết nối, nhưng lớp đang ở chế độ Wi-Fi: tag hiện OF. Bật BLE ở thanh trên để dùng tag.');
    } catch (error) {
      if (epoch !== this.epoch) return;
      if (++this.failures >= 3) this.state.state = GatewayProtocol.OFFLINE;   // tags show "nS"
      this.set(error.status === 401 ? 'error' : 'on', error.status === 401 ? 'Phiên đăng nhập giáo viên đã hết. Đăng nhập lại để Gateway chạy tiếp.'
        : error.code === 'ROOM_BUSY' ? 'Giáo viên khác đang có phòng chạy trên máy chủ.' : 'Gateway đã cắm, nhưng máy chủ chưa phản hồi: ' + error.message);
    } finally { if (epoch === this.epoch) this.syncing = false; }
  }
  // Next STATE frame: room state plus up to 2 reply slots, rotating through the tags that still have one.
  nextFrame() {
    const s = this.state, slots = [], used = new Set();
    for (let i = 0; i < 2 && this.order.length && !used.has(this.order[0]); i++) {   // never one tag twice in a frame
      const uid = this.order.shift(), reply = this.replies.get(uid);
      if (!reply) continue;
      used.add(uid);
      slots.push({addr:GatewayProtocol.lowAddress(uid), kind:reply.slot[0], seq:reply.slot[1], value:reply.slot[2]});
      if (--reply.remaining) this.order.push(uid); else this.replies.delete(uid);
    }
    const state = s.state === GatewayProtocol.OFFLINE ? s.state : s.mode === 'ble' ? s.state : GatewayProtocol.DISABLED;
    return GatewayProtocol.encodeDownlink(s.session, state, s.question, s.questionNo, slots);
  }
  async tick() {
    if (!this.port || !this.uid || this.sending) return;
    this.sending = true;
    const epoch = this.epoch;
    try { await this.write('FRAME ' + GatewayProtocol.hex(this.nextFrame())); }
    catch (error) { if (epoch === this.epoch) await this.disconnect('error', 'Mất kết nối Gateway: ' + error.message); }
    finally { if (epoch === this.epoch) this.sending = false; }
  }
}
if (typeof module !== 'undefined') module.exports = {GatewayProtocol, GatewayClient};
