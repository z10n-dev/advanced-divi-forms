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
- [ ] Berechtigte manuelle Prüfung umgeht den eigenen Release-Cache UND stößt einen
      passenden WordPress-Pluginupdate-Check an; POST und Nonce schützen Aktionen.
- [ ] Installierte Version, neueste gültige Release-Version, letzter erfolgreicher
      Check und verständliche Fehlerdiagnose sind im Backend sichtbar.
- [ ] Kein Update bei gleicher/älterer Version; fremde Plugins bleiben unangetastet.
- [ ] Regressionstests für alten Cache/neues Release, Netzwerkfehler, Rate Limit,
      ungültige Antwort, fehlendes ZIP sowie WordPress response/no_update-Pfad.
- [ ] Kein pauschales Deaktivieren des Caches und keine automatische Updatefreigabe.
Dateien: `includes/github-updater.php`, neue Backend-Diagnose nach Bedarf,
`tests/github-updater.php`, zusätzliche WP-Integrationstests und README.

### 2. Suche, Status-/Datumsfilter und konsistenter Excel-Export
- [ ] Suche nach Name, E-Mail oder Formularinhalt; Filter gelesen/ungelesen sowie
      Zeitraum, klare Sortierung und stabile Pagination.
- [ ] Filter bleiben beim Blättern, Quick Edit und Rückkehr zur Liste erhalten.
- [ ] Export unterscheidet explizit ausgewählte Zeilen, alle gefilterten Treffer und
      alle Antworten des Formulars; keine stillschweigende Ausweitung der Auswahl.
- [ ] Leere Treffer, ungültige Datumsbereiche, SQL-Sicherheit und Zugriff über
      Formulargrenzen sind getestet; bestehende Export-Sicherheitslimits bleiben.
Dateien: `includes/admin-menu.php`, `includes/submission-list.php`,
`includes/submission-actions.php`, `includes/submission-export.php`, Tests/README.

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
