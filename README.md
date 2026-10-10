# SymconEnigma2

IP-Symcon-Modul für **Enigma2-Receiver**, also Dreambox, Vu+, Gigablue und
andere. Es steuert den Receiver über die Web-API seines Webinterface
(`/web/…`). Diese Schnittstelle bieten OpenWebIF und das DreamOS-Webinterface
gleichermaßen an.

Das Modul kann:

- den Receiver aufwecken und in den Standby schicken
- den Zustand abfragen: Standby, eingeschaltet, nicht erreichbar
- den laufenden Sender, die Sendung und die Lautstärke anzeigen
- Lautstärke und Stummschaltung setzen
- Tasten der Fernbedienung senden, auf einen Sender umschalten und Meldungen
  auf dem Fernseher anzeigen

## Voraussetzungen

- IP-Symcon ab 8.0, getestet mit 9.0 auf einer SymBox
- Enigma2-Receiver mit Webinterface, getestet mit einer Dreambox DM7080
  (DreamOS, Webinterface 1.9.0)

Das Modul braucht nur die Endpunkte unter `/web/`. Die JSON-Schnittstelle
`/api/` von OpenWebIF nutzt es nicht, denn das DreamOS-Webinterface kennt sie
nicht.

## Einrichtung

1. In der **Modulsteuerung** (Kern-Instanzen → Modules) diese URL hinzufügen:
   `https://github.com/HeikoSchroers/SymconEnigma2`
2. Eine Instanz **Enigma2 Device** anlegen.
3. Adresse, Port (meist 80), Benutzer und Passwort des Webinterface
   eintragen. Ist im Webinterface keine Anmeldung eingeschaltet, bleibt der
   Benutzer leer.
4. **Geräteinfo** im Formular zeigt Modell und Versionen. Damit ist die
   Verbindung geprüft, ohne etwas zu schalten.

Eine feste Adresse für den Receiver im Router ist ratsam.

**Kein Konfigurator.** Enigma2-Receiver melden ihr Webinterface nicht
einheitlich im Netz. Die geprüfte Dreambox kündigt per mDNS nur SSH, SFTP und
SMB an, per SSDP nur ihren DLNA-Dienst. Andere Images machen es wieder
anders. Eine Suche würde deshalb Geräte übersehen oder fremde finden. Die
Adresse wird von Hand eingetragen.

## Zugangsdaten

Das Passwort steht als Eigenschaft in der Instanz. Das Formular zeigt es
verdeckt. In Sicherungen der Symcon-Einstellungen steht es allerdings mit
drin.

Das Modul gibt Benutzer und Passwort nur als HTTP-Basic-Anmeldung an curl
weiter. Sie erscheinen nie in einer URL, im Debug-Fenster oder in einer
Meldung.

Basic-Anmeldung über HTTP ist im Netz mitlesbar. Wer das Webinterface per
HTTPS erreicht, setzt den Haken **HTTPS**. Das Zertifikat prüft das Modul
dann nicht, weil Receiver selbst signierte Zertifikate haben.

## Variablen

| Variable | Typ | Bedeutung |
|---|---|---|
| Eingeschaltet | Schalter | Ein weckt den Receiver, Aus schickt ihn in den Standby |
| Zustand | Auswahl | nicht erreichbar, Standby, eingeschaltet |
| Sender | Text | laufender Sender, leer im Standby |
| Sendung | Text | laufende Sendung laut EPG |
| Lautstärke | Schieberegler 0–100 | setzt die Lautstärke des Receivers |
| Stumm | Schalter | Ton aus bzw. wieder an |

Die Abfrage läuft ab Werk alle 5 Sekunden, mindestens alle 2. Sender und
Lautstärke fragt sie nur ab, wenn der Receiver eingeschaltet ist. Die
Variablen schreibt sie nur, wenn sich ein Wert ändert.

**Fehler schalten nie.** Antwortet der Receiver nicht oder mit einem Fehler,
geht **Zustand** auf „nicht erreichbar“, und **Eingeschaltet** bleibt, wie
es war. Ein Netz- oder Anmeldefehler gilt also weder als „an“ noch als „aus“.

**Ruhezeit.** Nach dem Aufwecken meldet der Receiver noch einige Sekunden
„Standby“. Ohne Gegenmaßnahme spränge der Schalter kurz zurück, und die
HomeKit-Bridge sähe ein Aus. Nach einem Schaltbefehl lässt die Abfrage
**Eingeschaltet** und **Zustand** deshalb für die eingestellte Ruhezeit
stehen, ab Werk 15 Sekunden.

## Funktionen

| Funktion | Bedeutung |
|---|---|
| `E2_SetPower($id, true)` | aufwecken; `false` schickt in den Standby |
| `E2_GetState($id)` | 2 eingeschaltet, 1 Standby, 0 nicht erreichbar (Antwort des Receivers, auch in der Ruhezeit) |
| `E2_SendKey($id, $Taste)` | Taste der Fernbedienung, Linux-Tastencode (Tabelle unten) |
| `E2_SetVolume($id, 40)` | Lautstärke 0–100 |
| `E2_SetMute($id, true)` | stumm; `false` Ton wieder an |
| `E2_Zap($id, $Referenz)` | auf einen Sender umschalten (Service-Referenz) |
| `E2_ShowMessage($id, 'Text', 10)` | Meldung für 10 Sekunden auf dem Fernseher |
| `E2_GetCurrent($id)` | laufender Sender als `['Sender', 'Referenz', 'Sendung']` |
| `E2_GetInfo($id)` | Modell, Enigma-, Image- und Webinterface-Version |
| `E2_Update($id)` | Zustand abfragen und Variablen setzen |

Die Funktionen liefern `true` bzw. den Wert bei Erfolg. Bei einem Fehler
melden sie eine Warnung und liefern `false`, 0 oder ein leeres Array.
`E2_Update` meldet keine Warnung, damit ein ausgeschalteter Receiver das Log
nicht füllt.

Die Service-Referenz eines Senders liefert `E2_GetCurrent`, während er läuft.

### Tastencodes

| Taste | Code | Taste | Code |
|---|---|---|---|
| Lauter / Leiser / Stumm | 115 / 114 / 113 | Hoch / Runter | 103 / 108 |
| Kanal + / − | 402 / 403 | Links / Rechts | 105 / 106 |
| Bouquet + / − | 407 / 412 | OK | 352 |
| Menü / Exit | 139 / 174 | Info / EPG | 358 / 365 |
| Rot / Grün / Gelb / Blau | 398 / 399 / 400 / 401 | TV / Radio | 377 / 385 |
| Text / Audio / Video | 388 / 392 / 393 | Hilfe | 138 |
| Wiedergabe / Pause / Stopp | 207 / 119 / 128 | Zurück- / Vorspulen | 168 / 208 |
| Aufnahme | 167 | Ziffern 1–9 / 0 | 2–10 / 11 |
| Power (Standby umschalten) | 116 | | |

## Eigenheiten

- Befehle gehen per POST, Abfragen per GET. Manche Webinterfaces verlangen
  für Befehle eine Sitzungskennung und antworten sonst mit HTTP 412. Dann
  holt das Modul die Kennung von `/web/session` und wiederholt den Befehl
  einmal. Die DM7080 braucht das nicht, deshalb ist dieser Weg am Gerät
  nicht geprüft.
- Im **Deep Standby** ist der Receiver aus und das Webinterface nicht
  erreichbar. **Zustand** zeigt dann „nicht erreichbar“, und das Modul kann
  ihn nicht wecken.
- Die Stummschaltung kennt im Webinterface nur „umschalten“. `E2_SetMute`
  fragt deshalb erst den Zustand ab und schaltet nur, wenn nötig.

## Lizenz

MIT, siehe [LICENSE](LICENSE).
