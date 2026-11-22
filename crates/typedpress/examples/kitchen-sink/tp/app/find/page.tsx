import { integer, text } from "typedpress";
import { Search, SearchResults, type PageProps } from "typedpress/app";

export const search = { q: text().optional(), page: integer().min(1).default(1) };
export const metadata = { title: "Search workshops" };

export default function Find({ search }: PageProps<"find", { q?: string; page: number }>) {
  return <section><h1>Search workshops</h1><Search /><SearchResults kind="events" q={search.q} page={search.page} /></section>;
}
