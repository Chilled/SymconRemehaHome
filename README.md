# Remeha Home (eTwist) für IP-Symcon

Integration einer Remeha-Gastherme mit eTwist-Raumthermostat über die Cloud-API der
Remeha-Home-App. Login-Ablauf, Endpunkte und Moduslogik sind an die Home-Assistant-
Integration [msvisser/remeha_home](https://github.com/msvisser/remeha_home) angelehnt.

## Voraussetzungen

- IP-Symcon ab 6.0
- eTwist ist mit der Remeha-Home-App verbunden (Konto mit E-Mail und Passwort)
- Internetzugang des Symcon-Servers

## Installation

1. Ordner `SymconRemehaHome` als Git-Repository bereitstellen und im Module Control
   hinzufügen – oder direkt nach `<Symcon>/modules/SymconRemehaHome` kopieren.
2. Instanz **RemehaHome** (Hersteller „Remeha“) anlegen.
3. E-Mail und Passwort des Remeha-Home-Kontos eintragen und übernehmen.

Bei mehreren Zonen zeigt „Zonen anzeigen“ die IDs an. Die gewünschte ID in
„Klimazonen-ID“ eintragen und für jede weitere Zone eine eigene Instanz anlegen.

## Variablen

| Bereich | Variable | Schaltbar |
|---|---|---|
| Klimazone | Raumtemperatur, Solltemperatur | Sollwert ✔ |
| | Modus (Zeitprogramm / Manuell / Aus / Temporär) | ✔ |
| | Zeitprogramm 1–3, Kaminmodus | ✔ |
| | Wärmeanforderung, aktueller/nächster Programm-Sollwert, nächster Schaltzeitpunkt | – |
| Therme | Online, Wasserdruck, Außentemperatur (Gerät/Cloud), Gerätestatus, Betriebszustand | – |
| Warmwasser* | Temperatur, aktueller Sollwert, Warmwasserbereitung | – |
| | Modus (Zeitprogramm / Komfort / Eco), Komfort- und Eco-Sollwert | ✔ |
| Energie* | Verbrauch und Wärmeabgabe Heizung/Warmwasser heute (kWh) | – |

\* abschaltbar in der Konfiguration

Das Verhalten entspricht der HA-Integration: Wird die Solltemperatur im Zeitprogramm
geändert, setzt das Modul eine temporäre Übersteuerung bis zum nächsten Schaltpunkt.
Im manuellen Modus wird der feste Sollwert geändert. Im Modus „Aus“ (Frostschutz)
lässt sich kein Sollwert setzen.

## PHP-Befehle

```php
RMH_Update(int $InstanceID): bool;
RMH_SetTemperature(int $InstanceID, float $Temperature): bool;
RMH_SetMode(int $InstanceID, int $Mode);          // 0 Zeitprogramm, 1 Manuell, 2 Aus, 3 Temporär
RMH_SetTimeProgram(int $InstanceID, int $Program); // 1–3
RMH_SetFireplaceMode(int $InstanceID, bool $Active);
RMH_SetHotWaterMode(int $InstanceID, int $Mode);   // 0 Zeitprogramm, 1 Komfort, 2 Eco
RMH_SetHotWaterComfortSetpoint(int $InstanceID, float $Temperature);
RMH_SetHotWaterReducedSetpoint(int $InstanceID, float $Temperature);
RMH_ListZones(int $InstanceID): string;
RMH_ResetLogin(int $InstanceID);
```

## Hinweise

- Die API ist inoffiziell (aus dem App-Datenverkehr ermittelt) und kann sich ändern.
- Läuft der Refresh-Token ab, meldet sich das Modul mit den gespeicherten Zugangsdaten
  automatisch neu an. Die HA-Integration verlangt dafür eine erneute Anmeldung.
- Ein Abfrageintervall unter 60 Sekunden ist nicht zu empfehlen.
- Fehlersuche: Im Debug-Fenster der Instanz erscheinen alle API-Aufrufe und Antworten.
