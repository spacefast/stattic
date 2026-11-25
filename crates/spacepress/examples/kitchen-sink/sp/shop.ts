import { order, product, productCategory, productTag, store } from "spacepress";

export const shop = store({
  currency: "USD", country: "US", sellTo: ["US"], payments: ["cod"],
  shipping: [{ name: "United States", countries: ["US"], methods: [{ type: "localPickup", title: "Kitchen pickup" }] }],
});
// `url` is each product's address: WooCommerce's own links (cart, checkout, order mail) point there.
export const supplies = product({ label: "Kitchen supplies", sync: "content/supplies/*.md", url: "/supplies/:slug" });
export const supplyCategories = productCategory();
export const supplyTags = productTag();
export const orders = order();
