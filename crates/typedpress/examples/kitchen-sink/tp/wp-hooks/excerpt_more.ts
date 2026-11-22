import type { Handler } from "php/wordpress";

// WordPress ends a trimmed excerpt with this: in the journal's feed (/feed/) and wherever a theme
// or block prints an excerpt.
const more: Handler<"excerpt_more"> = () => " … keep reading on the journal.";
export default more;
