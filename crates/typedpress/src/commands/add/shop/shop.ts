import { store, product, productCategory, productTag, order } from "typedpress";

export const shop = store({
  currency: "EUR",
  country: "PL",
  address: { line1: "Rynek 1", city: "Kraków", postcode: "31-042" },
  sellTo: ["PL", "DE"],
  shipping: [{ name: "Poland", countries: ["PL"], methods: [
    { type: "flatRate", title: "Courier", cost: "15.00" },
    { type: "freeShipping", title: "Free over 200", minimum: "200.00" },
  ] }, { name: "Germany", countries: ["DE"], methods: [
    { type: "flatRate", title: "Courier", cost: "25.00" },
  ] }],
  payments: ["cod"],
  email: { fromName: "TypedPress Shop", fromAddress: "shop@example.com" },
});
export const products = product({ sync: "content/products/*.md", url: "/products/:slug" });
export const categories = productCategory({ sync: "content/product-categories/*.md", url: "/shop/:path" });
export const tags = productTag({ sync: "content/product-tags/*.md" });
export const orders = order();
