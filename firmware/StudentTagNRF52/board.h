#pragma once
#include <stdint.h>
#include "config.h"
// Pure board logic shared by the sketch and tests/board_test.cpp (no Arduino calls here).

namespace board {

// segments() in tag_controller.h gives bit0 = a ... bit6 = g, 1 = lit. The HDSP-7501 is common anode,
// so a segment lights when its 74HC595 output is LOW: the byte shifted out is the inverse.
// QH (bit 7) has no decimal point wired and simply stays HIGH.
inline uint8_t segmentByte(uint8_t lit) { return uint8_t(~lit); }

// One latch = 16 clocks, MSB first. Bits 15..8 are pushed through U1 into U2 (digit 2, right),
// bits 7..0 stay in U1 (digit 1, left); in each byte bit 0 lands on QA = segment a.
inline uint16_t chainWord(uint8_t leftLit, uint8_t rightLit) {
    return uint16_t(segmentByte(rightLit) << 8 | segmentByte(leftLit));
}
const uint16_t BLANK = 0xFFFF;  // every output HIGH: both digits dark

// Pressed-key mask: bit (row * 4 + col).
inline uint16_t keyBit(int row, int col) { return uint16_t(1u << (row * 4 + col)); }
inline char keyOf(int bit) { return KEYMAP[bit / 4][bit % 4]; }

// Debounced edge detector: a key counts once when the raw mask has been stable for DEBOUNCE_MS
// and it was not down before. Returns the newly pressed keys.
struct Debouncer {
    uint16_t raw = 0, down = 0;
    unsigned long changedAt = 0;
    uint16_t update(uint16_t now_raw, unsigned long now) {
        if (now_raw != raw) { raw = now_raw; changedAt = now; }
        if (now - changedAt < DEBOUNCE_MS || raw == down) return 0;
        const uint16_t pressed = uint16_t(raw & ~down);
        down = raw;
        return pressed;
    }
};

}  // namespace board
