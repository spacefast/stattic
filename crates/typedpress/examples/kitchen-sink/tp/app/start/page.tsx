import { redirect } from "typedpress/app";

export const route = { id: "kitchen.start" };

export default function Start() {
  redirect("/about", 301);
}
