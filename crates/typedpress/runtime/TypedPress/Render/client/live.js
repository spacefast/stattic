// `useLive(kind, query)` in a "use client" component (docs/spec/rendering.md §4.4). Each live field
// of a store calls `live(context, field)` from its `data-wp-init` callback: it subscribes, through
// the app's typed client (`@typedpress/client`, the same client every other caller runs), to the read
// the server already answered, and writes each new answer into the field. One client per page, so
// every subscription on the page travels in one request.
import { createClient } from "@typedpress/client";
import { getConfig } from "@wordpress/interactivity";

let client;
const page = () => (client ??= createClient({ nonce: getConfig("tp/live").nonce || undefined }));

export function live(context, field) {
  const value = context[field];
  const read = value?.$live;
  if (!read) return undefined;
  const query = page()[read.kind]?.list(read.query);
  if (!query) return undefined;
  const seed = value.error === undefined && read.token ? { token: read.token, data: value.data } : undefined;
  // `data` stays while a later refresh fails, as useQuery keeps it.
  return query.subscribe(
    (data) => { context[field] = { ...context[field], data, error: undefined, loading: false }; },
    (error) => { context[field] = { ...context[field], error: { code: error.code, message: error.message }, loading: false }; },
    seed,
  );
}
