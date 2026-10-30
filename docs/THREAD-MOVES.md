# Beiträge und Antworten umordnen

Entwicklungsfassung `2.5.0-beta.2`, basierend auf CUZY.APP Move content 2.4.1
einschließlich der Übersetzungsaktualisierungen aus 2.4.2.
Fork: https://github.com/Spotstone-UG/move-content
Entwicklungszweig: `feature/rethread-posts-and-comments`.

## Bedienung

Im Drei-Punkte-Menü eines normalen Beitrags oder einer Antwort erscheint für
Systemadmins und Admins des jeweiligen Spaces **Beitrag / Antwort umordnen**.
Normale Mitglieder und reine Space-Moderatoren dürfen die Funktion nicht nutzen.

1. **Beitrag zur Antwort:** Im Menü von Beitrag A die Funktion öffnen, den
   Permalink von Beitrag B und eine Begründung einfügen, Vorschau anzeigen und bestätigen.
   A erscheint anschließend als Antwort unter B.
2. **Antwort herauslösen:** Im Menü der Antwort „als eigenen Beitrag
   herauslösen“ auswählen, eine Begründung eingeben, Vorschau anzeigen und bestätigen.
3. **Antwort umhängen:** Im Menü der Antwort den Permalink eines anderen
   Beitrags und eine Begründung einfügen, Vorschau anzeigen und bestätigen.

Text einschließlich Markdown, ursprüngliche Autorenschaft, Erstellungs- und
Bearbeitungsdatum, Datei-GUIDs, native Likes und Emoji-Reaktionen bleiben
erhalten. Direkte Antworten ziehen mit. Es werden keine neuen
Veröffentlichungsaktivitäten oder Benachrichtigungen ausgelöst.
Die zeitliche Sortierung richtet sich weiterhin nach dem ursprünglichen Datum.

### Mitziehende Antworten und sichtbarer Verlauf

Aus `Beitrag A → Antwort X` wird beim Verschieben von A unter Beitrag B:
`Beitrag B → Antwort A → Antwort X`. Text, Autor, Zeitstempel, Anhänge und
Reaktionen von X bleiben erhalten. Bestehende Unterantworten bleiben auch beim
Umhängen oder Herauslösen einer Antwort am verschobenen Inhalt.

Jede neue Umordnung benötigt eine Begründung mit höchstens 2000 Zeichen.
Am verschobenen Beitrag bzw. der Antwort erscheint ein kleines gelbes
Warndreieck. Per Klick öffnet sich der **Verschiebeverlauf** in einem Fenster
mit Art der Umordnung, ausführendem Admin, Datum/Uhrzeit, Begründung und
Anzahl mitgezogener Antworten. Autor und Datum der ursprünglichen Nachricht
werden getrennt aufgeführt. Lesbare Zielbeiträge werden verlinkt. Der
Originaltext wird nicht verändert. Das Symbol ist auch per Tastatur bedienbar.

Weitere Umordnungen ergänzen den Verlauf, statt frühere Vermerke zu ersetzen.
Der Verlauf bleibt beim Wechsel zwischen Beitrag und Antwort erhalten und ist
für alle sichtbar, die den zugehörigen Inhalt lesen dürfen. Der lesende
Verlaufs-Endpunkt erfordert keine Adminrolle und prüft stattdessen dieselben
Leserechte wie der Inhalt. Unterantworten
behalten ihre eigenen bisherigen Verläufe; der neue Vermerk steht am bewegten
Hauptinhalt. Frühere Umordnungen ohne erfasste Begründung werden ausdrücklich
so gekennzeichnet. Begründungen werden als Text ausgegeben, nicht als HTML.

## Grenzen der ersten Fassung

- Normale HumHub-Beiträge (`Post`) und Kommentare (`Comment`), keine
  Kalendertermine, Videos oder eigenen Chat-Nachrichten anderer Module.
- Quelle und Ziel müssen im selben Space liegen und dieselbe Sichtbarkeit haben.
- Nur veröffentlichte, nicht archivierte/versteckte Inhalte. Gesperrte
  Diskussionen sind ausgeschlossen.
- Ein Beitrag mit bereits verschachtelten Antworten kann nicht zusätzlich zur
  Antwort werden: HumHub würde die dritte Ebene nicht vollständig anzeigen.
  Die betroffenen Antworten müssen zuerst umgeordnet werden.
- Eigene Themenmarkierungen und angehefteter Status müssen vor einer
  Umwandlung zur Antwort bewusst entfernt werden.
- Ein ursprünglicher Beitrag bleibt intern zunächst als gelöschter Datensatz
  bis zur regulären HumHub-Bereinigung erhalten. Er erscheint nicht mehr im Stream.
- Das Verschiebeprotokoll enthält den vorherigen Text und Metadaten. Es hat
  keinen ungeschützten Lese-Endpunkt. Sichtbar sind nur die Vermerke am lesbaren
  Inhalt, nicht die vollständigen internen Text-Snapshots. Es ist kein
  bedienbarer Rückgängig-Verlauf.

## Technische Umsetzung

Keine HumHub-Core-Änderungen. Menüeinträge über `EVENT_RUN`; POST-Formular mit
CSRF-Schutz und erneuter Berechtigungsprüfung. Die Bestätigung enthält einen
Fingerprint von Quelle und Ziel. Bei zwischenzeitlichen Änderungen muss eine
neue Vorschau erstellt werden.

Konvertierungen und polymorphe Referenzen werden in einer DB-Transaktion
umgestellt. Betroffene Content-Zeilen werden in stabiler Reihenfolge gesperrt.
Anhänge werden einschließlich ihrer `content_id` an den neuen Kontext gebunden,
auch bei mitziehenden Antworten. Optionale Module mit den üblichen
`object_model/object_id`, `content_model/content_id` oder
`source_class/source_pk`-Schlüsseln werden berücksichtigt. Bei nicht
auflösbaren Datenbankkonflikten wird die gesamte Änderung zurückgerollt.
Module mit abweichenden Referenzformaten benötigen einen eigenen Adapter;
diese Beta sollte deshalb vor einem Produktiveinsatz auf der jeweiligen
Installation abgenommen werden.
Gleichzeitiges Schreiben neuer Antworten während einer Umwandlung wurde noch
nicht in einem Mehrprozess-Test geprüft. Für diese Beta nur ruhende Diskussionen
umordnen; dies ist noch keine Freigabe für den produktiven Betrieb.

Alte Beitrags- und Kommentar-Permalinks werden anhand des Verschiebeprotokolls
weitergeleitet. Auch Weiterleitungsketten bei mehrfacher Umwandlung werden
aufgelöst. Der Zielinhalt wird nur nach aktueller Leseberechtigung geöffnet.
Kommentar-Caches und Suchindex werden nach erfolgreichem Verschieben aktualisiert.

## Installation und Test

Nur den vorhandenen Modulordner `move-content` aktualisieren, nicht parallel
zum Original installieren. Die Modul-ID bleibt gleich. Vorher DB und Dateien
sichern. Die Migration `m261030_120000_thread_moves` über HumHubs normalen
Modulmigrationsweg anwenden. Für Beta 2 zusätzlich
`m261030_180000_thread_move_notices` anwenden; bestehende Verschiebungen werden
ihrem aktuellen Ziel zugeordnet, ohne eine Begründung zu erfinden.

Der Integrationstest ist absichtlich auf die dedizierte Testcommunity beschränkt:

```sh
php protected/modules/move-content/tests/thread-move-runtime.php \
  /var/www/vhosts/sexpositiv.events/testcommunity.selbstsein.events
```

Er benötigt HumHub 1.18.5, mindestens einen Administrator, ein normales Mitglied,
einen Space und für die Emoji-Prüfung das installierte Conversations-Modul.
Alle Testbeiträge, Rollenänderungen und Reaktionen werden zurückgerollt.
Die Protokolltabelle wird bei Bedarf angelegt und bleibt für die Testinstallation
erhalten. Der Test ist kein Produktions-Migrationsskript.

Das Original und seine Lizenz-/Urheberhinweise bleiben erhalten. Erweiterungen
stehen unter AGPL-3.0-or-later.
