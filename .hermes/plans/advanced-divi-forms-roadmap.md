# Advanced Divi Forms — iterative Entwicklungs-Roadmap

## Auftrag und Ziel

Alle sechs mit Z10N besprochenen Verbesserungen in mehreren getesteten Iterationen
im Repository `/home/hermes/work/advanced-divi-forms` implementieren. Das bestehende
Plugin und seine Daten bleiben rückwärtskompatibel. Diese Datei ist der dauerhafte
Goal-Vertrag und die Checkliste für Folgeturns. Fortschritt nur mit tatsächlichen
Testergebnissen markieren, keine simulierten Reviews oder erfundenen Ausgaben.

Ausgangspunkt: Branch `fix/delete-confirmation-alignment`, Commit `1d11b7f` enthält
den vollständig getesteten lokalen Checkbox-Fix 0.5.2. Dieser ist noch NICHT
veröffentlicht. Nicht durch Checkout/Reset verlieren oder versehentlich auslassen.
Vor Arbeitsbeginn git status/fetch prüfen. Für die Roadmap einen lokalen
Integrationsbranch verwenden; Änderungen pro Iteration unabhängig reviewen/committen.
Keine Versionstags, GitHub-Merges, Releases oder Änderungen an einer echten Website
ohne gesonderten Veröffentlichungsauftrag. Nicht anhalten, nur weil ein Release
noch nicht genehmigt ist: lokale Umsetzung und Verifikation sind das Goal.

## Iterationen und prüfbare Akzeptanzkriterien

### 1. Updater transparenter und manuelles Prüfen zuverlässig
- [x] Berechtigte manuelle Prüfung umgeht den eigenen Release-Cache UND stößt einen
      passenden WordPress-Pluginupdate-Check an; POST und Nonce schützen Aktionen.
- [x] Installierte Version, neueste gültige Release-Version, letzter erfolgreicher
      Check und verständliche Fehlerdiagnose sind im Backend sichtbar.
- [x] Kein Update bei gleicher/älterer Version; fremde Plugins bleiben unangetastet.
- [x] Regressionstests für alten Cache/neues Release, Netzwerkfehler, Rate Limit,
      ungültige Antwort, fehlendes ZIP sowie WordPress response/no_update-Pfad.
- [x] Kein pauschales Deaktivieren des Caches und keine automatische Updatefreigabe.
Dateien: `includes/github-updater.php`, neue Backend-Diagnose nach Bedarf,
`tests/github-updater.php`, zusätzliche WP-Integrationstests und README.

Beleg Iteration 1: Commit `dd66b3714abb9de0a2d6def22da65371fb571ccf` (0.6.0).
TDD rot: neue WP-Suite scheiterte zuerst am fehlenden Fresh-Check-API, danach am
fehlenden POST-Handler. Grün: alle neun WP-Suiten aus dem Smoke-Skript gegen den
installierten ZIP; PHP-Syntax, PHPCS, Version, Updater, Divi-JS, JS-Syntax und
`git diff --check`. Vollständige Ausgabe: Scratch `adf-test/updater-verification.txt`.
Chromium: neue Updaterprüfung mit echter anonymer GitHub-Antwort 0.5.1,
Cache-Umgehung und GET-Ablehnung; Desktop/Tablet/Mobil 1280/768/390px.
Alle drei Bestands-Browsersuiten grün, XLSX-Downloads mit openpyxl gegengelesen;
Checkbox-Fix erhalten. Unabhängiges Read-only-Review ohne Befunde, Tool-Aufrufe
im Delegationstranskript geprüft (`deleg_564440f5`). SQLite, kein MySQL-Nachweis.
ZIP-Dateien stimmen bytegenau mit installiertem Plugin und Source überein;
SHA256 `144f94d8a3edf2dd154f1f6a9124651591751468c660f2d920cdb683ab6f3167`.
Keine Veröffentlichung.

### 2. Suche, Status-/Datumsfilter und konsistenter Excel-Export
- [x] Suche nach Name, E-Mail oder Formularinhalt; Filter gelesen/ungelesen sowie
      Zeitraum, klare Sortierung und stabile Pagination.
- [x] Filter bleiben beim Blättern, Quick Edit und Rückkehr zur Liste erhalten.
- [x] Export unterscheidet explizit ausgewählte Zeilen, alle gefilterten Treffer und
      alle Antworten des Formulars; keine stillschweigende Ausweitung der Auswahl.
- [x] Leere Treffer, ungültige Datumsbereiche, SQL-Sicherheit und Zugriff über
      Formulargrenzen sind getestet; bestehende Export-Sicherheitslimits bleiben.
Dateien: `includes/admin-menu.php`, `includes/submission-list.php`,
`includes/submission-actions.php`, `includes/submission-export.php`, Tests/README.

Beleg Iteration 2: Commit `4c924b087164cea3273576daf8686e59b0b6e34d` (0.7.0).
TDD rot/grün für Suche, Legacy-Ungelesen/Gelesen, Datumsgrenzen/-validierung,
stabile Sortierung, Listen-/Detail-/Quick-Edit-Kontext und gefilterten Export.
Browser zuerst rot bei leerer selected-Auswahl (HTTP 200 statt 400), leerer letzter
Seite und Label-Abständen; nach Implementierung alle Prüfungen grün.
`python3 .../adf-test/verify-adf.py --browser`: alle zehn WP-Suiten aus dem
Smoke-Skript, PHP-Syntax, PHPCS, Version, Updater, Divi-/Submission-JS und Diff grün.
Vier Chromium-Suiten gegen installierten ZIP: openpyxl bestätigt exakt 25 gefilterte,
30 gesamte und 2 ausgewählte Datensätze; Formulargrenzen und ungültige/mehrdeutige
Exports HTTP 400, Navigation/Quick Edit/Detail und drei Viewports geprüft.
Unabhängiges Review neuer Filtermodule (`deleg_52e34b9c`) enthielt widersprüchliche
Schweregrade, aber keinen reproduzierbaren Angriff. Ergänzendes Controller-Review
`deleg_2fecd61e` prüfte den tatsächlichen Working-Diff und fand keine Befunde;
Tool-Transkripte gegengelesen. Belegdatei: Scratch `adf-test/filter-verification.txt`.
15 ZIP-Dateien bytegenau gleich Source und Installation. SHA256
`f47c9757eb17e9c7fa804ab17a8e234c15d4986f87817aa2814333480e59fa94`.
Testumgebung jetzt SQLite AST: alter Legacy-Treiber überschreibt `esc_like()` als
No-op und verfälscht Literal-%/_-Tests. Aktivierung des vorhandenen AST-Treibers nur
in eigener Wegwerfinstallation korrigiert dies; kein Produktions-Workaround im
Plugin. Weiterhin kein MySQL-/lizenzierter Divi-/Live-SMTP-Nachweis, kein Release.

### 3. Wiederherstellbarer Papierkorb
- [ ] Normales Löschen in Liste, Detail und Mehrfachaktion verschiebt in Papierkorb.
- [ ] Papierkorb separat ansehen und einzelne/mehrere Einsendungen wiederherstellen.
- [ ] Endgültige Löschung ist eine getrennte, ausdrücklich bestätigte Aktion;
      Wiederherstellen bewahrt Originalfelder, Notizen, Status und Formularbezug.
- [ ] Standardliste und Standardexport schließen Papierkorb aus; Zähler und Filter
      stimmen. Bestehende Datensätze werden nicht automatisch gelöscht.
- [ ] WordPress-Core-Autobereinigung darf die vereinbarte explizite Löschgrenze nicht
      unbemerkt umgehen; Verhalten dokumentieren und testen.
Dateien: Submission-Controller/Abfragen/UI/Export und neue Tests, keine Live-Daten.

Zwischenstand Iteration 3 (noch NICHT abgehakt/committed):
- Neue `includes/submission-trash.php` und `tests/submission-trash.php`.
  `trash`/`restore` erhalten Felder, Notiz, Lesestatus und Formularbezug;
  Restore erzwingt private statt WordPress-Default draft.
- Normales Verschieben bleibt auch bei `EMPTY_TRASH_DAYS=0` wiederherstellbar.
  `pre_delete_post` blockiert automatische Core-Löschung eigener Trash-Antworten,
  lässt andere CPTs und explizites force=true aus bestätigten Server-Aktionen in Ruhe.
- Permanente Aktion verlangt Trash-Status und Bestätigung; direkte Detailaktionen
  sind POST/Nonce-geschützt, exakt eine Aktion, zentrale Status-/Formularvalidierung.
- Neue validierte `adf_view=active|trash`, getrennte Listen/Detail-/Restore-/Delete-
  Controls, formularbezogene Totals; normale Exporte ohne Trash, explizite Trash-
  Exporte möglich. Alter Quick Edit erscheint nicht im Trash.
- TDD jeweils rot/grün: Kernfunktionen, Core-Autobereinigung, Status-/Bestätigungs-
  Grenze, View/Export, Controller und Detail-Routing. Reales Chromium:
  `tests/submission-trash-browser.py` bestätigt Einzel-Trash/Restore, ID/Datum/Zähler.
- Zuletzt grün: PHPCS, `git diff --check`, `tests/submission-controller.php`,
  `tests/submission-trash.php` normal UND mit
  `wp --exec='define("EMPTY_TRASH_DAYS",0);' eval-file tests/submission-trash.php`.
- Inzwischen vollständig grün gegen installierten 0.8.0-ZIP:
  `python3 .../adf-test/verify-adf.py --browser`: ELF WP-Suiten plus Extra-Lauf
  mit EMPTY_TRASH_DAYS=0, PHP-Syntax, PHPCS, Version, Updater, Divi-/Submission-JS,
  Diff und FÜNF Chromium-Suiten mit openpyxl, Single/Bulk-Restore/Permanent Delete,
  Trash-Export, Standardexport-Ausschluss und 1280/768/390px. Checkbox-Fix erhalten.
- Early-load-Routenschutz behebt echtes HTTP200-FehlerHTML nach Adminheader;
  fehlende/gelöschte Antworten liefern jetzt tatsächlich HTTP404. Wiederherstellen
  und permanente Löschbestätigung sind auch mobil mit gemessenem Abstand getrennt.
- `dist/advanced-divi-forms.zip`: 0.8.0, 16 Dateien bytegenau Source/Installation,
  KEIN Symlink. SHA256
  `e565c7a8bdd3d58401851d99f5115d9d31997d6c276a78e732278a7b4960ee37`.
  Ausgabe: Scratch `adf-test/trash-verification.txt`. Eigene Fixtures bereinigt,
  Site wieder wordpress.test, SQLite AST; kein MySQL-Nachweis und kein Release.
- OFFEN: Unabhängiges Review `deleg_66aff518` übernehmen/verifizieren, Commit und
  finale Abhakung. Implementierung/Regressionen/ZIP sind geprüft, noch uncommitted.
- Read-only-Vorbereitung für Iteration4: `deleg_021f962b` (Ergebnis noch nicht
  übernommen). Keine Veröffentlichung, keine Produktivdaten berührt.

### 4. Template-Vorschau und Platzhalterhilfe
- [ ] Vorschau mit frei eingegebenen Testdaten, ohne irgendeine Mail zu versenden.
- [ ] Verfügbare Feld-Platzhalter und `{{form.summary}}` im Editor sichtbar und
      einfügbar; fehlende/unbekannte IDs verständlich anzeigen.
- [ ] Vorschau führt weiterhin weder Skripte, PHP, Shortcodes noch externe Requests
      aus. Testdaten werden nicht als echte Einsendung gespeichert.
- [ ] Rendering bleibt escaped, nicht rekursiv, Body-/Attributgrenzen bleiben sicher.
Dateien: `includes/mail-templates.php`, Editor-Assets und Tests/README.

### 5. Lesbare Feldbeschriftungen mit historischem Snapshot
- [ ] Beim Eingang stabile Feld-ID plus verfügbare Beschriftung separat speichern;
      originale `_adf_fields` und der bestehende API-Vertrag bleiben erhalten.
- [ ] Einsendungsliste/-detail und Excel zeigen Beschriftungen, IDs bleiben sichtbar
      oder zugänglich; doppelte Beschriftungen führen nicht zu Spaltenvermischung.
- [ ] Spätere Umbenennung im Formular verändert historische Antworten nicht.
- [ ] Alte Datensätze ohne Label-Snapshot funktionieren unverändert mit ID-Fallback.
Dateien: `includes/form-data.php`, `includes/divi-integration.php`, UI/Export/Tests.

### 6. Datenschutz, Aufbewahrung und gezielte Berechtigungen
- [ ] Aufbewahrungsfristen explizit konfigurierbar und standardmäßig AUS; Aktivierung
      und Folgen klar beschrieben. Kein automatisches Löschen ohne konfigurierte
      Einwilligung. Tests nur mit synthetischen Wegwerfdaten.
- [ ] Integration in WordPress-Personendatenexport und -löschung mit geprüfter,
      eindeutiger E-Mail-Zuordnung; Fremddaten nie aufgrund einer Vermutung zuordnen.
      Legacy-Fälle ohne sichere Zuordnung transparent behandeln.
- [ ] Eigene Berechtigung zum Lesen/Bearbeiten/Exportieren von Einsendungen, ohne
      vollständige Administratorrechte vorauszusetzen; Vergabe nur durch Admins.
- [ ] Template-HTML, Plugin-Einstellungen und Berechtigungsvergabe bleiben streng
      getrennt geschützt. Keine stillschweigende Vergabe an Editoren/Autoren.
- [ ] Upgrade erhält bisherigen Adminzugriff; direkte Handler, Exporte, Vorschau,
      Formulargrenzen und Rollenmatrix mit positiven/negativen Tests absichern.
Dateien: neue Privacy/Retention/Capabilities-Module, bestehende Zugriffskontrollen,
Bootstrap/ggf. Upgrade-Hooks, Tests und Dokumentation.

## Arbeitsweise je Iteration

1. Relevante Skills laden; Istzustand und Datenfluss lesen, enge Reproduktion bzw.
   fehlenden Akzeptanztest erstellen und zuerst rot ausführen.
2. Kleinsten vertikalen Teil implementieren und grün testen; keine unabhängigen
   Features quer in einem Riesenpatch vermischen.
3. Relevante Regressionen und reale WP-Integration ausführen; UI in Chromium mit
   Desktop-/Tablet-/Mobilansichten prüfen. Tatsächliche Ausgaben belegen Fortschritt.
4. Unabhängiges Review beauftragen, dessen Tool-Evidenz prüfen, bestätigte Befunde
   beheben. Review-Ergebnis nicht als Browserbeweis ausgeben.
5. Klaren Commit auf dem lokalen Roadmap-Branch erstellen. Kurz im Chat berichten:
   umgesetzt, real geprüft, verbleibend. Checkliste aktualisieren, dann nächste
   ausführbare Iteration beginnen, ohne einen neuen Nutzerauftrag abzuwarten.

## Verifikation und Belege

- PHP: `/home/hermes/tools/php-adf/php`; Tool-PATH bei Bedarf ergänzen.
- `.ci/vendor/bin/phpcs --standard=phpcs.xml.dist`
- `bash scripts/check-version.sh`
- `php tests/github-updater.php`
- `node tests/divi-settings.js` und `node --check assets/submissions.js`
- Alle WP-CLI-Suiten aus `scripts/test-wordpress-activation.sh` gegen eine
  WEGWERF-Installation ausführen und um die neuen Suiten erweitern.
- `tests/submissions-browser.py`, `tests/submissions-layout-browser.py`,
  `tests/delete-confirmation-browser.py` und die neuen UI-Prüfungen nach Bedarf
  mit frischen synthetischen Fixtures ausführen. Downloads mit openpyxl gegenlesen.
- Installierbares ZIP mit `scripts/build-plugin.sh` in sauberem dist erzeugen,
  ohne Source-Symlink überschreiben zu lassen installieren und erneut testen.
- Lokale SQLite-Prüfung ersetzt keinen MySQL-Test. Nach Möglichkeit zusätzlich
  isolierte MySQL-Prüfung ausführen; sonst diese Einschränkung ehrlich dokumentieren.
- Zuletzt vollständige Tests, Syntax/Coding-Standards, Review und konsistentes ZIP
  aller sechs Iterationen. `git diff --check` und saubere Arbeitskopie bestätigen.
- Belege/temporäre Fixtures nur im konfigurierten Scratch-Bereich ablegen; in dieser
  Checkliste echte Testkommandos, Ergebnisse und Commit-IDs kurz referenzieren.

## Grenzen und Pausenbedingungen

Nur dieses Repository und eigene Wegwerf-Testdateien/-prozesse im Scratch-Bereich
ändern. Keine anderen Hermes-Profile, produktiven Websites/Datenbanken, fremden
Repositories, Host-/Proxy-Konfigurationen oder bereits belegten Dienste ändern.
Keine echten Mails versenden, keine Geheimnisse in Repository/Chat ablegen, keine
neuen kostenpflichtigen Dienste/Accounts oder erhöhte Berechtigungen beschaffen.
Bestehende stabile APIs und Originaldaten bewahren; Datenschutzfunktionen nicht
als Rechtsberatung oder pauschale Compliance-Garantie darstellen.

Pausieren mit konkretem Grund, wenn eine nötige Nutzerentscheidung, Zugangsdaten,
Kostenfreigabe, produktiver Eingriff oder nicht sicher verifizierbare/destruktive
Migration erforderlich wird. Nicht beliebig weiterprobieren oder Ergebnisse
simulieren. Sonst lokal weiterarbeiten. Benutzer-Steuerung hat immer Vorrang.

## Abschluss

Fertig erst, wenn alle sechs Bereiche implementiert, integriert, getestet,
reviewt, dokumentiert und als installierbares ZIP geliefert sind. Verbleibende
Lizenz-Divi-/SMTP-/Live-Stagingprüfungen ausdrücklich benennen. Veröffentlichung
ist KEIN Abschlusskriterium und erfordert einen separaten Auftrag.
