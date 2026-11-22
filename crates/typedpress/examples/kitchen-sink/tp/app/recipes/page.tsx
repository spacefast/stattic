import { text } from "typedpress";
import { recipes } from "typedpress:db";
import { Link, type PageProps } from "typedpress/app";

export const search = { after: text().optional() };
export const metadata = { title: "Recipes" };

export default async function Recipes({ search }: PageProps<"recipes", { after?: string }>) {
  // The kind API reads as the visitor: published recipes only, ten at a time by cursor.
  const page = await recipes.paginate({ orderBy: { title: "asc" }, limit: 10, cursor: search.after ?? null, with: { topicIds: true } });
  return (
    <section>
      <h1>Recipes</h1>
      {page.rows.length === 0 && <p>No recipes yet.</p>}
      <ul>
        {page.rows.map((recipe) => (
          <li>
            <Link href={`/recipes/${recipe.slug}`}>{recipe.title}</Link> · serves {recipe.servings}
            {recipe.topicIds.map((topic) => <span className="kitchen-topic" style={{ borderColor: topic.color ?? "currentColor" }}>{topic.name}</span>)}
          </li>
        ))}
      </ul>
      {page.cursor && <Link href={`/recipes?after=${page.cursor}`}>More recipes</Link>}
    </section>
  );
}
