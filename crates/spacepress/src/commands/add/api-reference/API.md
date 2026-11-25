# The API reference

`spacepress add api-reference` wrote these files. They are yours: change the markup, the styles and
the URLs as you like.

| File | Serves |
| --- | --- |
| `sp/app/api/page.tsx` | `/api`: every operation, by tag |
| `sp/app/api/[tag]/[operation]/page.tsx` | `/api/<tag>/<operation>`: one operation |
| `sp/components/ApiFields.tsx` | the list of an operation's parameters, body or response fields |

Both pages read `apiReference()` from `"spacepress/content"`. It returns the OpenAPI document
`spacepress build` writes for this app (`sp/_generated/openapi.json`), as the site serves it at
`/wp-json/spacepress/v1/openapi.json`, so the reference follows the schema on every build.

- Each operation has `method`, `path`, `summary`, `description` and `access`, `params`, `body`
  and `responses` as field lists, and `curl` and `fetch` samples.
- A field is `{ name, depth, type, required, description, constraints, in }`. Indent by `depth`.
- Descriptions are HTML, from the document's Markdown: render them with `<Html value>`.
- For another API, put its OpenAPI JSON in `public/` and call `apiReference("that-api.json")`.
- In a project with `output: "static"`, the operation page also lists every operation in
  `staticParams`, so the static site builds one page per operation. A static site has no API of its own, so export it with
  `spacepress export --site <hosted site>`: the samples then call that site's API. Without
  `--site` the export refuses, because the samples would name the throwaway WordPress it renders on.

The whole reference: [docs/config-api.md](https://github.com/spacefast/spacepress/blob/main/docs/config-api.md#an-api-reference-on-the-site).
