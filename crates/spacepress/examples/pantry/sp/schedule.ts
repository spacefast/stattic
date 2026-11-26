import { cron } from "spacepress";
import { mail } from "spacepress/server";
import { shoppingList } from "spacepress/server/db";

// Every Monday at 08:00 UTC: mail the open shopping list.
export const weeklyList = cron("0 8 * * 1", async () => {
  const open = await shoppingList.list({ where: { done: false }, orderBy: { name: "asc" } });
  if (open.length === 0) return;
  await mail.send({
    to: "kitchen@example.com",
    subject: `Shopping list: ${open.length} items`,
    text: open.map((entry) => `- ${entry.name}`).join("\n"),
  });
});
