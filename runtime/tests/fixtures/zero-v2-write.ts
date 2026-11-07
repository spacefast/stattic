import { astroBlogFiles } from "../../../apps/my/src/lib/write/astro-blog.js";

/** Shared with the hosted WordPress seed proof. Uses the real Write generator. */
export function zeroV2WriteFixture() {
  return astroBlogFiles({
    title: "A first post",
    markdown: "Hello **Zero**. This post came from Write.",
    publishedAt: "2026-11-06T00:00:00Z",
  });
}
