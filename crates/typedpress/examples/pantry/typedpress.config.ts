import {
  text, integer, boolean, date, timestamp, json,
  defineConfig, job, db, HttpError,
  table, select, ref, query, mutation,
} from "typedpress";
import { UNITS, LOW_STOCK, timestamps, slugify } from "./tp/lib/fields";

// Composition is plain TypeScript: shared field sets, factories, spreads.
const named = <T extends object>(extra: T) => ({ name: text(), ...extra });

export const locations = table(named({
  kind: select(["fridge", "freezer", "pantry"]).default("pantry"),
}));

export const items = table(named({
  slug: text().unique().max(191),
  quantity: integer().default(1),
  unit: select(UNITS).default("pcs"),
  lowStockAt: integer().default(LOW_STOCK),
  expiresOn: date().optional(),
  opened: boolean().default(false),
  locationId: ref(locations).optional(),
  tags: json<string[]>().$default(() => []),
  ...timestamps,
})).on({
  // Hooks compile to PHP and run inside the write; a throw rolls it back.
  beforeCreate(data) {
    data.name = data.name.trim();
    if (data.name === "") throw new HttpError(422, "invalid_input", "name can't be empty");
    data.slug = slugify(data.name);
    data.tags = (data.tags ?? []).map((t) => t.toLowerCase());
  },
  async afterCreate(row) { await restock(row.id, row.name, row.quantity, row.lowStockAt); },
  async afterUpdate(row) { await restock(row.id, row.name, row.quantity, row.lowStockAt); },
});

export const shoppingList = table({
  itemId: ref(items).unique().optional().onDelete("cascade"),
  name: text(),
  done: boolean().default(false),
  notifiedAt: timestamp().optional(),                // set by the notifyLowStock job
  ...timestamps,
});

export const tables = { locations, items, shoppingList };
export const entities = { ...tables };

// An item at or below its low-stock mark gets one shopping-list entry.
async function restock(itemId: number, name: string, quantity: number, lowStockAt: number) {
  if (quantity > lowStockAt) return;
  const existing = await db.query.shoppingList.findFirst({ where: { itemId } });
  if (!existing) {
    await db.insert(shoppingList).values({ itemId, name });
    // Off the request: runs after this write commits (Action Scheduler delivers it).
    await notifyLowStock.enqueue({ itemId }, { key: `low-${itemId}` });
  }
}

// A real pantry would push a notification here; this one stamps the list entry.
export const notifyLowStock = job({ retries: 2 }, async ({ itemId }: { itemId: number }) => {
  if (!Number.isInteger(itemId)) throw new HttpError(422, "invalid_input", "itemId must be an integer", "/itemId");
  await db.update(shoppingList).set({ notifiedAt: new Date().toISOString() }).where({ itemId });
});

export const consume = mutation({}, async ({ itemId, amount = 1 }: { itemId: number; amount?: number }) => {
  if (!Number.isInteger(itemId)) throw new HttpError(422, "invalid_input", "itemId must be an integer", "/itemId");
  if (!Number.isInteger(amount)) throw new HttpError(422, "invalid_input", "amount must be an integer", "/amount");
  const item = await db.query.items.findFirst({ where: { id: itemId } });
  if (!item) throw new HttpError(404, "not_found", `No item ${itemId}`);
  const [updated] = await db
    .update(items)
    .set({ quantity: Math.max(0, item.quantity - amount) })
    .where({ id: itemId })
    .returning();
  if (!updated) throw new HttpError(404, "not_found", `No item ${itemId}`);
  return { item: updated, message: `${updated.name}: ${updated.quantity} ${updated.unit} left` };
});

export const expiringSoon = query({}, async ({ days = 3 }: { days?: number }) => {
  if (!Number.isInteger(days)) throw new HttpError(422, "invalid_input", "days must be an integer", "/days");
  const cutoff = new Date(Date.now() + days * 86_400_000).toISOString().slice(0, 10);
  const soon = await db.query.items.findMany({
    where: { expiresOn: { lte: cutoff }, quantity: { gt: 0 } },
    orderBy: { expiresOn: "asc" },
    with: { locationId: true },
  });
  return soon.map((i) => ({
    name: i.name,
    expiresOn: i.expiresOn,
    where: i.locationId?.name ?? "somewhere",
  }));
});

export default defineConfig({});
