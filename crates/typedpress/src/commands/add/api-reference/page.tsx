// Written by `typedpress add api-reference`; yours to change. The app's API reference: every operation
// of the OpenAPI document `typedpress build` writes, by tag, each linking to its own page.
import { Html, Link } from "typedpress/app";
import { apiReference } from "typedpress/content";

export const metadata = { title: "API reference", description: "Every operation of this app's HTTP API." };

export default function ApiReference() {
  const api = apiReference();
  return (
    <main className="api-reference">
      <h1>{api.title}</h1>
      <p>
        Base URL: <code>{api.server}</code>
      </p>
      <Html value={api.description} />
      {api.tags.map((t) => (
        <section key={t.slug} id={t.slug}>
          <h2>{t.name}</h2>
          <Html value={t.description} />
          <ul>
            {t.operations.map((o) => (
              <li key={o.id}>
                <Link href={`/api/${t.slug}/${o.slug}`}>{o.summary}</Link> <code>{o.method} {o.path}</code>
              </li>
            ))}
          </ul>
        </section>
      ))}
    </main>
  );
}
