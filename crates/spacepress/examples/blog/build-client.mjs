// @ts-check
// Publish the browser client that `spacepress build` already emits. Run after `spacepress build`.
import { copyFileSync, mkdirSync } from "node:fs";

const here = (p) => new URL(p, import.meta.url);
mkdirSync(here("public/client/"), { recursive: true });
copyFileSync(here("sp/_generated/client/LICENSE"), here("public/client/LICENSE"));
copyFileSync(here("sp/_generated/client/client.js"), here("public/client/index.js"));
console.log("wrote public/client/index.js and LICENSE");
