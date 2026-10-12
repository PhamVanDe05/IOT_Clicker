// g++ -std=c++11 -Wall -Wextra -Werror tests/ble_test.cpp -o /tmp/clicker-ble-test && /tmp/clicker-ble-test
#include "../firmware/StudentTagNRF52/tag_controller.h"
#include <cassert>
#include <cstdio>
#include <iostream>
#include <string>

// Hex of a frame; the same vectors are pinned in tests/test_ble_bridge.py for the Python bridge.
static std::string hex(const uint8_t *p, uint8_t n) {
    std::string out; char b[3];
    for (uint8_t i = 0; i < n; ++i) { snprintf(b, sizeof b, "%02x", p[i]); out += b; }
    return out;
}

static ble::Downlink state(uint32_t sid, uint8_t st, uint32_t qid = 0, uint16_t qno = 0) {
    ble::Downlink d;
    d.session = sid; d.state = st; d.question = qid; d.questionNo = qno;
    return d;
}

static ble::Downlink reply(ble::Downlink d, const uint8_t *addr, uint8_t kind, uint8_t seq, uint8_t value, int slot = 1) {
    memcpy(d.slot[slot].addr, addr, 3);
    d.slot[slot].kind = kind; d.slot[slot].seq = seq; d.slot[slot].value = value;
    return d;
}

static bool shows(TagController &t, unsigned long now, const char *text, bool blink = false) {
    const Screen s = t.screen(now);
    return s.left == text[0] && s.right == text[1] && s.blink == blink;
}

int main() {
    // Frames fit a 31-byte legacy advert: AD header 2 + company 2 + payload (+3 flags on tags).
    static_assert(2 + 2 + ble::DOWNLINK_SIZE <= 31, "downlink too large");
    static_assert(3 + 2 + 2 + ble::UPLINK_MAX <= 31, "uplink too large with flags");

    uint8_t buf[32];
    ble::Uplink login, back;
    login.type = ble::LOGIN; login.seq = 7; login.counter = 42; login.session = 70000;
    strcpy(login.student, "00146217");
    const uint8_t n = ble::encodeUplink(login, buf);
    assert(n == 15 && hex(buf, n) == "53430102072a7011010008" "00146217");
    assert(ble::decodeUplink(buf, n, back));
    assert(back.type == ble::LOGIN && back.seq == 7 && back.counter == 42 && back.session == 70000 && std::string(back.student) == "00146217");
    strcpy(login.student, "12345678901234567890");
    assert(ble::encodeUplink(login, buf) == ble::UPLINK_MAX && ble::decodeUplink(buf, ble::UPLINK_MAX, back));
    assert(std::string(back.student) == "12345678901234567890");
    strcpy(login.student, "12a"); assert(!ble::encodeUplink(login, buf));
    assert(!ble::decodeUplink(buf, 3, back));
    buf[0] = 'X'; assert(!ble::decodeUplink(buf, 14, back));

    ble::Uplink answer; answer.type = ble::ANSWER; answer.seq = 9; answer.session = 3; answer.question = 123456789; answer.answer = 'C';
    assert(hex(buf, ble::encodeUplink(answer, buf)) == "534301030900030000001" "5cd5b0743");
    assert(ble::decodeUplink(buf, 15, back) && back.question == 123456789 && back.answer == 'C');
    buf[14] = 'E'; assert(!ble::decodeUplink(buf, 15, back));
    ble::Uplink beat; beat.counter = 1; beat.session = 2; beat.question = 3; beat.logged = 1; beat.answer = 'D';
    assert(hex(buf, ble::encodeUplink(beat, buf)) == "53430101000102000000030000000144");

    const uint8_t me[3] = {0x11, 0x22, 0x33}, other[3] = {0x11, 0x22, 0x34};
    ble::Downlink d = reply(state(5, ble::QUESTION_ACTIVE, 42, 12), me, ble::ANSWER_OK, 4, 'B', 0), dd;
    assert(ble::encodeDownlink(d, buf) == ble::DOWNLINK_SIZE && ble::decodeDownlink(buf, ble::DOWNLINK_SIZE, dd));
    assert(hex(buf, ble::DOWNLINK_SIZE) == "5343018005000000042a0000000c00112233020442000000000000");
    assert(dd.session == 5 && dd.state == ble::QUESTION_ACTIVE && dd.question == 42 && dd.questionNo == 12);
    assert(!memcmp(dd.slot[0].addr, me, 3) && dd.slot[0].kind == ble::ANSWER_OK && dd.slot[0].seq == 4 && dd.slot[0].value == 'B');
    assert(!ble::decodeDownlink(buf, ble::DOWNLINK_SIZE - 1, dd));

    TagController t;
    memcpy(t.addr, me, 3);
    unsigned long now = 1000;
    assert(shows(t, now, "nC") && !strcmp(t.statusText(now), "Mat ket noi laptop"));  // never heard the bridge
    t.onDownlink(state(0, ble::OFFLINE), now); assert(shows(t, now, "nS"));
    t.onDownlink(state(0, ble::DISABLED), now); assert(shows(t, now, "OF"));
    t.onDownlink(state(0, ble::WAIT_SESSION), now); assert(shows(t, now, "--"));
    t.press('1', now); t.press('C', now); assert(!t.pending);   // login not open

    t.onDownlink(state(1, ble::LOGIN_OPEN), now); assert(shows(t, now, "Lo") && !strcmp(t.statusText(now), "Nhap MSSV + OK"));
    for (char c : std::string("00146217")) t.press(c, now);
    assert(shows(t, now, "17"));
    t.press('B', now); assert(shows(t, now, "21"));
    t.press('7', now); t.press('C', now);
    assert(t.pending && t.request.seq == 1 && std::string(t.uplink().student) == "00146217");
    assert(shows(t, now, "17", true) && !strcmp(t.statusText(now), "Dang gui MSSV..."));
    t.press('5', now); assert(t.inputLen == 8);                  // keys ignored while sending
    t.onDownlink(reply(state(1, ble::LOGIN_OPEN), other, ble::LOGIN_OK, 1, 0), now); assert(t.pending);  // other tag
    t.onDownlink(reply(state(1, ble::LOGIN_OPEN), me, ble::LOGIN_OK, 2, 0), now); assert(t.pending);     // stale seq
    t.onDownlink(reply(state(1, ble::LOGIN_OPEN), me, ble::LOGIN_OK, 1, 0), now);
    assert(!t.pending && t.logged && t.vibrate == 1 && shows(t, now, "Hi"));
    now += NOTICE_MS; assert(shows(t, now, "rd"));
    ble::Uplink hello = t.uplink(); assert(hello.type == ble::HELLO && hello.logged && hello.session == 1);

    t.vibrate = 0;
    t.onDownlink(state(1, ble::QUESTION_ACTIVE, 9, 7), now); assert(t.vibrate == 3 && shows(t, now, "07"));
    t.press('6', now); assert(shows(t, now, "07"));             // only 1-4 are answers
    t.press('3', now); assert(shows(t, now, "C-"));
    t.press('B', now); assert(shows(t, now, "07"));
    t.press('2', now); t.press('C', now); assert(t.pending && t.request.answer == 'B' && shows(t, now, "B-", true));
    t.onDownlink(reply(state(1, ble::QUESTION_ACTIVE, 9, 7), me, ble::ANSWER_OK, 2, 'C'), now); assert(t.pending);  // wrong value
    t.onDownlink(reply(state(1, ble::QUESTION_ACTIVE, 9, 7), me, ble::ANSWER_OK, 2, 'B'), now);
    assert(!t.pending && t.accepted && shows(t, now, "B="));
    t.press('1', now); t.press('C', now); assert(!t.pending && t.selected == 'B');  // answer locked on tag
    assert(t.uplink().answer == 'B');

    // Question closed before ACK: server rejects, tag shows E6 and clears the unsent choice.
    t.onDownlink(state(1, ble::QUESTION_ACTIVE, 10, 8), now); assert(!t.accepted && shows(t, now, "08"));
    t.press('4', now); t.press('C', now);
    t.onDownlink(reply(state(1, ble::READY), me, ble::ERROR, 3, ble::E_QUESTION_CLOSED), now);
    assert(!t.pending && !t.selected && shows(t, now, "E6") && t.vibrate == 2 && !strcmp(t.statusText(now), "Cau da dong"));
    now += NOTICE_MS; assert(shows(t, now, "rd"));

    // Tag rebooted mid-session: STATUS from the bridge restores login and saved answer.
    TagController r; memcpy(r.addr, me, 3);
    r.onDownlink(state(1, ble::QUESTION_ACTIVE, 11, 9), now); assert(shows(r, now, "LC"));
    r.onDownlink(reply(state(1, ble::QUESTION_ACTIVE, 11, 9), me, ble::STATUS, 0, 'D'), now);
    assert(r.logged && r.accepted && shows(r, now, "D="));
    r.onDownlink(reply(state(1, ble::QUESTION_ACTIVE, 11, 9), me, ble::STATUS, 0, 0), now);
    assert(!r.logged && !r.accepted);

    // Login errors: wrong MSSV, then MSSV already used on another tag.
    TagController e; memcpy(e.addr, me, 3);
    e.onDownlink(state(2, ble::LOGIN_OPEN), now);
    e.press('9', now); e.press('C', now);
    e.onDownlink(reply(state(2, ble::LOGIN_OPEN), me, ble::ERROR, 1, ble::E_STUDENT_NOT_FOUND), now);
    assert(!e.pending && !e.logged && shows(e, now, "E2") && e.inputLen == 1);
    e.press('C', now);
    e.onDownlink(reply(state(2, ble::LOGIN_OPEN), me, ble::ERROR, 2, ble::E_STUDENT_IN_USE), now);
    assert(shows(e, now, "E3"));

    // New session resets login; finished session; bridge silence; display sleep and wake key.
    t.onDownlink(state(2, ble::LOGIN_OPEN), now); assert(!t.logged && shows(t, now, "Lo"));
    t.onDownlink(state(2, ble::FINISHED), now); assert(shows(t, now, "En"));
    t.onDownlink(reply(state(2, ble::FINISHED), other, ble::SCORE, 0, 67), now); assert(shows(t, now, "En"));
    t.onDownlink(reply(state(2, ble::FINISHED), me, ble::SCORE, 0, 101), now); assert(shows(t, now, "En"));
    t.onDownlink(reply(state(2, ble::FINISHED), me, ble::SCORE, 0, 67), now);
    assert(t.hasScore && t.scoreTenths == 67 && shows(t, now, " 7") && !strcmp(t.statusText(now), "Diem 6.7/10"));
    t.onDownlink(reply(state(2, ble::FINISHED), me, ble::SCORE, 0, 67), now + SCORE_MS - 1);
    assert(shows(t, now + SCORE_MS - 1, " 7"));
    assert(shows(t, now + SCORE_MS, "En") && !strcmp(t.statusText(now + SCORE_MS), "Ket thuc"));
    t.onDownlink(reply(state(2, ble::FINISHED), me, ble::SCORE, 0, 68), now + SCORE_MS);
    assert(shows(t, now + SCORE_MS, " 7") && !strcmp(t.statusText(now + SCORE_MS), "Diem 6.8/10"));
    TagController late; memcpy(late.addr, me, 3);
    late.onDownlink(reply(state(4, ble::LOGIN_OPEN), me, ble::STATUS, 0, 'L'), now);
    late.onDownlink(state(4, ble::QUESTION_ACTIVE, 12, 1), now);
    late.press('1', now); late.press('C', now); assert(late.pending);
    late.onDownlink(reply(state(4, ble::FINISHED), me, ble::SCORE, 0, 100), now);
    assert(!late.pending && shows(late, now, "10"));  // final score settles an answer still waiting for ACK
    now += SCORE_MS;
    assert(shows(t, now + BRIDGE_TIMEOUT_MS + 1, "nC"));
    t.onDownlink(state(3, ble::LOGIN_OPEN), now); assert(!t.hasScore);
    now += DISPLAY_SLEEP_MS + 1; t.onDownlink(state(3, ble::LOGIN_OPEN), now);  // unchanged state keeps it asleep
    assert(shows(t, now, "  ") && !t.awake);
    assert(!t.press('5', now) && t.inputLen == 0 && shows(t, now, "Lo"));  // first key only wakes

    // Sequence numbers skip 0 (reserved for STATUS).
    t.seq = 255; t.press('1', now); t.press('C', now); assert(t.request.seq == 1);

    assert(segments('8') == 0x7F && segments('A') == 0x77 && segments('=') == 0x48 && segments('?') == 0);
    std::cout << "ble_test: all checks passed\n";
}
