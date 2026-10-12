// Student Tag case: top shell, bottom shell with a 2xAAA bay under the board, sliding battery door, 12 key caps.
//
//   openscad -D 'part="top"'    -o top.stl    student_tag_case.scad   (also: bottom, door, keys, assembly)
//   python3 tests/case_test.py  exports every part and checks it against the real board (KiCad 3D export).
//
// Frame: X right, Y up, origin at the board's lower-left corner (the key end); the board covers X 0..50, Y 0..80
// and the module end is at Y = 80. KiCad (x, y) maps to (x - 120.5, 138 - y). All numbers in mm.
// Print: PLA or PETG, 0.2 mm layers, no supports. Top shell face down, bottom shell floor down, door flat,
// key caps face down.
// Parts to buy: 4 x M2x25 screw (self-tapping into the top shell), AAA contacts: 1 plus plate, 1 minus spring,
// 1 series bridge (spring + plate), optional 1.5 mm red/smoke acrylic for the display window.

part = "assembly";   // top | bottom | door | keys | assembly

// ---------------------------------------------------------------- measure these on the real parts
pcb_t        = 1.6;
sw_h         = 1.5;    // ALPS SKQG height above the board (KiCad model says 1.5 for the stemmed part)
socket_h     = 8.5;    // female header under the module
module_t     = 1.2;    // SuperMini board thickness
usb_w        = 9.0;    // USB-C receptacle on the module
usb_h        = 3.3;
pin_below    = 3.8;    // longest through-hole pin under the board (display); trim longer pins
aaa_d        = 10.5;
aaa_l        = 44.5;
contact_len  = 5.5;    // board length the contacts take in total: spring (compressed) + plate
contact_t    = 0.6;    // contact plate thickness
motor_d      = 8.2;    // 0834 coin motor
motor_h      = 3.4;

// ---------------------------------------------------------------- print fit
wall = 2.0; floor_t = 1.5; top_t = 2.0;
clr  = 0.4;            // board to inner wall
fit  = 0.3;            // sliding / removable parts
$fn  = 48;

// ---------------------------------------------------------------- stack (Z)
bay_h      = aaa_d + 1.0;
roof_t     = 1.2;
z_roof     = floor_t + bay_h + roof_t;                 // top of the battery bay roof
z_pcb      = z_roof + pin_below + 0.8;                 // board bottom = standoff top
z_pcb_top  = z_pcb + pcb_t;
z_top_in   = z_pcb_top + socket_h + module_t + usb_h + 1.2;
H          = z_top_in + top_t;                         // overall height
split      = z_pcb_top;                                // bottom shell ends at the board's top face

// ---------------------------------------------------------------- board features (from StudentTag.kicad_pcb)
BW = 50; BL = 80;
holes    = [[2.6, 77.4], [47.4, 77.4], [2.8, 3.0], [47.2, 3.0]];     // M2
key_x    = [6.38, 18.38, 30.38, 42.38];
key_y    = [33, 21, 9];
labels   = [["1", "2", "3", "4"], ["5", "6", "7", "8"], ["BACK", "9", "0", "OK"]];
window   = [10.0, 39.8, 26.7, 52.7];                  // both HDSP-A151 bodies: x0, y0, x1, y1
module_y = 65.6;                                        // centre line of the module (and its USB-C port)
sw13_y   = 67.5;                                        // power switch lever, on the left edge
j4       = [8.85, 73.1];                                // battery wires land here
j3       = [2.4, 46.0];                                 // motor wires

// ---------------------------------------------------------------- derived sizes
in0 = [-clr, -clr]; in1 = [BW + clr, BL + clr];
r_in = 2.4; r_out = r_in + wall;
key_hole = 9.0; key_cap = key_hole - 2 * fit; sleeve = 6; sleeve_wall = 1.2;
flange = 10.4; tab_w = 5; flange_t = 1.2; key_out = 1.5; plunger_d = 3.0;
bay_w  = 2 * aaa_d + 1.5;
bay_l  = aaa_l + contact_len;
bay_x0 = BW / 2 - bay_w / 2;
bay_y0 = 6;
bay_wall = 1.6; end_wall = 2.4;
door_top_w = bay_w + 3; door_bot_w = bay_w + 1;       // dovetail: wider on top so the door cannot drop out

module rrect(x0, y0, x1, y1, r, h) {                    // rounded rectangle, extruded
    linear_extrude(h) offset(r) offset(-r) translate([x0, y0]) square([x1 - x0, y1 - y0]);
}
module outer(h) { rrect(in0[0] - wall, in0[1] - wall, in1[0] + wall, in1[1] + wall, r_out, h); }
module inner(h) { rrect(in0[0], in0[1], in1[0], in1[1], r_in, h); }
module label(t, size, depth = 0.6) {
    linear_extrude(depth + 0.01) text(t, size = size, font = "Liberation Sans:style=Bold", halign = "center", valign = "center");
}

// ---------------------------------------------------------------- top shell
module top_shell() {
    difference() {
        union() {
            difference() { translate([0, 0, split]) outer(H - split); translate([0, 0, split - 0.01]) inner(z_top_in - split + 0.01); }
            for (x = key_x, y = key_y)                                        // key guides
                translate([x - key_hole / 2 - sleeve_wall, y - key_hole / 2 - sleeve_wall, z_top_in - sleeve])
                    cube([key_hole + 2 * sleeve_wall, key_hole + 2 * sleeve_wall, sleeve]);
            translate([window[0] - 3, window[1] - 3, z_top_in - 1.5])        // frame that holds the acrylic
                cube([window[2] - window[0] + 6, window[3] - window[1] + 6, 1.5]);
            for (h = holes) translate([h[0], h[1], z_pcb_top]) cylinder(d = 4.6, h = z_top_in - z_pcb_top);   // screw bosses (J2 is 2.4 mm away)
        }
        // Key columns clear all the way down to the board: the corner screw bosses give up a sliver next to BACK and OK.
        for (x = key_x, y = key_y) translate([x - key_hole / 2, y - key_hole / 2, split - 0.01]) cube([key_hole, key_hole, H]);
        translate([window[0], window[1], z_top_in - 5]) cube([window[2] - window[0], window[3] - window[1], 10]);
        translate([window[0] - 1.5, window[1] - 1.5, z_top_in - 1.5 - 0.01])   // acrylic pocket (1.5 mm sheet)
            cube([window[2] - window[0] + 3, window[3] - window[1] + 3, 1.5]);
        for (h = holes) translate([h[0], h[1], z_pcb_top - 1]) cylinder(d = 1.7, h = z_top_in - z_pcb_top);   // M2 pilot
        translate([in1[0] - 1, module_y - 13 / 2, z_pcb_top + socket_h + module_t + usb_h / 2 - 7.5 / 2])   // USB-C
            cube([wall + 2, 13, 7.5]);
        translate([in0[0] - wall - 1, sw13_y - 4, split - 0.01]) cube([wall + 2, 8, 3]);   // power switch slot
        translate([in0[0] - wall - 1, sw13_y - 6, split - 0.01]) cube([1 + 1.2, 12, 5]);   // finger recess around it
        translate([BW / 2, 66, H - 0.6]) label("STUDENT TAG", 4.5);
        translate([0, 0, split - 0.01])                                        // rebate for the bottom shell's lip
            rrect(in0[0] - wall / 2, in0[1] - wall / 2, in1[0] + wall / 2, in1[1] + wall / 2, r_in + wall / 2, 1.5);
    }
}

// ---------------------------------------------------------------- bottom shell
module door_profile(extra = 0) {                        // cross-section of the sliding door, X centred on the bay
    polygon([[-door_bot_w / 2 - extra, -0.01], [door_bot_w / 2 + extra, -0.01], [door_top_w / 2 + extra, floor_t + extra], [-door_top_w / 2 - extra, floor_t + extra]]);
}

cells   = [bay_x0 + bay_w / 4, bay_x0 + 3 * bay_w / 4];   // cell centre lines
plate_w = 10;                                             // AAA contact plate width

module contact_slot(cx, w, y_face, dir) {                 // dir +1: wall beyond y_face, -1: wall before it
    y_slot = dir > 0 ? y_face + 1.0 : y_face - 1.0 - (contact_t + 0.4);
    translate([cx - w / 2 - 0.1, y_slot, -1]) cube([w + 0.2, contact_t + 0.4, 1 + floor_t + bay_h]);
    translate([cx - w / 2 + 1, dir > 0 ? y_face - 0.01 : y_face - 1.0, -1]) cube([w - 2, 1.01, 1 + floor_t + bay_h]);
}

module bottom_shell() {
    difference() {
        union() {
            difference() { outer(split); translate([0, 0, floor_t]) inner(split); }
            translate([0, 0, split - 0.01]) difference() {                   // alignment lip into the top shell (overlaps the wall: one body)
                rrect(in0[0] - wall / 2 + fit / 2, in0[1] - wall / 2 + fit / 2, in1[0] + wall / 2 - fit / 2, in1[1] + wall / 2 - fit / 2, r_in + wall / 2, 1.5 - fit + 0.01);
                translate([0, 0, -1]) inner(4);
            }
            for (h = holes) translate([h[0], h[1], 0]) cylinder(d = 5, h = z_pcb);   // board standoffs
            translate([bay_x0 - bay_wall, bay_y0 - end_wall, 0])               // battery bay walls and roof
                cube([bay_w + 2 * bay_wall, bay_l + 2 * end_wall, z_roof]);
            translate([6.8, 45, floor_t]) difference() { cylinder(d = motor_d + 0.3 + 2, h = 2); translate([0, 0, -1]) cylinder(d = motor_d + 0.3, h = 4); }   // motor seat
        }
        translate([bay_x0, bay_y0, -1]) cube([bay_w, bay_l, 1 + floor_t + bay_h]);         // the bay
        translate([BW / 2, bay_y0 + bay_l, 0]) rotate([90, 0, 0])                          // door channel, open at the key end
            linear_extrude(bay_l + bay_y0 + clr + wall + 1) door_profile(fit / 2);
        // AAA contacts, pushed in from below before the door goes on. Terminal end (towards the module): the left
        // cell's plus plate and the right cell's minus spring, wires up through the roof to J4. Key end: the series
        // bridge. Each plate sits in a slot 1 mm inside the end wall; a window narrower than the plate exposes it.
        for (cx = cells) {
            contact_slot(cx, plate_w, bay_y0 + bay_l, 1);
            translate([cx, bay_y0 + bay_l + 1.0 + (contact_t + 0.4) / 2, floor_t + bay_h - 2]) cylinder(d = 2, h = 10);   // wire
        }
        contact_slot(BW / 2, bay_w - 1, bay_y0, -1);                                                   // series bridge
        for (s = [-1, 1]) translate([BW / 2 + s * door_top_w / 2, -clr - wall + 3, floor_t / 2]) sphere(d = 1.2);   // door detents
        translate([in0[0] - wall - 1, sw13_y - 4, split - 0.01]) cube([wall + 2, 8, 3]);            // lip cut at the power switch
        for (h = holes) translate([h[0], h[1], -1]) {
            cylinder(d = 2.3, h = z_pcb + 2);                                                 // M2 clearance
            cylinder(d = 4.2, h = 1 + 2.2);                                                  // screw head recess
        }
        translate([BW / 2 - bay_w / 4, bay_y0 + bay_l + end_wall + 4, -0.01]) mirror([1, 0, 0]) label("+", 5);   // polarity
        translate([BW / 2 + bay_w / 4, bay_y0 + bay_l + end_wall + 4, -0.01]) mirror([1, 0, 0]) label("-", 5);
    }
}

// ---------------------------------------------------------------- battery door (slides out towards the key end)
module door() {
    len = bay_l + bay_y0 + clr + wall;
    difference() {
        union() {
            rotate([90, 0, 0]) translate([0, 0, -len]) linear_extrude(len) door_profile();
            for (s = [-1, 1]) translate([s * (door_top_w / 2), 3, floor_t / 2]) sphere(d = 0.9);   // snap bumps
        }
        for (i = [0 : 3]) translate([-door_bot_w / 2 + 3, 1 + i * 1.6, -0.5]) cube([door_bot_w - 6, 0.6, 0.9]);   // finger grip
    }
}

// ---------------------------------------------------------------- key caps
module key_cap(t) {
    body = H - (z_top_in - sleeve) + key_out;
    plunger = (z_top_in - sleeve - flange_t) - (z_pcb_top + sw_h) - 0.2;
    difference() {
        union() {
            // Retaining tabs left and right only: a square flange on BACK and OK would hit the corner screw bosses.
            translate([-flange / 2, -tab_w / 2, 0]) cube([flange, tab_w, flange_t]);
            translate([-key_cap / 2, -key_cap / 2, 0]) cube([key_cap, key_cap, flange_t + body]);
            translate([0, 0, -plunger]) cylinder(d = plunger_d, h = plunger + 0.01);
        }
        translate([0, 0, flange_t + body - 0.6]) label(t, len(t) > 2 ? 2.2 : 4.5);
    }
}

// ---------------------------------------------------------------- output
module door_in_place() { translate([BW / 2, -clr - wall, 0]) door(); }   // closed

module keys_in_place() {                                 // caps resting on their switches
    for (r = [0 : 2], c = [0 : 3]) translate([key_x[c], key_y[r], z_top_in - sleeve - flange_t - 0.2]) key_cap(labels[r][c]);
}

if (part == "dims") echo(z_pcb = z_pcb, z_pcb_top = z_pcb_top, H = H, key_x = key_x, key_y = key_y, holes = holes,
                         window = window, module_y = module_y, sw13_y = sw13_y, socket_h = socket_h, module_t = module_t,
                         usb_h = usb_h, size = [in1[0] - in0[0] + 2 * wall, in1[1] - in0[1] + 2 * wall, H]);
else if (part == "top") top_shell();
else if (part == "door_placed") door_in_place();          // viewer / checks: the door closed
else if (part == "keys_placed") keys_in_place();          // viewer / checks: caps resting on their switches
else if (part == "bottom") bottom_shell();
else if (part == "door") door();
else if (part == "keys") for (r = [0 : 2], c = [0 : 3]) translate([c * 13, r * 13, 0]) rotate([180, 0, 0]) key_cap(labels[r][c]);
else {
    color("SteelBlue", 0.9) bottom_shell();
    color("Gold") door_in_place();
    color("Silver", 0.35) top_shell();
    color("White") keys_in_place();
    color("DarkGreen") translate([0, 0, z_pcb]) cube([BW, BL, pcb_t]);
}
