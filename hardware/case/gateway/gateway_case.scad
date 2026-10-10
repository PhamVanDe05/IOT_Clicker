// BLE gateway case: one nRF52840 SuperMini (headers soldered, pins down) in a pebble-shaped USB stick. The USB-C end
// takes a straight C male-male adapter (or a short cable); a light dot over the status LED, a pin hole for reset,
// a lanyard hole through the rounded tail. Two halves snap together with four tongues on the long sides, the same
// tongues as the Student Tag V3 case; no screws.
//
//   openscad -D 'part="top"' -o top.stl gateway_case.scad      (also: bottom, top_print, assembly, dims)
//   /usr/bin/python3 tests/gateway_case_test.py                  checks every part against the module
//
// Frame: X along the stick (0 = module's USB-C edge, tail at +X), Y across (0 = centre), Z up from the bottom. mm.
// Print: PLA/PETG, 0.2 mm layers, no supports. Top half face down, bottom half floor down.

part = "assembly";   // top | top_print | bottom | assembly | dims

// ---------------------------------------------------------------- measure these on the real module
mod_l      = 33.4;   // SuperMini board
mod_w      = 18.0;
mod_t      = 1.0;
usb_w      = 8.94;   // USB-C receptacle on top of the module, at X = 0
usb_h      = 3.2;
usb_over   = 0.6;    // receptacle sticks out past the board edge
usb_len    = 7.4;
pins_below = 8.5;    // header plastic (2.5) + pin ends under the board; clip the pins to make the stick thinner
hdr_w      = 2.6;    // header plastic width, rows at Y = +-7.62
row_y      = 7.62;
pins_above = 1.5;    // pin tips above the board after soldering
led_at     = [5.5, -6.6];   // status LED on the module (X, Y): check on the real module
reset_at   = [10.0, 5.6];   // reset button / pad: check on the real module

// ---------------------------------------------------------------- shape
wall = 1.8; floor_t = 1.6; top_t = 1.6;            // under 2 mm, 4 lines of a 0.4 nozzle
gap = 0.4;                                       // board to the end walls
side_gap = 2.3;                                  // board to the side walls: room for the tongues and their bend
tail = 9.5;                                      // solid rounded tail with the lanyard hole
r_edge = 1.6;                                    // top and bottom edge fillets
port = [11.8, 6.0];                              // opening that takes a cable's or adapter's moulding, so the plug goes fully in
txt_d = 1.0;                                     // engraving depth of the name and logo (0.6 mm skin left)
$fn = 64;

// ---------------------------------------------------------------- stack
z_pcb     = floor_t + pins_below + 0.3;
z_pcb_top = z_pcb + mod_t;
usb_z     = z_pcb_top + usb_h / 2;
z_in      = z_pcb_top + max(usb_h, pins_above) + 0.4;
H         = max(z_in + top_t, usb_z + port[1] / 2 + r_edge);   // the port stays below the top fillet
split     = z_pcb_top;                           // board sits in the bottom half, the top half presses it down

in0 = [-usb_over - gap, -mod_w / 2 - side_gap];
in1 = [mod_l + gap, mod_w / 2 + side_gap];
W   = in1[1] - in0[1] + 2 * wall;                // outer width
x0  = in0[0] - wall;                             // outer USB end
x1  = in1[0] + tail;                             // outer tail end
R_usb = 3.0;                                     // corner radius at the USB end
R_tail = 9.0;                                    // corner radius at the tail (clears the cavity's corners)

// ---------------------------------------------------------------- snap tongues
// As on the tag: 6 mm long, 10 mm wide, tapered 1.6 mm at the root to 1.0 at the hook, a wedge and edge fins at the root
// (strain at the root 1.9 %). Two on each long side; each bends towards the cavity as the halves go together, its hook
// catches in a pocket in the side wall of the bottom half.
tongue_l = 6.0; tongue_t = 1.6; tongue_tip = 1.0; tongue_p = 0.4; hook_h = 1.2; root_h = 2.0; fin = 1.5;
tongues  = [for (s = [-1, 1], x = [10, 23.4]) [s, x, 10, 0.15]];   // [side -1 | 1 (Y), X centre, width, inset past the wall face]
guide_x  = [[0.2, 3.0], [mod_l - 3.0, mod_l - 0.2]];   // posts beside the board's ends that keep it centred across
rib = 1.2;                                       // rib thickness; fillet ribs along the side walls are 2 mm on each leg
top_rib_x = [1.0, 16.7, 31.0];                   // ribs inside the top half's side walls, clear of the tongue roots

// ---------------------------------------------------------------- outline
module plan(o = 0) {                             // pebble: rounded USB end, rounder tail; o grows it
    hull() for (s = [-1, 1]) {
        translate([x0 + R_usb, s * (W / 2 - R_usb)]) circle(R_usb + o);
        translate([x1 - R_tail, s * (W / 2 - R_tail)]) circle(R_tail + o);
    }
}
module body(z0, z1, rb, rt) {                    // plan extruded with filleted bottom / top edges
    hull() {
        translate([0, 0, z0 + rb]) linear_extrude(z1 - z0 - rb - rt) plan();
        if (rb > 0) for (k = [0 : 4]) let(a = 90 * k / 4)
            translate([0, 0, z0 + rb - rb * cos(a)]) linear_extrude(0.01) plan(-rb * (1 - sin(a)));
        if (rt > 0) for (k = [0 : 4]) let(a = 90 * k / 4)
            translate([0, 0, z1 - rt + rt * cos(a) - 0.01]) linear_extrude(0.01) plan(-rt * (1 - sin(a)));
    }
}
module cavity(z0, z1) { translate([in0[0], in0[1], z0]) cube([in1[0] - in0[0], in1[1] - in0[1], z1 - z0]); }
module at_tongue(t) {                            // child drawn for a wall with its inner face at X = 0, outside -X, centred on Y = 0
    if (t[0] < 0) translate([t[1], in0[1], 0]) rotate([0, 0, 90]) children();
    else translate([t[1], in1[1], 0]) rotate([0, 0, -90]) children();
}
// Tongue of width w, its outer face `inset` past the wall face, tapered; hook with a 30 deg lead-in at the tip, flat
// catch above; a wedge above the split ties a root that stands in the cavity to the wall; fins at both edges.
module tongue(w, inset) {
    translate([0, w / 2, 0]) rotate([90, 0, 0]) linear_extrude(w)
        polygon([[-inset, split - tongue_l], [-inset + tongue_tip, split - tongue_l], [-inset + tongue_t, split + 0.01], [-inset, split + 0.01]]);
    for (s = [-1, 1]) translate([-inset, s * w / 2, split]) rotate([90, 0, 90])
        linear_extrude(tongue_t - 0.2) polygon([[0, 0.01], [s * fin, 0.01], [0, -fin]]);
    translate([-inset, w / 2, split - tongue_l]) rotate([90, 0, 0]) linear_extrude(w)
        polygon([[0.01, 0], [-tongue_p, tongue_p / tan(30)], [-tongue_p, hook_h], [0.01, hook_h]]);
    if (tongue_t > inset) translate([0, w / 2, split]) rotate([90, 0, 0]) linear_extrude(w)
        polygon([[-0.01, -0.01], [tongue_t - inset, -0.01], [-0.01, root_h]]);
}
module tongue_room(w, inset) {                   // one box (tongue, fins, room to bend in, open to the cavity) and the hook pocket
    translate([-inset - 0.15, -w / 2 - fin - 0.3, split - tongue_l - 0.3]) cube([0.15 + tongue_t + tongue_p + 0.15, w + 2 * fin + 0.6, tongue_l + 0.31]);
    translate([-inset - tongue_p - 0.1, -w / 2 - 0.3, split - tongue_l - 0.3]) cube([inset + tongue_p + 0.11, w + 0.6, hook_h + 0.5]);   // 0.2 play over the hook
}
module port_cut() {
    translate([x0 - 1, 0, usb_z]) rotate([0, 90, 0]) linear_extrude(1 - usb_over + 1 - x0)          // to 1 mm past the receptacle face
        offset(1.6) offset(-1.6) square([port[1], port[0]], center = true);
}
module logo(depth) {                             // signal waves round a dot, engraved near the tail; strokes 0.9 mm
    translate([x1 - 11.8, 0, H - depth]) linear_extrude(depth + 0.01) {
        circle(1.0);
        for (r = [2.6, 4.4, 6.2]) intersection() {                         // arcs open towards the USB end
            difference() { circle(r + 0.45); circle(r - 0.45); }
            polygon([[0, 0], [-20, -20], [-20, 20]]);
        }
    }
}

// ---------------------------------------------------------------- bottom half
lanyard = [x1 - 4.2, 0];
module bottom_half() {
    difference() {
        union() {
            difference() { body(0, split, r_edge, 0); cavity(floor_t, split + 1); }
            // ledges under both board ends (the pins start 1.46 mm in), a stop for the board's USB edge
            translate([0, -mod_w / 2, floor_t - 0.01]) cube([1.0, mod_w, z_pcb - floor_t + 0.01]);
            translate([mod_l - 1.0, -mod_w / 2, floor_t - 0.01]) cube([1.0, mod_w, z_pcb - floor_t + 0.01]);
            translate([in0[0] - 0.01, -mod_w / 2, floor_t - 0.01]) cube([-in0[0] - 0.05 + 0.01, mod_w, z_pcb - floor_t + 0.01 + mod_t]);
            for (g = guide_x, s = [-1, 1]) translate([g[0], s > 0 ? mod_w / 2 + 0.15 : in0[1] - 0.01, floor_t - 0.01])   // side guides
                cube([g[1] - g[0], side_gap - 0.15 + 0.01, split - floor_t + 0.01]);
            // ribs: one along the floor between the header rows, a fillet along each side wall below the tongues
            translate([1.5, -rib / 2, floor_t - 0.01]) cube([mod_l - 3, rib, 3.0]);
            for (s = [-1, 1]) translate([0.5, s * in1[1], floor_t - 0.01]) rotate([90, 0, 90]) linear_extrude(mod_l - 1)
                polygon([[0, 0], [-s * 2.0, 0], [0, 2.0]]);
        }
        for (t = tongues) at_tongue(t) tongue_room(t[2], t[3]);                               // tongue slots and hook pockets
        port_cut();
        translate([lanyard[0], lanyard[1], -1]) cylinder(d = 3.2, h = H + 2);
        translate([lanyard[0], lanyard[1], -0.01]) cylinder(d1 = 4.4, d2 = 3.2, h = 0.6);
    }
}

// ---------------------------------------------------------------- top half
module top_half() {
    difference() {
        union() {
            difference() { body(0, H, r_edge, r_edge); translate([x0 - 10, -W, -1]) cube([x1 - x0 + 20, 2 * W, split + 1]); cavity(split - 1, z_in); }
            for (t = tongues) at_tongue(t) tongue(t[2], t[3]);
            for (x = top_rib_x, s = [-1, 1]) translate([x - rib / 2, s > 0 ? mod_w / 2 + 0.3 : in0[1] - 0.01, split])   // side wall ribs
                cube([rib, side_gap - 0.3 + 0.01, z_in - split + 0.01]);
            // pressers on both board ends, clear of the USB receptacle and the pin rows
            for (s = [-1, 1]) translate([0.1, s > 0 ? usb_w / 2 + 0.6 : -mod_w / 2 + 0.6, z_pcb_top]) cube([0.9, mod_w / 2 - usb_w / 2 - 1.2, z_in - z_pcb_top + 0.01]);
            translate([mod_l - 1.3, -row_y + hdr_w / 2 + 0.4, z_pcb_top]) cube([1.2, 2 * row_y - hdr_w - 0.8, z_in - z_pcb_top + 0.01]);
            for (p = [led_at, reset_at]) translate([p[0], p[1], z_pcb_top + max(usb_h, pins_above) - 1.0]) cylinder(d = 3.0, h = z_in - z_pcb_top - max(usb_h, pins_above) + 1.01);   // light / pin guides
        }
        port_cut();
        translate([led_at[0], led_at[1], z_pcb_top]) cylinder(d = 1.8, h = H);               // light dot
        translate([led_at[0], led_at[1], H - 0.5]) cylinder(d1 = 1.8, d2 = 2.8, h = 0.51);
        translate([reset_at[0], reset_at[1], z_pcb_top]) cylinder(d = 1.3, h = H, $fn = 20);  // reset pin hole
        translate([reset_at[0], reset_at[1], H - 0.4]) cylinder(d1 = 1.3, d2 = 2.1, h = 0.41, $fn = 20);
        translate([lanyard[0], lanyard[1], -1]) cylinder(d = 3.2, h = H + 2);
        translate([lanyard[0], lanyard[1], H - 0.6]) cylinder(d1 = 3.2, d2 = 4.4, h = 0.61);
        logo(txt_d);
        translate([13.5, 0, H - txt_d]) linear_extrude(txt_d + 0.01) text("CLICKER", size = 3.6, font = "DejaVu Sans:style=Bold", halign = "center", valign = "center", spacing = 1.15);
    }
}

if (part == "dims") echo(z_pcb = z_pcb, z_pcb_top = z_pcb_top, H = H, usb_z = usb_z, size = [x1 - x0, W, H], x0 = x0, x1 = x1,
                         split = split, mod = [mod_l, mod_w, mod_t], usb = [usb_w, usb_h, usb_over, usb_len], pins_below = pins_below,
                         row_y = row_y, hdr_w = hdr_w, pins_above = pins_above, port = port, led_at = led_at, reset_at = reset_at,
                         lanyard = lanyard);
else if (part == "top") top_half();
else if (part == "top_print") translate([0, 0, H]) rotate([180, 0, 0]) top_half();
else if (part == "bottom") bottom_half();
else {
    color("#1F6F78") bottom_half();
    color("#E6EAE8", 0.5) top_half();
}
