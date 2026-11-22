export const metadata = { title: "Your cart", robots: { index: false, follow: false } };
export default function CartPage() {
  return <main className="shop-main"><a href="/shop">← Keep shopping</a><h1>Your cart</h1><shop-cart><p>Loading your cart…</p></shop-cart></main>;
}
