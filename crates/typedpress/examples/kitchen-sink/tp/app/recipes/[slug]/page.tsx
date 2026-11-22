import { Comments, Content, notFound, type PageProps } from "typedpress/app";

export const metadata = ({ item }: PageProps<"recipes/[slug]">) => ({ title: item?.title ?? "Recipe", description: item?.excerpt ?? "" });

export default function Recipe({ item }: PageProps<"recipes/[slug]">) {
  if (!item) notFound();
  return (
    <article>
      <h1>{item.title}</h1>
      <p>Serves {item.servings}</p>
      {/* The kitchen-policy plugin's beforeCreate hook wrote this notice when the recipe was created. */}
      {item.excerpt && <p role="note">{item.excerpt}</p>}
      <Content value={item.content} />
      <Comments for={item} />
    </article>
  );
}
