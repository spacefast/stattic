import { definePlugin } from "spacepress";

// A plugin package: its hooks run before the app's own, and sp/_generated/plugins.lock.json records
// what its code may read and write.
export default function kitchenPolicy(options: { notice: string }) {
  return definePlugin({
    name: "policy",
    hooks: {
      recipes: {
        beforeCreate(data) {
          if (typeof data.title === "string") data.title = data.title.trim();
          // Every recipe carries the kitchen's allergen notice unless it has its own summary.
          if (typeof data.excerpt !== "string" || data.excerpt === "") data.excerpt = options.notice;
        },
      },
    },
  });
}
