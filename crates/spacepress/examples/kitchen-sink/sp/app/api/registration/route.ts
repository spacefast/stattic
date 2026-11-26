import { env } from "spacepress";
import { db } from "spacepress/server";
import { sanitize_title } from "spacepress/server/wp";
import type { RouteRequest, RouteResponse } from "spacepress/app";
import { events } from "../../../schema";

export const signature = { secret: env("KITCHEN_REGISTRATION_SECRET"), header: "x-kitchen-signature", scheme: "hmac-sha256" };

// A signed call from the kitchen's booking desk closes a workshop: ?workshop=Bread Basics or bread-basics.
export async function POST(request: RouteRequest): Promise<RouteResponse> {
  const workshop = await db.query.events.findFirst({ where: { slug: sanitize_title(request.query.get("workshop") ?? "") } });
  if (!workshop) return { status: 404, body: "Workshop not found." };
  await db.update(events).set({ registrationOpen: false }).where({ id: workshop.id });
  return { status: 204 };
}
