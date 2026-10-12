#!/usr/bin/python3
"""Student Tag case V2: STL files to print, STEP files to open in SolidWorks (or any CAD) and a print guide, in export/.

    /usr/bin/python3 image/StudentTag/case_v2/export_v2.py      (needs OpenSCAD and kicad-cli; run case_test.py v2 first)

stl/   every printed piece in its own file (16: two shells, two covers, twelve key caps named by their legend), already
       turned the way it prints; one filament colour, the colours are only for viewing. stl.zip holds them with the guide.
step/  every part where it sits in the assembled tag, plus the board from KiCad. All share one origin: the board's
       lower-left corner at the key end, Z = 0 on the board's underside, mm. Inserted at the origin of a SolidWorks
       assembly they line up by themselves.

OpenSCAD 2021 writes no STEP, so the case parts go through the meshes: each flat patch of the mesh becomes one
planar face, rounded surfaces become narrow flat faces. The STEP files are read back and checked (every edge shared
by exactly two faces, in opposite directions; the solid's volume equals the mesh's) before anything is kept.
"""
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile
from concurrent.futures import ThreadPoolExecutor
from datetime import date
from pathlib import Path

import numpy as np
import trimesh

HERE = Path(__file__).resolve().parent
SCAD = HERE / 'student_tag_case_v2.scad'
PCB = HERE.parent / 'StudentTag.kicad_pcb'
OUT = HERE / 'export'
KICAD_ORIGIN = '120.5x138mm'                  # the board's lower-left corner in KiCad coordinates

# Viewing colours in the STEP files (the tag prints in one colour). Key index = row * 4 + column (row 0 = 1 2 3 4).
KEY_RGB = [(0.85, 0.23, 0.23), (0.18, 0.44, 0.84), (0.95, 0.76, 0.19), (0.23, 0.65, 0.34)] + [(0.18, 0.21, 0.22)] * 7 + [(0.12, 0.44, 0.47)]
COLOUR = {'top': (0.90, 0.92, 0.91), 'bottom': (0.12, 0.44, 0.47), 'door': (0.16, 0.54, 0.58), 'cover': (0.23, 0.26, 0.28)}


def openscad(part, out, **defines):
    args = ['openscad', '-D', f'part="{part}"'] + [a for k, v in defines.items() for a in ('-D', f'{k}={v}')] + ['-o', str(out), str(SCAD)]
    run = subprocess.run(args, capture_output=True, text=True, timeout=1800)
    if run.returncode or not Path(out).exists():
        sys.exit(f'OpenSCAD failed on {part}:\n{run.stderr[-1500:]}')
    return out


def dims():
    with tempfile.TemporaryDirectory() as tmp:
        openscad('dims', f'{tmp}/d.echo')
        text = Path(f'{tmp}/d.echo').read_text()
    grab = lambda key: eval(re.search(rf'\b{key} = (\[[^\]]*\]|[-\d.e]+)', text).group(1))
    return {k: grab(k) for k in ('z_pcb', 'H', 'size')}


# ---------------------------------------------------------------- STEP (ISO 10303-21, AP214) from closed meshes
class Step:
    def __init__(self):
        self.lines = []

    def add(self, text):
        self.lines.append(text)
        return f'#{len(self.lines)}'

    def point(self, p):
        return self.add("CARTESIAN_POINT('',(%s))" % ','.join(f'{c:.6f}' for c in p))

    def direction(self, d):
        return self.add("DIRECTION('',(%s))" % ','.join(f'{c:.9f}' for c in d))


def face_groups(mesh):
    """Each coplanar patch of the mesh (trimesh facet) is one face; every other triangle is a face of its own."""
    grouped = np.zeros(len(mesh.faces), bool)
    groups = []
    for facet in mesh.facets:
        corners = mesh.vertices[mesh.faces[facet].ravel()]
        normal = mesh.face_normals[facet[0]]
        if np.abs((corners - corners[0]) @ normal).max() > 1e-4:   # not flat enough for one PLANE: keep its triangles
            continue
        groups.append(np.asarray(facet))
        grouped[facet] = True
    return groups + [np.array([i]) for i in np.nonzero(~grouped)[0]]


def boundary_loops(mesh, group):
    """The group's boundary as closed vertex loops, in the triangles' winding: outer loop counter-clockwise, holes clockwise."""
    edges = mesh.faces[group][:, [0, 1, 1, 2, 2, 0]].reshape(-1, 2)
    _, inverse, count = np.unique(np.sort(edges, axis=1), axis=0, return_inverse=True, return_counts=True)
    after = {}
    for a, b in edges[count[inverse.ravel()] == 1]:
        after.setdefault(int(a), []).append(int(b))
    loops = []
    while after:
        start = next(iter(after))
        walk, v = [start], start
        while True:
            b = after[v].pop()
            if not after[v]:
                del after[v]
            if b == start:
                break
            walk.append(b)
            v = b
        # A loop that passes a vertex twice (two holes touching) is split there: CAD wants simple loops.
        stack = [walk]
        while stack:
            loop = stack.pop()
            seen = {}
            for i, v in enumerate(loop):
                if v in seen:
                    stack += [loop[seen[v]:i], loop[:seen[v]] + loop[i:]]
                    break
                seen[v] = i
            else:
                loops.append(loop)
    return loops


def closed_shell(st, mesh, z_shift):
    """One CLOSED_SHELL of planar faces; vertices and edges shared between the faces that meet there."""
    V = mesh.vertices + [0, 0, z_shift]
    vertex_ids, edge_ids = {}, {}

    def vertex(a):
        if a not in vertex_ids:
            vertex_ids[a] = st.add(f"VERTEX_POINT('',{st.point(V[a])})")
        return vertex_ids[a]

    def edge(a, b):
        u, w = min(a, b), max(a, b)
        if (u, w) not in edge_ids:
            d = V[w] - V[u]
            length = np.linalg.norm(d)
            line = st.add(f"LINE('',{st.point(V[u])},{st.add(f'VECTOR({chr(39)}{chr(39)},{st.direction(d / length)},{length:.6f})')})")
            edge_ids[(u, w)] = st.add(f"EDGE_CURVE('',{vertex(u)},{vertex(w)},{line},.T.)")
        return edge_ids[(u, w)], '.T.' if a == u else '.F.'

    faces = []
    for group in face_groups(mesh):
        normal = mesh.face_normals[group[0]]
        loops = boundary_loops(mesh, group)
        area = [0.5 * np.dot(np.cross(V[l], np.roll(V[l], -1, axis=0)).sum(axis=0), normal) for l in loops]
        outer = int(np.argmax(area))
        bounds = []
        for i, loop in enumerate(loops):
            oriented = [st.add("ORIENTED_EDGE('',*,*,%s,%s)" % edge(a, b)) for a, b in zip(loop, loop[1:] + loop[:1])]
            bounds.append(st.add(f"{'FACE_OUTER_BOUND' if i == outer else 'FACE_BOUND'}('',{st.add('EDGE_LOOP(%s,(%s))' % (chr(39) * 2, ','.join(oriented)))},.T.)"))
        p0 = V[loops[outer][0]]
        ref = V[loops[outer][1]] - p0
        ref -= np.dot(ref, normal) * normal
        placement = st.add(f"AXIS2_PLACEMENT_3D('',{st.point(p0)},{st.direction(normal)},{st.direction(ref / np.linalg.norm(ref))})")
        faces.append(st.add(f"ADVANCED_FACE('',({','.join(bounds)}),{st.add(f'PLANE({chr(39)}{chr(39)},{placement})')},.T.)"))
    return st.add(f"CLOSED_SHELL('',({','.join(faces)}))")


def solids(st, name, mesh, z_shift):
    """Solid items for one mesh: each outer shell, with the voids that sit inside it (hollow key caps, support block)."""
    parts = mesh.split(only_watertight=False)
    outers = [p for p in parts if p.volume > 0]
    voids = [p for p in parts if p.volume < 0]
    items = []
    for n, shell in enumerate(outers):
        inside = [v for v in voids if np.all(v.bounds[0] >= shell.bounds[0] - 1e-6) and np.all(v.bounds[1] <= shell.bounds[1] + 1e-6)]
        label = name if len(outers) == 1 else f'{name}_{n + 1}'
        outer = closed_shell(st, shell, z_shift)
        if inside:
            # A void is written as the shell of its hollow (normals out of the hollow), used reversed.
            hollows = [st.add(f"ORIENTED_CLOSED_SHELL('',*,{closed_shell(st, trimesh.Trimesh(v.vertices, v.faces[:, ::-1]), z_shift)},.F.)") for v in inside]
            items.append(st.add(f"BREP_WITH_VOIDS('{label}',{outer},({','.join(hollows)}))"))
        else:
            items.append(st.add(f"MANIFOLD_SOLID_BREP('{label}',{outer})"))
    return items


def write_step(path, bodies, z_shift):
    """bodies: [(name, mesh, (r, g, b))]; one part file holding every body as a coloured solid."""
    st = Step()
    app = st.add("APPLICATION_CONTEXT('core data for automotive mechanical design processes')")
    st.add(f"APPLICATION_PROTOCOL_DEFINITION('international standard','automotive_design',2000,{app})")
    product = st.add(f"PRODUCT('{path.stem}','{path.stem}','',({st.add(f'PRODUCT_CONTEXT({chr(39)}{chr(39)},{app},{chr(39)}mechanical{chr(39)})')}))")
    st.add(f"PRODUCT_RELATED_PRODUCT_CATEGORY('part',$,({product}))")
    definition = st.add(f"PRODUCT_DEFINITION('design','',{st.add(f'PRODUCT_DEFINITION_FORMATION({chr(39)}{chr(39)},{chr(39)}{chr(39)},{product})')},"
                        f"{st.add(f'PRODUCT_DEFINITION_CONTEXT({chr(39)}part definition{chr(39)},{app},{chr(39)}design{chr(39)})')})")
    shape = st.add(f"PRODUCT_DEFINITION_SHAPE('','',{definition})")
    mm = st.add('(LENGTH_UNIT()NAMED_UNIT(*)SI_UNIT(.MILLI.,.METRE.))')
    rad = st.add('(NAMED_UNIT(*)PLANE_ANGLE_UNIT()SI_UNIT($,.RADIAN.))')
    sr = st.add('(NAMED_UNIT(*)SI_UNIT($,.STERADIAN.)SOLID_ANGLE_UNIT())')
    tol = st.add(f"UNCERTAINTY_MEASURE_WITH_UNIT(LENGTH_MEASURE(1.E-05),{mm},'distance_accuracy_value','confusion accuracy')")
    context = st.add(f"(GEOMETRIC_REPRESENTATION_CONTEXT(3)GLOBAL_UNCERTAINTY_ASSIGNED_CONTEXT(({tol}))"
                     f"GLOBAL_UNIT_ASSIGNED_CONTEXT(({mm},{rad},{sr}))REPRESENTATION_CONTEXT('Context #1','3D Context with UNIT and UNCERTAINTY'))")
    axis = st.add(f"AXIS2_PLACEMENT_3D('',{st.point([0, 0, 0])},{st.direction([0, 0, 1])},{st.direction([1, 0, 0])})")
    items, styled = [], []
    for name, mesh, rgb in bodies:
        mine = solids(st, name, mesh, z_shift)
        items += mine
        colour = st.add("COLOUR_RGB('',%.3f,%.3f,%.3f)" % rgb)
        fill = st.add(f"SURFACE_STYLE_FILL_AREA({st.add(f'FILL_AREA_STYLE({chr(39)}{chr(39)},({st.add(f"FILL_AREA_STYLE_COLOUR({chr(39)}{chr(39)},{colour})")}))')})")
        usage = st.add(f"SURFACE_STYLE_USAGE(.BOTH.,{st.add(f'SURFACE_SIDE_STYLE({chr(39)}{chr(39)},({fill}))')})")
        style = st.add(f"PRESENTATION_STYLE_ASSIGNMENT(({usage}))")
        styled += [st.add(f"STYLED_ITEM('color',({style}),{item})") for item in mine]
    rep = st.add(f"ADVANCED_BREP_SHAPE_REPRESENTATION('{path.stem}',({axis},{','.join(items)}),{context})")
    st.add(f"SHAPE_DEFINITION_REPRESENTATION({shape},{rep})")
    st.add(f"MECHANICAL_DESIGN_GEOMETRIC_PRESENTATION_REPRESENTATION('',({','.join(styled)}),{context})")
    header = ("ISO-10303-21;\nHEADER;\nFILE_DESCRIPTION(('Student Tag case V2'),'2;1');\n"
              f"FILE_NAME('{path.name}','{date.today().isoformat()}T00:00:00',(''),(''),'export_v2.py','','');\n"
              "FILE_SCHEMA(('AUTOMOTIVE_DESIGN { 1 0 10303 214 1 1 1 1 }'));\nENDSEC;\nDATA;\n")
    path.write_text(header + ''.join(f'#{i}={line};\n' for i, line in enumerate(st.lines, 1)) + 'ENDSEC;\nEND-ISO-10303-21;\n')


def check_step(path, want_volume):
    """Read the file back: every edge twice with opposite senses in its shell, and the solids' volume."""
    ent = dict(re.findall(r'^#(\d+)=(.*);$', path.read_text(), re.M))
    refs = lambda text: [int(r) for r in re.findall(r'#(\d+)', text)]
    coords = lambda i: np.array([float(c) for c in re.search(r'\(([^()]*)\)\)$', ent[str(i)]).group(1).split(',')])

    def shell(i):
        uses, volume = {}, 0.0
        for face in refs(ent[str(i)]):
            f = ent[str(face)]
            *bounds, plane = refs(f)
            placement = refs(ent[str(plane)])[0]
            origin, normal = (coords(r) for r in refs(ent[str(placement)])[:2])
            area = 0.0
            for bound in bounds:
                points = []
                for oe in refs(ent[str(refs(ent[str(bound)])[0])]):
                    curve = refs(ent[str(oe)])[0]
                    sense = ent[str(oe)].rstrip(')').endswith('.T.')
                    uses.setdefault(curve, []).append(sense)
                    start, end = (coords(refs(ent[str(v)])[0]) for v in refs(ent[str(curve)])[:2])
                    points.append(start if sense else end)
                p = np.array(points)
                area += 0.5 * np.dot(np.cross(p, np.roll(p, -1, axis=0)).sum(axis=0), normal)
            volume += np.dot(origin, normal) * area / 3
        bad = [c for c, s in uses.items() if sorted(s) != [False, True]]
        return volume, bad

    total, bad = 0.0, []
    for i, text in ent.items():
        if text.startswith('MANIFOLD_SOLID_BREP') or text.startswith('BREP_WITH_VOIDS'):
            ids = refs(text)
            v, b = shell(ids[0])
            total += v
            bad += b
            for oriented in ids[1:]:
                v, b = shell(refs(ent[str(oriented)])[0])
                total -= v
                bad += b
    ok = not bad and abs(total - want_volume) <= 1e-3 * want_volume
    return ok, total, len(bad)


# ---------------------------------------------------------------- print and CAD guide
KEY_NAMES = ['1A', '2B', '3C', '4D', '5', '6', '7', '8', 'BACK', '9', '0', 'OK']   # key index = row * 4 + column
PRINT = {'vo-tren': ('top_print', {}, 'Mặt có chữ úp xuống bàn'), 'vo-duoi': ('bottom', {}, 'Đáy xuống bàn'),
         'nap-pin': ('door', {}, 'Mặt ngoài xuống bàn, 3 lẫy hướng lên'), 'nap-usb': ('cover', {}, 'Mặt ngoài xuống bàn, 2 chốt hướng lên')}
PRINT.update({f'phim_{name}': ('keys', {'key_set': f'[{i}]'}, f'Mặt có ký hiệu {"←" if name == "BACK" else name} úp xuống bàn')
              for i, name in enumerate(KEY_NAMES)})


def write_guide(d, volumes):
    vn = lambda x, n=2: f'{x:.{n}f}'.replace('.', ',')
    rows = '\n'.join(f'| `{name}.stl` | {how} | {vn(volumes[name])} |' for name, (_, _, how) in PRINT.items())
    total = sum(volumes.values())
    (OUT / 'HUONG_DAN_IN.md').write_text(f"""# Vỏ Student Tag V2: file in 3D và file CAD

Sinh bằng `export_v2.py` từ `student_tag_case_v2.scad`: sửa file `.scad` rồi chạy lại, không sửa tay các file ở đây.
Kích thước ngoài {vn(d['size'][0], 1)} × {vn(d['size'][1], 1)} × {vn(d['size'][2])} mm.

## `stl/`: in 3D, một màu (cũng có trong `stl.zip` kèm file này)

16 file, mỗi file in 1 cái: vỏ trên, vỏ dưới, nắp pin (3 lẫy in liền), nắp USB (2 chốt in liền) và 12 phím.
12 phím khác nhau vì mỗi phím khắc một ký hiệu; 12 lỗ trên vỏ cùng cỡ, lắp phím theo ký hiệu:
`1 2 3 4 / 5 6 7 8 / ← 9 0 OK` (BACK là phím ←).

| File | Đặt khi in (đã xoay sẵn) | Nhựa (cm³, khối đặc) |
|---|---|---|
{rows}

Tổng {vn(total, 1)} cm³ khối đặc, tối đa khoảng {round(total * 1.24)} g PLA; in có độ đặc thì ít hơn.

- PLA hoặc PETG, đầu phun 0,4 mm, lớp 0,2 mm, **không cần support**.
- Vỏ trên, vỏ dưới: 3 đường thành, độ đặc 15–20 %.
- Nắp pin, nắp USB: độ đặc 100 % (lẫy 0,8 mm và chốt cần đặc). Muốn lẫy dẻo hơn thì in nắp pin bằng PETG.
- In thử trước nắp pin, nắp USB và 1 phím để xem độ khít trên máy in của bạn.

## Vật tư mua thêm

4 vít M2×20 đầu tròn, bộ tiếp điểm AAA (1 lá +, 1 lò xo −, 1 lá nối tiếp), 2 pin AAA, motor rung 0834,
mica đỏ 1,5 mm cắt 19,4 × 15,6 mm (không bắt buộc), dây mảnh 26–30 AWG. Chi tiết: `../../BOM.md`.

## Lắp ráp

1. Cắm module SuperMini vào socket J1/J2; cắt chân xuyên lỗ dưới board còn tối đa 3,8 mm.
2. Ấn lá + và lò xo − vào 2 hốc bên trái khoang pin, lá nối tiếp vào hốc bên phải; đặt motor vào ổ.
3. Hàn dây pin vào J4 (đỏ chân 2, đen chân 1 GND), dây motor vào J3; đặt board nằm phẳng trên nóc khoang pin.
4. Úp vỏ trên xuống bàn, thả 12 phím vào ống dẫn, đặt mica vào hốc cửa sổ LED; lật vỏ trên úp lên board.
5. Bắt 4 vít M2×20 từ đáy.
6. Ấn nắp USB vào hốc ở cạnh phải: 2 chốt bấm lọt qua khe, nắp trượt được nhưng không rơi.
7. Lắp 2 pin theo dấu + / − khắc trong nắp pin, ấn thẳng nắp vào tới khi nghe “tách”. Tháo: móng tay vào khe kéo, kéo thẳng ra.

## `step/`: mở trên SolidWorks

| File | Là gì |
|---|---|
| `student_tag_v2_vo-lap-rap.step` | Cả vỏ: 16 khối (vỏ trên, vỏ dưới, nắp pin, nắp USB, 12 phím), đã tô màu để dễ nhìn |
| `student_tag_v2_vo-tren.step`, `…_vo-duoi.step`, `…_nap-pin.step`, `…_nap-usb.step`, `…_phim.step` | Từng phần riêng |
| `student_tag_board_kicad.step` | Board và linh kiện, xuất từ KiCad (chưa có module SuperMini: KiCad không có mô hình của nó) |

- Mọi file dùng chung một gốc tọa độ: góc dưới trái của board (phía phím), X sang phải, Y về phía module,
  Z = 0 ở mặt dưới board, đơn vị mm, đúng vị trí khi lắp. Trong SolidWorks: tạo Assembly, Insert Component
  từng file, đặt tại gốc (Fix) là các phần khớp nhau.
- File vỏ là khối đặc (solid) ghép từ các mặt phẳng; mặt bo cong gồm nhiều mặt nhỏ. Đo, lắp ráp, ra bản vẽ được,
  nhưng không có lịch sử tính năng (feature tree). Muốn đổi kích thước thì sửa `student_tag_case_v2.scad` và
  chạy lại `export_v2.py`.

## Cần đo trên linh kiện thật trước khi in

| Thông số trong `.scad` | Đang dùng | Đo ở đâu |
|---|---|---|
| `sw_h` / `sw_travel` | 1,5 / 0,3 mm | Nút SKQG: cao từ mặt board tới đỉnh cần; nhấn bao sâu thì ăn |
| `socket_h` | 8,5 mm | Socket J1/J2: từ mặt board tới mặt dưới module |
| `contact_len` / `contact_t` / `plate_w` | 5,5 / 0,6 / 10 mm | Bộ tiếp điểm AAA mua về |
| `motor_d` | 8,2 mm | Motor rung 0834 |

Đổi số nào thì chạy lại `/usr/bin/python3 tests/case_test.py v2` (kiểm tra lắp ghép) rồi `export_v2.py`.
""")


# ---------------------------------------------------------------- main
def main():
    d = dims()
    stl, step = OUT / 'stl', OUT / 'step'
    if OUT.exists():
        shutil.rmtree(OUT)
    stl.mkdir(parents=True)
    step.mkdir()
    with tempfile.TemporaryDirectory() as tmp, ThreadPoolExecutor(8) as pool:
        placed = {'top': 'top', 'bottom': 'bottom', 'door': 'door', 'cover': 'cover_closed'} | {f'key{i}': f'key{i}' for i in range(12)}
        jobs = [(stl / f'{name}.stl', part, defines) for name, (part, defines, _) in PRINT.items()]
        jobs += [(Path(tmp) / f'{name}.stl', part, {}) for name, part in placed.items()]
        list(pool.map(lambda job: openscad(job[1], job[0], **job[2]), jobs))
        volumes = {}
        for f in sorted(stl.glob('*.stl')):
            mesh = trimesh.load(f)
            if not mesh.is_watertight or mesh.bounds[0][2] < -1e-6 or mesh.bounds[0][2] > 1e-6:
                sys.exit(f'{f.name}: not closed or not lying on the bed')
            volumes[f.stem] = mesh.volume / 1000
            print(f'stl  {f.name:28s} {mesh.volume / 1000:6.2f} cm3')

        mesh = {name: trimesh.load(Path(tmp) / f'{name}.stl') for name in placed}
        files = {
            'student_tag_v2_vo-tren': [('vo_tren', mesh['top'], COLOUR['top'])],
            'student_tag_v2_vo-duoi': [('vo_duoi', mesh['bottom'], COLOUR['bottom'])],
            'student_tag_v2_nap-pin': [('nap_pin', mesh['door'], COLOUR['door'])],
            'student_tag_v2_nap-usb': [('nap_usb', mesh['cover'], COLOUR['cover'])],
            'student_tag_v2_phim': [(f'phim_{i + 1}', mesh[f'key{i}'], KEY_RGB[i]) for i in range(12)],
        }
        files['student_tag_v2_vo-lap-rap'] = [b for bodies in files.values() for b in bodies]
        for name, bodies in files.items():
            path = step / f'{name}.step'
            write_step(path, bodies, -d['z_pcb'])
            want = sum(m.volume for _, m, _ in bodies)
            ok, got, bad = check_step(path, want)
            print(f'step {path.name:36s} {len(bodies):2d} bodies  volume {got / 1000:6.2f} cm3 (mesh {want / 1000:6.2f})  '
                  f'{path.stat().st_size / 1e6:5.1f} MB  {"OK" if ok else f"BAD ({bad} edges)"}')
            if not ok:
                sys.exit(f'{path.name} failed the read-back check')

    board = step / 'student_tag_board_kicad.step'
    run = subprocess.run(['kicad-cli', 'pcb', 'export', 'step', '--force', '--subst-models', '--user-origin', KICAD_ORIGIN,
                          '-o', str(board), str(PCB)], capture_output=True, text=True, timeout=1800)
    if run.returncode or not board.exists():
        sys.exit('kicad-cli could not export the board:\n' + run.stdout[-800:] + run.stderr[-800:])
    print(f'step {board.name:36s} board + parts from KiCad  {board.stat().st_size / 1e6:5.1f} MB')
    write_guide(d, volumes)
    with zipfile.ZipFile(OUT / 'stl.zip', 'w', zipfile.ZIP_DEFLATED) as z:        # one file to send to the print shop
        for f in sorted(stl.glob('*.stl')):
            z.write(f, f'student_tag_v2_stl/{f.name}')
        z.write(OUT / 'HUONG_DAN_IN.md', 'student_tag_v2_stl/HUONG_DAN_IN.md')
    print(f'zip  stl.zip  {len(volumes)} STL + guide  {(OUT / "stl.zip").stat().st_size / 1e6:.1f} MB')
    print(f'case {d["size"][0]:.1f} x {d["size"][1]:.1f} x {d["size"][2]:.2f} mm -> {OUT}')


if __name__ == '__main__':
    main()
