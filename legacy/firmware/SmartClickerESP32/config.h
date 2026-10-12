#pragma once
#include <stdint.h>

// Wi-Fi and the server link are set on the board: ESPTouch (hold OK) or the setup page (hold BACK).
// This file contains only hardware settings; device_key.h is local to this board.
#include "device_key.h"

constexpr int OLED_SDA = 21, OLED_SCL = 22;
constexpr int OLED_ADDRESS = 0x3C;
constexpr int OLED_WIDTH = 128, OLED_HEIGHT = 64;
// Each button: 3V3 -- 10k -- GPIO -- switch -- GND.
// Old 12-button layout (inactive):
// BUTTON_PINS[] = {35,32,23,25,26,27,14,12,13,34,19,18};
// BUTTON_KEYS[] = {'1','2','3','4','5','6','7','8','9','0','B','C'};
// Keep SD1/GPIO8 and SD2/GPIO9 disconnected; do not pull GPIO12 up at boot.
constexpr int BUTTON_PINS[] = {34,35,32};
constexpr char BUTTON_KEYS[] = {'1','B','C'}; // 1, BACK, OK
constexpr int BUTTON_COUNT = sizeof(BUTTON_PINS) / sizeof(BUTTON_PINS[0]);
constexpr unsigned DEBOUNCE_MS = 20;
// Wi-Fi stays awake (no modem sleep) and one TCP connection is reused, so a request takes ~10-30 ms.
// A pending login/answer is retried every RETRY_MS; the server accepts answers up to 1.5 s after a question closes.
constexpr unsigned POLL_MS = 400, RETRY_MS = 300, HTTP_TIMEOUT_MS = 800, WIFI_RETRY_MS = 5000, DRAW_MS = 30;
constexpr unsigned REMOTE_TIMEOUT_MS = 4000;  // https link through the internet (Tailscale Funnel)
constexpr unsigned OFFLINE_AFTER_FAILURES = 3; // screen shows NO SERVER after this many failed requests in a row
constexpr unsigned NET_STATS_MS = 5000; // Serial summary of request times
// Setup modes change only by holding a key; holding the same key again cancels back to the saved Wi-Fi.
// Hold OK 5 s: ESPTouch from the website (server on the same Wi-Fi as the board).
// Hold BACK 5 s: the board's own setup Wi-Fi "Clicker-XXXX" + page at 192.168.4.1, for a server far away;
// the first board configured there passes the Wi-Fi to every other board waiting in that mode (ESP-NOW).
constexpr unsigned WIFI_SETUP_HOLD_MS = 5000, PORTAL_HOLD_MS = 5000;
constexpr unsigned PORTAL_CONNECT_MS = 15000, PORTAL_SHARE_MS = 20000, PORTAL_RESULT_MS = 8000;
constexpr uint8_t PORTAL_CHANNEL = 1; // every waiting board listens for the shared Wi-Fi on this channel
// Link pre-filled on the setup page (hold BACK), which is the mode used away from the laptop's Wi-Fi.
// Empty: pre-fill the link the board already has.
constexpr char DEFAULT_SERVER[] = "https://clicker.example.ts.net";  // Tailscale Funnel on the laptop
// After this many failed requests in a row, look for the server again (clicker.local, then DEFAULT_SERVER);
// a failed search waits SERVER_LOOKUP_PAUSE_MS before the next one.
constexpr unsigned SERVER_LOOKUP_FAILURES = 5, SERVER_LOOKUP_PAUSE_MS = 15000;
constexpr char SERVER_HOSTNAME[] = "clicker";
