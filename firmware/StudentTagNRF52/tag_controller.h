#pragma once
// Tag logic without Arduino dependencies so tests/ble_test.cpp can run it on the PC.
#include <stdio.h>
#include "protocol.h"

const unsigned long BRIDGE_TIMEOUT_MS = 5000, NOTICE_MS = 1500, SCORE_MS = 5000, DISPLAY_SLEEP_MS = 30000;

// Two characters for the 7-segment pair; blink=true means "sending, waiting for ACK".
struct Screen { char left, right; bool blink; };

struct TagController {
    uint8_t addr[3] = {0};                   // 3 low bytes of own BLE address
    uint32_t session = 0, question = 0;
    uint16_t questionNo = 0;
    uint8_t state = ble::OFFLINE;
    bool heard = false, logged = false, accepted = false, awake = true, hasScore = false;
    uint8_t scoreTenths = 0;
    char scoreText[20] = {0};
    char selected = 0, input[ble::MAX_DIGITS + 1] = {0};
    uint8_t inputLen = 0, seq = 0, vibrate = 0;  // vibrate: pulses requested, cleared by the sketch
    bool pending = false;
    ble::Uplink request;
    char notice[3] = {0};
    unsigned long lastHeard = 0, noticeAt = 0, scoreAt = 0, activeAt = 0;

    void clearInput() { inputLen = 0; input[0] = 0; }
    void wake(unsigned long now) { awake = true; activeAt = now; }
    void show(const char *text, uint8_t pulses, unsigned long now) {
        notice[0] = text[0]; notice[1] = text[1]; noticeAt = now; vibrate = pulses; wake(now);
    }

    void onDownlink(const ble::Downlink &d, unsigned long now) {
        heard = true; lastHeard = now;
        if (d.session != session) { logged = false; accepted = false; selected = 0; hasScore = false; scoreText[0] = 0; clearInput(); wake(now); }
        if (d.question != question || d.state != state) {
            if (!accepted || d.question != question) { selected = 0; accepted = false; }
            if (d.state == ble::QUESTION_ACTIVE && d.question != question && logged) vibrate = 3;
            wake(now);
        }
        session = d.session; question = d.question; questionNo = d.questionNo; state = d.state;
        for (int i = 0; i < 2; ++i)
            if (d.slot[i].kind != ble::NONE && memcmp(d.slot[i].addr, addr, 3) == 0) onSlot(d.slot[i], now);
    }

    void onSlot(const ble::Slot &s, unsigned long now) {
        if (s.kind == ble::SCORE) {
            if (state != ble::FINISHED || s.value > 100) return;
            pending = false; logged = true;
            if (!hasScore || scoreTenths != s.value) {
                scoreTenths = s.value; hasScore = true; scoreAt = now;
                snprintf(scoreText, sizeof scoreText, "Diem %u.%u/10", s.value / 10, s.value % 10);
                wake(now);
            }
            return;
        }
        if (s.kind == ble::STATUS) {
            // Server truth for this tag in the current session; never overrides an unacknowledged request.
            if (pending) return;
            logged = s.value != 0;
            if (s.value >= 'A' && s.value <= 'D') { selected = char(s.value); accepted = true; }
            else if (accepted) { selected = 0; accepted = false; }
            return;
        }
        if (!pending || s.seq != request.seq) return;
        if (s.kind == ble::LOGIN_OK && request.type == ble::LOGIN) {
            if (request.session == session) logged = true;
            clearInput(); show("Hi", 1, now);
        } else if (s.kind == ble::ANSWER_OK && request.type == ble::ANSWER && s.value == request.answer) {
            if (request.session == session && request.question == question) { selected = char(s.value); accepted = true; }
            vibrate = 1; wake(now);
        } else if (s.kind == ble::ERROR) {
            if (s.value == ble::E_LOGIN_REQUIRED) logged = false;
            if (request.type == ble::ANSWER && !accepted) selected = 0;
            const char text[3] = {'E', char('0' + (s.value % 10)), 0};
            show(text, 2, now);
        } else return;
        pending = false;
    }

    // Returns false when the press only woke the display.
    bool press(char key, unsigned long now) {
        const bool wasAwake = awake;
        wake(now);
        if (!wasAwake || pending) return false;
        if (key == 'C') confirm();
        else if (key == 'B') backspace();
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
        seq = uint8_t(seq == 255 ? 1 : seq + 1);  // seq 0 is reserved for STATUS
        u.seq = seq; request = u; pending = true;
    }

    // Frame to advertise now: the pending request until ACK/ERROR, else a HELLO heartbeat.
    ble::Uplink uplink() const {
        if (pending) return request;
        ble::Uplink u;
        u.session = session; u.question = question; u.logged = logged;
        u.answer = accepted ? uint8_t(selected) : 0;
        return u;
    }

    bool bridgeLost(unsigned long now) const { return !heard || now - lastHeard > BRIDGE_TIMEOUT_MS; }

    Screen screen(unsigned long now) {
        if (!pending && now - activeAt > DISPLAY_SLEEP_MS) awake = false;
        if (!awake) return {' ', ' ', false};
        if (notice[0] && now - noticeAt < NOTICE_MS) return {notice[0], notice[1], false};
        if (pending && request.type == ble::LOGIN) return lastDigits(true);
        if (pending) return {char(request.answer), '-', true};
        if (bridgeLost(now)) return {'n', 'C', false};
        if (state == ble::OFFLINE) return {'n', 'S', false};
        if (state == ble::DISABLED) return {'O', 'F', false};
        if (state == ble::FINISHED) {
            if (hasScore && now - scoreAt < SCORE_MS) { const uint8_t rounded = uint8_t((scoreTenths + 5) / 10); return {rounded == 10 ? '1' : ' ', char('0' + rounded % 10), false}; }
            return {'E', 'n', false};
        }
        if (state == ble::WAIT_SESSION || state == ble::CREATED) return {'-', '-', false};
        if (!logged) {
            if (state != ble::LOGIN_OPEN) return {'L', 'C', false};
            return inputLen ? lastDigits(false) : Screen{'L', 'o', false};
        }
        if (state != ble::QUESTION_ACTIVE || !question) return {'r', 'd', false};
        if (accepted) return {selected, '=', false};
        if (selected) return {selected, '-', false};
        return {char('0' + questionNo / 10 % 10), char('0' + questionNo % 10), false};
    }

    // One-line explanation of screen() for a text display (OLED); same priority order, ASCII only.
    const char *statusText(unsigned long now) const {
        static const char *const ERRORS[] = {"Loi", "Chua mo dang nhap", "Sai MSSV", "MSSV dang dung",
            "May dang dung", "Can dang nhap", "Cau da dong", "Da khoa dap an", "Bi tu choi", "Loi bridge"};
        if (!awake) return "";
        if (notice[0] && now - noticeAt < NOTICE_MS)
            return notice[0] == 'E' ? ERRORS[(notice[1] - '0') % 10] : "Da vao phong";
        if (pending) return request.type == ble::LOGIN ? "Dang gui MSSV..." : "Dang gui dap an...";
        if (bridgeLost(now)) return "Mat ket noi laptop";
        if (state == ble::OFFLINE) return "Server offline";
        if (state == ble::DISABLED) return "Web dang o Wi-Fi";
        if (state == ble::FINISHED) return hasScore && now - scoreAt < SCORE_MS ? scoreText : "Ket thuc";
        if (state == ble::WAIT_SESSION || state == ble::CREATED) return "Cho mo dang nhap";
        if (!logged) return state == ble::LOGIN_OPEN ? "Nhap MSSV + OK" : "Het gio dang nhap";
        if (state != ble::QUESTION_ACTIVE || !question) return "Cho cau hoi";
        if (accepted) return "Da gui dap an";
        return selected ? "Bam OK de gui" : "Chon 1-4 (A-D)";
    }

    Screen lastDigits(bool blink) const {
        const char *s = pending ? request.student : input;
        const uint8_t n = uint8_t(strlen(s));
        return {n >= 2 ? s[n - 2] : ' ', n ? s[n - 1] : ' ', blink};
    }
};

// Segment bits for a common-cathode digit: bit0..bit6 = a..g (74HC595 Q0..Q6), 1 = lit.
inline uint8_t segments(char c) {
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
