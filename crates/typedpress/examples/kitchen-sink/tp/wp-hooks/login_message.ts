import { esc_attr, esc_html, esc_url, get_option, home_url, type Handler } from "php/wordpress";

// The notice above the sign-in form (tp/app/login.tsx shows it). A filter returns markup, so every
// value placed in it is escaped for where it lands.
const welcome: Handler<"login_message"> = (message) => {
  const site = String(get_option("blogname"));
  return `${message}<p class="message">Members of ${esc_html(site)} sign in to reserve seats. `
    + `<a href="${esc_url(home_url("/about/"))}" title="${esc_attr(`About ${site}`)}">About the kitchen</a></p>`;
};
export default welcome;
