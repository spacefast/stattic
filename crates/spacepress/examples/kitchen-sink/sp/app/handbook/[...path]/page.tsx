import { Content, Link, notFound, type PageProps } from "spacepress/app";
import { headings, nav, pager } from "spacepress/content";
import { handbook } from "../../../schema";

export default async function Handbook({ item }: PageProps<"handbook/[...path]">) {
  if (!item) notFound();
  const chapters = await nav(handbook, item);
  const nearby = await pager(handbook, item);
  const previous = nearby.prev;
  const next = nearby.next;
  return (
    <article>
      <h1>{item.title}</h1>
      <ul>{chapters.map((chapter) => <li><a href={chapter.href}>{chapter.title}</a></li>)}</ul>
      {item && (
        <>
          <ul>{headings(item).map((heading) => <li><Link href={`#${heading.id}`}>{heading.text}</Link></li>)}</ul>
          <Content value={item.content} />
        </>
      )}
      {previous && <a href={previous.href}>Previous: {previous.title}</a>}
      {next && <a href={next.href}>Next: {next.title}</a>}
    </article>
  );
}
