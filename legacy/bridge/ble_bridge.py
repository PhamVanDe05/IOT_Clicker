#!/usr/bin/env python3
"""BLE bridge: the server's own Bluetooth adapter talks to nRF52840 Student Tags.

Tags advertise HELLO/LOGIN/ANSWER frames; this program scans them through BlueZ (D-Bus),
forwards LOGIN/ANSWER to api.php, and advertises the session state plus per-tag replies.
No BLE connections are used, so one adapter serves the whole classroom.
Frame layout: firmware/StudentTagNRF52/protocol.h (vectors pinned in tests/test_ble_bridge.py).

Run:  python3 bridge/ble_bridge.py            (bridge key from .local/access.json)
"""
import argparse
import json
import os
import signal
import struct
import sys
import threading
import time
import urllib.error
import urllib.request

COMPANY = 0xFFFF
MAGIC = b"SC\x01"
HELLO, LOGIN, ANSWER, STATE = 0x01, 0x02, 0x03, 0x80
STATES = {"WAIT_SESSION": 0, "CREATED": 1, "LOGIN_OPEN": 2, "READY": 3, "QUESTION_ACTIVE": 4, "FINISHED": 5}
DISABLED, OFFLINE = 6, 0xFF
LOGIN_OK, ANSWER_OK, STATUS, ERROR = 1, 2, 3, 4
ERRORS = {"LOGIN_CLOSED": 1, "STUDENT_NOT_FOUND": 2, "STUDENT_IN_USE": 3, "DEVICE_IN_USE": 4,
          "LOGIN_REQUIRED": 5, "QUESTION_CLOSED": 6, "ANSWER_LOCKED": 7}
E_REJECTED, E_GATEWAY = 8, 9
MAX_DIGITS = 20

SYNC_S = 1.0             # bridge_sync period: session state + tag heartbeats
TAG_ACTIVE_S = 8.0       # tag counts as online if heard within this window
ROTATE_MS = 120          # each advert carries 2 reply slots
REPLY_REPEATS = 10       # adverts per reply; tags keep retrying if all are missed
STATUS_REPEAT_S = 2.0    # minimum gap between STATUS corrections for one tag
OFFLINE_AFTER_FAILS = 3


# ---------- protocol (pure functions, tested without Bluetooth) ----------

def decode_uplink(data):
    """Manufacturer data after the company ID -> dict, or None if it is not a valid tag frame."""
    if len(data) < 10 or data[:3] != MAGIC:
        return None
    kind, seq, counter = data[3], data[4], data[5]
    (session,) = struct.unpack_from("<I", data, 6)
    frame = {"type": kind, "seq": seq, "counter": counter, "session": session}
    if kind == HELLO and len(data) >= 16:
        frame.update(question=struct.unpack_from("<I", data, 10)[0], logged=bool(data[14]), answer=chr(data[15]) if data[15] else "")
        return frame
    if kind == ANSWER and len(data) >= 15 and data[14] in b"ABCD":
        frame.update(question=struct.unpack_from("<I", data, 10)[0], answer=chr(data[14]))
        return frame
    if kind != LOGIN or len(data) < 11:
        return None
    n = data[10]
    if not 0 < n <= MAX_DIGITS or len(data) < 11 + (n + 1) // 2:
        return None
    digits = [(data[11 + i // 2] >> (0 if i % 2 else 4)) & 0x0F for i in range(n)]
    if max(digits) > 9:
        return None
    frame["student"] = "".join(map(str, digits))
    return frame


def encode_downlink(session, state, question, question_no, slots):
    """STATE frame (without company ID); slots = up to 2 (addr3, kind, seq, value) tuples."""
    out = bytearray(MAGIC + bytes([STATE]))
    out += struct.pack("<IBIH", session, state, question, question_no)
    for i in range(2):
        addr, kind, seq, value = slots[i] if i < len(slots) else (b"\0\0\0", 0, 0, 0)
        out += addr + bytes([kind, seq, value])
    return bytes(out)


def low_address(address):
    """'AA:BB:CC:DD:EE:FF' -> 3 low bytes, least significant first (tag's ble_gap_addr_t order)."""
    return bytes.fromhex(address.replace(":", ""))[::-1][:3]


def belief(logged, answer):
    return (ord(answer) if answer else ord("L")) if logged else 0


class Tag:
    def __init__(self, address):
        self.address = address
        self.uid = "NRF-" + address.replace(":", "").upper()
        self.addr3 = low_address(address)
        self.seen = 0.0
        self.hello = None
        self.job = None           # LOGIN/ANSWER frame waiting for the server
        self.done_seq = 0         # last answered request, replayed if the tag retries
        self.done_slot = None
        self.status_at = float("-inf")


class Bridge:
    """Tag registry, reply rotation and HTTP forwarding. Bluetooth-independent for tests."""

    def __init__(self, post):
        self.post = post          # post(payload) -> (http_status, json_dict)
        self.lock = threading.Lock()
        self.tags = {}
        self.replies = {}         # address -> [slot, remaining]
        self.order = []           # rotation order of addresses with replies
        self.state = {"session": 0, "state": OFFLINE, "question": 0, "question_no": 0}
        self.mode = "wifi"
        self.fails = 0
        self.uid = "BRIDGE-UNKNOWN"

    def push_reply(self, tag, kind, seq, value):
        slot = (tag.addr3, kind, seq, value)
        if tag.address not in self.replies:
            self.order.append(tag.address)
        self.replies[tag.address] = [slot, REPLY_REPEATS]
        return slot

    def on_advert(self, address, data, now=None):
        frame = decode_uplink(data)
        if not frame:
            return
        now = time.monotonic() if now is None else now
        with self.lock:
            tag = self.tags.get(address) or self.tags.setdefault(address, Tag(address))
            tag.seen = now
            if frame["type"] == HELLO:
                tag.hello = frame
            elif frame["seq"] and frame["seq"] == tag.done_seq:
                self.push_reply(tag, *tag.done_slot[1:])      # ACK was missed: replay it
            elif not tag.job or tag.job["seq"] != frame["seq"]:
                tag.job = frame

    def next_frame(self):
        """Advert payload for the next rotation tick (company ID first)."""
        with self.lock:
            slots = []
            for _ in range(min(2, len(self.order))):  # two different tags per frame
                address = self.order.pop(0)
                entry = self.replies[address]
                slots.append(entry[0])
                entry[1] -= 1
                if entry[1] > 0:
                    self.order.append(address)
                else:
                    del self.replies[address]
            s = self.state
            state = DISABLED if self.mode != "ble" and s["state"] != OFFLINE else s["state"]
            frame = encode_downlink(s["session"], state, s["question"], s["question_no"], slots)
        return struct.pack("<H", COMPANY) + frame

    def process_job(self):
        with self.lock:
            tag = next((t for t in self.tags.values() if t.job), None)
            job = dict(tag.job) if tag else None
        if not job:
            return False
        login = job["type"] == LOGIN
        payload = {"action": "login" if login else "answer", "device_uid": tag.uid,
                   "bridge_uid": self.uid, "session_id": job["session"]}
        if login:
            payload["student_id"] = job["student"]
        else:
            payload.update(question_id=job["question"], answer=job["answer"])
        code, body = self.post(payload)
        if code == 200 and body.get("ok") and body.get("type") == ("LOGIN_OK" if login else "ANSWER_ACK"):
            kind, value = (LOGIN_OK, 0) if login else (ANSWER_OK, ord(job["answer"]))
        elif 400 <= code < 500 and code not in (408, 429):
            kind, value = ERROR, E_GATEWAY if code == 401 else ERRORS.get(body.get("code"), E_REJECTED)
        else:
            print(f"{tag.uid} {payload['action']}: retry (HTTP {code})", flush=True)
            return False
        print(f"{tag.uid} {payload['action']} seq={job['seq']} -> kind={kind} value={value}", flush=True)
        with self.lock:
            if tag.job and tag.job["seq"] == job["seq"]:
                tag.job = None
                tag.done_seq = job["seq"]
                tag.done_slot = self.push_reply(tag, kind, job["seq"], value)
        return True

    def sync(self, now=None):
        now = time.monotonic() if now is None else now
        with self.lock:
            uids = [t.uid for t in self.tags.values() if now - t.seen < TAG_ACTIVE_S]
        code, body = self.post({"action": "bridge_sync", "device_uid": self.uid, "tags": uids})
        if code != 200 or not body.get("ok"):
            self.fails += 1
            if self.fails >= OFFLINE_AFTER_FAILS:
                with self.lock:
                    self.state["state"] = OFFLINE
            print(f"bridge_sync failed (HTTP {code} {body.get('code', '')})", flush=True)
            return False
        self.fails = 0
        question = body.get("question") or {}
        status = body.get("tags") or {}
        with self.lock:
            self.mode = body.get("mode", "wifi")
            self.state = {"session": body.get("session_id") or 0, "state": STATES.get(body.get("state"), 0),
                          "question": question.get("question_id") or 0, "question_no": question.get("question_no") or 0}
            for tag in self.tags.values():
                info, hello = status.get(tag.uid), tag.hello
                if not info or tag.job or not hello or self.mode != "ble":
                    continue
                # Only correct a tag that already follows this session/question but disagrees with the server.
                if hello["session"] != self.state["session"] or hello["question"] != self.state["question"]:
                    continue
                truth = belief(info.get("logged"), info.get("answer") or "")
                if truth != belief(hello["logged"], hello["answer"]) and now - tag.status_at >= STATUS_REPEAT_S:
                    tag.status_at = now
                    self.push_reply(tag, STATUS, 0, truth)
        return True


# ---------- HTTP ----------

def make_post(url, key):
    def post(payload):
        request = urllib.request.Request(url, json.dumps(payload).encode(),
                                         {"Content-Type": "application/json", "X-Device-Key": key})
        try:
            with urllib.request.urlopen(request, timeout=2) as response:
                return response.status, json.loads(response.read() or b"{}")
        except urllib.error.HTTPError as error:
            try:
                return error.code, json.loads(error.read() or b"{}")
            except ValueError:
                return error.code, {}
        except (OSError, ValueError):
            return -1, {}
    return post


def http_worker(bridge, stop):
    next_sync = 0.0
    while not stop.is_set():
        if time.monotonic() >= next_sync:
            next_sync = time.monotonic() + SYNC_S
            bridge.sync()
        elif not bridge.process_job():
            time.sleep(0.02)


# ---------- BlueZ D-Bus ----------

def run_bluez(bridge, adapter_name):
    import dbus
    import dbus.mainloop.glib
    import dbus.service
    from gi.repository import GLib

    dbus.mainloop.glib.DBusGMainLoop(set_as_default=True)
    bus = dbus.SystemBus()
    adapter_path = "/org/bluez/" + adapter_name
    adapter = dbus.Interface(bus.get_object("org.bluez", adapter_path), "org.bluez.Adapter1")
    props = dbus.Interface(bus.get_object("org.bluez", adapter_path), "org.freedesktop.DBus.Properties")
    props.Set("org.bluez.Adapter1", "Powered", dbus.Boolean(True))
    bridge.uid = "BRIDGE-" + str(props.Get("org.bluez.Adapter1", "Address")).replace(":", "")
    print("Bridge UID:", bridge.uid, flush=True)

    class Advertisement(dbus.service.Object):
        PATH = "/clicker/bridge/advert0"

        def __init__(self):
            super().__init__(bus, self.PATH)
            self.payload = bridge.next_frame()

        def properties(self):
            return {"Type": "broadcast",
                    "ManufacturerData": dbus.Dictionary({dbus.UInt16(COMPANY): dbus.Array(self.payload[2:], signature="y")}, signature="qv"),
                    "MinInterval": dbus.UInt32(100), "MaxInterval": dbus.UInt32(100)}

        @dbus.service.method("org.freedesktop.DBus.Properties", in_signature="s", out_signature="a{sv}")
        def GetAll(self, interface):
            return self.properties()

        @dbus.service.method("org.bluez.LEAdvertisement1")
        def Release(self):
            print("Advertisement released by BlueZ", flush=True)

        @dbus.service.signal("org.freedesktop.DBus.Properties", signature="sa{sv}as")
        def PropertiesChanged(self, interface, changed, invalidated):
            pass

        def update(self):
            payload = bridge.next_frame()
            if payload != self.payload:
                self.payload = payload
                self.PropertiesChanged("org.bluez.LEAdvertisement1", {"ManufacturerData": self.properties()["ManufacturerData"]}, [])
            return True

    advert = Advertisement()
    manager = dbus.Interface(bus.get_object("org.bluez", adapter_path), "org.bluez.LEAdvertisingManager1")
    registered = {"ok": False}

    def register():
        if not registered["ok"]:
            manager.RegisterAdvertisement(advert.PATH, {},
                reply_handler=lambda: (registered.update(ok=True), print("Advertising started", flush=True)),
                error_handler=lambda e: print("RegisterAdvertisement failed:", e, flush=True))

    def on_manufacturer(path, data):
        if data and COMPANY in data:
            address = path.rsplit("/dev_", 1)[-1].replace("_", ":")
            bridge.on_advert(address, bytes(data[COMPANY]))

    def interfaces_added(path, interfaces):
        device = interfaces.get("org.bluez.Device1")
        if device and str(path).startswith(adapter_path + "/dev_"):
            on_manufacturer(str(path), device.get("ManufacturerData"))

    def properties_changed(interface, changed, invalidated, path=None):
        if interface == "org.bluez.Device1" and "ManufacturerData" in changed and str(path).startswith(adapter_path + "/dev_"):
            on_manufacturer(str(path), changed["ManufacturerData"])

    bus.add_signal_receiver(interfaces_added, dbus_interface="org.freedesktop.DBus.ObjectManager", signal_name="InterfacesAdded")
    bus.add_signal_receiver(properties_changed, dbus_interface="org.freedesktop.DBus.Properties", signal_name="PropertiesChanged",
                            arg0="org.bluez.Device1", path_keyword="path")

    def health():
        # Another program may stop discovery, and bluetoothd may restart; recover both.
        try:
            if not props.Get("org.bluez.Adapter1", "Discovering"):
                adapter.SetDiscoveryFilter({"Transport": "le", "DuplicateData": dbus.Boolean(True)})
                adapter.StartDiscovery()
                print("Scanning started", flush=True)
        except dbus.exceptions.DBusException as error:
            print("Scan error:", error, flush=True)
        register()
        return True

    def owner_changed(name, old, new):
        if name == "org.bluez" and new:
            registered["ok"] = False

    bus.add_signal_receiver(owner_changed, dbus_interface="org.freedesktop.DBus", signal_name="NameOwnerChanged", arg0="org.bluez")
    health()
    GLib.timeout_add(ROTATE_MS, advert.update)
    GLib.timeout_add_seconds(5, health)
    loop = GLib.MainLoop()
    for sig in (signal.SIGINT, signal.SIGTERM):
        GLib.unix_signal_add(GLib.PRIORITY_DEFAULT, sig, loop.quit)
    loop.run()
    # Leave the adapter as we found it: no stale advert or discovery session.
    for call in (lambda: manager.UnregisterAdvertisement(advert.PATH), adapter.StopDiscovery):
        try:
            call()
        except dbus.exceptions.DBusException:
            pass
    print("Bridge stopped", flush=True)


def main():
    root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--api", default="http://127.0.0.1/clicker/api.php")
    parser.add_argument("--adapter", default="hci0")
    parser.add_argument("--access", default=os.path.join(root, ".local", "access.json"),
                        help="JSON with bridge_key (or set CLICKER_BRIDGE_KEY)")
    args = parser.parse_args()
    key = os.environ.get("CLICKER_BRIDGE_KEY")
    if not key:
        with open(args.access, encoding="utf-8") as f:
            key = json.load(f)["bridge_key"]
    bridge = Bridge(make_post(args.api, key))
    stop = threading.Event()
    threading.Thread(target=http_worker, args=(bridge, stop), daemon=True).start()
    try:
        run_bluez(bridge, args.adapter)
    finally:
        stop.set()


if __name__ == "__main__":
    sys.exit(main())
