"""python3 -m unittest tests/test_ble_bridge.py  (from the clicker directory; no Bluetooth needed)"""
import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "bridge"))
import ble_bridge as b  # noqa: E402

TAG = "F6:D5:C4:B3:A2:E1"
ME = bytes([0xE1, 0xA2, 0xB3])
# Same frames as tests/ble_test.cpp (the firmware's encoder).
LOGIN = bytes.fromhex("53430102072a7011010008" "00146217")
ANSWER = bytes.fromhex("534301030900030000001" "5cd5b0743")
HELLO = bytes.fromhex("53430101000102000000030000000144")
DOWNLINK = "5343018005000000042a0000000c00112233020442000000000000"


class FakeServer:
    def __init__(self):
        self.calls, self.replies = [], []

    def __call__(self, payload):
        self.calls.append(payload)
        if payload["action"] == "bridge_sync":
            return self.sync
        return self.replies.pop(0)


def hello(session, question, logged, answer=0, counter=1):
    return b"SC\x01\x01\x00" + bytes([counter]) + session.to_bytes(4, "little") + question.to_bytes(4, "little") + bytes([logged, answer])


class Protocol(unittest.TestCase):
    def test_vectors_match_firmware(self):
        self.assertEqual(b.decode_uplink(LOGIN), {"type": 2, "seq": 7, "counter": 42, "session": 70000, "student": "00146217"})
        self.assertEqual(b.decode_uplink(ANSWER), {"type": 3, "seq": 9, "counter": 0, "session": 3, "question": 123456789, "answer": "C"})
        self.assertEqual(b.decode_uplink(HELLO), {"type": 1, "seq": 0, "counter": 1, "session": 2, "question": 3, "logged": True, "answer": "D"})
        frame = b.encode_downlink(5, 4, 42, 12, [(bytes([0x11, 0x22, 0x33]), 2, 4, ord("B"))])
        self.assertEqual(frame.hex(), DOWNLINK)
        self.assertLessEqual(2 + 2 + len(frame), 31)

    def test_rejects_bad_frames(self):
        self.assertIsNone(b.decode_uplink(b"XX" + LOGIN[2:]))
        self.assertIsNone(b.decode_uplink(LOGIN[:12]))
        self.assertIsNone(b.decode_uplink(ANSWER[:14] + b"E"))
        self.assertIsNone(b.decode_uplink(LOGIN[:11] + b"\xa0" + LOGIN[12:]))

    def test_address_order(self):
        self.assertEqual(b.low_address(TAG), ME)


class BridgeLogic(unittest.TestCase):
    def setUp(self):
        self.server = FakeServer()
        self.server.sync = (200, {"ok": True, "mode": "ble", "session_id": 1, "state": "LOGIN_OPEN", "question": None, "tags": {}})
        self.bridge = b.Bridge(self.server)

    def frame(self):
        data = self.bridge.next_frame()
        self.assertEqual(data[:2], b"\xff\xff")
        return data[2:]

    def test_login_ack_replay_and_state(self):
        self.bridge.sync(now=0)
        self.assertEqual(self.frame()[8], 2)                              # LOGIN_OPEN
        self.bridge.on_advert(TAG, LOGIN, now=0)
        self.bridge.on_advert(TAG, LOGIN[:5] + b"\x2b" + LOGIN[6:], now=0.3)  # retry, new counter
        self.server.replies.append((200, {"ok": True, "type": "LOGIN_OK"}))
        self.assertTrue(self.bridge.process_job())
        self.assertFalse(self.bridge.process_job())                       # retries did not queue twice
        self.assertEqual(self.server.calls[-1], {"action": "login", "device_uid": "NRF-F6D5C4B3A2E1", "bridge_uid": "BRIDGE-UNKNOWN", "session_id": 70000, "student_id": "00146217"})
        slot = self.frame()[15:21]
        self.assertEqual(slot, ME + bytes([b.LOGIN_OK, 7, 0]))
        for _ in range(b.REPLY_REPEATS - 1):
            self.frame()
        self.assertEqual(self.frame()[15:21], bytes(6))                   # reply expired
        self.bridge.on_advert(TAG, LOGIN, now=1)                          # tag missed every ACK
        self.assertEqual(self.frame()[15:21], slot)                       # replayed without HTTP
        self.assertEqual(len([c for c in self.server.calls if c["action"] == "login"]), 1)

    def test_errors_and_network_retry(self):
        self.bridge.on_advert(TAG, ANSWER, now=0)
        self.server.replies += [(-1, {}), (503, {}), (409, {"ok": False, "code": "QUESTION_CLOSED"})]
        self.assertFalse(self.bridge.process_job())
        self.assertFalse(self.bridge.process_job())
        self.assertTrue(self.bridge.process_job())
        self.assertEqual(self.server.calls[-1]["answer"], "C")
        self.assertEqual(self.frame()[15:21], ME + bytes([b.ERROR, 9, 6]))
        other = "11:22:33:44:55:66"
        self.bridge.on_advert(other, LOGIN, now=0)
        self.server.replies.append((401, {"ok": False, "code": "UNAUTHORIZED"}))
        self.bridge.process_job()
        self.assertIn(bytes([0x66, 0x55, 0x44, b.ERROR, 7, b.E_GATEWAY]), self.frame())

    def test_two_slots_rotate_between_tags(self):
        tags = ["00:00:00:00:00:%02X" % i for i in range(3)]
        for i, address in enumerate(tags):
            self.bridge.on_advert(address, LOGIN, now=0)
            self.server.replies.append((200, {"ok": True, "type": "LOGIN_OK"}))
            self.bridge.process_job()
        seen = set()
        for _ in range(3):
            f = self.frame()
            self.assertNotEqual(f[15:18], f[21:24])
            seen |= {f[15], f[21]}
        self.assertEqual(seen, {0, 1, 2})

    def test_status_corrects_rebooted_tag_only(self):
        self.server.sync = (200, {"ok": True, "mode": "ble", "session_id": 1, "state": "QUESTION_ACTIVE",
                                  "question": {"question_id": 9, "question_no": 3},
                                  "tags": {"NRF-F6D5C4B3A2E1": {"logged": True, "answer": "B"}}})
        self.bridge.on_advert(TAG, hello(1, 9, 0), now=0)
        self.bridge.sync(now=0)
        self.assertEqual(self.server.calls[-1]["tags"], ["NRF-F6D5C4B3A2E1"])
        self.assertEqual(self.frame()[15:21], ME + bytes([b.STATUS, 0, ord("B")]))
        self.bridge.replies.clear(); self.bridge.order.clear()
        self.bridge.on_advert(TAG, hello(1, 9, 1, ord("B")), now=0.5)     # tag agrees now
        self.bridge.sync(now=3)
        self.assertEqual(self.frame()[15:21], bytes(6))
        self.bridge.on_advert(TAG, hello(1, 8, 0), now=4)                 # tag still on old question
        self.bridge.sync(now=6)
        self.assertEqual(self.frame()[15:21], bytes(6))
        self.bridge.sync(now=20)                                          # not heard for 16 s
        self.assertEqual(self.server.calls[-1]["tags"], [])

    def test_wifi_mode_and_offline(self):
        self.server.sync = (200, {"ok": True, "mode": "wifi", "session_id": 1, "state": "LOGIN_OPEN", "question": None, "tags": {}})
        self.bridge.sync(now=0)
        self.assertEqual(self.frame()[8], b.DISABLED)
        self.server.sync = (-1, {})
        for _ in range(b.OFFLINE_AFTER_FAILS):
            self.bridge.sync(now=0)
        self.assertEqual(self.frame()[8], b.OFFLINE)


if __name__ == "__main__":
    unittest.main()
