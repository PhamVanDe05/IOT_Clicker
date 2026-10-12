#!/usr/bin/env python3
"""Publish clicker.local -> the laptop's current Wi-Fi IPv4 through Avahi.

Teachers open http://clicker.local/clicker/ on any network; ESP32 boards look the
same name up when the saved server IP stops answering. The record follows the
default-route interface and is republished whenever the IP changes.
"""
import argparse
import subprocess
import sys
import time

import dbus

AVAHI = 'org.freedesktop.Avahi'
IF_UNSPEC, PROTO_INET = -1, 0
NO_REVERSE = 16  # the hostname already owns the IP -> name PTR record


def current_ip():
    # Same source the web uses: the address the laptop would send from to reach the network.
    out = subprocess.run(['ip', '-4', 'route', 'get', '1.1.1.1'], capture_output=True, text=True).stdout.split()
    return out[out.index('src') + 1] if 'src' in out else ''


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--name', default='clicker.local')
    parser.add_argument('--interval', type=float, default=5)
    args = parser.parse_args()
    bus = dbus.SystemBus()
    server = dbus.Interface(bus.get_object(AVAHI, '/'), 'org.freedesktop.Avahi.Server')
    group, published = None, None
    while True:
        ip = current_ip()
        if ip != published:
            try:
                if group is None:
                    group = dbus.Interface(bus.get_object(AVAHI, server.EntryGroupNew()), 'org.freedesktop.Avahi.EntryGroup')
                group.Reset()
                if ip:
                    group.AddAddress(IF_UNSPEC, PROTO_INET, dbus.UInt32(NO_REVERSE), args.name, ip)
                    group.Commit()
                published = ip
                print(f'{args.name} -> {ip or "(no network)"}', flush=True)
            except dbus.DBusException as error:
                # Avahi restarted or the name clashed; start over on the next tick.
                print(f'avahi: {error.get_dbus_message()}', file=sys.stderr, flush=True)
                group, published = None, None
        time.sleep(args.interval)


if __name__ == '__main__':
    main()
