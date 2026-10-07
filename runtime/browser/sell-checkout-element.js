import { sellCheckoutStatusSchema } from "../../packages/common/src/contracts/sell.js";

function startCheckoutStatus(root) {
  const title = root.getElementById("status-title");
  const copy = root.getElementById("status-copy");
  const product = root.getElementById("product-name");
  const retry = root.getElementById("retry");
  const badge = root.getElementById("test-mode");
  const download = root.getElementById("download");
  const recover = root.getElementById("recover");
  const query = new URLSearchParams(location.search);
  const endpoint =
    "/__spacefast/sell/status?" +
    new URLSearchParams({
      sessionId: query.get("sessionId") || "",
      purchaseToken: query.get("purchaseToken") || "",
    });
  let attempts = 0;
  let timer;
  let running = false;
  let disposed = false;
  function show(heading, message) {
    title.textContent = heading;
    copy.textContent = message;
    document.title = heading;
  }
  async function check() {
    if (running || disposed) return;
    running = true;
    retry.disabled = true;
    clearTimeout(timer);
    download.hidden = true;
    download.removeAttribute("href");
    recover.hidden = true;
    try {
      const response = await fetch(endpoint, {
        cache: "no-store",
        credentials: "omit",
        referrerPolicy: "no-referrer",
        signal: AbortSignal.timeout(10000),
        headers: { accept: "application/json" },
      });
      if (!response.ok) {
        if (response.status === 404 || response.status === 422) {
          show(
            "We couldn’t verify this purchase",
            "Open the original link from Checkout, or contact the seller.",
          );
          return;
        }
        throw new Error("unavailable");
      }
      const { data: rawData } = await response.json();
      if (disposed) return;
      const data = sellCheckoutStatusSchema.parse(rawData);
      product.textContent = data.productName;
      badge.hidden = !data.testMode;
      if (data.state === "paid") {
        if (
          data.kind === "digital" &&
          data.delivery === "ready" &&
          data.downloadUrl !== undefined
        ) {
          const url = new URL(data.downloadUrl, location.origin);
          if (url.protocol !== "https:" || url.pathname !== "/sell/download")
            throw new Error("invalid download");
          download.href = url.href;
          download.hidden = false;
          show(
            "Your download is ready",
            "Your payment is confirmed. Download your file while this seven-day link is valid.",
          );
        } else if (data.kind === "digital" && data.delivery === "expired") {
          if (data.recoveryUrl === undefined) throw new Error("invalid recovery");
          const recovery = new URL(data.recoveryUrl);
          if (recovery.protocol !== "https:" || recovery.pathname !== "/sell/recover")
            throw new Error("invalid recovery");
          recover.href = recovery.href;
          recover.hidden = false;
          show(
            "Your download link has expired",
            "Request a fresh link at your purchase email. Your purchase is still confirmed.",
          );
        } else if (data.kind === "digital" && ["on_hold", "unavailable"].includes(data.delivery)) {
          show(
            "Your download needs attention",
            "Your payment is confirmed. Contact the seller for help with delivery.",
          );
        } else {
          show(
            "Payment confirmed",
            data.kind === "digital"
              ? "Your payment is confirmed. Delivery access is being prepared."
              : "Your payment is confirmed. The seller will arrange shipping.",
          );
          if (data.kind === "digital" && ++attempts < 12) timer = setTimeout(check, 5000);
        }
      } else if (data.state === "refunded") {
        show(
          "Payment refunded",
          "This purchase has been fully refunded. Contact the seller if you need help.",
        );
      } else if (data.state === "disputed") {
        show(
          "Payment under review",
          "This payment is under review. Contact the seller for help with your order.",
        );
      } else if (data.state === "failed") {
        show("Checkout wasn’t completed", "Return to the product page to try again.");
      } else if (data.state === "processing") {
        show(
          "Confirming your payment",
          "We’re checking with Stripe. This page updates automatically.",
        );
        if (++attempts < 12) timer = setTimeout(check, 5000);
        else
          show(
            "Still waiting for confirmation",
            "Payment hasn’t been confirmed yet. Check again shortly before starting another checkout.",
          );
      } else throw new Error("invalid state");
    } catch {
      show(
        "We couldn’t check your payment",
        "Your payment status is unavailable right now. Check again shortly before starting another checkout.",
      );
    } finally {
      running = false;
      retry.disabled = false;
    }
  }
  retry.addEventListener("click", () => {
    attempts = 0;
    void check();
  });
  const pause = () => clearTimeout(timer);
  window.addEventListener("pagehide", pause);
  void check();
  return () => {
    disposed = true;
    clearTimeout(timer);
    window.removeEventListener("pagehide", pause);
  };
}
if (!customElements.get("sf-checkout-status")) {
  customElements.define(
    "sf-checkout-status",
    class extends HTMLElement {
      connectedCallback() {
        this.cleanup?.();
        const root = this.shadowRoot ?? this.attachShadow({ mode: "open" });
        root.innerHTML =
          '<style>:host{display:block;font:inherit;color:inherit}h1{font:400 2.5rem Georgia,serif;margin:0 0 1rem}p{line-height:1.5}button,a{display:inline-block;font:inherit;padding:.75rem 1rem;margin:1rem .5rem 0 0;border:0;border-radius:999px;background:#0c0c0c;color:#fcfcfa;text-decoration:none;cursor:pointer}[hidden]{display:none}</style><h1 id="status-title">Confirming your payment</h1><p id="status-copy">We’re checking with Stripe.</p><p id="product-name"></p><p id="test-mode" hidden>Test purchase · no real payment</p><a id="download" hidden rel="noreferrer">Download file</a><a id="recover" hidden rel="noreferrer">Get a fresh link</a><button id="retry" type="button">Check again</button>';
        this.cleanup = startCheckoutStatus(root);
      }
      disconnectedCallback() {
        this.cleanup?.();
      }
    },
  );
}
