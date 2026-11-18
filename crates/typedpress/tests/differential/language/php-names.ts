// PHP spelling is private to each binding: source names, keys and closures retain
// JavaScript identity even when multiple names collapse to one snake_case base.
import { action, defineConfig } from "typedpress";

function bindings(itemId: number, item_id: number) {
  const httpStatus = 200;
  const HTTPStatus = 201;
  const THIS = 7;
  const GLOBALS = 8;
  const _GET = 9;
  const $dollar = 10;
  const żółć = 11;
  const record = { itemId, item_id, httpStatus, HTTPStatus, THIS, GLOBALS, _GET, $dollar, żółć };
  const callbacks = [1, 2].map((itemId) => () => itemId + item_id);
  const values: number[] = [];
  for (const itemId of [3, 4]) values.push(itemId + item_id);
  return { record, values, captured: callbacks.map((callback) => callback()) };
}

function exits(mode: number) {
  if (mode === 0) return "zero";
  if (mode === 1) throw new Error("one");
  return "other";
}

function fallthrough(mode: number) {
  if (mode > 0) return "positive";
}

export const namingBindings = action({ input: {}, handler: () => bindings(20, 30) });
export const namingControlFlow = action({ input: {}, handler: () => {
  let caught = "";
  try { exits(1); } catch (error) { caught = "caught"; }
  return [exits(0), exits(2), fallthrough(1), typeof fallthrough(0), caught];
} });
export default defineConfig({ name: "language-php-names" });

function fooBar() { return "camel"; }
function foo_bar() { return "snake"; }
async function sameName() { return "async"; }
function same_name__body() { return "source body"; }

export const namingFunctions = action({ input: {}, handler: () => [fooBar(), foo_bar()] });
export const namingAsync = action({ input: {}, handler: async () => [await sameName(), same_name__body()] });
// These source roots deliberately occupy names requested by generated wrappers.
export const naming_async__body = action({ input: {}, handler: () => "root body" });
export const naming_async__root = action({ input: {}, handler: () => "root queue" });
