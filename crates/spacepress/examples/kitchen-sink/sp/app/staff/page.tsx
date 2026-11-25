import { db } from "spacepress";
import { events } from "spacepress:db";
import { current_user_can } from "php/wordpress";
import { Html, useFormState } from "spacepress/app";
import { apiReference } from "spacepress/content";
import { checkSchedule, postAnnouncement } from "../../server";

export const metadata = { title: "Staff" };

export default async function Staff() {
  const api = apiReference();
  const check = useFormState(checkSchedule);
  const announced = useFormState(postAnnouncement);
  const kitchen = await db.globals.settings.get();
  // `system` reads past the visitor's rules: drafts count too.
  const drafts = await events.system.count({ where: { status: "draft" } });
  const log = await db.query.activity.findMany({ orderBy: { createdAt: "desc" }, limit: 10, with: { eventId: true } });
  return (
    <>
      <h1>Kitchen staff</h1>
      <a href="/wp-admin/new/screens/schedule">Manage the workshop schedule</a>
      {current_user_can("manage_options") && <a href="/wp-admin/options-general.php">Site settings</a>}
      <p>{drafts} draft workshop(s) waiting for review.</p>
      <form action={checkSchedule}><button>Check the schedule</button></form>
      {check.error && <p role="alert">{check.error.message}</p>}
      {check.result && (
        <p role="status">
          {check.result.workshops} workshop(s) open for registration · times in {check.result.timezone}
          {check.result.hasWorkshops ? "" : " · no workshop has been recorded yet"}
        </p>
      )}
      <form action={postAnnouncement}>
        <label>Announcement, shown on every page <textarea name="announcement" maxLength={280} defaultValue={kitchen.announcement ?? ""} /></label>
        <button>Post announcement</button>
      </form>
      {announced.error && <p role="alert">{announced.error.message}</p>}
      {announced.result && <p role="status">{announced.result.announcement ? "The announcement is up." : "The announcement is down."}</p>}
      <h2>Kitchen log</h2>
      {log.length === 0 && <p>Nothing has happened yet.</p>}
      <ul>{log.map((entry) => <li>{entry.createdAt.slice(0, 10)} · {entry.summary}{entry.eventId && ` · ${entry.eventId.title}`}</li>)}</ul>
      <details>
        <summary>API operations</summary>
        {api.tags.map((tag) => (
          <section>
            <h3>{tag.name}</h3>
            {/* The OpenAPI document's descriptions are Markdown rendered to HTML. */}
            <Html value={tag.description} />
            <ul>{tag.operations.map((operation) => <li><code>{operation.method} {operation.path}</code> {operation.summary}</li>)}</ul>
          </section>
        ))}
      </details>
    </>
  );
}
