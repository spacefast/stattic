import assert from "node:assert/strict";
import { test } from "node:test";
import { createCartHooks, type CartHooks } from "./cart-react.ts";
import { createCartClient, WooStoreError, type WooCartClient } from "./commerce.ts";

type Variation = { attribute: string; value: string }[];
function wireCart(quantity: number, country = "PL", selected = "courier", variation: Variation = []) {
  const totals = {
    total_items: "2400", total_price: "3900", total_shipping: "1500", total_discount: "0", total_tax: "0",
    currency_code: "EUR", currency_minor_unit: 2,
  };
  return {
    items: quantity ? [{
      key: "mug", id: 42, name: "Ceramic mug", quantity, images: [], variation,
      prices: { price: "2400", currency_code: "EUR", currency_minor_unit: 2 },
      totals: { line_total: "2400", line_subtotal: "2400" },
    }] : [],
    items_count: quantity, needs_shipping: true, coupons: [], totals, shipping_address: { country },
    shipping_rates: [{ package_id: 0, name: "Shipping", shipping_rates: ["courier", "pickup"].map((id) => ({
      rate_id: id, name: id, price: "1500", selected: id === selected, currency_code: "EUR", currency_minor_unit: 2,
    })) }],
  };
}

const answer = (cart: ReturnType<typeof wireCart>) => Response.json(cart, { headers: { Nonce: "test-nonce" } });

// Exercise the hooks through React's external store interface without a DOM.
function mount<T>(client: WooCartClient, use: (hooks: CartHooks) => T) {
  let stop: (() => void) | undefined;
  const updates: T[] = [];
  const hooks = createCartHooks({
    useSyncExternalStore(subscribe, snapshot) {
      stop ??= subscribe(() => updates.push(read()));
      return snapshot();
    },
  }, client);
  const read = () => use(hooks);
  const initial = read();
  return { initial, read, updates, unmount: () => stop?.() };
}

test("cart hooks share a single read and roll back a refused new item", async (t) => {
  const write = Promise.withResolvers<Response>();
  let reads = 0;
  const client = createCartClient({ fetch: async (url) => {
    if (String(url).endsWith("/cart")) {
      reads++;
      return answer(wireCart(1));
    }
    return write.promise;
  } });
  const cart = mount(client, (hooks) => hooks.useCart());
  const count = mount(client, (hooks) => hooks.useCartCount());
  t.after(() => { cart.unmount(); count.unmount(); });
  assert.equal(cart.initial.loading, true);
  assert.equal(count.initial, undefined);
  await cart.read().refresh();
  assert.equal(reads, 1);

  const added = cart.read().add(123, 2);
  assert.equal(cart.read().pending, true);
  assert.equal(cart.read().cart?.items.length, 1);
  assert.equal(count.read(), 3);
  // Woo refuses stock with a 400 and no cart (CartController::validate_add_to_cart).
  write.resolve(Response.json({
    code: "woocommerce_rest_product_out_of_stock",
    message: "You cannot add &quot;Field guide&quot; to the cart because the product is out of stock.",
    data: { status: 400 },
  }, { status: 400 }));
  await assert.rejects(added, WooStoreError);
  assert.equal(count.read(), 1);
  assert.equal(cart.read().cart?.items[0]?.quantity, 1);
  assert.equal(cart.read().pending, false);
  const error = cart.read().error;
  assert.ok(error instanceof WooStoreError);
  assert.equal(error.message, 'You cannot add "Field guide" to the cart because the product is out of stock.');
  assert.equal(error.code, "woocommerce_rest_product_out_of_stock");
  assert.equal(error.status, 400);
  assert.equal(error.cart, undefined);
});

test("a 409 replaces the cart, keeps later optimistic writes in order, and stays until the next write starts", async (t) => {
  const first = Promise.withResolvers<Response>();
  const second = Promise.withResolvers<Response>();
  const firstSent = Promise.withResolvers<void>();
  const secondSent = Promise.withResolvers<void>();
  const writes: string[] = [];
  const client = createCartClient({ fetch: async (url) => {
    const path = String(url).split("/").at(-1) ?? "";
    if (path === "cart") return answer(wireCart(1));
    writes.push(path);
    if (path === "update-item") {
      firstSent.resolve();
      return first.promise;
    }
    // One Response can be read once: the last add gets its own.
    if (writes.length > 2) return answer(wireCart(3));
    secondSent.resolve();
    return second.promise;
  } });
  const cart = mount(client, (hooks) => hooks.useCart());
  const count = mount(client, (hooks) => hooks.useCartCount());
  t.after(() => { cart.unmount(); count.unmount(); });
  await cart.read().refresh();

  const updated = cart.read().update("mug", 2);
  assert.equal(cart.read().cart?.items[0]?.quantity, 2);
  const added = cart.read().add(42, 2);
  assert.equal(count.read(), 4);
  await firstSent.promise;
  assert.deepEqual(writes, ["update-item"]);
  // The line went away in another tab. Woo answers 409 with the current cart
  // (CartController::set_cart_item_quantity, AbstractCartRoute::get_route_error_response).
  first.resolve(Response.json({
    code: "woocommerce_rest_cart_invalid_key", message: "Cart item does not exist.", data: { status: 409, cart: wireCart(0) },
  }, { status: 409 }));
  let refusal: unknown;
  await assert.rejects(updated, (error) => {
    assert.ok(error instanceof WooStoreError);
    assert.equal(error.status, 409);
    assert.equal(error.code, "woocommerce_rest_cart_invalid_key");
    assert.deepEqual(error.cart?.items, []);
    assert.equal(error.cart?.totals.totalPrice, "39.00");
    assert.equal(cart.read().error, error);
    refusal = error;
    return true;
  });
  // The pending add is previewed on Woo's cart: the line is gone, so only the count moves.
  assert.deepEqual(cart.read().cart?.items, []);
  assert.equal(count.read(), 2);
  assert.equal(cart.read().pending, true);
  await secondSent.promise;
  assert.deepEqual(writes, ["update-item", "add-item"]);
  second.resolve(answer(wireCart(2)));
  await added;
  // The add was queued before the refusal, so its success does not hide it.
  assert.equal(cart.read().error, refusal);
  assert.equal(cart.read().pending, false);
  assert.equal(count.read(), 2);
  assert.ok(count.updates.every((quantity) => quantity === undefined || quantity <= 4));
  await cart.read().refresh();
  assert.equal(cart.read().error, refusal);
  // The shopper acts again: the refusal goes away as that write starts.
  const again = cart.read().add(42);
  assert.equal(cart.read().error, undefined);
  await again;
  assert.equal(cart.read().error, undefined);
});

test("shipping and removal previews keep confirmed prices and roll back independently", async (t) => {
  const first = Promise.withResolvers<Response>();
  const second = Promise.withResolvers<Response>();
  const third = Promise.withResolvers<Response>();
  const client = createCartClient({ fetch: async (url) => {
    if (String(url).endsWith("/cart")) return answer(wireCart(1));
    if (String(url).endsWith("/update-customer")) return first.promise;
    if (String(url).endsWith("/select-shipping-rate")) return second.promise;
    return third.promise;
  } });
  const cart = mount(client, (hooks) => hooks.useCart());
  t.after(cart.unmount);
  await cart.read().refresh();
  const address = cart.read().updateCustomer({ shipping: { country: "DE", postcode: "10115" } });
  const shipping = cart.read().selectShipping(0, "pickup");
  const removal = cart.read().remove("mug");
  const chosen = () => cart.read().cart?.shippingRates[0]?.rates.find((rate) => rate.selected)?.id;
  assert.equal(cart.read().cart?.shippingAddress?.postcode, "10115");
  assert.equal(chosen(), "pickup");
  assert.deepEqual(cart.read().cart?.items, []);
  assert.equal(cart.read().cart?.totals.totalPrice, "39.00");

  first.resolve(answer(wireCart(1, "DE")));
  await address;
  second.resolve(Response.json({ code: "shipping_changed", data: { cart: { items: [] } } }, { status: 409 }));
  await assert.rejects(shipping, (error) => error instanceof WooStoreError && error.cart === undefined);
  assert.equal(chosen(), "courier");
  assert.equal(cart.read().cart?.shippingAddress?.country, "DE");
  assert.equal(cart.read().cart?.itemsCount, 0);

  third.resolve(answer(wireCart(0, "DE")));
  await removal;
  assert.equal(cart.read().pending, false);
  assert.deepEqual(cart.read().cart?.items, []);
});

test("adding a variation that has one line previews that line's quantity", async (t) => {
  const size: Variation = [{ attribute: "Size", value: "Large" }];
  const write = Promise.withResolvers<Response>();
  const client = createCartClient({ fetch: async (url) => String(url).endsWith("/cart") ? answer(wireCart(1, "PL", "courier", size)) : write.promise });
  const cart = mount(client, (hooks) => hooks.useCart());
  t.after(cart.unmount);
  await cart.read().refresh();
  const added = cart.read().add(42, 2);
  assert.equal(cart.read().cart?.items[0]?.quantity, 3);
  assert.equal(cart.read().cart?.itemsCount, 3);
  write.resolve(answer(wireCart(3, "PL", "courier", size)));
  await added;
  assert.equal(cart.read().cart?.items[0]?.quantity, 3);
});

test("refresh() after a write answers with a cart read after that write", async (t) => {
  const firstRead = Promise.withResolvers<Response>();
  const write = Promise.withResolvers<Response>();
  let reads = 0;
  let quantity = 1;
  const client = createCartClient({ fetch: async (url) => {
    if (!String(url).endsWith("/cart")) return write.promise.then((response) => { quantity = 2; return response; });
    return ++reads === 1 ? firstRead.promise : answer(wireCart(quantity));
  } });
  const cart = mount(client, (hooks) => hooks.useCart());
  t.after(cart.unmount);
  // Mounting queued the first read. It is still waiting when the write and the second refresh are queued.
  const before = cart.read().refresh();
  const added = cart.read().add(42);
  const after = cart.read().refresh();
  assert.notEqual(before, after);
  firstRead.resolve(answer(wireCart(1)));
  assert.equal((await before).itemsCount, 1);
  write.resolve(answer(wireCart(2)));
  await added;
  assert.equal((await after).itemsCount, 2);
  // With no write in between, two callers share one read.
  const reading = reads;
  await Promise.all([cart.read().refresh(), cart.read().refresh()]);
  assert.equal(reads, reading + 1);
});

test("update with quantity zero removes the line, as Woo refuses a quantity of zero", async (t) => {
  const writes: string[] = [];
  const client = createCartClient({ fetch: async (url, init) => {
    const path = String(url).split("/").at(-1) ?? "";
    if (path === "cart") return answer(wireCart(1));
    writes.push(`${path} ${init?.body}`);
    return answer(wireCart(0));
  } });
  const cart = mount(client, (hooks) => hooks.useCart());
  t.after(cart.unmount);
  await cart.read().refresh();
  const removed = cart.read().update("mug", 0);
  assert.deepEqual(cart.read().cart?.items, []);
  await removed;
  assert.deepEqual(writes, ['remove-item {"key":"mug"}']);
});

test("a page shown again or restored from the back/forward cache reads the cart, at most once per interval", async (t) => {
  const listeners = new Map<string, (event: unknown) => void>();
  const target = (prefix: string) => ({
    addEventListener: (type: string, run: (event: unknown) => void) => { listeners.set(prefix + type, run); },
    removeEventListener: (type: string) => { listeners.delete(prefix + type); },
  });
  const page = { visibilityState: "visible", ...target("document:") };
  const scope = globalThis as Record<string, unknown>;
  const saved = { document: scope.document, addEventListener: scope.addEventListener, removeEventListener: scope.removeEventListener };
  Object.assign(scope, { document: page, ...target("window:") });
  t.after(() => Object.assign(scope, saved));
  t.mock.timers.enable({ apis: ["Date"], now: 1_000_000 });

  let reads = 0;
  let quantity = 1;
  const client = createCartClient({ fetch: async () => { reads++; return answer(wireCart(quantity)); } });
  const cart = mount(client, (hooks) => hooks.useCart());
  await cart.read().refresh();
  assert.equal(reads, 1);
  assert.deepEqual([...listeners.keys()].sort(), ["document:visibilitychange", "window:pageshow"]);

  // Shown again straight after Woo confirmed the cart: nothing to ask.
  listeners.get("document:visibilitychange")!({});
  await cart.read().refresh();
  assert.equal(reads, 2, "only the explicit refresh read");
  t.mock.timers.tick(5000);
  page.visibilityState = "hidden";
  listeners.get("document:visibilitychange")!({});
  page.visibilityState = "visible";
  quantity = 2;
  listeners.get("document:visibilitychange")!({});
  listeners.get("document:visibilitychange")!({});
  await cart.read().refresh();
  assert.equal(reads, 3, "one read, shared with the explicit refresh");
  assert.equal(cart.read().cart?.itemsCount, 2);

  // An ordinary page load fires pageshow too. Only a restored page (persisted) is stale.
  listeners.get("window:pageshow")!({ persisted: false });
  await Promise.resolve();
  assert.equal(reads, 3);
  quantity = 0;
  listeners.get("window:pageshow")!({ persisted: true });
  await cart.read().refresh();
  assert.equal(reads, 4);
  assert.equal(cart.read().cart?.itemsCount, 0);

  cart.unmount();
  assert.equal(listeners.size, 0);
});

test("a failed read stays in error until Woo answers with a cart, from a read or a write", async (t) => {
  const answers: (() => Response)[] = [
    () => new Response("<h1>Briefly unavailable for scheduled maintenance.</h1>", { status: 503 }),
    () => answer(wireCart(1)),
    () => new Response("", { status: 502 }),
  ];
  const client = createCartClient({ fetch: async () => (answers.shift() ?? (() => answer(wireCart(2))))() });
  const cart = mount(client, (hooks) => hooks.useCart());
  t.after(cart.unmount);
  await assert.rejects(cart.read().refresh(), WooStoreError);
  const failed = cart.read().error;
  assert.ok(failed instanceof WooStoreError);
  assert.deepEqual([failed.status, failed.message, cart.read().loading, cart.read().cart], [503, "The cart request failed", false, undefined]);
  await cart.read().refresh();
  assert.deepEqual([cart.read().error, cart.read().cart?.itemsCount], [undefined, 1]);

  // A read that fails while a write waits is answered by that write's cart.
  const reading = cart.read().refresh();
  const added = cart.read().add(42);
  await assert.rejects(reading, WooStoreError);
  assert.equal((cart.read().error as WooStoreError).status, 502);
  assert.equal(cart.read().cart?.itemsCount, 2, "the pending add is still previewed");
  await added;
  assert.deepEqual([cart.read().error, cart.read().cart?.itemsCount, cart.read().pending], [undefined, 2, false]);
});

test("a token cart's order goes through the store: pending while it is placed, Woo's cart afterwards", async (t) => {
  const order = Promise.withResolvers<Response>();
  const tokens = { headers: { "Cart-Token": "token" } };
  let ordered = false;
  const calls: string[] = [];
  const client = createCartClient({ session: "token", fetch: async (url, init) => {
    calls.push(`${init?.method} ${String(url).replace("/wp-json/wc/store/v1", "")}`);
    if (String(url).endsWith("/checkout")) {
      const response = await order.promise;
      ordered = response.ok;
      return response;
    }
    return Response.json(wireCart(ordered ? 0 : 1), tokens);
  } });
  const cart = mount(client, (hooks) => hooks.useCart());
  t.after(() => cart.unmount());
  await cart.read().refresh();
  assert.equal(cart.read().cart?.itemsCount, 1);

  const input = { billing_address: { first_name: "Test", email: "buyer@example.test" }, payment_method: "cod" };
  const placed = cart.read().checkout(input);
  assert.deepEqual([cart.read().pending, cart.read().cart?.itemsCount], [true, 1]);
  const result = { order_id: 7, status: "processing", payment_result: { payment_status: "success", redirect_url: "/checkout/order-received/7/", payment_details: [] } };
  order.resolve(Response.json(result, tokens));
  assert.deepEqual(await placed, result);
  // The client read the cart Woo emptied; the store shows it without a refresh of its own.
  assert.deepEqual(calls, ["GET /cart", "POST /checkout", "GET /cart"]);
  assert.deepEqual([cart.read().pending, cart.read().cart?.itemsCount, cart.read().error], [false, 0, undefined]);
});

test("a refused token order keeps the cart and reports the refusal", async (t) => {
  const tokens = { headers: { "Cart-Token": "token" } };
  const client = createCartClient({ session: "token", fetch: async (url) => String(url).endsWith("/checkout")
    ? Response.json({ code: "woocommerce_rest_invalid_email_address", message: "The provided email address is not valid", data: { status: 400 } }, { status: 400, ...tokens })
    : Response.json(wireCart(1), tokens) });
  const cart = mount(client, (hooks) => hooks.useCart());
  t.after(() => cart.unmount());
  await cart.read().refresh();
  await assert.rejects(cart.read().checkout({ billing_address: { email: "nope" }, payment_method: "cod" }), { code: "woocommerce_rest_invalid_email_address" });
  const state = cart.read();
  assert.deepEqual([state.pending, state.cart?.itemsCount, (state.error as WooStoreError).status], [false, 1, 400]);
});

test("a successful token order leaves the cart stale until a read succeeds", async (t) => {
  const tokens = { headers: { "Cart-Token": "token" } };
  const result = { order_id: 7, status: "processing", payment_result: { payment_status: "success", redirect_url: "", payment_details: [] } };
  const retry = Promise.withResolvers<Response>();
  const calls: string[] = [];
  const client = createCartClient({ session: "token", fetch: async (url, init) => {
    calls.push(`${init?.method} ${String(url).replace("/wp-json/wc/store/v1", "")}`);
    if (calls.length === 1) return Response.json(wireCart(1), tokens);
    if (String(url).endsWith("/checkout")) return Response.json(result, tokens);
    if (calls.length === 3) return Response.json({}, { status: 503 });
    return retry.promise;
  } });
  const cart = mount(client, (hooks) => hooks.useCart());
  t.after(cart.unmount);
  await cart.read().refresh();

  const placed = await cart.read().checkout({ billing_address: { email: "buyer@example.test" }, payment_method: "cod" });
  assert.deepEqual(placed, result);
  assert.deepEqual([cart.read().pending, cart.read().stale, cart.read().cart?.itemsCount], [false, true, 1]);
  retry.resolve(Response.json(wireCart(0), tokens));
  await cart.read().refresh();
  assert.deepEqual(calls, ["GET /cart", "POST /checkout", "GET /cart", "GET /cart"]);
  assert.deepEqual([cart.read().stale, cart.read().cart?.itemsCount, cart.read().error], [false, 0, undefined]);
});
