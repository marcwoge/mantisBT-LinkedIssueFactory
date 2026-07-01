# LinkedIssueFactory

Ein MantisBT-2.x-Plugin, das aus einem bestehenden Ticket ein **neues,
verknüpftes Ticket** erzeugt – wahlweise **einmalig** oder **wiederkehrend**.
Das neue Ticket kann in jedem Projekt angelegt werden, in dem der aktuelle
Benutzer Tickets erstellen darf; die Felder werden aus dem Ursprungsticket
vorbefüllt.

> 🇬🇧 An English version of this guide is available in
> [README.md](README.md).

---

## Zweck

In der Detailansicht jedes Tickets erscheint eine Schaltfläche
**„Verknüpftes Ticket erstellen“**. Sie öffnet ein Formular, in dem Sie

* ein **Zielprojekt** wählen (nur Projekte, in denen Sie berechtigt sind),
* die aus dem Ursprungsticket vorbefüllte **Zusammenfassung / Beschreibung /
  Schritte / zusätzliche Informationen** prüfen,
* **Kategorie, Priorität, Schweregrad, Reproduzierbarkeit, Bearbeiter,
  Sichtbarkeit, Fälligkeitsdatum** setzen,
* die **Beziehung** des neuen Tickets zum Ursprung festlegen (Standard:
  *verbunden mit*, nicht *Duplikat*),
* im Zielprojekt gültige **Custom Fields** übernehmen,
* das Ticket **sofort** erstellen oder eine **Wiederholung planen**.

Das Plugin nutzt durchgängig MantisBT-Core-APIs (`IssueAddCommand`,
`relationship_api`, `custom_field_api`, …), **ohne Core-Änderungen** und **ohne
Composer-/npm-Abhängigkeiten**.

---

## Aufbau des Repositories

Das installierbare Plugin liegt in einem eigenen, in sich geschlossenen Ordner;
alles Übrige ist nur Werkzeug zum lokalen Testen.

```
LinkedIssueFactory/     ← das Plugin – DIESEN Ordner nach <mantis>/plugins/ kopieren
docker/                 ← lokale MantisBT-Testumgebung (siehe docker/README.md)
docker-compose.yml
docs/MANUAL_TESTS.md    ← manuelle Testanleitung
```

Das Release enthält zusätzlich ein fertiges Archiv mit **nur** dem
`LinkedIssueFactory/`-Ordner – siehe das
[neueste Release](https://github.com/marcwoge/mantisBT-LinkedIssueFactory/releases/latest).

---

## Voraussetzungen

* MantisBT **2.x** (entwickelt/getestet mit 2.28)
* PHP **7.4+**

---

## Installation

1. Plugin so ins MantisBT-Verzeichnis `plugins/` kopieren, dass die Hauptklasse
   hier liegt:

   ```
   <mantis>/plugins/LinkedIssueFactory/LinkedIssueFactory.php
   ```

2. In MantisBT **Verwaltung → Plugins verwalten** öffnen.
3. Bei **Linked Issue Factory** auf **Installieren** klicken. Die Datenbank­tabelle
   wird über die `schema()`-Methode automatisch angelegt.
4. Unter **Verwaltung → Plugins verwalten → Linked Issue Factory** (bzw. die vom
   Plugin ergänzten *Verwalten*-Menüpunkte) konfigurieren.

Manuelles SQL ist nicht nötig – die Tabelle wird über `schema()` erstellt und
berücksichtigt den konfigurierten DB-Tabellen-Präfix.

---

## Konfiguration

**Verwaltung → Linked Issue Factory → Konfiguration**:

| Option | Beschreibung |
| --- | --- |
| Standard-Beziehungstyp | Beziehung neuer verknüpfter Tickets (Standard *verbunden mit*). |
| Custom Fields standardmäßig übernehmen | Custom Fields aus dem Ursprungsticket vorbefüllen. |
| Standard-Zielprojekt | Optionales vorausgewähltes Zielprojekt (sonst das Quellprojekt). |
| Wiederkehrende Tickets aktivieren | Hauptschalter für die Wiederholungsfunktion und deren Seiten. |
| Standardintervall | Vorgabe für neue Wiederholungen. |
| Technischer Cron-Benutzer | Konto, mit dem der Cron sich anmeldet (sofern nicht per Umgebungsvariable überschrieben). |
| Wiederkehrendes Ticket verknüpfen mit | Verknüpfung mit dem **ursprünglichen Quellticket** oder dem **zuletzt erzeugten** Ticket. |
| Interne Notizen hinzufügen | Querverweis-Notizen im Ursprungs- und im neuen Ticket. |
| Interne Notizen privat | Diese Notizen als privat erstellen. |

Alle Werte werden über `plugin_config_set()` in der Standard-Config-Tabelle
gespeichert, damit auch der CLI-Cron sie lesen kann.

---

## Nutzung aus einem Ticket

1. Beliebiges Ticket öffnen.
2. In der Aktionsleiste **Verknüpftes Ticket erstellen** anklicken.
3. Zielprojekt wählen (das Formular lädt neu und zeigt dessen Kategorien und
   Custom Fields).
4. Die vorbefüllten Felder anpassen.
5. **Ticket jetzt erstellen** – oder **Wiederholung planen…**.

Beim Erstellen legt das Plugin das neue Ticket an, erzeugt die gewählte Beziehung
zum Ursprung, übernimmt die passenden Custom Fields und schreibt (falls
aktiviert) Querverweis-Notizen.

---

## Wiederkehrende Tickets

Eine Wiederholung besteht aus einer gespeicherten **Vorlage** plus einer
**Wiederholungsregel**. Sie wird aus einem Ursprungsticket erstellt
(**Wiederholung planen…**) und in der eigenen Plugin-Tabelle abgelegt. Das
Ursprungsticket wird dabei nie verändert; jeder Lauf erzeugt ein **neues**
Ticket.

Unterstützt: **einmalig, täglich, wöchentlich, monatlich, jährlich** oder frei
**alle *X* Tage/Wochen/Monate/Jahre**. Jede Wiederholung hat ein Startdatum, ein
optionales Enddatum, eine optionale Maximalanzahl und ein Aktiv-/Inaktiv-Flag.

Verwaltung unter **Verwaltung → Linked Issue Factory → Wiederholungen**; die
nächsten geplanten Erstellungen unter **… → Dashboard**.

### Feld-Snapshots

Beim Speichern einer Wiederholung werden die **Standardfelder** und die
**Custom-Field-Werte** des Ursprungstickets als JSON-Snapshot festgehalten. Der
Cron spielt diesen Snapshot ab – die wiederkehrende Erstellung funktioniert also
auch, wenn das Ursprungsticket später geändert oder gelöscht wird. Die Kategorie
wird im Zielprojekt **über den Namen** neu aufgelöst (IDs sind projektbezogen).

---

## Cron einrichten

Die wiederkehrenden Tickets erzeugt ein CLI-Skript:

```
<mantis>/plugins/LinkedIssueFactory/scripts/linked_issue_factory_cron.php
```

Optionen:

| Option | Wirkung |
| --- | --- |
| `--dry-run` | Zeigt nur an, was passieren würde; schreibt nichts. |
| `--schedule-id=<id>` | Verarbeitet nur eine Wiederholung (zum Testen). |
| `--verbose` | Protokolliert auch übersprungene / noch nicht fällige. |
| `--help`, `-h` | Hilfe. |

Das Skript meldet sich als technischer Benutzer an, ermittelt in dieser
Reihenfolge:

1. Umgebungsvariable `LINKED_ISSUE_FACTORY_USER`, sonst
2. konfigurierter **Technischer Cron-Benutzer**, sonst
3. `administrator`.

### Beispiel-Crontab

Täglich um 06:00 als bestimmter technischer Benutzer:

```cron
0 6 * * * LINKED_ISSUE_FACTORY_USER=automation \
  php /var/www/html/plugins/LinkedIssueFactory/scripts/linked_issue_factory_cron.php \
  >> /var/log/linked_issue_factory.log 2>&1
```

Vorschau (Trockenlauf):

```bash
php /var/www/html/plugins/LinkedIssueFactory/scripts/linked_issue_factory_cron.php --dry-run --verbose
```

### Parallelität / keine Duplikate

Jede fällige Ausführung wird **atomar beansprucht**: per bedingtem
`UPDATE … SET next_run_at = <neu> WHERE id = <id> AND next_run_at = <alt>` mit
Prüfung der betroffenen Zeilen. Bei zwei parallel gestarteten Cron-Läufen
gewinnt nur einer – eine Ausführung kann also nie doppelt erzeugt werden. Nach
erfolgreicher Erstellung schreibt der Cron `next_run_at` fort, erhöht
`occurrences_created`, merkt sich `last_created_bug_id` und **deaktiviert** die
Wiederholung, sobald Enddatum oder Maximalanzahl erreicht sind.

---

## Custom-Field-Übernahme

Regeln beim Kopieren von Custom Fields:

1. Ist **dieselbe Custom-Field-ID** im Zielprojekt verknüpft → übernehmen.
2. Sonst: gleicher **Name + kompatibler Typ** im Zielprojekt → übernehmen
   (Strategie *linked_or_name*; einschränkbar auf *linked_only*).
3. Feld im Zielprojekt nicht vorhanden/nicht beschreibbar → überspringen.
4. Wert für das Zielfeld ungültig (z. B. keine erlaubte Auswahloption) →
   überspringen.
5. **Pflicht-Custom-Fields** des Zielprojekts werden im Formular angezeigt und
   müssen vor dem Erstellen ausgefüllt werden.

Validierung und Schreiben laufen über die MantisBT-Custom-Field-APIs, nie über
rohes SQL. Ein ungültiges Zielfeld bringt das Plugin daher nicht zum Absturz –
es wird übersprungen oder gemeldet.

---

## Berechtigungen & Sicherheit

* Sie müssen das Ursprungsticket **sehen** dürfen.
* Sie müssen im Zielprojekt **Tickets erstellen** dürfen
  (`report_bug_threshold`); nur solche Projekte werden angeboten.
* Custom Fields werden nur gelesen/geschrieben, wo Sie die Rechte haben.
* Bearbeiter werden nur angeboten/akzeptiert, wo sie im Zielprojekt Tickets
  bearbeiten dürfen.
* Die Verwaltungsseiten (**Konfiguration**, **Wiederholungen**, **Dashboard**)
  erfordern `manage_plugin_threshold`.
* Alle POST-Formulare sind per CSRF-Token geschützt (`form_security_*`).
* Alle Ausgaben werden HTML-escaped; Benutzereingaben gelangen nie direkt ins
  SQL – parametrisierte Queries für die eigene Tabelle, Core-APIs für alles
  andere.
* Der Cron erstellt Tickets als eigener technischer Benutzer.

---

## Datenbank

Das Plugin legt eine Tabelle an (Standardname beim Präfix `mantis`):

`mantis_linked_issue_factory_schedule` – eine Zeile je Wiederholung, mit
Vorlagentext, Standard-/Custom-Field-Snapshots, Wiederholungsregel, Lauf-Daten
(`next_run_at`, `occurrences_created`, `last_created_bug_id`) und Aktiv-Flag.

Die Tabelle wird über `schema()` erstellt und versioniert; beim Deinstallieren
über den Plugin-Manager wird sie wieder entfernt.

---

## Grenzen

* **Über-/Unterticket** wird über das MantisBT-Paar `BUG_DEPENDANT` /
  `BUG_BLOCKS` abgebildet (MantisBT kennt keinen eigenen Parent/Child-Typ); die
  Bezeichnungen stammen direkt aus den Core-Beziehungsbeschreibungen.
* Die wiederkehrende Erstellung basiert auf dem **Snapshot** beim Speichern. Hat
  das Zielprojekt **Pflicht-Custom-Fields**, die aus dem Snapshot nicht befüllt
  werden können, wird diese Ausführung als Fehler protokolliert und übersprungen
  (die Wiederholung bleibt aktiv und rückt auf den nächsten Termin).
* Der Cron erzeugt nur Ticket, Beziehung und Notizen – keine interaktiven
  Folgeaktionen.
* Die Verwaltung der Wiederholungen ist für Administratoren gedacht
  (`manage_plugin_threshold`).

---

## Lizenz

MIT. Architektonisch am Plugin
[Reveille](https://github.com/marcwoge/reveille) orientiert.
