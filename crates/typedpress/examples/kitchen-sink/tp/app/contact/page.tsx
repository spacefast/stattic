import { apiReference } from "typedpress/content";
import { ContactForm } from "../../components/ContactForm";

export const metadata = { title: "Contact the kitchen" };

export default function Contact() {
  // The `services.forms.contact` endpoint, under wherever this site serves the app's API.
  const endpoint = `${apiReference().server}/services/forms/contact`;
  return <section><h1>Contact the kitchen</h1><ContactForm endpoint={endpoint} /></section>;
}
