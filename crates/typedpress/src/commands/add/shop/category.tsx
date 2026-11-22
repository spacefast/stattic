import { integer } from "typedpress";
import { products } from "typedpress:db";
import { Pagination } from "typedpress/app";
import { get_option } from "php/wordpress";
export const search = { catalogPage: integer().min(1).default(1) };
export default async function CategoryPage({ item, search }) {
  const currency = String(get_option("woocommerce_currency"));
  const where = { categories: { has: item.id } };
  const total = await products.count({ where });
  const pages = Math.max(1, Math.ceil(total / 24));
  const page = Math.min(pages, Math.max(1, search.catalogPage ?? 1));
  const items = await products.list({ where, limit: 24, offset: (page - 1) * 24, orderBy: { id: "asc" } });
  return <main className="shop-main"><a href="/shop">← All products</a><h1>{item.name}</h1><div className="product-grid">{items.map((product) => <article key={product.id} className="product-card"><a href={product.url || "/shop"}><h2>{product.name}</h2></a><p>{product.price == null ? "Price unavailable" : product.price + " " + currency}</p></article>)}</div><Pagination page={page} pages={pages} param="catalogPage" /></main>;
}
