"use client";

import { useState } from "typedpress/client";

// Posts to the native contact form (typedpress.config.ts, `services.forms.contact`): WordPress
// validates the fields, saves the submission and queues the notification mail.
export function ContactForm({ endpoint }: { endpoint: string }) {
  const [email, setEmail] = useState("");
  const [message, setMessage] = useState("");
  const [status, setStatus] = useState("idle");
  const send = async (event: SubmitEvent) => {
    event.preventDefault();
    setStatus("sending");
    const response = await window.fetch(endpoint, {
      method: "POST",
      // The key makes a retried submit the same submission. `website` is the form's spam trap.
      headers: { "content-type": "application/json", "idempotency-key": window.crypto.randomUUID() },
      body: JSON.stringify({ website: "", data: { email, message } }),
    });
    setStatus(response.ok ? "sent" : "failed");
  };

  return (
    <form onSubmit={send}>
      <label>Email <input type="email" required value={email} onInput={(event: InputEvent) => { setEmail((event.target as HTMLInputElement).value); }} /></label>
      <label>Message <textarea required minLength={3} maxLength={2000} value={message} onInput={(event: InputEvent) => { setMessage((event.target as HTMLTextAreaElement).value); }} /></label>
      <button disabled={status === "sending"}>Send</button>
      <p role="status">{status === "sent" ? "Thanks. We read every message." : status === "failed" ? "That did not send. Try again in a moment." : ""}</p>
    </form>
  );
}
