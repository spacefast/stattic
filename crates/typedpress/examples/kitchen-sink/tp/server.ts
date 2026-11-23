import { adminDashboard, adminScreen, adminWidget, cron, db, HttpError, item, mail, mutation, query } from "typedpress";
import {
  abilities, get_current_user_id, get_option, get_permalink, get_post, get_userdata, sanitize_text_field,
  wp_insert_post,
} from "php/wordpress";
import { events as workshops } from "typedpress:db";
import Reservation from "../emails/reservation";
import { registrations } from "./schema";

// Closes registration each morning for workshops whose date has passed.
export const closePastWorkshops = cron("0 6 * * *", async () => {
  const today = new Date().toISOString().slice(0, 10);
  const open = await workshops.system.list({ where: { registrationOpen: true } });
  for (const event of open) {
    if (event.startsOn < today) {
      await workshops.system.update(event.id, { registrationOpen: false });
    }
  }
});

export const bookSeats = mutation({ access: "authenticated" }, async ({ eventId, seats, allergies }: { eventId: number; seats: number; allergies?: string }, ctx) => {
  if (!Number.isInteger(eventId)) throw new HttpError(422, "invalid_input", "eventId must be an integer", "/eventId");
  if (!Number.isInteger(seats) || seats < 1 || seats > 4) throw new HttpError(422, "invalid_input", "seats must be an integer from 1 to 4", "/seats");
  if (allergies !== undefined && allergies.length > 200) throw new HttpError(422, "invalid_input", "allergies must be at most 200 characters", "/allergies");
  const event = await db.query.events.findFirst({ where: { id: eventId, status: "published" } });
  const member = ctx.user;
  if (!member) throw new HttpError(401, "unauthenticated", "Sign in to reserve a seat.");
  // The confirmation goes to the account's own address, never to one typed into the form.
  const account = get_userdata(get_current_user_id());
  if (account === false) throw new HttpError(401, "unauthenticated", "Sign in to reserve a seat.");
  if (!event || !event.registrationOpen) {
    throw new HttpError(409, "registration_closed", "This workshop is closed for registration.");
  }
  const existing = await db.query.registrations.findFirst({ where: { eventId, memberId: member.id } });
  if (existing) throw new HttpError(409, "already_registered", "You already have a reservation.");
  const allergens = (allergies ?? "").split(",").map((entry) => sanitize_text_field(entry)).filter((entry) => entry !== "");
  const [booking] = await db.insert(registrations)
    .values({ eventId, memberId: member.id, seats, preferences: { allergens } }).returning();
  if (!booking) throw new HttpError(500, "internal", "The reservation could not be saved.");
  const kitchen = await db.globals.settings.get();
  const body = mail.render(Reservation, {
    name: account.display_name, workshop: event.title, startsOn: event.startsOn, seats,
    code: booking.code, url: get_permalink(event.id) || "", kitchen: kitchen.siteTitle,
  });
  await mail.send({
    to: { email: account.user_email, name: account.display_name },
    subject: `Reservation for ${event.title}`,
    html: body.html, text: body.text,
    // An unset setting is null, and a null field is left out of the message: it then goes from
    // noreply@ the site's host, with no Reply-To of its own.
    from: kitchen.mailFrom ? { email: kitchen.mailFrom, name: kitchen.siteTitle } : null,
    replyTo: kitchen.replyTo,
  });
  return { code: booking.code };
});

// The contact page's form: anyone may write, and the message is mailed to the site's admin address
// after the request commits. The visitor's address is the Reply-To, never the sender.
export const contactKitchen = mutation({ access: "anyone", rateLimit: "5/hour" }, async ({ email, message }: { email: string; message: string }) => {
  if (email.length > 191 || !/^[^\s@,]+@[^\s@,]+\.[^\s@,]+$/.test(email)) throw new HttpError(422, "invalid_input", "email must be an email address", "/email");
  if (message.length < 3 || message.length > 2000) throw new HttpError(422, "invalid_input", "message must be 3 to 2000 characters", "/message");
  const sent = await mail.send({
    to: String(get_option("admin_email")),
    subject: "A message from the contact page",
    text: message,
    replyTo: email,
  });
  return { delivery: sent.id };
});

export const cancelSeats = mutation({ access: "authenticated" }, async ({ eventId }: { eventId: number }, ctx) => {
  if (!Number.isInteger(eventId)) throw new HttpError(422, "invalid_input", "eventId must be an integer", "/eventId");
  const member = ctx.user;
  if (!member) throw new HttpError(401, "unauthenticated", "Sign in to cancel a reservation.");
  const [cancelled] = await db.delete(registrations).where({ eventId, memberId: member.id }).returning();
  if (!cancelled) throw new HttpError(404, "not_found", "You have no reservation for this workshop.");
  return { seats: cancelled.seats };
});

export const upcoming = query({ access: "editor" }, async () => {
  const rows = await db.query.events.findMany({ where: { status: "published" }, orderBy: { startsOn: "asc" } });
  return { rows };
});

// The schedule screen's actions use the kind API from "typedpress:db": it runs as the signed-in
// editor, under the workshops' own access rules, and answers 404 for a row they may not touch.
export const closeRegistration = mutation({ access: "editor" }, async ({ id }: { id: number }) => {
  if (!Number.isInteger(id)) throw new HttpError(422, "invalid_input", "id must be an integer", "/id");
  const event = await workshops.update(id, { registrationOpen: false });
  return { id: event.id };
});

export const scheduleAgain = mutation({ access: "editor" }, async ({ id }: { id: number }) => {
  if (!Number.isInteger(id)) throw new HttpError(422, "invalid_input", "id must be an integer", "/id");
  const source = await workshops.get(id);
  if (!source) throw new HttpError(404, "not_found", "Workshop not found.");
  // The same class a week later, as a draft for an editor to review.
  const nextWeek = new Date(new Date(source.startsOn).getTime() + 7 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
  const copy = await workshops.create({
    title: source.title, startsOn: nextWeek, venue: source.venue, format: source.format,
    equipment: source.equipment, status: "draft",
  });
  return { id: copy.id, startsOn: copy.startsOn };
});

export const cancelWorkshop = mutation({ access: "admin", destructive: true }, async ({ id }: { id: number }) => {
  if (!Number.isInteger(id)) throw new HttpError(422, "invalid_input", "id must be an integer", "/id");
  const gone = await workshops.delete(id);
  return { id: gone.id };
});

export const restoreWorkshop = mutation({ access: "admin" }, async ({ id }: { id: number }) => {
  if (!Number.isInteger(id)) throw new HttpError(422, "invalid_input", "id must be an integer", "/id");
  const back = await workshops.restore(id);
  return { id: back.id };
});

export const purgeWorkshop = mutation({ access: "admin", destructive: true }, async ({ id }: { id: number }) => {
  if (!Number.isInteger(id)) throw new HttpError(422, "invalid_input", "id must be an integer", "/id");
  const gone = await workshops.purge(id);
  return { id: gone.id };
});

export const workshopHistory = query({ access: "editor" }, async ({ id }: { id: number }) => {
  if (!Number.isInteger(id)) throw new HttpError(422, "invalid_input", "id must be an integer", "/id");
  const list = await workshops.revisions.list(id);
  const first = list.length > 0 ? await workshops.revisions.get(id, list[list.length - 1]!.version) : null;
  const diff = list.length >= 2 ? await workshops.revisions.diff(id, list[list.length - 1]!.version, list[0]!.version) : null;
  return { count: list.length, firstTitle: first?.title ?? null, diff };
});

export const revertWorkshop = mutation({ access: "editor" }, async ({ id, version }: { id: number; version: number }) => {
  if (!Number.isInteger(id)) throw new HttpError(422, "invalid_input", "id must be an integer", "/id");
  if (!Number.isInteger(version) || version < 1) throw new HttpError(422, "invalid_input", "version must be positive", "/version");
  const restored = await workshops.revisions.restore(id, version);
  return { id: restored.id, title: restored.title };
});

// The banner the layout shows on every page. Clearing the field takes it down.
export const postAnnouncement = mutation({ access: "editor" }, async ({ announcement }: { announcement?: string }) => {
  if (announcement !== undefined && announcement.length > 280) throw new HttpError(422, "invalid_input", "announcement must be at most 280 characters", "/announcement");
  const saved = await db.globals.settings.update({ announcement: announcement ? sanitize_text_field(announcement) : null });
  return { announcement: saved.announcement };
});

// Starts a journal post about a workshop as a WordPress draft, for an editor to finish.
export const draftRecap = mutation({ access: "editor" }, async ({ id }: { id: number }) => {
  if (!Number.isInteger(id)) throw new HttpError(422, "invalid_input", "id must be an integer", "/id");
  const event = await db.query.events.findFirst({ where: { id } });
  if (!event) throw new HttpError(404, "not_found", "Workshop not found.");
  const postId = wp_insert_post({
    post_title: `Recap: ${event.title}`, post_status: "draft", post_type: "post",
    post_content: `<!-- wp:paragraph --><p>Notes from ${event.title} on ${event.startsOn}.</p><!-- /wp:paragraph -->`,
  });
  const draft = get_post(postId);
  return { postId, title: draft ? draft.post_title : "", status: draft ? draft.post_status : "" };
});

async function readScheduleSummary() {
  const open = await abilities.call("kitchen-sink/events-list", { where: { registrationOpen: true }, limit: 100 });
  return {
    workshops: open.rows.length,
    timezone: String(get_option("timezone_string") || "UTC"),
    // WordPress stores a true option as "1".
    hasWorkshops: Boolean(get_option("kitchen_has_workshops")),
  };
}

// The staff page's form posts this. It reads the workshops through the WordPress Ability the build
// registers for the kind, as the signed-in editor: the call an agent or another plugin makes.
export const checkSchedule = mutation({ access: "editor" }, async () => await readScheduleSummary());

export const scheduleMetrics = query({ access: "editor" }, async () => await readScheduleSummary());

export const schedule = adminScreen({
  title: "Workshop schedule",
  access: "editor",
  view: {
    action: upcoming, items: "rows", id: "id",
    fields: { title: { label: "Workshop" }, startsOn: { label: "Date" } },
    layout: { type: "table", titleField: "title", fields: ["startsOn"] },
  },
  actions: [
    { id: "close", label: "Close registration", action: closeRegistration, input: { id: item("id") } },
    { id: "recap", label: "Draft a journal recap", action: draftRecap, input: { id: item("id") } },
    { id: "repeat", label: "Schedule again next week", action: scheduleAgain, input: { id: item("id") } },
    {
      id: "cancel", label: "Cancel workshop", action: cancelWorkshop, input: { id: item("id") },
      confirm: "Cancel this workshop? It is deleted for good, unless it has reservations.",
    },
  ],
});

export const scheduleSummary = adminWidget({
  title: "Open workshops",
  description: "Live count of workshops currently accepting registrations.",
  access: "editor",
  action: scheduleMetrics,
  display: "metrics",
  metrics: [
    { id: "workshops", label: "Open workshops", path: "workshops" },
    { id: "timezone", label: "Timezone", path: "timezone" },
  ],
  width: 2,
  height: 1,
  link: { label: "Manage schedule", to: "/screens/schedule" },
});

export const studioOverview = adminDashboard({
  title: "Studio overview",
  description: "Workshops, open seats, and recent updates.",
  access: "editor",
  menu: { icon: "chartBar" },
  widgets: [
    { widget: scheduleSummary, width: 2, height: 1 },
    { widget: "tp/content-overview", width: 2, height: 2 },
    { widget: "tp/recent-activity", width: 2, height: 2 },
  ],
});
