// Student Tag case V3, for the ordered "Student Remote" board (image/Student Remote, 50 x 75 mm): the SuperMini is
// soldered straight onto the board on its pins (no sockets), so the top shell drops about 10 mm. One flat top at
// the height the module, C5, the power switch and the displays need; the key caps reach up to it through guide
// sleeves. Push-on battery door underneath (2 x AAA under the keys, three snap arms). No USB-C opening: the tag runs on
// cells, the case is opened to flash it.
// (V1 and V2, made for an earlier generated board, were removed on 2026-10-05.)
//
//   openscad -D 'part="top"' -o top.stl student_tag_case_v3.scad   (also: bottom, door, keys, assembly)
//   /usr/bin/python3 tests/case_test.py v3   exports every part and checks it against the real board.
//
// Frame: X right, Y up, origin at the board's lower-left corner (the key end); the board covers X 0..50, Y 0..75;
// KiCad (x, y) maps to (x - 119.87, 137.48 - y). All numbers in mm.
// Print: PLA or PETG, 0.2 mm layers, no supports. Top shell face down, bottom shell floor down, door outer face
// down, key caps face down.
// Assembly: key caps into the top shell from inside, board onto the bottom shell, top shell pressed on (six snap
// tongues click), one M2x16 screw from below at the board's top-left hole.

part = "assembly";   // top | top_print | bottom | door | keys | key0..key11 | assembly
key_set = [0 : 11];

// ---------------------------------------------------------------- measure these on the real parts
pcb_t       = 1.6;
sw_h        = 1.5;    // ALPS SKQG (with stem) above the board
sw_travel   = 0.3;    // SKQG press travel: the key's legs land on the board 0.15 mm after it
module_gap  = 0.0;    // SuperMini underside above the board (0: pins pushed through, boards flush)
module_t    = 1.0;    // SuperMini board thickness
usb_h       = 3.3;    // USB-C receptacle on the module
pin_top     = 1.0;    // header pin ends left above the module after soldering (trim them)
c5_h        = 5.5;    // C5 100 uF electrolytic
sw13_h      = 6.4;    // SW13 Wuerth WS-SLTV slide switch (10 x 2.5 x 6.4 per its footprint name)
led_h       = 6.2;    // 7-segment digit above the board (KiCad HDSP-A151 model; 0.28" 2811AS is lower)
aaa_d       = 10.5;
aaa_l       = 44.5;
contact_len = 5.5;
contact_t   = 0.6;
plate_w     = 10;
motor_d     = 10;     // 1027 coin motor, 10 x 2.7 mm, glued in its seat on the floor

// ---------------------------------------------------------------- print fit and shape
wall = 1.5; floor_t = 1.5; roof_t = 0.8;      // walls 1.0-1.5 mm: compact, still stiff in PLA
top_t   = 1.2;        // head skin
sleeve_wall = 1.0;    // key guide sleeves under the top
gap  = 1.15;          // board edge to inner wall
fit  = 0.3;
clr  = 0.2;
R = 5.2; r_top = 1.2; r_bot = 2.5;   // inner corner radius R - wall = 3.7 clears the board's square corners
$fn = 48;

BW = 50; BL = 75;
in0  = [-gap, -gap];           in1  = [BW + gap, BL + gap];
out0 = in0 - [wall, wall];     out1 = in1 + [wall, wall];

// ---------------------------------------------------------------- stack (Z)
z_pcb     = floor_t + aaa_d + 0.3 + roof_t;            // the board lies on the battery bay roof
z_pcb_top = z_pcb + pcb_t;
split     = z_pcb - 2.4;                               // shells meet below the board
lip_h     = 1.5 - fit;                                 // the top shell's lip, down into the bottom shell
z_head_in = z_pcb_top + max(c5_h, sw13_h, led_h, module_gap + module_t + usb_h, module_gap + module_t + pin_top) + 0.5;
z_guide   = z_pcb_top + sw_h + 0.8 + 0.2;              // guide sleeves end 0.2 above the key caps' flange (0.8) resting on the switch

// ---------------------------------------------------------------- board features (from Student Remote.kicad_pcb)
holes    = [[13.02, 24.64], [13.06, 12.61], [37.02, 12.49], [37.06, 24.63], [3.29, 72.12]];   // M2
pins_at  = [0, 1, 2, 3];                               // locating pins in the top shell, pressing the board down
screw_at = 4;                                          // through screw from below
key_x    = [7.01, 19.01, 31.01, 43.01];
key_y    = [31.49, 19.49, 7.49];
labels   = [["1", "2", "3", "4"], ["5", "6", "7", "8"], ["←", "9", "0", "OK"]];
answers  = ["A", "B", "C", "D"];
window   = [14.7, 38.85, 32.3, 52.55];                 // both digits' courtyards (U3, U4) plus 0.2
shroud   = 0.8;                                        // light shroud wall round the window, hides the rest of the board
module_y = 65.06;                                      // centre line of the SuperMini (J1 Y 57.5, J2 Y 72.6) and its USB-C
sw13_y   = 63.8;                                       // power switch lever, left edge

// ---------------------------------------------------------------- battery door (as V2)
arm_h  = 6.0; arm_t = 1.6; arm_tip = 1.0; hook_p = 0.5; moat = 0.3; arm_w = 7;   // tapered 1.6 -> 1.0: strain 1.9 %
arms   = [[8.5, 15.5, -1], [34.5, 41.5, -1], [21.5, 28.5, 1]];
cell_z = floor_t + aaa_d / 2;
cell_half = sqrt(pow(aaa_d / 2, 2) - pow(cell_z - floor_t - arm_h, 2));
bay_y0 = 6.0;
arm_room = 0.2 + arm_t + hook_p + 0.15;
cells_y = [bay_y0 + arm_room + cell_half, bay_y0 + arm_room + cell_half + aaa_d + 0.6];
bay_y1  = cells_y[1] + cell_half + arm_room;
catch_z = floor_t + arm_h - hook_p - 0.15;
door_x  = [0.8, BW - 0.8];
door_y  = [5.2, bay_y1 + 1.0];
step    = 1.0;

// ---------------------------------------------------------------- battery bay
cx0      = BW / 2 - (aaa_l + contact_len) / 2;
cx1      = BW / 2 + (aaa_l + contact_len) / 2;
roof_z   = floor_t + aaa_d + 0.3;
support_y1 = 36.3;                                      // solid support under the top key row and its legs
rib2     = [12, 52.6, 48, 55.6];                       // support between the display pins and the module's lower pin row

// ---------------------------------------------------------------- snap tongues: hang from the top shell, inside the wall
// Short and tapered: 1.6 mm at the root, 1.0 at the hook (0.55 did not print, long 1.2 broke easily). tongue_in of it
// sits in a slot in the bottom wall, the rest in the cavity; the hook reaches tongue_p further into the wall (0.85 mm
// skin left). Root gussets: a wedge above the split into the top shell's wall, and fins at both edges against side
// knocks. Strain at the root (tapered beam, bent tongue_p) = 1.9 %.
tongue_l = 6; tongue_t = 1.6; tongue_tip = 1.0; tongue_w = 10; tongue_p = 0.4; hook_h = 1.2; tongue_in = 0.15;
root_h = 2.0; fin = 1.5;
tongues  = [[-1, 42], [-1, 56], [1, 41], [1, 56], [0, 12], [0, 38]];   // [-1 left wall | 1 right wall | 0 key end, Y or X]

H         = z_head_in + top_t;
z_top_in  = H - top_t;
H_deck    = H;                                         // one flat top (V1 style)
name_y = 71.5; name_d = 1.0;                           // "STUDENT TAG" across the top end, 1 mm deep over a 0.8 mm pad

// ---------------------------------------------------------------- keys
key_hole = 9.0; key_cap = key_hole - 2 * fit; key_r = 1.5;
tab_len = 10.4; tab_w = 5; tab_t = 0.8; key_out = 2.0; plunger_d = 3;
leg_w = 4; leg_t = 1.2; leg_y = 3.0;
z_cap0   = z_pcb_top + sw_h;                           // cap bottom (flange) resting on the switch
leg_gap  = sw_travel + 0.15;

// ---------------------------------------------------------------- shapes
module rsq(w, d, r) { offset(r) offset(-r) square([w, d], center = true); }
module rrect(x0, y0, x1, y1, r, h) { linear_extrude(h) translate([(x0 + x1) / 2, (y0 + y1) / 2]) rsq(x1 - x0, y1 - y0, r); }
module corner(r, h, rb, rt) {
    rotate_extrude() hull() {
        square([0.01, h]);
        translate([r - rb, rb]) circle(rb);
        translate([r - rt, h - rt]) circle(rt);
    }
}
module rbox(a, b, z0, z1, r, rb, rt) {
    hull() for (x = [a[0] + r, b[0] - r], y = [a[1] + r, b[1] - r]) translate([x, y, z0]) corner(r, z1 - z0, rb, rt);
}
module outer() { rbox(out0, out1, 0, H, R, r_bot, r_top); }
module inner(z0, z1) { rbox(in0, in1, z0, z1, R - wall, 0.01, 0.01); }
module cavity(z0) { inner(z0, z_top_in); }
module label(t, size, depth = 0.6) {
    linear_extrude(depth + 0.01) text(t, size = size, font = "DejaVu Sans:style=Bold", halign = "center", valign = "center");
}
module slab(z0, z1) { translate([out0[0] - 5, out0[1] - 5, z0]) cube([out1[0] - out0[0] + 10, out1[1] - out0[1] + 10, z1 - z0]); }
module lip_band(z0, h, f, e = 0) {   // e: reach this far into the cavity (avoids coincident faces on a cut)
                             // the inner half of the wall, f = fit taken off its outer face
    translate([0, 0, z0]) difference() {
        rrect(in0[0] - wall / 2 + f / 2, in0[1] - wall / 2 + f / 2, in1[0] + wall / 2 - f / 2, in1[1] + wall / 2 - f / 2, R - wall / 2, h);
        translate([0, 0, -1]) linear_extrude(h + 2) translate([BW / 2, BL / 2]) rsq(in1[0] - in0[0] - 2 * e, in1[1] - in0[1] - 2 * e, R - wall - e);
    }
}
// Place a child (drawn for the left wall: X = wall face, Z = tongue root, extends along +Y) on each tongue spot.
module at_tongue(t) {
    if (t[0] == -1) translate([in0[0], t[1], 0]) children();
    else if (t[0] == 1) translate([in1[0], t[1], 0]) mirror([1, 0, 0]) children();
    else translate([t[1], in0[1], 0]) rotate([0, 0, 90]) children();
}

// One snap tongue (left-wall frame), its hook (30 deg lead-in at the tip, flat catch above) and the root wedge.
module tongue() {
    translate([0, tongue_w / 2, 0]) rotate([90, 0, 0]) linear_extrude(tongue_w)                    // tapered body
        polygon([[-tongue_in, split - tongue_l], [-tongue_in + tongue_tip, split - tongue_l], [-tongue_in + tongue_t, split + 0.01], [-tongue_in, split + 0.01]]);
    for (s = [-1, 1]) translate([-tongue_in, s * tongue_w / 2, split]) rotate([90, 0, 90])          // edge fins
        linear_extrude(tongue_t - 0.2) polygon([[0, 0.01], [s * fin, 0.01], [0, -fin]]);
    translate([-tongue_in, tongue_w / 2, split - tongue_l]) rotate([90, 0, 0]) linear_extrude(tongue_w)
        polygon([[0.01, 0], [-tongue_p, tongue_p / tan(30)], [-tongue_p, hook_h], [0.01, hook_h]]);
    translate([0, tongue_w / 2, split]) rotate([90, 0, 0]) linear_extrude(tongue_w)
        polygon([[-0.01, -0.01], [tongue_t - tongue_in, -0.01], [-0.01, root_h]]);
}

// ---------------------------------------------------------------- top shell
module top_shell() {
    difference() {
        union() {
            difference() { intersection() { outer(); slab(split, H + 1); } cavity(split - 0.01); }
            lip_band(split - lip_h, lip_h + 0.01, fit);                                      // lip into the bottom shell
            for (t = tongues) at_tongue(t) tongue();                                        // snap tongues
            // Window: a shroud from the top skin down past the digits so only they show (acrylic glued on outside).
            translate([0, 0, z_pcb_top + 1.0]) difference() {
                rrect(window[0] - shroud, window[1] - shroud, window[2] + shroud, window[3] + shroud, 1.2 + shroud, z_top_in - z_pcb_top - 1.0 + 0.01);
                translate([0, 0, -1]) rrect(window[0], window[1], window[2], window[3], 0.5, 20);
            }
            for (x = key_x, y = key_y) translate([x, y, z_guide])                            // key guide sleeves
                linear_extrude(z_top_in - z_guide + 0.01) rsq(key_hole + 2 * sleeve_wall, key_hole + 2 * sleeve_wall, key_r + fit + sleeve_wall);
            for (i = pins_at) translate([holes[i][0], holes[i][1], z_pcb_top]) {             // bosses pressing the board, locating pins
                cylinder(d = 3.2, h = z_top_in - z_pcb_top + 0.01);
                translate([0, 0, -1.2]) cylinder(d = 1.8, h = 1.21);
            }
            translate([holes[screw_at][0], holes[screw_at][1], z_pcb_top]) cylinder(d = 4.2, h = z_top_in - z_pcb_top + 0.01);
            intersection() { cavity(split); translate([4, name_y - 3.5, z_top_in - 0.8]) cube([BW - 8, 7, 0.81]); }   // pad under the name, clear of SW13
        }
        for (x = key_x, y = key_y) translate([x, y, split - 0.01]) linear_extrude(H) rsq(key_hole, key_hole, key_r + fit);
        translate([0, 0, z_top_in - 5]) rrect(window[0], window[1], window[2], window[3], 0.5, 10);
        translate([0, 0, H - 0.8]) hull() {
            rrect(window[0], window[1], window[2], window[3], 1.2, 0.01);
            translate([0, 0, 0.8]) rrect(window[0] - 0.8, window[1] - 0.8, window[2] + 0.8, window[3] + 0.8, 2, 0.02);
        }
        translate([holes[screw_at][0], holes[screw_at][1], z_pcb_top - 1]) cylinder(d = 1.7, h = 7);                     // M2 pilot
        translate([out0[0] - 1, sw13_y - 4, z_pcb_top + 0.8]) cube([wall + 2, 8, 5]);         // power switch slot
        translate([out0[0] - 1, sw13_y - 6, z_pcb_top + 0.3]) cube([1 + 1.0, 12, 6]);         // finger recess around it
        translate([BW / 2, name_y, H - name_d]) label("STUDENT TAG", 4.0, name_d);
    }
}

// ---------------------------------------------------------------- bottom shell
module door_opening() {
    translate([door_x[0] - clr, door_y[0] - clr, -1]) cube([door_x[1] - door_x[0] + 2 * clr, door_y[1] - door_y[0] + 2 * clr, 1 + floor_t / 2]);
    translate([door_x[0] + step - clr, door_y[0] + step - clr, floor_t / 2 - 0.01])
        cube([door_x[1] - door_x[0] - 2 * step + 2 * clr, door_y[1] - door_y[0] - 2 * step + 2 * clr, floor_t / 2 + 0.02]);
}

module bottom_shell() {
    difference() {
        union() {
            difference() { intersection() { outer(); slab(-1, split); } inner(floor_t, split + 1); }
            // Standoff under the screw: 1.2 mm wall round the head recess, flared foot, three ribs to the walls / floor
            // (the one towards J4 stays low, under its pins).
            let(h = holes[screw_at]) translate([h[0], h[1], 0]) {
                cylinder(d = 6.4, h = z_pcb);
                cylinder(d1 = 9.4, d2 = 6.4, h = floor_t + 2.0);
            }
            let(h = holes[screw_at]) {
                intersection() { inner(0, z_pcb); translate([in0[0], h[1] - 0.6, 0]) cube([h[0] - in0[0], 1.2, z_pcb]); }   // stays off the top shell's rounded corner
                translate([h[0] - 0.6, h[1], 0]) cube([1.2, in1[1] - h[1], z_pcb]);
                translate([h[0], h[1] - 0.6, 0]) cube([4.2, 1.2, floor_t + 6]);
            }
            intersection() {                                                                // bay walls, roof and support under the keys
                inner(0, z_pcb);
                translate([in0[0], bay_y0 - 3, 0]) cube([in1[0] - in0[0], support_y1 - bay_y0 + 3, z_pcb]);
            }
            translate([rib2[0], rib2[1], 0]) cube([rib2[2] - rib2[0], rib2[3] - rib2[1], z_pcb]);
            translate([6.5, 44, floor_t - 0.01]) difference() { cylinder(d = motor_d + 2.3, h = 2); translate([0, 0, -1]) cylinder(d = motor_d + 0.3, h = 4); }   // motor seat
        }
        lip_band(split - lip_h - 0.01, lip_h + 0.02, 0, 0.05);                                    // rebate for the top shell's lip
        for (t = tongues) at_tongue(t) {                                                    // slot for each tongue, pocket for its hook
            translate([-tongue_in - 0.15, -tongue_w / 2 - 0.3, split - tongue_l - 0.3]) cube([tongue_in + 0.16, tongue_w + 0.6, tongue_l + 0.31]);
            translate([-tongue_in - 0.15, -tongue_w / 2 - fin - 0.3, split - fin - 0.3]) cube([tongue_in + 0.16, tongue_w + 2 * fin + 0.6, fin + 0.31]);
            translate([-tongue_in - tongue_p - 0.1, -tongue_w / 2 - 0.3, split - tongue_l - 0.3]) cube([tongue_in + tongue_p + 0.11, tongue_w + 0.6, hook_h + 0.6]);
            // room for the tongue and its bend where the support under the keys reaches the side walls
            if (t[0] != 0) translate([-0.01, -tongue_w / 2 - fin - 0.3, split - tongue_l - 0.3]) cube([tongue_t + tongue_p + 0.3, tongue_w + 2 * fin + 0.6, tongue_l + root_h + 0.6]);
        }
        translate([cx0, bay_y0, floor_t - 0.01]) cube([cx1 - cx0, bay_y1 - bay_y0, roof_z - floor_t + 0.01]);
        door_opening();
        for (a = arms) translate([a[0] - 0.3, a[2] < 0 ? bay_y0 - hook_p - 0.1 : bay_y1 - 0.01, catch_z - 0.6])
            cube([a[1] - a[0] + 0.6, hook_p + 0.11, 1.2]);
        for (y = [door_y[0] - clr - 0.3, door_y[1] + clr + 0.3]) translate([25, y, 0]) scale([4, 2.2, 1]) sphere(1, $fn = 32);
        for (y = cells_y) {
            translate([cx0 - contact_t - 0.1, y - plate_w / 2 - 0.15, floor_t]) cube([contact_t + 0.11, plate_w + 0.3, roof_z - floor_t]);
            translate([cx0 - contact_t - 0.1, y - 1.6, roof_z - 0.01]) cube([contact_t + 0.11, 3.2, z_pcb - roof_z + 0.02]);
        }
        translate([cx1 - 0.01, cells_y[0] - plate_w / 2 - 0.15, floor_t])
            cube([contact_t + 0.11, cells_y[1] - cells_y[0] + plate_w + 0.3, roof_z - floor_t]);
        translate([holes[screw_at][0], holes[screw_at][1], -1]) {
            cylinder(d = 2.3, h = z_pcb + 2);
            cylinder(d = 4.0, h = 1 + 2.2);
        }
    }
}

// ---------------------------------------------------------------- battery door (as V2)
function arm_y(a) = a[2] < 0 ? door_y[0] + step : bay_y1 - 0.2 - arm_t;
module snap_arm(a, bend = 0) {
    y0 = arm_y(a);
    h = floor_t + arm_h - floor_t / 2;
    tilt = a[2] * bend * atan((hook_p + 0.05) / h);
    translate([0, y0 + arm_t / 2, floor_t / 2]) rotate([tilt, 0, 0]) translate([0, -y0 - arm_t / 2, 0]) {
        translate([a[0], 0, 0]) rotate([90, 0, 90]) linear_extrude(arm_w)                           // tapered, hook face flat
            let(f = a[2] < 0 ? y0 : y0 + arm_t, d = a[2] < 0 ? 1 : -1)
            polygon([[f, -0.01], [f + d * arm_t, -0.01], [f + d * arm_tip, h], [f, h]]);
        translate([a[0], a[2] < 0 ? y0 : y0 + arm_t, 0]) rotate([90, 0, 90]) linear_extrude(arm_w)
            polygon([[-a[2] * 0.05, catch_z - floor_t / 2 - hook_p / tan(35)], [a[2] * hook_p, catch_z - floor_t / 2],   // 0.05 into the arm
                     [a[2] * hook_p, catch_z - floor_t / 2 + 0.15], [-a[2] * 0.05, h]]);
    }
}
module door(bend = 0) {
    difference() {
        union() {
            translate([door_x[0], door_y[0], 0]) cube([door_x[1] - door_x[0], door_y[1] - door_y[0], floor_t / 2]);
            difference() {
                translate([door_x[0] + step, door_y[0] + step, floor_t / 2 - 0.01]) cube([door_x[1] - door_x[0] - 2 * step, door_y[1] - door_y[0] - 2 * step, floor_t / 2 + 0.01]);
                for (a = arms) translate([a[0] - moat, arm_y(a) - moat, floor_t / 2]) cube([arm_w + 2 * moat, arm_t + 2 * moat, floor_t]);
            }
            for (a = arms) snap_arm(a, bend);
        }
        for (y = [door_y[0], door_y[1]]) translate([25, y, -0.01]) scale([3, 1.2, 0.6]) sphere(1, $fn = 24);
        for (i = [0 : 1], e = [0 : 1])
            translate([e == 0 ? 4 : BW - 4, cells_y[i], floor_t - 0.4]) label((i + e) % 2 == 0 ? "+" : "−", 4, 0.4);
    }
}

// ---------------------------------------------------------------- key caps: flange under the deck, legs stop over-travel
module key_cap(t, sub = "") {
    body = H_deck + key_out - z_cap0;                    // flange bottom to key face
    legs = z_cap0 - (z_pcb_top + leg_gap);
    difference() {
        union() {
            translate([-tab_len / 2, -tab_w / 2, 0]) cube([tab_len, tab_w, tab_t]);
            hull() {
                linear_extrude(body - 0.5) rsq(key_cap, key_cap, key_r);
                linear_extrude(body) rsq(key_cap - 1, key_cap - 1, key_r - 0.5);
            }
            for (s = [-1, 1]) translate([-leg_w / 2, s > 0 ? leg_y : -leg_y - leg_t, -legs]) cube([leg_w, leg_t, legs + 0.01]);
        }
        translate([0, 0, -0.01]) linear_extrude(body - 1.2) difference() {                 // hollow, open at the bottom
            rsq(key_cap - 2, key_cap - 2, key_r - 0.5);
            circle(d = plunger_d + 2);                                                     // solid centre pressing the stem
        }
        translate([sub == "" ? 0 : -0.9, sub == "" ? 0 : -0.5, body - 0.6]) label(t, len(t) > 1 ? 3.0 : (t == "←" ? 5 : 4.4));
        if (sub != "") translate([2.3, 2.2, body - 0.6]) label(sub, 2.2);
    }
}
function key_label(r, c) = labels[r][c];
function key_sub(r, c) = r == 0 ? answers[c] : "";
module key_in_place(i) {
    r = floor(i / 4); c = i % 4;
    translate([key_x[c], key_y[r], z_cap0]) key_cap(key_label(r, c), key_sub(r, c));
}
module keys_in_place() { for (i = [0 : 11]) key_in_place(i); }
module door_in_place() { door(); }

// ---------------------------------------------------------------- output
if (part == "dims") echo(z_pcb = z_pcb, z_pcb_top = z_pcb_top, H = H, key_x = key_x, key_y = key_y, holes = holes,
                         window = window, module_y = module_y, sw13_y = sw13_y, module_gap = module_gap, module_t = module_t,
                         usb_h = usb_h, size = [out1[0] - out0[0], out1[1] - out0[1], H],
                         leg_gap = leg_gap, cells_y = cells_y,
                         cx = [cx0, cx1], cell_z = cell_z, bay = [cx0, bay_y0, cx1, bay_y1], door_y = door_y,
                         arm = [arm_h, arm_t, hook_p, catch_z], H_deck = H_deck, split = split,
                         sw13_h = sw13_h, led_h = led_h, pin_top = pin_top, z_cap0 = z_cap0, tongues = tongues);
else if (part == "top") top_shell();
else if (part == "top_print") translate([0, 0, H]) rotate([180, 0, 0]) top_shell();
else if (part == "bottom") bottom_shell();
else if (part == "door") door();
else if (part == "keys") for (i = key_set) let(r = floor(i / 4), c = i % 4)
    translate([c * 13, r * 13, H_deck + key_out - z_cap0]) rotate([180, 0, 0]) key_cap(key_label(r, c), key_sub(r, c));
else if (part == "keys_placed") keys_in_place();
else if (len(part) > 3 && part[0] == "k" && part[1] == "e" && part[2] == "y") key_in_place(part == "key10" ? 10 : part == "key11" ? 11 : ord(part[3]) - 48);
else {
    color("Teal", 0.9) bottom_shell();
    color("Teal") door_in_place();
    color("WhiteSmoke", 0.4) top_shell();
    color("DarkSlateGray") keys_in_place();
}
