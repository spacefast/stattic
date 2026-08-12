/* eslint-disable no-underscore-dangle, unicorn/consistent-function-scoping -- Zero host globals are part of the generated runtime ABI. */
const __statticZeroBootstrap = globalThis.__statticZeroBootstrap || {};
globalThis.__statticZeroRequest = __statticZeroBootstrap.request || {};
globalThis.__statticZeroContext = __statticZeroBootstrap.context || {};
globalThis.__statticZeroCapabilities = __statticZeroBootstrap.capabilities || {};
globalThis.__statticZeroEndpoint = __statticZeroBootstrap.endpoint || {};
globalThis.__statticZeroTemplateCapabilities = __statticZeroBootstrap.capabilities || {};
globalThis.__statticZeroEvents = [];
globalThis.__statticZeroResult = "";

// @stattic-if logging
globalThis.__statticLog = function __statticLog(level, message, metadata) {
  globalThis.__statticZeroEvents.push({
    event: "zero.log",
    level: String(level || "info"),
    message: String(message || ""),
    metadata: metadata && typeof metadata === "object" && !Array.isArray(metadata) ? metadata : {},
    request_id: globalThis.__statticZeroRequest.headers?.["x-request-id"] || null,
    mutation_name: globalThis.__statticZeroEndpoint.endpointId || null,
  });
};
// @stattic-endif

// @stattic-if db
if (typeof globalThis.__statticDbHost === "function") {
  globalThis.__statticDb = function __statticDb(operation) {
    return globalThis.__statticDbHost(String(operation ?? ""));
  };
}
// @stattic-endif

// @stattic-if services
// The bridge only. The author-facing gravatar/spam/email clients are the shared
// source from @spacefast/common, installed over this by the generated host —
// the same source the Functions binding uses, so neither tier can drift.
if (typeof globalThis.__statticServiceHost === "function") {
  globalThis.__statticService = function __statticService(service, operation, payload) {
    const answer = JSON.parse(
      globalThis.__statticServiceHost(
        JSON.stringify({ service: service, operation: operation, payload: payload || {} }),
      ),
    );
    if (answer.ok !== true) {
      const error = new Error(answer.message || "The service refused the call.");
      error.name = "SpacefastServiceError";
      error.code = answer.code || "service_upstream_unavailable";
      throw error;
    }
    return answer.result === undefined ? null : answer.result;
  };
}
// @stattic-endif

// @stattic-if fetch
globalThis.__statticFetch = async function __statticFetch(input, init = {}) {
  if (typeof globalThis.__statticFetchHost !== "function") {
    throw new Error("zero_fetch_unavailable");
  }
  const url =
    typeof input === "string" || (typeof URL !== "undefined" && input instanceof URL)
      ? String(input)
      : String(input?.url || "");
  const method = String(init.method || input?.method || "GET").toUpperCase();
  const headers = Object.fromEntries(new globalThis.Headers(init.headers || input?.headers).entries());
  const body = init.body === undefined ? input?.body : init.body;
  const answer = JSON.parse(
    globalThis.__statticFetchHost(
      JSON.stringify({
        url,
        method,
        headers,
        bodyBase64: endpointEncodeBase64(endpointResponseBodyBytes(body)),
      }),
    ),
  );
  if (answer.ok !== true) {
    const error = new Error(answer.message || "The fetch target could not be reached.");
    error.name = "SpacefastFetchError";
    error.code = answer.code || "zero_fetch_upstream_unavailable";
    throw error;
  }
  return new globalThis.Response(decodeBase64Bytes(answer.result.bodyBase64 || ""), {
    status: answer.result.status,
    headers: answer.result.headers,
  });
};
globalThis.fetch = globalThis.__statticFetch;
// @stattic-endif

// @stattic-if auth
globalThis.__statticAuth = Object.freeze({
  current() {
    return __statticZeroBootstrap.auth || null;
  },
});
// @stattic-endif

// @stattic-if env
globalThis.__statticEnv = Object.freeze(__statticZeroBootstrap.variables || {});
// @stattic-endif

// @stattic-if realtime
globalThis.__statticRealtime = Object.freeze({
  publish(input) {
    const payload = input && typeof input === "object" && !Array.isArray(input) ? input : {};
    const changedTables = Array.isArray(payload.changedTables)
      ? payload.changedTables
      : Array.isArray(payload.tables)
        ? payload.tables
        : [];
    const changedQueries = Array.isArray(payload.changedQueries)
      ? payload.changedQueries
      : Array.isArray(payload.invalidate)
        ? payload.invalidate
        : [];
    const eventId =
      typeof payload.eventId === "string" && payload.eventId
        ? payload.eventId
        : `evt_${Date.now().toString(36)}_${Math.random().toString(36).slice(2)}`;
    globalThis.__statticZeroEvents.push({
      event: "zero.realtime",
      payload: {
        ...payload,
        eventId,
        runtimeKind: "zero",
        spaceId: payload.spaceId || globalThis.__statticZeroContext.spaceId,
        versionId: payload.versionId || globalThis.__statticZeroContext.versionId,
        requestId:
          payload.requestId || globalThis.__statticZeroRequest.headers?.["x-request-id"] || eventId,
        mutationName: payload.mutationName || globalThis.__statticZeroEndpoint.endpointId || null,
        changedTables,
        changedQueries,
        schemaHash: payload.schemaHash || globalThis.__statticZeroContext.schemaHash || null,
        committedAt: payload.committedAt || new Date().toISOString(),
      },
    });
    return { ok: true, eventId };
  },
});
// @stattic-endif
