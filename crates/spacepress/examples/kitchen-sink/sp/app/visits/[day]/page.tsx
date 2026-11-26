import { notFound } from "spacepress/app";
import type { PageProps, StaticParams } from "./$types";

export const prerender = true;
export const metadata = { title: "Visit the teaching kitchen" };

export function staticParams(): StaticParams {
  return [{ day: "saturday" }];
}

export default function Visit({ params }: PageProps) {
  if (params.day !== "saturday") notFound();
  return <article><h1>Saturday visits</h1><p>Drop by the teaching kitchen between 10 a.m. and noon.</p></article>;
}
