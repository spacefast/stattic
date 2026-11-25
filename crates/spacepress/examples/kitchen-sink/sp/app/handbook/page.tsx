import { Link } from "spacepress/app";
import { nav } from "spacepress/content";
import { handbook } from "../../schema";

export const metadata = { title: "Kitchen handbook" };

export default async function HandbookIndex() {
  const chapters = await nav(handbook);
  return <section><h1>Kitchen handbook</h1><ul>{chapters.map((chapter) => <li><Link href={chapter.href}>{chapter.title}</Link></li>)}</ul></section>;
}
