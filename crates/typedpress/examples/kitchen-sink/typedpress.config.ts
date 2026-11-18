import {
  action, blocks, boolean, collection, date, db, defineConfig, email, env, file, files, global, group, HttpError, integer, job, jobs, media,
  repeater, richText, select, slug, table, text, timestamp, url, userProjection, ref,
} from "typedpress";

// Native WordPress posts: title, content, drafts, revisions and custom fields.
export const pages = collection({
  title: text().max(120),
  slug: slug({ source: "title" }).optional(),
  body: richText().optional(),
  cover: media({ accept: "image" }).optional(),
  featured: boolean().default(false),
  seo: group({
    title: text().max(70).optional(),
    description: text({ multiline: true }).max(160).optional(),
  }).optional(),
  sections: blocks({
    hero: { heading: text(), image: media({ accept: "image" }).optional() },
    quote: { text: text({ multiline: true }), author: text().optional() },
  }).optional(),
}, {
  label: "Pages", title: "title", content: "body", uid: true,
  access: {
    read: (ctx) => ["editor", "admin"].includes(ctx.user?.role ?? "") ? true : { status: "published" },
    create: "editor", update: "editor", delete: "admin",
  },
}).on({
  beforeCreate(data) {
    data.title = data.title.trim();
    if (data.title === "") throw new HttpError(422, "invalid_input", "Give this page a title");
  },
  async afterCreate(page) {
    await jobs.recordPage.enqueue({ pageId: page.id }, { key: `page-${page.id}` });
  },
});

export const handouts = files({ accept: ["application/pdf"], maxBytes: 5_000_000, access: { read: "authenticated", create: "editor", delete: "admin" } });

export const events = collection({
  title: text().max(120),
  slug: slug({ source: "title" }).optional(),
  description: richText().optional(),
  startsOn: date(),
  venue: text(),
  poster: media({ accept: "image" }).optional(),
  handout: file(handouts).optional(),
  speakers: repeater({ name: text(), bio: text({ multiline: true }).optional() }).optional(),
}, { label: "Events", title: "title", content: "description" });

// WordPress users with additional profile fields and access to their own row.
export const members = userProjection({
  email: email().unique().max(191),
  name: text().max(80),
  website: url().optional(),
  plan: select(["free", "supporter"]).default("free"),
}, { access: { read: "self", create: "anyone", update: "self", delete: "admin" } });

// App data in custom tables, related to native posts and users.
export const registrations = table({
  eventId: ref(events).onDelete("cascade"),
  memberId: ref(members).onDelete("cascade"),
  confirmed: boolean().default(false),
  createdAt: timestamp().defaultNow(),
}, { access: { read: "editor", create: "authenticated", update: "editor", delete: "admin" } });

export const activity = table({
  pageId: ref(pages).onDelete("cascade"),
  message: text(),
  createdAt: timestamp().defaultNow(),
}, { access: { read: "editor", create: "admin", update: "admin", delete: "admin" } });

const entities = { pages, events, members, registrations, activity };

// Shared settings use WordPress options through Secure Custom Fields.
export const settings = global({
  siteTitle: text().default("The kitchen sink"),
  contactEmail: email().optional(),
  announcement: text({ multiline: true }).optional(),
  links: repeater({ label: text(), href: url() }).optional(),
}, { label: "Site settings", access: { read: "anyone", update: "admin" } });

export const recordPage = job({
  input: { pageId: integer() }, retries: 3,
  async handler({ pageId }) {
    await db.insert(activity).values({ pageId, message: "Page created" });
  },
});

export const publishPage = action({
  input: { title: text(), body: text() }, access: "editor",
  async handler({ title, body }) {
    const [page] = await db.insert(pages).values({ title, body, status: "published" }).returning();
    return page;
  },
});

export const renameMember = action({
  input: { id: integer(), name: text() }, access: "admin",
  async handler({ id, name }) {
    const [member] = await db.update(members).set({ name }).where({ id }).returning();
    if (!member) throw new HttpError(404, "not_found", "Member not found");
    return member;
  },
});

export default defineConfig({
  name: "kitchen-sink",
  media: { sizes: { card: { width: 600, height: 400, fit: "cover" }, avatar: { width: 96, height: 96, fit: "cover" } } },
  plugins: { "classic-editor": "1.6.7" },
  webhooks: [{ url: env("KITCHEN_WEBHOOK_URL"), secret: env("KITCHEN_WEBHOOK_SECRET"), events: ["pages.created", "pages.updated"] }],
});
