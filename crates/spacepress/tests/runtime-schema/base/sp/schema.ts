import { collection, table, global, text, integer, richText, group, repeater, blocks } from "spacepress";

export const articles = collection({
  title: text(),
  body: richText().optional(),
  rating: integer().optional(),
  // Stored by Secure Custom Fields as `sp_seo`, `sp_seo_title`, and for each link row
  // `sp_links`, `sp_links_<n>_href`: names a runtime field must not take.
  seo: group({ title: text().optional() }).optional(),
  links: repeater({ href: text() }).optional(),
  // Flexible content also keeps a record of its layouts under `_sp_page_layout_meta`.
  page: blocks({ hero: { heading: text().optional() } }).optional(),
});

export const votes = table({ article: integer(), weight: integer().default(1) });

// Stored as the options `sp_global_site_<field>`: a runtime global must not overlap them.
export const site = global({ meta_title: text().optional() });
