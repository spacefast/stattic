import { Comments, Content, notFound } from "spacepress/app";
import type { PageProps } from "./$types";

export default function Journal({ item }: PageProps) {
  if (!item) notFound();
  return <article><h1>{item.title}</h1><Content value={item.content} /><Comments for={item} /></article>;
}
