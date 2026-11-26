import { Content, Link, notFound } from "spacepress/app";
import type { PageProps } from "./$types";

export const metadata = ({ item }: PageProps) => ({ title: item?.name ?? "Kitchen supplies" });

// The product's own page: WooCommerce links here from the cart, the checkout and order mail.
export default function Supply({ item }: PageProps) {
  if (!item) notFound();
  return (
    <article>
      <h1>{item.name}</h1>
      <p>USD {item.price}{item.sku && ` · SKU ${item.sku}`}</p>
      <Content value={item.description} />
      {item.purchasable && <a href={`/cart/?add-to-cart=${item.id}`}>Add to cart</a>}
      <Link href="/shop">All supplies</Link>
    </article>
  );
}
