
// One page per operation, so the static site (`output: "static"`) builds every one of them.
export async function staticParams(): Promise<StaticParams> {
  return apiReference().tags.flatMap((t) => t.operations.map((o) => ({ tag: t.slug, operation: o.slug })));
}
