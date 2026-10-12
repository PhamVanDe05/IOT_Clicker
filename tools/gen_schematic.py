"""Generate the Student Tag schematic page (inline SVG, net-label style)."""
import html

E = html.escape
out = []


def t(x, y, s, size=11, anchor="start", cls="t"):
    return f'<text x="{x}" y="{y}" font-size="{size}" text-anchor="{anchor}" class="{cls}">{E(s)}</text>'


def ln(x1, y1, x2, y2, cls="w"):
    return f'<line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}" class="{cls}"/>'


def pl(pts, cls="w"):
    return '<polyline points="' + " ".join(f"{x},{y}" for x, y in pts) + f'" class="{cls}"/>'


def dot(x, y):
    return f'<circle cx="{x}" cy="{y}" r="3" class="dot"/>'


def net(x, y, name, side="right"):
    """Net label flag attached at (x,y); side = direction the flag extends."""
    w = len(name) * 7 + 12
    if side == "right":
        pts = [(x, y), (x + 8, y - 8), (x + 8 + w, y - 8), (x + 8 + w, y + 8), (x + 8, y + 8)]
        tx, anchor = x + 8 + w / 2, "middle"
    else:
        pts = [(x, y), (x - 8, y - 8), (x - 8 - w, y - 8), (x - 8 - w, y + 8), (x - 8, y + 8)]
        tx, anchor = x - 8 - w / 2, "middle"
    cls = "flag pwr" if name in ("3V3", "VBAT", "GND") else "flag"
    return ('<polygon points="' + " ".join(f"{a},{b}" for a, b in pts) + f'" class="{cls}"/>'
            + t(tx, y + 4, name, 10.5, anchor, "mono"))


def gnd(x, y):
    return (ln(x, y, x, y + 8) + ln(x - 9, y + 8, x + 9, y + 8) + ln(x - 6, y + 12, x + 6, y + 12)
            + ln(x - 3, y + 16, x + 3, y + 16))


def rail(x, y, name):
    """Supply symbol: wire up to a bar with the rail name."""
    return ln(x, y, x, y - 10) + ln(x - 9, y - 10, x + 9, y - 10) + t(x, y - 15, name, 10.5, "middle", "mono")


def res_h(x, y, ref, val, above=True):
    s = ln(x, y, x + 8, y) + f'<rect x="{x+8}" y="{y-5}" width="24" height="10" class="part"/>' + ln(x + 32, y, x + 40, y)
    ty = y - 8 if above else y + 17
    return s + t(x + 20, ty, f"{ref} {val}".strip(), 8.5, "middle", "ref")


def res_v(x, y, ref, val, left=False):
    s = ln(x, y, x, y + 8) + f'<rect x="{x-5}" y="{y+8}" width="10" height="24" class="part"/>' + ln(x, y + 32, x, y + 40)
    return s + (t(x - 10, y + 23, f"{ref} {val}", 9.5, "end", "ref") if left else t(x + 10, y + 23, f"{ref} {val}", 9.5, "start", "ref"))


def cap_v(x, y, ref, val, polar=False, h=40):
    m = y + h / 2
    s = ln(x, y, x, m - 3) + ln(x - 10, m - 3, x + 10, m - 3) + ln(x - 10, m + 3, x + 10, m + 3) + ln(x, m + 3, x, y + h)
    if polar:
        s += t(x - 14, m - 5, "+", 10, "middle", "ref")
    return s + t(x + 14, m + 4, f"{ref} {val}", 9.5, "start", "ref")


def switch_v(x, y, ref, key):
    """Push button from (x,y) down to (x,y+30)."""
    return (ln(x, y, x, y + 8) + f'<circle cx="{x}" cy="{y+9}" r="2" class="pin"/>'
            + f'<circle cx="{x}" cy="{y+21}" r="2" class="pin"/>' + ln(x - 7, y + 7, x - 7, y + 23)
            + ln(x - 7, y + 15, x - 13, y + 15) + ln(x, y + 23, x, y + 26)
            + t(x + 9, y + 14, ref, 9.5, "start", "ref") + t(x + 9, y + 25, f"“{key}”", 9.5, "start", "key"))


def ic(x, y, w, left, right, ref, part, pitch=20):
    """Box with pins; left/right = list of (num, name) or None. Returns svg, pin end coords by name."""
    rows = max(len(left), len(right))
    h = rows * pitch + pitch
    s = f'<rect x="{x}" y="{y}" width="{w}" height="{h}" class="ic"/>'
    s += t(x + w / 2, y - 18, ref, 12, "middle", "refbig") + t(x + w / 2, y - 6, part, 10, "middle", "ref")
    pins = {}
    for side, lst in (("l", left), ("r", right)):
        for i, p in enumerate(lst):
            if not p:
                continue
            num, name = p
            py = y + pitch + i * pitch
            if side == "l":
                s += ln(x - 20, py, x, py, "pinl") + (t(x - 10, py - 3, str(num), 8.5, "middle", "num") if str(num) != name else "") + t(x + 5, py + 4, name, 10, "start", "mono")
                pins[name] = (x - 20, py)
            else:
                s += ln(x + w, py, x + w + 20, py, "pinl") + (t(x + w + 10, py - 3, str(num), 8.5, "middle", "num") if str(num) != name else "") + t(x + w - 5, py + 4, name, 10, "end", "mono")
                pins[name] = (x + w + 20, py)
    return s, pins, h


def nc(x, y):
    return ln(x - 4, y - 4, x + 4, y + 4, "nc") + ln(x - 4, y + 4, x + 4, y - 4, "nc")


def svg(w, h, label, body):
    return (f'<svg viewBox="0 0 {w} {h}" role="img" aria-label="{E(label)}" xmlns="http://www.w3.org/2000/svg">'
            f'<rect x="1" y="1" width="{w-2}" height="{h-2}" class="frame"/>' + body + "</svg>")


# ---------------- Sheet 1: MCU + power ----------------
s = ""
u, P, h = ic(430, 70, 150,
             [("B+", "B+"), ("RST", "RST"), ("3V3", "3V3"), ("GND", "GND")],
             [("P0.17", "P0.17"), ("P0.20", "P0.20"), ("P0.22", "P0.22"), ("P0.24", "P0.24"),
              ("P1.00", "P1.00"), ("P0.11", "P0.11"), ("P1.04", "P1.04"), ("P1.06", "P1.06")],
             "U0", "nRF52840 SuperMini", pitch=30)
s += u
right = [("P0.17", "SEG_DATA"), ("P0.20", "SEG_CLK"), ("P0.22", "SEG_LATCH"), ("P0.24", "SEG_OE"),
         ("P1.00", "KEY_LOAD"), ("P0.11", "KEY_CLK"), ("P1.04", "KEY_DATA"), ("P1.06", "MOTOR")]
for pin, name in right:
    x, y = P[pin]
    s += ln(x, y, x + 30, y) + net(x + 30, y, name)
s += t(505, 70 + h + 22, "P0.13 = VCC_OFF (nội bộ bo), firmware giữ LOW → bật 3V3 ra", 9.5, "middle", "ref")
s += t(505, 70 + h + 36, "Trống: P0.02 P0.29 P0.31 P1.11 P1.13 P1.15 P0.09 P0.10 P0.06 P0.08", 9.5, "middle", "ref")
# power path: battery -> switch -> VBAT -> B+
bx, by = P["B+"]
s += t(70, 52, "Pin LiPo 3,7 V", 10, "middle", "ref")
# battery vertical at x=70 from y=100 to 160
s += ln(70, by, 70, by + 20) + ln(58, by + 20, 82, by + 20) + ln(63, by + 26, 77, by + 26) + ln(58, by + 32, 82, by + 32) + ln(63, by + 38, 77, by + 38)
s += ln(70, by + 38, 70, by + 58) + gnd(70, by + 58) + t(88, by + 24, "+", 11, "start", "ref") + t(88, by + 44, "BT1", 9.5, "start", "ref")
# switch horizontal from (70,by) to (190,by)
s += ln(70, by, 130, by) + f'<circle cx="132" cy="{by}" r="2" class="pin"/>' + ln(134, by, 162, by - 10) + f'<circle cx="164" cy="{by}" r="2" class="pin"/>'
s += ln(166, by, 260, by) + t(148, by - 16, "SW1 MSK-12C02", 9.5, "middle", "ref")
s += dot(260, by) + ln(260, by, bx, by) + cap_v(260, by, "C1", "47 µF", polar=True) + gnd(260, by + 40)
s += dot(330, by) + ln(330, by, 330, by - 30) + net(330, by - 30, "VBAT", "right")
gx, gy = P["GND"]
s += ln(gx, gy, gx - 30, gy) + ln(gx - 30, gy, gx - 30, gy + 60) + gnd(gx - 30, gy + 60)
rx, ry = P["RST"]
s += nc(rx, ry)
vx, vy = P["3V3"]
s += ln(vx, vy, vx - 60, vy) + dot(vx - 60, vy) + net(vx - 60, vy, "3V3", "left")
# decoupling bank
cy0 = 330
s += t(170, cy0 - 26, "Tụ lọc, đặt sát chân 16/8 của từng IC", 9.5, "middle", "ref")
s += ln(70, cy0, 280, cy0) + net(70, cy0, "3V3", "left")
for i, ref in enumerate(["C2", "C3", "C4", "C5"]):
    x = 100 + i * 55
    s += dot(x, cy0) + ln(x, cy0 - 0, x, cy0) + cap_v(x, cy0, ref, "", h=40) + gnd(x, cy0 + 40)
s += t(170, cy0 + 74, "C2–C5 100 nF → U1, U2, U3, U4", 9.5, "middle", "ref")
out.append(("mcu", "Tờ 1 · Vi điều khiển và nguồn",
            "Pin qua công tắc trượt vào B+ của SuperMini; mạch sạc và ổn áp 3,3 V có sẵn trên bo. Mọi chân tín hiệu đi ra bằng nhãn mạng, nối tới tờ 2–4.",
            svg(860, 470, "Sơ đồ vi điều khiển nRF52840 SuperMini, pin, công tắc và các nhãn mạng tín hiệu", s)))

# ---------------- Sheet 2: display ----------------
def sr595(x, y, ref, first):
    s, P, h = ic(x, y, 120,
                 [(14, "SER"), (11, "SRCLK"), (12, "RCLK"), (13, "OE"), (10, "SRCLR"), None, (16, "VCC"), (8, "GND")],
                 [(15, "Q0"), (1, "Q1"), (2, "Q2"), (3, "Q3"), (4, "Q4"), (5, "Q5"), (6, "Q6"), (7, "Q7"), (9, "Q7'")],
                 ref, "74HC595")
    for pin, name in (("SRCLK", "SEG_CLK"), ("RCLK", "SEG_LATCH"), ("OE", "SEG_OE")):
        px, py = P[pin]
        s += ln(px, py, px - 30, py) + net(px - 30, py, name, "left")
    for pin, name in (("SRCLR", "3V3"), ("VCC", "3V3"), ("GND", "GND")):
        px, py = P[pin]
        s += ln(px, py, px - 20, py) + net(px - 20, py, name, "left")
    if first:
        px, py = P["SER"]
        s += ln(px, py, px - 30, py) + net(px - 30, py, "SEG_DATA", "left")
    return s, P, h


def digit(x, y, ref, rows):
    s = f'<rect x="{x}" y="{y}" width="80" height="{rows*20+40}" class="ic"/>'
    s += t(x + 40, y - 18, ref, 12, "middle", "refbig") + t(x + 40, y - 6, "2811AS · CC", 10, "middle", "ref")
    for i, seg in enumerate("abcdefg"):
        s += t(x + 6, y + 24 + i * 20, seg, 10, "start", "mono")
    # glyph
    gx, gy = x + 36, y + 40
    segs = {"a": (gx, gy, gx + 24, gy), "b": (gx + 26, gy + 2, gx + 26, gy + 28), "c": (gx + 26, gy + 32, gx + 26, gy + 58),
            "d": (gx, gy + 60, gx + 24, gy + 60), "e": (gx - 2, gy + 32, gx - 2, gy + 58), "f": (gx - 2, gy + 2, gx - 2, gy + 28),
            "g": (gx, gy + 30, gx + 24, gy + 30)}
    for a in segs.values():
        s += ln(*a, cls="seg")
    s += ln(x + 40, y + rows * 20 + 40, x + 40, y + rows * 20 + 56) + gnd(x + 40, y + rows * 20 + 56)
    s += t(x + 48, y + rows * 20 + 52, "CC", 9, "start", "num")
    return s


s = ""
for idx, (ref, y, dref, first) in enumerate((("U3", 50, "DS1 · chữ số trái", True), ("U4", 330, "DS2 · chữ số phải", False))):
    body, P, h = sr595(260, y, ref, first)
    s += body
    rbase = 1 + idx * 7
    for i in range(7):
        px, py = P[f"Q{i}"]
        s += res_h(px + 20, py, f"R{rbase+i}", "", above=True).replace(f">R{rbase+i} <", f">R{rbase+i}<") + ln(px, py, px + 20, py) + ln(px + 60, py, 520, py)
    s += t(460, y + 8, f"R{rbase}–R{rbase+6} 1 kΩ", 9.5, "middle", "ref")
    s += digit(520, y, dref.split(" · ")[0], 7) + t(560, y + 7 * 20 + 90, dref.split(" · ")[1], 9.5, "middle", "ref")
    qx, qy = P["Q7"]
    s += nc(qx + 4, qy) + t(qx + 12, qy + 4, "dp không dùng", 9, "start", "num")
    if first:
        cx, cy = P["Q7'"]
        s += pl([(cx, cy), (430, cy), (430, 300), (200, 300), (200, 350), (240, 350)])
        s += t(315, 294, "U3 Q7' → U4 SER", 9.5, "middle", "ref")
    else:
        cx, cy = P["Q7'"]
        s += nc(cx + 4, cy)
# OE pull-up drawn once on sheet
s += net(120, 560, "SEG_OE", "left") + ln(120, 560, 150, 560) + dot(150, 560) + res_v(150, 560, "R15", "10k") + ln(150, 600, 150, 600) + ln(150, 600, 150, 606) + net(150, 606, "3V3", "right")
s += t(165, 546, "Kéo lên: LED tắt khi khởi động", 9.5, "start", "ref")
out.append(("display", "Tờ 2 · Hiển thị: 2 × 74HC595 → 2 LED 7 đoạn",
            "Hai 74HC595 nối tiếp: MCU đẩy 16 bit vào U3, bit tràn qua Q7' sang U4. Mỗi chữ số dùng một IC, không quét nên LED không nhấp nháy. OE kéo lên 3V3 để màn tối khi khởi động; firmware kéo xuống để bật hoặc để nháy.",
            svg(700, 640, "Sơ đồ hai 74HC595 nối tiếp điều khiển hai LED 7 đoạn catốt chung qua 14 điện trở 1k", s)))

# ---------------- Sheet 3: keypad ----------------
def sr165(x, ref, keys, first, rbase, kbase):
    y = 60
    s, P, h = ic(x, y, 120,
                 [(11, "D0"), (12, "D1"), (13, "D2"), (14, "D3"), (3, "D4"), (4, "D5"), (5, "D6"), (6, "D7")],
                 [(1, "SH/LD"), (2, "CLK"), (15, "CLK INH"), (10, "SER"), (9, "QH"), (7, "QH'"), (16, "VCC"), (8, "GND")],
                 ref, "74HC165", pitch=50)
    railx = x - 200
    last = max(i for i, key in enumerate(keys) if key)
    s += ln(railx, y + 20, railx, y + 50 + last * 50) + rail(railx, y + 20, "3V3")
    r = rbase
    k = kbase
    for i, key in enumerate(keys):
        px, py = P[f"D{i}"]
        if key:
            s += dot(railx, py) + res_h(railx, py, f"R{r}", "10k") + ln(railx + 40, py, px, py)
            nx = px - 50
            s += dot(nx, py) + switch_v(nx, py, f"K{k}", key) + gnd(nx, py + 26)
            r += 1
            k += 1
        else:
            s += ln(px, py, px - 30, py) + net(px - 30, py, "3V3", "left")
    right = [("SH/LD", "KEY_LOAD"), ("CLK", "KEY_CLK"), ("CLK INH", "GND")]
    right += [("SER", "U2_QH"), ("QH", "KEY_DATA")] if first else [("SER", "GND"), ("QH", "U2_QH")]
    right += [("VCC", "3V3"), ("GND", "GND")]
    for pin, name in right:
        px, py = P[pin]
        s += ln(px, py, px + 20, py) + net(px + 20, py, name)
    qx, qy = P["QH'"]
    s += nc(qx + 4, qy)
    return s


s = sr165(260, "U1", ["1", "2", "3", "4", "5", "6", "7", "8"], True, 16, 1)
s += sr165(760, "U2", ["9", "0", "BACK", "OK", None, None, None, None], False, 24, 9)
out.append(("keypad", "Tờ 3 · Bàn phím: 12 phím → 2 × 74HC165",
            "Mỗi phím nối ngõ vào xuống GND, có 10 kΩ kéo lên 3V3, nên nhấn = mức 0. SH/LD xuống thấp chốt 16 ngõ vào, sau đó MCU đọc lần lượt qua QH của U1; U2 đẩy dữ liệu sang U1 qua nhãn U2_QH. Bốn ngõ D4–D7 của U2 để dành, nối cứng lên 3V3.",
            svg(1080, 540, "Sơ đồ hai 74HC165 nối tiếp đọc 12 phím, mỗi phím có điện trở kéo lên 10k", s)))

# ---------------- Sheet 4: motor ----------------
s = ""
s += net(130, 190, "MOTOR", "left") + ln(130, 190, 150, 190) + res_h(150, 190, "R28", "1k") + ln(190, 190, 250, 190) + dot(220, 190)
s += res_v(220, 190, "R29", "10k", left=True) + gnd(220, 230)
# MOSFET: gate plate at x=250, channel at x=258
s += ln(250, 170, 250, 210, "part2") + ln(258, 168, 258, 180, "part2") + ln(258, 185, 258, 195, "part2") + ln(258, 200, 258, 212, "part2")
s += pl([(258, 174), (290, 174), (290, 120)]) + pl([(258, 206), (290, 206), (290, 250)]) + ln(258, 190, 290, 190) + ln(290, 190, 290, 206)
s += '<polygon points="260,190 268,186 268,194" class="arrow"/>'
s += t(304, 186, "Q1 AO3400A", 10, "start", "refbig") + t(304, 200, "(BOM: 2N7002)", 9.5, "start", "ref") + t(304, 214, "N-MOSFET logic-level", 9.5, "start", "ref")
s += gnd(290, 250)
# motor between drain(290,120) and VBAT (290,40)
s += ln(290, 120, 290, 105) + dot(290, 105) + f'<circle cx="290" cy="82" r="16" class="part"/>' + t(290, 87, "M", 13, "middle", "refbig")
s += ln(290, 66, 290, 50) + dot(290, 50) + ln(290, 50, 290, 36) + net(290, 36, "VBAT", "right")
s += t(250, 86, "M1 0834", 9.5, "end", "ref") + t(250, 99, "3 V 80 mA", 9.5, "end", "ref")
# flyback diode parallel, cathode to VBAT
s += pl([(290, 105), (360, 105), (360, 90)]) + '<polygon points="352,90 368,90 360,76" class="part"/>' + ln(352, 74, 368, 74) + pl([(360, 74), (360, 50), (290, 50)])
s += t(376, 80, "D1 1N4148W", 10, "start", "refbig") + t(376, 94, "dập xung ngược", 9.5, "start", "ref")
out.append(("motor", "Tờ 4 · Motor rung",
            "MOTOR mức 1 mở Q1, dòng chạy từ VBAT qua motor xuống GND. R29 giữ cổng ở mức 0 khi MCU đang khởi động để motor không tự rung; D1 hấp thụ xung ngược khi tắt motor.",
            svg(560, 290, "Sơ đồ mạch lái motor rung bằng MOSFET N có điện trở kéo xuống cổng và diode dập", s)))

# ---------------- netlist ----------------
netlist = [
    ("SEG_DATA", "P0.17", "U3 SER (14)", "Dữ liệu LED"),
    ("SEG_CLK", "P0.20", "U3, U4 SRCLK (11)", "Xung dịch LED"),
    ("SEG_LATCH", "P0.22", "U3, U4 RCLK (12)", "Chốt ra LED"),
    ("SEG_OE", "P0.24", "U3, U4 OE (13) · R15 lên 3V3", "Bật/tắt, nháy LED"),
    ("KEY_LOAD", "P1.00", "U1, U2 SH/LD (1)", "Chốt phím"),
    ("KEY_CLK", "P0.11", "U1, U2 CLK (2)", "Xung đọc phím"),
    ("KEY_DATA", "P1.04", "U1 QH (9)", "Dữ liệu phím về MCU"),
    ("U2_QH", "—", "U2 QH (9) → U1 SER (10)", "Nối tiếp 2 IC phím"),
    ("MOTOR", "P1.06", "R28 → cổng Q1", "Rung"),
    ("VBAT", "B+", "SW1, C1, M1, D1", "Nguồn pin sau công tắc"),
    ("3V3", "3V3", "U1–U4 VCC, R15–R27, SRCLR, U2 D4–D7", "Ổn áp trên bo"),
]
bom = [
    ("U0", "nRF52840 SuperMini", "1", "có"), ("U1, U2", "74HC165D", "2", "có"), ("U3, U4", "74HC595", "2", "có"),
    ("DS1, DS2", "2811AS 0,28″ catốt chung", "2", "có"), ("K1–K12", "Nút 4×4 mm SMD", "12", "có"),
    ("R1–R14", "1 kΩ 0603 (đoạn LED)", "14", "có"), ("R28", "1 kΩ 0603 (cổng Q1)", "1", "thêm"),
    ("R15, R29", "10 kΩ 0603 (OE, cổng Q1)", "2", "có"), ("R16–R27", "10 kΩ 0603 (kéo lên phím)", "12", "thêm"),
    ("C1", "47 µF 16 V", "1", "có"), ("C2–C5", "100 nF 0603", "4", "thêm"),
    ("Q1", "AO3400A (thay 2N7002)", "1", "đổi"), ("D1", "1N4148W", "1", "thêm"),
    ("M1", "Motor rung 0834", "1", "có"), ("SW1", "MSK-12C02", "1", "có"), ("BT1", "LiPo 3,7 V 300–500 mAh", "1", "thêm"),
]

rows = "".join(f"<tr><td class='mono'>{E(a)}</td><td class='mono'>{E(b)}</td><td>{E(c)}</td><td>{E(d)}</td></tr>" for a, b, c, d in netlist)
brows = "".join(f"<tr><td class='mono'>{E(a)}</td><td>{E(b)}</td><td class='num'>{E(c)}</td><td><span class='st {E(d)}'>{ {'có':'Có trong BOM','thêm':'Cần thêm','đổi':'Nên đổi'}[d] }</span></td></tr>" for a, b, c, d in bom)
sheets = "".join(f"""<section class="sheet" id="{sid}"><header><h2>{E(title)}</h2><p>{E(cap)}</p></header>
<figure><div class="scroll">{svgc}</div></figure></section>""" for sid, title, cap, svgc in out)
nav = "".join(f'<a href="#{sid}">{E(title.split(" · ")[1])}</a>' for sid, title, _, _ in out)

page = f"""<title>Student Tag nRF52840</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans+Condensed:wght@500;700&family=IBM+Plex+Sans:wght@400;600&display=swap">
<style>
:root{{--paper:#f3f5f1;--sheet:#fbfcf9;--ink:#1f2a2e;--muted:#5b6b70;--grid:#e3e9e2;--line:#cfd8d2;--accent:#b4461f;--flag:#fde9dc;--pwr:#e4ecf7;--pwrline:#2f5d93;--on:#d8321f;--ok:#2d7a4f;--okbg:#e2f1e7;--addbg:#fde9dc;--chg:#8a5a00;--chgbg:#fbefd2}}
@media (prefers-color-scheme:dark){{:root:not([data-theme="light"]){{--paper:#131a1c;--sheet:#182124;--ink:#dce6e3;--muted:#93a4a6;--grid:#223034;--line:#33454a;--accent:#f08a5d;--flag:#3b2519;--pwr:#1c2c40;--pwrline:#8fb6e6;--on:#ff6a55;--ok:#7fd3a0;--okbg:#1b3326;--addbg:#3b2519;--chg:#f0c46a;--chgbg:#3a2f14;color-scheme:dark}}}}
:root[data-theme="dark"]{{--paper:#131a1c;--sheet:#182124;--ink:#dce6e3;--muted:#93a4a6;--grid:#223034;--line:#33454a;--accent:#f08a5d;--flag:#3b2519;--pwr:#1c2c40;--pwrline:#8fb6e6;--on:#ff6a55;--ok:#7fd3a0;--okbg:#1b3326;--addbg:#3b2519;--chg:#f0c46a;--chgbg:#3a2f14;color-scheme:dark}}
body{{background:var(--paper);color:var(--ink);font:15px/1.6 "IBM Plex Sans",system-ui,sans-serif}}
.wrap{{max-width:1180px;margin:0 auto;padding-inline:20px;padding-block:28px 60px;display:grid;gap:28px}}
h1,h2{{font-family:"IBM Plex Sans Condensed","Arial Narrow",sans-serif;text-wrap:balance;margin:0}}
h1{{font-size:clamp(28px,4vw,40px);line-height:1.1}}h2{{font-size:20px}}
.mono,code{{font-family:"IBM Plex Mono",ui-monospace,monospace;font-size:.92em}}
.titleblock{{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:20px;align-items:end;border:1.5px solid var(--ink);background:var(--sheet);padding:20px}}
.titleblock p{{margin:8px 0 0;color:var(--muted);max-width:68ch}}
.meta{{display:grid;grid-template-columns:auto auto;border:1px solid var(--line);font:12px/1.4 "IBM Plex Mono",monospace}}
.meta span{{padding:5px 10px;border-bottom:1px solid var(--line)}}.meta span:nth-child(odd){{color:var(--muted);border-right:1px solid var(--line)}}
.meta span:nth-last-child(-n+2){{border-bottom:0}}
nav{{display:flex;flex-wrap:wrap;gap:8px}}nav a{{font:600 13px "IBM Plex Mono",monospace;color:var(--ink);text-decoration:none;border:1px solid var(--line);padding:6px 12px;background:var(--sheet)}}
nav a:hover,nav a:focus-visible{{border-color:var(--accent);color:var(--accent);outline:none}}
.sheet{{background:var(--sheet);border:1px solid var(--line);padding:18px;display:grid;gap:12px;scroll-margin-top:12px}}
.sheet header p{{margin:4px 0 0;color:var(--muted);max-width:80ch;font-size:14px}}
figure{{margin:0}}.scroll{{overflow-x:auto;background-color:var(--sheet);background-image:linear-gradient(var(--grid) 1px,transparent 1px),linear-gradient(90deg,var(--grid) 1px,transparent 1px);background-size:20px 20px}}
svg{{display:block;min-width:640px;width:100%;height:auto;color:var(--ink)}}
svg .frame{{fill:none;stroke:var(--line)}}
svg .w,svg .pinl{{stroke:currentColor;stroke-width:1.4;fill:none}}svg .nc{{stroke:var(--accent);stroke-width:1.6}}
svg .ic{{fill:var(--sheet);stroke:currentColor;stroke-width:1.8}}svg .part{{fill:var(--sheet);stroke:currentColor;stroke-width:1.4}}svg .part2{{stroke:currentColor;stroke-width:2.2}}
svg .pin{{fill:var(--sheet);stroke:currentColor;stroke-width:1.2}}svg .dot,svg .arrow{{fill:currentColor}}
svg .seg{{stroke:var(--on);stroke-width:4;stroke-linecap:round;opacity:.85}}
svg .flag{{fill:var(--flag);stroke:var(--accent);stroke-width:1.2}}svg .flag.pwr{{fill:var(--pwr);stroke:var(--pwrline)}}
svg text{{fill:currentColor;font-family:"IBM Plex Sans",sans-serif}}svg .mono{{font-family:"IBM Plex Mono",monospace}}
svg .ref{{fill:var(--muted)}}svg .refbig{{font-weight:600}}svg .num{{fill:var(--muted);font-family:"IBM Plex Mono",monospace}}svg .key{{fill:var(--accent);font-weight:600}}
.tables{{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr));gap:20px;align-items:start}}
table{{width:100%;border-collapse:collapse;font-size:13.5px;background:var(--sheet)}}
th,td{{text-align:left;padding:7px 10px;border-bottom:1px solid var(--line);vertical-align:top}}th{{font:600 11px "IBM Plex Mono",monospace;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}}
td.num{{font-variant-numeric:tabular-nums;text-align:right}}
.st{{font:600 11px "IBM Plex Mono",monospace;padding:2px 7px;white-space:nowrap}}.st.có{{background:var(--okbg);color:var(--ok)}}.st.thêm{{background:var(--addbg);color:var(--accent)}}.st.đổi{{background:var(--chgbg);color:var(--chg)}}
.panel{{border:1px solid var(--line);background:var(--sheet);padding:16px;display:grid;gap:10px}}.panel h2{{font-size:18px}}
.notes{{margin:0;padding-left:20px;display:grid;gap:6px;color:var(--ink);max-width:80ch}}.notes li::marker{{color:var(--accent)}}
.legend{{display:flex;flex-wrap:wrap;gap:16px;font-size:13px;color:var(--muted);align-items:center}}
.chip{{display:inline-block;font:600 11px "IBM Plex Mono",monospace;padding:1px 8px;border:1px solid var(--accent);background:var(--flag);color:var(--ink);margin-right:6px}}.chip.pwr{{border-color:var(--pwrline);background:var(--pwr)}}
.x{{color:var(--accent);font-weight:700;margin-right:6px}}
@media (max-width:640px){{.titleblock{{grid-template-columns:1fr}}.sheet{{padding:12px}}}}
</style>
<div class="wrap">
<header class="titleblock"><div><h1>Student Tag nRF52840</h1>
<p>Sơ đồ nguyên lý của thẻ trả lời BLE: 12 phím qua mạch mã hóa 74HC165, 2 LED 7 đoạn qua mạch giải mã 74HC595, motor rung và nguồn pin. Chân khớp với <code>firmware/StudentTagNRF52/config.h</code>; đổi chân trên mạch thì sửa file đó.</p></div>
<div class="meta"><span>Dự án</span><span>Classroom Clicker</span><span>Bản</span><span>Rev A · đề xuất</span><span>Ngày</span><span>25/09/2026</span><span>Số tờ</span><span>4</span></div></header>
<nav aria-label="Các tờ sơ đồ">{nav}<a href="#netlist">Bảng nối</a><a href="#bom">Linh kiện</a></nav>
<div class="legend"><span><span class="chip">SEG_DATA</span>nhãn mạng: cùng tên là cùng một dây</span><span><span class="chip pwr">3V3</span>nhãn nguồn</span><span><span class="x">×</span>chân để trống</span><span>Số nhỏ trên chân = số chân IC</span></div>
{sheets}
<div class="tables">
<section class="panel" id="netlist"><h2>Bảng nối dây</h2><div class="scroll" style="background:none"><table><thead><tr><th>Mạng</th><th>Chân MCU</th><th>Tới</th><th>Chức năng</th></tr></thead><tbody>{rows}</tbody></table></div></section>
<section class="panel" id="bom"><h2>Linh kiện theo sơ đồ</h2><div class="scroll" style="background:none"><table><thead><tr><th>Ký hiệu</th><th>Linh kiện</th><th>SL</th><th>So với BOM</th></tr></thead><tbody>{brows}</tbody></table></div></section>
</div>
<section class="panel"><h2>Kiểm tra trước khi làm mạch</h2><ol class="notes">
<li>Số chân của LED 2811AS khác nhau giữa các nhà sản xuất; dò lại chân a–g và hai chân CC theo datasheet trước khi vẽ footprint.</li>
<li>Motor lấy nguồn từ VBAT sau công tắc, không lấy từ 3V3, để dòng khởi động không làm sụt áp và reset nRF52840.</li>
<li>Dòng LED: (3,3 V − 1,8 V) / 1 kΩ ≈ 1,5 mA mỗi đoạn, tối đa 21 mA khi hiện “88”. Muốn sáng hơn thì giảm xuống 470 Ω; mỗi 74HC595 vẫn dưới giới hạn 70 mA.</li>
<li>Nếu giữ 2N7002 như BOM: ở 3,3 V cổng, Rds(on) vài ôm và dòng khởi động motor vượt 115 mA, nên motor rung yếu và transistor nóng. AO3400A đúng chân SOT-23 nên thay thẳng được.</li>
<li>Đặt C2–C5 sát chân 16 (VCC) và 8 (GND) của từng IC; đặt C1 gần B+.</li>
</ol></section>
</div>"""
open(__import__("os").path.join(__import__("os").path.dirname(__file__), "..", "docs", "student-tag-schematic.html"), "w").write(page)
print("ok", len(page))
