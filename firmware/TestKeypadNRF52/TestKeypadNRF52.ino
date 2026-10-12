// Bench test of the 3x4 key matrix on the nRF52840 SuperMini, same pins as the StudentTag PCB.
// Rows: input pull-up. Columns: driven LOW one at a time; a pressed key pulls its row LOW.
// Prints each press on Serial 115200 and on the SH1106G OLED taken from the ESP32 (I2C 0x3C).
#include <Arduino.h>
#include <Adafruit_TinyUSB.h>  // USB Serial on the Adafruit nRF52 core (bluefruit.h pulls it in otherwise)
#include <nrf_gpio.h>
#include <Wire.h>
#include <Adafruit_SH110X.h>

// OLED: VCC -> 3.3V, GND -> GND, SDA -> P0.24, SCL -> P1.00. Wire takes Arduino pin numbers of
// the ItsyBitsy nRF52840 variant: D1 = P0.24, D30 = P1.00.
const uint8_t OLED_SDA = 1, OLED_SCL = 30;
Adafruit_SH1106G screen(128, 64, &Wire, -1);
bool oled = false;
int seen = 0;          // distinct keys pressed so far
bool hit[3][4];

#define PIN(port, pin) (((port) << 5) | (pin))

const uint32_t ROW_PINS[3] = {PIN(1, 13), PIN(1, 11), PIN(0, 10)};
const uint32_t COL_PINS[4] = {PIN(1, 15), PIN(0, 2), PIN(0, 29), PIN(0, 31)};
const char *ROW_NAMES[3] = {"P1.13", "P1.11", "P0.10"};
const char *COL_NAMES[4] = {"P1.15", "P0.02", "P0.29", "P0.31"};
const char *KEYMAP[3][4] = {
  {"1",    "2", "3", "4"},
  {"5",    "6", "7", "8"},
  {"BACK", "9", "0", "OK"},
};
const unsigned DEBOUNCE_MS = 30;

bool down[3][4];
unsigned long changedAt[3][4];

// P0.10 is an NFC pad: it ignores GPIO until UICR releases it (written once, then reset).
void releaseNfcPins() {
  if ((NRF_UICR->NFCPINS & UICR_NFCPINS_PROTECT_Msk) != (UICR_NFCPINS_PROTECT_NFC << UICR_NFCPINS_PROTECT_Pos)) return;
  NRF_NVMC->CONFIG = NVMC_CONFIG_WEN_Wen << NVMC_CONFIG_WEN_Pos;
  while (!NRF_NVMC->READY) {}
  NRF_UICR->NFCPINS &= ~UICR_NFCPINS_PROTECT_Msk;
  while (!NRF_NVMC->READY) {}
  NRF_NVMC->CONFIG = NVMC_CONFIG_WEN_Ren << NVMC_CONFIG_WEN_Pos;
  while (!NRF_NVMC->READY) {}
  NVIC_SystemReset();
}

void setup() {
  releaseNfcPins();
  Serial.begin(115200);
  // Open-drain columns, idle released: two keys held on one row cannot short two columns.
  for (uint32_t pin : COL_PINS) {
    nrf_gpio_cfg(pin, NRF_GPIO_PIN_DIR_OUTPUT, NRF_GPIO_PIN_INPUT_DISCONNECT, NRF_GPIO_PIN_NOPULL,
                 NRF_GPIO_PIN_S0D1, NRF_GPIO_PIN_NOSENSE);
    nrf_gpio_pin_set(pin);
  }
  for (uint32_t pin : ROW_PINS) nrf_gpio_cfg_input(pin, NRF_GPIO_PIN_PULLUP);

  Wire.setPins(OLED_SDA, OLED_SCL);
  oled = screen.begin(0x3C, true);
  show("--", "bam 1 phim");
}

// Big key name on top, a status line below.
void show(const char *key, const char *line) {
  if (!oled) return;
  screen.clearDisplay();
  screen.setTextColor(SH110X_WHITE);
  screen.setTextSize(4); screen.setCursor(0, 0); screen.print(key);
  screen.setTextSize(1); screen.setCursor(0, 50); screen.print(line);
  screen.display();
}

void loop() {
  static unsigned long hello = 0;
  const unsigned long now = millis();
  if (now - hello >= 5000) {
    hello = now;
    Serial.printf("[keypad] dang quet 3x4... da bam %d/12 phim, OLED %s\n", seen, oled ? "OK" : "KHONG THAY (kiem tra SDA P0.24 / SCL P1.00)");
  }

  for (int c = 0; c < 4; ++c) {
    nrf_gpio_pin_clear(COL_PINS[c]);
    delayMicroseconds(5);
    for (int r = 0; r < 3; ++r) {
      const bool pressed = !nrf_gpio_pin_read(ROW_PINS[r]);  // LOW = pressed
      if (pressed == down[r][c]) { changedAt[r][c] = now; continue; }
      if (now - changedAt[r][c] < DEBOUNCE_MS) continue;     // must stay changed for 30 ms
      down[r][c] = pressed;
      if (!pressed) continue;
      if (!hit[r][c]) { hit[r][c] = true; ++seen; }
      Serial.printf("Nhan: %-4s  (hang %d %s, cot %d %s)\n",
                    KEYMAP[r][c], r + 1, ROW_NAMES[r], c + 1, COL_NAMES[c]);
      char line[24];
      snprintf(line, sizeof line, "H%d C%d  da bam %d/12", r + 1, c + 1, seen);
      show(KEYMAP[r][c], line);
    }
    nrf_gpio_pin_set(COL_PINS[c]);
  }
  delay(5);
}
