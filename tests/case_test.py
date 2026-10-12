#!/usr/bin/python3
"""Student Tag cases: every printed part renders to a closed mesh, lines up with the real board, and touches no part.

    /usr/bin/python3 tests/case_test.py [v1|v2]    (both by default; system Python: reads the board through KiCad's pcbnew)

Needs OpenSCAD. The keep-out model is built from image/StudentTag/StudentTag.kicad_pcb: each footprint's courtyard,
as tall as its KiCad 3D model (through-hole pins below the board as deep as the model's pins), plus the SuperMini
module on its sockets, which has no KiCad model. STL files for printing are written to each case's stl/ folder.
V2 adds: the board is supported under every key's over-travel legs, the battery bay has no line of sight from
outside with the door shut, the push-on door is held by its snap arms and goes on and off straight with the arms
bent clear of the case and the cells, the cells fit, and the sliding USB-C cover covers the port when closed,
clears a cable when open and cannot be pulled off or slid past either end.
"""
import re
import subprocess
import sys
import tempfile
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import pcbnew
import trimesh

ROOT = Path(__file__).resolve().parents[1]
PCB = ROOT / 'image/StudentTag/StudentTag.kicad_pcb'
CASES = {
    'v1': (ROOT / 'image/StudentTag/case/student_tag_case.scad', ('top', 'bottom', 'door', 'keys')),
    'v2': (ROOT / 'image/StudentTag/case_v2/student_tag_case_v2.scad', ('top', 'bottom', 'door', 'keys', 'cover')),
}
MODELS = Path('/usr/share/kicad/3dmodels')
LEFT, TOP, BOARD_H = 120.5, 58.0, 80.0
failures = 0
pool = ThreadPoolExecutor(max_workers=8)


def check(label, ok, detail=''):
    global failures
    print('PASS' if ok else 'FAIL', label, '' if ok else detail)
    failures += not ok


def openscad(*args):
    return subprocess.run(['openscad', *args], capture_output=True, text=True, timeout=1800)


def render(scad, body, tmp):
    """Render `body` (OpenSCAD code using the case's modules); returns the mesh, or None when the result is empty."""
    src = Path(tempfile.mkstemp(suffix='.scad', dir=tmp)[1])
    src.write_text(f'use <{scad}>\n{body}\n')
    out = openscad('-o', str(src.with_suffix('.stl')), str(src))
    if 'top level object is empty' in out.stderr:
        return None
    if out.returncode:
        sys.exit(f'OpenSCAD failed on:\n{body}\n{out.stderr[-1500:]}')
    return trimesh.load(src.with_suffix('.stl'))


def volume(mesh):
    return 0.0 if mesh is None else abs(mesh.volume)


def dims(scad):
    """The case's own numbers, echoed by OpenSCAD (part="dims"), as a dict."""
    with tempfile.TemporaryDirectory() as tmp:
        out = openscad('-D', 'part="dims"', '-o', f'{tmp}/dims.echo', str(scad))
        echo = re.search(r'ECHO: (.*)', Path(f'{tmp}/dims.echo').read_text() if Path(f'{tmp}/dims.echo').exists() else '')
    if not echo:
        sys.exit('OpenSCAD gave no dimensions:\n' + out.stderr[-2000:])
    values, depth, start = {}, 0, 0
    text = echo.group(1) + ','
    for i, ch in enumerate(text):              # split on top-level commas: "key = value, key = [..], ..."
        depth += (ch == '[') - (ch == ']')
        if ch == ',' and depth == 0:
            key, raw = text[start:i].split(' = ', 1)
            values[key.strip()] = eval(raw)
            start = i + 1
    return values


def model_z(footprint):
    """Lowest and highest point of the footprint's KiCad 3D model (mm above the board)."""
    for model in footprint.Models():
        path = Path(model.m_Filename.replace('${KICAD9_3DMODEL_DIR}', str(MODELS)).replace('${KICAD10_3DMODEL_DIR}', str(MODELS)))
        path = path.with_suffix('.step')
        if path.exists():
            zs = [float(z) for z in re.findall(r"CARTESIAN_POINT\('[^']*',\(\s*[-\d.E+]+,\s*[-\d.E+]+,\s*([-\d.E+]+)\)\)", path.read_text(errors='ignore'))]
            return min(zs), max(zs)
    return 0.0, 3.0   # no model (MSK-12C02 slide switch): a generous 3 mm


def keepout(d, board):
    """Boxes (x0, y0, z0, x1, y1, z1, name) in case coordinates that no case part may enter."""
    to_case = lambda x, y: (pcbnew.ToMM(x) - LEFT, TOP + BOARD_H - pcbnew.ToMM(y))
    boxes = []
    for fp in board.GetFootprints():
        ref = fp.GetReference()
        if ref.startswith('H'):
            continue
        z_lo, z_hi = model_z(fp)
        court = fp.GetCourtyard(pcbnew.B_Cu if fp.IsFlipped() else pcbnew.F_Cu)
        box = court.BBox() if not court.IsEmpty() else fp.GetBoundingBox(False)
        (x0, y1), (x1, y0) = to_case(box.GetLeft(), box.GetTop()), to_case(box.GetRight(), box.GetBottom())
        if fp.IsFlipped():
            boxes.append((x0, y0, d['z_pcb'] - z_hi, x1, y1, d['z_pcb'], ref))
        else:
            boxes.append((x0, y0, d['z_pcb_top'], x1, y1, d['z_pcb_top'] + z_hi, ref))
        for pad in fp.Pads():
            if pad.GetAttribute() == pcbnew.PAD_ATTRIB_PTH and z_lo < 0:   # pins sticking out under the board
                px, py = to_case(pad.GetPosition().x, pad.GetPosition().y)
                boxes.append((px - 0.6, py - 0.6, d['z_pcb'] + z_lo, px + 0.6, py + 0.6, d['z_pcb'], f'{ref} pin'))
    # SuperMini on its sockets: 18 x 33 mm board plus the USB-C receptacle, centred between the two header rows.
    top = d['z_pcb_top'] + d['socket_h']
    boxes.append((14.0, d['module_y'] - 9, top, 48.9, d['module_y'] + 9, top + d['module_t'] + d['usb_h'], 'SuperMini module'))
    # The board, 2 mm corner radius, as two crossed boxes (the shell's inner corners follow the rounded outline);
    # 0.05 mm thinner because the standoffs and bosses touch it by design.
    for x0, y0, x1, y1 in [(0.0, 2.0, 50.0, 78.0), (2.0, 0.0, 48.0, 80.0)]:
        boxes.append((x0, y0, d['z_pcb'] + 0.05, x1, y1, d['z_pcb_top'] - 0.05, 'board'))
    return boxes


def scad_boxes(boxes):
    return 'union() {\n' + ''.join(
        f'  translate([{x0:.3f}, {y0:.3f}, {z0:.3f}]) cube([{x1 - x0:.3f}, {y1 - y0:.3f}, {z1 - z0:.3f}]);  // {name}\n'
        for x0, y0, z0, x1, y1, z1, name in boxes) + '}\n'


def clash(scad, part_call, boxes, tmp):
    """Names of the keep-out boxes the part intersects (empty when it touches nothing)."""
    def find(chosen):                           # halve the list until each clash is a single part: ~2 log2(n) renders
        if render(scad, f'intersection() {{\n  {part_call};\n  {scad_boxes(chosen)}}}', tmp) is None:
            return []
        if len(chosen) == 1:
            return [chosen[0][6]]
        return find(chosen[:len(chosen) // 2]) + find(chosen[len(chosen) // 2:])
    return find(boxes)


def overlap(scad, a, b, tmp):
    # Faces that only touch (the shells meet at the split plane) give a zero-volume result: that is fine.
    return volume(render(scad, f'intersection() {{ {a}; {b}; }}', tmp))


def v2_checks(scad, d, tmp):
    """Checks for what V2 adds: key support, sealed bay, snap-on door, cells, sliding USB-C cover."""
    z_pcb = d['z_pcb']
    closed, opened = d['cover'][0], d['cover'][1]          # cover = [y closed, y open, length, z0, z1]
    bx0, by0, bx1, by1 = d['bay']
    py, pz, pw, ph = d['port']
    # Each key has two legs, 4 x 1.2 mm, centred 3.6 mm either side of the key (student_tag_case_v2.scad, key_cap).
    legs = [(x, y + t * 3.6) for x in d['key_x'] for y in d['key_y'] for t in (-1, 1)]
    leg_cubes = ''.join(f'translate([{x - 2:.3f}, {y - 0.6:.3f}, {z_pcb - 0.2:.3f}]) cube([4, 1.2, 0.2]);' for x, y in legs)
    cell = lambda y: f'translate([{25 - 44.5 / 2}, {y}, {d["cell_z"]}]) rotate([0, 90, 0]) cylinder(d = 10.4, h = 44.5, $fn = 32)'
    jobs = {
        # Board support right under the key legs: the bottom shell's top face must fill every leg's footprint.
        'support': f'intersection() {{ bottom_shell(); union() {{ {leg_cubes} }} }}',
        # Bay sealed: shadow of (bottom shell + closed door) in the floor layers must cover the whole bay from below.
        'sealed': f'difference() {{ translate([{bx0}, {by0}, 0]) cube([{bx1 - bx0}, {by1 - by0}, 1]); translate([0, 0, -1]) linear_extrude(3) '
                  f'projection() intersection() {{ union() {{ bottom_shell(); door_in_place(); }} translate([-5, 0, 0]) cube([60, 45, 1.6]); }} }}',
        'cells': ' '.join(f'intersection() {{ union() {{ bottom_shell(); door_in_place(); }} {cell(y)}; }}' for y in d['cells_y']),
        'door drops': 'intersection() { bottom_shell(); translate([0, 0, -0.4]) door_in_place(); }',
        'door stops': 'intersection() { bottom_shell(); translate([0, 0, 0.3]) door_in_place(); }',
        'bent arms/cells': ' '.join(f'intersection() {{ door(1); {cell(y)}; }}' for y in d['cells_y']),
        'cable': f'intersection() {{ union() {{ top_shell(); bottom_shell(); cover({opened}); }} translate([48.9, {py}, {pz}]) '
                 f'rotate([90, 0, 90]) linear_extrude(8) offset(1.8) offset(-1.8) square([{pw - 0.6}, {ph - 0.6}], center = true); }}',
        'covers port': f'intersection() {{ cover({closed}); translate([{d["size"][0] - 2.95 - 3}, {py}, {pz}]) rotate([90, 0, 90]) '
                       f'linear_extrude(5) square([{pw - 1}, {ph - 1}], center = true); }}',
        'pulled out': f'intersection() {{ top_shell(); translate([0.6, 0, 0]) cover({closed}); }}',
        'past open end': f'intersection() {{ top_shell(); cover({opened - 1.0}); }}',
        'past closed end': f'intersection() {{ top_shell(); cover({closed + 1.0}); }}',
    }
    paths = (0.5, 2, 4, 6.5)
    for dz in paths:
        jobs[f'door path {dz}'] = f'intersection() {{ bottom_shell(); translate([0, 0, -{dz}]) door(1); }}'
    results = dict(zip(jobs, pool.map(lambda body: render(scad, body, tmp), jobs.values())))
    vol = lambda k: volume(results[k])

    covered = [0.0] * len(legs)
    if results['support'] is not None:
        for piece in results['support'].split(only_watertight=False):
            cx, cy = piece.centroid[:2]
            i = min(range(len(legs)), key=lambda k: (legs[k][0] - cx) ** 2 + (legs[k][1] - cy) ** 2)
            covered[i] += abs(piece.volume) / (4 * 1.2 * 0.2)
    arm_h, arm_t, hook_p, _ = d['arm']
    check('board is supported right under all 24 key legs (>= 99 %)', min(covered) >= 0.99, [round(c, 2) for c in covered])
    check('battery bay: no line of sight from outside with the door shut', results['sealed'] is None, 'uncovered area remains')
    check('two AAA cells fit the bay', vol('cells') < 0.01, vol('cells'))
    check('door cannot drop: the three hooks sit on their pockets', results['door drops'] is not None)
    check('door cannot be pushed further in', results['door stops'] is not None)
    check(f'door goes on and off straight with its {arm_h:g} mm arms bent {hook_p:g} mm', all(vol(f'door path {dz}') < 0.01 for dz in paths),
          [round(vol(f'door path {dz}'), 3) for dz in paths])
    check('bent arms stay clear of the cells', vol('bent arms/cells') < 0.01, vol('bent arms/cells'))
    check('USB-C cover closed covers the whole port', results['covers port'] is not None)
    check('USB-C cover open leaves a cable free to plug in', vol('cable') < 0.01, vol('cable'))
    check('USB-C cover cannot be pulled off the wall', results['pulled out'] is not None)
    check('USB-C cover stops at both ends of its travel', results['past open end'] is not None and results['past closed end'] is not None)
    check(f'key legs stop {d["leg_gap"]:.2f} mm above the board, plungers rest on the switches', abs(d['leg_gap'] - 0.45) < 0.2)


def run(name):
    scad, parts = CASES[name]
    d = dims(scad)
    print(f'--- {name}: case {d["size"][0]:.1f} x {d["size"][1]:.1f} x {d["size"][2]:.1f} mm, board at z {d["z_pcb"]:.1f}')
    stl = scad.parent / 'stl'
    stl.mkdir(exist_ok=True)
    outs = pool.map(lambda part: openscad('-D', f'part="{part}"', '-o', str(stl / f'{part}.stl'), str(scad)), parts)
    for part, out in zip(parts, outs):
        mesh = trimesh.load(stl / f'{part}.stl') if (stl / f'{part}.stl').exists() else None
        check(f'{part}: renders to a closed, printable mesh', out.returncode == 0 and mesh is not None and mesh.is_watertight
              and mesh.volume > 0, out.stderr[-500:])

    board = pcbnew.LoadBoard(str(PCB))
    boxes = keepout(d, board)
    to_case = lambda p: (round(pcbnew.ToMM(p.x) - LEFT, 2), round(TOP + BOARD_H - pcbnew.ToMM(p.y), 2))
    fp = {f.GetReference(): f for f in board.GetFootprints()}
    keys = sorted(to_case(fp[f'SW{n}'].GetPosition()) for n in range(1, 13))
    holes = sorted(to_case(fp[f'H{n}'].GetPosition()) for n in range(1, 5))
    want_keys = sorted((x, y) for x in d['key_x'] for y in d['key_y'])
    check('12 key holes sit on the 12 switches (0.1 mm)', all(abs(a[0] - b[0]) < 0.1 and abs(a[1] - b[1]) < 0.1 for a, b in zip(keys, want_keys)), (keys, want_keys))
    check('4 screw bosses sit on the 4 board holes (0.1 mm)', all(abs(a[0] - b[0]) < 0.1 and abs(a[1] - b[1]) < 0.1
          for a, b in zip(holes, sorted(map(tuple, d['holes'])))), (holes, d['holes']))
    displays = [b for b in boxes if b[6] in ('U3', 'U4')]
    w = d['window']
    check('display window lies over both displays', all(w[0] < (b[0] + b[3]) / 2 < w[2] and w[1] < (b[1] + b[4]) / 2 < w[3] for b in displays), (w, displays))
    sw13 = to_case(fp['SW13'].GetPosition())
    check('power switch slot at SW13', abs(sw13[1] - d['sw13_y']) < 1.0, (sw13, d['sw13_y']))

    with tempfile.TemporaryDirectory() as tmp:
        parts_on_board = [('top shell', 'top_shell()'), ('bottom shell', 'bottom_shell()'), ('key caps', 'keys_in_place()')]
        pairs = [('top_shell()', 'bottom_shell()'), ('bottom_shell()', 'door_in_place()'), ('top_shell()', 'keys_in_place()')]
        if name == 'v2':
            cv = d['cover']
            parts_on_board += [('door', 'door_in_place()'), ('cover closed', f'cover({cv[0]})'), ('cover open', f'cover({cv[1]})')]
            pairs += [('top_shell()', f'cover({cv[0]})'), ('top_shell()', f'cover({cv[1]})'), ('bottom_shell()', f'cover({cv[1]})')]
        hits = pool.map(lambda item: clash(scad, item[1], boxes, tmp), parts_on_board)
        vols = pool.map(lambda ab: overlap(scad, *ab, tmp), pairs)
        for (label, _), hit in zip(parts_on_board, hits):
            check(f'{label} clears every part on the board', not hit, hit)
        for (a, b), vol in zip(pairs, vols):
            check(f'{a[:-2]} and {b.split("(")[0]} do not overlap when assembled (overlap {vol:.3f} mm3)', vol < 0.01)
        if name == 'v2':
            v2_checks(scad, d, tmp)


def main():
    if subprocess.run(['which', 'openscad'], capture_output=True).returncode:
        sys.exit('OpenSCAD is not installed: sudo apt install -y openscad')
    for name in sys.argv[1:] or list(CASES):
        run(name)
    print('ALL PASS' if not failures else f'{failures} FAIL')
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
