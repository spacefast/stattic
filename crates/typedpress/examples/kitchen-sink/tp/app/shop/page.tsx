import { db } from "typedpress";
import { Link } from "typedpress/app";

export const metadata = { title: "Kitchen supplies" };

export default async function Supplies() {
  const supplies = await db.query.supplies.findMany({ limit: 20 });
  return (
    <>
      <h1>Kitchen supplies</h1>
      {supplies.map((supply) => (
        <article>
          <h2><Link href={`/supplies/${supply.slug}`}>{supply.name}</Link></h2>
          {supply.shortDescription && <p>{supply.shortDescription}</p>}
          {supply.purchasable && <a href={`/cart/?add-to-cart=${supply.id}`}>Add to cart · USD {supply.price}</a>}
        </article>
      ))}
      <Link href="/cart">View cart</Link>
    </>
  );
}
