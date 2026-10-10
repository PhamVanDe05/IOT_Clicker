// ClickerGateway: nRF52840 SuperMini on the teacher laptop's USB. Relays BLE adverts between the tags (ClickerTag) and
// the teacher web page (web/gateway.js, Web Serial at 115200 baud). It never talks to the server itself: the web page
// calls the API with the teacher's session and only then sends the ACK down.
//
//   web -> gateway   HELLO                     gateway answers HELLO BRIDGE-<MAC>
//   web -> gateway   FRAME <27-byte hex>       STATE frame advertised to every tag until the next one
//   gateway -> web   TAG <MAC> <hex>           one tag advert (HELLO, LOGIN or ANSWER), repeats filtered out
//
// No FRAME for 3 s (tab closed, cable pulled on the web side): the gateway advertises OFFLINE, tags show "nS".
// Build: Arduino IDE, core "Adafruit nRF52", board "Adafruit ItsyBitsy nRF52840 Express".

#include <bluefruit.h>

// ====================================================================================== Settings
const unsigned long WEB_TIMEOUT_MS = 3000;
const uint16_t ADV_INTERVAL = 160;                    // 100 ms, 0.625 ms units
const uint16_t SCAN_INTERVAL = 160, SCAN_WINDOW = 160; // listen all the time (USB powered)
const uint8_t MAX_TAGS = 64;                          // tags remembered for repeat filtering

// ====================================================================================== BLE frames
// Same layout as ClickerTag.ino; the gateway only checks frames, it never builds tag frames.
namespace ble {

const uint16_t COMPANY = 0xFFFF;
const uint8_t MAGIC0 = 'S', MAGIC1 = 'C', VERSION = 1;
const uint8_t MAX_DIGITS = 20;
enum FrameType : uint8_t { HELLO = 0x01, LOGIN = 0x02, ANSWER = 0x03, STATE = 0x80 };
const uint8_t OFFLINE = 0xFF;
const uint8_t HEADER = 4;
const uint8_t DOWNLINK_SIZE = HEADER + 11 + 12;
const uint8_t UPLINK_MAX = HEADER + 2 + 4 + 1 + MAX_DIGITS / 2;
const uint8_t STATE_AT = 8;   // byte offset of the session state in a STATE frame

inline bool valid(const uint8_t *p, uint8_t len) { return len >= HEADER && p[0] == MAGIC0 && p[1] == MAGIC1 && p[2] == VERSION; }

struct UplinkId { uint8_t type, seq, counter; };   // what tells one tag advert from its repeats

inline bool checkUplink(const uint8_t *p, uint8_t len, UplinkId &id) {
    if (!valid(p, len) || len < 10) return false;
    id = {p[3], p[4], p[5]};
    if (id.type == HELLO) return len >= 16;
    if (id.type == ANSWER) return len >= 15 && p[14] >= 'A' && p[14] <= 'D';
    if (id.type != LOGIN || len < 11) return false;
    const uint8_t n = p[10];
    if (n == 0 || n > MAX_DIGITS || len < 11 + (n + 1) / 2) return false;
    for (uint8_t i = 0; i < n; ++i)
        if (((p[11 + i / 2] >> (i % 2 ? 0 : 4)) & 0x0F) > 9) return false;
    return true;
}

inline bool checkDownlink(const uint8_t *p, uint8_t len) { return valid(p, len) && p[3] == STATE && len >= DOWNLINK_SIZE; }

inline void offlineFrame(uint8_t *out) {   // session 0, state OFFLINE, no question, empty slots
    memset(out, 0, DOWNLINK_SIZE);
    out[0] = MAGIC0; out[1] = MAGIC1; out[2] = VERSION; out[3] = STATE;
    out[STATE_AT] = OFFLINE;
}

}  // namespace ble

// ====================================================================================== Downlink: STATE to the tags
namespace downlink {

uint8_t frame[ble::DOWNLINK_SIZE];        // last frame from the web (or the OFFLINE frame)
uint8_t advertised[ble::DOWNLINK_SIZE];   // what the radio is sending now
unsigned long frameAt = 0;

void advertise() {
    if (!memcmp(advertised, frame, sizeof frame)) return;
    memcpy(advertised, frame, sizeof frame);
    uint8_t data[2 + sizeof frame] = {uint8_t(ble::COMPANY), uint8_t(ble::COMPANY >> 8)};
    memcpy(data + 2, frame, sizeof frame);
    Bluefruit.Advertising.stop();
    Bluefruit.Advertising.clearData();
    Bluefruit.Advertising.addManufacturerData(data, sizeof data);
    Bluefruit.Advertising.setType(BLE_GAP_ADV_TYPE_NONCONNECTABLE_NONSCANNABLE_UNDIRECTED);
    Bluefruit.Advertising.setInterval(ADV_INTERVAL, ADV_INTERVAL);
    Bluefruit.Advertising.start(0);
}

void set(const uint8_t *next) { memcpy(frame, next, sizeof frame); frameAt = millis(); advertise(); }

void goOffline() { ble::offlineFrame(frame); advertise(); }

void begin() { memset(advertised, 0xFF, sizeof advertised); goOffline(); }

// The web went quiet: tell the tags so they show "nS" and keep their requests for later.
void update(unsigned long now) { if (now - frameAt > WEB_TIMEOUT_MS && frame[ble::STATE_AT] != ble::OFFLINE) goOffline(); }

}  // namespace downlink

// ====================================================================================== Uplink: tag adverts to the web
namespace uplink {

struct Report { uint8_t addr[6], len, data[2 + ble::UPLINK_MAX]; };
struct Seen { uint8_t addr[6]; ble::UplinkId id; };

QueueHandle_t reports;   // scanner callback (BLE task) -> loop()
Seen seen[MAX_TAGS] = {};
uint8_t seenCount = 0, seenNext = 0;

// True when this advert repeats the last one from the same tag (same type, seq and counter). Remembers it otherwise.
bool repeated(const uint8_t *addr, const ble::UplinkId &id) {
    for (uint8_t i = 0; i < seenCount; ++i) {
        if (memcmp(seen[i].addr, addr, 6)) continue;
        const bool same = seen[i].id.type == id.type && seen[i].id.seq == id.seq && seen[i].id.counter == id.counter;
        seen[i].id = id;
        return same;
    }
    Seen &slot = seen[seenNext];             // new tag: replace the oldest entry
    memcpy(slot.addr, addr, 6); slot.id = id;
    seenNext = uint8_t((seenNext + 1) % MAX_TAGS);
    if (seenCount < MAX_TAGS) ++seenCount;
    return false;
}

void onScan(ble_gap_evt_adv_report_t *advert) {
    Report r = {};
    r.len = Bluefruit.Scanner.parseReportByType(advert, BLE_GAP_AD_TYPE_MANUFACTURER_SPECIFIC_DATA, r.data, sizeof r.data);
    ble::UplinkId id;
    if (r.len >= 2 + 10 && r.data[0] == uint8_t(ble::COMPANY) && r.data[1] == uint8_t(ble::COMPANY >> 8) &&
        ble::checkUplink(r.data + 2, uint8_t(r.len - 2), id)) {
        memcpy(r.addr, advert->peer_addr.addr, 6);
        if (!repeated(r.addr, id) && Serial) xQueueSend(reports, &r, 0);
    }
    Bluefruit.Scanner.resume();
}

void begin() {
    reports = xQueueCreate(128, sizeof(Report));
    Bluefruit.Scanner.setRxCallback(onScan);
    Bluefruit.Scanner.filterMSD(ble::COMPANY);
    Bluefruit.Scanner.setInterval(SCAN_INTERVAL, SCAN_WINDOW);
    Bluefruit.Scanner.useActiveScan(false);
    Bluefruit.Scanner.start(0);
}

// TAG <MAC, most significant byte first> <payload hex without the company ID>
void update() {
    Report r;
    while (Serial && xQueueReceive(reports, &r, 0) == pdTRUE) {
        Serial.printf("TAG %02X%02X%02X%02X%02X%02X ", r.addr[5], r.addr[4], r.addr[3], r.addr[2], r.addr[1], r.addr[0]);
        for (uint8_t i = 2; i < r.len; ++i) Serial.printf("%02X", r.data[i]);
        Serial.println();
    }
}

}  // namespace uplink

// ====================================================================================== Commands from the web
namespace web {

char uid[20];      // BRIDGE-<MAC>
char line[80];
uint8_t lineLen = 0;

int nibble(char c) {
    if (c >= '0' && c <= '9') return c - '0';
    if (c >= 'A' && c <= 'F') return c - 'A' + 10;
    if (c >= 'a' && c <= 'f') return c - 'a' + 10;
    return -1;
}

// FRAME <54 hex digits>: a STATE frame to advertise. Anything malformed is ignored.
void onFrame(const char *hex) {
    uint8_t next[ble::DOWNLINK_SIZE];
    for (uint8_t i = 0; i < sizeof next; ++i) {
        const int hi = nibble(hex[2 * i]), lo = nibble(hex[2 * i + 1]);
        if (hi < 0 || lo < 0) return;
        next[i] = uint8_t(hi << 4 | lo);
    }
    if (ble::checkDownlink(next, sizeof next)) downlink::set(next);
}

void onLine() {
    line[lineLen] = 0;
    if (!strcmp(line, "HELLO")) Serial.printf("HELLO %s\n", uid);
    else if (!strncmp(line, "FRAME ", 6) && lineLen == 6 + 2 * ble::DOWNLINK_SIZE) onFrame(line + 6);
}

void begin() {
    const ble_gap_addr_t own = Bluefruit.getAddr();
    snprintf(uid, sizeof uid, "BRIDGE-%02X%02X%02X%02X%02X%02X", own.addr[5], own.addr[4], own.addr[3], own.addr[2], own.addr[1], own.addr[0]);
}

void update() {
    while (Serial.available()) {
        const char c = char(Serial.read());
        if (c == '\n') { onLine(); lineLen = 0; }
        else if (c == '\r') continue;
        else if (lineLen < sizeof line - 1) line[lineLen++] = c;
        else lineLen = 0;   // too long for any command: drop it
    }
}

}  // namespace web

// ====================================================================================== Main
void setup() {
    Serial.begin(115200);
    Bluefruit.autoConnLed(false);
    Bluefruit.begin(1, 1);
    Bluefruit.setTxPower(4);
    web::begin();
    downlink::begin();
    uplink::begin();
}

void loop() {
    web::update();
    downlink::update(millis());
    uplink::update();
    delay(2);
}
