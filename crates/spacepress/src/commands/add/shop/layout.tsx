import { MiniCart } from "../../components/MiniCart";
import "../../shop.css";
export const template = "blank";
export const metadata = { title: { default: "SpacePress Shop", template: "%s · Shop" }, scripts: ["/shop.js"] };
export default function Layout({ children }) {
  return <div className="storefront"><header className="shop-header"><a href="/shop" className="shop-brand">Everyday objects</a><nav aria-label="Shop"><a href="/shop">Shop</a><MiniCart /></nav></header>{children}<footer className="shop-footer">Thoughtful things for everyday living.</footer></div>;
}
