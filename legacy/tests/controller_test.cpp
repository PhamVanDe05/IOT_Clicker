#include "../firmware/SmartClickerESP32/controller.h"
#include "../firmware/SmartClickerESP32/button_input.h"
#include <cassert>
#include <iostream>
int main() {
    // Current wiring: three buttons (1, BACK, OK) on GPIO34/35/32; see config.h.
    static_assert(BUTTON_COUNT == 3, "Buttons 1, BACK, OK");
    static_assert(BUTTON_PINS[0] == 34 && BUTTON_PINS[1] == 35 && BUTTON_PINS[2] == 32, "Physical button order changed");
    static_assert(BUTTON_KEYS[0] == '1' && BUTTON_KEYS[1] == 'B' && BUTTON_KEYS[2] == 'C', "Button keys changed");
    constexpr unsigned D = DEBOUNCE_MS;
    ButtonInput digit, back, ok;
    assert(!digit.update(true, 100, '0'));
    assert(!digit.update(true, 100 + D - 1, '0'));
    assert(digit.update(true, 100 + D, '0') == '0');
    assert(!digit.update(true, 1135, '0'));
    assert(!digit.update(false, 1140, '0'));
    assert(!digit.update(false, 1140 + D, '0'));
    assert(!back.update(true, 200, 'B'));
    assert(back.update(true, 200 + D, 'B') == 'B');
    assert(!ok.update(true, 300, 'C'));
    assert(ok.update(true, 300 + D, 'C') == 'C');
    Controller c;
    c.key('1'); c.confirm(); assert(c.pending.action.empty());
    c.poll(1,true,"",0,0,0); c.key('0');c.key('1');assert(c.input=="01");c.backspace();assert(c.input=="0");c.backspace();assert(c.input.empty());
    for(char d:std::string("00146217"))c.key(d);
    c.confirm();assert(c.pending.student=="00146217");c.key('2');assert(c.input=="00146217");
    assert(!c.ack(2,0,0,"00146217"));assert(!c.ack(1,0,0,"wrong"));assert(c.ack(1,0,0,"00146217"));
    c.poll(1,false,"00146217",9,12,0);c.key('5');c.key('0');c.confirm();assert(c.pending.action.empty());
    c.key('3');c.backspace();assert(!c.selected);c.key('2');c.confirm();assert(c.pending.answer=='B' && c.pending.question==9);
    c.poll(2,true,"",0,0,0);assert(c.pending.session==1 && c.pending.question==9);
    assert(!c.ack(1,9,'C',""));assert(c.ack(1,9,'B',""));assert(!c.accepted);
    c.poll(2,false,"23146215",10,1,0);c.key('1');c.confirm();assert(c.ack(2,10,'A',""));
    c.key('4');c.confirm();assert(c.selected=='A' && c.pending.action.empty());
    c.poll(2,false,"23146215",11,2,0);assert(!c.accepted && !c.selected);
    c.poll(2,false,"23146215",11,2,'C');assert(c.accepted && c.selected=='C');
    c.poll(0,false,"",0,0,0);assert(c.session==0 && c.student.empty() && !c.question && !c.accepted && !c.selected);
    std::cout<<"PASS: 3 buttons, debounce, BACK/OK, login, retry, stale ACK, immutable answer, next question\n";
}
