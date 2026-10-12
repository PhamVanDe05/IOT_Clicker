#include <Arduino.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <ESPmDNS.h>
#include <esp_mac.h>
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SH110X.h>
#include <ArduinoJson.h>
#include <Preferences.h>
#include "config.h"
#include "button_input.h"
#include "controller.h"
#include "root_ca.h"

// Three tasks: buttonTask scans keys, netTask (core 0) does every HTTP request, and loop() owns the
// Controller, the OLED and the Wi-Fi setup modes. loop() never waits for the network, so a key press shows at once.
// The server is stored as a link: "http://192.168.x.x" (same Wi-Fi, from ESPTouch) or "https://…" (far away).

Adafruit_SH1106G screen(OLED_WIDTH, OLED_HEIGHT, &Wire, -1);
Preferences settings;
Controller state;
ButtonInput buttons[BUTTON_COUNT];
QueueHandle_t keyEvents, netJobs, netResults;
SemaphoreHandle_t serverLock;  // guards serverIp and settings, shared by loop() and netTask
String uid, notice, shownText;
bool oledReady = false, provisioning = false, netBusy = false;
volatile bool serverOnline = false;
volatile uint32_t senderIp = 0;
String serverIp;
unsigned long lastPoll = 0, lastRetry = 0, lastDraw = 0, noticeAt = 0;

enum JobKind : uint8_t { JOB_POLL, JOB_SEND };
struct NetJob {
    JobKind kind;
    char action[8], student[21];
    int session, question;
    char answer;
};
struct NetResult {
    JobKind kind;
    int code;
    bool ok, loginOpen;
    char type[16], error[24], student[21], note[40];
    int session, question, questionNo;
    char answer, saved;
};

void message(const String &text) {
    notice = text;
    noticeAt = millis();
    Serial.println(text);
}

String currentServer() {
    xSemaphoreTake(serverLock, portMAX_DELAY);
    const String ip = serverIp;
    xSemaphoreGive(serverLock);
    return ip;
}

void saveServer(const String &ip) {
    xSemaphoreTake(serverLock, portMAX_DELAY);
    serverIp = ip;
    settings.putString("server", ip);
    xSemaphoreGive(serverLock);
}

// "192.168.4.201", "https://x.ts.net/clicker/" -> "http://192.168.4.201", "https://x.ts.net"; "" if unusable.
String normalizeServer(const String &raw) {
    String link = raw;
    link.trim();
    if (!link.length()) return "";
    if (link.indexOf("://") < 0) link = "http://" + link;
    if (!link.startsWith("http://") && !link.startsWith("https://")) return "";
    for (const char *tail : {"/api.php", "/", "/clicker", "/"}) if (link.endsWith(tail)) link.remove(link.length() - strlen(tail));
    return link.length() <= 96 ? link : "";
}

String apiUrl(const String &server) { return server + "/clicker/api.php"; }

// The laptop answers as clicker.local (service clicker-mdns) on the Wi-Fi it is on; none -> empty.
String findClickerLocal() {
    static bool started = false;
    if (!started) started = MDNS.begin(("clicker-" + uid.substring(12, 14) + uid.substring(15)).c_str());
    const IPAddress found = started ? MDNS.queryHost(SERVER_HOSTNAME, 2000) : IPAddress();
    return found == IPAddress() ? "" : "http://" + found.toString();
}

// Last reason the station was refused, for the setup page and Serial (e.g. wrong password vs. no such Wi-Fi).
volatile uint8_t wifiFailReason = 0;
void wifiDisconnected(WiFiEvent_t, WiFiEventInfo_t info) {
    wifiFailReason = info.wifi_sta_disconnected.reason;
    Serial.printf("[wifi] disconnected, reason %u\n", wifiFailReason);
}

// Setup page check: the server answers its public auth probe (no device key sent).
bool serverReachable(const String &server, String &why) {
    WiFiClient plain;
    WiFiClientSecure secure;
    secure.setCACert(ROOT_CA);
    HTTPClient probe;
    probe.setConnectTimeout(5000);
    probe.setTimeout(5000);
    WiFiClient &link = server.startsWith("https://") ? secure : plain;
    if (!probe.begin(link, apiUrl(server) + "?action=auth")) { why = "link sai"; return false; }
    const int code = probe.GET();
    probe.end();
    why = code > 0 ? "HTTP " + String(code) : "không kết nối được";
    return code == 200;
}

#include "setup_portal.h"

// Hold OK 5 s again: leave ESPTouch and go back to the saved Wi-Fi.
void cancelWifiSetup() {
    WiFi.stopSmartConfig();
    provisioning = false;
    WiFi.mode(WIFI_STA);
    WiFi.setSleep(false);
    WiFi.begin();
    message("Setup cancelled");
}

void smartConfigEvent(WiFiEvent_t, WiFiEventInfo_t info) {
    const uint8_t *ip = info.sc_got_ssid_pswd.cellphone_ip;
    senderIp = (uint32_t(ip[0]) << 24) | (uint32_t(ip[1]) << 16) | (uint32_t(ip[2]) << 8) | ip[3];
}

void startWifiSetup() {
    if (provisioning) return;
    if (portal::active()) portal::cancel();
    serverOnline = false;
    senderIp = 0;
    provisioning = WiFi.beginSmartConfig(SC_TYPE_ESPTOUCH);
    message(provisioning ? "Wi-Fi setup: send from web" : "Wi-Fi setup failed");
}


// ---------------------------------------------------------------- network task (core 0)

HTTPClient http;
WiFiClient plainClient;
WiFiClientSecure secureClient;
unsigned serverFailures = 0;
unsigned long statCount = 0, statFail = 0, statTotalMs = 0, statMaxMs = 0, statSince = 0;

// The saved server stopped answering: try, in order, the laptop's clicker.local name (same Wi-Fi as the laptop,
// whose IP may have changed) and the public link built into the firmware (any Wi-Fi with internet). The first
// one that answers is saved, so a board set up at home keeps working at the teacher's and the other way round.
bool findServer(NetResult &result) {
    static unsigned long lastLookup = 0;
    serverFailures = 0;
    if (lastLookup && millis() - lastLookup < SERVER_LOOKUP_PAUSE_MS) return false;
    lastLookup = millis();
    const String saved = currentServer();
    const String candidates[] = {findClickerLocal(), String(DEFAULT_SERVER)};
    for (const String &link : candidates) {
        String why;
        if (!link.length() || link == saved || !serverReachable(link, why)) continue;
        saveServer(link);
        snprintf(result.note, sizeof result.note, "Server found: %s", link.c_str());
        return true;
    }
    Serial.println("[net] server lookup: saved link, clicker.local and built-in link all unreachable");
    return false;
}

int request(JsonDocument &payload, JsonDocument &response, NetResult &result) {
    if (WiFi.status() != WL_CONNECTED) { serverOnline = false; return -1; }
    payload["device_uid"] = uid;
    String body;
    serializeJson(payload, body);
    const unsigned long start = millis();
    // Same URL every time + setReuse: HTTPClient keeps the TCP connection open between requests (Apache keep-alive).
    // HTTPClient reuses any live socket, so drop it when the server address changes (mDNS or new Wi-Fi setup).
    static String connectedTo;
    const String server = currentServer();
    if (server != connectedTo) { plainClient.stop(); secureClient.stop(); connectedTo = server; }
    WiFiClient &client = server.startsWith("https://") ? secureClient : plainClient;
    // Over the internet link a request takes ~0.5 s and sometimes a few seconds; on the same Wi-Fi ~10 ms.
    const unsigned timeout = server.startsWith("https://") ? REMOTE_TIMEOUT_MS : HTTP_TIMEOUT_MS;
    http.setConnectTimeout(timeout);
    http.setTimeout(timeout);
    int code = -1;
    if (server.length() && http.begin(client, apiUrl(server))) {
        http.addHeader("Content-Type", "application/json");
        http.addHeader("X-Device-Key", DEVICE_KEY);
        code = http.POST(body);
        const String text = code > 0 ? http.getString() : "";
        http.end();
        if (code > 0 && deserializeJson(response, text)) code = -1;
    }
    const unsigned long ms = millis() - start;
    statCount++; statTotalMs += ms; statMaxMs = max(statMaxMs, ms);
    if (code <= 0) statFail++;
    // One slow reply must not flip the screen to NO SERVER; a few failures in a row do.
    serverFailures = code > 0 ? 0 : serverFailures + 1;
    if (code > 0) serverOnline = true;
    else if (serverFailures >= OFFLINE_AFTER_FAILURES) serverOnline = false;
    if (serverFailures >= SERVER_LOOKUP_FAILURES) findServer(result);
    if (payload["action"] != "poll") Serial.printf("[net] %s -> %d in %lu ms\n", (const char *)payload["action"], code, ms);
    return code;
}

void runJob(const NetJob &job, NetResult &result) {
    JsonDocument payload, response;
    result = {};
    result.kind = job.kind;
    payload["action"] = job.kind == JOB_POLL ? "poll" : job.action;
    if (job.kind == JOB_SEND) {
        payload["session_id"] = job.session;
        if (!strcmp(job.action, "login")) payload["student_id"] = job.student;
        else { payload["question_id"] = job.question; payload["answer"] = String(job.answer); }
    }
    result.code = request(payload, response, result);
    result.ok = result.code == 200 && response["ok"].as<bool>();
    strlcpy(result.error, response["code"] | "", sizeof result.error);
    strlcpy(result.type, response["type"] | "", sizeof result.type);
    result.session = response["session_id"] | 0;
    if (job.kind == JOB_POLL) {
        result.loginOpen = String(response["state"] | "") == "LOGIN_OPEN";
        strlcpy(result.student, response["student_id"] | "", sizeof result.student);
        result.question = response["question"]["question_id"] | 0;
        result.questionNo = response["question"]["question_no"] | 0;
        const char *saved = response["question"]["accepted_answer"] | "";
        result.saved = saved[0];
    } else {
        strlcpy(result.student, response["student_id"] | "", sizeof result.student);
        result.question = response["question_id"] | 0;
        const char *answer = response["answer"] | "";
        result.answer = answer[0];
    }
}

void netTask(void *) {
    http.setReuse(true);
    secureClient.setCACert(ROOT_CA);  // verify the server certificate; the device key never goes to an impostor
    http.setConnectTimeout(HTTP_TIMEOUT_MS);
    http.setTimeout(HTTP_TIMEOUT_MS);
    statSince = millis();
    for (;;) {
        NetJob job;
        if (xQueueReceive(netJobs, &job, pdMS_TO_TICKS(NET_STATS_MS)) == pdTRUE) {
            NetResult result;
            runJob(job, result);
            xQueueSend(netResults, &result, portMAX_DELAY);
        }
        if (millis() - statSince >= NET_STATS_MS && statCount) {
            Serial.printf("[net] %lu req, avg %lu ms, max %lu ms, fail %lu\n", statCount, statTotalMs / statCount, statMaxMs, statFail);
            statCount = statFail = statTotalMs = statMaxMs = 0;
            statSince = millis();
        }
    }
}

// ---------------------------------------------------------------- loop() side

void queueJob(JobKind kind) {
    NetJob job = {};
    job.kind = kind;
    if (kind == JOB_SEND) {
        strlcpy(job.action, state.pending.action.c_str(), sizeof job.action);
        strlcpy(job.student, state.pending.student.c_str(), sizeof job.student);
        job.session = state.pending.session;
        job.question = state.pending.question;
        job.answer = state.pending.answer;
    }
    if (xQueueSend(netJobs, &job, 0) == pdTRUE) netBusy = true;
}

void applyResult(const NetResult &r) {
    netBusy = false;
    if (r.note[0]) message(r.note);
    if (r.kind == JOB_POLL) {
        if (r.ok) state.poll(r.session, r.loginOpen, r.student, r.question, r.questionNo, r.saved);
        else if (r.code >= 400) message(r.error[0] ? r.error : "SERVER ERROR");
        return;
    }
    if (state.pending.action.empty()) return;
    if (r.ok) {
        const bool loginAck = !strcmp(r.type, "LOGIN_OK") && state.pending.action == "login";
        const bool answerAck = !strcmp(r.type, "ANSWER_ACK") && state.pending.action == "answer";
        if ((loginAck || answerAck) && state.ack(r.session, r.question, r.answer, r.student)) message(loginAck ? "LOGIN OK" : "SAVED");
    } else if (r.code >= 400 && r.code < 500 && r.code != 408 && r.code != 429) {
        message(r.error[0] ? r.error : "REJECTED");
        state.pending = {};
    } else {
        Serial.println("Retrying...");  // the screen keeps "Sending ..." instead of flashing every retry
    }
}

void buttonTask(void *) {
    // Every key acts on press. Held 5 s: OK -> 'W' (ESPTouch from the website), BACK -> 'P' (setup Wi-Fi).
    unsigned long heldSince[BUTTON_COUNT] = {};
    bool longSent[BUTTON_COUNT] = {};
    for (;;) {
        const unsigned long now = millis();
        for (int i = 0; i < BUTTON_COUNT; ++i) {
            const char key = buttons[i].update(digitalRead(BUTTON_PINS[i]) == LOW, now, BUTTON_KEYS[i]);
            if (key) { xQueueSend(keyEvents, &key, 0); heldSince[i] = now; longSent[i] = false; }
            if (!buttons[i].pressed) { heldSince[i] = 0; continue; }
            const char longKey = BUTTON_KEYS[i] == 'C' ? 'W' : BUTTON_KEYS[i] == 'B' ? 'P' : 0;
            const unsigned hold = BUTTON_KEYS[i] == 'C' ? WIFI_SETUP_HOLD_MS : PORTAL_HOLD_MS;
            if (longKey && heldSince[i] && !longSent[i] && now - heldSince[i] >= hold) {
                xQueueSend(keyEvents, &longKey, 0);
                longSent[i] = true;
            }
        }
        vTaskDelay(pdMS_TO_TICKS(2));
    }
}

void logStatus() {
    static int lastWifi = -1, lastServer = -1;
    const int wifi = WiFi.status() == WL_CONNECTED, server = serverOnline;
    if (wifi == lastWifi && server == lastServer) return;
    lastWifi = wifi;
    lastServer = server;
    if (wifi) Serial.printf("[wifi] %s ip=%s rssi=%d sleep=%s server=%s %s\n", WiFi.SSID().c_str(),
        WiFi.localIP().toString().c_str(), WiFi.RSSI(), WiFi.getSleep() ? "on" : "off", currentServer().c_str(), server ? "ONLINE" : "offline");
    else Serial.println("[wifi] disconnected");
}

// Screen content, one line per '\n'. A line starting with '#' is drawn large (size 2, 10 characters wide):
// the state the student needs, in a word or two. Plain lines are small hints. Redrawn only when it changes.
String screenText() {
    String t;
    if (portal::state == portal::SHARING) {
        t = "#WI-FI OK\nSharing to other\nboards...\n";
    } else if (portal::active()) {
        t = "#SETUP\nWi-Fi: " + portal::apName + "\nOpen 192.168.4.1\n";
        t += portal::state == portal::CONNECTING ? "Connecting...\n" : "BACK 5s: cancel\n";
    } else if (provisioning) {
        t = String("#SETUP\n") + (senderIp ? "Joining Wi-Fi...\n" : "Send from web\n") + "OK 5s: cancel\n";
    } else if (WiFi.status() != WL_CONNECTED) {
        t = "#NO WI-FI\nBACK 5s: set Wi-Fi\n";
    } else if (!serverOnline) {
        t = "#NO SERVER\nSearching...\n";
    } else if (millis() - noticeAt < 2000 && notice.length()) {
        t = notice == "SAVED" || notice == "LOGIN OK" ? "#" + notice + "\n" : notice + "\n";
    } else if (!state.pending.action.empty()) {
        t = state.pending.action == "answer" ? "#SENDING\n#" + String(state.pending.answer) + "\n" : "#SENDING\n";
    } else if (state.student.empty()) {
        if (state.loginOpen) t = "MSSV:\n#" + String(state.input.c_str()) + "\nOK: send  BACK: del\n";
        else t = "#WAIT\n";  // connected; the MSSV box appears when the teacher opens login
    } else if (state.question) {
        t = "#Q" + String(state.questionNo) + "\n";
        if (state.accepted) t += "#" + String(state.selected) + " SAVED\n";
        else if (state.selected) t += "#" + String(state.selected) + "\nOK: send\n";
        else t += "1=A 2=B 3=C 4=D\n";
    } else {
        t = "#WAIT\n#QUESTION\n" + String(state.student.c_str()) + "\n";
    }
    return t;
}

void draw() {
    if (!oledReady) return;
    const String text = screenText();
    if (text == shownText) return;
    shownText = text;
    screen.clearDisplay();
    screen.setTextColor(SH110X_WHITE);
    int y = 0, start = 0;
    while (start < (int)text.length()) {
        int end = text.indexOf('\n', start);
        if (end < 0) end = text.length();
        const bool big = text[start] == '#';
        const String line = text.substring(start + big, end);
        screen.setTextSize(big ? 2 : 1);
        screen.setCursor(0, y);
        screen.print(line);
        y += big ? 18 : 10;
        start = end + 1;
    }
    screen.display();
}

void setup() {
    Serial.begin(115200);
    Wire.begin(OLED_SDA, OLED_SCL);
    Wire.setTimeOut(20);
    oledReady = screen.begin(OLED_ADDRESS, true);
    if (!oledReady) Serial.println("OLED missing; check SDA/SCL/address");

    for (int pin : BUTTON_PINS) pinMode(pin, pin == 12 ? INPUT_PULLUP : INPUT);
    keyEvents = xQueueCreate(32, sizeof(char));
    netJobs = xQueueCreate(1, sizeof(NetJob));
    netResults = xQueueCreate(1, sizeof(NetResult));
    serverLock = xSemaphoreCreateMutex();
    settings.begin("clicker", false);
    serverIp = normalizeServer(settings.getString("server", ""));  // older boards stored a bare IP
    WiFi.persistent(true);
    WiFi.onEvent(smartConfigEvent, ARDUINO_EVENT_SC_GOT_SSID_PSWD);
    WiFi.onEvent(wifiDisconnected, ARDUINO_EVENT_WIFI_STA_DISCONNECTED);
    WiFi.mode(WIFI_STA);
    WiFi.setSleep(false);  // no modem sleep: replies arrive in ~10 ms instead of 100-260 ms
    WiFi.setAutoReconnect(true);
    // Factory MAC from eFuse: WiFi.macAddress() reads 00:00:00:00:00:00 on core 3.x before the radio is up,
    // which would give every board the same device UID.
    uint8_t mac[6];
    esp_efuse_mac_get_default(mac);
    char text[18];
    snprintf(text, sizeof text, "%02X:%02X:%02X:%02X:%02X:%02X", mac[0], mac[1], mac[2], mac[3], mac[4], mac[5]);
    uid = text;
    if (!keyEvents || !netJobs || !netResults || !serverLock
        || xTaskCreate(buttonTask, "buttons", 2048, nullptr, 2, nullptr) != pdPASS
        || xTaskCreatePinnedToCore(netTask, "net", 8192, nullptr, 1, nullptr, 0) != pdPASS) {
        Serial.println("Task allocation failed");
        while (true) delay(1000);
    }
    if (serverIp.length()) WiFi.begin();
    else startWifiSetup();
    Serial.println("Device UID: " + uid);
    Serial.println("Saved server: " + (serverIp.length() ? serverIp : String("(none)")));
    draw();
}

void loop() {
    char key;
    bool sendNow = false;
    while (xQueueReceive(keyEvents, &key, 0) == pdTRUE) {
        // Each setup mode is entered and left only by holding its own key again; it never switches by itself.
        if (key == 'W') {
            if (provisioning) cancelWifiSetup(); else startWifiSetup();
        }
        else if (key == 'P') {
            if (provisioning) { WiFi.stopSmartConfig(); provisioning = false; }
            if (portal::active()) portal::cancel(); else portal::begin();
        }
        else if (provisioning || portal::active()) continue;
        else if (key == 'C') { state.confirm(); sendNow = true; }
        else if (key == 'B') state.backspace();
        else state.key(key);
    }
    NetResult result;
    if (xQueueReceive(netResults, &result, 0) == pdTRUE) applyResult(result);

    const unsigned long now = millis();
    if (portal::active()) {
        portal::loop();
    } else if (provisioning) {
        if (WiFi.smartConfigDone() && WiFi.status() == WL_CONNECTED && senderIp) {
            const uint32_t ip = senderIp;
            saveServer("http://" + String((ip >> 24) & 255) + "." + String((ip >> 16) & 255) + "." + String((ip >> 8) & 255) + "." + String(ip & 255));
            WiFi.stopSmartConfig();
            WiFi.setSleep(false);
            provisioning = false;
            message("Wi-Fi ready: " + WiFi.localIP().toString());
        }
    } else if (WiFi.status() != WL_CONNECTED) {
        // Keep retrying the saved Wi-Fi; setup modes start only from a held key (or first boot with none saved).
        serverOnline = false;
        if (now - lastRetry >= WIFI_RETRY_MS) {
            lastRetry = now;
            WiFi.reconnect();
        }
    } else if (!netBusy) {
        // A new press goes out at once; an unacknowledged one is resent every RETRY_MS; otherwise poll.
        if (!state.pending.action.empty() && (sendNow || now - lastRetry >= RETRY_MS)) {
            lastRetry = now;
            queueJob(JOB_SEND);
        } else if (state.pending.action.empty() && now - lastPoll >= POLL_MS) {
            lastPoll = now;
            queueJob(JOB_POLL);
        }
    }
    logStatus();
    if (now - lastDraw >= DRAW_MS) {
        lastDraw = now;
        draw();
    }
    vTaskDelay(pdMS_TO_TICKS(2));
}
