import { db, integer } from "spacepress";
import { events } from "spacepress:db";
import { Link, Pagination, type PageProps } from "spacepress/app";
import { WorkshopBoard } from "../components/WorkshopBoard";

export const search = { page: integer().min(1).default(1) };
export const metadata = { description: "Workshops, recipes, and supplies at the Community Kitchen." };

export default async function Home({ search }: PageProps<"/", { page: number }>) {
  const pageSize = 10;
  const workshops = await events.list({
    orderBy: { startsOn: "asc" }, offset: (search.page - 1) * pageSize, limit: pageSize,
  });
  const count = await events.count();
  const journal = await db.query.posts.findMany({ limit: 5 });
  return (
    <>
      <h1>Workshops</h1>
      <Link href="/find">Search workshops</Link>
      <ul>{workshops.map((event) => <li><Link href={`/events/${event.slug}`}>{event.title}</Link> · {event.startsOn}</li>)}</ul>
      {workshops.length === 0 && <p>No workshops are scheduled.</p>}
      <Pagination page={search.page} pages={Math.ceil(count / pageSize)} />
      <WorkshopBoard />
      <h2>From the journal</h2>
      <ul>{journal.map((post) => <li><Link href={`/journal/${post.slug}`}>{post.title}</Link></li>)}</ul>
      <Link href="/about">About the kitchen</Link>
    </>
  );
}
