// g++ -std=c++11 -Wall -Wextra -Werror tests/clicker_tag_test.cpp -o /tmp/clicker-tag-test && /tmp/clicker-tag-test
#include "../firmware/ClickerTag/tag_logic.h"
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

static bool shows(Tag &t, unsigned long now, const char *text, bool blink = false) {
    const Screen s = t.screen(now);
    return s.tens == text[0] && s.units == text[1] && s.blink == blink;
}

static void testScores(const uint8_t *me, const uint8_t *other) {
    static_assert(ble::SCORE == 5 && ble::DOWNLINK_SIZE == 27, "score must use the existing slot format");
    unsigned long now = 1000;
    const ble::Downlink finished = state(7, ble::FINISHED);
    const ble::Downlink published = reply(finished, me, ble::SCORE, 0, 97);
    uint8_t buf[32];
    ble::Downlink decoded;
    assert(ble::encodeDownlink(published, buf) == 27 && ble::decodeDownlink(buf, 27, decoded));
    assert(hex(buf, 27) == "534301800700000005000000000000" "000000000000" "112233050061");
    assert(decoded.slot[1].kind == ble::SCORE && decoded.slot[1].seq == 0 && decoded.slot[1].value == 97);

    Tag t; memcpy(t.addr, me, 3);
    t.onDownlink(reply(state(7, ble::READY), me, ble::STATUS, 0, 'L'), now);
    t.onDownlink(reply(state(7, ble::READY), me, ble::SCORE, 0, 97), now);
    assert(t.logged && !t.hasScore && shows(t, now, "rd"));   // scores only after FINISHED
    t.onDownlink(finished, now); assert(shows(t, now, "En"));
    t.onDownlink(reply(finished, other, ble::SCORE, 0, 97), now);
    t.onDownlink(reply(finished, me, ble::SCORE, 1, 97), now);
    assert(!t.hasScore && shows(t, now, "En"));              // wrong tag or malformed score seq

    Tag unlogged; memcpy(unlogged.addr, me, 3);
    unlogged.onDownlink(published, now);
    assert(!unlogged.logged && !unlogged.hasScore && shows(unlogged, now, "En"));
    { Tag idle; memcpy(idle.addr, me, 3); idle.onDownlink(finished, now);   // never logged in: "En" 3 s, then sleep
      assert(shows(idle, now + END_SHOW_MS - 1, "En") && shows(idle, now + END_SHOW_MS, "  ")); }
    // A repeated score can follow STATUS restoring login after a tag reboot.
    unlogged.onDownlink(reply(published, me, ble::STATUS, 0, 'L', 0), now);
    assert(unlogged.logged && unlogged.hasScore && shows(unlogged, now, "97"));

    // Tenths of a score out of 10: whole scores show as numbers, others as the two digits of the tenths.
    const uint8_t scores[] = {0, 10, 50, 100, 5, 95, 97, 99};
    const char *text[] = {" 0", " 1", " 5", "10", "05", "95", "97", "99"};
    for (unsigned i = 0; i < sizeof scores; ++i) {
        t.onDownlink(reply(finished, me, ble::SCORE, 0, scores[i]), ++now);
        assert(t.hasScore && t.score == scores[i] && shows(t, now, text[i]) && !t.vibrate);
        const ble::Uplink u = t.uplink();
        assert(u.type == ble::HELLO && u.logged == 3 && u.answer == scores[i]);
        assert(ble::encodeUplink(u, buf) == 16 && buf[14] == 3 && buf[15] == scores[i]);
        ble::Uplink back;
        assert(ble::decodeUplink(buf, 16, back) && back.logged == 3 && back.answer == scores[i]);
    }
    t.onDownlink(published, ++now);
    unsigned long activeAt = t.activeAt;
    for (unsigned value = 101; value < 255; ++value)
        t.onDownlink(reply(finished, me, ble::SCORE, 0, uint8_t(value)), ++now);
    assert(t.hasScore && t.score == 97 && t.activeAt == activeAt);

    // Score for 5 s, then "En" for 3 s, then the display sleeps; repeated frames do not restart it.
    const unsigned long shownAt = t.scoreAt;
    t.onDownlink(published, shownAt + SCORE_SHOW_MS - 1); assert(shows(t, shownAt + SCORE_SHOW_MS - 1, "97"));
    t.onDownlink(published, shownAt + SCORE_SHOW_MS); assert(shows(t, shownAt + SCORE_SHOW_MS, "En"));
    now = shownAt + SCORE_SHOW_MS + END_SHOW_MS;
    t.onDownlink(published, now);
    assert(!t.gatewayLost(now) && shows(t, now, "  ") && !t.awake);
    assert(t.uplink().logged == 3 && t.uplink().answer == 97); // score ACK still sent while LEDs sleep
    t.onDownlink(published, ++now); assert(!t.awake);
    assert(!t.press('5', now) && shows(t, now, "En"));        // a key wakes the display: plain "En"
    t.onDownlink(reply(finished, me, ble::SCORE, 0, 7), ++now);
    assert(t.awake && shows(t, now, "07"));                  // changed score shows again

    const ble::Downlink withdrawn = reply(finished, me, ble::SCORE, 0, 255);
    t.onDownlink(withdrawn, ++now);
    activeAt = t.activeAt;
    assert(!t.hasScore && shows(t, now, "En") && t.uplink().logged == 1);
    for (unsigned long elapsed = 1000; elapsed <= DISPLAY_SLEEP_MS; elapsed += 1000) {
        now = activeAt + elapsed;
        t.onDownlink(withdrawn, now);
        assert(t.activeAt == activeAt);
    }
    t.onDownlink(withdrawn, ++now);
    assert(shows(t, now, "  ") && !t.awake);                 // repeated withdrawal also permits sleep

    // Network loss clears the score belief; the browser can resend until HELLO acknowledges it.
    t.onDownlink(published, ++now); assert(t.hasScore && shows(t, now, "97"));
    t.onDownlink(state(7, ble::OFFLINE), ++now);
    assert(!t.hasScore && shows(t, now, "nS") && t.uplink().logged == 1);
    t.onDownlink(published, ++now);
    assert(t.hasScore && shows(t, now, "97") && t.uplink().logged == 3);
    assert(shows(t, now + GATEWAY_TIMEOUT_MS + 1, "nC"));
    activeAt = t.activeAt; now += GATEWAY_TIMEOUT_MS + 2;
    t.onDownlink(published, now); assert(shows(t, now, "En") && t.activeAt == activeAt);   // past the 5 s score

    // Session changes, leaving FINISHED and revoking the login all invalidate the stored score.
    const ble::Downlink next = state(8, ble::FINISHED);
    t.onDownlink(reply(next, me, ble::SCORE, 0, 97), ++now);
    assert(!t.logged && !t.hasScore && shows(t, now, "En") && t.uplink().logged == 0);
    t.onDownlink(reply(next, me, ble::STATUS, 0, 'L'), now);
    t.onDownlink(reply(next, me, ble::SCORE, 0, 100), now); assert(shows(t, now, "10"));
    t.onDownlink(state(8, ble::READY), ++now); assert(!t.hasScore && shows(t, now, "rd"));
    t.onDownlink(next, ++now); assert(shows(t, now, "En"));
    t.onDownlink(reply(next, me, ble::SCORE, 0, 97), now);
    t.onDownlink(reply(next, me, ble::STATUS, 0, 'L'), ++now);
    assert(t.hasScore && shows(t, now, "97"));               // restoring the same login preserves score
    t.onDownlink(reply(next, me, ble::STATUS, 0, 0), ++now);
    assert(!t.logged && !t.hasScore && shows(t, now, "En"));
    t.onDownlink(reply(next, me, ble::STATUS, 0, 'L'), ++now);
    assert(!t.hasScore && shows(t, now, "En"));              // old score cannot reappear on a new mapping

    // SCORE can be saved during a late answer retry; it never acts as the answer ACK.
    Tag waiting; memcpy(waiting.addr, me, 3);
    waiting.onDownlink(reply(state(9, ble::QUESTION_ACTIVE, 42, 1), me, ble::STATUS, 0, 'L'), now);
    waiting.press('2', now); waiting.press(KEY_OK, now);
    const std::string before = hex(buf, ble::encodeUplink(waiting.uplink(), buf));
    waiting.onDownlink(reply(state(9, ble::FINISHED, 42), me, ble::SCORE, 0, 97), ++now);
    assert(waiting.hasScore && waiting.pending && shows(waiting, now, " B", true));
    assert(hex(buf, ble::encodeUplink(waiting.uplink(), buf)) == before);
    waiting.onDownlink(reply(state(9, ble::FINISHED, 42), me, ble::ANSWER_OK, waiting.request.seq, 'B'), ++now);
    assert(!waiting.pending && shows(waiting, now, "97") && waiting.uplink().logged == 3);
    waiting.onDownlink(reply(state(9, ble::FINISHED, 42), me, ble::SCORE, 0, 255), ++now);
    assert(shows(waiting, now, "En") && waiting.uplink().logged == 1 && waiting.uplink().answer == 'B');
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
    testScores(me, other);
    ble::Downlink d = reply(state(5, ble::QUESTION_ACTIVE, 42, 12), me, ble::ANSWER_OK, 4, 'B', 0), dd;
    assert(ble::encodeDownlink(d, buf) == ble::DOWNLINK_SIZE && ble::decodeDownlink(buf, ble::DOWNLINK_SIZE, dd));
    assert(hex(buf, ble::DOWNLINK_SIZE) == "5343018005000000042a0000000c00112233020442000000000000");
    assert(dd.session == 5 && dd.state == ble::QUESTION_ACTIVE && dd.question == 42 && dd.questionNo == 12);
    assert(!memcmp(dd.slot[0].addr, me, 3) && dd.slot[0].kind == ble::ANSWER_OK && dd.slot[0].seq == 4 && dd.slot[0].value == 'B');
    assert(!ble::decodeDownlink(buf, ble::DOWNLINK_SIZE - 1, dd));

    Tag t;
    memcpy(t.addr, me, 3);
    unsigned long now = 1000;
    assert(shows(t, now, "--"));                                // just powered on, gateway not heard yet
    t.onDownlink(state(0, ble::OFFLINE), now); assert(shows(t, now, "nS"));
    t.onDownlink(state(0, ble::DISABLED), now); assert(shows(t, now, "OF"));
    t.onDownlink(state(0, ble::WAIT_SESSION), now); assert(shows(t, now, "--"));
    t.press('1', now); t.press('C', now); assert(!t.pending);   // login not open

    t.onDownlink(state(1, ble::LOGIN_OPEN), now); assert(shows(t, now, "Lo"));
    for (char c : std::string("00146217")) t.press(c, now);
    assert(shows(t, now, "17"));
    t.press('B', now); assert(shows(t, now, "21"));
    t.press('7', now); t.press('C', now);
    assert(t.pending && t.request.seq == 1 && std::string(t.uplink().student) == "00146217");
    assert(shows(t, now, "17", true) && t.vibrate);             // OK sends and buzzes once
    t.vibrate = false;
    t.press('5', now); assert(t.inputLen == 8);                  // keys ignored while sending
    t.onDownlink(reply(state(1, ble::LOGIN_OPEN), other, ble::LOGIN_OK, 1, 0), now); assert(t.pending);  // other tag
    t.onDownlink(reply(state(1, ble::LOGIN_OPEN), me, ble::LOGIN_OK, 2, 0), now); assert(t.pending);     // stale seq
    t.onDownlink(reply(state(1, ble::LOGIN_OPEN), me, ble::LOGIN_OK, 1, 0), now);
    assert(!t.pending && t.logged && !t.vibrate && shows(t, now, "Hi"));   // the ACK does not buzz
    now += NOTICE_MS; assert(shows(t, now, "rd"));
    ble::Uplink hello = t.uplink(); assert(hello.type == ble::HELLO && hello.logged && hello.session == 1);

    t.onDownlink(state(1, ble::QUESTION_ACTIVE, 9, 7), now); assert(t.vibrate && shows(t, now, " 7"));   // new question: buzz, tens dark
    t.vibrate = false;
    t.onDownlink(state(1, ble::QUESTION_ACTIVE, 9, 7), now); assert(!t.vibrate);   // same question again: no buzz
    t.press('6', now); assert(shows(t, now, " 7"));             // only 1-4 are answers
    t.press('3', now); assert(shows(t, now, " C") && !t.vibrate);
    t.press('B', now); assert(shows(t, now, " 7"));
    t.press('2', now); t.press('C', now); assert(t.pending && t.request.answer == 'B' && shows(t, now, " B", true) && t.vibrate);
    t.vibrate = false;
    t.onDownlink(reply(state(1, ble::QUESTION_ACTIVE, 9, 7), me, ble::ANSWER_OK, 2, 'C'), now); assert(t.pending);  // wrong value
    t.onDownlink(reply(state(1, ble::QUESTION_ACTIVE, 9, 7), me, ble::ANSWER_OK, 2, 'B'), now);
    assert(!t.pending && t.accepted && shows(t, now, "B="));
    t.press('1', now); t.press('C', now); assert(!t.pending && t.selected == 'B');  // answer locked on tag
    assert(t.uplink().answer == 'B');

    // Question closed before ACK: server rejects, tag shows E6 and clears the unsent choice.
    t.onDownlink(state(1, ble::QUESTION_ACTIVE, 10, 12), now); assert(!t.accepted && shows(t, now, "12"));
    t.vibrate = false;
    t.press('4', now); t.press('C', now);
    t.onDownlink(reply(state(1, ble::READY), me, ble::ERROR, 3, ble::E_QUESTION_CLOSED), now);
    t.vibrate = false;
    t.onDownlink(reply(state(1, ble::READY), me, ble::ERROR, 3, ble::E_QUESTION_CLOSED), now);   // repeated reply
    assert(!t.pending && !t.selected && shows(t, now, "E6") && !t.vibrate);   // errors do not buzz
    now += NOTICE_MS; assert(shows(t, now, "rd"));

    // Tag rebooted mid-session: STATUS from the bridge restores login and saved answer.
    Tag r; memcpy(r.addr, me, 3);
    r.onDownlink(state(1, ble::QUESTION_ACTIVE, 11, 9), now); assert(shows(r, now, "LC"));
    r.onDownlink(reply(state(1, ble::QUESTION_ACTIVE, 11, 9), me, ble::STATUS, 0, 'D'), now);
    assert(r.logged && r.accepted && shows(r, now, "D="));
    r.onDownlink(reply(state(1, ble::QUESTION_ACTIVE, 11, 9), me, ble::STATUS, 0, 0), now);
    assert(!r.logged && !r.accepted);

    // Login errors: wrong MSSV, then MSSV already used on another tag.
    Tag e; memcpy(e.addr, me, 3);
    e.onDownlink(state(2, ble::LOGIN_OPEN), now);
    e.press('9', now); e.press('C', now);
    e.onDownlink(reply(state(2, ble::LOGIN_OPEN), me, ble::ERROR, 1, ble::E_STUDENT_NOT_FOUND), now);
    assert(!e.pending && !e.logged && shows(e, now, "E2") && e.inputLen == 1);
    e.press('C', now);
    e.onDownlink(reply(state(2, ble::LOGIN_OPEN), me, ble::ERROR, 2, ble::E_STUDENT_IN_USE), now);
    assert(shows(e, now, "E3"));

    // Every documented E-code is shown for NOTICE_MS, then returns to the login input.
    for (uint8_t code = ble::E_LOGIN_CLOSED; code <= ble::E_GATEWAY; ++code) {
        Tag error; memcpy(error.addr, me, 3);
        error.onDownlink(state(2, ble::LOGIN_OPEN), now);
        error.press('1', now); error.press(KEY_OK, now);
        error.onDownlink(reply(state(2, ble::LOGIN_OPEN), me, ble::ERROR, error.request.seq, code), now);
        const char text[] = {'E', char('0' + code), 0};
        assert(!error.pending && shows(error, now, text));
        assert(shows(error, now + NOTICE_MS, " 1"));
    }

    // Pending requests take screen priority over connection warnings and keep the same seq until ACK.
    Tag waiting; memcpy(waiting.addr, me, 3);
    waiting.onDownlink(state(2, ble::LOGIN_OPEN), now);
    waiting.press('1', now); waiting.press(KEY_OK, now);
    waiting.onDownlink(state(2, ble::OFFLINE), now);
    assert(shows(waiting, now + GATEWAY_TIMEOUT_MS + 1, " 1", true));
    assert(waiting.pending && waiting.uplink().seq == 1);

    // New session resets login; finished session; bridge silence; display sleep and wake key.
    t.onDownlink(state(2, ble::LOGIN_OPEN), now); assert(!t.logged && shows(t, now, "Lo"));
    t.onDownlink(state(2, ble::FINISHED), now); assert(shows(t, now, "En"));
    assert(shows(t, now + GATEWAY_TIMEOUT_MS + 1, "nC"));
    t.onDownlink(state(2, ble::LOGIN_OPEN), now);
    now += DISPLAY_SLEEP_MS + 1; t.onDownlink(state(2, ble::LOGIN_OPEN), now);  // unchanged state keeps it asleep
    assert(shows(t, now, "  ") && !t.awake);
    assert(!t.press('5', now) && t.inputLen == 0 && shows(t, now, "Lo"));  // first key only wakes

    // Sequence numbers skip 0 (reserved for STATUS).
    t.seq = 255; t.press('1', now); t.press('C', now); assert(t.request.seq == 1);

    assert(seg::glyph('8') == 0x7F && seg::glyph('A') == 0x77 && seg::glyph('=') == 0x48 && seg::glyph('?') == 0);
    assert(seg::tensOf(1) == ' ' && seg::unitsOf(1) == '1' && seg::tensOf(12) == '1' && seg::tensOf(105) == ' ' && seg::unitsOf(105) == '5');
    // One-digit mode: one character stays on the tens digit, two characters take turns with a dark gap.
    assert(oneDigit(Screen{' ', '1', false}, 0) == '1' && oneDigit(Screen{' ', '1', false}, 999) == '1');
    assert(oneDigit(Screen{' ', 'B', true}, 0) == 'B' && oneDigit(Screen{' ', 'B', true}, BLINK_MS) == ' ');
    assert(oneDigit(Screen{'1', '2', false}, 0) == '1' && oneDigit(Screen{'1', '2', false}, CHAR_MS) == ' ');
    assert(oneDigit(Screen{'1', '2', false}, CHAR_MS + GAP_MS) == '2' && oneDigit(Screen{'1', '2', false}, 2 * CHAR_MS + GAP_MS) == ' ');
    assert(oneDigit(Screen{'1', '2', false}, 2 * (CHAR_MS + GAP_MS)) == '1' && 2 * (CHAR_MS + GAP_MS) == 1000);
    assert(oneDigit(Screen{' ', ' ', false}, 0) == ' ');
    // Listening follows what the student can see: fast for an ACK, slower awake, slow asleep, slowest without a session.
    {
        const uint8_t addr[3] = {9, 9, 9};
        Tag l; memcpy(l.addr, addr, 3); unsigned long t = 1000;
        l.wake(t);
        assert(l.scanInterval(t) == SCAN_AWAKE && l.helloPeriod() == HELLO_MS);
        l.onDownlink(state(3, ble::LOGIN_OPEN), t);
        l.press('1', t); l.press('C', t);
        assert(l.pending && l.scanInterval(t) == SCAN_ACK && l.helloPeriod() == RETRY_MS);
        l.onDownlink(reply(state(3, ble::QUESTION_ACTIVE, 5, 1), addr, ble::LOGIN_OK, 1, 0), t);
        t += DISPLAY_SLEEP_MS + 1; l.onDownlink(state(3, ble::QUESTION_ACTIVE, 5, 1), t);
        assert(l.screen(t).tens == ' ' && !l.awake && l.scanInterval(t) == SCAN_ASLEEP && l.helloPeriod() == HELLO_SLEEP_MS);
        assert(l.scanInterval(t + GATEWAY_TIMEOUT_MS + 1) == SCAN_IDLE);    // gateway gone
        l.onDownlink(state(3, ble::FINISHED), t); l.awake = false;
        assert(l.scanInterval(t) == SCAN_IDLE);                             // no running session
        static_assert(SCAN_ACK < SCAN_AWAKE && SCAN_AWAKE < SCAN_ASLEEP && SCAN_ASLEEP < SCAN_IDLE, "listen levels");
        static_assert(SCAN_WINDOW > 48, "a 40 ms window must cover the gateway's 30 ms advert interval");
    }
    std::cout << "ble_test: all checks passed\n";
}
