#pragma once
// BLE frames between ClickerTag and ClickerGateway (Manufacturer Specific Data). ClickerGateway.ino checks the same layout.
#include <stdint.h>
#include <string.h>

// Shared with ClickerGateway and web/gateway.js. Manufacturer Specific Data, company 0xFFFF; little-endian integers.
// Slot addresses are the 3 low bytes of the tag's BLE MAC, least significant first.
// Uplink byte 5 is a counter bumped on every refresh, so each heartbeat and retry is a new advert.
namespace ble {

const uint16_t COMPANY = 0xFFFF;
const uint8_t MAGIC0 = 'S', MAGIC1 = 'C', VERSION = 1;
const uint8_t MAX_DIGITS = 20;

enum FrameType : uint8_t { HELLO = 0x01, LOGIN = 0x02, ANSWER = 0x03, STATE = 0x80 };
// DISABLED: the web is in Wi-Fi (ESP32) mode. OFFLINE: the gateway cannot reach the web or the server.
enum State : uint8_t { WAIT_SESSION = 0, CREATED, LOGIN_OPEN, READY, QUESTION_ACTIVE, FINISHED, DISABLED, OFFLINE = 0xFF };
enum Kind : uint8_t { NONE = 0, LOGIN_OK = 1, ANSWER_OK = 2, STATUS = 3, ERROR = 4, SCORE = 5 };   // reply in a STATE slot
enum ErrorCode : uint8_t {   // shown as "E<n>"
    E_LOGIN_CLOSED = 1, E_STUDENT_NOT_FOUND = 2, E_STUDENT_IN_USE = 3, E_DEVICE_IN_USE = 4,
    E_LOGIN_REQUIRED = 5, E_QUESTION_CLOSED = 6, E_ANSWER_LOCKED = 7, E_REJECTED = 8, E_GATEWAY = 9
};

struct Slot {
    uint8_t addr[3];
    uint8_t kind, seq, value;   // SCORE: seq=0, value=0..99; 255 withdraws the score
};

struct Uplink {   // tag -> gateway; HELLO reports what the tag believes so the web only corrects differences
    uint8_t type = HELLO, seq = 0, counter = 0;
    uint32_t session = 0, question = 0;
    uint8_t logged = 0, answer = 0;   // HELLO: logged bit0=login, bit1=score; answer=score if bit1, else answer letter
    char student[MAX_DIGITS + 1] = {0};   // LOGIN only, digits
};

struct Downlink {   // gateway -> every tag
    uint32_t session = 0, question = 0;
    uint8_t state = OFFLINE;
    uint16_t questionNo = 0;
    Slot slot[2] = {};
};

const uint8_t HEADER = 4;   // magic, version, type
const uint8_t DOWNLINK_SIZE = HEADER + 11 + 12;
const uint8_t UPLINK_MAX = HEADER + 2 + 4 + 1 + MAX_DIGITS / 2;

inline void put32(uint8_t *p, uint32_t v) { for (int i = 0; i < 4; ++i) p[i] = uint8_t(v >> (8 * i)); }
inline uint32_t get32(const uint8_t *p) { return uint32_t(p[0]) | uint32_t(p[1]) << 8 | uint32_t(p[2]) << 16 | uint32_t(p[3]) << 24; }
inline void header(uint8_t *p, uint8_t type) { p[0] = MAGIC0; p[1] = MAGIC1; p[2] = VERSION; p[3] = type; }
inline bool valid(const uint8_t *p, uint8_t len) { return len >= HEADER && p[0] == MAGIC0 && p[1] == MAGIC1 && p[2] == VERSION; }

// Bytes written (without the company ID), 0 on invalid input.
inline uint8_t encodeUplink(const Uplink &u, uint8_t *out) {
    header(out, u.type);
    out[4] = u.seq; out[5] = u.counter;
    put32(out + 6, u.session);
    if (u.type == HELLO) { put32(out + 10, u.question); out[14] = u.logged; out[15] = u.answer; return 16; }
    if (u.type == ANSWER) { put32(out + 10, u.question); out[14] = u.answer; return 15; }
    if (u.type != LOGIN) return 0;
    const uint8_t n = uint8_t(strlen(u.student));
    if (n == 0 || n > MAX_DIGITS) return 0;
    out[10] = n;
    memset(out + 11, 0, MAX_DIGITS / 2);
    for (uint8_t i = 0; i < n; ++i) {   // packed BCD, first digit in the high nibble
        const char c = u.student[i];
        if (c < '0' || c > '9') return 0;
        out[11 + i / 2] |= uint8_t(c - '0') << (i % 2 ? 0 : 4);
    }
    return uint8_t(11 + (n + 1) / 2);
}

inline bool decodeUplink(const uint8_t *p, uint8_t len, Uplink &u) {
    if (!valid(p, len) || len < 10) return false;
    u = Uplink();
    u.type = p[3]; u.seq = p[4]; u.counter = p[5]; u.session = get32(p + 6);
    if (u.type == HELLO && len >= 16) { u.question = get32(p + 10); u.logged = p[14]; u.answer = p[15]; return true; }
    if (u.type == ANSWER && len >= 15) { u.question = get32(p + 10); u.answer = p[14]; return u.answer >= 'A' && u.answer <= 'D'; }
    if (u.type != LOGIN || len < 11) return false;
    const uint8_t n = p[10];
    if (n == 0 || n > MAX_DIGITS || len < 11 + (n + 1) / 2) return false;
    for (uint8_t i = 0; i < n; ++i) {
        const uint8_t d = (p[11 + i / 2] >> (i % 2 ? 0 : 4)) & 0x0F;
        if (d > 9) return false;
        u.student[i] = char('0' + d);
    }
    return true;
}

inline uint8_t encodeDownlink(const Downlink &d, uint8_t *out) {
    header(out, STATE);
    put32(out + 4, d.session);
    out[8] = d.state;
    put32(out + 9, d.question);
    out[13] = uint8_t(d.questionNo); out[14] = uint8_t(d.questionNo >> 8);
    for (int i = 0; i < 2; ++i) {
        uint8_t *s = out + 15 + 6 * i;
        memcpy(s, d.slot[i].addr, 3);
        s[3] = d.slot[i].kind; s[4] = d.slot[i].seq; s[5] = d.slot[i].value;
    }
    return DOWNLINK_SIZE;
}

inline bool decodeDownlink(const uint8_t *p, uint8_t len, Downlink &d) {
    if (!valid(p, len) || p[3] != STATE || len < DOWNLINK_SIZE) return false;
    d.session = get32(p + 4); d.state = p[8]; d.question = get32(p + 9);
    d.questionNo = uint16_t(p[13] | p[14] << 8);
    for (int i = 0; i < 2; ++i) {
        const uint8_t *s = p + 15 + 6 * i;
        memcpy(d.slot[i].addr, s, 3);
        d.slot[i].kind = s[3]; d.slot[i].seq = s[4]; d.slot[i].value = s[5];
    }
    return true;
}

}  // namespace ble
