#!/usr/bin/env python3
"""Generate StudentTag.kicad_sch (KiCad 10) for the Student Tag clicker PCB.

    python3 image/StudentTag/gen_schematic.py

Drawn as image/mau.png (positions read off the image, A4): framed blocks joined by net labels, with two fixes
of the reference (74HC595 RCLK <- lat and SRCLR -> 3v; Q2 drain towards the battery). Symbols come from the stock KiCad libraries
(/usr/share/kicad/symbols) and are embedded in the file, so it opens without extra libraries.
Check with tests/schematic_test.py (every connection against the reference drawing).
"""
import math
import re
import uuid
from pathlib import Path

HERE = Path(__file__).resolve().parent
LIBS = Path('/usr/share/kicad/symbols')
NAME = 'StudentTag'
ROOT = str(uuid.uuid5(uuid.NAMESPACE_URL, 'clicker/StudentTag'))
_ids = 0


def uid():
    # Stable UUIDs: regenerating the file gives the same identities (clean diffs, PCB links stay valid).
    global _ids
    _ids += 1
    return str(uuid.uuid5(uuid.UUID(ROOT), str(_ids)))


# ---------------------------------------------------------------- S-expressions

class Q(str):
    """A quoted string atom."""


TOKEN = re.compile(r'\s*(?:(\()|(\))|"((?:[^"\\]|\\.)*)"|([^\s()"]+))')


def parse(text):
    stack, pos = [[]], 0
    while (m := TOKEN.match(text, pos)) and m.end() > pos:
        pos = m.end()
        open_, close, quoted, atom = m.groups()
        if open_:
            stack.append([])
        elif close:
            node = stack.pop()
            stack[-1].append(node)
        elif quoted is not None:
            stack[-1].append(Q(quoted.replace('\\"', '"').replace('\\\\', '\\')))
        else:
            stack[-1].append(atom)
    return stack[0][0]


def dump(node, depth=0):
    if isinstance(node, Q):
        return '"' + node.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n') + '"'
    if not isinstance(node, list):
        return str(node)
    parts = [dump(child, depth + 1) for child in node]
    flat = '(' + ' '.join(parts) + ')'
    if len(flat) < 100 and not any(isinstance(c, list) and c and c[0] in ('symbol', 'property', 'pin') for c in node[1:]):
        return flat
    pad = '\n' + '\t' * (depth + 1)
    return '(' + parts[0] + ''.join(pad + p for p in parts[1:]) + ')'


def num(value):
    return f'{round(value, 4):g}'


def child(node, key):
    return next((c for c in node if isinstance(c, list) and c and c[0] == key), None)


# ---------------------------------------------------------------- library symbols

_library_cache = {}
lib_symbols = {}


def library(name):
    if name not in _library_cache:
        tree = parse((LIBS / f'{name}.kicad_sym').read_text())
        _library_cache[name] = {c[1]: c for c in tree[1:] if isinstance(c, list) and c[0] == 'symbol'}
    return _library_cache[name]


def flatten(lib, name):
    """The symbol with any `extends` chain resolved into one self-contained definition."""
    node = library(lib)[name]
    base = child(node, 'extends')
    if not base:
        return [x for x in node]
    parent = flatten(lib, base[1])
    own = {c[1]: c for c in node if isinstance(c, list) and c[0] == 'property'}
    out = [parent[0], Q(name)]
    for c in parent[2:]:
        if isinstance(c, list) and c[0] == 'property' and c[1] in own:
            out.append(own.pop(c[1]))
        elif isinstance(c, list) and c[0] == 'symbol':
            out.append([c[0], Q(c[1].replace(base[1], name, 1))] + c[2:])
        else:
            out.append(c)
    out[2:2] = list(own.values())
    return out


def symbol_def(lib_id):
    if lib_id not in lib_symbols:
        lib, name = lib_id.split(':')
        node = flatten(lib, name)
        lib_symbols[lib_id] = ['symbol', Q(lib_id)] + node[2:]
    return lib_symbols[lib_id]


def pins_of(lib_id):
    pins = {}

    def walk(node):
        if isinstance(node, list):
            if node and node[0] == 'pin':
                at = child(node, 'at')
                pins[str(child(node, 'number')[1])] = (float(at[1]), float(at[2]))
            for c in node:
                walk(c)
    walk(symbol_def(lib_id))
    return pins


def lib_property(lib_id, key):
    prop = next((c for c in symbol_def(lib_id) if isinstance(c, list) and c[0] == 'property' and c[1] == key), None)
    return str(prop[2]) if prop else ''


# ---------------------------------------------------------------- schematic items

items, wires, points, refs = [], [], [], {}
FONT = ['effects', ['font', ['size', '1.27', '1.27']]]


def rotate(x, y, angle):
    a = math.radians(angle)
    return x * math.cos(a) - y * math.sin(a), x * math.sin(a) + y * math.cos(a)


class Part:
    def __init__(self, lib_id, ref, value, x, y, rot=0, footprint=None, fields='lib', hide_value=False, dx=2.54, mirror=None):
        self.lib_id, self.ref, self.value, self.x, self.y, self.rot, self.mirror = lib_id, ref, value, x, y, rot, mirror
        self.pins = {n: self.at(px, py) for n, (px, py) in pins_of(lib_id).items()}
        power = ref.startswith('#')
        fp = self.footprint = lib_property(lib_id, 'Footprint') if footprint is None else footprint
        ref_at, val_at = self.field_positions(fields, dx)
        props = [self.prop('Reference', ref, ref_at, hide=power),
                 self.prop('Value', value, val_at, hide=hide_value),
                 self.prop('Footprint', fp, (x, y, 0, None), hide=True),
                 self.prop('Datasheet', lib_property(lib_id, 'Datasheet') or '~', (x, y, 0, None), hide=True),
                 self.prop('Description', lib_property(lib_id, 'Description'), (x, y, 0, None), hide=True)]
        node = ['symbol', ['lib_id', Q(lib_id)], ['at', num(x), num(y), str(rot)], *([['mirror', mirror]] if mirror else []), ['unit', '1'],
                ['exclude_from_sim', 'no'], ['in_bom', 'no' if power else 'yes'], ['on_board', 'no' if power else 'yes'],
                ['dnp', 'no'], ['uuid', Q(uid())], *props,
                *[['pin', Q(n), ['uuid', Q(uid())]] for n in self.pins],
                ['instances', ['project', Q(NAME), ['path', Q('/' + ROOT), ['reference', Q(ref)], ['unit', '1']]]]]
        items.append(node)
        refs[ref] = self

    def at(self, px, py):
        if self.mirror == 'y':   # flipped left-right
            px = -px
        rx, ry = rotate(px, py, self.rot)
        return round(self.x + rx, 4), round(self.y - ry, 4)

    def field_positions(self, mode, dx):
        # Field angles are relative to the symbol; these keep the text horizontal and upright (found by rendering).
        x, y, a = self.x, self.y, {0: 0, 90: 270, 180: 0, 270: 90}[self.rot]
        if isinstance(mode, tuple):  # explicit ((dx, dy, justify[, turn]), ...) for reference and value
            # A symbol turned 180 degrees or flipped left-right also mirrors its fields' justification.
            flip = {'left': 'right', 'right': 'left'} if (self.rot == 180) != (self.mirror == 'y') else {}
            return [(x + f[0], y + f[1], (a + (f[3] if len(f) > 3 else 0)) % 360, flip.get(f[2], f[2])) for f in mode]
        if mode == 'right':      # vertical part: name and value stacked to the right
            return (x + dx, y - 1.27, a, 'left'), (x + dx, y + 1.27, a, 'left')
        if mode == 'left':
            return (x - dx, y - 1.27, a, 'right'), (x - dx, y + 1.27, a, 'right')
        if mode == 'above':      # horizontal part: "R1 470" on one line above it
            return (x - 0.635, y - 1.905, a, 'right'), (x + 0.635, y - 1.905, a, 'left')
        if mode == 'top':        # name above, value (key label) under the part
            return (x, y - dx, a, None), (x, y + 2.54, a, None)
        if mode == 'stack':      # name and value both above
            return (x, y - dx - 2.54, a, None), (x, y - dx, a, None)
        if mode == 'west':       # power arrow pointing left: its name further left
            return (x - dx, y - 2.54, a, 'right'), (x - dx, y, a, 'right')
        props = {c[1]: c for c in symbol_def(self.lib_id) if isinstance(c, list) and c[0] == 'property'}
        out = []
        for key in ('Reference', 'Value'):
            at = child(props[key], 'at')
            px, py = self.at(float(at[1]), float(at[2]))
            out.append((px, py, (float(at[3]) + self.rot) % 180, None))
        return out

    @staticmethod
    def prop(key, value, where, hide=False):
        x, y, angle, justify = where
        effects = ['effects', ['font', ['size', '1.27', '1.27']]] + ([['justify', justify]] if justify else [])
        return ['property', Q(key), Q(value), ['at', num(x), num(y), num(angle)]] + ([['hide', 'yes']] if hide else []) + [effects]

    def __getitem__(self, pin):
        return self.pins[str(pin)]


def wire(*pts):
    for a, b in zip(pts, pts[1:]):
        if a != b:
            wires.append([tuple(round(v, 4) for v in a), tuple(round(v, 4) for v in b)])


def label(name, at, side='right', glob=True, shape='passive'):
    x, y = at
    angle = {'right': 0, 'left': 180, 'up': 90, 'down': 270}[side]
    justify = 'left' if side in ('right', 'up') else 'right'
    effects = ['effects', ['font', ['size', '1.27', '1.27']], ['justify', justify]]
    if glob:
        items.append(['global_label', Q(name), ['shape', shape], ['at', num(x), num(y), str(angle)],
                      ['fields_autoplaced', 'yes'], effects, ['uuid', Q(uid())],
                      ['property', Q('Intersheetrefs'), Q('${INTERSHEET_REFS}'), ['at', num(x), num(y), '0'], ['hide', 'yes'],
                       ['effects', ['font', ['size', '1.27', '1.27']], ['justify', 'left']]]])
    else:
        items.append(['label', Q(name), ['at', num(x), num(y), str(angle)], effects, ['uuid', Q(uid())]])
    points.append((round(x, 4), round(y, 4)))


def no_connect(at):
    items.append(['no_connect', ['at', num(at[0]), num(at[1])], ['uuid', Q(uid())]])


_power = {'count': 0}


def power(kind, at, rot=0, fields='lib', dx=2.54):
    _power['count'] += 1
    return Part(f'power:{kind}', f'#PWR0{_power["count"]:02d}', kind, *at, rot=rot, fields=fields, dx=dx)


BLUE = ['color', '0', '0', '132', '1']


def frame(title, x1, y1, x2, y2):
    items.append(['rectangle', ['start', num(x1), num(y1)], ['end', num(x2), num(y2)],
                  ['stroke', ['width', '0.5'], ['type', 'solid'], BLUE], ['fill', ['type', 'none']], ['uuid', Q(uid())]])
    if title:
        text(title, x1 + 1.27, y1 + 4.064, size=3, bold=True, color=True)


def text(body, x, y, size=1.5, bold=False, color=False):
    font = ['font', ['size', num(size), num(size)]] + ([['thickness', num(size / 5)], ['bold', 'yes']] if bold else []) + ([BLUE] if color else [])
    items.append(['text', Q(body), ['exclude_from_sim', 'no'], ['at', num(x), num(y), '0'],
                  ['effects', font, ['justify', 'left', 'top' if '\n' in body else 'bottom']], ['uuid', Q(uid())]])


# ---------------------------------------------------------------- the circuit (drawn as image/mau.png, A4)

# Hand-solder variants: larger pads, easier to solder and to rework.
R_FP, C_FP = 'Resistor_SMD:R_0603_1608Metric_Pad0.98x0.95mm_HandSolder', 'Capacitor_SMD:C_0603_1608Metric_Pad1.08x0.95mm_HandSolder'
D_FP = 'Diode_SMD:D_SOD-323_HandSoldering'
BELOW = ((0, -4.445, None), (0, 0, None))             # switches: name above, value hidden
RIGHT, LEFT = ((2.54, -1.27, 'left'), (2.54, 1.27, 'left')), ((-2.54, -1.27, 'right'), (-2.54, 1.27, 'right'))
R_ABOVE = ((-2.54, -1.905, 'left'), (3.81, -1.905, 'left'))   # horizontal resistor: "R1   470" above it
X0, X1, X2, X3 = 10.16, 95.25, 186.69, 283.21           # block edges measured on the reference (before OFFSET)
Y0, Y1, Y2, Y3, XS1, XS2 = 10.16, 59.69, 106.68, 156.21, 68.58, 125.73

# --- NRF52840 Promini: J2 = left row (pins face left, 13 at the top), J1 = right row
frame('NRF52840 Promini', X0, Y0, X1, Y1)
J2 = Part('Connector:Conn_01x13_Pin', 'J2', 'Conn_01x13_Pin', 35.56, 34.29, rot=180,
          fields=((-6.35, 17.78, 'left'), (-6.35, 20.32, 'left')), footprint='Connector_PinSocket_2.54mm:PinSocket_1x13_P2.54mm_Vertical')
J1 = Part('Connector:Conn_01x13_Pin', 'J1', 'Conn_01x13_Pin', 45.72, 34.29,
          fields=((-1.27, -18.8, 'left'), (-1.27, -16.51, 'left')), footprint='Connector_PinSocket_2.54mm:PinSocket_1x13_P2.54mm_Vertical')
wire(J2[10], (24.13, J2[10][1]), (24.13, J2[9][1]), J2[9])
power('GND', (24.13, J2[9][1]), rot=270, fields=((-5.08, 0, 'right'), (-5.08, 0, 'right')))
for pin, name, x in [(8, 'lat', 16.51), (7, 'clk', 24.13), (6, 'mot', 16.51)]:
    wire(J2[pin], (x, J2[pin][1])); label(name, (x, J2[pin][1]), 'left', shape='input')
wire(J1[3], (62.23, J1[3][1])); power('GND', (62.23, J1[3][1]), rot=90, fields=((5.08, 0, 'left'), (5.08, 0, 'left')))
for pin, name in zip(range(6, 14), ['col4', 'col3', 'col2', 'col1', 'row1', 'row2', 'row3', 'data']):
    x = 62.23 if pin % 2 == 0 else 54.61                  # staggered like the reference
    wire(J1[pin], (x, J1[pin][1])); label(name, (x, J1[pin][1]), 'right', shape='input')
rail = J1[5][1]
C4 = Part('Device:C', 'C4', '100nf', 76.2, rail + 3.81, fields=LEFT, footprint=C_FP)
C5 = Part('Device:C_Polarized', 'C5', '100uf', 83.82, rail + 3.81, fields=RIGHT, footprint='Capacitor_SMD:CP_Elec_5x5.4')
wire(J1[5], C4[1], C5[1], (87.63, rail)); label('3v', (87.63, rail), 'right', shape='input')
wire(C5[2], C4[2], (76.2, C4[2][1] + 2.54)); power('GND', (76.2, C4[2][1] + 2.54))

# --- Batterty Maneger (spelled as on the reference)
frame('Batterty Maneger', X0, Y1, X1, Y2)
J4 = Part('Connector:Conn_01x02_Pin', 'J4', 'Conn_01x02_Pin', 35.56, 73.66, fields=((0, -2.54, None), (0, 0, None)), hide_value=True,
          footprint='Connector_PinHeader_2.54mm:PinHeader_1x02_P2.54mm_Vertical')
SW13 = Part('Switch:SW_SPDT_312', 'SW13', 'SW_SPDT_312', 48.26, 76.2, fields=((0, -5.08, None), (0, 0, None)), hide_value=True,
            footprint='Button_Switch_SMD:SW_SPDT_Shouhan_MSK12C02')
# Fix of the reference: drain towards the battery, source to the 3v rail, gate to GND. Reversed battery leaves
# the gate above the source and the body diode reverse biased, so nothing flows.
Q2 = Part('Transistor_FET:AO3401A', 'Q2', 'AO3401A', 58.42, 83.82, mirror='y', fields=((-5.08, -1.27, 'right'), (-5.08, 1.27, 'right')))
wire(J4[1], (J4[1][0], 68.58), (60.96, 68.58), (67.31, 68.58), (67.31, Q2[1][1]), Q2[1])
power('GND', (60.96, 68.58))
wire(J4[2], SW13[1]); wire(SW13[2], Q2[3])
wire(Q2[2], (Q2[2][0], 93.98)); label('3v', (Q2[2][0], 93.98), 'down', shape='input')

# --- 4x3 Matrix Button: 3 rows x 4 columns, diode anode on the row
frame('4x3 Matrix Button', X1, Y0, X2, Y2)
for r in range(3):
    y_row = 39.37 + r * 16.51
    anodes = []
    for c in range(4):
        n, x_sw = r * 4 + c + 1, 120.65 + c * 16.51
        sw = Part('Switch:SW_Push', f'SW{n}', 'SKQG', x_sw, y_row - 7.62, fields=BELOW, hide_value=True,
                  footprint='Button_Switch_SMD:SW_SPST_SKQG_WithStem')
        d = Part('Diode:1N5819WS', f'D{n}', '1N5819WS', x_sw - 7.62, y_row - 3.81, rot=270, fields=RIGHT, footprint=D_FP)
        wire(d[1], sw[1])
        anodes.append(d[2])
        wire(sw[2], (sw[2][0], y_row + 8.89 if r < 2 else 81.28))
    wire((105.41, y_row), *anodes); label(f'row{r + 1}', (105.41, y_row), 'left', shape='input')
for c in range(4):
    label(f'col{c + 1}', (125.73 + c * 16.51, 81.28), 'down', shape='input')

# --- 74HC595 x2. Fix of the reference: RCLK <- lat, SRCLR -> 3v (it tied RCLK to GND and SRCLR to lat)
frame(None, X2, Y0, X3, Y2)
text('74HC595', 255.27, 55.88, size=3.5, bold=True, color=True)
wire((193.04, 15.24), (195.58, 15.24), (199.39, 15.24), (227.33, 15.24))
label('3v', (193.04, 15.24), 'left', shape='input')
wire((199.39, 15.24), (199.39, 62.23), (227.33, 62.23))
LAT, CLK = 207.01, 209.55
for i, u_y in enumerate((30.48, 77.47)):
    U = Part('74xx:74HC595', f'U{i + 1}', '74HC595', 227.33, u_y, fields=((1.27, -17.02, 'left'), (1.27, -14.99, 'left')),
             footprint='Package_SO:SOIC-16_3.9x9.9mm_P1.27mm')
    C = Part('Device:C', f'C{i + 1}', '100nf', (195.58, 199.39)[i], U[16][1] + 3.81, fields=LEFT, footprint=C_FP)
    power('GND', (C[2][0], C[2][1] + 2.54)); wire(C[2], (C[2][0], C[2][1] + 2.54))
    tie = U[8][1] + 1.27                                   # ~OE to GND: outputs always on
    wire(U[13], (214.63, U[13][1]), (214.63, tie), (U[8][0], tie))
    wire(U[8], (U[8][0], tie), (U[8][0], tie + 1.27)); power('GND', (U[8][0], tie + 1.27))
    wire(U[10], (212.09, U[10][1]), (212.09, U[16][1]))    # ~SRCLR held high: the register is never cleared
    wire(U[11], (CLK, U[11][1])); wire(U[12], (LAT, U[12][1]))
    if i == 0:
        wire(U[14], (207.01, U[14][1])); label('data', (207.01, U[14][1]), 'left', shape='input')
        wire((207.01, U[11][1]), (CLK, U[11][1])); label('clk', (207.01, U[11][1]), 'left', shape='input')
        wire((204.47, U[12][1]), (LAT, U[12][1])); label('lat', (204.47, U[12][1]), 'left', shape='input')
        first = U
    else:
        wire((CLK, first[11][1]), (CLK, U[11][1])); wire((LAT, first[12][1]), (LAT, U[12][1]))
        wire(first[9], (240.03, first[9][1]), (240.03, 58.42), (214.63, 58.42), (214.63, U[14][1]), U[14])
    names = (1, 2, 3, 4, 5, 6, 7) if i == 0 else (8, 12, 9, 13, 10, 14, 11)   # R numbering as on the reference
    for k, (q, seg) in enumerate(zip((15, 1, 2, 3, 4, 5, 6), 'abcdefg')):
        cx, tip = (243.84, 264.16) if k % 2 == 0 else (255.27, 271.78)
        R = Part('Device:R', f'R{names[k]}', '470', cx, U[q][1], rot=90, fields=R_ABOVE, footprint=R_FP)
        wire(U[q], R[1]); wire(R[2], (tip, U[q][1])); label(f'l{i + 1}{seg}', (tip, U[q][1]), 'right', shape='input')

# --- 7-Segment (1) and (2): HDSP-7501 common anode
for i, (x1, x2, ux) in enumerate(((X0, XS1, 40.64), (XS1, XS2, 100.33))):
    frame(f'7-Segment ({i + 1})', x1, Y2, x2, Y3)
    U = Part('Display_Character:HDSP-7501', f'U{i + 3}', 'HDSP-7501', ux, 137.16, fields=((0, -17.78, None), (0, -15.24, None)))
    for k, (pin, seg) in enumerate(zip((10, 9, 8, 5, 4, 2, 3), 'abcdefg')):
        tip = U[pin][0] - (3.81 if k % 2 == 0 else 10.16)
        wire(U[pin], (tip, U[pin][1])); label(f'l{i + 1}{seg}', (tip, U[pin][1]), 'left', shape='input')
    ca = U[6] if i == 0 else U[1]                          # the reference ties CA pin 6 on U3, pin 1 on U4
    wire(ca, (ca[0] + (5.08 if i == 0 else 3.81), ca[1])); label('3v', (ca[0] + (5.08 if i == 0 else 3.81), ca[1]), 'right', shape='input')

# --- Vibration Motor
frame('Vibration Motor', XS2, Y2, X2, Y3)
frame(None, X2, Y2, X3, Y3)
label('3v', (143.51, 113.03), 'left', shape='input')
C3 = Part('Device:C', 'C3', '100nf', 157.48, 116.84, fields=LEFT, footprint=C_FP)
D13 = Part('Diode:1N5819WS', 'D13', '1N5819WS', 163.83, 116.84, rot=270, fields=RIGHT, footprint=D_FP)
J3 = Part('Connector:Conn_01x02_Pin', 'J3', 'Conn_01x02_Pin', 173.99, 125.73, rot=180, fields=((1.27, -2.03, 'left'), (0, 0, None)),
          hide_value=True, footprint='Connector_PinHeader_2.54mm:PinHeader_1x02_P2.54mm_Vertical')
Q1 = Part('Transistor_FET:AO3400A', 'Q1', 'AO3400A', 166.37, 139.7, fields=((5.08, -1.27, 'left'), (5.08, 1.27, 'left')))
R15 = Part('Device:R', 'R15', '470', 149.86, 139.7, rot=90, fields=R_ABOVE, footprint=R_FP)
R16 = Part('Device:R', 'R16', '10k', 157.48, 144.78, fields=((-2.54, 2.54, None, 90), (-2.54, -1.905, None, 90)), footprint=R_FP)
wire((143.51, 113.03), C3[1], D13[1], (168.91, 113.03), J3[2])
power('GND', (C3[2][0], C3[2][1] + 2.54)); wire(C3[2], (C3[2][0], C3[2][1] + 2.54))
wire(D13[2], (163.83, 130.81), (168.91, 130.81))
wire(J3[1], (168.91, 130.81), Q1[3])
label('mot', (143.51, 139.7), 'left', shape='input')
wire((143.51, 139.7), R15[1]); wire(R15[2], (157.48, 139.7), Q1[1]); wire((157.48, 139.7), R16[1])
for p in (R16[2], Q1[2]):
    wire(p, (p[0], 151.13)); power('GND', (p[0], 151.13))

# ---------------------------------------------------------------- connectivity helpers and output

def on_segment(p, a, b):
    (x, y), (x1, y1), (x2, y2) = p, a, b
    if p in (a, b):
        return False
    if x1 == x2 == x:
        return min(y1, y2) < y < max(y1, y2)
    if y1 == y2 == y:
        return min(x1, x2) < x < max(x1, x2)
    return False


def finish_wires():
    """Split wires where an endpoint or pin lands inside another wire, and dot every 3-way meeting point."""
    anchors = {p for w in wires for p in w} | {p for part in refs.values() for p in part.pins.values()} | set(points)
    segments = [list(w) for w in wires]
    changed = True
    while changed:
        changed = False
        for i, (a, b) in enumerate(segments):
            hit = next((p for p in anchors if on_segment(p, a, b)), None)
            if hit:
                segments[i:i + 1] = [[a, hit], [hit, b]]
                changed = True
                break
    degree = {}
    for a, b in segments:
        for p in (a, b):
            degree[p] = degree.get(p, 0) + 1
    for part in refs.values():
        for p in part.pins.values():
            if p in degree:
                degree[p] += 1
    for a, b in segments:
        items.append(['wire', ['pts', ['xy', num(a[0]), num(a[1])], ['xy', num(b[0]), num(b[1])]],
                      ['stroke', ['width', '0'], ['type', 'default']], ['uuid', Q(uid())]])
    for p, count in sorted(degree.items()):
        if count >= 3:
            items.append(['junction', ['at', num(p[0]), num(p[1])], ['diameter', '0'], ['color', '0', '0', '0', '0'], ['uuid', Q(uid())]])


finish_wires()
OFFSET = 2.54   # the whole drawing sits inside the sheet's inner border, clear of the zone numbers


def shift(node):
    if isinstance(node, list) and node:
        if node[0] in ('at', 'xy', 'start', 'end'):
            node[1], node[2] = num(float(node[1]) + OFFSET), num(float(node[2]) + OFFSET)
        for c in node[1:]:
            shift(c)


for item in items:
    shift(item)
sheet = ['kicad_sch', ['version', '20250114'], ['generator', Q('eeschema')], ['generator_version', Q('10.0')],
         ['uuid', Q(ROOT)], ['paper', Q('A4')],
         ['title_block', ['title', Q('Student Tag — nRF52840 BLE clicker')], ['date', Q('2026-09-28')], ['rev', Q('B')],
          ['company', Q('Classroom Clicker')],
          ['comment', '1', Q('Vẽ như image/mau.png, đã sửa: 74HC595 RCLK ← lat, SRCLR → 3v; Q2 D phía pin')],
          ['comment', '2', Q('Sinh bằng image/StudentTag/gen_schematic.py — sửa script rồi chạy lại, không sửa tay')]],
         ['lib_symbols', *lib_symbols.values()], *items,
         ['sheet_instances', ['path', Q('/'), ['page', Q('1')]]], ['embedded_fonts', 'no']]
(HERE / f'{NAME}.kicad_sch').write_text(dump(sheet) + '\n')

# ---------------------------------------------------------------- BOM.md (same parts as the schematic)

PACKAGE = {  # footprint -> how to buy it
    R_FP: '0603 (pad hàn tay)', C_FP: '0603 (pad hàn tay)', D_FP: 'SOD-323 (pad hàn tay)', 'Package_TO_SOT_SMD:SOT-23': 'SOT-23',
    'Package_SO:SOIC-16_3.9x9.9mm_P1.27mm': 'SOIC-16 (SOP-16)', 'Capacitor_SMD:CP_Elec_5x5.4': 'Tụ nhôm SMD 5×5,4 mm',
    'Connector_PinSocket_2.54mm:PinSocket_1x13_P2.54mm_Vertical': 'Header cái 1×13, 2,54 mm (xuyên lỗ)',
    'Connector_PinHeader_2.54mm:PinHeader_1x02_P2.54mm_Vertical': '2 lỗ 2,54 mm (hàn dây hoặc header, xuyên lỗ)',
    'Button_Switch_SMD:SW_SPDT_Shouhan_MSK12C02': 'MSK-12C02, SMD 7 chân',
    'Display_7Segment:HDSP-A151': 'DIP-10, 0,3" (xuyên lỗ)',
    'Button_Switch_SMD:SW_SPST_SKQG_WithStem': 'ALPS SKQG 5,2×5,2 mm SMD, có trục',
}
ROLE = {
    'J1': 'Hàng header cắm chân phải module nRF52840 Promini', 'J2': 'Hàng header cắm chân trái module',
    'C4': 'Lọc nguồn cạnh chân 3v của module', 'C5': 'Tụ lọc nguồn lớn cạnh module',
    'J4': 'Đầu cắm pin', 'SW13': 'Công tắc nguồn', 'Q2': 'MOSFET kênh P chống lắp ngược pin (D phía pin, S phía mạch)',
    'U1': 'Thanh ghi dịch 8 bit lái LED số 1', 'U2': 'Thanh ghi dịch 8 bit lái LED số 2 (nối tiếp sau U1)',
    'C1': 'Tụ lọc nguồn U1', 'C2': 'Tụ lọc nguồn U2',
    'U3': 'LED 7 đoạn số 1', 'U4': 'LED 7 đoạn số 2',
    'Q1': 'MOSFET kênh N đóng/cắt motor', 'R15': 'Điện trở cổng MOSFET', 'R16': 'Kéo cổng xuống GND: motor không tự rung khi MCU khởi động',
    'D13': 'Dập xung điện áp ngược của motor', 'C3': 'Tụ lọc nguồn motor', 'J3': 'Đầu cắm motor rung',
    **{f'SW{n}': 'Phím' for n in range(1, 13)},
    **{f'D{n}': 'Diode ma trận phím (bấm nhiều phím cùng lúc không nhận nhầm)' for n in range(1, 13)},
    **{f'R{n}': 'Hạn dòng từng đoạn LED' for n in range(1, 15)},
}


def ref_ranges(names):
    keyed = sorted(names, key=lambda r: (re.sub(r'\d', '', r), int(re.sub(r'\D', '', r))))
    out, run = [], []
    for r in keyed + [None]:
        if run and r and re.sub(r'\d', '', r) == re.sub(r'\d', '', run[-1]) and int(re.sub(r'\D', '', r)) == int(re.sub(r'\D', '', run[-1])) + 1:
            run.append(r)
            continue
        if run:
            out.append(run[0] if len(run) == 1 else f'{run[0]}–{run[-1]}' if len(run) > 2 else f'{run[0]}, {run[1]}')
        run = [r] if r else []
    return ', '.join(out)


groups = {}
for part in refs.values():
    if not part.ref.startswith('#'):
        value = {'SKQG': 'Nút nhấn', 'SW_SPDT_312': 'MSK-12C02', 'Conn_01x02_Pin': {'J3': 'Motor 0834 3V', 'J4': 'Pin 3V'}.get(part.ref)}.get(part.value, part.value)
        groups.setdefault((value, part.footprint), []).append(part.ref)
rows = []
order = lambda r: (re.sub(r'\d', '', r), int(re.sub(r'\D', '', r)))
for (value, fp), members in sorted(groups.items(), key=lambda g: min(map(order, g[1]))):
    roles = {}
    for r in sorted(members, key=order):
        roles.setdefault(ROLE[r], []).append(r)
    role = '; '.join(text if len(roles) == 1 else f'{ref_ranges(rs)}: {text}' for text, rs in roles.items())
    if value == 'Nút nhấn':
        role = 'Phím 1 2 3 4 / 5 6 7 8 / BACK 9 0 OK (ma trận 3 hàng × 4 cột)'
    package = PACKAGE.get(fp, fp.split(':')[-1])
    rows.append(f'| {ref_ranges(members)} | {value} | {package} | {len(members)} | {role} |')
(HERE / 'BOM.md').write_text(f'''# BOM — Student Tag (PCB 2 lớp)

Sinh cùng `{NAME}.kicad_sch` bằng `gen_schematic.py`: sửa script rồi chạy lại, không sửa tay file này.
Linh kiện dán (SMD) trừ LED 7 đoạn, 2 hàng header cắm module và 2 cặp lỗ hàn dây J3, J4.

## Linh kiện hàn trên PCB

| Ký hiệu | Linh kiện | Kiểu chân | SL | Chức năng |
|---|---|---|---|---|
''' + '\n'.join(rows) + f'''

Tổng: {sum(len(m) for m in groups.values())} linh kiện trên PCB.

## Mua kèm, không hàn bằng footprint

Theo vỏ in 3D V2 (`case_v2/`, file in và CAD trong `case_v2/export/`).

| Linh kiện | SL | Chức năng |
|---|---|---|
| Module nRF52840 SuperMini (ProMicro) | 1 | Vi điều khiển + BLE; cắm vào J1/J2 bằng 2 hàng header đực đi kèm module |
| Pin AAA 1,5 V | 2 | Nguồn 3 V, 2 viên nối tiếp trong khoang pin của vỏ |
| Bộ tiếp điểm AAA: 1 lá +, 1 lò xo −, 1 lá nối tiếp (lò xo + lá) | 1 bộ | Ấn vào hốc 2 đầu khoang pin; lá + và lò xo − có chân hàn, hàn dây vào J4 |
| Motor rung 0834, 3 V, 80 mA | 1 | Rung báo, đặt vào ổ trong vỏ dưới, hàn dây vào J3 |
| Vít M2×20 đầu tròn | 4 | Bắt từ đáy vỏ, tự ren vào trụ vỏ trên, kẹp board giữa hai vỏ |
| Mica đỏ 1,5 mm, cắt 19,4 × 15,6 mm | 1 | Cửa sổ LED 7 đoạn (không bắt buộc) |
| Dây mảnh 26–30 AWG | ~20 cm | Tiếp điểm pin → J4, motor → J3 |

## Khác với BOM cũ (`image/BOM Student Tag Respond.xlsx`)

| BOM cũ | Theo sơ đồ mẫu | Ghi chú |
|---|---|---|
| 74HC165D ×2 (mã hóa nút) | Không có | Phím đọc bằng ma trận 3×4 trực tiếp từ module |
| 2811AS âm chung | HDSP-7501 dương chung | |
| Điện trở LED 1 kΩ | 470 Ω | |
| 2N7002 (115 mA) | AO3400A | 2N7002 yếu cho motor 80 mA |
| Tụ nhôm 47 µF | 100 µF | |
| Không có | 1N5819WS ×13, AO3401A, tụ 100 nF ×4 | |
''')
project = HERE / f'{NAME}.kicad_pro'
if not project.exists():
    project.write_text('{}\n')
print(f'wrote {NAME}.kicad_sch: {len([r for r in refs if not r.startswith("#")])} parts, {len(wires)} wire runs')
