#include <Arduino.h>
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SH110X.h>

// Moi nut: 3V3 -- 10k -- GPIO -- nut -- GND. OLED: SDA21, SCL22.
// Cau hinh cu (chi de doi chieu, khong chay):
// pins[]  = {34, 35, 32, 23, 25, 26, 27, 14, 12, 13, 19, 18};
// names[] = {"0", "1", "2", "3", "4", "5", "6", "7", "8", "9", "BACK", "OK"};
const uint8_t pins[] = {34, 35, 32};
const char *const names[] = {"1", "BACK", "OK"};
constexpr size_t keyCount = sizeof(pins) / sizeof(pins[0]);
uint8_t raw[keyCount], stable[keyCount];
unsigned long changedAt[keyCount];
Adafruit_SH1106G screen(128, 64, &Wire, -1);
bool oledReady = false;

void showKey(const char *name, int pin) {
  if (!oledReady) return;
  screen.clearDisplay();
  screen.drawRect(0, 0, 128, 64, SH110X_WHITE);
  screen.setTextColor(SH110X_WHITE);
  screen.setTextSize(1);
  screen.setCursor(6, 5);
  screen.print("KEYPAD TEST");
  screen.drawFastHLine(4, 17, 120, SH110X_WHITE);
  screen.setTextSize(2);
  screen.setCursor(6, 23);
  screen.print(name);
  screen.setTextSize(1);
  screen.setCursor(6, 52);
  if (pin >= 0) { screen.print("GPIO "); screen.print(pin); }
  else screen.print("Press a button");
  screen.display();
}

void setup() {
  Serial.begin(115200);
  Serial.println("Keypad setup start");
  Wire.begin(21, 22);
  Wire.setTimeOut(20);
  Serial.println("I2C started");
  uint8_t address = 0;
  for (uint8_t candidate : {0x27, 0x3C, 0x3D, 0x3F}) {
    Wire.beginTransmission(candidate);
    if (Wire.endTransmission() == 0) {
      Serial.printf("I2C found at 0x%02X\n", candidate);
      if (candidate == 0x3C || candidate == 0x3D) address = candidate;
    }
  }
  if (address) oledReady = screen.begin(address, true);
  Serial.printf(oledReady ? "OLED found at 0x%02X\n" : "OLED not responding on SDA21/SCL22\n", address);
  for (size_t i = 0; i < keyCount; ++i) {
    pinMode(pins[i], INPUT);
    raw[i] = stable[i] = digitalRead(pins[i]);
    changedAt[i] = millis();
  }
  showKey("READY", -1);
  Serial.println("Keypad test ready");
}

void loop() {
  const unsigned long now = millis();
  for (size_t i = 0; i < keyCount; ++i) {
    const uint8_t level = digitalRead(pins[i]);
    if (level != raw[i]) { raw[i] = level; changedAt[i] = now; }
    if (level != stable[i] && now - changedAt[i] >= 35) {
      stable[i] = level;
      if (level == LOW) {
        showKey(names[i], pins[i]);
        Serial.printf("Pressed %s (GPIO %u)\n", names[i], pins[i]);
      }
    }
  }
  delay(5);
}
