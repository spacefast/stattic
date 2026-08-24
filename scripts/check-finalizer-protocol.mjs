// Drift guard for the generated finalizer protocol files. The default is a
// non-destructive check: generate into a temporary directory, format the
// TypeScript output, and compare it to the checked-in copies. Pass --write to
// intentionally replace stale generated files.

import { spawnSync } from "node:child_process";
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";
import { pathToFileURL } from "node:url";

const repoRoot = path.resolve(import.meta.dirname, "..");
const write = process.argv.slice(2).join(" ") === "--write";
if (process.argv.length > 3 || (process.argv.length === 3 && !write)) {
  throw new Error("usage: node scripts/check-finalizer-protocol.mjs [--write]");
}

const outputs = [
  { args: [], file: "packages/routing/src/protocol.generated.ts" },
  { args: ["--php"], file: "runtime/engine/shared/finalizer-protocol.generated.php" },
];

function run(command, args, options = {}) {
  const result = spawnSync(command, args, {
    cwd: repoRoot,
    encoding: "utf8",
    maxBuffer: 64 * 1024 * 1024,
    ...options,
  });
  if (result.error) throw result.error;
  return result;
}

function parsePhpLiteral(literal) {
  if (/^\d+$/.test(literal)) return Number(literal);
  if (literal.startsWith("'") && literal.endsWith("'")) return literal.slice(1, -1);
  // Double-quoted PHP strings carry the `\0`-prefixed response-table keys: NUL
  // is impossible in a request path, so it is what makes a compiled special key
  // uncollidable — and PHP only interprets the escape inside double quotes.
  if (literal.startsWith('"') && literal.endsWith('"')) {
    return literal.slice(1, -1).replace(/\\(x[0-9A-Fa-f]{2}|0|\\|")/g, (_, escape) => {
      if (escape[0] === "x") return String.fromCharCode(Number.parseInt(escape.slice(1), 16));
      return escape === "0" ? "\0" : escape;
    });
  }
  if (literal.startsWith("[") && literal.endsWith("]")) {
    const entries = literal.slice(1, -1);
    return entries === "" ? [] : entries.split(", ").map(parsePhpLiteral);
  }
  throw new Error(`finalizer_protocol_php_literal_invalid:${literal}`);
}

function phpConstants(source) {
  const constants = new Map();
  for (const line of source.split("\n")) {
    const match = line.match(/^const ([A-Z0-9_]+) = (.+);$/);
    if (match) constants.set(match[1], parsePhpLiteral(match[2]));
  }
  return constants;
}

const temporaryRoot = mkdtempSync(path.join(os.tmpdir(), "spacefast-finalizer-protocol-"));
try {
  const generated = new Map();
  for (const { args, file } of outputs) {
    const result = run("cargo", [
      "run",
      "--locked",
      "--quiet",
      "-p",
      "stattic-runtime-compiler",
      "--bin",
      "protocol-codegen",
      "--",
      ...args,
    ]);
    if (result.status !== 0) {
      process.stderr.write(result.stderr ?? "");
      throw new Error(`finalizer_protocol_codegen_failed:${file}`);
    }
    const temporaryFile = path.join(temporaryRoot, path.basename(file));
    writeFileSync(temporaryFile, result.stdout);
    if (file.endsWith(".ts")) {
      const format = run("bun", ["run", "oxfmt", temporaryFile]);
      if (format.status !== 0) {
        process.stderr.write(format.stderr ?? "");
        throw new Error(
          "finalizer_protocol_format_failed: oxfmt is unavailable; run pnpm install and retry",
        );
      }
    }
    generated.set(file, { temporaryFile, source: readFileSync(temporaryFile, "utf8") });
  }

  const tsFile = generated.get(outputs[0].file);
  const phpFile = generated.get(outputs[1].file);
  if (!tsFile || !phpFile) throw new Error("finalizer_protocol_generated_output_missing");
  const { FINALIZER_PROTOCOL } = await import(pathToFileURL(tsFile.temporaryFile).href);
  const constants = phpConstants(phpFile.source);

  const sharedContracts = [
    { name: "STATTIC_RUNTIME_EGRESS_MAX_REDIRECT_HOPS", read: (p) => p.egress.maxRedirectHops },
    {
      name: "STATTIC_RUNTIME_EGRESS_TENANT_FETCH_ALLOWED_SCHEMES",
      read: (p) => p.egress.profiles.tenantFetch.allowedSchemes,
    },
    {
      name: "STATTIC_RUNTIME_EGRESS_PROXY_ROUTE_ALLOWED_SCHEMES",
      read: (p) => p.egress.profiles.proxyRoute.allowedSchemes,
    },
    { name: "STATTIC_RUNTIME_EGRESS_DENIED_IPV4", read: (p) => p.egress.deniedIpv4 },
    { name: "STATTIC_RUNTIME_EGRESS_DENIED_IPV6", read: (p) => p.egress.deniedIpv6 },
    { name: "STATTIC_RUNTIME_EGRESS_INTERNAL_HOSTS", read: (p) => p.egress.internalHosts },
    {
      name: "STATTIC_RUNTIME_EXECUTION_TIMEOUT_MS_DEFAULT",
      read: (p) => p.limits.executionTimeoutMsDefault,
    },
    {
      name: "STATTIC_RUNTIME_EXECUTION_TIMEOUT_MS_MAX",
      read: (p) => p.limits.executionTimeoutMsMax,
    },
    {
      name: "STATTIC_RUNTIME_EXECUTION_MEMORY_BYTES_DEFAULT",
      read: (p) => p.limits.executionMemoryBytesDefault,
    },
    {
      name: "STATTIC_RUNTIME_EXECUTION_MEMORY_BYTES_MAX",
      read: (p) => p.limits.executionMemoryBytesMax,
    },
    {
      name: "STATTIC_RUNTIME_EXECUTION_BODY_BYTES_DEFAULT",
      read: (p) => p.limits.executionBodyBytesDefault,
    },
    {
      name: "STATTIC_RUNTIME_EXECUTION_BODY_BYTES_MAX",
      read: (p) => p.limits.executionBodyBytesMax,
    },
    {
      name: "STATTIC_RUNTIME_EXECUTION_OUTPUT_BYTES_DEFAULT",
      read: (p) => p.limits.executionOutputBytesDefault,
    },
    {
      name: "STATTIC_RUNTIME_EXECUTION_OUTPUT_BYTES_MAX",
      read: (p) => p.limits.executionOutputBytesMax,
    },
    {
      name: "STATTIC_RUNTIME_EXECUTION_SUBREQUESTS_DEFAULT",
      read: (p) => p.limits.executionSubrequestsDefault,
    },
    {
      name: "STATTIC_RUNTIME_EXECUTION_SUBREQUESTS_MAX",
      read: (p) => p.limits.executionSubrequestsMax,
    },
    {
      name: "STATTIC_RUNTIME_EXECUTION_DB_OPERATIONS_DEFAULT",
      read: (p) => p.limits.executionDbOperationsDefault,
    },
    {
      name: "STATTIC_RUNTIME_EXECUTION_DB_OPERATIONS_MAX",
      read: (p) => p.limits.executionDbOperationsMax,
    },
    { name: "STATTIC_RUNTIME_ZERO_BUNDLE_MAX_BYTES", read: (p) => p.limits.zeroBundleMaxBytes },
    { name: "STATTIC_RUNTIME_ZERO_BUNDLE_LIMIT", read: (p) => p.limits.zeroBundleLimit },
    { name: "STATTIC_RUNTIME_ARTIFACT_SCHEMA", read: (p) => p.responses.schema },
    { name: "STATTIC_RUNTIME_ARTIFACT_SCHEMA_MIN", read: (p) => p.responses.schemaMin },
    { name: "STATTIC_RUNTIME_ARTIFACT_SCHEMA_MAX", read: (p) => p.responses.schemaMax },
    { name: "STATTIC_RUNTIME_ARTIFACT_HASH_PREFIX_LEN", read: (p) => p.responses.hashPrefixLen },
    { name: "STATTIC_RUNTIME_VERSION_ROOT_POINTER_FILE", read: (p) => p.responses.rootPointerFile },
    {
      name: "STATTIC_RUNTIME_RESPONSE_TABLE_SPLIT_BYTES",
      read: (p) => p.responses.tableSplitBytes,
    },
    { name: "STATTIC_RUNTIME_RESPONSE_TABLE_MAX_BYTES", read: (p) => p.responses.tableMaxBytes },
    { name: "STATTIC_RUNTIME_RESPONSE_ENTRY_STATUS", read: (p) => p.responses.entryKeys.status },
    { name: "STATTIC_RUNTIME_RESPONSE_ENTRY_HEADERS", read: (p) => p.responses.entryKeys.headers },
    { name: "STATTIC_RUNTIME_RESPONSE_ENTRY_BLOB", read: (p) => p.responses.entryKeys.blob },
    { name: "STATTIC_RUNTIME_RESPONSE_ENTRY_ETAG", read: (p) => p.responses.entryKeys.etag },
    { name: "STATTIC_RUNTIME_RESPONSE_ENTRY_LENGTH", read: (p) => p.responses.entryKeys.length },
    { name: "STATTIC_RUNTIME_RESPONSE_ENTRY_LANE", read: (p) => p.responses.entryKeys.lane },
    { name: "STATTIC_RUNTIME_RESPONSE_ENTRY_ACTION", read: (p) => p.responses.entryKeys.action },
    {
      name: "STATTIC_RUNTIME_RESPONSE_ENTRY_CACHE_CLASS",
      read: (p) => p.responses.entryKeys.cacheClass,
    },
    {
      name: "STATTIC_RUNTIME_RESPONSE_ENTRY_RULES_FIRST",
      read: (p) => p.responses.entryKeys.rulesFirst,
    },
    { name: "STATTIC_RUNTIME_RESPONSE_KEY_SPA", read: (p) => p.responses.specialKeys.spa },
    {
      name: "STATTIC_RUNTIME_RESPONSE_KEY_NOT_FOUND",
      read: (p) => p.responses.specialKeys.notFound,
    },
    {
      name: "STATTIC_RUNTIME_RESPONSE_KEY_NOT_FOUND_PREFIX",
      read: (p) => p.responses.specialKeys.notFoundPrefix,
    },
    { name: "STATTIC_RUNTIME_RESPONSE_KEY_RULES", read: (p) => p.responses.specialKeys.rules },
    { name: "STATTIC_RUNTIME_RESPONSE_KEY_ROBOTS", read: (p) => p.responses.specialKeys.robots },
    {
      name: "STATTIC_RUNTIME_RESPONSE_ENTRY_ALLOWLISTED_EXT",
      read: (p) => p.responses.entryKeys.allowlistedExt,
    },
    {
      name: "STATTIC_RUNTIME_PROVIDER_ASSET_EXTENSIONS",
      read: (p) => p.responses.providerAssetExtensions,
    },
    {
      name: "STATTIC_RUNTIME_PLATFORM_OWNED_HEADER_PREFIXES",
      read: (p) => p.responses.platformOwnedHeaderPrefixes,
    },
    {
      name: "STATTIC_RUNTIME_PLATFORM_OWNED_HEADERS",
      read: (p) => p.responses.platformOwnedHeaders,
    },
    {
      name: "STATTIC_RUNTIME_PHP_LANE_BODY_MAX_BYTES",
      read: (p) => p.responses.phpLaneBodyMaxBytes,
    },
    { name: "STATTIC_RUNTIME_THEME_STYLESHEET_PATH", read: (p) => p.responses.themeStylesheetPath },
    { name: "STATTIC_RUNTIME_THEME_STYLESHEET_URL", read: (p) => p.responses.themeStylesheetUrl },
  ];
  for (const { name, read } of sharedContracts) {
    const phpValue = constants.get(name);
    const tsValue = read(FINALIZER_PROTOCOL);
    if (!constants.has(name) || JSON.stringify(phpValue) !== JSON.stringify(tsValue)) {
      throw new Error(
        `${name}: PHP=${JSON.stringify(phpValue)} TypeScript=${JSON.stringify(tsValue)}`,
      );
    }
  }

  // Constants that were removed by a decision, not renamed. The generated files
  // are rewritten wholesale, so a stale one only shows up as a diff — but a
  // hand-edited engine file reintroducing the name is exactly the drift this
  // guard exists to catch, and the name is what the engine would `use`.
  const withdrawn = [
    // D148: no compression sidecars at all. nginx compresses on the fly in both
    // lanes and ignores sidecar files anyway.
    "STATTIC_RUNTIME_RESPONSE_ENTRY_ENCODINGS",
    "STATTIC_RUNTIME_PRECOMPRESS_MIN_BYTES",
    "STATTIC_RUNTIME_PRECOMPRESS_BROTLI_QUALITY",
    "STATTIC_RUNTIME_PRECOMPRESS_BROTLI_WINDOW",
    "STATTIC_RUNTIME_PRECOMPRESS_GZIP_LEVEL",
    "STATTIC_RUNTIME_PRECOMPRESS_CONTENT_TYPE_PREFIXES",
    "STATTIC_RUNTIME_PRECOMPRESS_CONTENT_TYPES",
    // D100: no asset-link rewrite. A hashed filename is detected, never minted.
    "STATTIC_RUNTIME_ASSET_URL_PREFIX",
  ];
  for (const name of withdrawn) {
    if (constants.has(name)) throw new Error(`${name}: withdrawn constant is still generated`);
  }

  // `content-language` is live-refuted and `accept-ranges` is nginx-generated
  // rather than passed through: an entry carrying either takes the PHP lane, so
  // neither may creep back into the survivor set (D146, §16).
  for (const header of ["content-language", "accept-ranges"]) {
    if (FINALIZER_PROTOCOL.responses.accelSurvivingHeaders.includes(header)) {
      throw new Error(`accelSurvivingHeaders: ${header} does not survive X-Accel-Redirect`);
    }
  }

  // The other direction, and the one the accel lane exists for. Every entry
  // carries a compiled `content-type`; dropping it from the survivor set would
  // not fail anything loudly, it would quietly move every static asset onto the
  // PHP lane and serve the rest without their type.
  if (!FINALIZER_PROTOCOL.responses.accelSurvivingHeaders.includes("content-type")) {
    throw new Error("accelSurvivingHeaders: content-type is on every entry and must survive");
  }

  // A compiled special answers at a key no request can spell. NUL is rejected
  // during path normalization, so the `\0` prefix is the whole of that
  // guarantee — it is what keeps `\0robots` (the deny-all a noindex host
  // serves) from claiming the `/robots.txt` a publisher uploaded, and every
  // other special off a real path. A special respelled without it would shadow
  // published content on every host.
  for (const [name, key] of Object.entries(FINALIZER_PROTOCOL.responses.specialKeys)) {
    if (typeof key !== "string" || !key.startsWith("\0")) {
      throw new Error(`specialKeys.${name}: ${JSON.stringify(key)} must start with NUL`);
    }
  }

  const stale = outputs.filter(
    ({ file }) => readFileSync(path.join(repoRoot, file), "utf8") !== generated.get(file).source,
  );
  if (stale.length > 0 && !write) {
    for (const { file } of stale) {
      const generatedFile = generated.get(file).temporaryFile;
      process.stderr.write(`Generated finalizer protocol file is stale: ${file}\n`);
      const diff = run("git", [
        "diff",
        "--no-index",
        "--",
        path.join(repoRoot, file),
        generatedFile,
      ]);
      process.stderr.write(diff.stdout ?? "");
    }
    process.stderr.write("Run: bun scripts/check-finalizer-protocol.mjs --write\n");
    process.exitCode = 1;
  } else if (write) {
    for (const { file } of outputs)
      writeFileSync(path.join(repoRoot, file), generated.get(file).source);
    console.log("Finalizer protocol generated files were written.");
  } else {
    console.log("Finalizer protocol generated files are current.");
  }
} finally {
  rmSync(temporaryRoot, { recursive: true, force: true });
}
