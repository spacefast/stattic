import { afterAll, expect, test } from "bun:test";

import { deploy, get, startRuntime, type Runtime } from "./harness.ts";

const HOST = "html-inject.test";

let runtimes: Runtime[] = [];

afterAll(() => {
  for (const runtime of runtimes) runtime.stop();
});

test("runtime splices static inject placements into served HTML", async () => {
  const runtime = await startRuntime();
  runtimes.push(runtime);

  await deploy(runtime, {
    spaceId: "spc_html_inject",
    versionId: "ver_html_inject_1",
    files: {
      "index.html":
        '<!doctype html><html><head><meta charset="utf-8"></head><body class="home"><h1>Home</h1></body></html>\n',
    },
    serving: {
      config: {
        index: "index.html",
        listing: false,
        viewer: false,
        fallback: null,
        inject: {
          head: ['<meta name="spacefast-head" content="ok">'],
          bodyStart: ['<div id="spacefast-body-start"></div>'],
          bodyEnd: ["<script>window.spacefastBodyEnd = true;</script>"],
          noscript: ['<noscript><iframe src="https://example.test/ns"></iframe></noscript>'],
        },
      },
    },
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: [HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const response = await get(runtime, HOST, "/");
  expect(response.status).toBe(200);
  const html = await response.text();

  expect(html).toContain('<meta name="spacefast-head" content="ok">');
  expect(html).toContain("<noscript>");
  expect(html).toContain('id="spacefast-body-start"');
  expect(html).toContain("window.spacefastBodyEnd = true");

  const bodyOpen = html.indexOf('<body class="home">');
  const noscript = html.indexOf("<!-- spacefast:noscript -->");
  const bodyStart = html.indexOf("<!-- spacefast:body-start -->");
  const heading = html.indexOf("<h1>Home</h1>");
  const bodyEnd = html.indexOf("<!-- spacefast:body-end -->");
  const bodyClose = html.indexOf("</body>");

  expect(bodyOpen).toBeGreaterThanOrEqual(0);
  expect(noscript).toBeGreaterThan(bodyOpen);
  expect(bodyStart).toBeGreaterThan(noscript);
  expect(heading).toBeGreaterThan(bodyStart);
  expect(bodyEnd).toBeGreaterThan(heading);
  expect(bodyClose).toBeGreaterThan(bodyEnd);
});
