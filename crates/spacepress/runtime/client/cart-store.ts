import { cart, WooStoreError, type WooCart, type WooCartClient, type WooTokenCartClient } from "./commerce.ts";

export interface WooCartState {
  /** Woo's last confirmed cart with the pending writes' previews applied. Undefined until the first read answers. */
  cart: WooCart | undefined;
  /** True until the first read answers or fails. */
  loading: boolean;
  /** True while a write waits for Woo. */
  pending: boolean;
  /** True after an order succeeds until Woo confirms the cart again. */
  stale: boolean;
  /** The last failure. A refused write stays until the next write starts, so a later write that was
   * already queued and succeeds does not hide it. A failed read stays until Woo next answers with a cart. */
  error: Error | undefined;
}

export interface WooCartActions extends Pick<WooCartClient, "add" | "update" | "remove" | "selectShipping" | "updateCustomer"> {
  /** Reads the cart from Woo, after every write already queued. */
  refresh(): Promise<WooCart>;
}

/** A token cart's actions. `pending` is true while the order is placed, and the cart is Woo's afterwards. */
export interface WooTokenCartActions extends WooCartActions, Pick<WooTokenCartClient, "checkout"> {}

export interface WooCartStore<Actions extends WooCartActions = WooCartActions> {
  subscribe(changed: () => void): () => void;
  snapshot(): WooCartState;
  serverSnapshot(): WooCartState;
  actions: Actions;
}

const wooCartStores = new WeakMap<WooCartClient, WooCartStore>();
/** A tab shown again reads the cart only when Woo last answered at least this long ago. */
const SHOWN_AGAIN_MS = 5000;

/** One cart store per client, shared by React hooks and other browser subscribers. */
export function createCartStore(client: WooTokenCartClient): WooCartStore<WooTokenCartActions>;
export function createCartStore(client?: WooCartClient): WooCartStore;
export function createCartStore(client: WooCartClient | WooTokenCartClient = cart): WooCartStore {
  const shared = wooCartStores.get(client);
  if (shared) return shared;

  const initial: WooCartState = { cart: undefined, loading: true, pending: false, stale: false, error: undefined };
  let state = initial;
  let confirmed: WooCart | undefined;
  const optimistic = new Set<(current: WooCart) => WooCart>();
  const listeners = new Set<() => void>();
  let unsubscribe: (() => void) | undefined;
  let tail: Promise<unknown> = Promise.resolve();
  let reading: Promise<WooCart> | undefined;
  let writing = false;
  let receivedDuringWrite: WooCart | undefined;
  let failedRead: Error | undefined;
  // When Woo last gave a cart or failed a read.
  let answeredAt = 0;

  function publish(changes: Partial<WooCartState> = {}) {
    let cart = confirmed;
    if (cart) for (const update of optimistic) cart = update(cart);
    state = { ...state, ...changes, cart, pending: optimistic.size > 0 };
    for (const listener of listeners) listener();
  }

  function receive(cart: WooCart) {
    // The client's subscription can emit the write's own answer before its promise resolves.
    // Wait for that promise so we can drop the corresponding optimistic update at the same time.
    if (writing) {
      receivedDuringWrite = cart;
      return;
    }
    confirmed = cart;
    publish(answered());
  }

  /** Woo gave a cart. That answers a failed read; a refused write stays until the next write starts. */
  function answered(): Partial<WooCartState> {
    answeredAt = Date.now();
    return { loading: false, stale: false, ...(state.error === failedRead ? { error: undefined } : {}) };
  }

  function describe(error: unknown): Error {
    return error instanceof Error ? error : new Error(String(error));
  }

  function enqueue<T>(run: () => Promise<T>): Promise<T> {
    const next = tail.then(run);
    tail = next.catch(() => undefined);
    return next;
  }

  function refresh(): Promise<WooCart> {
    if (reading) return reading;
    const next = enqueue(async () => {
      try {
        const cart = await client.get();
        if (cart !== confirmed || state.stale) receive(cart);
        return cart;
      } catch (error) {
        failedRead = describe(error);
        answeredAt = Date.now();
        publish({ loading: false, error: failedRead });
        throw error;
      } finally {
        if (reading === next) reading = undefined;
      }
    });
    reading = next;
    return next;
  }

  /** `cartOf` gives the cart in Woo's answer. An answer with none (an order) uses the cart the client read after it. */
  function write<T>(run: () => Promise<T>, update: (current: WooCart) => WooCart, cartOf: (answer: T) => WooCart | undefined): Promise<T> {
    optimistic.add(update);
    // A read queued or sent before this write answers with the cart from before it, so a later refresh() asks again.
    reading = undefined;
    const result = enqueue(async () => {
      writing = true;
      let changes: Partial<WooCartState> = { loading: false };
      try {
        const answer = await run();
        const current = cartOf(answer) ?? receivedDuringWrite;
        if (current) {
          confirmed = current;
          changes = answered();
        } else {
          changes.stale = true;
        }
        return answer;
      } catch (error) {
        // Woo sends the current cart with a 409. Any other refusal rolls back to the confirmed cart.
        const current = (error instanceof WooStoreError ? error.cart : undefined) ?? receivedDuringWrite;
        if (current) {
          confirmed = current;
          changes = answered();
        }
        changes.error = describe(error);
        throw error;
      } finally {
        writing = false;
        receivedDuringWrite = undefined;
        optimistic.delete(update);
        publish(changes);
      }
    });
    // The shopper acted again: only now does the previous failure go away.
    publish({ error: undefined });
    return result;
  }

  // Quantity and removal predictions follow @woo/storefront-core (MIT), packages/core/src/resources/cart.ts.
  function quantity(current: WooCart, key: string, next: number): WooCart {
    const item = current.items.find((item) => item.key === key);
    if (!item || !Number.isSafeInteger(next) || next < 0) return current;
    const items = next === 0
      ? current.items.filter((item) => item.key !== key)
      : current.items.map((item) => item.key === key ? { ...item, quantity: next } : item);
    return { ...current, items, itemsCount: current.itemsCount + next - item.quantity };
  }

  const cartWrite = (run: () => Promise<WooCart>, update: (current: WooCart) => WooCart) => write(run, update, (cart) => cart);
  const actions: WooCartActions = {
    refresh,
    add: (id, count = 1) => cartWrite(() => client.add(id, count), (current) => {
      if (!Number.isSafeInteger(count) || count <= 0) return current;
      // A variation's line has the variation's id. Two lines with one id differ in data only Woo can compare.
      const matches = current.items.filter((item) => item.id === id);
      const item = matches.length === 1 ? matches[0] : undefined;
      // Woo supplies the new line's key, product data and prices. Until then only the count changes.
      return item ? quantity(current, item.key, item.quantity + count) : { ...current, itemsCount: current.itemsCount + count };
    }),
    update: (key, count) => cartWrite(() => client.update(key, count), (current) => quantity(current, key, count)),
    remove: (key) => cartWrite(() => client.remove(key), (current) => quantity(current, key, 0)),
    selectShipping: (packageId, rateId) => cartWrite(() => client.selectShipping(packageId, rateId), (current) => ({
      ...current,
      shippingRates: current.shippingRates.map((pack) => pack.packageId === packageId && pack.rates.some((rate) => rate.id === rateId)
        ? { ...pack, rates: pack.rates.map((rate) => ({ ...rate, selected: rate.id === rateId })) }
        : pack),
    })),
    updateCustomer: (input) => cartWrite(() => client.updateCustomer(input), (current) => ({
      ...current,
      ...(input.billing ? { billingAddress: { ...current.billingAddress, ...input.billing } } : {}),
      ...(input.shipping ? { shippingAddress: { ...current.shippingAddress, ...input.shipping } } : {}),
    })),
  };

  const reread = () => { refresh().catch(() => undefined); };
  if ("checkout" in client) {
    (actions as WooTokenCartActions).checkout = async (input) => {
      const order = await write(async () => {
        // Observe the client's post-order read even if the store has no subscribers.
        const stop = client.subscribe((cart) => { receivedDuringWrite = cart; });
        try { return await client.checkout(input); } finally { stop(); }
      }, (current) => current, () => undefined);
      if (state.stale) reread();
      return order;
    };
  }

  // The cart can change while this page is not looked at: another tab, or an order placed before the
  // browser restores this page from its back/forward cache. Ask again when the page is shown.
  type Listens = { addEventListener?(type: string, run: (event: unknown) => void): void; removeEventListener?(type: string, run: (event: unknown) => void): void };
  const page = () => (globalThis as { document?: Listens & { visibilityState?: string } }).document;
  const shown = () => {
    // Switching tabs back and forth asks once per SHOWN_AGAIN_MS at most; a cart Woo just confirmed is current.
    if (page()?.visibilityState === "visible" && (state.stale || Date.now() - answeredAt >= SHOWN_AGAIN_MS)) reread();
  };
  const restored = (event: unknown) => {
    if ((event as { persisted?: boolean } | null)?.persisted === true) reread();
  };

  const store: WooCartStore = {
    actions,
    snapshot: () => state,
    serverSnapshot: () => initial,
    subscribe(changed) {
      listeners.add(changed);
      if (listeners.size === 1) {
        unsubscribe = client.subscribe(receive);
        page()?.addEventListener?.("visibilitychange", shown);
        (globalThis as Listens).addEventListener?.("pageshow", restored);
        reread();
      }
      return () => {
        listeners.delete(changed);
        if (listeners.size === 0) {
          unsubscribe?.();
          unsubscribe = undefined;
          page()?.removeEventListener?.("visibilitychange", shown);
          (globalThis as Listens).removeEventListener?.("pageshow", restored);
        }
      };
    },
  };
  wooCartStores.set(client, store);
  return store;
}
