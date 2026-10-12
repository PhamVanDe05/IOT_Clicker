// Student Remote: nRF52840 ProMini + 4x3 key matrix + 2 x 74HC595 -> 2 x 7-segment + vibration motor.
// Talks to the classroom server's BLE bridge using advertising only (no connection).
#include <bluefruit.h>
#include <nrf_gpio.h>
#include <Wire.h>
#include <Adafruit_SH110X.h>
#include "config.h"
#include "protocol.h"
#include "tag_controller.h"
#include "board.h"

TagController tag;
QueueHandle_t downlinks;
uint8_t advertised[2 + ble::UPLINK_MAX];
uint8_t advertisedLen = 0, advertCounter = 0;
unsigned long advertisedAt = 0, sentAt = 0;  // sentAt: first advert of the pending request
board::Debouncer keys;
unsigned long lastScan = 0, motorUntil = 0, motorNext = 0;
uint8_t motorPulses = 0;
uint32_t shownWord = 0x10000;  // last word latched; out of 16-bit range forces the first write
Adafruit_SH1106G oled(128, 64, &Wire, -1);
bool hasOled = false;
char oledShown[48] = "";      // last frame drawn; I2C redraws are slow, so only on change

// 16 bits MSB first into U1 -> U2, then a rising edge on RCLK copies them to the outputs.
void shiftWord(uint16_t word) {
    nrf_gpio_pin_clear(SEG_LATCH);
    for (int i = 15; i >= 0; --i) {
        nrf_gpio_pin_write(SEG_DATA, (word >> i) & 1);
        nrf_gpio_pin_set(SEG_CLOCK);
        nrf_gpio_pin_clear(SEG_CLOCK);
    }
    nrf_gpio_pin_set(SEG_LATCH);
}

void showWord(uint16_t word) {
    if (word == shownWord) return;
    shiftWord(word);
    shownWord = word;
}

void showChars(char left, char right) { showWord(board::chainWord(segments(left), segments(right))); }

// Drive one column LOW at a time and read the rows: a pressed key pulls its row LOW through the diode.
uint16_t readMatrix() {
    uint16_t pressed = 0;
    for (int c = 0; c < 4; ++c) {
        nrf_gpio_pin_clear(COL_PINS[c]);
        delayMicroseconds(3);
        for (int r = 0; r < 3; ++r)
            if (!nrf_gpio_pin_read(ROW_PINS[r])) pressed |= board::keyBit(r, c);
        nrf_gpio_pin_set(COL_PINS[c]);
    }
    return pressed;
}

void scanKeys(unsigned long now) {
    const uint16_t pressed = keys.update(readMatrix(), now);
    for (int bit = 0; bit < 12; ++bit)
        if ((pressed >> bit) & 1) {
            const bool used = tag.press(board::keyOf(bit), now);
            Serial.printf("[key] %c %s input=%s state=%u pending=%d\n", board::keyOf(bit),
                          used ? "" : "(ignored)", tag.input, tag.state, tag.pending);
        }
}

void driveMotor(unsigned long now) {
    if (tag.vibrate) { motorPulses = tag.vibrate; tag.vibrate = 0; motorNext = now; }
    if (motorUntil && now >= motorUntil) { nrf_gpio_pin_clear(MOTOR); motorUntil = 0; motorNext = now + VIBRATE_OFF_MS; }
    if (!motorUntil && motorPulses && now >= motorNext) {
        nrf_gpio_pin_set(MOTOR); motorUntil = now + VIBRATE_ON_MS; --motorPulses;
    }
}

// Wire takes Arduino pin numbers, which differ per board variant: find the one mapped to a raw pin.
uint8_t arduinoPin(uint32_t raw) {
    for (uint8_t i = 0; i < PINS_COUNT; ++i) if (g_ADigitalPinMap[i] == raw) return i;
    return 0xFF;
}

void setupOled() {
    const uint8_t sda = arduinoPin(OLED_SDA), scl = arduinoPin(OLED_SCL);
    if (sda == 0xFF || scl == 0xFF) return;
    Wire.setPins(sda, scl);
    hasOled = oled.begin(0x3C, true);
    Wire.setClock(400000);  // a full frame takes ~25 ms instead of ~100 ms, the key scan stays responsive
    Serial.println(hasOled ? "OLED OK" : "OLED not found (SDA P0.24, SCL P1.00)");
}

// Status line on top, the 7-segment pair's two characters in the middle, the MSSV being typed below.
void drawOled(const Screen &s, bool dark, unsigned long now) {
    char frame[sizeof oledShown];
    const char *typed = tag.pending && tag.request.type == ble::LOGIN ? tag.request.student : tag.input;
    snprintf(frame, sizeof frame, "%c%c|%s|%s", dark ? ' ' : s.left, dark ? ' ' : s.right,
             tag.awake ? typed : "", tag.statusText(now));
    if (!strcmp(frame, oledShown)) return;
    strcpy(oledShown, frame);
    oled.clearDisplay();
    oled.setTextColor(SH110X_WHITE);
    oled.setTextSize(1); oled.setCursor(0, 0); oled.print(tag.statusText(now));
    oled.setTextSize(3); oled.setCursor(46, 16); oled.print(frame[0]); oled.print(frame[1]);  // 2 x 18 px wide
    oled.setTextSize(2); oled.setCursor(0, 46); oled.print(tag.awake ? typed : "");
    oled.display();
}

// OE is wired to GND, so "dark" (asleep, or the off phase of a blink) is sent as all segments off.
void render(unsigned long now) {
    const Screen s = tag.screen(now);
    const bool dark = !tag.awake || (s.blink && (now / BLINK_MS) % 2);
    if (dark) showWord(board::BLANK); else showChars(s.left, s.right);
    if (hasOled) drawOled(s, dark, now);
}

// Re-sends the advert when its content changes or the refresh period ends; each refresh bumps
// the counter so the bridge (BlueZ) sees heartbeats and retries of an unchanged request.
void advertise(unsigned long now) {
    ble::Uplink u = tag.uplink();
    const unsigned period = tag.pending ? RETRY_MS : HELLO_MS;
    u.counter = advertCounter;
    uint8_t data[sizeof advertised];
    data[0] = uint8_t(ble::COMPANY); data[1] = uint8_t(ble::COMPANY >> 8);
    uint8_t len = uint8_t(2 + ble::encodeUplink(u, data + 2));
    if (len == advertisedLen && !memcmp(data, advertised, len) && now - advertisedAt < period) return;
    u.counter = ++advertCounter;
    if (u.type != ble::HELLO && u.seq != advertised[2 + 4]) { sentAt = now; Serial.printf("[ble] send type=%u seq=%u\n", u.type, u.seq); }
    len = uint8_t(2 + ble::encodeUplink(u, data + 2));
    memcpy(advertised, data, len); advertisedLen = len; advertisedAt = now;
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

// P0.09 (data) and P0.10 (row3) are the NFC antenna pads. Unless UICR releases them they stay NFC
// and ignore GPIO; the setting lives in flash, so this writes it once and restarts.
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

void setupPins() {
    const uint32_t outputs[] = {SEG_DATA, SEG_CLOCK, SEG_LATCH, MOTOR};
    for (uint32_t pin : outputs) nrf_gpio_cfg_output(pin);
    nrf_gpio_pin_clear(MOTOR);
    nrf_gpio_pin_clear(SEG_CLOCK);
    // Open-drain, idle released: two keys held on one row cannot short two columns, even without diodes.
    for (uint32_t pin : COL_PINS) {
        nrf_gpio_cfg(pin, NRF_GPIO_PIN_DIR_OUTPUT, NRF_GPIO_PIN_INPUT_DISCONNECT, NRF_GPIO_PIN_NOPULL,
                     NRF_GPIO_PIN_S0D1, NRF_GPIO_PIN_NOSENSE);
        nrf_gpio_pin_set(pin);
    }
    for (uint32_t pin : ROW_PINS) nrf_gpio_cfg_input(pin, NRF_GPIO_PIN_PULLUP);
    showWord(board::BLANK);  // the 595s power up with random outputs and OE cannot hide them
}

void setup() {
    releaseNfcPins();
    Serial.begin(115200);
    setupPins();
    setupOled();
    downlinks = xQueueCreate(8, sizeof(ble::Downlink));

    Bluefruit.autoConnLed(false);
    Bluefruit.begin(1, 1);
    Bluefruit.setTxPower(4);
    const ble_gap_addr_t addr = Bluefruit.getAddr();
    memcpy(tag.addr, addr.addr, 3);
    char uid[17];
    snprintf(uid, sizeof uid, "NRF-%02X%02X%02X%02X%02X%02X", addr.addr[5], addr.addr[4], addr.addr[3], addr.addr[2], addr.addr[1], addr.addr[0]);
    Serial.println(uid);  // device_uid shown on the teacher dashboard

    // Lamp test, then the last address byte so a teacher can match tag and dashboard row.
    const char hex[] = "0123456789AbCdEF";
    showWord(board::chainWord(0x7F, 0x7F)); delay(600);  // lamp test: 88
    showChars(hex[addr.addr[0] >> 4], hex[addr.addr[0] & 15]); delay(1200);
    tag.wake(millis());

    Bluefruit.Scanner.setRxCallback(onScan);
    Bluefruit.Scanner.restartOnDisconnect(true);
    Bluefruit.Scanner.filterMSD(ble::COMPANY);
    Bluefruit.Scanner.setInterval(SCAN_INTERVAL, SCAN_WINDOW);
    Bluefruit.Scanner.useActiveScan(false);
    Bluefruit.Scanner.start(0);
    advertise(millis());
}

void loop() {
    const unsigned long now = millis();
    ble::Downlink d;
    while (xQueueReceive(downlinks, &d, 0) == pdTRUE) {
        const bool wasPending = tag.pending;
        tag.onDownlink(d, now);
        if (wasPending && !tag.pending) Serial.printf("[ble] reply for seq %u after %lu ms\n", tag.request.seq, now - sentAt);
    }
    // Scan every 5 ms while the display is on, every 50 ms while asleep (a press still wakes it).
    if (now - lastScan >= (tag.awake ? SCAN_MS : SCAN_MS * 10)) { lastScan = now; scanKeys(now); }
    advertise(now);
    driveMotor(now);
    render(now);
    delay(2);  // FreeRTOS tickless idle sleeps the CPU between iterations
}
