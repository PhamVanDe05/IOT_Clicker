'use strict';
// The USB board transports BLE frames. The signed-in teacher tab owns server access.
class GatewayClient {
  constructor(request, status) {
    this.request = request; this.status = status;
    this.tags = new Map(); this.done = new Map(); this.replies = new Map(); this.order = [];
    this.queue = []; this.pending = new Set(); this.running = 0;
    this.state = {session:0, state:255, question:0, questionNo:0, mode:'ble'};
    this.port = null; this.uid = ''; this.connecting = false; this.serialText = '';
    this.tickTimer = null; this.syncTimer = null; this.failures = 0; this.epoch = 0;
  }

  async autoConnect(exclude = null) {
    if (this.port || this.connecting) return;
    if (!isSecureContext) { this.status('Web Serial cần HTTPS; trên laptop chạy server hãy mở http://localhost/clicker/.'); return; }
    if (!('serial' in navigator)) { this.status('Chrome hoặc Edge trên máy tính mới dùng được Gateway USB.'); return; }
    for (const port of await navigator.serial.getPorts()) {
      if (port === exclude || port.getInfo().usbVendorId !== 0x239A) continue;
      try { await this.connect(port); return; } catch { /* Another permitted serial device. */ }
    }
    this.status('Chưa kết nối. Cắm Gateway và bấm Kết nối Gateway.');
  }

  async connectPrompt() {
    if (this.port || this.connecting) return;
    if (!isSecureContext) throw new Error('Web Serial cần HTTPS; trên laptop chạy server hãy mở http://localhost/clicker/.');
    if (!('serial' in navigator)) throw new Error('Trình duyệt không hỗ trợ Web Serial. Dùng Chrome hoặc Edge trên máy tính.');
    const port = await navigator.serial.requestPort({filters:[{usbVendorId:0x239A}]});
    try { await this.connect(port); }
    catch (error) {
      await this.autoConnect(port);
      if (!this.uid) throw error;
    }
  }

  async connect(port) {
    if (this.port || this.connecting) return;
    this.connecting = true;
    this.status('Đang nhận diện Gateway…');
    try {
      try { await port.open({baudRate:115200}); }
      catch (error) { throw new Error('Không mở được cổng USB. Đóng Serial Monitor Arduino và tab web khác đang dùng Gateway, rồi kết nối lại. ' + error.message); }
      this.port = port;
      await port.setSignals({dataTerminalReady:true});
      this.writer = port.writable.getWriter();
      this.hello = new Promise((resolve, reject) => { this.helloResolve = resolve; this.helloReject = reject; });
      this.readTask = this.readLoop();
      for (let attempt = 0; attempt < 8 && !this.uid; attempt++) {
        await this.write('HELLO');
        await Promise.race([this.hello, new Promise(resolve => setTimeout(resolve, 1000))]);
      }
      if (!this.uid) throw new Error('Cổng USB không trả lời như Gateway Clicker.');
      this.status(`Đã kết nối ${this.uid}`);
      this.tickTimer = setInterval(() => this.tick(), 120);
      this.syncTimer = setInterval(() => this.sync(), 1000);
      await this.sync();
    } catch (error) {
      await this.disconnect();
      this.status(error.message);
      throw error;
    } finally { this.connecting = false; }
  }

  async disconnect(message = 'Gateway chưa kết nối.') {
    this.epoch++;
    clearInterval(this.tickTimer); clearInterval(this.syncTimer);
    this.tickTimer = this.syncTimer = null;
    const port = this.port;
    this.port = null; this.uid = '';
    this.helloReject?.(new Error('Gateway đã ngắt kết nối.'));
    this.helloResolve = this.helloReject = null;
    try { await this.reader?.cancel(); } catch {}
    try { await this.readTask; } catch {}
    try { this.writer?.releaseLock(); await port?.close(); } catch {}
    this.reader = this.writer = this.readTask = null;
    this.queue.length = 0; this.pending.clear(); this.done.clear(); this.tags.clear();
    this.replies.clear(); this.order.length = 0;
    this.running = 0; this.syncing = false; this.sending = false;
    this.serialText = ''; this.failures = 0;
    this.state.state = 255;
    this.status(message);
  }

  async write(line) {
    if (!this.writer) throw new Error('Gateway đã ngắt kết nối.');
    await this.writer.write(new TextEncoder().encode(line + '\n'));
  }

  async readLoop() {
    let failure = 'Mất kết nối Gateway. Cắm lại để tự kết nối.';
    try {
      this.reader = this.port.readable.getReader();
      const decoder = new TextDecoder();
      while (this.port) {
        const {value, done} = await this.reader.read();
        if (done) break;
        this.serialText += decoder.decode(value, {stream:true});
        if (this.serialText.length > 4096) this.serialText = '';
        let end;
        while ((end = this.serialText.indexOf('\n')) >= 0) {
          const line = this.serialText.slice(0, end).trim();
          this.serialText = this.serialText.slice(end + 1);
          this.onLine(line);
        }
      }
    } catch (error) {
      failure = 'Mất kết nối Gateway: ' + error.message;
    } finally {
      try { this.reader?.releaseLock(); } catch {}
      this.reader = null;
      if (this.port) this.disconnect(failure);
    }
  }

  onLine(line) {
    const hello = /^HELLO (BRIDGE-[A-F0-9]{12})$/.exec(line);
    if (hello) { this.uid = hello[1]; this.helloResolve?.(); this.helloResolve = this.helloReject = null; return; }
    const tag = /^TAG ([A-F0-9]{12}) ([A-F0-9]{20,42})$/.exec(line);
    if (!tag || !this.uid) return;
    const bytes = Uint8Array.from(tag[2].match(/../g).map(n => parseInt(n, 16)));
    if (bytes[0] !== 83 || bytes[1] !== 67 || bytes[2] !== 1) return;
    const type = bytes[3], seq = bytes[4], session = new DataView(bytes.buffer).getUint32(6, true);
    const uid = 'NRF-' + tag[1], now = Date.now();
    let item = this.tags.get(uid);
    if (!item) {
      if (this.tags.size >= 200) for (const [oldUid, old] of this.tags) if (now - old.seen > 8000) this.tags.delete(oldUid);
      if (this.tags.size >= 200) return;
      item = {seen:now, hello:null, statusAt:0}; this.tags.set(uid, item);
    }
    item.seen = now;
    if (type === 1 && bytes.length >= 16) {
      item.hello = {session, question:new DataView(bytes.buffer).getUint32(10, true), logged:!!bytes[14], answer:bytes[15]};
      return;
    }
    if (!seq || ![2, 3].includes(type)) return;
    let payload = {bridge_uid:this.uid, device_uid:uid, session_id:session};
    if (type === 2) {
      if (bytes.length < 11 || !bytes[10] || bytes[10] > 20 || bytes.length < 11 + Math.ceil(bytes[10] / 2)) return;
      let student = '';
      for (let i = 0; i < bytes[10]; i++) {
        const digit = (bytes[11 + (i >> 1)] >> (i % 2 ? 0 : 4)) & 15;
        if (digit > 9) return;
        student += digit;
      }
      payload.student_id = student;
    } else {
      if (bytes.length < 15 || bytes[14] < 65 || bytes[14] > 68) return;
      payload.question_id = new DataView(bytes.buffer).getUint32(10, true);
      payload.answer = String.fromCharCode(bytes[14]);
    }
    const key = `${uid}:${type}:${seq}:${session}:${payload.question_id || 0}`;
    if (this.done.has(key)) { this.pushReply(uid, this.done.get(key)); return; }
    if (this.pending.has(key)) return;
    this.pending.add(key);
    this.queue.push({key, uid, seq, type, payload});
    this.runJobs();
  }

  runJobs() {
    while (this.running < 4 && this.queue.length && this.port) {
      const job = this.queue.shift();
      const epoch = this.epoch;
      this.running++;
      this.request(job.type === 2 ? 'gateway_login' : 'gateway_answer', job.payload).then(result => {
        if (epoch !== this.epoch || this.uid !== job.payload.bridge_uid) return;
        if (result.type !== (job.type === 2 ? 'LOGIN_OK' : 'ANSWER_ACK')) throw new Error('Phản hồi server không hợp lệ.');
        const slot = [job.type === 2 ? 1 : 2, job.seq, job.type === 2 ? 0 : job.payload.answer.charCodeAt(0)];
        this.done.set(job.key, slot); this.pushReply(job.uid, slot);
        this.pending.delete(job.key);
      }).catch(error => {
        if (epoch !== this.epoch || this.uid !== job.payload.bridge_uid) return;
        if (error.status >= 400 && error.status < 500 && ![408, 429].includes(error.status)) {
          if (error.status === 401) { this.pending.delete(job.key); this.status('Phiên giáo viên hết hạn. Đăng nhập lại để Gateway hoạt động.'); return; }
          const codes = {LOGIN_CLOSED:1, STUDENT_NOT_FOUND:2, STUDENT_IN_USE:3, DEVICE_IN_USE:4,
            LOGIN_REQUIRED:5, QUESTION_CLOSED:6, ANSWER_LOCKED:7};
          const slot = [4, job.seq, codes[error.code] || 8];
          this.done.set(job.key, slot); this.pushReply(job.uid, slot); this.pending.delete(job.key);
        } else setTimeout(() => { if (epoch === this.epoch && this.port && this.pending.has(job.key)) { this.queue.push(job); this.runJobs(); } }, 800);
      }).finally(() => { if (epoch === this.epoch) { this.running--; this.runJobs(); } });
    }
  }

  pushReply(uid, slot) {
    if (!this.replies.has(uid)) this.order.push(uid);
    this.replies.set(uid, {slot, remaining:10});
  }

  async sync() {
    if (!this.port || !this.uid || this.syncing) return;
    this.syncing = true;
    const epoch = this.epoch;
    try {
      const now = Date.now();
      const tags = [...this.tags].filter(([, item]) => now - item.seen < 8000).map(([uid]) => uid);
      const data = await this.request('gateway_sync', {bridge_uid:this.uid, tags});
      if (epoch !== this.epoch) return;
      this.failures = 0;
      this.status(`Đã kết nối ${this.uid}`);
      const q = data.question || {};
      const states = {WAIT_SESSION:0, CREATED:1, LOGIN_OPEN:2, READY:3, QUESTION_ACTIVE:4, FINISHED:5};
      this.state = {session:data.session_id || 0, state:states[data.state] ?? 0,
        question:q.question_id || 0, questionNo:q.question_no || 0, mode:data.mode};
      for (const [uid, item] of this.tags) {
        if (now - item.seen > 30000) { this.tags.delete(uid); continue; }
        const truth = data.tags?.[uid], hello = item.hello;
        if (!truth || !hello || this.state.mode !== 'ble' || [...this.pending].some(key => key.startsWith(uid + ':')) ||
            hello.session !== this.state.session || hello.question !== this.state.question) continue;
        const value = truth.logged ? (truth.answer ? truth.answer.charCodeAt(0) : 76) : 0;
        const belief = hello.logged ? (hello.answer || 76) : 0;
        if (value !== belief && now - item.statusAt >= 2000) {
          item.statusAt = now; this.pushReply(uid, [3, 0, value]);
        }
      }
    } catch (error) {
      if (epoch !== this.epoch) return;
      if (++this.failures >= 3) this.state.state = 255;
      this.status('Gateway nối USB; server không phản hồi: ' + error.message);
    } finally { if (epoch === this.epoch) this.syncing = false; }
  }

  async tick() {
    if (!this.port || !this.uid || this.sending) return;
    this.sending = true;
    const epoch = this.epoch;
    try {
      const bytes = new Uint8Array(27), view = new DataView(bytes.buffer);
      bytes.set([83, 67, 1, 128]);
      view.setUint32(4, this.state.session, true);
      bytes[8] = this.state.state === 255 ? 255 : this.state.mode === 'ble' ? this.state.state : 6;
      view.setUint32(9, this.state.question, true);
      view.setUint16(13, this.state.questionNo, true);
      for (let i = 0; i < 2 && this.order.length; i++) {
        const uid = this.order.shift(), reply = this.replies.get(uid), offset = 15 + 6 * i;
        if (!reply) continue;
        const mac = uid.slice(4).match(/../g).map(n => parseInt(n, 16));
        bytes.set([mac[5], mac[4], mac[3], ...reply.slot], offset);
        if (--reply.remaining) this.order.push(uid); else this.replies.delete(uid);
      }
      await this.write('FRAME ' + [...bytes].map(n => n.toString(16).padStart(2, '0')).join('').toUpperCase());
    } catch (error) { if (epoch === this.epoch) { this.status('Mất kết nối Gateway: ' + error.message); await this.disconnect(); } }
    finally { if (epoch === this.epoch) this.sending = false; }
  }
}
