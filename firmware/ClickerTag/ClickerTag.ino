// ClickerTag: firmware for the Student Remote PCB (image/Student Remote, 50 x 75 mm).
// nRF52840 SuperMini, 3 x 4 key matrix, two 74HC595 driving two common-anode 7-segment digits, vibration motor, 2 x AAA.
// Talks to ClickerGateway with BLE advertising only: no pairing, no connection.
//
//   config.h      pins and timing
//   protocol.h    BLE frame layout
//   tag_logic.h   what the tag shows and sends (plain C++, tested on the PC)
//   ClickerTag.ino  hardware: display, motor, keys, radio, setup() and loop()
//
// Both 74HC595: pin 12 RCLK on lat (J2-8), pin 10 SRCLR on 3V.
// Build: Arduino IDE, core "Adafruit nRF52", board "Adafruit ItsyBitsy nRF52840 Express" (SuperMini, nice!nano bootloader).

#include <bluefruit.h>
#include <nrf_gpio.h>
#include "config.h"
#include "protocol.h"
#include "tag_logic.h"

Tag tag;
StartupAnimation startup;
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
uint16_t scanning = SCAN_AWAKE;   // current listen interval

// Listens as often as the tag needs right now (see Tag::scanInterval); restarting the scanner takes well under 1 ms.
void listen(unsigned long now) {
    const uint16_t interval = tag.scanInterval(now);
    if (interval == scanning) return;
    scanning = interval;
    Bluefruit.Scanner.stop();
    Bluefruit.Scanner.setInterval(scanning, SCAN_WINDOW);
    Bluefruit.Scanner.start(0);
}

// Re-advertises when the frame changes or its refresh period ends; each refresh bumps the counter.
void update(unsigned long now) {
    ble::Uplink u = tag.uplink();
    u.counter = counter;
    uint8_t data[sizeof advert] = {uint8_t(ble::COMPANY), uint8_t(ble::COMPANY >> 8)};
    uint8_t len = uint8_t(2 + ble::encodeUplink(u, data + 2));
    const unsigned long period = tag.helloPeriod();
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
    Serial.printf("DCDC on at boot: %lu\n", (unsigned long)NRF_POWER->DCDCEN);   // 1: the bootloader runs it, inductor fitted
    Bluefruit.autoConnLed(false);
    Bluefruit.begin(1, 1);
    if (USE_DCDC) sd_power_dcdc_mode_set(NRF_POWER_DCDC_ENABLE);
    Bluefruit.setTxPower(TX_POWER);
    const ble_gap_addr_t own = Bluefruit.getAddr();
    memcpy(tag.addr, own.addr, 3);
    Serial.printf("NRF-%02X%02X%02X%02X%02X%02X\n", own.addr[5], own.addr[4], own.addr[3], own.addr[2], own.addr[1], own.addr[0]);
    Bluefruit.Scanner.setRxCallback(onScan);
    Bluefruit.Scanner.restartOnDisconnect(true);
    Bluefruit.Scanner.filterMSD(ble::COMPANY);
    Bluefruit.Scanner.setInterval(scanning, SCAN_WINDOW);
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
    if (startup.running) { display::latch(startup.word()); return; }
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

    radio::begin();
    const unsigned long now = millis();
    tag.wake(now);
    startup.begin(now);
}

void loop() {
    const unsigned long now = millis();
    ble::Downlink d;
    while (xQueueReceive(downlinks, &d, 0) == pdTRUE) tag.onDownlink(d, now);
    const bool startupBuzz = startup.update(now);
    if (!startup.running) keypad::update(now);   // keys start taking input once the 4.8 s effect ends
    if (startupBuzz || (!startup.running && tag.vibrate)) motor::buzz(now);
    tag.vibrate = false;
    motor::update(now);
    radio::update(now);
    radio::listen(now);
    render(now);
    delay(2);   // FreeRTOS tickless idle sleeps the CPU between iterations
}
