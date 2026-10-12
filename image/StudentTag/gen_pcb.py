#!/usr/bin/env python3
"""Build StudentTag.kicad_pcb (2 layers, 50 x 80 mm) from StudentTag.kicad_sch, route it and export fabrication files.

    /usr/bin/python3 image/StudentTag/gen_pcb.py            (system Python: it needs KiCad's pcbnew module)
    /usr/bin/python3 image/StudentTag/gen_pcb.py fab        (only re-export Gerbers, drill, placement, renders)

Placement follows the original Student Remote board (image/Student Remote.step, a KiCad 3D export):
module across the top, displays and 74HC595s in the middle, power and motor at the top left, a 3 x 4 key grid
at 12 mm pitch below. Routing is done by Freerouting (.local/tools/freerouting-2.1.0.jar, Java 21), then GND
is poured on both layers. Check with tests/pcb_test.py (DRC + netlist against the schematic).
"""
import re
import shutil
import subprocess
import sys
from pathlib import Path

import pcbnew

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
NAME = 'StudentTag'
PCB = HERE / f'{NAME}.kicad_pcb'
FOOTPRINTS = Path('/usr/share/kicad/footprints')
FREEROUTING = ROOT / '.local/tools/freerouting-2.1.0.jar'
mm = pcbnew.FromMM

# Board outline (mm, KiCad page coordinates): 50 wide, 80 tall, 2 mm corner radius.
LEFT, TOP, WIDTH, HEIGHT, CORNER = 120.5, 58.0, 50.0, 80.0, 2.0

# Positions of the original board (x, y, orientation). SW13 is new: the original had the switch off-board.
PLACE = {
    'J2': (136.33, 64.82, 90), 'J1': (166.81, 79.97, 270), 'J4': (130.64, 64.87, 270),
    'SW13': (123.4, 70.5, 270), 'Q2': (122.88, 82.35, 270), 'C4': (127.40, 78.17, 90), 'C5': (131.30, 71.35, 0),
    'D13': (127.41, 82.17, 270), 'J3': (122.90, 92.00, 0), 'Q1': (131.24, 82.34, 270),
    'R15': (132.20, 78.12, 270), 'R16': (130.26, 78.12, 270), 'C3': (157.51, 76.79, 0),
    'U3': (131.84, 86.68, 0), 'U4': (140.79, 86.68, 0),   # 4.5 mm left of the original: room to route
    'U1': (164.33, 87.42, 90), 'U2': (164.32, 96.98, 90), 'C1': (164.33, 87.42, 0), 'C2': (164.32, 96.98, 0),
    # Segment resistors in two columns at 2.7 mm pitch (the original stacked 14 at 1.65 mm: no room for 0.3 mm
    # tracks between hand-solder pads). U1's seven in the upper half, U2's in the lower half, in output order.
    **{r: (153.8 if col == 'A' else 157.3, 83.4 + 2.7 * row, 180) for r, (row, col) in {
        'R1': (0, 'B'), 'R2': (0, 'A'), 'R3': (1, 'B'), 'R4': (1, 'A'), 'R5': (2, 'B'), 'R6': (2, 'A'), 'R7': (3, 'B'),
        'R8': (3, 'A'), 'R12': (4, 'B'), 'R9': (4, 'A'), 'R13': (5, 'B'), 'R10': (5, 'A'), 'R14': (6, 'B'), 'R11': (6, 'A')}.items()},
    **{f'SW{r * 4 + c + 1}': (126.88 + 12 * c, 105.0 + 12 * r, 0) for r in range(3) for c in range(4)},   # 1 mm higher than
    **{f'D{r * 4 + c + 1}': (132.87 + 12 * c, 104.2 + 12 * r, 270) for r in range(3) for c in range(4)},  # the original: room for holes
}
# The only parts on the bottom: each 74HC595's decoupling capacitor right under its chip. That keeps the strips
# above and below the chips free for clk, lat, the QH' -> SER chain and the segment lines.
BOTTOM = {'C1', 'C2'}
HOLES = [(123.1, 60.6), (167.9, 60.6), (123.3, 135.0), (167.7, 135.0)]   # M2, clear of every courtyard
# Silkscreen: the original layout packs these parts too tightly for names; they stay on the Fab layer and in the
# placement file (outlines and diode polarity bars remain on the silkscreen).
FAB_ONLY_NAMES = {f'R{n}' for n in range(1, 17)} | {f'D{n}' for n in range(1, 14)} | {'C1', 'C2', 'C3', 'C4'}
NAME_AT = {'J1': (151.6, 82.6), 'J2': (151.6, 62.4), 'J4': (129.4, 62.4), 'SW13': (124.0, 76.4),
           'U3': (134.4, 83.8), 'U4': (143.3, 83.8), 'U1': (164.33, 87.42), 'U2': (164.32, 96.98)}   # U1/U2 on their body
# What the user reads: the key's function under each key (the SW names stay on the Fab layer).
KEY_LABELS = dict(zip([f'SW{n}' for n in range(1, 13)], ['1', '2', '3', '4', '5', '6', '7', '8', 'BACK', '9', '0', 'OK']))
FAB_ONLY_NAMES |= set(KEY_LABELS)


def netlist():
    """Components (ref -> fields) and nets (name -> [(ref, pin)]) straight from the schematic."""
    out = HERE / f'{NAME}.net'
    subprocess.run(['kicad-cli', 'sch', 'export', 'netlist', '--format', 'kicadsexpr', '-o', str(out), str(HERE / f'{NAME}.kicad_sch')],
                   check=True, capture_output=True)
    text = ' '.join(out.read_text().split())
    out.unlink()
    parts = {}
    for block in text.split('(comp ')[1:]:
        field = lambda key: (re.search(rf'\({key} "([^"]*)"\)', block) or [None, ''])[1]
        parts[field('ref')] = {'value': field('value'), 'footprint': field('footprint'), 'datasheet': field('datasheet'),
                               'description': field('description'), 'uuid': re.findall(r'\(tstamps "([^"]+)"\)', block)[-1]}
    nets = {}
    for name, body in re.findall(r'\(net \(code "\d+"\) \(name "([^"]*)"\)(.*?)(?=\(net \(code|$)', text):
        nets[name] = re.findall(r'\(node \(ref "([^"]+)"\) \(pin "([^"]+)"\)', body)   # one-pin nets too: parity
    return parts, nets


def silk_text(board, body, x, y, size=1.2):
    text = pcbnew.PCB_TEXT(board)
    text.SetText(body); text.SetLayer(pcbnew.F_SilkS); text.SetPosition(pcbnew.VECTOR2I(mm(x), mm(y)))
    text.SetTextSize(pcbnew.VECTOR2I(mm(size), mm(size))); text.SetTextThickness(mm(size * 0.18)); text.SetBold(True)
    board.Add(text)


def outline(board):
    """Rounded rectangle on Edge.Cuts."""
    x0, y0, x1, y1, r = LEFT, TOP, LEFT + WIDTH, TOP + HEIGHT, CORNER
    for a, b in [((x0 + r, y0), (x1 - r, y0)), ((x1, y0 + r), (x1, y1 - r)), ((x1 - r, y1), (x0 + r, y1)), ((x0, y1 - r), (x0, y0 + r))]:
        seg = pcbnew.PCB_SHAPE(board, pcbnew.SHAPE_T_SEGMENT)
        seg.SetStart(pcbnew.VECTOR2I(mm(a[0]), mm(a[1]))); seg.SetEnd(pcbnew.VECTOR2I(mm(b[0]), mm(b[1])))
        seg.SetLayer(pcbnew.Edge_Cuts); seg.SetWidth(mm(0.1)); board.Add(seg)
    for cx, cy, start in [(x0 + r, y0 + r, (x0, y0 + r)), (x1 - r, y0 + r, (x1 - r, y0)), (x1 - r, y1 - r, (x1, y1 - r)), (x0 + r, y1 - r, (x0 + r, y1))]:
        arc = pcbnew.PCB_SHAPE(board, pcbnew.SHAPE_T_ARC)
        arc.SetCenter(pcbnew.VECTOR2I(mm(cx), mm(cy))); arc.SetStart(pcbnew.VECTOR2I(mm(start[0]), mm(start[1])))
        arc.SetArcAngleAndEnd(pcbnew.EDA_ANGLE(90, pcbnew.DEGREES_T))
        arc.SetLayer(pcbnew.Edge_Cuts); arc.SetWidth(mm(0.1)); board.Add(arc)


def rules(board):
    """2-layer rules inside any fab's standard process (JLCPCB/PCBWay: 0.127 mm track/space, 0.3 mm drill):
    0.25 mm signal tracks with 0.15 mm clearance and 0.7/0.35 mm vias; supply nets 0.5 mm with 0.8/0.4 mm vias."""
    ds = board.GetDesignSettings()
    ds.m_MinClearance, ds.m_TrackMinWidth, ds.m_ViasMinSize, ds.m_MinThroughDrill = mm(0.15), mm(0.25), mm(0.7), mm(0.35)
    ns = ds.m_NetSettings
    default = ns.GetDefaultNetclass()
    default.SetClearance(mm(0.15)); default.SetTrackWidth(mm(0.25)); default.SetViaDiameter(mm(0.7)); default.SetViaDrill(mm(0.35))
    power = pcbnew.NETCLASS('Power')
    power.SetClearance(mm(0.15)); power.SetTrackWidth(mm(0.5)); power.SetViaDiameter(mm(0.8)); power.SetViaDrill(mm(0.4))
    ns.SetNetclass('Power', power)
    for net in ('3v', 'GND', 'VBAT'):
        ns.SetNetclassPatternAssignment(net, 'Power')


def place(board, parts, nets):
    netinfo = {}
    for name in nets:
        item = pcbnew.NETINFO_ITEM(board, name)
        board.Add(item)
        netinfo[name] = item
    pad_net = {(ref, pin): name for name, nodes in nets.items() for ref, pin in nodes}
    for ref, part in sorted(parts.items()):
        lib, name = part['footprint'].split(':')
        fp = pcbnew.FootprintLoad(str(FOOTPRINTS / f'{lib}.pretty'), name)
        if fp is None:
            sys.exit(f'{ref}: footprint {part["footprint"]} not found')
        # Link to the schematic symbol (library id, symbol UUID, fields) so DRC schematic parity holds.
        fp.SetFPIDAsString(part['footprint']); fp.SetPath(pcbnew.KIID_PATH('/' + part['uuid']))
        fp.SetSheetname('/'); fp.SetSheetfile(f'{NAME}.kicad_sch')
        fp.SetReference(ref); fp.SetValue(part['value'])
        fp.SetField('Datasheet', part['datasheet']); fp.SetField('Description', part['description'])
        name_text = fp.Reference()
        name_text.SetTextSize(pcbnew.VECTOR2I(mm(0.8), mm(0.8))); name_text.SetTextThickness(mm(0.12))
        name_text.SetVisible(ref not in FAB_ONLY_NAMES)
        x, y, rot = PLACE[ref]
        fp.SetPosition(pcbnew.VECTOR2I(mm(x), mm(y))); fp.SetOrientationDegrees(rot)
        if ref in NAME_AT:
            name_text.SetPosition(pcbnew.VECTOR2I(mm(NAME_AT[ref][0]), mm(NAME_AT[ref][1]))); name_text.SetTextAngleDegrees(0)
        board.Add(fp)
        if ref in BOTTOM:
            fp.SetLayerAndFlip(pcbnew.B_Cu)   # after Add: flipping a footprint outside a board crashes pcbnew
        for pad in fp.Pads():
            net = pad_net.get((ref, pad.GetNumber()))
            if net:
                pad.SetNet(netinfo[net])
            if net == 'GND' and ref in ('J1', 'J2'):
                # Module ground pins: solid to the plane (tracks leave too little room for thermal spokes).
                pad.SetLocalZoneConnection(pcbnew.ZONE_CONNECTION_FULL)
    for ref, label in KEY_LABELS.items():
        silk_text(board, label, PLACE[ref][0], PLACE[ref][1] + 3.8)
    silk_text(board, 'STUDENT TAG', LEFT + WIDTH / 2, TOP + HEIGHT - 3.0, size=1.4)
    for i, (x, y) in enumerate(HOLES, 1):
        hole = pcbnew.FootprintLoad(str(FOOTPRINTS / 'MountingHole.pretty'), 'MountingHole_2.2mm_M2')
        hole.SetReference(f'H{i}'); hole.Reference().SetVisible(False); hole.SetPosition(pcbnew.VECTOR2I(mm(x), mm(y)))
        hole.SetAttributes(pcbnew.FP_BOARD_ONLY | pcbnew.FP_EXCLUDE_FROM_BOM | pcbnew.FP_EXCLUDE_FROM_POS_FILES)
        board.Add(hole)


def edge_keepout(board):
    """No tracks or vias within 0.6 mm of the edge, so the router keeps the 0.5 mm copper-to-edge rule.
    Four strips rather than one ring: the Specctra export drops holes, which would block the whole board."""
    w, x0, y0, x1, y1 = 0.6, LEFT, TOP, LEFT + WIDTH, TOP + HEIGHT
    for ax, ay, bx, by in [(x0, y0, x1, y0 + w), (x0, y1 - w, x1, y1), (x0, y0, x0 + w, y1), (x1 - w, y0, x1, y1)]:
        keepout = pcbnew.ZONE(board)
        keepout.SetIsRuleArea(True); keepout.SetDoNotAllowTracks(True); keepout.SetDoNotAllowVias(True)
        keepout.SetDoNotAllowPads(False); keepout.SetDoNotAllowFootprints(False); keepout.SetDoNotAllowZoneFills(False)
        keepout.SetLayerSet(pcbnew.LSET.AllCuMask()); keepout.SetZoneName('edge keepout')
        outline = keepout.Outline(); outline.NewOutline()
        for x, y in [(ax, ay), (bx, ay), (bx, by), (ax, by)]:
            outline.Append(mm(x), mm(y))
        board.Add(keepout)


def remove_stubs(board):
    """Drop what the router leaves behind: segments lying inside another segment of the same net and layer (it also
    writes exact duplicates), then spurs with an end touching no other track on its layer, no via and no pad.
    Neither carries a connection; KiCad's own dangling test misses them right after the session import."""
    open_before = board.GetConnectivity().GetUnconnectedCount(False)
    tracks = [t for t in board.GetTracks() if t.Type() == pcbnew.PCB_TRACE_T]
    kept = []
    for a in sorted(tracks, key=lambda t: t.GetLength()):
        on = lambda p, b: segment_distance((p.x, p.y), b.GetStart(), b.GetEnd()) < mm(0.001)
        if any(b is not a and b.GetLayer() == a.GetLayer() and b.GetNetCode() == a.GetNetCode() and b.GetLength() >= a.GetLength()
               and on(a.GetStart(), b) and on(a.GetEnd(), b) for b in tracks if b not in kept or b is not a):
            board.Delete(a)
            tracks = [t for t in tracks if t is not a]
        else:
            kept.append(a)
    while True:
        tracks = [t for t in board.GetTracks() if t.Type() == pcbnew.PCB_TRACE_T]
        vias = [v for v in board.GetTracks() if v.Type() == pcbnew.PCB_VIA_T]
        pads = [p for fp in board.GetFootprints() for p in fp.Pads()]

        def free(t, p):   # touches nothing: no other track's body (T joints count), no via, no pad
            q = (p.x, p.y)
            return (not any(o is not t and o.GetLayer() == t.GetLayer() and segment_distance(q, o.GetStart(), o.GetEnd()) <= o.GetWidth() / 2
                            for o in tracks)
                    and not any(segment_distance(q, v.GetPosition(), v.GetPosition()) <= v.GetWidth(pcbnew.F_Cu) / 2 for v in vias)
                    and not any(pad.IsOnLayer(t.GetLayer()) and pad.HitTest(p) for pad in pads))
        spurs = [t for t in tracks if free(t, t.GetStart()) or free(t, t.GetEnd())]
        if not spurs:
            break
        for track in spurs:
            board.Delete(track)
    board.BuildConnectivity()
    if board.GetConnectivity().GetUnconnectedCount(False) > open_before:
        sys.exit('track clean-up opened a connection')


def route(board, attempts=8):
    """Freerouting on a Specctra export, then the session back into the board. Its result varies from run to run and
    its own summary can claim completion with a connection still open, so KiCad's connectivity decides and each
    attempt starts again from an unrouted board; the first complete one is kept."""
    work = HERE / 'freerouting'
    work.mkdir(exist_ok=True)
    dsn, ses = work / f'{NAME}.dsn', work / f'{NAME}.ses'
    for attempt in range(1, attempts + 1):
        for track in list(board.GetTracks()):
            board.Delete(track)
        if not pcbnew.ExportSpecctraDSN(board, str(dsn)):
            sys.exit('Specctra export failed')
        ses.unlink(missing_ok=True)
        subprocess.run(['java', '-Djava.awt.headless=true', '-jar', str(FREEROUTING), '-de', str(dsn), '-do', str(ses),
                        '-mp', '100', '--gui.enabled=false'], capture_output=True, text=True, timeout=1800)
        if not ses.exists() or not pcbnew.ImportSpecctraSES(board, str(ses)):
            sys.exit('Freerouting produced no session')
        board.BuildConnectivity()
        open_count = board.GetConnectivity().GetUnconnectedCount(False)
        print(f'routing attempt {attempt}: {open_count} connections open')
        if open_count == 0:
            remove_stubs(board)
            shutil.rmtree(work)
            return
    sys.exit(f'no complete routing in {attempts} Freerouting runs (last: {open_count} open)')


def segment_distance(p, a, b):
    ax, ay, bx, by, px, py = a.x, a.y, b.x, b.y, p[0], p[1]
    dx, dy = bx - ax, by - ay
    t = 0 if dx == dy == 0 else max(0, min(1, ((px - ax) * dx + (py - ay) * dy) / (dx * dx + dy * dy)))
    return ((ax + t * dx - px) ** 2 + (ay + t * dy - py) ** 2) ** 0.5


def stitch_candidates(board, pitch=3.81, via=0.8, clearance=0.3):
    """GND via spots on a grid, clear of every track, via and pad of another net and of the board edge."""
    gnd = board.FindNet('GND').GetNetCode()
    tracks = [t for t in board.GetTracks() if t.GetNetCode() != gnd]
    pads = [p for fp in board.GetFootprints() for p in fp.Pads()]
    keep = mm(via / 2 + clearance)
    spots, y = [], TOP + 3.0
    while y < TOP + HEIGHT - 3.0:
        x = LEFT + 3.0
        while x < LEFT + WIDTH - 3.0:
            p = (mm(x), mm(y))
            clear = all((segment_distance(p, t.GetStart(), t.GetEnd()) if t.Type() == pcbnew.PCB_TRACE_T
                         else segment_distance(p, t.GetPosition(), t.GetPosition()))
                        - (t.GetWidth(pcbnew.F_Cu) if t.Type() == pcbnew.PCB_VIA_T else t.GetWidth()) / 2 > keep for t in tracks)
            box = pcbnew.BOX2I(pcbnew.VECTOR2I(p[0] - keep, p[1] - keep), pcbnew.VECTOR2I(2 * keep, 2 * keep))
            clear = clear and not any(pad.GetBoundingBox().Intersects(box) for pad in pads)
            clear = clear and all(((hx - x) ** 2 + (hy - y) ** 2) ** 0.5 > 2.5 for hx, hy in HOLES)
            if clear:
                spots.append(p)
            x += pitch
        y += pitch
    return spots


def ground_pour(board):
    """GND copper on both layers, 0.5 mm inside the edge; islands with no GND connection are dropped."""
    gnd = board.FindNet('GND')
    inset = 0.5
    corners = [(LEFT + inset, TOP + inset), (LEFT + WIDTH - inset, TOP + inset),
               (LEFT + WIDTH - inset, TOP + HEIGHT - inset), (LEFT + inset, TOP + HEIGHT - inset)]
    for layer in (pcbnew.F_Cu, pcbnew.B_Cu):
        zone = pcbnew.ZONE(board)
        zone.SetLayer(layer); zone.SetNet(gnd); zone.SetZoneName(f'GND {board.GetLayerName(layer)}')
        outline = zone.Outline(); outline.NewOutline()
        for x, y in corners:
            outline.Append(mm(x), mm(y))
        zone.SetLocalClearance(mm(0.3)); zone.SetMinThickness(mm(0.25))
        zone.SetPadConnection(pcbnew.ZONE_CONNECTION_THERMAL)
        zone.SetThermalReliefGap(mm(0.4)); zone.SetThermalReliefSpokeWidth(mm(0.4))
        zone.SetIslandRemovalMode(pcbnew.ISLAND_REMOVAL_MODE_ALWAYS)
        board.Add(zone)
    # Stitching vias tie the two GND planes together so no plane fragment hangs on a single pad.
    vias = []
    for x, y in stitch_candidates(board):
        via = pcbnew.PCB_VIA(board)
        via.SetPosition(pcbnew.VECTOR2I(x, y)); via.SetWidth(mm(0.8)); via.SetDrill(mm(0.4)); via.SetNet(gnd)
        board.Add(via); vias.append(via)
    pcbnew.ZONE_FILLER(board).Fill(board.Zones())
    zones = [z for z in board.Zones() if z.GetNetname() == 'GND']
    lonely = [v for v in vias if not all(z.GetFilledPolysList(z.GetLayer()).Contains(v.GetPosition()) for z in zones)]
    for via in lonely:
        board.Delete(via)
    pcbnew.ZONE_FILLER(board).Fill(board.Zones())
    print(f'{len(vias) - len(lonely)} GND stitching vias')


def fabrication():
    """Gerbers, drill, placement file in StudentTag_fab.zip (what JLCPCB/PCBWay ask for), plus top/bottom renders."""
    fab = HERE / 'fab'
    shutil.rmtree(fab, ignore_errors=True); fab.mkdir()
    cli = lambda *args: subprocess.run(['kicad-cli', 'pcb', *args, str(PCB)], check=True, capture_output=True)
    cli('export', 'gerbers', '-o', str(fab), '--no-protel-ext', '--subtract-soldermask',
        '-l', 'F.Cu,B.Cu,F.Paste,B.Paste,F.Silkscreen,B.Silkscreen,F.Mask,B.Mask,Edge.Cuts')
    cli('export', 'drill', '-o', f'{fab}/', '--excellon-separate-th', '--generate-map', '--map-format', 'pdf')
    cli('export', 'pos', '-o', str(fab / f'{NAME}-pos.csv'), '--format', 'csv', '--units', 'mm', '--side', 'both')
    zip_path = HERE / f'{NAME}_fab.zip'
    zip_path.unlink(missing_ok=True)
    shutil.make_archive(str(zip_path.with_suffix('')), 'zip', fab)
    shutil.rmtree(fab)
    for side in ('top', 'bottom'):
        cli('render', '-o', str(HERE / f'{NAME}_{side}.png'), '--side', side, '--width', '1000', '--height', '1500',
            '--quality', 'high', '--background', 'opaque')


def main():
    parts, nets = netlist()
    missing = sorted(set(parts) - set(PLACE))
    if missing:
        sys.exit(f'no position for {missing}')
    work = HERE / f'{NAME}.building.kicad_pcb'      # the finished board replaces the old one only at the end
    board = pcbnew.NewBoard(str(work))
    rules(board)
    outline(board)
    place(board, parts, nets)
    edge_keepout(board)
    route(board)
    ground_pour(board)
    pcbnew.SaveBoard(str(work), board)             # board plus project settings (net classes, rules)
    work.replace(PCB)
    work.with_suffix('.kicad_pro').replace(PCB.with_suffix('.kicad_pro'))
    work.with_suffix('.kicad_prl').unlink(missing_ok=True)
    # KiCad's own filler, so the saved copper is exactly what DRC and the Gerbers see.
    subprocess.run(['kicad-cli', 'pcb', 'drc', '--refill-zones', '--save-board', '-o', str(HERE / 'drc.rpt'), str(PCB)],
                   check=True, capture_output=True)
    (HERE / 'drc.rpt').unlink()
    fabrication()
    print(f'placed {len(parts)} parts, {len(nets)} nets on {WIDTH:g} x {HEIGHT:g} mm')


if __name__ == '__main__':
    fabrication() if sys.argv[1:] == ['fab'] else main()   # 'fab': re-export files of the current board only
