// A typed client for the tables in tp/tables.ts, added by `typedpress add db`. Import these
// functions from your frontend, or copy the calls. `typedpress build` regenerates the client's types
// from your schema, so a renamed field is a type error here.
import { createClient } from "./tp/_generated/client/index.ts";

const first = createClient({ url: "/wp-json/typedpress/v1" });

/** The open tasks of one project by due date; tasks with no date come first. At most 50 unless you pass `limit`. */
export function openTasks(project: number) {
  return first.tasks.list({ where: { project, done: false }, orderBy: { dueOn: "asc" } });
}

/** Every project with its tasks, through the relation the `project` field declares. */
export function projectsWithTasks() {
  return first.projects.list({ with: { tasks: true } });
}

export function addTask(project: number, title: string) {
  return first.tasks.create({ project, title });
}

export function finishTask(id: number) {
  return first.tasks.update(id, { done: true });
}
