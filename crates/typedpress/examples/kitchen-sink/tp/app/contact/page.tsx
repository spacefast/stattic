import { useFormState } from "typedpress/app";
import { contactKitchen } from "../../server";

export const metadata = { title: "Contact the kitchen" };

export default function Contact() {
  const contact = useFormState(contactKitchen);
  return (
    <section>
      <h1>Contact the kitchen</h1>
      <form action={contactKitchen}>
        <label>Email <input name="email" type="email" required defaultValue={contact.values.email ?? ""} /></label>
        <label>Message <textarea name="message" required minLength={3} maxLength={2000} defaultValue={contact.values.message ?? ""} /></label>
        <button>Send</button>
      </form>
      {contact.error && <p role="alert">{contact.error.message}</p>}
      {contact.result && <p role="status">Thanks. We read every message.</p>}
    </section>
  );
}
