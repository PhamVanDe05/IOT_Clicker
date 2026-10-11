// ClickerTag: firmware for the Student Remote PCB (image/Student Remote, 50 x 75 mm).
// nRF52840 SuperMini, 3 x 4 key matrix, two 74HC595 driving two common-anode 7-segment digits, vibration motor, 2 x AAA.
// Talks to ClickerGateway with BLE advertising only: no pairing, no connection.
//
// Both 74HC595 must be wired pin 12 RCLK -> lat (J2-8) and pin 10 SRCLR -> 3V. The board as first ordered ties RCLK to
// GND and SRCLR to lat: the digits then never show anything.
//
// Build: Arduino IDE, core "Adafruit nRF52", board "Adafruit ItsyBitsy nRF52840 Express" (SuperMini, nice!nano bootloader).
// Everything above the "Hardware" section is plain C++: tests/ble_test.cpp and tests/board_test.cpp compile this file on
// the PC, where ARDUINO is not defined.

#include <stdint.h>
#include <string.h>

// ====================================================================================== Pins and timing
// Raw P<port>.<pin> numbers, independent of the Arduino board variant.
#define PIN(port, pin) (((port) << 5) | (pin))

const uint32_t SEG_DATA = PIN(0, 9), SEG_CLOCK = PIN(0, 20), SEG_LATCH = PIN(0, 17);   // J1-13, J2-7, J2-8
const uint32_t MOTOR = PIN(0, 22);   // AO3400A gate through 470 Ohm, 10 kOhm pull-down: HIGH = on

// A key is a 1N5819 from its row to its column: a column driven LOW pulls the row of a pressed key LOW.
const uint32_t ROW_PINS[3] = {PIN(1, 13), PIN(1, 11), PIN(0, 10)};               // read, internal pull-up
const uint32_t COL_PINS[4] = {PIN(1, 15), PIN(0, 2), PIN(0, 29), PIN(0, 31)};     // driven LOW one at a time
const char KEY_BACK = 'B', KEY_OK = 'C';
const char KEYMAP[3][4] = {          // as printed on the PCB
    {'1', '2', '3', '4'},
    {'5', '6', '7', '8'},
    {KEY_BACK, '9', '0', KEY_OK},
};

const unsigned long LAMP_TEST_MS = 800;        // all segments on at power-up
const unsigned long VIBRATE_MS = 60;           // one light buzz: raise for a stronger one
const unsigned long DEBOUNCE_MS = 30, SCAN_MS = 5, BLINK_MS = 250;
// One-digit mode: only the tens (left) digit is used. A one-character screen (question 1, answer A) sits on it;
// a two-character screen ("12", "Lo", "A=") shows its characters in turn: first for CHAR_MS, dark for GAP_MS, second,
// dark again; 1 s per cycle. The gap tells "11" from "1". Set false once both digits work: they then show side by side.
const bool ONE_DIGIT = false;
const unsigned long CHAR_MS = 400, GAP_MS = 100;
const unsigned long NOTICE_MS = 1500;          // "Hi" / error code on screen
const unsigned long GATEWAY_TIMEOUT_MS = 5000; // no STATE heard for this long: "nC"
const unsigned long DISPLAY_SLEEP_MS = 30000;  // digits off to save the cells; any key wakes them
const unsigned long RETRY_MS = 300, HELLO_MS = 1000;   // advert refresh: request waiting for ACK / heartbeat
const uint16_t ADV_FAST = 160, ADV_SLOW = 800;         // advertising interval, 0.625 ms units
const uint16_t SCAN_INTERVAL = 160, SCAN_WINDOW = 48;  // listen 30 ms every 100 ms

// ====================================================================================== BLE frames
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
enum Kind : uint8_t { NONE = 0, LOGIN_OK = 1, ANSWER_OK = 2, STATUS = 3, ERROR = 4 };   // reply in a STATE slot
enum ErrorCode : uint8_t {   // shown as "E<n>"
    E_LOGIN_CLOSED = 1, E_STUDENT_NOT_FOUND = 2, E_STUDENT_IN_USE = 3, E_DEVICE_IN_USE = 4,
    E_LOGIN_REQUIRED = 5, E_QUESTION_CLOSED = 6, E_ANSWER_LOCKED = 7, E_REJECTED = 8, E_GATEWAY = 9
};

struct Slot {
    uint8_t addr[3];
    uint8_t kind, seq, value;   // STATUS value: 0 = not logged in, 'L' = logged in, 'A'-'D' = saved answer
};

struct Uplink {   // tag -> gateway; HELLO reports what the tag believes so the web only corrects differences
    uint8_t type = HELLO, seq = 0, counter = 0;
    uint32_t session = 0, question = 0;
    uint8_t logged = 0, answer = 0;
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

// ====================================================================================== 7-segment digits
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

}  // namespace seg

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
    char input[ble::MAX_DIGITS + 1] = {0};
    uint8_t inputLen = 0, seq = 0;
    ble::Uplink request;     // sent until its ACK or ERROR arrives
    char notice[2] = {0};
    unsigned long lastHeard = 0, noticeAt = 0, activeAt = 0;

    void wake(unsigned long now) { awake = true; activeAt = now; }   // display on, sleep timer restarted

    // ------------------------------------------------------------------ from the gateway
    void onDownlink(const ble::Downlink &d, unsigned long now) {
        heard = true; lastHeard = now;
        if (d.session != session) { logged = false; accepted = false; selected = 0; clearInput(); wake(now); }
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
        if (s.kind == ble::STATUS) {   // server truth for this tag; never overrides a request still waiting
            if (pending) return;
            logged = s.value != 0;
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
        u.session = session; u.question = question; u.logged = logged;
        u.answer = accepted ? uint8_t(selected) : 0;
        return u;
    }

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
        if (state == ble::FINISHED) return {'E', 'n', false};
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

  private:
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

// ====================================================================================== Hardware
#ifdef ARDUINO
#include <bluefruit.h>
#include <nrf_gpio.h>

Tag tag;
QueueHandle_t downlinks;   // STATE frames from the scanner callback (BLE task) to loop()

// ------------------------------------------------------------------ display: call these to show anything
namespace display {

uint32_t shown = 0x10000;   // last word latched; out of 16-bit range forces the first write

// 16 bits into U1 -> U2, then a rising edge on RCLK copies them to the outputs at once.
void latch(uint16_t word) {
    if (word == shown) return;
    nrf_gpio_pin_clear(SEG_LATCH);
    for (int i = 15; i >= 0; --i) {
        nrf_gpio_pin_write(SEG_DATA, (word >> i) & 1);
        delayMicroseconds(1);   // data setup before the clock edge
        nrf_gpio_pin_set(SEG_CLOCK);
        delayMicroseconds(1);   // a bare set/clear pulse is ~40 ns: too short for a 74HC595 on 2 x AAA and long rework wires
        nrf_gpio_pin_clear(SEG_CLOCK);
    }
    delayMicroseconds(1);
    nrf_gpio_pin_set(SEG_LATCH);
    shown = word;
}

void show(char tens, char units) { latch(seg::word(tens, units)); }          // two characters, ' ' = dark
void showChar(char c) { show(' ', c); }                                       // one character on the units digit
void showNumber(unsigned n) { show(seg::tensOf(n), seg::unitsOf(n)); }        // 0..99, tens dark below 10
void allOn() { latch(seg::ALL_ON); }
void off() { latch(seg::ALL_OFF); }

void begin() {
    nrf_gpio_cfg_output(SEG_DATA); nrf_gpio_cfg_output(SEG_CLOCK); nrf_gpio_cfg_output(SEG_LATCH);
    nrf_gpio_pin_clear(SEG_CLOCK);
    off();   // the 595s power up with random outputs and OE is tied low
}

}  // namespace display

// ------------------------------------------------------------------ motor
namespace motor {

unsigned long until = 0;

void begin() { nrf_gpio_cfg_output(MOTOR); nrf_gpio_pin_clear(MOTOR); }
void buzz(unsigned long now) { nrf_gpio_pin_set(MOTOR); until = now + VIBRATE_MS; }
void update(unsigned long now) { if (until && now >= until) { nrf_gpio_pin_clear(MOTOR); until = 0; } }

}  // namespace motor

// ------------------------------------------------------------------ key matrix
namespace keypad {

keys::Debouncer debouncer;
unsigned long lastScan = 0;

void begin() {
    // Columns open-drain, released when idle: two keys held on one row cannot short two columns.
    for (uint32_t pin : COL_PINS) {
        nrf_gpio_cfg(pin, NRF_GPIO_PIN_DIR_OUTPUT, NRF_GPIO_PIN_INPUT_DISCONNECT, NRF_GPIO_PIN_NOPULL,
                     NRF_GPIO_PIN_S0D1, NRF_GPIO_PIN_NOSENSE);
        nrf_gpio_pin_set(pin);
    }
    for (uint32_t pin : ROW_PINS) nrf_gpio_cfg_input(pin, NRF_GPIO_PIN_PULLUP);
}

uint16_t read() {
    uint16_t pressed = 0;
    for (int c = 0; c < 4; ++c) {
        nrf_gpio_pin_clear(COL_PINS[c]);
        delayMicroseconds(3);
        for (int r = 0; r < 3; ++r)
            if (!nrf_gpio_pin_read(ROW_PINS[r])) pressed |= keys::mask(r, c);
        nrf_gpio_pin_set(COL_PINS[c]);
    }
    return pressed;
}

// Every 5 ms while the display is on, every 50 ms while asleep (a press still wakes it).
void update(unsigned long now) {
    if (now - lastScan < (tag.awake ? SCAN_MS : SCAN_MS * 10)) return;
    lastScan = now;
    const uint16_t pressed = debouncer.update(read(), now);
    for (int i = 0; i < 12; ++i)
        if ((pressed >> i) & 1) tag.press(keys::at(i), now);
}

}  // namespace keypad

// ------------------------------------------------------------------ radio
namespace radio {

uint8_t advert[2 + ble::UPLINK_MAX];
uint8_t advertLen = 0, counter = 0;
unsigned long advertAt = 0;

// Re-advertises when the frame changes or its refresh period ends; each refresh bumps the counter.
void update(unsigned long now) {
    ble::Uplink u = tag.uplink();
    u.counter = counter;
    uint8_t data[sizeof advert] = {uint8_t(ble::COMPANY), uint8_t(ble::COMPANY >> 8)};
    uint8_t len = uint8_t(2 + ble::encodeUplink(u, data + 2));
    const unsigned long period = tag.pending ? RETRY_MS : HELLO_MS;
    if (len == advertLen && !memcmp(data, advert, len) && now - advertAt < period) return;
    u.counter = ++counter;
    len = uint8_t(2 + ble::encodeUplink(u, data + 2));
    memcpy(advert, data, len); advertLen = len; advertAt = now;
    const uint16_t interval = tag.pending ? ADV_FAST : ADV_SLOW;
    Bluefruit.Advertising.stop();
    Bluefruit.Advertising.clearData();
    Bluefruit.Advertising.addManufacturerData(data, len);
    Bluefruit.Advertising.setType(BLE_GAP_ADV_TYPE_NONCONNECTABLE_NONSCANNABLE_UNDIRECTED);
    Bluefruit.Advertising.setInterval(interval, interval);
    Bluefruit.Advertising.start(0);
}

void onScan(ble_gap_evt_adv_report_t *report) {
    uint8_t data[32];
    const uint8_t len = Bluefruit.Scanner.parseReportByType(report, BLE_GAP_AD_TYPE_MANUFACTURER_SPECIFIC_DATA, data, sizeof data);
    ble::Downlink d;
    if (len > 2 && data[0] == uint8_t(ble::COMPANY) && data[1] == uint8_t(ble::COMPANY >> 8) &&
        ble::decodeDownlink(data + 2, uint8_t(len - 2), d))
        xQueueSend(downlinks, &d, 0);
    Bluefruit.Scanner.resume();
}

void begin() {
    Bluefruit.autoConnLed(false);
    Bluefruit.begin(1, 1);
    Bluefruit.setTxPower(4);
    const ble_gap_addr_t own = Bluefruit.getAddr();
    memcpy(tag.addr, own.addr, 3);
    Serial.printf("NRF-%02X%02X%02X%02X%02X%02X\n", own.addr[5], own.addr[4], own.addr[3], own.addr[2], own.addr[1], own.addr[0]);
    Bluefruit.Scanner.setRxCallback(onScan);
    Bluefruit.Scanner.restartOnDisconnect(true);
    Bluefruit.Scanner.filterMSD(ble::COMPANY);
    Bluefruit.Scanner.setInterval(SCAN_INTERVAL, SCAN_WINDOW);
    Bluefruit.Scanner.useActiveScan(false);
    Bluefruit.Scanner.start(0);
}

}  // namespace radio

// P0.09 (segment data) and P0.10 (key row 3) are the NFC pads. They work as GPIO only once UICR releases them; that
// setting lives in flash, so it is written once and the chip restarts.
void releaseNfcPins() {
    if ((NRF_UICR->NFCPINS & UICR_NFCPINS_PROTECT_Msk) != (UICR_NFCPINS_PROTECT_NFC << UICR_NFCPINS_PROTECT_Pos)) return;
    NRF_NVMC->CONFIG = NVMC_CONFIG_WEN_Wen << NVMC_CONFIG_WEN_Pos;
    while (!NRF_NVMC->READY) {}
    NRF_UICR->NFCPINS &= ~UICR_NFCPINS_PROTECT_Msk;
    while (!NRF_NVMC->READY) {}
    NRF_NVMC->CONFIG = NVMC_CONFIG_WEN_Ren << NVMC_CONFIG_WEN_Pos;
    while (!NRF_NVMC->READY) {}
    NVIC_SystemReset();
}

void render(unsigned long now) {
    const Screen s = tag.screen(now);
    if (!tag.awake) display::off();
    else if (ONE_DIGIT) display::show(oneDigit(s, now), ' ');
    else if (s.blink && (now / BLINK_MS) % 2) display::off();
    else display::show(s.tens, s.units);
}

void setup() {
    releaseNfcPins();
    Serial.begin(115200);
    display::begin();
    motor::begin();
    keypad::begin();
    downlinks = xQueueCreate(8, sizeof(ble::Downlink));

    display::allOn();          // lamp test, then "--" until the gateway is heard
    delay(LAMP_TEST_MS);
    radio::begin();
    tag.wake(millis());
}

void loop() {
    const unsigned long now = millis();
    ble::Downlink d;
    while (xQueueReceive(downlinks, &d, 0) == pdTRUE) tag.onDownlink(d, now);
    keypad::update(now);
    if (tag.vibrate) { tag.vibrate = false; motor::buzz(now); }
    motor::update(now);
    radio::update(now);
    render(now);
    delay(2);   // FreeRTOS tickless idle sleeps the CPU between iterations
}

#endif  // ARDUINO
