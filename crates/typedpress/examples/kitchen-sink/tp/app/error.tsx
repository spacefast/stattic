import { Link } from "typedpress/app";

export const metadata = { title: "Something went wrong" };

// Rendered with a 500 when a page below it throws. The error itself goes to the PHP log.
export default function ErrorPage() {
  return (
    <section>
      <h1>The kitchen page could not load</h1>
      <p>Try again in a moment.</p>
      <Link href="/">See the workshop schedule</Link>
    </section>
  );
}
