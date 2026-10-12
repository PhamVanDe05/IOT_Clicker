#!/usr/bin/env python3
"""Student Tag schematic, drawn as image/mau.png with its two wiring fixes: every net must match exactly.

    python3 image/StudentTag/gen_schematic.py && python3 tests/schematic_test.py

Needs kicad-cli (KiCad 10). The expected nets are read off image/mau.png, fixed where the reference was wrong:
74HC595 RCLK <- lat and SRCLR -> 3v (as firmware/StudentTagNRF52 drives them), Q2 drain towards the battery.
ERC may only report what the reference itself has: pins left open and power nets fed by the "3v" label.
"""
import re
import subprocess
import sys
import tempfile
from pathlib import Path

SCH = Path(__file__).resolve().parents[1] / 'image/StudentTag/StudentTag.kicad_sch'
INHERITED = {'pin_not_connected', 'pin_not_driven', 'power_pin_not_driven'}


def expected():
    nets = [
        {'J2.9', 'J2.10', 'J1.3', 'J4.1', 'Q2.1', 'C1.2', 'C2.2', 'C3.2', 'C4.2', 'C5.2', 'U1.8', 'U2.8',
         'U1.13', 'U2.13', 'R16.2', 'Q1.2'},                         # ~OE on GND
        {'J1.5', 'Q2.2', 'C1.1', 'C2.1', 'C3.1', 'C4.1', 'C5.1', 'U1.16', 'U2.16', 'U1.10', 'U2.10',
         'U3.6', 'U4.1', 'D13.1', 'J3.2'},                           # "3v"; ~SRCLR held high
        {'J4.2', 'SW13.1'}, {'SW13.2', 'Q2.3'},                      # battery -> switch -> Q2 drain
        {'J2.8', 'U1.12', 'U2.12'}, {'J2.7', 'U1.11', 'U2.11'},      # lat -> RCLK, clk -> SRCLK
        {'J1.13', 'U1.14'}, {'U1.9', 'U2.14'},                       # data -> U1 SER, U1 QH' -> U2 SER
        {'J2.6', 'R15.1'}, {'R15.2', 'R16.1', 'Q1.1'}, {'Q1.3', 'J3.1', 'D13.2'},
    ]
    rows = {1: 'J1.10', 2: 'J1.11', 3: 'J1.12'}
    cols = {1: 'J1.9', 2: 'J1.8', 3: 'J1.7', 4: 'J1.6'}
    for r in rows:
        nets.append({rows[r]} | {f'D{(r - 1) * 4 + c}.2' for c in cols})     # diode anode on the row
    for c in cols:
        nets.append({cols[c]} | {f'SW{(r - 1) * 4 + c}.2' for r in rows})
    for n in range(1, 13):
        nets.append({f'SW{n}.1', f'D{n}.1'})
    for u, display, names in (('U1', 'U3', (1, 2, 3, 4, 5, 6, 7)), ('U2', 'U4', (8, 12, 9, 13, 10, 14, 11))):
        for k, (q, seg) in enumerate(zip((15, 1, 2, 3, 4, 5, 6), (10, 9, 8, 5, 4, 2, 3))):   # QA..QG -> 470R -> A..G
            nets += [{f'{u}.{q}', f'R{names[k]}.1'}, {f'R{names[k]}.2', f'{display}.{seg}'}]
    unused = {f'J1.{p}' for p in (1, 2, 4)} | {f'J2.{p}' for p in (1, 2, 3, 4, 5, 11, 12, 13)} \
        | {'U1.7', 'U2.7', 'U2.9', 'U3.1', 'U3.7', 'U4.6', 'U4.7', 'SW13.3'}
    return {frozenset(n) for n in nets}, unused


def main():
    failures = 0
    with tempfile.TemporaryDirectory() as tmp:
        subprocess.run(['kicad-cli', 'sch', 'erc', '--severity-all', '-o', f'{tmp}/erc.rpt', str(SCH)], check=True, capture_output=True)
        report = Path(f'{tmp}/erc.rpt').read_text()
        kinds = re.findall(r'^\[(\w+)\]', report, re.M)
        unexpected = [k for k in kinds if k not in INHERITED]
        counts = ', '.join(f'{kinds.count(k)} {k}' for k in sorted(set(kinds))) or 'none'
        print('PASS' if not unexpected else 'FAIL', f'ERC only reports what the reference has ({counts})')
        failures += bool(unexpected)
        subprocess.run(['kicad-cli', 'sch', 'export', 'netlist', '--format', 'kicadsexpr', '-o', f'{tmp}/net', str(SCH)],
                       check=True, capture_output=True)
        text = Path(f'{tmp}/net').read_text()
    nets = []
    for block in re.findall(r'\(net\s+\(code "?\d+"?\)\s+\(name "[^"]*"\)(.*?)\)\s*(?=\(net\s|\)\s*\)\s*$)', text, re.S):
        nets.append(frozenset(f'{r}.{p}' for r, p in re.findall(r'\(node\s+\(ref "([^"]+)"\)\s+\(pin "([^"]+)"\)', block)))
    real = {n for n in nets if len(n) > 1}
    single = {next(iter(n)) for n in nets if len(n) == 1}
    want, unused = expected()
    for label, ok, detail in [
        ('every intended net is present', want <= real, sorted(map(sorted, want - real))),
        ('no extra or merged nets', real <= want, sorted(map(sorted, real - want))),
        ('unused pins are exactly the planned no-connects', single == unused, sorted(single ^ unused)),
    ]:
        print('PASS' if ok else 'FAIL', label, '' if ok else detail)
        failures += not ok
    print(f'{len(real)} nets checked, {sum(map(len, real))} pin connections')
    print('ALL PASS' if not failures else f'{failures} FAIL')
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
