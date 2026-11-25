// Written by `spacepress add api-reference`; yours to change. One operation: who may call it, what it
// takes and answers, and a request to copy.
import { Html, Link, notFound, type PageProps } from "spacepress/app";
import { apiReference } from "spacepress/content";
import { ApiFields } from "../../../../components/ApiFields";

export const metadata = ({ params }: PageProps<"api/[tag]/[operation]">) => {
  const tag = apiReference().tags.find((t) => t.slug === params.tag);
  const op = tag ? tag.operations.find((o) => o.slug === params.operation) : undefined;
  return { title: op ? op.summary : "API reference", description: op ? `${op.method} ${op.path}` : undefined };
};

export default function ApiOperation({ params }: PageProps<"api/[tag]/[operation]">) {
  const tag = apiReference().tags.find((t) => t.slug === params.tag);
  if (!tag) notFound();
  const op = tag.operations.find((o) => o.slug === params.operation);
  if (!op) notFound();
  return (
    <main className="api-operation">
      <p>
        <Link href="/api">API reference</Link> · {tag.name}
      </p>
      <h1>{op.summary}</h1>
      <p>
        <code>{op.method} {op.path}</code>
        {op.access !== "" && <span className="api-access"> · access: {op.access}</span>}
      </p>
      <Html value={op.description} />
      {op.params.length > 0 && <h2>Parameters</h2>}
      {op.params.length > 0 && <ApiFields fields={op.params} />}
      {op.bodyType !== "" && <h2>Body</h2>}
      {op.bodyType !== "" && <p><code>{op.bodyType}</code></p>}
      {op.bodyType !== "" && <ApiFields fields={op.body} />}
      <h2>Responses</h2>
      {op.responses.map((r) => (
        <section key={r.status}>
          <h3>{r.status}</h3>
          <Html value={r.description} />
          <ApiFields fields={r.fields} />
        </section>
      ))}
      <h2>Request</h2>
      <pre className="api-curl"><code>{op.curl}</code></pre>
      <pre className="api-fetch"><code>{op.fetch}</code></pre>
    </main>
  );
}
