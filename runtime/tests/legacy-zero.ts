// The one seam between the runtime suites (and the e2e runtime contract that
// reuses their helpers) and Zero 0.8 in legacy/zero-0.8. They import the
// compiler and SDK sources from here, so deleting 0.8 is deleting
// legacy/zero-0.8 and then this file's callers. The spacefast/oxlint
// `no-legacy-zero-imports` rule enforces it.

export { assembleZeroCapsulePublish } from "../../legacy/zero-0.8/packages/zero-compile/src/capsule.js";
export { generateContentModelPhp } from "../../legacy/zero-0.8/packages/zero-compile/src/content-model-php.js";
export {
  compileZeroContentModel,
  parseContentDeclarations,
} from "../../legacy/zero-0.8/packages/zero-compile/src/content-model.js";
export { ZERO_RUNTIME_HOST_SOURCE } from "../../legacy/zero-0.8/packages/zero-compile/src/runtime-host.js";
