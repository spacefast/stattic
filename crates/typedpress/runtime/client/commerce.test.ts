import { test } from "node:test";
import assert from "node:assert/strict";
import { createCartClient, wooDecimal, WooStoreError } from "./commerce.ts";
const empty = {
  items: [], items_count: 0, needs_shipping: true, coupons: [], shipping_rates: [],
  totals: { total_items: "900719925474099301", total_price: "900719925474099301", total_shipping: null, total_discount: "0", total_tax: "0", currency_code: "EUR", currency_minor_unit: 2 },
};
test("cart money keeps exact minor units and currency precision", () => {
  assert.equal(wooDecimal("900719925474099301", 2), "9007199254740993.01");
  assert.equal(wooDecimal("9", 3), "0.009");
  assert.equal(wooDecimal("123", 0), "123");
  assert.equal(wooDecimal("-5", 2), "-0.05");
  assert.throws(() => wooDecimal("1.2", 2));
});
test("cart fees retain Woo's distinct keys and exact amounts", async () => {
  const client = createCartClient({ fetch: (async () => Response.json({ ...empty, fees: [
    { key: 'handling', name: 'Handling', totals: { total: '125', total_tax: '25' } },
    { key: 'gift', name: 'Gift wrap', totals: { total: '200', total_tax: '0' } },
  ] })) as typeof fetch });
  assert.deepEqual((await client.get()).fees, [
    { id: 'handling', name: 'Handling', total: '1.25', totalTax: '0.25' },
    { id: 'gift', name: 'Gift wrap', total: '2.00', totalTax: '0.00' },
  ]);
});
test("cart writes initialize and rotate the Woo nonce in one cookie session, serialized", async () => {
  const calls: { url: string; init: RequestInit }[] = [];
  const client = createCartClient({ fetch: (async (url, init) => {
    calls.push({url: String(url), init: init!});
    return Response.json(empty, {headers: {Nonce: `nonce-${calls.length}`}});
  }) as typeof fetch });
  const [first] = await Promise.all([client.add(42), client.update("item", 2)]);
  assert.equal(calls.length, 3);
  assert.deepEqual(calls.map(c => c.init.method), ["GET", "POST", "POST"]);
  assert.deepEqual(calls.map(c => new Headers(c.init.headers).get("Nonce")), [null, "nonce-1", "nonce-2"]);
  assert.ok(calls.every(c => c.init.credentials === "same-origin" && c.init.cache === "no-store" && !new Headers(c.init.headers).has("Cart-Token")));
  assert.equal(first.totals.totalPrice, "9007199254740993.01");
});
test("a rejected nonce refreshes before retry, and refusals do not poison later writes", async () => {
  let calls = 0;
  const client = createCartClient({ fetch: (async () => {
    calls++;
    if (calls === 2) return Response.json({code: "woocommerce_rest_invalid_nonce", message: "Nonce is invalid.", data: {status: 403}}, {status: 403});
    // QuantityLimits::validate_cart_item_quantity, sent with a 400 by CartController::set_cart_item_quantity.
    if (calls === 5) return Response.json({code: "invalid_quantity", message: "The maximum quantity of &quot;Mug &amp; saucer&quot; allowed in the cart is 3", data: {status: 400}}, {status: 400});
    return Response.json(empty, {headers: {Nonce: `nonce-${calls}`}});
  }) as typeof fetch });
  await client.add(1);
  assert.equal(calls, 4);
  await assert.rejects(client.update("one", 99), (error: WooStoreError) => {
    assert.equal(error.status, 400);
    assert.equal(error.code, "invalid_quantity");
    assert.equal(error.message, 'The maximum quantity of "Mug & saucer" allowed in the cart is 3');
    assert.equal(error.cart, undefined);
    return true;
  });
  await client.remove("one");
  assert.equal(calls, 6);
});

const checkoutInput = {
  billing_address: { first_name: "Test", last_name: "Buyer", email: "buyer@example.test" },
  payment_method: "cod",
};

/** Runs with browser globals in place of Node's, and puts Node's back. */
async function browser(globals: { localStorage?: PropertyDescriptor; location?: string }, run: () => Promise<void>) {
  const set: Record<string, PropertyDescriptor> = {};
  if (globals.localStorage) set.localStorage = { configurable: true, ...globals.localStorage };
  if (globals.location) set.location = { configurable: true, value: new URL(globals.location) };
  const before = Object.keys(set).map(name => [name, Object.getOwnPropertyDescriptor(globalThis, name)] as const);
  for (const [name, descriptor] of Object.entries(set)) Object.defineProperty(globalThis, name, descriptor);
  try { await run(); }
  finally {
    for (const [name, descriptor] of before) {
      if (descriptor) Object.defineProperty(globalThis, name, descriptor);
      else Reflect.deleteProperty(globalThis, name);
    }
  }
}
function memoryStorage(stored = new Map<string, string>()) {
  return { value: {
    getItem: (key: string) => stored.get(key) ?? null,
    setItem: (key: string, value: string) => { stored.set(key, value); },
    removeItem: (key: string) => { stored.delete(key); },
  } };
}
type Call = { url: string; method: string | undefined; token: string | null; nonce: string | null; credentials: RequestCredentials | undefined };
const call = (url: RequestInfo | URL, init?: RequestInit): Call => {
  const headers = new Headers(init?.headers);
  return { url: String(url), method: init?.method, token: headers.get("Cart-Token"), nonce: headers.get("Nonce"), credentials: init?.credentials };
};

test("a token cart sends no cookie and no nonce, and checks out on the same Store API session", async () => {
  const calls: Call[] = [];
  const stored = new Map<string, string>();
  await browser({ localStorage: memoryStorage(stored) }, async () => {
    const base = "https://shop.example.test/wp-json/wc/store/v1";
    const result = { order_id: 5, status: "processing", payment_result: { payment_status: "success", redirect_url: "https://shop.example.test/checkout/order-received/5/", payment_details: [] } };
    const transport: typeof fetch = async (url, init) => {
      assert.equal(init?.cache, "no-store");
      assert.equal(init?.redirect, "error");
      calls.push(call(url, init));
      // Woo sends a nonce on every cart response; a token session never needs it back.
      return Response.json(String(url).endsWith("/checkout") ? result : empty, {
        headers: { "Cart-Token": `token-${calls.length}`, Nonce: `nonce-${calls.length}` },
      });
    };
    const client = createCartClient({ base, session: "token", fetch: transport });
    await client.add(42);
    // A reload, or another tab: a new client finds the token in storage.
    const reloaded = createCartClient({ base: `${base}/`, session: "token", fetch: transport });
    await reloaded.get();
    // The first client sends the token the other one saved, not the one it last saw itself.
    await client.get();
    assert.deepEqual(await reloaded.checkout(checkoutInput), result);
    const otherStore = createCartClient({ base: base.replace("shop.", "other."), session: "token", fetch: transport });
    await otherStore.get();
    const sent = (url: string, method: string, token: string | null): Call => ({ url, method, token, nonce: null, credentials: "omit" });
    assert.deepEqual(calls, [
      sent(`${base}/cart`, "GET", null),
      sent(`${base}/cart/add-item`, "POST", "token-1"),
      sent(`${base}/cart`, "GET", "token-2"),
      sent(`${base}/cart`, "GET", "token-3"),
      sent(`${base}/checkout`, "POST", "token-4"),
      // The order emptied the cart: the client reads it for its subscribers.
      sent(`${base}/cart`, "GET", "token-5"),
      sent("https://other.example.test/wp-json/wc/store/v1/cart", "GET", null),
    ]);
    assert.deepEqual([...stored.keys()].sort(), [
      "typedpress:woo-cart:https://other.example.test/wp-json/wc/store/v1",
      "typedpress:woo-cart:https://shop.example.test/wp-json/wc/store/v1",
    ]);
  });
});

test("a token cart stays in memory when storage is blocked, and checkout without a token is refused", async () => {
  await browser({ localStorage: { get() { throw new Error("blocked"); } } }, async () => {
    const tokens: (string | null)[] = [];
    const client = createCartClient({
      session: "token",
      fetch: async (_url, init) => {
        tokens.push(new Headers(init?.headers).get("Cart-Token"));
        return Response.json(empty, { headers: { "Cart-Token": `token-${tokens.length}` } });
      },
    });
    await assert.rejects(client.checkout(checkoutInput), { code: "cart_token_unavailable" });
    assert.throws(() => client.checkoutLink("https://shop.example.test/checkout/"), { code: "cart_token_unavailable" });
    await client.add(42);
    await client.get();
    assert.deepEqual(tokens, [null, "token-1", "token-2"]);
    // A relative Store API root outside a browser names no origin to hold the link to.
    assert.throws(() => client.checkoutLink("https://shop.example.test/checkout/"), /Store API's origin/);
  });
});

test("a token cart refuses a write when Woo's first response carries no token", async () => {
  const methods: (string | undefined)[] = [];
  const client = createCartClient({
    session: "token",
    fetch: async (_url, init) => {
      methods.push(init?.method);
      return Response.json(empty, { headers: { Nonce: "only-nonce" } });
    },
  });
  await assert.rejects(client.add(42), { code: "cart_token_unavailable" });
  assert.deepEqual(methods, ["GET"]);
});

test("an expired token starts a new cart for a write, and refuses checkout", async () => {
  const stored = new Map([["typedpress:woo-cart:/wp-json/wc/store/v1", "expired"]]);
  await browser({ localStorage: memoryStorage(stored) }, async () => {
    const calls: Call[] = [];
    // Woo treats a token past its expiry as no token: a write is then asked for a nonce.
    const transport: typeof fetch = async (url, init) => {
      calls.push(call(url, init));
      if (init?.method === "POST" && new Headers(init.headers).get("Cart-Token") === "expired") {
        return Response.json({ code: "woocommerce_rest_missing_nonce", message: "Missing the Nonce header." }, { status: 401 });
      }
      return Response.json(empty, { headers: { "Cart-Token": "fresh" } });
    };
    const client = createCartClient({ session: "token", fetch: transport });
    await client.add(42);
    assert.deepEqual(calls.map(c => [c.method, c.url.replace("/wp-json/wc/store/v1", ""), c.token]), [
      ["POST", "/cart/add-item", "expired"], ["GET", "/cart", null], ["POST", "/cart/add-item", "fresh"],
    ]);
    stored.set("typedpress:woo-cart:/wp-json/wc/store/v1", "expired");
    const seen: number[] = [];
    client.subscribe(cart => seen.push(cart.itemsCount));
    await assert.rejects(client.checkout(checkoutInput), { code: "cart_token_expired", status: 401 });
    // The expired cart is gone from Woo: the client starts the new one and tells its subscribers.
    assert.deepEqual(calls.slice(3).map(c => [c.method, c.token]), [["POST", "expired"], ["GET", null]]);
    assert.deepEqual([...stored.values()], ["fresh"]);
    assert.deepEqual(seen, [0]);
  });
});

test("the session defaults to the cookie on the page's origin and to a token on another one", async () => {
  await browser({ location: "https://astro.example.test/products/mug", localStorage: memoryStorage() }, async () => {
    const calls: Call[] = [];
    const transport: typeof fetch = async (url, init) => {
      calls.push(call(url, init));
      return Response.json(empty, { headers: { "Cart-Token": "token", Nonce: "nonce" } });
    };
    await createCartClient({ fetch: transport }).add(1);
    await createCartClient({ base: "https://astro.example.test/wp-json/wc/store/v1", fetch: transport }).add(1);
    assert.deepEqual(calls.map(c => [c.method, c.credentials, c.token, c.nonce]), [
      ["GET", "same-origin", null, null], ["POST", "same-origin", null, "nonce"],
      ["GET", "same-origin", null, null], ["POST", "same-origin", null, "nonce"],
    ]);
    calls.length = 0;
    const store = "https://shop.example.test/wp-json/wc/store/v1";
    await createCartClient({ base: store, fetch: transport }).add(1);
    assert.deepEqual(calls.map(c => [c.method, c.credentials, c.token, c.nonce]), [
      ["GET", "omit", null, null], ["POST", "omit", "token", null],
    ]);
    // A relative checkout address is the store's, not the page's. Woo's cookie session copies the
    // token's cart when that page is opened with it.
    const tokenCart = createCartClient({ base: store, session: "token", fetch: transport });
    assert.equal(tokenCart.checkoutLink("/checkout/"), "https://shop.example.test/checkout/?session=token");
    assert.equal(tokenCart.checkoutLink("https://shop.example.test/checkout/?lang=pl"), "https://shop.example.test/checkout/?lang=pl&session=token");
    // The token is a credential for this cart: it goes to the Store API's origin only.
    for (const elsewhere of ["https://astro.example.test/checkout/", "https://shop.example.test.evil.test/checkout/", "//evil.test/checkout/", "http://shop.example.test/checkout/"]) {
      assert.throws(() => tokenCart.checkoutLink(elsewhere), /Store API's origin/);
    }
    // No browser sends that origin Woo's cookie: every write would begin an empty cart.
    assert.throws(() => createCartClient({ base: store, session: "cookie", fetch: transport }), /session: "token"/);
    assert.equal(calls.length, 2);
  });
});

test("error codes pass through unchanged, and only a 409's data.cart is read as the cart", async () => {
  const answers = [
    // A code from an extension, or from the Cart-Token session another client adds.
    Response.json({code: "cart_token_expired", message: "Sign in again &#8211; &#x2014; &bogus; &#0;", data: {status: 401}}, {status: 401}),
    Response.json({code: "rate_limit_exceeded", message: "Too many requests. Please wait 7 seconds before trying again.", data: {status: 429}}, {status: 429}),
    Response.json({code: "woocommerce_rest_cart_invalid_key", message: "Cart item does not exist.", data: {status: 409, cart: {...empty, items_count: 4}}}, {status: 409}),
    // Woo never puts a cart anywhere else, so nothing else is read as one.
    Response.json({code: "woocommerce_rest_unknown_server_error", message: "Internal server error", data: {status: 500}, cart: empty, ...empty}, {status: 500}),
  ];
  const client = createCartClient({ fetch: (async () => answers.shift()!) as typeof fetch });
  const failure = (run: Promise<unknown>) => run.then(() => assert.fail("expected a refusal"), (error: WooStoreError) => error);
  let error = await failure(client.get());
  assert.ok(error instanceof WooStoreError);
  assert.deepEqual([error.code, error.status, error.message], ["cart_token_expired", 401, "Sign in again \u2013 \u2014 &bogus; &#0;"]);
  assert.equal("rawCode" in error, false);
  error = await failure(client.get());
  assert.deepEqual([error.code, error.status, error.cart], ["rate_limit_exceeded", 429, undefined]);
  error = await failure(client.get());
  assert.deepEqual([error.code, error.status, error.cart?.itemsCount], ["woocommerce_rest_cart_invalid_key", 409, 4]);
  error = await failure(client.get());
  assert.deepEqual([error.code, error.status, error.cart], ["woocommerce_rest_unknown_server_error", 500, undefined]);
});
test("an answer that is not JSON reports its status, not a parse failure", async () => {
  const page = () => new Response("<!doctype html><title>Briefly unavailable for scheduled maintenance.</title>", {status: 503, headers: {"Content-Type": "text/html"}});
  const client = createCartClient({ fetch: (async () => page()) as typeof fetch });
  for (const run of [client.get(), client.add(1)]) {
    await assert.rejects(run, (error: WooStoreError) => {
      assert.ok(error instanceof WooStoreError);
      assert.deepEqual([error.code, error.status, error.message, error.cart], ["http_error", 503, "The cart request failed", undefined]);
      return true;
    });
  }
});
