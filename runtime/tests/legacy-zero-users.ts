// Zero 0.8's Space Users verifier for space-users-http.ts. Apart from
// ./legacy-zero.ts because the e2e runtime contract imports that helper, and
// the e2e typecheck must not compile the capsule compiler's sources.

export { verifyUser } from "../../legacy/zero-0.8/packages/zero/src/users.js";
