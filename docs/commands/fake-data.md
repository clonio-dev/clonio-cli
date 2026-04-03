# `fake:data` Command

Seeds a configured database connection with realistic fake data for local testing. Useful for benchmarking Clonio's runtime behaviour against large volumes of data across different database engines.

## Usage

```bash
clonio fake:data <connection> [<rows>] [--fresh]
```

## Arguments

| Argument | Required | Default | Description |
|---|---|---|---|
| `connection` | yes | — | Name of the database connection from `clonio.json` |
| `rows` | no | `1000` | Number of rows to insert per table |

## Options

| Option | Description |
|---|---|
| `--fresh` | Drop all demo tables and recreate the schema before seeding |

## Schema

The command creates two domain groups of tables automatically (using `CREATE TABLE IF NOT EXISTS`, or dropping and recreating when `--fresh` is passed).

### Task management

| Table | Primary key | Relationships |
|---|---|---|
| `users` | UUID | — |
| `user_login_history` | bigint (auto-increment) | `user_id` → `users.id` |
| `projects` | UUID | `owner_id` → `users.id` |
| `issues` | bigint (auto-increment) | `project_id` → `projects.id`, `reporter_id` / `assignee_id` → `users.id` |
| `comments` | bigint (auto-increment) | `issue_id` → `issues.id`, `user_id` → `users.id` |

### Product catalog

| Table | Primary key | Relationships |
|---|---|---|
| `categories` | bigint (auto-increment) | `parent_id` → `categories.id` (self-referencing, nullable) |
| `tags` | bigint (auto-increment) | — |
| `products` | UUID | `category_id` → `categories.id` |
| `product_tags` | composite (`product_id` UUID + `tag_id` bigint) | `product_id` → `products.id`, `tag_id` → `tags.id` |

This schema intentionally covers a range of primary key strategies (UUID, bigint auto-increment, composite) and relationship types (one-to-many, self-referencing, many-to-many) to exercise the full breadth of Clonio's inspection and transfer logic.

## Supported database engines

Works with all five connection types:

| Engine | Driver value |
|---|---|
| MySQL | `mysql` |
| MariaDB | `mariadb` |
| PostgreSQL | `pgsql` |
| SQL Server | `sqlsrv` |
| SQLite | `sqlite` |

Foreign-key constraint disabling during `--fresh` drops is handled per-engine:

- **MySQL / MariaDB** — `SET FOREIGN_KEY_CHECKS=0`
- **SQLite** — `PRAGMA foreign_keys = OFF`
- **PostgreSQL / SQL Server** — tables are dropped in reverse dependency order

## Examples

```bash
# Seed the default 1 000 rows per table
clonio fake:data local-mysql

# Seed 1 million rows per table (good for benchmarking transfer speed)
clonio fake:data local-pgsql 1000000

# Reset and reseed from scratch
clonio fake:data local-sqlite 50000 --fresh
```

## Performance notes

Data is inserted in batches of 500 rows. For each parent table (those whose IDs are needed by child tables as foreign keys), up to 50 000 IDs are kept in memory; larger tables are sampled so child rows still get a realistic distribution of parent references without unbounded memory growth.

At 1 million rows per table the command inserts roughly 9 million rows in total. Expected throughput varies by engine and host latency — on a local socket-connected MySQL/PostgreSQL instance, expect around 50 000–150 000 rows per second per table.

The `product_tags` junction table produces 1–5 tag associations per product rather than a fixed row count, so its total will differ from the other tables.

## Exit codes

| Code | Constant | Meaning |
|---|---|---|
| `0` | `Success` | All rows inserted successfully |
| `1` | `GeneralError` | Schema creation or seeding failure |
| `2` | `ConfigError` | Connection name not found in `clonio.json` |
| `3` | `ConnectionError` | Could not open the database connection |
| `4` | `ValidationError` | `rows` argument is not a positive integer |
