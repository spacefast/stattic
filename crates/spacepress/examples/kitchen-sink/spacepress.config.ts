import { defineConfig, env } from "spacepress";
import kitchenPolicy from "./sp/plugins/kitchen-policy/index";

export default defineConfig({
  name: "kitchen-sink",
  use: [kitchenPolicy({ notice: "Made in a kitchen that handles nuts, wheat and dairy." })],
  media: { sizes: { card: { width: 600, height: 400, fit: "cover" } } },
  uploads: { backend: "local" },
  plugins: { "classic-editor": "1.6.7" },
  // The journal names its authors: author archives and the users sitemap stay as WordPress ships them.
  publicAuthors: true,
  // Keep soft-deleted rows and revisions indefinitely unless explicitly purged.
  retention: { deleted: "forever", revisions: "forever" },
  // The kitchen takes no remote publishing or pingbacks.
  xmlrpc: false,
  pings: false,
  // A fresh install imports the synced Markdown under content/, so the pages have something to show.
  seed: true,
  webhooks: [{
    url: env("KITCHEN_WEBHOOK_URL"),
    secret: env("KITCHEN_WEBHOOK_SECRET"),
    events: ["events.created", "events.updated"],
  }],
});
