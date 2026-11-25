// SpacePress typed client: React hooks for a React frontend that runs outside SpacePress (Next.js, TanStack
// Start, an Astro island, Vite). `spacepress build` copies this file next to `index.ts` in
// `sp/_generated/client/`. Do not edit the copy.
//
// It imports nothing from "react": you pass your React to `createHooks`, so a project that never
// imports this file needs no React, and the hooks use whichever React the app already bundles.
//
//   import * as React from "react";
//   import { createClient } from "./sp/_generated/client/index.ts";
//   import { createHooks } from "./sp/_generated/client/react.ts";
//
//   export const first = createClient();
//   export const { useQuery, useMutation } = createHooks(React);
//
// SpacePress's own pages (`sp/app/`) never run React; they keep data live with `<Live>` and `useLive`.

import type { SpacePressError, Query } from "./index.ts";

/** The parts of React the hooks use (React 18 and later, Preact through `preact/compat`). */
export interface ReactLike {
  useSyncExternalStore<S>(subscribe: (onChange: () => void) => () => void, getSnapshot: () => S, getServerSnapshot?: () => S): S;
  useRef<V>(initial: V): { current: V };
  useState<S>(initial: S | (() => S)): [S, (next: S | ((previous: S) => S)) => void];
  // `any` here only so that every function type is accepted as F.
  useCallback<F extends (...args: any[]) => any>(fn: F, deps: readonly unknown[]): F;
  useMemo<V>(factory: () => V, deps: readonly unknown[]): V;
  useLayoutEffect(effect: () => void | (() => void), deps?: readonly unknown[]): void;
  useEffect(effect: () => void | (() => void), deps?: readonly unknown[]): void;
}

export interface QueryState<T> {
  /**
   * The latest result; `undefined` until the first answer. Kept while a later refresh fails on the
   * network; cleared when the server refuses the read (401, 403, 404), as after a sign-out.
   */
  data: T | undefined;
  /** The problem of the last refresh, if it failed: a refused read (401, 403, 404) or the network. */
  error: SpacePressError | undefined;
  /** True until the first answer or error. */
  loading: boolean;
}

export interface UseQueryOptions<T> {
  /** `false` subscribes to nothing and returns `{ data: undefined, loading: false }`. */
  enabled?: boolean;
  /** An answer rendered on the server (`SpacePress\Live\Module::evaluate`): shown at once, and during server rendering. */
  seed?: { token: string; data: T };
}

/** Changes what subscribers of `query` are shown until the write is answered. */
export type Apply = <T>(query: Query<T>, update: (current: T) => T) => void;

export interface UseMutationOptions<A extends unknown[]> {
  /**
   * Runs before the write is sent: call `apply(query, update)` for each read the write will change.
   * The updates are kept until the server's next answer when the write succeeds, and taken back
   * when it fails.
   */
  optimistic?: (apply: Apply, ...args: A) => void;
}

export interface Mutation<A extends unknown[], R> {
  /** Runs the write. Resolves with its result; rejects with its error after rolling back. */
  mutate(...args: A): Promise<R>;
  /** True while at least one call is waiting for its answer. */
  pending: boolean;
  /** The error of the last call that failed; cleared by the next call or `reset()`. */
  error: Error | undefined;
  reset(): void;
}

export interface Hooks {
  /**
   * Keeps a read current: `useQuery(first.todos.list({ where: { done: false } }))`. Pass the read
   * inline; it is re-subscribed only when its `key` (the call and its arguments) changes.
   * `null`, `undefined` or `false` in place of the read subscribes to nothing.
   */
  useQuery<T>(query: Query<T> | null | undefined | false, options?: UseQueryOptions<T>): QueryState<T>;
  /** Wraps a write: `useMutation((title: string) => first.todos.create({ title }), { optimistic })`. */
  useMutation<A extends unknown[], R>(run: (...args: A) => Promise<R>, options?: UseMutationOptions<A>): Mutation<A, R>;
}

export function createHooks(React: ReactLike): Hooks {
  // No hook reads or writes a ref while rendering (the React Compiler's rule): the store is made
  // from the read of the render that starts it, and a mutation reads its latest callbacks only
  // when it runs. Effects that copy them run before the browser paints; on a server, where layout
  // effects warn and never run, a plain effect stands in.
  const useLatestEffect = typeof document === "undefined" ? React.useEffect : React.useLayoutEffect;

  function useQuery<T>(query: Query<T> | null | undefined | false, options: UseQueryOptions<T> = {}): QueryState<T> {
    const active = query && options.enabled !== false ? query : null;
    const key = active?.key ?? null;
    const seed = options.seed;
    // The read is usually built inline, so a new object arrives with every render. Reads with the
    // same key are the same read, so the store starts one subscription per key, from the read of
    // the render that made it.
    const store = React.useMemo(() => {
      const initial: QueryState<T> = seed && active !== null ? { data: seed.data, error: undefined, loading: false } : { data: undefined, error: undefined, loading: active !== null };
      // The snapshot useSyncExternalStore reads: a new object whenever an answer arrives.
      const held = { state: initial };
      return {
        subscribe(changed: () => void): () => void {
          if (active === null) return () => {};
          return active.subscribe(
            (data) => {
              held.state = { data, error: undefined, loading: false };
              changed();
            },
            (error) => {
              // A network failure keeps what was shown. A refusal (401, 403, a 404 on `get`) means
              // this visitor may not read it, for example after signing out: the data goes.
              const refused = error.status === 401 || error.status === 403 || error.status === 404;
              held.state = { data: refused ? undefined : held.state.data, error, loading: false };
              changed();
            },
            seed,
          );
        },
        snapshot: () => held.state,
        server: () => initial,
      };
      // Keyed on the read's key on purpose: a new object for the same read is the same subscription.
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);
    return React.useSyncExternalStore(store.subscribe, store.snapshot, store.server);
  }

  function useMutation<A extends unknown[], R>(run: (...args: A) => Promise<R>, options: UseMutationOptions<A> = {}): Mutation<A, R> {
    const [state, setState] = React.useState<{ pending: number; error: Error | undefined }>({ pending: 0, error: undefined });
    const latest = React.useRef({ run, options });
    useLatestEffect(() => {
      latest.current = { run, options };
    });
    const mutate = React.useCallback(async (...args: A): Promise<R> => {
      const updates: { settle(): void; rollback(): void }[] = [];
      setState((s) => ({ pending: s.pending + 1, error: undefined }));
      try {
        latest.current.options.optimistic?.((query, update) => void updates.push(query.optimistic(update)), ...args);
        const result = await latest.current.run(...args);
        for (const update of updates) update.settle();
        setState((s) => ({ ...s, pending: s.pending - 1 }));
        return result;
      } catch (e) {
        for (const update of updates) update.rollback();
        const error = e instanceof Error ? e : new Error(String(e));
        setState((s) => ({ pending: s.pending - 1, error }));
        throw e;
      }
    }, []);
    const reset = React.useCallback(() => setState((s) => ({ ...s, error: undefined })), []);
    return { mutate, pending: state.pending > 0, error: state.error, reset };
  }

  return { useQuery, useMutation };
}
