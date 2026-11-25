import { db } from "spacepress";
import { get_permalink, wp_strip_all_tags } from "php/wordpress";
import type { RouteRequest, RouteResponse } from "spacepress/app";

export const guard = "anyone";

export async function GET(request: RouteRequest): Promise<RouteResponse> {
  const rows = await db.query.events.findMany({
    where: { registrationOpen: true, status: "published" },
    columns: { id: true, title: true, startsOn: true, description: true },
    orderBy: { startsOn: "asc" },
    limit: 10,
  });
  // Plain text and absolute links, for a calendar or a newsletter that reads this feed.
  const workshops = rows.map((workshop) => ({
    title: workshop.title,
    startsOn: workshop.startsOn,
    summary: wp_strip_all_tags(workshop.description ?? ""),
    url: get_permalink(workshop.id) || null,
  }));
  return {
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ kitchen: request.query.get("kitchen") ?? "main", workshops }),
  };
}
