import{a as t}from"./chunk-DHQ4JGNE.js";var e=`
.sf-shield { position: fixed; inset: 0; cursor: crosshair; pointer-events: auto; background: transparent; touch-action: none; }
.sf-pbox {
  position: fixed; top: 0; left: 0; pointer-events: none;
  outline: 2px solid var(--sf-collab-accent);
  box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.35);
  background: none;
  will-change: transform, width, height;
}
.sf-pbox[data-tiny="1"] { box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.35); }
.sf-pbox[data-commit="1"] { animation: sf-shutter 120ms var(--sf-collab-ease-out); }
.sf-ptag {
  position: fixed; top: 0; left: 0; pointer-events: none;
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 8px; border-radius: var(--sf-collab-radius-sm);
  ${t}
  font: var(--sf-collab-text-tag); color: var(--sf-collab-text); max-width: 44ch;
  will-change: transform;
}
.sf-pbox[hidden], .sf-ptag[hidden] { display: none; }
.sf-ptag-dim { color: var(--sf-collab-text-muted); }
.sf-ptag-depth { display: inline-flex; gap: 2px; margin-right: 2px; }
.sf-ptag-depth i { width: 3px; height: 3px; border-radius: 50%; background: var(--sf-collab-text-faint); }
.sf-ptag-parent {
  border-left: 1px solid var(--sf-collab-border); padding-left: 6px;
  color: var(--sf-collab-text-muted); font: inherit;
  pointer-events: auto; cursor: pointer; /* tappable widen segment on touch */
}
.sf-sel-btn:hover { background: var(--sf-collab-accent-hover); }
.sf-sel-btn {
  position: fixed; top: 0; left: 0; transform: translate(-50%, -100%);
  display: inline-flex; align-items: center; gap: 6px; height: 28px; padding: 0 10px;
  border-radius: var(--sf-collab-radius-full);
  /* the space's colour, like the orb: this is the layer offering to do its one job */
  border: 0; background: var(--sf-collab-accent); color: var(--sf-collab-accent-ink);
  box-shadow: var(--sf-collab-shadow-sm);
  font: var(--sf-collab-text-sm); font-weight: 600; cursor: pointer; pointer-events: auto;
  animation: sf-fade-up var(--sf-collab-dur-1) var(--sf-collab-ease-out);
}

@keyframes sf-fade-up { from { opacity: 0; transform: translate(-50%, -100%) translateY(8px); } to { opacity: 1; transform: translate(-50%, -100%); } }
/* "scale", not "transform": the box is placed by its inline translate, and a
   transform keyframe would flash it at the page's top-left for the shutter. */
@keyframes sf-shutter { 0% { scale: 1; } 40% { scale: 0.98; } 100% { scale: 1; } }

@media (pointer: coarse) {
  .sf-sel-btn { position: relative; }
  .sf-sel-btn::before {
    content: ""; position: absolute; left: 50%; top: 50%;
    transform: translate(-50%, -50%);
    width: max(100%, 40px); height: max(100%, 40px);
  }
}

@media (prefers-reduced-motion: reduce) {
  .sf-pbox { animation: none; }
  .sf-sel-btn { animation-duration: 80ms; animation-name: sf-fade; }
}
`;export{e as a};
