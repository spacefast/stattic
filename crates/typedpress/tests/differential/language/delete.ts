// `delete`, used to drop optional fields and map
// entries before storing or returning a record. Every action runs under Node and as compiled PHP;
// the JSON results must be byte-identical. `delete-helpers.mjs` runs the same checks untyped.
import { defineConfig, mutation } from "typedpress";
import { deleteUntyped as deleteUntypedImpl } from "./delete-helpers.mjs";

const run = (handler: () => unknown) => mutation({}, handler);

type Draft = { title: string; secret?: string; note?: string | null; tags?: string[] };

/** The 0.8 shape: strip optional fields from an input before it is stored or returned. */
function scrub(draft: Draft): unknown[] {
  const results = [delete draft.secret, delete draft.note, delete draft.secret];
  return [results, draft, Object.keys(draft), "secret" in draft];
}

function maps(): unknown[] {
  const counts: Record<string, number> = { b: 2, a: 1, "10": 10, "2": 2, c: 3 };
  const key = (name: string) => name;
  const removed = [delete counts.a, delete counts[key("c")], delete counts["10"], delete counts.missing, delete counts[2]];
  counts.a = 4;
  return [removed, Object.keys(counts), counts];
}

function optionalChain(): unknown[] {
  const present: { inner?: Record<string, number> } = { inner: { x: 1, y: 2 } };
  const absent: { inner?: Record<string, number> } = {};
  return [delete present.inner?.x, present, delete absent.inner?.x, absent];
}

/** Non-configurable properties: deleting them throws a TypeError in strict mode, as here. */
function nonConfigurable(): string[] {
  const attempt = (label: string, remove: () => boolean) => {
    try { return `${label}: ${remove()}`; } catch (error) { const e = error as Error; return `${label}: ${e.name}: ${e.message}`; }
  };
  const list: string[] = ["a", "b"];
  const text = "abc";
  const pattern = /a/g;
  const lengthOf = list as unknown as Record<string, unknown>;
  const textObject = text as unknown as Record<string, unknown>;
  const regexObject = pattern as unknown as Record<string, unknown>;
  const math = Math as unknown as Record<string, unknown>;
  return [
    attempt("array length", () => delete lengthOf.length),
    attempt("string length", () => delete textObject.length),
    attempt("string index", () => delete textObject[1]),
    attempt("string beyond", () => delete textObject[7]),
    attempt("string other", () => delete textObject.other),
    attempt("regex lastIndex", () => delete regexObject.lastIndex),
    attempt("Math.PI", () => delete math.PI),
    attempt("array past end", () => delete lengthOf[5]),
    attempt("array named", () => delete lengthOf.extra),
    attempt("inherited", () => delete (list as unknown as Record<string, unknown>).push),
  ];
}

/** `delete` evaluates the object, then the key, then deletes; null and undefined throw. */
function order(): unknown[] {
  const seen: string[] = [];
  const record: Record<string, number> = { k: 1 };
  const target = () => { seen.push("object"); return record; };
  const key = () => { seen.push("key"); return "k"; };
  const removed = delete target()[key()];
  const missing = null as unknown as Record<string, number>;
  let thrown = "none";
  try { delete missing.k; } catch (error) { thrown = (error as Error).name; }
  return [removed, seen, record, thrown];
}

function reads(): unknown[] {
  const box: { value?: number; other: number } = { value: 1, other: 2 };
  const before = box.value ?? -1;
  delete box.value;
  const after = box.value ?? -1;
  box.value = 3;
  return [before, after, box.value, box];
}

const BUILD_TIME_DELETE = { scrub: scrub({ title: "t", secret: "s", note: null }), maps: maps(), optional: optionalChain() };

export const deleteScrub = run(() => [scrub({ title: "t", secret: "s", note: null, tags: [] }), scrub({ title: "only" })]);

export const deleteMaps = run(() => maps());

export const deleteOptionalChain = run(() => optionalChain());

export const deleteNonConfigurable = run(() => nonConfigurable());

export const deleteOrder = run(() => order());

export const deleteReads = run(() => reads());

export const deleteBuildTime = run(() => BUILD_TIME_DELETE);

export const deleteUntyped = run(() => deleteUntypedImpl());

export default defineConfig({ name: "language-delete" });
