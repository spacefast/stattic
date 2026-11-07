import {
  action as __z08Action,
  boolean as __z08Boolean,
  db as __z08Db,
  defineConfig as __z08Config,
  HttpError as __z08HttpError,
  json as __z08Json,
  real as __z08Real,
  table as __z08Table,
  text as __z08Text,
  email as __z08Email,
  userProjection as __z08Users,
  collection as __z08Collection,
  richText as __z08RichText,
  type InferSelectModel,
} from "zero";

import { version } from "./version";
const __z08_taskItems = __z08Table(
  {
    createdAt: __z08Text()
      .notNull()
      .$defaultFn(() => new Date().toISOString()),
    updatedAt: __z08Text()
      .notNull()
      .$defaultFn(() => new Date().toISOString())
      .$onUpdate(() => new Date().toISOString()),
    title: __z08Text().notNull(),
    done: __z08Boolean().notNull().default(false),
  },
  {
    existingTable: "sf_spc_zero_v2_migrated_taskitems_58c2072c6d",
    api: { list: false, get: false, create: false, update: false, delete: false },
    agents: false,
  },
);
function __z08_taskItems_row(row: InferSelectModel<typeof __z08_taskItems>) {
  return {
    id: String(row.id),
    createdAt: row.createdAt,
    updatedAt: row.updatedAt,
    title: row.title,
    done: row.done ?? false,
  };
}
function __z08_taskItems_nullable(
  row: InferSelectModel<typeof __z08_taskItems> | null | undefined,
) {
  return row == null ? null : __z08_taskItems_row(row);
}
type TaskUpdate = { title?: string; done?: boolean; updatedAt: string };
async function __z08_taskItems_update(id: string, patch: { title?: string; done?: boolean }) {
  const set: TaskUpdate = { updatedAt: new Date().toISOString() };
  const allowed: string[] = ["title", "done"];
  for (const key of Object.keys(patch)) {
    if (!allowed.includes(key)) throw new Error("Unknown table field.");
  }
  if (Object.keys(patch).includes("title")) {
    if (patch.title === undefined) throw new Error("Required table field.");
    set.title = patch.title;
  }
  if (Object.keys(patch).includes("done")) {
    set.done = patch.done === undefined ? false : patch.done;
  }
  const current = await __z08Db.query.taskItems.findFirst({ where: { id: __z08Key(id) } });
  if (current == null) return null;
  const rows = await __z08Db
    .update(__z08_taskItems)
    .set(set)
    .where({ id: __z08Key(id) })
    .returning();
  const row = rows[0];
  return row == null ? null : __z08_taskItems_row(row);
}
async function __z08_taskItems_get(id: string) {
  const row = await __z08Db.query.taskItems.findFirst({ where: { id: __z08Key(id) } });
  return row == null ? null : __z08_taskItems_row(row);
}
async function __z08_taskItems_insert(input: { title: string; done?: boolean }) {
  const allowed: string[] = ["title", "done"];
  for (const key of Object.keys(input)) {
    if (!allowed.includes(key)) throw new Error("Unknown table field.");
  }
  const timestamp = new Date().toISOString();
  const rows = await __z08Db
    .insert(__z08_taskItems)
    .values({
      createdAt: timestamp,
      updatedAt: timestamp,
      title: input.title,
      done: input.done === undefined ? false : input.done,
    })
    .returning();
  const row = rows[0];
  if (row == null) throw new Error("Insert returned no row.");
  return __z08_taskItems_row(row);
}
function __z08Key(id: string): number {
  const n = Number(id);
  if (!/^[1-9][0-9]*$/.test(id) || n > 9007199254740991)
    throw new __z08HttpError(422, "legacy_id_range", "Expected a positive safe integer ID.");
  return n;
}
export default __z08Config({
  name: "migrated-proof",
  tables: { taskItems: __z08_taskItems },
  actions: {
    version: __z08Action({
      input: {},
      access: "anyone",
      agents: false,
      async handler(__z08input, __z08ctx) {
        return version;
      },
    }),
    get: __z08Action({
      input: { id: __z08Text().notNull() },
      access: "anyone",
      agents: false,
      async handler(__z08input, __z08ctx) {
        const id = __z08input.id;
        return await __z08_taskItems_get(id);
      },
    }),
    list: __z08Action({
      input: {},
      access: "anyone",
      agents: false,
      async handler(__z08input, __z08ctx) {
        return (
          await __z08Db.query.taskItems.findMany({ orderBy: { createdAt: "asc", id: "asc" } })
        ).map(__z08_taskItems_row);
      },
    }),
    add: __z08Action({
      input: { title: __z08Text().notNull() },
      access: "anyone",
      agents: false,
      async handler(__z08input, __z08ctx) {
        const title = __z08input.title;
        return await __z08_taskItems_insert({ title });
      },
    }),
    setDone: __z08Action({
      input: { id: __z08Text().notNull(), done: __z08Boolean().notNull() },
      access: "anyone",
      agents: false,
      async handler(__z08input, __z08ctx) {
        const id = __z08input.id;
        const done = __z08input.done;
        return await __z08_taskItems_update(id, { done });
      },
    }),
    remove: __z08Action({
      input: { id: __z08Text().notNull() },
      access: "anyone",
      agents: false,
      async handler(__z08input, __z08ctx) {
        const id = __z08input.id;
        return (
          (
            await __z08Db
              .delete(__z08_taskItems)
              .where({ id: __z08Key(id) })
              .returning()
          ).length > 0
        );
      },
    }),
    rollback: __z08Action({
      input: {},
      access: "anyone",
      agents: false,
      async handler(__z08input, __z08ctx) {
        await __z08_taskItems_insert({ title: "must roll back" });
        throw new Error("rollback proof");
      },
    }),
  },
});
