// `typeof navigator` / `typeof process` and `navigator.platform` / `process.platform`, as npm
// packages detect Windows (picomatch 4.0.7's isWindows is included verbatim). Both `typeof` forms
// are the constant "object"; the two reads are strings computed by PHP when it runs
// (TypedPress\Host), never on the build machine. Every action runs under Node and as compiled PHP on the
// same machine; the JSON results must be byte-identical. See
// docs/decisions/2026-11-14-host-platform-detection.md.
import { defineConfig, mutation } from "typedpress";
import { detection as detectionImpl, guarded as guardedImpl, members as membersImpl, picomatch as picomatchImpl, platforms as platformsImpl, shadowed as shadowedImpl, withEnv as withEnvImpl } from "./host-platform-helpers.mjs";

const run = (handler: () => unknown) => mutation({}, handler);

function typedDetectionImpl(): string[] {
  return [typeof navigator, typeof process, typeof globalThis.navigator, typeof globalThis.process];
}

function typedPlatformsImpl(): { navigator: string; process: string; windows: boolean } {
  const windows = navigator.platform.toLowerCase() === "win32" || process.platform === "win32";
  return { navigator: navigator.platform, process: process.platform, windows };
}

function typedGuardImpl(): string {
  if (typeof process !== "undefined" && process.platform) return `process:${process.platform}`;
  return "none";
}

function typedShadowImpl(process: { platform: string }): string {
  return process.platform;
}

function typedEnvImpl(): string[] {
  return [process.platform, process.env.TP_HOST_PLATFORM_UNSET ?? "unset"];
}

export const picomatch = run(() => picomatchImpl());
export const detection = run(() => detectionImpl());
export const platforms = run(() => platformsImpl());
export const guarded = run(() => guardedImpl());
export const members = run(() => membersImpl());
export const shadowed = run(() => shadowedImpl());
export const withEnv = run(() => withEnvImpl());
export const typedDetection = run(() => typedDetectionImpl());
export const typedPlatforms = run(() => typedPlatformsImpl());
export const typedGuard = run(() => typedGuardImpl());
export const typedShadow = run(() => typedShadowImpl({ platform: "z" }));
export const typedEnv = run(() => typedEnvImpl());

export default defineConfig({ name: "language-host-platform" });
