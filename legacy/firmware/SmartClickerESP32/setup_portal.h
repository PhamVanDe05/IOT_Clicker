#pragma once
// Setup Wi-Fi for a server far away (hold BACK 5 s): the board opens its own open Wi-Fi "Clicker-XXXX"
// with a captive page at 192.168.4.1 where the teacher picks the hotspot, types its password and the
// server link. The board checks both, then passes the settings to every other board waiting in this mode
// over ESP-NOW, sealed with AES-256-GCM under a key derived from DEVICE_KEY (other kits cannot read or forge it).
//
// Expects from the sketch: String uid; void message(const String&); void saveServer(const String&);
// String currentServer(); String normalizeServer(const String&); bool serverReachable(const String&, String&);
// String findClickerLocal(); volatile uint8_t wifiFailReason.
#include <WebServer.h>
#include <DNSServer.h>
#include <esp_now.h>
#include <esp_wifi.h>
#include <esp_system.h>
#if __has_include(<esp_random.h>)
#include <esp_random.h>  // esp_fill_random moved here in ESP-IDF 5 (Arduino core 3.x)
#endif
#include <mbedtls/gcm.h>
#include <mbedtls/md.h>

namespace portal {

enum State : uint8_t { OFF, WAITING, CONNECTING, RESULT, SHARING };
State state = OFF;
WebServer web(80);
DNSServer dns;
String apName, pendingSsid, pendingPass, pendingServer, resultText, networkOptions;
bool resultOk = false, routesReady = false;
unsigned long stateAt = 0, lastShare = 0, lastTry = 0;

struct Shared { char ssid[33], pass[65], server[97]; };
constexpr uint8_t MAGIC[4] = {'C', 'S', 'H', '1'};
constexpr size_t PACKET = sizeof MAGIC + 12 + sizeof(Shared) + 16;  // 227 of ESP-NOW's 250 bytes
uint8_t shareKey[32];
volatile bool received = false;
Shared inbox;

void deriveKey() {
    const String seed = String("clicker-share:") + DEVICE_KEY;
    mbedtls_md(mbedtls_md_info_from_type(MBEDTLS_MD_SHA256), (const unsigned char *)seed.c_str(), seed.length(), shareKey);
}

bool seal(const Shared &plain, uint8_t *out) {
    memcpy(out, MAGIC, 4);
    esp_fill_random(out + 4, 12);
    mbedtls_gcm_context gcm;
    mbedtls_gcm_init(&gcm);
    const bool ok = !mbedtls_gcm_setkey(&gcm, MBEDTLS_CIPHER_ID_AES, shareKey, 256)
        && !mbedtls_gcm_crypt_and_tag(&gcm, MBEDTLS_GCM_ENCRYPT, sizeof plain, out + 4, 12, MAGIC, 4,
               (const unsigned char *)&plain, out + 16, 16, out + 16 + sizeof plain);
    mbedtls_gcm_free(&gcm);
    return ok;
}

bool unseal(const uint8_t *in, int len, Shared &plain) {
    if (len != (int)PACKET || memcmp(in, MAGIC, 4)) return false;
    mbedtls_gcm_context gcm;
    mbedtls_gcm_init(&gcm);
    const bool ok = !mbedtls_gcm_setkey(&gcm, MBEDTLS_CIPHER_ID_AES, shareKey, 256)
        && !mbedtls_gcm_auth_decrypt(&gcm, sizeof plain, in + 4, 12, MAGIC, 4, in + 16 + sizeof plain, 16,
               in + 16, (unsigned char *)&plain);
    mbedtls_gcm_free(&gcm);
    plain.ssid[32] = plain.pass[64] = plain.server[96] = 0;
    return ok;
}

// Runs in the Wi-Fi task: only a valid, authenticated packet is copied for loop() to apply.
// Arduino core 3.x (ESP-IDF 5) passes a receive-info struct first; core 2.x passes the sender MAC.
#if ESP_IDF_VERSION_MAJOR >= 5
void onShare(const esp_now_recv_info_t *, const uint8_t *data, int len) {
#else
void onShare(const uint8_t *, const uint8_t *data, int len) {
#endif
    Shared plain;
    if (state == WAITING && !received && unseal(data, len, plain)) { inbox = plain; received = true; }
}

const char PAGE[] PROGMEM = R"HTML(<!doctype html><html lang="vi"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>Cài Wi-Fi Clicker</title>
<style>body{font-family:system-ui,sans-serif;margin:0;padding:20px;background:#f3f6f7;color:#16303d}
main{max-width:420px;margin:auto;background:#fff;border-radius:12px;padding:20px;box-shadow:0 4px 18px #0001}
h1{font-size:20px;margin:0 0 4px}p{color:#587285;font-size:14px}label{display:block;font-weight:600;margin:14px 0 6px}
select,input,button{width:100%;box-sizing:border-box;font-size:16px;padding:11px;border:1px solid #c9d6da;border-radius:8px}
button{background:#0c8d79;color:#fff;border:0;font-weight:700;margin-top:18px}#msg{margin-top:16px;font-weight:600}
.ok{color:#15765b}.bad{color:#b43636}</style></head><body><main>
<h1>Cài Wi-Fi cho Clicker</h1><p>Thiết bị: <b>%DEVICE%</b>. Chọn Wi-Fi (hotspot) mà thiết bị sẽ dùng.</p>
<form id="f"><label for="ssid">Wi-Fi</label><select id="ssid" name="ssid">%OPTIONS%</select>
<input id="other" name="other" placeholder="Hoặc gõ tên Wi-Fi" autocomplete="off">
<label for="pass">Mật khẩu Wi-Fi</label><input id="pass" name="pass" type="password" autocomplete="off">
<label for="server">Link server</label><input id="server" name="server" value="%SERVER%" placeholder="https://… hoặc http://192.168.x.x">
<button>Lưu và kết nối</button></form><p id="msg" role="status"></p>
<p>Các board khác đang giữ BACK 5 giây sẽ tự nhận Wi-Fi này sau khi lưu xong.</p></main><script>
const $=id=>document.getElementById(id),say=(t,c)=>{$('msg').textContent=t;$('msg').className=c||''};
$('f').onsubmit=async e=>{e.preventDefault();const b=new URLSearchParams({ssid:$('other').value.trim()||$('ssid').value,
 pass:$('pass').value,server:$('server').value.trim()});say('Đang kết nối… (tối đa 20 giây)');
 try{await fetch('/save',{method:'POST',body:b})}catch{}poll()};
async function poll(){try{const s=await(await fetch('/status')).json();if(s.state==='connecting'){setTimeout(poll,1000);return}
 say(s.text,s.ok?'ok':'bad')}catch{say('Đang kết nối lại với thiết bị…');setTimeout(poll,1500)}}
</script></body></html>)HTML";

String htmlEscape(const String &text) {
    String out;
    for (const char c : text) {
        if (c == '&') out += "&amp;"; else if (c == '<') out += "&lt;"; else if (c == '>') out += "&gt;";
        else if (c == '"') out += "&quot;"; else out += c;
    }
    return out;
}

// Scan once, before the setup Wi-Fi goes up: scanning while a laptop is joined to the board can stall
// the radio, and a stalled station never even tries the network typed on the page.
void scanNetworks() {
    const unsigned long start = millis();
    const int count = WiFi.scanNetworks();
    networkOptions = "";
    String seen = "\n";
    for (int i = 0; i < count; ++i) {
        const String name = WiFi.SSID(i);
        if (!name.length() || seen.indexOf("\n" + name + "\n") >= 0) continue;
        seen += name + "\n";
        networkOptions += "<option>" + htmlEscape(name) + "</option>";
    }
    WiFi.scanDelete();
    if (!networkOptions.length()) networkOptions = "<option value=\"\">Không thấy Wi-Fi nào — gõ tên bên dưới</option>";
    Serial.printf("[setup] scan: %d networks in %lu ms\n", count, millis() - start);
}

// ESP-IDF disconnect reasons -> what the teacher can do about it.
String failText(uint8_t reason) {
    switch (reason) {
        case 2: case 15: case 202: case 204: return "sai mật khẩu.";
        case 201: case 210: case 211: return "không thấy Wi-Fi này (đang tắt, ở quá xa, hoặc là mạng 5 GHz). Điện thoại đang phát hotspot không được dùng để vào Wi-Fi Clicker.";
        case 0: return "không có phản hồi từ Wi-Fi (đang tắt hoặc ở quá xa).";
        default: return "Wi-Fi từ chối (mã " + String(reason) + ").";
    }
}

void setupRoutes() {
    if (routesReady) return;
    routesReady = true;
    web.on("/", [] {
        String page = FPSTR(PAGE);
        page.replace("%DEVICE%", htmlEscape(apName));
        page.replace("%SERVER%", htmlEscape(strlen(DEFAULT_SERVER) ? String(DEFAULT_SERVER) : currentServer()));
        page.replace("%OPTIONS%", networkOptions);
        web.send(200, "text/html; charset=utf-8", page);
    });
    web.on("/save", HTTP_POST, [] {
        pendingSsid = web.arg("ssid");
        pendingPass = web.arg("pass");
        pendingServer = normalizeServer(web.arg("server"));
        if (!pendingSsid.length() || pendingSsid.length() > 32 || pendingPass.length() > 64 || pendingServer.length() > 96) {
            resultOk = false;
            resultText = "Tên Wi-Fi hoặc mật khẩu không hợp lệ.";
            state = RESULT; stateAt = millis();
        } else {
            state = CONNECTING; stateAt = lastTry = millis();
            wifiFailReason = 0;
            const int started = WiFi.begin(pendingSsid.c_str(), pendingPass.c_str());
            Serial.printf("[setup] join \"%s\" (begin=%d)\n", pendingSsid.c_str(), started);
        }
        web.send(200, "application/json", "{\"ok\":true}");
    });
    web.on("/status", [] {
        JsonDocument doc;
        doc["state"] = state == CONNECTING ? "connecting" : "done";
        doc["ok"] = resultOk;
        doc["text"] = resultText;
        String body;
        serializeJson(doc, body);
        web.send(200, "application/json", body);
    });
    // Captive portal: any address the phone/laptop probes lands on the setup page.
    web.onNotFound([] { web.sendHeader("Location", "http://192.168.4.1/"); web.send(302, "text/plain", ""); });
}

void begin() {
    if (state != OFF) return;
    deriveKey();
    apName = "Clicker-" + uid.substring(12, 14) + uid.substring(15, 17);
    // No auto-reconnect: joining the old Wi-Fi would move the channel away from PORTAL_CHANNEL (where the
    // waiting boards listen) and could be mistaken for the network typed on the page.
    WiFi.setAutoReconnect(false);
    WiFi.disconnect(false, false);
    WiFi.persistent(false);  // a Wi-Fi typed here is tried first; only a working one replaces the saved one
    WiFi.mode(WIFI_STA);
    scanNetworks();
    WiFi.mode(WIFI_AP_STA);
    WiFi.softAP(apName.c_str(), nullptr, PORTAL_CHANNEL);
    dns.start(53, "*", WiFi.softAPIP());
    setupRoutes();
    web.begin();
    if (esp_now_init() == ESP_OK) esp_now_register_recv_cb(onShare);
    received = false; resultOk = false; resultText = "";
    state = WAITING; stateAt = millis();
    message("Setup Wi-Fi: " + apName);
}

void stopServers() {
    web.stop();
    dns.stop();
    WiFi.softAPdisconnect(true);
}

// Leave the setup mode and join Wi-Fi: the new one (saved to flash) or, when cancelled, the saved one.
void finish(const char *ssid = nullptr, const char *pass = nullptr) {
    esp_now_deinit();
    stopServers();
    WiFi.mode(WIFI_STA);
    WiFi.persistent(true);
    WiFi.setSleep(false);
    WiFi.setAutoReconnect(true);
    if (ssid) WiFi.begin(ssid, pass); else WiFi.begin();
    state = OFF;
}

void cancel() {
    if (state == OFF) return;
    finish();
    message("Setup cancelled");
}

void startSharing() {
    // Drop the hotspot for a moment and go to the channel the waiting boards listen on.
    stopServers();
    WiFi.setAutoReconnect(false);
    WiFi.disconnect(false, false);
    WiFi.mode(WIFI_STA);
    esp_wifi_set_channel(PORTAL_CHANNEL, WIFI_SECOND_CHAN_NONE);
    esp_now_peer_info_t peer = {};
    memset(peer.peer_addr, 0xFF, 6);
    peer.channel = PORTAL_CHANNEL;
    peer.ifidx = WIFI_IF_STA;
    if (!esp_now_is_peer_exist(peer.peer_addr)) esp_now_add_peer(&peer);
    state = SHARING; stateAt = lastShare = millis();
    message("Sharing Wi-Fi 20s");
}

void loop() {
    const unsigned long now = millis();
    if (state == WAITING || state == CONNECTING || state == RESULT) {
        dns.processNextRequest();
        web.handleClient();
    }
    if (state == WAITING && received) {
        // Another board sent the settings: save them exactly as if typed here.
        saveServer(inbox.server);
        received = false;
        finish(inbox.ssid, inbox.pass);
        message(String("Wi-Fi from other board: ") + inbox.ssid);
    } else if (state == CONNECTING && WiFi.status() == WL_CONNECTED && WiFi.SSID() == pendingSsid) {
        String why;
        resultOk = true;
        bool reached = pendingServer.length() && serverReachable(pendingServer, why);
        String found;
        // Same-Wi-Fi server whose IP changed: the laptop still answers as clicker.local.
        if (!reached && !pendingServer.startsWith("https://") && (found = findClickerLocal()).length() && serverReachable(found, why)) {
            pendingServer = found;
            reached = true;
        }
        if (reached) resultText = "Thành công: đã vào Wi-Fi và gọi được server " + pendingServer + ". Hãy quay lại Wi-Fi của bạn và mở link web.";
        else if (!pendingServer.length()) resultText = "Đã vào Wi-Fi. Chưa có link server — nhập link rồi lưu lại.";
        else resultText = "Đã vào Wi-Fi nhưng không gọi được server " + pendingServer + " (" + why + "). Kiểm tra link và máy chủ có đang bật không.";
        saveServer(pendingServer);
        state = RESULT; stateAt = now;
        message(resultText.startsWith("Thành công") ? "Wi-Fi + server OK" : "Wi-Fi OK, check server");
    } else if (state == CONNECTING && now - stateAt >= PORTAL_CONNECT_MS) {
        WiFi.disconnect(false, false);
        resultOk = false;
        resultText = "Không vào được Wi-Fi \"" + pendingSsid + "\": " + failText(wifiFailReason) + " Sửa rồi lưu lại.";
        state = WAITING; stateAt = now;
        message("Wi-Fi failed, try again");
    } else if (state == CONNECTING && now - lastTry >= 5000 && WiFi.status() != WL_CONNECTED) {
        // Auto-reconnect is off in this mode, so a single refused attempt would otherwise end the try.
        lastTry = now;
        Serial.printf("[setup] still joining: status=%d reason=%u, retry\n", WiFi.status(), wifiFailReason);
        WiFi.begin(pendingSsid.c_str(), pendingPass.c_str());
    } else if (state == RESULT && now - stateAt >= PORTAL_RESULT_MS) {
        if (resultOk) startSharing(); else { state = WAITING; stateAt = now; }
    } else if (state == SHARING) {
        if (now - lastShare >= 200) {
            lastShare = now;
            Shared plain = {};
            strlcpy(plain.ssid, pendingSsid.c_str(), sizeof plain.ssid);
            strlcpy(plain.pass, pendingPass.c_str(), sizeof plain.pass);
            strlcpy(plain.server, pendingServer.c_str(), sizeof plain.server);
            uint8_t packet[PACKET];
            const uint8_t everyone[6] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};
            if (seal(plain, packet)) esp_now_send(everyone, packet, sizeof packet);
        }
        if (now - stateAt >= PORTAL_SHARE_MS) { finish(pendingSsid.c_str(), pendingPass.c_str()); message("Wi-Fi saved, shared"); }
    }
}

bool active() { return state != OFF; }

}  // namespace portal
