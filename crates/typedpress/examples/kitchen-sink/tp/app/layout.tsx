import { db } from "typedpress";
import { Image, Link, TemplatePart, type LayoutProps } from "typedpress/app";
import logo from "./whisk.svg";
import "./site.css";

export const template = "blank";
export const metadata = {
  title: { default: "Community Kitchen", template: "%s · Community Kitchen" },
  // WordPress's own feed of the journal. tp/wp-hooks/excerpt_more.ts ends its trimmed excerpts.
  links: [{ rel: "alternate", type: "application/rss+xml", href: "/feed/" }],
};

export default async function Layout({ children }: LayoutProps) {
  const kitchen = await db.globals.settings.get();
  return (
    <>
      <TemplatePart slug="header" tagName="header" />
      <main className="kitchen">
        <nav aria-label="Kitchen">
          <Link href="/" prefetch><Image src={logo} alt="" width={24} height={24} priority /> {kitchen.siteTitle}</Link>
          <Link href="/recipes">Recipes</Link>
          <Link href="/handbook">Handbook</Link>
          <Link href="/visits/saturday">Visiting</Link>
          <Link href="/shop">Supplies</Link>
          <Link href="/contact">Contact</Link>
          <Link href="/staff">Staff</Link>
        </nav>
        {kitchen.announcement && <p>{kitchen.announcement}</p>}
        {children}
        {kitchen.links && kitchen.links.length > 0 && (
          <ul className="kitchen-links">{kitchen.links.map((link) => <li><a href={link.href}>{link.label}</a></li>)}</ul>
        )}
      </main>
      <TemplatePart slug="footer" tagName="footer" />
    </>
  );
}
