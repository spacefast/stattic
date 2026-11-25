"use client";

import { sharedState, uploadFile, useEffect, useEvent, useLive, useMount, useRef, useState } from "spacepress/client";
import styles from "./WorkshopBoard.module.css";

export const display = sharedState({ dismissals: 0 });

export function WorkshopBoard() {
  // The element `ref={toggle}` is on: read in handlers and effects, in the browser.
  const toggle = useRef<HTMLButtonElement>();
  const [ready, setReady] = useState(false);
  const [open, setOpen] = useState(false);
  const [uploadedName, setUploadedName] = useState("");
  const openWorkshops = useLive<{ id: number }>("events", {
    where: { registrationOpen: true }, columns: { id: true }, limit: 100,
  });
  const workshops = openWorkshops.data;
  const count = workshops ? workshops.length : 0;
  useMount(() => { setReady(true); });
  // Escape closed the panel: put the keyboard back on the button that opens it.
  useEffect(() => {
    if (display.dismissals > 0) toggle.current?.focus();
  }, [display.dismissals]);
  useEvent("keydown", (event) => {
    if (open && event.key === "Escape") {
      setOpen(false);
      display.dismissals += 1;
    }
  });
  const onHandout = async (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    const saved = await uploadFile("handouts", file);
    setUploadedName(saved.filename);
  };

  return (
    <aside className={styles.board}>
      <button ref={toggle} type="button" disabled={!ready} aria-expanded={open} onClick={() => { setOpen(!open); }}>
        Workshops with open registration
      </button>
      <p className={styles.count} hidden={!open}>{count} workshops have open registration.</p>
      <label hidden={!open}>
        Upload handout
        <input type="file" accept="application/pdf" onChange={onHandout} />
      </label>
      <p hidden={!open}>{uploadedName}</p>
    </aside>
  );
}
