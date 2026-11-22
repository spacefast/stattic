import { defineConfig, env } from "typedpress";
import kitchenPolicy from "./tp/plugins/kitchen-policy/index";

export default defineConfig({
  name: "kitchen-sink",
  use: [kitchenPolicy({ notice: "Made in a kitchen that handles nuts, wheat and dairy." })],
  media: { sizes: { card: { width: 600, height: 400, fit: "cover" } } },
  uploads: { backend: "local" },
  plugins: { "classic-editor": "1.6.7" },
  // The journal names its authors: author archives and the users sitemap stay as WordPress ships them.
  publicAuthors: true,
  // The kitchen takes no remote publishing or pingbacks.
  xmlrpc: false,
  pings: false,
  // A fresh install imports the synced Markdown under content/, so the pages have something to show.
  seed: true,
  // The contact page's form: validated, saved and mailed by WordPress, with no handler to write.
  services: {
    forms: {
      contact: {
        fields: {
          email: { type: "email", notNull: true, rules: { max: 191 } },
          message: { type: "text", notNull: true, rules: { min: 3, max: 2000 } },
        },
        recipient: env("KITCHEN_CONTACT_TO"),
        sender: env("KITCHEN_CONTACT_FROM"),
        limit: 5,
      },
    },
  },
  webhooks: [{
    url: env("KITCHEN_WEBHOOK_URL"),
    secret: env("KITCHEN_WEBHOOK_SECRET"),
    events: ["events.created", "events.updated"],
  }],
});
