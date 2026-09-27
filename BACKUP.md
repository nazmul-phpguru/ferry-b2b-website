# Ferry Telecom local backup

This repository contains a snapshot of the PHP storefront source and a full
compressed export of the local MySQL database, `ferry_app`.

## Database

- Files: `database/ferry_app.sql.gz.part-01` through `part-88`
- Exported from MySQL on 27 September 2026 using `mysqldump` with a consistent
  transaction snapshot, routines, triggers, and events.
- SHA-256 of the joined archive: `4fc110e6759d6b784e55b3a43252026677f4412fea791c3c84c34756ab5fd864`
- The export contains customer and account data.

Restore into a local MySQL server with an account that can create databases:


```sh
php database/join.php
gzip -dc database/ferry_app.sql.gz | mysql -u root -p
```

Copy `config.example.php` to `config.php`, then set local database credentials
and a fresh random application key. `config.php` is intentionally excluded.

Runtime files under `storage/`, including product media, are not part of this
source and database snapshot. They require a separate media backup for a fully
restored storefront.
