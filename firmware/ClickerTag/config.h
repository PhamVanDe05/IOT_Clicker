#pragma once
// Pins and timing of the Student Remote PCB. Change a pin or a delay here, nowhere else.
#include <stdint.h>

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

const unsigned long STARTUP_STEP_MS = 200;    // one segment per step around the two digits
const uint8_t STARTUP_ROUNDS = 3;
const unsigned long VIBRATE_MS = 60;           // one light buzz: raise for a stronger one
const unsigned long DEBOUNCE_MS = 30, SCAN_MS = 5, BLINK_MS = 250;
// One-digit mode: only the tens (left) digit is used. A one-character screen (question 1, answer A) sits on it;
// a two-character screen ("12", "Lo", "A=") shows its characters in turn: first for CHAR_MS, dark for GAP_MS, second,
// dark again; 1 s per cycle. The gap tells "11" from "1". Set false once both digits work: they then show side by side.
const bool ONE_DIGIT = false;
const unsigned long CHAR_MS = 400, GAP_MS = 100;
const unsigned long NOTICE_MS = 1500;          // "Hi" / error code on screen
const unsigned long GATEWAY_TIMEOUT_MS = 5000; // no STATE heard for this long: "nC"
const unsigned long DISPLAY_SLEEP_MS = 15000;  // digits off to save the cells (the LEDs draw the most); any key wakes them
// End of the test: score SCORE_SHOW_MS, then "En" END_SHOW_MS, then the display sleeps. A logged-in tag still waiting
// for its score shows "En" up to SCORE_WAIT_MS.
const unsigned long SCORE_SHOW_MS = 5000, END_SHOW_MS = 3000, SCORE_WAIT_MS = 10000;
// Advert refresh: a request waiting for its ACK, the heartbeat with the display on, the heartbeat while asleep.
const unsigned long RETRY_MS = 300, HELLO_MS = 1000, HELLO_SLEEP_MS = 3000;
const uint16_t ADV_FAST = 160, ADV_SLOW = 800;         // advertising interval, 0.625 ms units
const int8_t TX_POWER = 0;                             // dBm; a classroom is in range at 0 (+4 costs more current)

// Listening. The gateway advertises every 30 ms, so a 40 ms window always catches one frame and the delay is the
// interval. The radio receiver is the tag's biggest cost after the LEDs: listen often only when it matters.
const uint16_t SCAN_WINDOW = 64;           // 40 ms (0.625 ms units)
const uint16_t SCAN_ACK = 160;             // 100 ms: waiting for the ACK of a login or answer
const uint16_t SCAN_AWAKE = 400;           // 250 ms: display on during a session
const uint16_t SCAN_ASLEEP = 1600;         // 1 s: display off, waiting for the next question
const uint16_t SCAN_IDLE = 3200;           // 2 s: display off and no running session (or no gateway)

// nRF52840 DC/DC converter for the radio's core supply (about 40 % less radio current). Only for a module that has the
// inductor on DCC; without it the chip browns out and resets. The tag prints "DCDC on at boot: 1" when the bootloader
// already runs it, which proves the inductor is fitted.
const bool USE_DCDC = false;
