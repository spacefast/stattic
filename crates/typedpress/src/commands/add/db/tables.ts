// The database module, added by `typedpress add db`. This file is yours: rename the tables, change the
// fields, or delete it all and start again.
//
// A table() is a real SQL table: typed columns, foreign keys and indexes. Use it for the data your
// app works on (orders, bookings, votes, anything you filter, sort, count or join). Keep
// collection() for what people write and publish in the WordPress editor.
//
// - Every exported table() registers under its export name: the typed client gets `first.tasks`,
//   and the API gets `/__tp/api/v1/tasks`.
// - `typedpress build` writes the SQL for each change here to tp/_generated/migrations/. Commit that
//   folder and never edit it.
// - A deploy checks every change against the live rows before it runs any of them. Removing a table
//   or field that holds rows is refused until the deploy names the row count (`--drop=tasks=12`).
//   Rename with `was: "old_name"` on the table or `.was("old")` on a field and the rows stay.
import { boolean, date, ref, table, text } from "typedpress";

export const projects = table({
  name: text().max(120),
  archived: boolean().default(false),
}, {
  // Who may do what. A role admits itself and every role above it:
  // subscriber < author < editor < admin. "subscriber" is anyone with an account.
  access: { read: "subscriber", create: "editor", update: "editor", delete: "editor" },
});

export const tasks = table({
  // A reference to a project: a foreign key in the database, so a task can't exist without its
  // project, and deleting a project deletes its tasks. The field name is also the relation:
  // `first.tasks.list({ with: { project: true } })` reads each task with its project, and
  // `first.projects.list({ with: { tasks: true } })` reads each project with its tasks.
  // ref() can point at a collection() too: a table row can belong to a post.
  project: ref(projects).onDelete("cascade"),
  title: text().max(200),
  done: boolean().default(false),
  dueOn: date().optional(),
}, {
  access: {
    read: "subscriber",
    create: "subscriber",
    // A rule can be a function. `user` is null for a visitor who is not signed in, so refuse that
    // first. Returning a filter limits the rule to the rows that match it: editors change any
    // task, everyone else with an account only a task that is still open.
    update: ({ user }) => {
      if (!user || user.role === "guest") return false;
      if (user.role === "editor" || user.role === "admin") return true;
      return { done: false };
    },
    delete: "editor",
  },
});
