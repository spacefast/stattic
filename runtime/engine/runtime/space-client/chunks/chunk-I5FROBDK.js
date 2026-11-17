import{g as i}from"./chunk-UTPTYCGH.js";import{g as n}from"./chunk-T4WTB5OG.js";import{a as c}from"./chunk-LKZNXTKA.js";var l=`
.sf-avatar {
  position: relative;
  border-radius: var(--sf-collab-radius-full);
  display: grid; place-items: center; overflow: hidden;
  font-weight: 600; flex: none;
}
.sf-avatar img {
  position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover;
  border-radius: inherit;
  opacity: 0; transition: opacity var(--sf-collab-dur-1) var(--sf-collab-ease-out);
}
.sf-avatar img[data-ok="1"] { opacity: 1; }
/* Where faces stand for who is here (presence), a photo keeps the person's
   color as a ring just inside its edge, so a face still says which cursor and
   which pins are theirs. Drawn over the photo; an initial needs no ring. */
.sf-avatar::after {
  content: ""; position: absolute; inset: 0; border-radius: inherit; pointer-events: none;
  box-shadow: inset 0 0 0 1.5px var(--sf-avatar-color); opacity: 0;
}
:is(.sf-presence, .sf-presence-row) .sf-avatar:has(img[data-ok="1"])::after { opacity: 1; }
`;var f=`
*, *::before, *::after { box-sizing: border-box; }
button { font: inherit; color: inherit; border: 0; background: none; cursor: pointer; padding: 0; }
/* One ring, in the space's own accent (--sf-collab-focus, derived in
   collabThemeCss). The separating halo is drawn in the surface's own ink rather
   than black: a hard black ring reads as a defect on a light overlay. */
button:focus-visible, a:focus-visible, [tabindex]:focus-visible, textarea:focus-visible, input:focus-visible {
  outline: none;
  box-shadow: 0 0 0 2px var(--sf-collab-surface-solid), 0 0 0 4px var(--sf-collab-focus);
}

/* ---- layers ---- */
.sf-layer { position: fixed; inset: 0; pointer-events: none; contain: layout style; }
.sf-layer > * { pointer-events: auto; }

/* ---- keyframes shared across surfaces ---- */
@keyframes sf-fade { from { opacity: 0; } to { opacity: 1; } }
@keyframes sf-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.45; } }
@keyframes sf-strip-in { from { opacity: 0; transform: translateX(-50%) translateY(-8px); } to { opacity: 1; transform: translateX(-50%); } }

@media (prefers-reduced-motion: reduce) {
  * { transition-duration: 0.01ms !important; }
}
`;function d(){let e=new CSSStyleSheet;return e.replaceSync(i+f+l),e}var h="spacefast-collab",m=[{name:"picker",z:"highlight"},{name:"pins",z:"pins"},{name:"orb",z:"orb"},{name:"cards",z:"cards"},{name:"sheet",z:"sheet"},{name:"toasts",z:"toasts"},{name:"cursors",z:"cursors"}];function u(e,t){let o=e.querySelector(`.sf-l-${t}`);if(o)return o;let a=m.find(p=>p.name===t)?.z;if(!a)throw new Error(`unknown overlay layer: ${t}`);let s=c("div",{class:`sf-layer sf-l-${t}`,style:{zIndex:String(n[a])}});return e.appendChild(s),s}var r=class extends HTMLElement{};function y(e,t){let o=customElements.get(e);if(o!==t){if(o!==void 0){console.warn(`Spacefast: <${e}> is already defined; skipping registration.`);return}customElements.define(e,t)}}function T(){y(h,r);let e=new r,t=e.attachShadow({mode:"open"});return t.adoptedStyleSheets=[d()],document.documentElement.appendChild(e),{host:e,root:t,layer:o=>u(t,o),destroy(){e.remove()}}}export{u as a,T as b};
