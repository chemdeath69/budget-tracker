# 40 · Load the database schema

[← Back to the Installation Guide](../INSTRUCTIONS.md)

The app ships its complete schema in **[`www/lib/schema.sql`](../../www/lib/schema.sql)** — **47
tables**, MySQL 8 / InnoDB / `utf8mb4`. On a **fresh install you apply this one file** and you're
done. (The numbered files in `www/lib/migrations/` are only for *upgrading* an existing database —
see [Upgrading](#upgrading) at the bottom. You do **not** run them on a fresh install.)

> ⚠️ **The plain `mysql` command-line client on this host is MySQL 5.6 and cannot authenticate to
> the MySQL 8 server.** So don't try `mysql < schema.sql`. Use one of the three methods below, all of
> which talk to MySQL 8 correctly.

Pick one:

- **[Method 1 — phpMyAdmin import](#method-1)** — clicky, no terminal.
- **[Method 2 — Control Panel API import](#method-2)** — what the guided installer uses.
- **[Method 3 — SSH (PDO runner / `deploy.sh schema`)](#method-3)** — for the rsync crowd.

---

<a name="method-1"></a>
## Method 1 — phpMyAdmin import

1. Panel → **phpMyAdmin** (`/databases/phpadmin`). Make sure you open the **MySQL 8** server's
   phpMyAdmin (the panel links the correct one from the MySQL 8 database row).
2. In the left sidebar, click your database **`<cpuser>_budget`**.
3. Open the **Import** tab.
4. **Choose File** → select `www/lib/schema.sql` from your computer → **Go**.
5. Wait for "Import has been successfully finished". Refresh the sidebar — you should see ~47 tables
   (`users`, `items`, `accounts`, `transactions`, `holdings`, …).

---

<a name="method-2"></a>
## Method 2 — Control Panel API import

The schema is plain text, so you can put it on the server with the Files API and import it — no SSH:

1. Upload `schema.sql` to the server. The simplest path: it's **already there** if you uploaded the
   code (`~/www/<sub>/lib/schema.sql`). Otherwise write it with the Files API — note `write-file`
   only **overwrites an existing file**, so create it first:
   `POST /api/v1/files/create-file` (`dir=/www/<sub>/lib`, `name=schema.sql`) then
   `POST /api/v1/files/write-file` (`file=/www/<sub>/lib/schema.sql`, `content=<the SQL text>`).
2. Import it:
   ```
   POST /api/v1/databases/8/import
   { "database": "<cpuser>_budget", "collation": "utf8mb4",
     "file_path": "/www/<sub>/lib/schema.sql" }
   ```
   - The `8` selects the MySQL 8 server. `file_path` is `/www/...` (resolved under your home).
   - ⚠️ The `collation` field is passed to `mysql` as `--default-character-set`, so it must be a
     **charset** (`utf8mb4`) — **not** a collation like `utf8mb4_general_ci` (that fails the import).
   - The import runs as an **async task**; the response returns a `task_id`. Check
     `GET /api/v1/tasks/details?id=<task_id>` for `status: finished`.

The **guided installer** does all of this (create-file → write-file → import → poll the task) for you.

The **guided installer** does exactly this for you.

---

<a name="method-3"></a>
## Method 3 — SSH (PDO runner)

The repo's `deploy.sh schema` runs a small PHP/PDO script on the server that reads `config.php`,
connects to **MySQL 8 via the socket**, and executes `schema.sql` statement-by-statement (this is
the workaround for the MySQL-5.6-CLI problem):

```bash
./deploy.sh schema
```

It prints something like `Executed N statements. Tables: users, items, accounts, …`.

If you'd rather run it directly over SSH, the equivalent is a PHP 8.3 CLI one-off:

```bash
ssh -i ~/.ssh/<yourkey> <cpuser>@<server>.sureserver.com
/usr/local/bin/php83.cli -r '
$c=require "/home/<cpuser>/www/<sub>/lib/config.php"; $d=$c["db"];
$pdo=new PDO("mysql:unix_socket={$d["socket"]};dbname={$d["name"]};charset=utf8mb4",$d["user"],$d["pass"],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$sql=file_get_contents("/home/<cpuser>/www/<sub>/lib/schema.sql");
foreach(array_filter(array_map("trim",explode(";",$sql))) as $s){ if($s!=="") $pdo->exec($s); }
echo "done\n";'
```

> Use **`/usr/local/bin/php83.cli`**, not bare `php` (which is 5.6 here). A harmless ionCube stderr
> warning may print — ignore it.

---

## Verify the load

Any method — confirm the tables exist. Over SSH:

```bash
/usr/local/bin/php83.cli -r '
$c=require "/home/<cpuser>/www/<sub>/lib/config.php"; $d=$c["db"];
$pdo=new PDO("mysql:unix_socket={$d["socket"]};dbname={$d["name"]}",$d["user"],$d["pass"]);
echo implode("\n",$pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN)),"\n";'
```

Or just look at the table list in phpMyAdmin. You want to see ~47 tables.

---

<a name="upgrading"></a>
## Upgrading later (NOT needed for a fresh install)

`schema.sql` is kept current, so a fresh install already has every table and column. When you later
pull **new code** that introduces a migration file `www/lib/migrations/NNN_*.php`, apply just that
one (each is idempotent and CLI-only):

```bash
/usr/local/bin/php83.cli /home/<cpuser>/www/<sub>/lib/migrations/NNN_something.php
```

Migrations are numbered `001`–`030+`. A fresh `schema.sql` already includes all of them; only run a
migration when upgrading an **existing** database to newer code that added it. The
[`HANDOFF.md`](../HANDOFF.md) "current state" section always names the next migration number.

---

## ✅ Checkpoint

- [ ] ~47 tables exist in `<cpuser>_budget` on the **MySQL 8** server.

→ Next: **[50 · Cron & first sync](50-cron-and-sync.md)**.
