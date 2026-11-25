import { timestamp } from "spacepress";

export const UNITS = ["pcs", "g", "kg", "ml", "l"] as const;
export const LOW_STOCK = 1;

export const timestamps = {
  createdAt: timestamp().defaultNow(),
  updatedAt: timestamp().defaultNow().$onUpdate(() => new Date()),
};

export function slugify(name: string): string {
  return name
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}
