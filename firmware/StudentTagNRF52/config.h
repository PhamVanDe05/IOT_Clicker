#pragma once
#include <stdint.h>
// Student Tag PCB (image/StudentTag): nRF52840 SuperMini + 3x4 key matrix + 2 x 74HC595 -> 2 x HDSP-7501 + motor.
// Pins are raw P<port>.<pin> numbers, independent of the Arduino board variant
// (build with "Adafruit Feather nRF52840 Express" or a nice!nano variant selected).
#define PIN(port, pin) (((port) << 5) | (pin))

// Header J1 pin 13 / J2 pins 7, 8 (J1-3, J2-9/10 = GND and J1-5 = 3V confirm the ProMini pinout).
// U1 SER <- data; U1 QH' -> U2 SER; SRCLK <- clk; RCLK <- lat; OE to GND (always on).
// image/mau.png tied RCLK (pin 12) to GND and SRCLR (pin 10) to lat, so the outputs never latched;
// image/StudentTag (schematic and PCB) wires RCLK <- lat, SRCLR -> 3V as this firmware expects.
const uint32_t SEG_DATA = PIN(0, 9), SEG_CLOCK = PIN(0, 20), SEG_LATCH = PIN(0, 17);

// 1 = bench test: bare SuperMini + 4x4 membrane keypad (column C4 open), no display/motor wired.
#ifndef TEST_KEYPAD
#define TEST_KEYPAD 0
#endif

// COL_PINS are driven LOW one at a time, ROW_PINS read with the internal pull-up;
// KEYMAP[read][driven]. P0.10 and P0.09 are the NFC pads, released as GPIO at boot.
#if TEST_KEYPAD
// Keypad rows R1..R4 are the driven lines, keypad columns C1..C3 are read. Layout on the keypad:
// 1 2 3 / 4 5 6 / 7 8 9 / BACK 0 OK (the * and # keys).
const uint32_t ROW_PINS[3] = {PIN(0, 2), PIN(0, 29), PIN(0, 31)};
const uint32_t COL_PINS[4] = {PIN(1, 15), PIN(1, 13), PIN(1, 11), PIN(0, 10)};
const char KEYMAP[3][4] = {
    {'1', '4', '7', 'B'},
    {'2', '5', '8', '0'},
    {'3', '6', '9', 'C'},
};
#else
// PCB: 1N5819 in series with each key, anode on the row: a column driven LOW pulls the row
// of a pressed key LOW through its diode.
const uint32_t ROW_PINS[3] = {PIN(1, 13), PIN(1, 11), PIN(0, 10)};
const uint32_t COL_PINS[4] = {PIN(1, 15), PIN(0, 2), PIN(0, 29), PIN(0, 31)};
// SW1..SW12 row by row. 'B' = BACK, 'C' = OK (the keys TagController expects).
const char KEYMAP[3][4] = {
    {'1', '2', '3', '4'},
    {'5', '6', '7', '8'},
    {'B', '9', '0', 'C'},   // bottom row: BACK at the left, as printed on the PCB
};
#endif

// Optional SH1106G 128x64 OLED (the ESP32 one), I2C 0x3C. Not on the PCB: if it does not answer,
// only the 7-segment pair is driven.
const uint32_t OLED_SDA = PIN(0, 24), OLED_SCL = PIN(1, 0);

// AO3400A gate through 470 Ohm with a 10 kOhm pull-down; motor on 3V with a flyback diode. HIGH = on.
const uint32_t MOTOR = PIN(0, 22);

const unsigned DEBOUNCE_MS = 30, SCAN_MS = 5, BLINK_MS = 250;
const unsigned VIBRATE_ON_MS = 120, VIBRATE_OFF_MS = 100;
// Advertising interval in 0.625 ms units: fast while a request waits for ACK, slow for heartbeat.
const uint16_t ADV_FAST = 160, ADV_SLOW = 800;
// Advert refresh (counter bump): retry of a pending request, and HELLO heartbeat.
const unsigned RETRY_MS = 300, HELLO_MS = 1000;
// Scan 30 ms every 100 ms to save battery; the bridge repeats each ACK many times.
const uint16_t SCAN_INTERVAL = 160, SCAN_WINDOW = 48;
