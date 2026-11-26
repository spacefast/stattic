import { db } from "spacepress/server";
import type { PageProps } from "./$types";
import { get_current_user_id, home_url, is_user_logged_in, wp_login_url } from "spacepress/server/wp";
import { Comments, Content, Live, notFound, useFormState } from "spacepress/app";
import { bookSeats, cancelSeats } from "../../../server";
import styles from "./workshop.module.css";

export const metadata = ({ item }: PageProps) => ({ title: item?.title ?? "Workshop" });

export default async function Workshop({ item }: PageProps) {
  if (!item) notFound();
  const booking = useFormState(bookSeats);
  const cancellation = useFormState(cancelSeats);
  const signedIn = is_user_logged_in();
  // Media columns hold ids; `with` loads their rows.
  const pictures = await db.query.events.findFirst({
    where: { id: item.id }, columns: { id: true }, with: { poster: true, gallery: true },
  });
  const poster = pictures ? pictures.poster : null;
  const gallery = pictures ? pictures.gallery : [];
  // The handout is a private file: only a signed-in visitor gets its signed, five-minute link.
  const attached = signedIn
    ? await db.query.events.findFirst({ where: { id: item.id }, columns: { id: true }, with: { handout: true } })
    : undefined;
  const handout = attached && attached.handout !== null && typeof attached.handout !== "number" ? attached.handout : null;
  const reservation = signedIn
    ? await db.query.registrations.findFirst({ where: { eventId: item.id, memberId: get_current_user_id() } })
    : undefined;
  return (
    <article className={styles.workshop}>
      <h1>{item.title}</h1>
      <p>{item.startsOn} · {item.venue} · {item.format === "demo" ? "Demonstration" : "Hands-on class"}</p>
      {item.host && (
        <p>Hosted by {item.host.website ? <a href={item.host.website}>{item.host.name}</a> : item.host.name}</p>
      )}
      {poster && <img className={styles.poster} src={poster.sizes.card?.url ?? poster.url} alt={poster.alt ?? ""} />}
      <Content value={item.description} />
      {item.equipment.length > 0 && <p>Bring: {item.equipment.join(", ")}</p>}
      {item.ingredients && item.ingredients.length > 0 && (
        <section>
          <h2>Ingredients</h2>
          <ul>{item.ingredients.map((ingredient) => <li>{ingredient.name} · {ingredient.grams} g</li>)}</ul>
        </section>
      )}
      {(item.sections ?? []).map((section) => section.layout === "tip"
        ? <aside className={styles.tip}><h2>{section.heading}</h2><Content value={section.body} /></aside>
        : <blockquote><p>{section.text}</p><cite>{section.author}</cite></blockquote>)}
      {gallery.length > 0 && (
        <ul className={styles.gallery}>
          {gallery.map((picture) => <li><img src={picture.sizes.card?.url ?? picture.url} alt={picture.alt ?? ""} /></li>)}
        </ul>
      )}
      {item.format === "demo" && item.demoUrl && <p><a href={item.demoUrl}>Watch the demonstration</a></p>}
      {handout && <p><a href={handout.url}>Download the handout ({handout.filename})</a></p>}
      <Live id="registration">
        <p>{item.registrationOpen ? "Registration is open." : "Registration is closed."}</p>
      </Live>
      {!signedIn && item.registrationOpen && (
        <p><a href={wp_login_url(home_url(`/events/${item.slug}/`))}>Sign in to reserve a seat</a></p>
      )}
      {signedIn && reservation && (
        <form action={cancelSeats}>
          <input type="hidden" name="eventId" value={item.id} />
          <p>You hold {reservation.seats} seat(s). Reservation code: {reservation.code}</p>
          <button>Cancel my reservation</button>
        </form>
      )}
      {signedIn && !reservation && item.registrationOpen && (
        <form action={bookSeats}>
          <input type="hidden" name="eventId" value={item.id} />
          <label>Seats <input name="seats" type="number" min={1} max={4} defaultValue={booking.values.seats ?? 1} /></label>
          <label>Allergies, separated by commas <input name="allergies" maxLength={200} defaultValue={booking.values.allergies ?? ""} /></label>
          <button>Reserve seats</button>
        </form>
      )}
      {booking.error && <p role="alert">{booking.error.message}</p>}
      {booking.result && <p role="status">A confirmation is on its way to your account's email address.</p>}
      {cancellation.error && <p role="alert">{cancellation.error.message}</p>}
      {cancellation.result && <p role="status">Your reservation of {cancellation.result.seats} seat(s) is cancelled.</p>}
      <Comments for={item} />
    </article>
  );
}
