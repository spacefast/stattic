/// <reference lib="dom" />

import "./sell-checkout-element.js";
import {
  createSellPayment,
  type SellAttemptStore,
} from "../../packages/common/src/utils/sell-payment.js";
import { formatSellPrice } from "../../packages/common/src/utils/sell-price.js";

/** Explicit script entry for static sites; Zero consumes the same controller. */
export function registerSellPaymentElement() {
  if (customElements.get("sf-payment")) return;
  class SellPaymentElement extends HTMLElement {
    static observedAttributes = ["product"];
    private payment: ReturnType<typeof createSellPayment> | null = null;
    connectedCallback() {
      this.payment?.dispose();
      const root = this.shadowRoot ?? this.attachShadow({ mode: "open" });
      const sheet = new CSSStyleSheet();
      sheet.replaceSync(
        `:host{display:inline-block;color:inherit;font:inherit}button{font:inherit;min-height:48px;padding:.75rem 1rem;border:0;border-radius:999px;background:#0c0c0c;color:#fcfcfa;cursor:pointer}button:hover{filter:brightness(1.5)}button:focus-visible{outline:3px solid #6e6a5e;outline-offset:3px}button:disabled{opacity:.6;cursor:wait}p{font-size:1rem;line-height:1.5;margin:.75rem 0;max-width:56ch;overflow-wrap:anywhere}img{display:block;max-width:100%;max-height:16rem;object-fit:contain;border-radius:.5rem}.policy,.description{white-space:pre-wrap}.mode{font-weight:500}.test{background:#d8f24b;color:#0c0c0c;border-radius:999px;padding:.25rem .75rem;width:fit-content}[hidden]{display:none}`,
      );
      root.adoptedStyleSheets = [sheet];
      const cover = document.createElement("img");
      cover.hidden = true;
      cover.loading = "lazy";
      cover.referrerPolicy = "no-referrer";
      const details = document.createElement("p");
      const description = document.createElement("p");
      description.className = "description";
      const shipping = document.createElement("p");
      const policy = document.createElement("p");
      policy.className = "policy";
      const mode = document.createElement("p");
      mode.className = "mode";
      const button = document.createElement("button");
      button.type = "button";
      button.disabled = true;
      button.textContent = "Loading product…";
      const status = document.createElement("p");
      status.setAttribute("role", "status");
      status.setAttribute("aria-live", "polite");
      root.replaceChildren(cover, details, description, shipping, policy, mode, button, status);
      const label = this.textContent?.trim() || "Buy";
      let store: SellAttemptStore | null = null;
      try {
        store = sessionStorage;
      } catch {
        /* Current-page retries still retain their attempt in memory. */
      }
      try {
        this.payment = createSellPayment({
          productKey: this.getAttribute("product") ?? "",
          pagePath: location.pathname,
          origin: location.origin,
          store,
          navigate: (url) => location.assign(url),
          onChange: (state) => {
            const busy = state.phase === "loading" || state.phase === "starting";
            button.disabled = busy;
            button.textContent =
              state.phase === "loading"
                ? "Loading product…"
                : state.phase === "starting"
                  ? "Preparing Checkout…"
                  : state.phase === "error" && state.product
                    ? "Try Checkout again"
                    : state.product
                      ? label
                      : "Refresh product";
            cover.hidden = !state.product?.coverImage;
            if (state.product?.coverImage) {
              cover.src = state.product.coverImage;
              cover.alt = state.product.name;
            } else {
              cover.removeAttribute("src");
            }
            description.textContent = state.product?.description ?? "";
            description.hidden = !description.textContent;
            const physical = state.product?.kind === "physical" ? state.product : null;
            shipping.hidden = !physical;
            policy.hidden = !physical;
            shipping.textContent = physical
              ? `Shipping included · Ships to ${physical.shipping.allowedCountries.join(", ")}`
              : "";
            policy.textContent = physical?.shipping.policy ?? "";
            details.hidden = !state.product;
            mode.hidden = !state.product;
            if (state.product) {
              details.textContent = `${state.product.name} · ${formatSellPrice(state.product.price)}`;
              mode.textContent = state.product.testMode
                ? "Test purchase · no real payment"
                : "Live purchase";
              mode.className = state.product.testMode ? "mode test" : "mode";
            }
            status.textContent = state.message ?? "";
          },
        });
        button.addEventListener("click", () => {
          void this.payment?.buy();
        });
        void this.payment.load();
      } catch {
        button.textContent = label;
        status.textContent = "Add a valid product reference to this payment button.";
      }
    }
    disconnectedCallback() {
      this.payment?.dispose();
      this.payment = null;
    }
    attributeChangedCallback() {
      if (this.isConnected) this.connectedCallback();
    }
  }
  customElements.define("sf-payment", SellPaymentElement);
}

registerSellPaymentElement();
