#!/usr/bin/env python3
"""Student Tag PCB: DRC clean against the schematic, fully connected, 50 x 80 mm, fabrication files present.

    /usr/bin/python3 image/StudentTag/gen_pcb.py && python3 tests/pcb_test.py

Needs kicad-cli (KiCad 10). DRC runs with --schematic-parity, so a footprint or net that differs from
image/StudentTag/StudentTag.kicad_sch fails here, as do unrouted connections and clearance errors.
"""
import re
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path

HERE = Path(__file__).resolve().parents[1] / 'image/StudentTag'
PCB = HERE / 'StudentTag.kicad_pcb'
FAB = HERE / 'StudentTag_fab.zip'


def check(label, ok, detail=''):
    print('PASS' if ok else 'FAIL', label, '' if ok else detail)
    return 0 if ok else 1


def main():
    failures = 0
    with tempfile.TemporaryDirectory() as tmp:
        subprocess.run(['kicad-cli', 'pcb', 'drc', '--severity-all', '--schematic-parity', '-o', f'{tmp}/drc.rpt', str(PCB)],
                       check=True, capture_output=True)
        report = Path(f'{tmp}/drc.rpt').read_text()
    kinds = re.findall(r'^\[(\w+)\]', report, re.M)
    counts = ', '.join(f'{kinds.count(k)} {k}' for k in sorted(set(kinds))) or 'none'
    failures += check(f'DRC with schematic parity: no violations ({counts})', not kinds)
    unconnected = re.search(r'Found (\d+) unconnected pads', report)
    failures += check('every connection routed', unconnected and unconnected.group(1) == '0', unconnected and unconnected.group(0))

    text = PCB.read_text()
    edge = [tuple(map(float, xy)) for block in re.findall(r'\((?:gr_line|gr_arc)(.*?)\(layer "Edge\.Cuts"\)', text, re.S)
            for xy in re.findall(r'\((?:start|end|mid) ([-\d.]+) ([-\d.]+)\)', block)]
    xs, ys = [p[0] for p in edge], [p[1] for p in edge]
    size = (round(max(xs) - min(xs), 2), round(max(ys) - min(ys), 2)) if edge else None
    failures += check('board outline is 50 x 80 mm', size == (50.0, 80.0), size)
    layers = set(re.findall(r'\(layer "([FB]\.Cu)"\)', text))
    failures += check('two copper layers', layers == {'F.Cu', 'B.Cu'}, layers)

    names = zipfile.ZipFile(FAB).namelist() if FAB.exists() else []
    needed = ['F_Cu.gbr', 'B_Cu.gbr', 'F_Mask.gbr', 'B_Mask.gbr', 'F_Paste.gbr', 'B_Paste.gbr', 'F_Silkscreen.gbr', 'Edge_Cuts.gbr', '.drl', 'pos.csv']
    failures += check('fabrication zip has copper, mask, silk, outline, drill and placement',
                      all(any(n.endswith(k) for n in names) for k in needed), sorted(names))
    print('ALL PASS' if not failures else f'{failures} FAIL')
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
