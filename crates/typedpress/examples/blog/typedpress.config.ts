import {
  collection, global, text, integer, boolean, timestamp, richText, media, slug, select,
  repeater, group, blocks, email, url, file, files, defineConfig, job, env,
  db, HttpError,
  table, userProjection, ref, query, mutation,
} from "typedpress";

export const authors = collection({
  name: text().max(80),
  // Public profiles, private inboxes: only editors see an author's address (§4.7).
  email: email().unique().max(191).access({ read: "editor" }),
  bio: text({ multiline: true }).max(500).optional(),
  avatar: media({ accept: "image" }).optional(),
  userId: integer().optional(),                       // the User.id that owns this author profile
}, { label: "Authors", title: "name", uid: true, access: { read: "anyone", create: "admin", update: "admin", delete: "admin" } });

export const decks = files({ accept: ["application/pdf"], maxBytes: 20_000_000, access: { read: "anyone", create: "author", delete: "editor" } });

export const posts = collection({
  title: text().min(1).max(120),
  slug: slug({ source: "title" }).optional(),
  body: richText().optional(),
  excerpt: text({ multiline: true }).max(300).optional(),
  cover: media({ accept: "image" }).optional(),
  tags: select(["news", "guide", "release"]).many().default([]),
  authorId: ref(authors).optional(),
  // Only worth filling in for published posts: the editor hides it otherwise (§4.7).
  seo: group({
    metaTitle: text().max(70).optional(),
    metaDescription: text().max(160).optional(),
    noindex: boolean().default(false),
  }).showIf({ status: "published" }).optional(),
  // The talk's slide deck: a private file, downloaded through a signed link (§7.2).
  deck: file(decks).optional(),
  gallery: repeater({ image: media({ accept: "image" }), caption: text().max(120).optional() }).max(12).optional(),
  sections: blocks({
    hero: { heading: text().max(80), image: media({ accept: "image" }).optional() },
    quote: { text: text({ multiline: true }), cite: text().max(80).optional() },
  }).optional(),
  createdAt: timestamp().defaultNow(),
  updatedAt: timestamp().defaultNow().$onUpdate(() => new Date()),
}, {
  label: "Posts",
  title: "title",
  content: "body",
  // Write posts as Markdown in content/posts/; `typedpress sync` keeps files and site in step (§5).
  sync: "content/posts/*.md",
  access: {
    read: (ctx) => ["author", "editor", "admin"].includes(ctx.user?.role ?? "") ? true : { status: "published" },
    create: "author",
    update: (ctx) => ctx.user?.role === "admin" || ctx.user?.role === "editor" ? true : { authorId: { userId: ctx.user?.id ?? -1 } },
    delete: "editor",
  },
}).on({
  async beforeCreate(data, ctx) {
    if (data.authorId == null && ctx.user) {
      const author = await db.query.authors.findFirst({ where: { userId: ctx.user.id } });
      if (author) data.authorId = author.id;
    }
  },
});

export const comments = table({
  postId: ref(posts).onDelete("cascade"),
  name: text().max(60),
  body: text({ multiline: true }).min(1).max(2000).validate((v) => v.includes("http://") ? "No links in comments" : true),
  approved: boolean().default(false),
  notifiedAt: timestamp().optional(),                 // set by the notifyEditors job
  createdAt: timestamp().defaultNow(),
}, {
  access: { read: (ctx) => ["author", "editor", "admin"].includes(ctx.user?.role ?? "") ? true : { approved: true }, create: "anyone", update: "editor", delete: "editor" },
}).on({
  // Readers can't approve their own comments, whatever they send.
  async beforeCreate(data, ctx) {
    if (!["author", "editor", "admin"].includes(ctx.user?.role ?? "")) data.approved = false;
  },
  async afterCreate(row, ctx) {
    if (["author", "editor", "admin"].includes(ctx.user?.role ?? "")) {
      await db.update(comments).set({ approved: true }).where({ id: row.id });
    } else {
      await notifyEditors.enqueue({ commentId: row.id }, { key: `comment-${row.id}` });
    }
  },
});

// Readers who sign in (§6.2): the typedpress-identity screens on WordPress.
export const members = userProjection({
  email: email().unique().max(191),
  name: text().max(80),
  plan: select(["free", "pro"]).default("free"),
}, { access: { read: "self", update: "self", create: "anyone", delete: "admin" } });

export const collections = { authors, posts };
export const tables = { comments };
export const users = { members };
export const entities = { ...collections, ...tables, ...users };

export const settings = global({
  siteTitle: text().default("My blog").max(80),
  tagline: text().max(160).optional(),
  postsPerPage: integer().default(10).min(1).max(50),
  social: repeater({ label: text().max(40), href: url() }).optional(),
}, { label: "Site settings" });

// Runs after the comment's request commits, on the queue (Action Scheduler on WordPress).
export const notifyEditors = job({ retries: 3 }, async ({ commentId }: { commentId: number }) => {
  if (!Number.isInteger(commentId)) throw new HttpError(422, "invalid_input", "commentId must be an integer", "/commentId");
  await db.update(comments).set({ notifiedAt: new Date().toISOString() }).where({ id: commentId });
});

export const approveComment = mutation({ access: "editor" }, async ({ id }: { id: number }) => {
  if (!Number.isInteger(id)) throw new HttpError(422, "invalid_input", "id must be an integer", "/id");
  const [row] = await db.update(comments).set({ approved: true }).where({ id }).returning();
  if (!row) throw new HttpError(404, "not_found", `No comment ${id}`);
  return row;
});

export const publishedCount = query({ access: "anyone" }, async () => {
  const rows = await db.query.posts.findMany({ where: { status: "published" }, columns: { id: true } });
  return { count: rows.length };
});

export default defineConfig({
  name: "blog",
  // A 600×400 crop of every image for cards, centred on the image's focal point (§7.2).
  media: { sizes: { card: { width: 600, height: 400, fit: "cover" } } },
  // Every post change is POSTed, signed, to BLOG_HOOK_URL and retried on failure (§6.1).
  webhooks: [{ url: env("BLOG_HOOK_URL"), secret: env("BLOG_HOOK_SECRET"), events: ["posts.created", "posts.updated", "posts.deleted"] }],
});
