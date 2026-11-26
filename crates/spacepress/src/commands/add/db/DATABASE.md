# The database module

Added by `spacepress add db`. Everything here is yours to change.

Your app now has two kinds of storage, and each builder always picks the same one:

| | `collection()` | `table()` |
|---|---|---|
| Stored as | WordPress posts and post meta | its own SQL table |
| Edited in | the WordPress editor: drafts, revisions, publishing | your app, through the API and the typed client |
| Good for | what people write and publish | rows your app filters, sorts, counts and joins |

Post meta has no column types and no foreign keys, and filtering or sorting on it is slow. Data
your app queries belongs in a table.

## Files

- `sp/tables.ts`: example tables. Any `.ts` file under `sp/` works; a `table()` registers
  under its export name.
- `tables.client.ts`: the example tables from a frontend, through the generated client.
- `tsconfig.json` is yours and is not changed. For `tsc` to check the two files above, its
  `include` needs `"sp/**/*.ts"` and `"*.client.ts"`. A project from
  `npm create spacepress` already has both.
- `sp/_generated/migrations/`: written by `spacepress build`. It holds one SQL file per schema change
  and `snapshot.json`, the schema as of the last build. Commit the folder. Don't edit or delete
  anything in it: the next migration is planned against it.

## Changing the schema

Edit the tables and run `spacepress build`. The deploy checks each change against the rows the site
already has, before it runs any of them:

- A new table, a new optional field or a new field with `.default()` applies.
- A field that becomes required while rows lack it is refused, with the count.
- Removing a table or a field that holds rows is refused until the deploy names the count:
  `wp spacepress migrate --drop=tasks=12`.
- A rename keeps its rows: `was: "old_name"` on the table, `.was("old")` on the field.

A refusal changes nothing. `spacepress dev` is stricter with your local data: it refuses a save that
removes a field or changes its type, whether or not the table has rows. To apply one, stop dev and
start it once with `spacepress dev --allow-destructive`; run `spacepress build` before that if you want to
read the SQL.

## Reading and writing

- From a browser or another server: the generated client (`first.tasks.list(...)`) or
  `/wp-json/spacepress/v1/tasks`. Both apply the table's `access` rules.
- From an action, a hook or a job: `import { tasks } from "spacepress/server/db"` and call `tasks.list()`,
  `tasks.create()` and the rest. An action runs in one transaction with everything it writes, to
  tables and to WordPress content alike; a throw rolls all of it back.
