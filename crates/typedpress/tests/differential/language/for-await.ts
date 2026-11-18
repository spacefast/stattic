// `for await` in authored TypeScript over sync iterables, the narrow form that TypedPress's blocking
// model runs exactly: each element is a plain value or the settled result of a TypedPress call. Async
// iterables and generators work in imported JavaScript (the job queue) and stay refused here.
// Every action runs under Node and as compiled PHP; the JSON results must be byte-identical.
import { action, defineConfig } from "typedpress";

const run = (handler: () => unknown) => action({ input: {}, handler });

async function double(n: number): Promise<number> {
  return n * 2;
}

async function rows(): Promise<{ n: number; label: string }[]> {
  return [{ n: 1, label: "a" }, { n: 2, label: "b" }];
}

export const forAwaitArray = run(async () => {
  const out: number[] = [];
  for await (const row of await rows()) out.push(row.n * 10);
  return out;
});

export const forAwaitPromises = run(async () => {
  const out: number[] = [];
  for await (const v of [await double(1), await double(2), 7]) out.push(v);
  for await (const row of [await rows()]) out.push(row.length);
  return out;
});

export const forAwaitString = run(async () => {
  const out: string[] = [];
  for await (const c of "héllo") out.push(c);
  return out;
});

export const forAwaitControl = run(async () => {
  const out: number[] = [];
  outer: for await (const x of [1, 2, 3, 4]) {
    if (x === 2) continue outer;
    if (x === 4) break;
    for await (const y of [x, x + 1]) out.push(y);
  }
  return out;
});

export const forAwaitDestructure = run(async () => {
  const out: string[] = [];
  for await (const { n, label } of await rows()) out.push(`${label}${n}`);
  for await (const [k, v] of Object.entries({ x: 1, y: 2 })) out.push(`${k}=${v}`);
  return out;
});

export default defineConfig({ name: "language-for-await" });
