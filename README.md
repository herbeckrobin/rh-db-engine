# RH DB-Engine

Geteilte DB-Engine der rh-blueprint Kollektion. Keine eigenständige Installation, sondern eine Composer-Library, die DB-Plugins (rh-backup, rh-sync) bundeln. Plugins ohne DB-Bezug (z.B. ein Chatbot) ziehen sie nicht und bleiben schlank.

## Inhalt

- **Storage**, verwaltet `wp-content/rh-blueprint-data/{backups,jobs,auto-backups}` mit Guard-Files und Path-Traversal-Schutz.
- **Exporter**, dumpt die Datenbank chunked in ein ZIP (kein `mysqldump`, pure PHP).
- **Importer**, spielt ein ZIP ein, mit optionalem Table-Filter und URL/Prefix-Rewrite.
- **SearchReplace**, serialize-sicheres Replace.

Version-Negotiation wie der Core: mehrere Plugins können die Engine bundeln, die höchste Version gewinnt zur Laufzeit.

## Einbinden

```json
{
    "require": { "rh/db-engine": "^1.0" },
    "repositories": [
        { "type": "vcs", "url": "https://github.com/herbeckrobin/rh-db-engine" }
    ]
}
```

Zugriff über `rh_db_engine()->exporter()` / `importer()` / `storage()`.

## Test

```bash
php tests/negotiation-test.php
```

## Lizenz

GPL-2.0-or-later
