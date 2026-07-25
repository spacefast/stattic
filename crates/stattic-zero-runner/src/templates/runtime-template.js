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

// @stattic-if fetch
globalThis.__statticFetch = async function __statticFetch() {
  throw new Error("zero_fetch_unavailable");
};
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
