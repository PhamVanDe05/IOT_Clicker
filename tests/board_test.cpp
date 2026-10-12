// g++ -std=c++11 -Wall -Wextra -Werror tests/board_test.cpp -o /tmp/clicker-board-test && /tmp/clicker-board-test
// Student Remote PCB logic: 7-segment bytes through the 74HC595 chain and the 4x3 key matrix.
#include "../firmware/StudentTagNRF52/tag_controller.h"
#include "../firmware/StudentTagNRF52/board.h"
#include <cassert>
#include <cstdio>

// Two 74HC595 in a chain as wired: MCU -> U1 SER, U1 QH' -> U2 SER. Returns U1 (low byte) and U2 (high byte)
// outputs after shifting `word` MSB first and latching; output bit n = Q(A+n).
static uint16_t shiftThroughChain(uint16_t word) {
    uint8_t u1 = 0, u2 = 0;  // bit n = Q(A+n): each clock QA takes SER and every stage moves one step toward QH
    for (int i = 15; i >= 0; --i) {
        u2 = uint8_t(u2 << 1 | (u1 >> 7));        // U1 QH' feeds U2 SER
        u1 = uint8_t(u1 << 1 | ((word >> i) & 1));
    }
    return uint16_t(u2 << 8 | u1);
}

int main() {
    // Common anode: '1' lights b and c only, so on the wire only QB and QC are LOW.
    assert(board::segmentByte(segments('1')) == uint8_t(~0x06));
    assert(board::segmentByte(0) == 0xFF);

    // Left digit '1' must reach U1 (digit 1), right digit '2' must reach U2 (digit 2), segment a on QA.
    const uint16_t out = shiftThroughChain(board::chainWord(segments('1'), segments('2')));
    const uint8_t u1 = uint8_t(out & 0xFF), u2 = uint8_t(out >> 8);
    for (int seg = 0; seg < 7; ++seg) {
        const bool litLeft = (segments('1') >> seg) & 1, litRight = (segments('2') >> seg) & 1;
        assert(((u1 >> seg) & 1) == !litLeft);   // LOW = lit
        assert(((u2 >> seg) & 1) == !litRight);
    }
    assert((u1 >> 7) & 1 && (u2 >> 7) & 1);    // QH (no decimal point) stays HIGH
    assert(shiftThroughChain(board::BLANK) == 0xFFFF);

    // Matrix: 3 rows x 4 columns: 1 2 3 4 / 5 6 7 8 / BACK 9 0 OK.
    const char expected[] = "12345678" "B90C";
    for (int r = 0; r < 3; ++r)
        for (int c = 0; c < 4; ++c) {
            const uint16_t bit = board::keyBit(r, c);
            int index = 0; while (!((bit >> index) & 1)) ++index;
            assert(board::keyOf(index) == expected[r * 4 + c]);
        }

    // Debounce: bounce is ignored, a held key fires once, a second key fires on its own.
    board::Debouncer d;
    const uint16_t k1 = board::keyBit(0, 0), ok = board::keyBit(2, 3);
    assert(d.update(k1, 0) == 0);
    assert(d.update(0, 5) == 0);                 // bounce
    assert(d.update(k1, 10) == 0);
    assert(d.update(k1, 10 + DEBOUNCE_MS) == k1);
    assert(d.update(k1, 200) == 0);              // still held: no repeat
    assert(d.update(k1 | ok, 210) == 0);
    assert(d.update(k1 | ok, 210 + DEBOUNCE_MS) == ok);
    assert(d.update(0, 400) == 0);
    assert(d.update(0, 400 + DEBOUNCE_MS) == 0); // release produces nothing
    assert(d.update(k1, 500) == 0);
    assert(d.update(k1, 500 + DEBOUNCE_MS) == k1);

    puts("board_test: 595 chain, common-anode segments, 4x3 keymap, debounce OK");
    return 0;
}
