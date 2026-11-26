import { integer } from "spacepress";
import { products } from "spacepress/server/db";
import { Pagination } from "spacepress/app";
import { get_option } from "spacepress/server/wp";
export const metadata = { title: "Shop", description: "Simple, useful things for everyday living." };
export const search = { catalogPage: integer().min(1).default(1) };
export default async function Shop({ search }) {
  const currency = String(get_option("woocommerce_currency"));
  const total = await products.count();
  const pages = Math.max(1, Math.ceil(total / 24));
  const page = Math.min(pages, Math.max(1, search.catalogPage ?? 1));
  const items = await products.list({ limit: 24, offset: (page - 1) * 24, orderBy: { id: "asc" } });
  return <main className="shop-main"><p className="shop-eyebrow">The collection</p><h1>Made for everyday.</h1><p className="shop-intro">Simple, useful objects. A little more care in the things you choose.</p><div className="product-grid">{items.map((item) => <article key={item.id} className="product-card"><a href={item.url || "/shop"}><span className="product-placeholder" aria-hidden="true">{item.virtual ? "↗" : "◯"}</span><h2>{item.name}</h2></a><p>{item.price == null ? "Price unavailable" : item.price + " " + currency}</p><p>{item.availability === "outofstock" ? "Out of stock" : item.downloadable ? "Digital download" : item.virtual ? "Available" : "Ready to ship"}</p></article>)}</div><Pagination page={page} pages={pages} param="catalogPage" /></main>;
}
