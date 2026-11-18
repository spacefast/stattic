import { collection, table, global, text, integer, richText, group, repeater, blocks } from "typedpress";

export const articles = collection({
  title: text(),
  body: richText().optional(),
  rating: integer().optional(),
  // Stored by Secure Custom Fields as `tp_seo`, `tp_seo_title`, and for each link row
  // `tp_links`, `tp_links_<n>_href`: names a runtime field must not take.
  seo: group({ title: text().optional() }).optional(),
  links: repeater({ href: text() }).optional(),
  // Flexible content also keeps a record of its layouts under `_tp_page_layout_meta`.
  page: blocks({ hero: { heading: text().optional() } }).optional(),
});

export const votes = table({ article: integer(), weight: integer().default(1) });

// Stored as the options `tp_global_site_<field>`: a runtime global must not overlap them.
export const site = global({ meta_title: text().optional() });
