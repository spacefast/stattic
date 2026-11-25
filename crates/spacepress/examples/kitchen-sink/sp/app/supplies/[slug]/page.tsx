import { Content, Link, notFound, type PageProps } from "spacepress/app";

export const metadata = ({ item }: PageProps<"supplies/[slug]">) => ({ title: item?.name ?? "Kitchen supplies" });

// The product's own page: WooCommerce links here from the cart, the checkout and order mail.
export default function Supply({ item }: PageProps<"supplies/[slug]">) {
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
