#pragma once
// Tag logic in plain C++ (no Arduino calls): 7-segment glyphs, power-up effect, key debounce, what the tag shows and sends.
// tests/ble_test.cpp and tests/board_test.cpp compile it on the PC.
#include <stddef.h>
#include "config.h"
#include "protocol.h"

// Two characters: tens (left digit, U1) and units (right digit, U2). ' ' is a dark digit.
namespace seg {

// Segment bits a..g = bit 0..6, 1 = lit (74HC595 QA..QG).
inline uint8_t glyph(char c) {
    switch (c) {
        case '0': case 'O': return 0x3F; case '1': return 0x06; case '2': return 0x5B; case '3': return 0x4F;
        case '4': return 0x66; case '5': case 'S': return 0x6D; case '6': return 0x7D; case '7': return 0x07;
        case '8': return 0x7F; case '9': return 0x6F; case 'A': return 0x77; case 'b': case 'B': return 0x7C;
        case 'C': return 0x39; case 'd': case 'D': return 0x5E; case 'E': return 0x79; case 'F': return 0x71;
        case 'H': return 0x76; case 'i': return 0x04; case 'L': return 0x38; case 'n': return 0x54;
        case 'o': return 0x5C; case 'r': return 0x50; case '-': return 0x40; case '=': return 0x48;
        default: return 0x00;
    }
}

// 16 bits shifted MSB first: bits 15..8 pass through U1 into U2 (units), bits 7..0 stay in U1 (tens).
// Common anode: a segment lights when its output is LOW, so each byte is inverted. QH (no decimal point) stays HIGH.
inline uint16_t word(uint8_t tensBits, uint8_t unitsBits) { return uint16_t(uint8_t(~unitsBits) << 8 | uint8_t(~tensBits)); }
inline uint16_t word(char tens, char units) { return word(glyph(tens), glyph(units)); }
const uint16_t ALL_ON = 0x8080;    // every segment lit (lamp test)
const uint16_t ALL_OFF = 0xFFFF;

// Last two digits of n; the tens digit is dark below 10 (question 1 shows " 1").
inline char tensOf(unsigned n) { n %= 100; return n < 10 ? ' ' : char('0' + n / 10); }
inline char unitsOf(unsigned n) { return char('0' + n % 10); }

// Score out of 10 given in tenths: a whole score shows as a number (10 -> "10", 5.0 -> " 5"),
// otherwise the two digits of the tenths (9.75 -> 97 -> "97", 0.5 -> "05").
inline char scoreTens(unsigned tenths) { return tenths % 10 ? char('0' + tenths / 10 % 10) : tensOf(tenths / 10); }
inline char scoreUnits(unsigned tenths) { return tenths % 10 ? char('0' + tenths % 10) : unitsOf(tenths / 10); }

}  // namespace seg

// A timer keeps BLE scanning and advertising throughout the power-up effect.
struct StartupAnimation {
    unsigned long startedAt = 0;
    uint8_t step = 8 * STARTUP_ROUNDS;
    bool running = false;

    void begin(unsigned long now) { startedAt = now; step = 8 * STARTUP_ROUNDS; running = true; }

    bool update(unsigned long now) {   // true once at the start of each round: buzz
        if (!running) return false;
        const unsigned long next = (now - startedAt) / STARTUP_STEP_MS;
        if (next >= 8 * STARTUP_ROUNDS) { running = false; return false; }
        if (next == step) return false;
        step = uint8_t(next);
        return step % 8 == 0;
    }

    uint16_t word() const {
        const uint8_t tens[] =  {1, 0, 0, 0, 0, 8, 16, 32};   // a2 a1 b1 c1 d1 d2 e2 f2; digit 2 = left
        const uint8_t units[] = {0, 1, 2, 4, 8, 0, 0, 0};
        return running ? seg::word(tens[step % 8], units[step % 8]) : seg::ALL_OFF;
    }
};

// ====================================================================================== Keys
namespace keys {

inline uint16_t mask(int row, int col) { return uint16_t(1u << (row * 4 + col)); }   // pressed-key mask bit
inline char at(int index) { return KEYMAP[index / 4][index % 4]; }

// A key counts once, after the raw mask has been stable for DEBOUNCE_MS. Returns the newly pressed keys.
struct Debouncer {
    uint16_t raw = 0, down = 0;
    unsigned long changedAt = 0;
    uint16_t update(uint16_t nowRaw, unsigned long now) {
        if (nowRaw != raw) { raw = nowRaw; changedAt = now; }
        if (now - changedAt < DEBOUNCE_MS || raw == down) return 0;
        const uint16_t pressed = uint16_t(raw & ~down);
        down = raw;
        return pressed;
    }
};

}  // namespace keys

// ====================================================================================== Tag logic
// What the student sees and sends; no I/O. The sketch feeds it STATE frames and key presses, then shows screen(),
// advertises uplink() and runs the motor when `vibrate` is set.
struct Screen { char tens, units; bool blink; };   // blink: a request is waiting for its ACK

struct Tag {
    uint8_t addr[3] = {0};   // 3 low bytes of the own BLE address
    uint32_t session = 0, question = 0;
    uint16_t questionNo = 0;
    uint8_t state = ble::OFFLINE;
    bool heard = false, logged = false, accepted = false, awake = true, pending = false;
    bool vibrate = false;    // one light buzz requested; the sketch clears it
    char selected = 0;       // 'A'-'D'
    bool hasScore = false;
    uint8_t score = 0;       // score out of 10, in tenths cut down (9.75 -> 97), 0..100
    unsigned long scoreAt = 0, finishedAt = 0;
    char input[ble::MAX_DIGITS + 1] = {0};
    uint8_t inputLen = 0, seq = 0;
    ble::Uplink request;     // sent until its ACK or ERROR arrives
    char notice[2] = {0};
    unsigned long lastHeard = 0, noticeAt = 0, activeAt = 0;

    void wake(unsigned long now) { awake = true; activeAt = now; }   // display on, sleep timer restarted

    // ------------------------------------------------------------------ from the gateway
    void onDownlink(const ble::Downlink &d, unsigned long now) {
        heard = true; lastHeard = now;
        if (d.session != session || (state == ble::FINISHED && d.state != ble::FINISHED)) clearScore(now);
        if (d.session != session) { logged = false; accepted = false; selected = 0; clearInput(); wake(now); }
        if (d.state == ble::FINISHED && state != ble::FINISHED) finishedAt = now;
        if (d.question != question || d.state != state) {
            if (!accepted || d.question != question) { selected = 0; accepted = false; }
            if (d.state == ble::QUESTION_ACTIVE && d.question != question && logged) vibrate = true;   // new question
            wake(now);
        }
        session = d.session; question = d.question; questionNo = d.questionNo; state = d.state;
        for (const ble::Slot &s : d.slot)
            if (s.kind != ble::NONE && memcmp(s.addr, addr, 3) == 0) onSlot(s, now);
    }

    void onSlot(const ble::Slot &s, unsigned long now) {
        if (s.kind == ble::SCORE) {
            if (state != ble::FINISHED || !logged || s.seq != 0) return;
            if (s.value == 255) clearScore(now);
            else if (s.value <= 100 && (!hasScore || score != s.value)) {
                score = s.value; hasScore = true; scoreAt = now; wake(now);
            }
            return;   // a score never acknowledges a pending LOGIN or ANSWER
        }
        if (s.kind == ble::STATUS) {   // server truth for this tag; never overrides a request still waiting
            if (pending) return;
            logged = s.value != 0;
            if (!logged) clearScore(now);
            if (s.value >= 'A' && s.value <= 'D') { selected = char(s.value); accepted = true; }
            else if (accepted) { selected = 0; accepted = false; }
            return;
        }
        if (!pending || s.seq != request.seq) return;
        if (s.kind == ble::LOGIN_OK && request.type == ble::LOGIN) {
            if (request.session == session) logged = true;
            clearInput(); showNotice('H', 'i', now);
        } else if (s.kind == ble::ANSWER_OK && request.type == ble::ANSWER && s.value == request.answer) {
            if (request.session == session && request.question == question) { selected = char(s.value); accepted = true; }
            wake(now);
        } else if (s.kind == ble::ERROR) {
            if (s.value == ble::E_LOGIN_REQUIRED) logged = false;
            if (request.type == ble::ANSWER && !accepted) selected = 0;
            showNotice('E', char('0' + s.value % 10), now);
        } else return;
        pending = false;
    }

    // ------------------------------------------------------------------ keys
    // False when the press only woke the display or a request is still being sent.
    bool press(char key, unsigned long now) {
        const bool wasAwake = awake;
        wake(now);
        if (!wasAwake || pending) return false;
        if (key == KEY_OK) confirm();
        else if (key == KEY_BACK) backspace();
        else digit(key);
        return true;
    }

    bool canLogin() const { return !logged && state == ble::LOGIN_OPEN; }
    bool canAnswer() const { return logged && state == ble::QUESTION_ACTIVE && question && !accepted; }

    void digit(char d) {
        if (d < '0' || d > '9') return;
        if (canLogin() && inputLen < ble::MAX_DIGITS) { input[inputLen++] = d; input[inputLen] = 0; }
        else if (canAnswer() && d >= '1' && d <= '4') selected = char('A' + d - '1');
    }

    void backspace() {
        if (canLogin() && inputLen) input[--inputLen] = 0;
        else if (canAnswer()) selected = 0;
    }

    void confirm() {
        ble::Uplink u;
        u.session = session;
        if (canLogin() && inputLen) { u.type = ble::LOGIN; memcpy(u.student, input, inputLen + 1); }
        else if (canAnswer() && selected) { u.type = ble::ANSWER; u.question = question; u.answer = uint8_t(selected); }
        else return;
        seq = uint8_t(seq == 255 ? 1 : seq + 1);   // 0 is reserved for STATUS
        u.seq = seq; request = u; pending = true;
        vibrate = true;
    }

    // ------------------------------------------------------------------ to the gateway
    ble::Uplink uplink() const {   // the request until its ACK, else a HELLO heartbeat
        if (pending) return request;
        ble::Uplink u;
        u.session = session; u.question = question;
        u.logged = uint8_t((logged ? 1 : 0) | (hasScore ? 2 : 0));
        u.answer = hasScore ? score : (accepted ? uint8_t(selected) : 0);
        return u;
    }

    // ------------------------------------------------------------------ radio duty
    // How often to listen (0.625 ms units): fast only while it matters, slow when the student cannot see the result.
    uint16_t scanInterval(unsigned long now) const {
        if (pending) return SCAN_ACK;
        if (awake) return SCAN_AWAKE;
        const bool session = heard && !gatewayLost(now) && state != ble::OFFLINE && state != ble::DISABLED &&
                             state != ble::FINISHED && state != ble::WAIT_SESSION;
        return session ? SCAN_ASLEEP : SCAN_IDLE;
    }
    unsigned long helloPeriod() const { return pending ? RETRY_MS : awake ? HELLO_MS : HELLO_SLEEP_MS; }

    // ------------------------------------------------------------------ screen
    bool gatewayLost(unsigned long now) const { return heard && now - lastHeard > GATEWAY_TIMEOUT_MS; }

    Screen screen(unsigned long now) {
        if (!pending && now - activeAt > DISPLAY_SLEEP_MS) awake = false;
        if (!awake) return {' ', ' ', false};
        if (notice[0] && now - noticeAt < NOTICE_MS) return {notice[0], notice[1], false};
        if (pending && request.type == ble::LOGIN) return lastTwo(request.student, true);
        if (pending) return {' ', char(request.answer), true};
        if (!heard) return {'-', '-', false};               // just powered on, gateway not heard yet
        if (gatewayLost(now)) return {'n', 'C', false};
        if (state == ble::OFFLINE) return {'n', 'S', false};
        if (state == ble::DISABLED) return {'O', 'F', false};
        if (state == ble::FINISHED) return finished(now);
        if (state == ble::WAIT_SESSION || state == ble::CREATED) return {'-', '-', false};
        if (!logged) {
            if (state != ble::LOGIN_OPEN) return {'L', 'C', false};
            return inputLen ? lastTwo(input, false) : Screen{'L', 'o', false};
        }
        if (state != ble::QUESTION_ACTIVE || !question) return {'r', 'd', false};
        if (accepted) return {selected, '=', false};
        if (selected) return {' ', selected, false};
        return {seg::tensOf(questionNo), seg::unitsOf(questionNo), false};
    }

    // Score for SCORE_SHOW_MS, "En" for END_SHOW_MS, then the display sleeps. A key pressed afterwards shows "En".
    Screen finished(unsigned long now) {
        const unsigned long start = hasScore ? scoreAt : finishedAt;
        const unsigned long scoreEnd = start + (hasScore ? SCORE_SHOW_MS : 0);
        const unsigned long end = scoreEnd + (hasScore || !logged ? END_SHOW_MS : SCORE_WAIT_MS);
        if (activeAt <= end) {
            if (now < scoreEnd) return {seg::scoreTens(score), seg::scoreUnits(score), false};
            if (now >= end) { awake = false; return {' ', ' ', false}; }
        }
        return {'E', 'n', false};
    }

  private:
    void clearScore(unsigned long now) { if (hasScore) { hasScore = false; wake(now); } }
    void clearInput() { inputLen = 0; input[0] = 0; }
    void showNotice(char a, char b, unsigned long now) { notice[0] = a; notice[1] = b; noticeAt = now; wake(now); }
    static Screen lastTwo(const char *s, bool blink) {
        const size_t n = strlen(s);
        return {n >= 2 ? s[n - 2] : ' ', n ? s[n - 1] : ' ', blink};
    }
};

// Character for the single digit at time `now` (ONE_DIGIT mode); ' ' = dark.
inline char oneDigit(const Screen &s, unsigned long now) {
    if (s.tens == ' ' || s.units == ' ') {                 // one character
        const char c = s.tens == ' ' ? s.units : s.tens;
        return s.blink && (now / BLINK_MS) % 2 ? ' ' : c;
    }
    const unsigned long t = now % (2 * (CHAR_MS + GAP_MS));
    if (t < CHAR_MS) return s.tens;
    if (t < CHAR_MS + GAP_MS) return ' ';
    if (t < 2 * CHAR_MS + GAP_MS) return s.units;
    return ' ';
}
