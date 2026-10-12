#pragma once
#include "config.h"

struct ButtonInput {
    bool raw = false, pressed = false;
    unsigned long changed = 0;

    char update(bool down, unsigned long now, char key) {
        if (down != raw) { raw = down; changed = now; }
        if (now - changed >= DEBOUNCE_MS && raw != pressed) {
            pressed = raw;
            if (pressed) return key;
        }
        return 0;
    }
};
