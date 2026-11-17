var b={cursor:{omega:18},picker:{omega:80},orbLag:{omega:30},pinDrop:{stiffness:520,damping:28},orbSnap:{stiffness:380,damping:30},sheet:{omega:20}},i=768,d=380,p=28,g=32,h={animationMs:620,staggerMs:55,steps:10},e=`
:host {
  all: initial;
  position: fixed;
  top: 0; left: 0; width: 0; height: 0;
  z-index: 2147483000;
  pointer-events: none;
  font: var(--sf-collab-text-md);
  color: var(--sf-collab-text);
  line-height: 1.5;
  box-sizing: border-box;
  direction: ltr;
  -webkit-font-smoothing: antialiased;
  text-rendering: optimizeLegibility;
}

/* Embedded host \u2014 the same shadow boundary and the same tokens, but in the flow
   of a host document instead of floating over one. The dashboard mounts the
   conversation this way: the all-initial reset still walls it off from the
   host's CSS, the overlay's fixed 0-box does not apply. */
:host([data-sf-embedded="1"]) {
  display: block;
  position: static;
  width: auto;
  height: auto;
  z-index: auto;
  pointer-events: auto;
}
`,t=`
  color-scheme: dark;

  /* Surfaces \u2014 dark glass (D1) */
  --sf-collab-surface: rgba(22, 22, 27, 0.88);
  /* What you read or type into (theme/glass.ts SOLID_GLASS), and the colour a
     badge or a ring punches out of. Near-opaque, not translucent: at the glass
     alpha the page's own text shows straight through a comment card. */
  --sf-collab-surface-solid: rgba(22, 22, 27, 0.96);
  --sf-collab-surface-raised: rgba(32, 32, 39, 0.92);
  --sf-collab-blur: blur(16px) saturate(1.5);
  --sf-collab-border: rgba(255, 255, 255, 0.09);
  --sf-collab-border-strong: rgba(255, 255, 255, 0.16);

  /* Ink */
  --sf-collab-text: #f4f4f6;
  --sf-collab-text-muted: #a6a6b4;
  --sf-collab-text-faint: #74747f;

  /* The one emphasis: neutral ink, never the space's brand. Light on dark
     glass, dark on light \u2014 the layer is a tool on the page, not part of it.
     The accent family and focus are !important: a Space's theme sheet dresses
     the overlay's font and background, never these, including a sheet served
     before the accent left it. */
  --sf-collab-accent: #f4f4f6 !important;
  --sf-collab-accent-hover: #ffffff !important;
  --sf-collab-accent-ink: #16161b !important;
  --sf-collab-accent-soft: rgba(244, 244, 246, 0.14) !important;
  /* The round controls that float on the page beside the orb. They stand out
     from the page the way the orb does: light on a dark site, dark on a light
     one. */
  --sf-collab-control: #f4f4f6;
  --sf-collab-control-ink: #16161b;

  /* States */
  --sf-collab-danger: #ff6b64;
  --sf-collab-success: #8be583;
  --sf-collab-warn: #fbb605;
  --sf-collab-focus: #f4f4f6 !important;

  --sf-collab-scrim: rgba(0, 0, 0, 0.55);
  --sf-collab-mask: #26262e;

  --sf-collab-shadow: 0 12px 40px rgba(0, 0, 0, 0.38), 0 2px 10px rgba(0, 0, 0, 0.28);
  --sf-collab-shadow-sm: 0 4px 16px rgba(0, 0, 0, 0.3), 0 1px 4px rgba(0, 0, 0, 0.24);

  /* Type \u2014 system stack only (D2), sized in px */
  --sf-collab-font: -apple-system, "SF Pro", system-ui, BlinkMacSystemFont, "Segoe UI",
    Helvetica, Arial, sans-serif;
  --sf-collab-font-mono: ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace;
  --sf-collab-text-lg: 600 14px/1.35 var(--sf-collab-font);
  --sf-collab-text-md: 400 13px/1.5 var(--sf-collab-font);
  --sf-collab-text-sm: 500 12px/1.4 var(--sf-collab-font);
  --sf-collab-text-xs: 400 11px/1.35 var(--sf-collab-font);
  --sf-collab-text-tag: 500 11px/1.2 var(--sf-collab-font-mono);

  /* Radii */
  --sf-collab-radius-lg: 14px;
  --sf-collab-radius-md: 9px;
  --sf-collab-radius-sm: 6px;
  --sf-collab-radius-full: 999px;

  /* Motion */
  --sf-collab-dur-1: 120ms;
  --sf-collab-dur-2: 180ms;
  --sf-collab-dur-3: 260ms;
  --sf-collab-dur-4: 420ms;
  --sf-collab-ease-out: cubic-bezier(0.22, 1, 0.36, 1);
  --sf-collab-ease-inout: cubic-bezier(0.65, 0, 0.35, 1);
  --sf-collab-ease-in: cubic-bezier(0.4, 0, 1, 1);
  /* The Sidebar's slide, and the dock it pushes: one curve, one duration, so
     the Sidebar's edge and the dock move as one. */
  --sf-collab-dur-sheet: 380ms;
  --sf-collab-ease-sheet: cubic-bezier(0.32, 0.72, 0, 1);
`,l=`
  color-scheme: light;

  --sf-collab-accent: #1d1d22 !important;
  --sf-collab-accent-hover: #3a3a42 !important;
  --sf-collab-accent-ink: #ffffff !important;
  --sf-collab-accent-soft: rgba(29, 29, 34, 0.1) !important;
  --sf-collab-control: #1d1d22;
  --sf-collab-control-ink: #ffffff;

  --sf-collab-surface: rgba(252, 252, 253, 0.9);
  --sf-collab-surface-solid: rgba(252, 252, 253, 0.96);
  --sf-collab-surface-raised: rgba(244, 244, 246, 0.94);
  --sf-collab-border: rgba(20, 20, 25, 0.1);
  --sf-collab-border-strong: rgba(20, 20, 25, 0.18);

  --sf-collab-text: #1d1d22;
  --sf-collab-text-muted: #63636e;
  --sf-collab-text-faint: #8f8f99;

  --sf-collab-danger: #d93a30;
  --sf-collab-success: #2e7d3a;
  --sf-collab-warn: #a86a00;
  --sf-collab-focus: #1d1d22 !important;

  --sf-collab-scrim: rgba(0, 0, 0, 0.35);
  --sf-collab-mask: #e8e8ec;

  --sf-collab-shadow: 0 12px 40px rgba(20, 20, 30, 0.16), 0 2px 10px rgba(20, 20, 30, 0.1);
  --sf-collab-shadow-sm: 0 4px 16px rgba(20, 20, 30, 0.12), 0 1px 4px rgba(20, 20, 30, 0.08);
`,r=`
  --sf-collab-surface: rgba(22, 22, 27, 0.98);
  --sf-collab-surface-raised: rgba(32, 32, 39, 0.98);
`,c=`
  --sf-collab-surface: rgba(252, 252, 253, 0.98);
  --sf-collab-surface-raised: rgba(244, 244, 246, 0.98);
`,f=`
  --sf-collab-surface: rgba(20, 20, 25, 0.97);
  --sf-collab-surface-raised: rgba(30, 30, 37, 0.97);
  --sf-collab-border: rgba(255, 255, 255, 0.16);
  --sf-collab-border-strong: rgba(255, 255, 255, 0.28);
`,n=`
  --sf-collab-surface: rgba(252, 252, 253, 0.98);
  --sf-collab-surface-raised: rgba(240, 240, 243, 0.98);
  --sf-collab-border: rgba(20, 20, 25, 0.2);
  --sf-collab-border-strong: rgba(20, 20, 25, 0.34);
`;function s(a,o){return`${a} {${t}}${o} {${l}}@supports not (backdrop-filter: blur(1px)) {${a} {${r}} ${o} {${c}}}@media (prefers-contrast: more) {${a} {${f}} ${o} {${n}}}`}var u=e+s(":host",':host([data-sf-appearance="light"])'),m=s(":root",':root[data-sf-appearance="light"]');export{b as a,i as b,d as c,p as d,g as e,h as f,u as g};
