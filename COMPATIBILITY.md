# HumHub-Kompatibilität 2.5.0-beta.3

Stand: 30.10.2026. Ziel: HumHub CE 1.18.6 und 1.19.3, PHP 8.2+.
Die bisherige Mindestversion bleibt erhalten; die obere Grenze ist bewusst auf 1.19.x gesetzt.

Verschieben/Umwandeln unterstützt beide Kommentarstrukturen; native Likes, RecordMap, Aktivitäten, Anhänge und eigene Emoji-Reaktionen bleiben zugeordnet.

Geprüft in getrennten frischen HumHub-/MariaDB-Installationen mit den offiziellen Paketen 1.18.6 und 1.19.3: Modulaktivierung/Migrationen, Laden der Modulklassen und Rendern der Verwaltungs-/Navigationsansichten. Die bestehenden Geschäftsregeln wurden nicht erweitert. Produktive Dateien und Datenbanken wurden nicht geändert.

Zusätzliche Datenbank-Integration:

```sh
HUMHUB_COMPAT_TEST=1 HUMHUB_ROOT=/pfad/zur/testinstallation php tests/compat-runtime.php
```

Nur mit einer wegwerfbaren Testdatenbank ausführen (DSN muss `test` enthalten). Tests verwenden einen vorhandenen lokalen Systemadmin `admin`, Testdaten innerhalb einer zurückgerollten Transaktion und die aktivierten Module `conversations` und `move-content`.

Ein vollständiger Test der bestehenden produktiven Daten, des individuellen Themes und aller übrigen Community-Module ist damit nicht ersetzt. Insbesondere Keycloak und Share Content gehören nicht zu diesem Änderungssatz.
