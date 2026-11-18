// Untyped JavaScript for tests/differential/language/host-platform.ts: platform detection as npm
// packages write it. `navigator.platform` and `process.platform` are read when PHP runs, from
// TypedPress\Host; `typeof navigator` and `typeof process` are "object".

// picomatch 4.0.7 lib/utils.js, verbatim.
const isWindows = () => {
  if (typeof navigator !== 'undefined' && navigator.platform) {
    const platform = navigator.platform.toLowerCase();
    return platform === 'win32' || platform === 'windows';
  }

  if (typeof process !== 'undefined' && process.platform) {
    return process.platform === 'win32';
  }

  return false;
};

export function picomatch() {
  return { isWindows: isWindows(), type: typeof isWindows() };
}

// Evaluated at build time (module top level): the same constant answers as at request time.
const topLevel = [typeof navigator, typeof process, typeof globalThis.navigator, typeof globalThis.process];

export function detection() {
  return {
    requestTime: [typeof navigator, typeof process, typeof globalThis.navigator, typeof globalThis.process],
    buildTime: topLevel,
  };
}

export function platforms() {
  return { navigator: navigator.platform, process: process.platform };
}

export function guarded() {
  const out = {};
  out.navigatorValue = typeof navigator !== 'undefined' && navigator.platform;
  out.processValue = typeof process !== 'undefined' && process.platform;
  if (typeof navigator !== 'undefined' && navigator.platform) out.navigatorBranch = 'navigator';
  else out.navigatorBranch = 'none';
  if (typeof process === 'undefined' || !process.platform) out.processBranch = 'none';
  else out.processBranch = 'process';
  out.ternary = typeof navigator === 'object' ? 'object' : 'other';
  return out;
}

export function members() {
  return {
    lower: navigator.platform.toLowerCase(),
    win32: process.platform === 'win32',
    optionalNavigator: navigator?.platform,
    optionalProcess: process?.platform,
    length: process.platform.length > 0,
    joined: `${process.platform}/${navigator.platform}`,
  };
}

export function shadowed() {
  const navigator = { platform: 'x' };
  const read = (process) => process.platform;
  return [navigator.platform, typeof navigator, read({ platform: 'y' })];
}

export function withEnv() {
  return [process.platform, process.env.TP_HOST_PLATFORM_UNSET ?? 'unset'];
}
