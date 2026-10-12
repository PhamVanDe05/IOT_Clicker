// Student Tag case V2: two-tone rounded body, 2xAAA lying across the board under the keys, push-on battery door
// held by three 5 mm snap arms, USB-C cover sliding along the outside of the right wall, key caps with an
// over-travel stop. V1 (../case/) stays as it is.
//
//   openscad -D 'part="top"' -o top.stl student_tag_case_v2.scad   (also: bottom, door, keys, cover, assembly)
//   /usr/bin/python3 tests/case_test.py v2   exports every part and checks it against the real board.
//
// Frame as V1: X right, Y up, origin at the board's lower-left corner (the key end); the board covers X 0..50,
// Y 0..80; KiCad (x, y) maps to (x - 120.5, 138 - y). All numbers in mm.
// Print: PLA or PETG, 0.2 mm layers, no supports. Top shell face down, bottom shell floor down, door outer face
// down, USB cover outer face down, key caps face down (answer keys 1-4 in four colours: red, blue, yellow, green).
// Parts to buy: 4 x M2x20 screw (self-tapping into the top shell), AAA contacts: 1 plus plate, 1 minus spring,
// 1 series bridge; optional 1.5 mm red acrylic for the display window.

part = "assembly";   // top | top_print | bottom | door | cover | cover_closed | cover_open | keys | key0..key11 | assembly
key_set = [0 : 11];  // part "keys": which caps to lay out for printing, e.g. -D 'key_set=[0]' for the red 1A cap

// ---------------------------------------------------------------- measure these on the real parts
pcb_t       = 1.6;
sw_h        = 1.5;    // ALPS SKQG height above the board
sw_travel   = 0.3;    // SKQG press travel: the key's legs land on the board 0.15 mm after it
socket_h    = 8.5;    // female header under the module
module_t    = 1.2;    // SuperMini board thickness
usb_h       = 3.3;    // USB-C receptacle on the module
aaa_d       = 10.5;
aaa_l       = 44.5;
contact_len = 5.5;    // board length both contacts take: spring (compressed) + plate
contact_t   = 0.6;    // contact plate thickness
plate_w     = 10;     // contact plate width
motor_d     = 8.2;    // 0834 coin motor

// ---------------------------------------------------------------- print fit and shape
wall = 1.8; floor_t = 1.6; top_t = 1.8; roof_t = 0.8;
gap  = 1.15;          // board edge to inner wall: room for R7 outer corners around the board's R2 corners
fit  = 0.3;           // key caps in their holes
clr  = 0.2;           // door and cover
R = 7; r_top = 3; r_bot = 3;
$fn = 48;

BW = 50; BL = 80;
in0  = [-gap, -gap];           in1  = [BW + gap, BL + gap];
out0 = in0 - [wall, wall];     out1 = in1 + [wall, wall];

// ---------------------------------------------------------------- stack (Z)
z_pcb     = floor_t + aaa_d + 0.3 + roof_t;            // the board lies on the battery bay roof
z_pcb_top = z_pcb + pcb_t;
split     = z_pcb_top;                                 // bottom shell ends at the board's top face
lip_h     = 1.5 - fit;
usb_z     = z_pcb_top + socket_h + module_t + usb_h / 2;
port      = [13, 7];                                   // USB-C opening (Y, Z): passes a cable's overmold
usb_top   = usb_z + port[1] / 2;
H         = usb_top + 0.5 + 0.3 + r_top;               // the right wall stays flat up to the cover's top edge
z_top_in  = H - top_t;

// ---------------------------------------------------------------- board features (from StudentTag.kicad_pcb)
holes    = [[2.6, 77.4], [47.4, 77.4], [2.8, 3.0], [47.2, 3.0]];     // M2
key_x    = [6.38, 18.38, 30.38, 42.38];
key_y    = [33, 21, 9];
labels   = [["1", "2", "3", "4"], ["5", "6", "7", "8"], ["←", "9", "0", "OK"]];
answers  = ["A", "B", "C", "D"];                       // firmware: keys 1-4 choose answers A-D
window   = [10.0, 39.8, 26.7, 52.7];                  // both HDSP-7501 bodies
module_y = 65.6;                                        // centre line of the module and its USB-C port
sw13_y   = 67.5;                                        // power switch lever, left edge

// ---------------------------------------------------------------- battery door: pushed straight on, three snap arms
// Two arms at the key end, one at the display end, each standing 5 mm on the door just inside the bay's end faces.
arm_h  = 5.0;                                          // arm height above the door
arm_t  = 0.8;                                          // arm thickness
hook_p = 0.35;                                         // hook depth = how far the arm bends
moat   = 0.3;                                          // gap round the arm's root, cut through the door's inner half only
arm_w  = 6;
arms   = [[9, 15, -1], [35, 41, -1], [22, 28, 1]];     // x0, x1, hook direction (-1: key end, +1: display end)
cell_z = floor_t + aaa_d / 2;                          // cells lie on the closed door, flush with the floor
cell_half = sqrt(pow(aaa_d / 2, 2) - pow(cell_z - floor_t - arm_h, 2));   // a cell's half width at the arms' top
bay_y0 = 7.4;
arm_room = 0.2 + arm_t + hook_p + 0.15;               // end face to the bent arm's back, plus clearance
cells_y = [bay_y0 + arm_room + cell_half, bay_y0 + arm_room + cell_half + aaa_d + 0.6];
bay_y1  = cells_y[1] + cell_half + arm_room;
catch_z = floor_t + arm_h - hook_p - 0.15;             // the hooks sit in pockets in the bay's end faces at this height
door_x  = [0.8, BW - 0.8];                             // outer flange
door_y  = [6.6, bay_y1 + 1.0];
step    = 1.0;                                         // inner half 1 mm smaller: the gap has no straight line into the bay

// ---------------------------------------------------------------- battery bay: two cells across the board, under the keys
// Nothing on the board's underside below the keys (checked in case_test), so the board lies flat on the bay roof.
cx0      = BW / 2 - (aaa_l + contact_len) / 2;         // contact faces
cx1      = BW / 2 + (aaa_l + contact_len) / 2;
roof_z   = floor_t + aaa_d + 0.3;
support_y1 = 37.8;                                      // solid support under the top key row and its legs (display pins start 39.6)
rib2     = [12, 53.6, 48, 55.4];                       // support between the display pins and J1, wires pass at X < 12

// ---------------------------------------------------------------- USB-C cover: a plate sliding in a shallow recess, held by two snap pegs
sl_len    = port[0] + 1.6;
sl_closed = module_y - sl_len / 2;                     // plate Y start, closed / open
sl_open   = sl_closed - sl_len;
sl_z      = [usb_z - port[1] / 2 - 2.75, usb_top + 0.5];   // the pegs ride below the port
sl_t      = 0.8;
pegs      = [3.3, 11.3];                               // peg positions along the plate
peg_z     = sl_z[0] + 1.0;

// ---------------------------------------------------------------- keys
key_hole = 9.0; key_cap = key_hole - 2 * fit; key_r = 1.5; sleeve = 6; sleeve_wall = 1.2;
tab_len = 10.4; tab_w = 5; tab_t = 1.2; key_out = 1.5; plunger_d = 3;
leg_w = 4; leg_t = 1.2; leg_y = 3.0;                   // two legs, outside the switch courtyard (Y +-2.9)
z_sleeve = z_top_in - sleeve;
z_cap0   = z_sleeve - 0.2 - tab_t;                     // cap bottom (tabs) when resting on the switch
leg_gap  = sw_travel + 0.15;

// ---------------------------------------------------------------- shapes
module rsq(w, d, r) { offset(r) offset(-r) square([w, d], center = true); }
module rrect(x0, y0, x1, y1, r, h) { linear_extrude(h) translate([(x0 + x1) / 2, (y0 + y1) / 2]) rsq(x1 - x0, y1 - y0, r); }
module corner(r, h, rb, rt) {                          // profile of one rounded corner, revolved
    rotate_extrude() hull() {
        square([0.01, h]);
        translate([r - rb, rb]) circle(rb);
        translate([r - rt, h - rt]) circle(rt);
    }
}
module rbox(a, b, z0, z1, r, rb, rt) {                 // box with round vertical corners and filleted top/bottom edges
    hull() for (x = [a[0] + r, b[0] - r], y = [a[1] + r, b[1] - r]) translate([x, y, z0]) corner(r, z1 - z0, rb, rt);
}
module outer() { rbox(out0, out1, 0, H, R, r_bot, r_top); }
module inner(z0, z1) { rbox(in0, in1, z0, z1, R - wall, 0.01, 0.01); }
module label(t, size, depth = 0.6) {
    linear_extrude(depth + 0.01) text(t, size = size, font = "DejaVu Sans:style=Bold", halign = "center", valign = "center");
}
module slab(z0, z1) { translate([out0[0] - 5, out0[1] - 5, z0]) cube([out1[0] - out0[0] + 10, out1[1] - out0[1] + 10, z1 - z0]); }

// ---------------------------------------------------------------- top shell
module top_shell() {
    difference() {
        union() {
            difference() { intersection() { outer(); slab(split, H + 1); } inner(split - 0.01, z_top_in); }
            for (x = key_x, y = key_y) translate([x, y, z_sleeve])                          // key guides
                linear_extrude(z_top_in - z_sleeve + 0.01) rsq(key_hole + 2 * sleeve_wall, key_hole + 2 * sleeve_wall, key_r + fit + sleeve_wall);
            translate([0, 0, z_top_in - 1.5]) rrect(window[0] - 3, window[1] - 3, window[2] + 3, window[3] + 3, 2, 1.51);   // acrylic frame
            for (h = holes) translate([h[0], h[1], z_pcb_top]) cylinder(d = 4.6, h = z_top_in - z_pcb_top + 0.01);           // screw bosses
            // The cover's recess leaves a 0.9 mm wall: a 1 mm plate behind it on the inside, which also backs the pegs.
            translate([in1[0] - 1.0, sl_open - 1, sl_z[0] - 0.4]) cube([1.01, sl_closed - sl_open + sl_len + 2, z_top_in - sl_z[0] + 0.41]);
        }
        // Key columns clear all the way down to the board: the corner screw bosses give up a sliver next to BACK and OK.
        for (x = key_x, y = key_y) translate([x, y, split - 0.01]) linear_extrude(H) rsq(key_hole, key_hole, key_r + fit);
        translate([0, 0, z_top_in - 5]) rrect(window[0], window[1], window[2], window[3], 1.2, 10);                          // window
        translate([0, 0, H - 0.8]) hull() {                                                 // 0.8 mm chamfer round the window
            rrect(window[0], window[1], window[2], window[3], 1.2, 0.01);
            translate([0, 0, 0.8]) rrect(window[0] - 0.8, window[1] - 0.8, window[2] + 0.8, window[3] + 0.8, 2, 0.02);
        }
        translate([0, 0, z_top_in - 1.5 - 0.01]) rrect(window[0] - 1.5, window[1] - 1.5, window[2] + 1.5, window[3] + 1.5, 1.5, 1.5);   // acrylic
        for (h = holes) translate([h[0], h[1], z_pcb_top - 1]) cylinder(d = 1.7, h = 10);                                    // M2 pilot
        translate([in1[0] - 1.1, module_y, usb_z]) rotate([90, 0, 90]) linear_extrude(wall + 2.2) rsq(port[0], port[1], 1.5);   // USB-C port
        // Cover: recess 0.9 deep, slot for its pegs below the port, dimples for its detent at both ends of the travel.
        translate([out1[0] - sl_t - 0.1, sl_open - 0.2, sl_z[0] - 0.2]) cube([sl_t + 1, sl_closed - sl_open + sl_len + 0.4, sl_z[1] - sl_z[0] + 0.4]);
        translate([in1[0] - 1.1, sl_open + pegs[0] - 0.7, peg_z - 0.7]) cube([wall + 1, sl_closed - sl_open + pegs[1] - pegs[0] + 1.4, 1.4]);
        for (y = [sl_closed, sl_open]) translate([out1[0] - sl_t - 0.1, y + sl_len / 2, sl_z[1] - 1.1]) sphere(r = 0.4, $fn = 16);
        translate([out0[0] - 1, sw13_y - 4, split - 0.01]) cube([wall + 2, 8, 3]);          // power switch slot
        translate([out0[0] - 1, sw13_y - 6, split - 0.01]) cube([1 + 1.2, 12, 5]);          // finger recess around it
        translate([38.6, 48.4, H - 0.6]) label("STUDENT", 3.4);
        translate([38.6, 43.6, H - 0.6]) label("TAG", 3.4);
        translate([0, 0, split - 0.01])                                                    // rebate for the bottom shell's lip
            rrect(in0[0] - wall / 2, in0[1] - wall / 2, in1[0] + wall / 2, in1[1] + wall / 2, R - wall / 2, 1.5);
    }
}

// ---------------------------------------------------------------- bottom shell
module door_opening() {                                 // stepped: outer flange + smaller inner half, clearance clr
    translate([door_x[0] - clr, door_y[0] - clr, -1]) cube([door_x[1] - door_x[0] + 2 * clr, door_y[1] - door_y[0] + 2 * clr, 1 + floor_t / 2]);
    translate([door_x[0] + step - clr, door_y[0] + step - clr, floor_t / 2 - 0.01])
        cube([door_x[1] - door_x[0] - 2 * step + 2 * clr, door_y[1] - door_y[0] - 2 * step + 2 * clr, floor_t / 2 + 0.02]);
}

module bottom_shell() {
    difference() {
        union() {
            difference() { intersection() { outer(); slab(-1, split); } inner(floor_t, split + 1); }
            translate([0, 0, split - 0.01]) difference() {                                  // alignment lip into the top shell
                rrect(in0[0] - wall / 2 + fit / 2, in0[1] - wall / 2 + fit / 2, in1[0] + wall / 2 - fit / 2, in1[1] + wall / 2 - fit / 2, R - wall / 2, lip_h + 0.01);
                translate([0, 0, -1]) inner(0, 4);
            }
            for (h = holes) translate([h[0], h[1], 0]) cylinder(d = 5, h = z_pcb);         // board standoffs
            intersection() {                                                                // bay walls, roof and support under the top key row
                inner(0, z_pcb);
                translate([in0[0], bay_y0 - 3, 0]) cube([in1[0] - in0[0], support_y1 - bay_y0 + 3, z_pcb]);   // also under the BACK row's legs
            }
            translate([rib2[0], rib2[1], 0]) cube([rib2[2] - rib2[0], rib2[3] - rib2[1], z_pcb]);
            translate([6.8, 45, floor_t - 0.01]) difference() { cylinder(d = motor_d + 2.3, h = 2); translate([0, 0, -1]) cylinder(d = motor_d + 0.3, h = 4); }   // motor seat
        }
        translate([cx0, bay_y0, floor_t - 0.01]) cube([cx1 - cx0, bay_y1 - bay_y0, roof_z - floor_t + 0.01]);   // the bay
        door_opening();
        for (a = arms) translate([a[0] - 0.3, a[2] < 0 ? bay_y0 - hook_p - 0.1 : bay_y1 - 0.01, catch_z - 0.6])   // snap pockets
            cube([a[1] - a[0] + 0.6, hook_p + 0.11, 1.2]);
        for (y = [door_y[0] - clr - 0.3, door_y[1] + clr + 0.3]) translate([25, y, 0]) scale([4, 2.2, 1]) sphere(1, $fn = 32);   // fingernail notches
        // Contact pockets on the bay's end faces; the left ones have a slit for the solder tab, wires run up beside
        // the board edge to J4. Right: one bridge contact joins the cells in series.
        for (y = cells_y) {
            translate([cx0 - contact_t - 0.1, y - plate_w / 2 - 0.15, floor_t]) cube([contact_t + 0.11, plate_w + 0.3, roof_z - floor_t]);
            translate([cx0 - contact_t - 0.1, y - 1.6, roof_z - 0.01]) cube([contact_t + 0.11, 3.2, z_pcb - roof_z + 0.02]);
        }
        translate([cx1 - 0.01, cells_y[0] - plate_w / 2 - 0.15, floor_t])
            cube([contact_t + 0.11, cells_y[1] - cells_y[0] + plate_w + 0.3, roof_z - floor_t]);
        translate([out0[0] - 1, sw13_y - 4, split - 0.01]) cube([wall + 2, 8, 3]);          // lip cut at the power switch
        for (h = holes) translate([h[0], h[1], -1]) {
            cylinder(d = 2.3, h = z_pcb + 2);                                               // M2 clearance
            cylinder(d = 4.2, h = 1 + 2.2);                                                 // screw head recess
        }
        translate([0, 0, -0.01]) rrect(12, 46, 38, 60, 2, 0.41);                            // label area for the device number
        // The support block under the top key row is hollow: a 1.2 mm top plate on two walls.
        translate([in0[0] + 1.2, bay_y1 + 1.2, 2.4]) cube([in1[0] - in0[0] - 2.4, support_y1 - bay_y1 - 2.4, z_pcb - 1.2 - 2.4]);
    }
}

// ---------------------------------------------------------------- battery door
// Pushed on, each hook rides down the bay's end face (the arm bends hook_p, about 1.4 % strain for PLA: the arm
// starts at the flange, inside a moat in the door's inner half, so it bends over more than the 5 mm it stands out)
// and clicks into its pocket; pulled off, the sloped underside of the hook bends it back out. bend = 1 draws the
// arms bent, as they are while going on or off.
function arm_y(a) = a[2] < 0 ? door_y[0] + step : bay_y1 - 0.2 - arm_t;   // the arm's Y start, 0.2 mm off the end face
module snap_arm(a, bend = 0) {
    y0 = arm_y(a);
    h = floor_t + arm_h - floor_t / 2;                                 // from the flange up to the arm's top
    tilt = a[2] * bend * atan((hook_p + 0.05) / h);                    // bend towards the bay, away from the pocket
    translate([0, y0 + arm_t / 2, floor_t / 2]) rotate([tilt, 0, 0]) translate([0, -y0 - arm_t / 2, 0]) {
        translate([a[0], y0, -0.01]) cube([arm_w, arm_t, h + 0.01]);
        translate([a[0], a[2] < 0 ? y0 : y0 + arm_t, 0]) rotate([90, 0, 90]) linear_extrude(arm_w)   // hook: 45 deg lead-in, 35 deg release
            polygon([[0, catch_z - floor_t / 2 - hook_p / tan(35)], [a[2] * hook_p, catch_z - floor_t / 2],
                     [a[2] * hook_p, catch_z - floor_t / 2 + 0.15], [0, h]]);
    }
}
module door(bend = 0) {
    difference() {
        union() {
            translate([door_x[0], door_y[0], 0]) cube([door_x[1] - door_x[0], door_y[1] - door_y[0], floor_t / 2]);
            difference() {
                translate([door_x[0] + step, door_y[0] + step, floor_t / 2 - 0.01]) cube([door_x[1] - door_x[0] - 2 * step, door_y[1] - door_y[0] - 2 * step, floor_t / 2 + 0.01]);
                for (a = arms) translate([a[0] - moat, arm_y(a) - moat, floor_t / 2]) cube([arm_w + 2 * moat, arm_t + 2 * moat, floor_t]);   // moats
            }
            for (a = arms) snap_arm(a, bend);
        }
        for (y = [door_y[0], door_y[1]]) translate([25, y, -0.01]) scale([3, 1.2, 0.6]) sphere(1, $fn = 24);   // fingernail catches
        for (i = [0 : 1], e = [0 : 1])                                                     // polarity, seen with the door off
            translate([e == 0 ? 4 : BW - 4, cells_y[i], floor_t - 0.4]) label((i + e) % 2 == 0 ? "+" : "−", 4, 0.4);
    }
}

// ---------------------------------------------------------------- USB-C cover
module cover(y0) {
    translate([out1[0] - sl_t, y0, sl_z[0]]) {
        difference() {
            cube([sl_t, sl_len, sl_z[1] - sl_z[0]]);
            for (i = [-3 : 3]) translate([sl_t - 0.3, sl_len / 2 + i * 1.2 - 0.25, usb_z - sl_z[0] - 2.5]) cube([1, 0.5, 5]);   // grip
        }
        translate([0, sl_len / 2, sl_z[1] - sl_z[0] - 1.1]) sphere(r = 0.35, $fn = 16);        // detent bump
        for (p = pegs) translate([0, p, peg_z - sl_z[0]]) difference() {                     // snap pegs through the slot
            union() {
                rotate([0, -90, 0]) cylinder(d = 1.2, h = wall - sl_t + 1.0 + 0.01, $fn = 20);
                translate([-(wall - sl_t + 1.0), 0, 0]) rotate([0, -90, 0]) cylinder(d1 = 2.1, d2 = 1.2, h = 0.7, $fn = 20);
            }
            translate([-(wall - sl_t + 1.0) - 1, -1.5, -0.225]) cube([2.3, 3, 0.45]);          // slit: the head squeezes through
        }
    }
}

// ---------------------------------------------------------------- key caps
module key_cap(t, sub = "") {
    body    = H + key_out - z_cap0;
    plunger = z_cap0 - (z_pcb_top + sw_h);
    legs    = z_cap0 - (z_pcb_top + leg_gap);
    difference() {
        union() {
            // Retaining tabs left and right only: a square flange on BACK and OK would hit the corner screw bosses.
            translate([-tab_len / 2, -tab_w / 2, 0]) cube([tab_len, tab_w, tab_t]);
            hull() {
                linear_extrude(body - 0.5) rsq(key_cap, key_cap, key_r);
                linear_extrude(body) rsq(key_cap - 1, key_cap - 1, key_r - 0.5);
            }
            translate([0, 0, -plunger]) cylinder(d = plunger_d, h = plunger + 0.01);
            // Over-travel stop: the legs land on the board (supported right below) just after the switch clicks.
            for (s = [-1, 1]) translate([-leg_w / 2, s > 0 ? leg_y : -leg_y - leg_t, -legs]) cube([leg_w, leg_t, legs + 0.01]);
        }
        // Hollow (1 mm walls, 1.4 mm face): less plastic; the cavity is closed, its roof a 6 mm bridge when printed face down.
        translate([0, 0, tab_t]) linear_extrude(body - tab_t - 1.4) rsq(key_cap - 2, key_cap - 2, key_r - 0.5);
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

// ---------------------------------------------------------------- output
module door_in_place() { door(); }

if (part == "dims") echo(z_pcb = z_pcb, z_pcb_top = z_pcb_top, H = H, key_x = key_x, key_y = key_y, holes = holes,
                         window = window, module_y = module_y, sw13_y = sw13_y, socket_h = socket_h, module_t = module_t,
                         usb_h = usb_h, size = [out1[0] - out0[0], out1[1] - out0[1], H], port = [module_y, usb_z, port[0], port[1]],
                         cover = [sl_closed, sl_open, sl_len, sl_z[0], sl_z[1]], leg_gap = leg_gap, cells_y = cells_y,
                         cx = [cx0, cx1], cell_z = cell_z, bay = [cx0, bay_y0, cx1, bay_y1], door_y = door_y,
                         arm = [arm_h, arm_t, hook_p, catch_z]);
else if (part == "top") top_shell();
else if (part == "top_print") translate([0, 0, H]) rotate([180, 0, 0]) top_shell();   // face down for printing
else if (part == "bottom") bottom_shell();
else if (part == "door") door();
else if (part == "cover") rotate([0, 90, 0]) translate([-out1[0], -sl_closed, -sl_z[0]]) cover(sl_closed);   // for printing: outer face down
else if (part == "cover_closed") cover(sl_closed);
else if (part == "cover_open") cover(sl_open);
else if (part == "keys") for (i = key_set) let(r = floor(i / 4), c = i % 4)                      // face down for printing
    translate([c * 13, r * 13, H + key_out - z_cap0]) rotate([180, 0, 0]) key_cap(key_label(r, c), key_sub(r, c));
else if (part == "keys_placed") keys_in_place();
else if (len(part) > 3 && part[0] == "k" && part[1] == "e" && part[2] == "y") key_in_place(part == "key10" ? 10 : part == "key11" ? 11 : ord(part[3]) - 48);
else {
    color("Teal", 0.9) bottom_shell();
    color("Teal") door_in_place();
    color("WhiteSmoke", 0.4) top_shell();
    color("DimGray") cover(sl_closed);
    color("DarkSlateGray") keys_in_place();
}
