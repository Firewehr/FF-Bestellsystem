/**
 * ESP8266: Küchen-Warteschlange für eine Speise-Art abfragen (HTTPS).
 *
 * Filter: grillhuhn | grillwurst | kotelett (Whitelist auf dem Server in api_device.php).
 * Umschalten: optional Taster (siehe FILTER_CYCLE_PIN) oder START_FILTER_INDEX setzen.
 *
 * Bibliotheken: ArduinoJson v6, ESP8266-Board-Paket
 */

#include <ESP8266WiFi.h>
#include <WiFiClientSecure.h>
#include <ESP8266HTTPClient.h>
#include <ArduinoJson.h>
#include <EEPROM.h>

// --- Hier anpassen ---
static const char *WIFI_SSID = "DEIN_WLAN";
static const char *WIFI_PASS = "DEIN_PASSWORT";

static const char *API_HOST = "dein-server.example.com";
static const char *API_PATH = "/api_device.php";
static const char *PRINTER_TOKEN = "DEIN_PRINTER_TOKEN";

/** Start-Filter (0=grillhuhn, 1=grillwurst, 2=kotelett), wenn EEPROM noch leer */
static const int START_FILTER_INDEX = 0;

/**
 * Taster zwischen diesem GPIO und GND (INPUT_PULLUP), kurz drücken = nächster Filter.
 * -1 = keine Taster-Umschaltung (nur EEPROM / Startindex).
 * NodeMCU: z. B. D6 = GPIO12
 */
static const int FILTER_CYCLE_PIN = -1;

static const unsigned long POLL_MS = 30000;
// --- Ende Konfiguration ---

static const char *const FILTER_OPTIONS[] = {"grillhuhn", "grillwurst", "kotelett"};
static const size_t FILTER_COUNT = 3;

static const int EEPROM_ADDR = 0;
static const uint8_t EEPROM_MAGIC = 0xA7;

static int g_filterIndex = 0;
static int g_btnLastRaw = HIGH;
static int g_btnStable = HIGH;
static unsigned long g_btnDb = 0;

WiFiClientSecure secureClient;
HTTPClient https;

static void filter_load() {
  EEPROM.begin(16);
  if (EEPROM.read(EEPROM_ADDR) == EEPROM_MAGIC) {
    uint8_t v = EEPROM.read(EEPROM_ADDR + 1);
    if (v < FILTER_COUNT) {
      g_filterIndex = (int)v;
      return;
    }
  }
  g_filterIndex = START_FILTER_INDEX;
  if (g_filterIndex < 0 || (size_t)g_filterIndex >= FILTER_COUNT) {
    g_filterIndex = 0;
  }
}

static void filter_save() {
  EEPROM.write(EEPROM_ADDR, EEPROM_MAGIC);
  EEPROM.write(EEPROM_ADDR + 1, (uint8_t)g_filterIndex);
  EEPROM.commit();
}

static void filter_next() {
  g_filterIndex = (int)(((size_t)g_filterIndex + 1) % FILTER_COUNT);
  filter_save();
  Serial.printf("Filter gewählt: %s\n", FILTER_OPTIONS[g_filterIndex]);
}

static const char *filter_current() {
  return FILTER_OPTIONS[g_filterIndex];
}

static void filter_poll_button() {
  if (FILTER_CYCLE_PIN < 0) {
    return;
  }
  int r = digitalRead(FILTER_CYCLE_PIN);
  unsigned long now = millis();
  if (r != g_btnLastRaw) {
    g_btnDb = now;
    g_btnLastRaw = r;
  }
  if ((now - g_btnDb) < 50u) {
    return;
  }
  if (r != g_btnStable) {
    if (g_btnStable == HIGH && r == LOW) {
      filter_next();
    }
    g_btnStable = r;
  }
}

void setup_wifi() {
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  Serial.print(F("WLAN verbinden"));
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print('.');
  }
  Serial.println();
  Serial.print(F("IP: "));
  Serial.println(WiFi.localIP());
}

bool fetch_speise_api() {
  secureClient.setInsecure();

  String url = String("https://") + API_HOST + API_PATH + "?action=speise_queue&filter=" +
               String(filter_current());
  https.setTimeout(20000);
  if (!https.begin(secureClient, url)) {
    Serial.println(F("https.begin fehlgeschlagen"));
    return false;
  }

  https.addHeader(F("X-Api-Key"), PRINTER_TOKEN);
  https.addHeader(F("Accept"), F("application/json"));

  int code = https.GET();
  if (code != HTTP_CODE_OK) {
    Serial.printf("HTTP Code: %d\n", code);
    https.end();
    return false;
  }

  String body = https.getString();
  https.end();

  StaticJsonDocument<12288> doc;
  DeserializationError err = deserializeJson(doc, body);
  if (err) {
    Serial.print(F("JSON: "));
    Serial.println(err.c_str());
    return false;
  }

  if (!doc["ok"].as<bool>()) {
    Serial.println(F("API ok=false"));
    return false;
  }

  const char *filterEcho = doc["filter"].as<const char *>();
  Serial.printf("--- Filter: %s ---\n", filterEcho ? filterEcho : filter_current());

  JsonArray next = doc["next_three"].as<JsonArray>();
  Serial.println(F("Nächste 3 Runden (nur Zeilen mit diesem Suchbegriff):"));
  int i = 0;
  for (JsonObject row : next) {
    ++i;
    int plain = row["plain"].as<int>();
    int mitG = row["mit_gebaeck"].as<int>();
    Serial.printf(
        "  #%d Tisch %d | ohne Gebäck: %d | mit Gebäck: %d | Summe: %d | frühste Zeit: %s\n",
        i,
        row["tischnummer"].as<int>(),
        plain,
        mitG,
        row["gesamt"].as<int>(),
        row["zeitstempel_min"].as<const char *>());
  }

  JsonObject tot = doc["totals"];
  int p = tot["plain"].as<int>();
  int mg = tot["mit_gebaeck"].as<int>();
  int ges = tot["gesamt"].as<int>();

  Serial.println(F("Offen gesamt (alle passenden Küchen-Speisenzeilen):"));
  Serial.printf("  ohne Gebäck: %d | mit Gebäck: %d | Summe: %d\n", p, mg, ges);

  JsonArray offen = doc["speisen_offen"].as<JsonArray>();
  int n = offen.size();
  if (n > 0) {
    Serial.printf("Einzelzeilen (%d):\n", n);
    int show = n > 12 ? 12 : n;
    for (int j = 0; j < show; j++) {
      JsonObject z = offen[j];
      Serial.printf(
          "  - %s  (Tisch %d, %s)\n",
          z["positionsname"].as<const char *>(),
          z["tischnummer"].as<int>(),
          z["variant"].as<const char *>());
    }
    if (n > show) {
      Serial.printf("  … %d weitere\n", n - show);
    }
  }

  return true;
}

void setup() {
  Serial.begin(115200);
  delay(200);
  Serial.println();
  filter_load();
  Serial.printf("Aktiver Filter: %s (Taster-Pin %d)\n", filter_current(), FILTER_CYCLE_PIN);
  if (FILTER_CYCLE_PIN >= 0) {
    pinMode(FILTER_CYCLE_PIN, INPUT_PULLUP);
    g_btnLastRaw = digitalRead(FILTER_CYCLE_PIN);
    g_btnStable = g_btnLastRaw;
  }
  setup_wifi();
}

void loop() {
  filter_poll_button();

  static unsigned long lastPoll = 0;
  unsigned long now = millis();
  if (now - lastPoll >= POLL_MS || lastPoll == 0) {
    lastPoll = now;
    Serial.println(F("Abfrage..."));
    if (!fetch_speise_api()) {
      Serial.println(F("Abfrage fehlgeschlagen."));
    }
    Serial.println();
  }
}
