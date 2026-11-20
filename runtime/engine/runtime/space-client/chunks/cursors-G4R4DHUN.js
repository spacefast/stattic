import{a as j,b as U}from"./chunk-HWO2J6AG.js";import{F as Q,p as J,q as Z}from"./chunk-NGMBPLSR.js";import"./chunk-FGRW3PCW.js";import"./chunk-A2PPBQOK.js";import{b as I}from"./chunk-RYWM7HGM.js";import"./chunk-EUXPHIKX.js";import"./chunk-W5MTKZRN.js";import{a as ge,b as q}from"./chunk-XDVMX3GD.js";import"./chunk-X44FYCMZ.js";import"./chunk-Y6RYWYJU.js";import"./chunk-5R2CZELW.js";import{a as z}from"./chunk-UTPTYCGH.js";import{b as P,c as K}from"./chunk-T4WTB5OG.js";import"./chunk-DHQ4JGNE.js";import{a as v,b as _,d as A,e as X,f as W}from"./chunk-LKZNXTKA.js";import{a as B}from"./chunk-7YDESRNM.js";import"./chunk-OEFJXNBA.js";import{c as O}from"./chunk-7SZRL6FY.js";var N=O(ge(),1);var ee=`
/* Seeing others is the orb's switch (react/cursors.tsx); sending is not. */
.sf-cursor-layer { opacity: 0; transition: opacity var(--sf-collab-dur-2) var(--sf-collab-ease-out); }
.sf-cursor-layer[data-shown="1"] { opacity: 1; }
.sf-cursor { position: fixed; top: 0; left: 0; pointer-events: none; will-change: transform; }
.sf-cursor-body {
  position: absolute; left: 0; top: 0; width: 0; height: 0; transform-origin: 0 0;
  filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.25));
}
.sf-flag { position: absolute; left: 0; top: 0; }
.sf-flag-shape { position: absolute; left: 0; top: 0; display: block; overflow: visible; }
.sf-flag-shape path { transition: d var(--sf-collab-dur-2) var(--sf-collab-ease-out); }
.sf-flag-label {
  position: absolute; left: 4px; top: 4px;
  height: 26px; padding: 0 11px 0 11px;
  font: var(--sf-collab-text-sm); font-weight: 600; line-height: 26px; white-space: nowrap;
  max-width: 18ch; overflow: hidden; text-overflow: ellipsis;
  transition: opacity var(--sf-collab-dur-1) var(--sf-collab-ease-out);
}
.sf-cursor-body[data-corner="tr"] .sf-flag-shape { left: auto; right: 0; transform: scaleX(-1); }
.sf-cursor-body[data-corner="tr"] .sf-flag-label { left: auto; right: 4px; }
.sf-cursor-body[data-corner="bl"] .sf-flag-shape { top: auto; bottom: 0; transform: scaleY(-1); }
.sf-cursor-body[data-corner="bl"] .sf-flag-label { top: auto; bottom: 4px; }
.sf-cursor-body[data-corner="br"] .sf-flag-shape { left: auto; right: 0; top: auto; bottom: 0; transform: scale(-1, -1); }
.sf-cursor-body[data-corner="br"] .sf-flag-label { left: auto; right: 4px; top: auto; bottom: 4px; padding: 0 12px 0 11px; }
.sf-cursor-body[data-corner="tr"] .sf-flag, .sf-cursor-body[data-corner="br"] .sf-flag { left: auto; right: 0; }
.sf-cursor-body[data-corner="bl"] .sf-flag, .sf-cursor-body[data-corner="br"] .sf-flag { top: auto; bottom: 0; }
/* Chatting: the name steps up small and what they typed sits under it, the
   tag growing round both. */
.sf-flag-name, .sf-flag-line { display: block; overflow: hidden; text-overflow: ellipsis; }
.sf-flag-line:empty { display: none; }
.sf-flag[data-chat="1"] .sf-flag-label {
  /* Hung off a box with no width of its own, it would shrink to its narrowest
     word: it takes the line's own width, up to a readable measure. */
  width: max-content; max-width: 244px;
  height: auto; line-height: 1.35; padding: 5px 12px 7px; white-space: normal;
}
.sf-flag[data-chat="1"] .sf-flag-name { font: var(--sf-collab-text-xs); font-weight: 600; opacity: 0.8; white-space: nowrap; }
.sf-flag[data-chat="1"] .sf-flag-line { font: var(--sf-collab-text-md); font-weight: 500; line-height: 1.35; overflow-wrap: break-word; }
/* Idle: the name fades and the tag draws in to its point. */
.sf-flag[data-folded="1"] .sf-flag-label { opacity: 0; }

/* Your own name, following your own pointer (react/cursors.tsx). Shown only
   while your hand is moving on the page. */
.sf-own-flag {
  position: fixed; top: 0; left: 0; pointer-events: none; will-change: transform;
  display: flex; align-items: center; height: 24px; padding: 0 10px;
  border-radius: 12px; opacity: 0;
  font: var(--sf-collab-text-sm); font-weight: 600; line-height: 24px; white-space: nowrap;
  box-shadow: 0 0 0 1.5px #fff, 0 2px 6px rgba(0, 0, 0, 0.25);
  transition: opacity var(--sf-collab-dur-2) var(--sf-collab-ease-out);
}
.sf-own-flag[data-shown="1"] { opacity: 1; }
.sf-own-flag span { max-width: 18ch; overflow: hidden; text-overflow: ellipsis; }
/* Chat (\`/\`): the label opens into a line at your cursor \u2014 your name small
   above, what you type (and, for a moment after, what you said) under it. */
.sf-own-said, .sf-own-input { display: none; }
.sf-own-flag[data-chat] {
  opacity: 1; flex-direction: column; align-items: stretch;
  height: auto; padding: 5px 12px 7px; line-height: 1.3;
}
.sf-own-flag[data-chat] .sf-own-name { font: var(--sf-collab-text-xs); font-weight: 600; opacity: 0.8; }
.sf-own-flag[data-chat="typing"] .sf-own-input {
  display: block; width: 220px; margin: 0; padding: 0; border: 0; outline: none;
  background: transparent; color: inherit; caret-color: currentColor;
  font: var(--sf-collab-text-md); font-weight: 500; line-height: 1.35;
  resize: none; overflow: hidden; white-space: pre-wrap; overflow-wrap: anywhere;
}
.sf-own-input::placeholder { color: currentColor; opacity: 0.6; }
/* The open line is its own focus: no ring drawn round it inside the label. */
.sf-own-input:focus-visible { box-shadow: none; }
.sf-own-flag[data-chat="said"] .sf-own-said {
  display: block; max-width: 220px; white-space: normal; overflow-wrap: anywhere;
  font: var(--sf-collab-text-md); font-weight: 500;
}

@media (prefers-reduced-motion: reduce) {
  .sf-own-flag { transition: none; }
  .sf-flag-shape path, .sf-flag-label { transition: none; }
}
`;var ae=O(q(),1);function he(){return{now:()=>performance.now(),setTimer:(e,t)=>setTimeout(e,t),clearTimer:e=>clearTimeout(e)}}function be(e){let t=[];for(let o=e;o!==document.documentElement;){let n=o.parentElement;if(!n)return null;let r=0;for(let l=o.previousElementSibling;l;l=l.previousElementSibling)r+=1;t.unshift(`${r}:${o.localName}`),o=n}return t.length>0?t.join("/"):null}function ye(e){let t=document.documentElement;for(let o of e.split("/")){let n=o.indexOf(":"),r=t.children[Number(o.slice(0,n))];if(!r||r.localName!==o.slice(n+1))return null;t=r}return t}function we(e){let t=e.clock??he(),o=Math.max(33,e.intervalMs??34),n=Number.NEGATIVE_INFINITY,r=null,l=null,c=null,p=!1;function h(i,u){if(!r||r.el!==i.el)return!1;let d=(i.point.x-r.point.x)*u.width,m=(i.point.y-r.point.y)*u.height;return Math.hypot(d,m)*(globalThis.devicePixelRatio||1)<1}function b(i){l=null,n=t.now();let u=null,d=null;for(let g of document.elementsFromPoint(i.x,i.y)){let f=g.getBoundingClientRect();if(f.width>0&&f.height>0){u=g,d=f;break}}if(!u||!d)return;let m=be(u);if(!m)return;let y={el:m,point:{x:A((i.x-d.left)/d.width,0,1),y:A((i.y-d.top)/d.height,0,1)}};h(y,d)||(e.send(y),r=y)}function C(){if(c||!l)return;let i=Math.max(0,o-(t.now()-n));c=t.setTimer(()=>{c=null,l&&b(l)},i)}return{sample(i,u){p||(l={x:i,y:u},t.now()-n>=o?(c&&t.clearTimer(c),c=null,b(l)):C())},destroy(){p||(c&&t.clearTimer(c),c=null,l=null,p=!0)}}}var xe=5e3,oe=3e3;function ve(e){let t=ye(e.el);if(!t)return null;let o=t.getBoundingClientRect();return{x:o.left+e.x*o.width,y:o.top+e.y*o.height}}var T={offset:4,height:26,radius:11,join:9,folded:14};function Te(e,t){let o=T.offset,n=Math.min(T.radius,e/2,t/2),r=Math.min(T.join,e-n,t-n);return`M0 0L${o+r} ${o}H${o+e-n}A${n} ${n} 0 0 1 ${o+e} ${o+n}V${o+t-n}A${n} ${n} 0 0 1 ${o+e-n} ${o+t}H${o+n}A${n} ${n} 0 0 1 ${o} ${o+t-n}V${o+r}Z`}var $=16,te=32;function ne(e,t,o,n,r){let l=globalThis.innerWidth||0,c=globalThis.innerHeight||0,p=e==="tr"||e==="br",h=e==="bl"||e==="br",b=p?t>l-n-$-te:t>l-n-$;return(h?o>c-r-$-te:o>c-r-$)?b?"br":"bl":b?"tr":"tl"}var M={perVelocityX:.016,perVelocityY:.012,max:26,stiffness:180,damping:9};function Ce(e,t){let o=(e.spring.x-e.was.x)/t,n=(e.spring.y-e.was.y)/t;e.was={x:e.spring.x,y:e.spring.y};let r=e.corner==="tr"||e.corner==="br"?-1:1,l=e.corner==="bl"||e.corner==="br"?-1:1,c=A(-(l*o*M.perVelocityX+r*n*M.perVelocityY),-M.max,M.max);({x:e.swing.a,v:e.swing.v}=X(e.swing.a,e.swing.v,c,M.stiffness,M.damping,t)),e.body.style.rotate=`${e.swing.a.toFixed(2)}deg`}var Ee="#ff603d";function H(e,t,o){let n=Math.max(T.height,t.offsetHeight),r=o?T.folded:t.offsetWidth,l=o?T.folded:n,c=Te(r,l),p=e.firstElementChild;p?.setAttribute("d",c),p instanceof SVGElement&&p.style.setProperty("d",`path("${c}")`),e.setAttribute("width",String(T.offset+t.offsetWidth+4)),e.setAttribute("height",String(T.offset+n+4))}function re(e){let t=j(e);return t&&U(t)>.45?"#16161b":"#ffffff"}function se(e){return e.ui.cursorsAlways||e.ui.expanded||e.mode!=="idle"}function Se(e,t,o){let n=new Map,r=0;function l(i,u){let d=i.color||Ee,m=_("svg",{class:"sf-flag-shape"});m.appendChild(_("path",{fill:d,stroke:"#fff","stroke-width":"1.5","stroke-linejoin":"round"}));let y=v("span",{class:"sf-flag-name",text:i.name}),g=v("span",{class:"sf-flag-line"}),f=v("span",{class:"sf-flag-label",style:{color:re(d)}},[y,g]),s=v("span",{class:"sf-flag"},[m,f]),x=v("div",{class:"sf-cursor-body",dataset:{corner:"tl"}},[s]),w=v("div",{class:"sf-cursor"},[x]);return t.appendChild(w),H(m,f,!1),o||x.animate?.([{scale:"0.3"},{scale:"1.15",offset:.6},{scale:"1"}],{duration:360,easing:"cubic-bezier(0.34, 1.56, 0.64, 1)"}),{el:w,body:x,flag:s,drawing:m,label:f,name:y,line:g,spring:new W(u.x,u.y,z.cursor.omega),lastMoved:i.last_update,lastTarget:{...i.target},folded:!1,corner:"tl",was:{...u},swing:{a:0,v:0}}}function c(i,u){n.delete(i);let d=o?null:u.el.animate?.([{opacity:1},{opacity:0,scale:"0.8"}],{duration:200,easing:"cubic-bezier(0.4, 0, 1, 1)"});if(d){let m=()=>u.el.remove();d.finished.then(m,m)}else u.el.remove()}let p=0;function h(i){r=0;let u=Date.now(),d=e.store.getState(),m=p?Math.min(.05,(i-p)/1e3):1/60;p=i;let y=0;for(let[g,f]of n)d.cursors.has(g)||c(g,f);for(let[g,f]of d.cursors){if(u-f.last_update>xe){let S=n.get(g);S&&c(g,S);continue}let s=n.get(g),x=ve(f.target);if(!x){s&&c(g,s);continue}s||(s=l(f,x),n.set(g,s));let w=f.chat&&u-(f.chat_at??0)<P?f.chat:"";(s.name.textContent!==f.name||s.line.textContent!==w)&&(s.name.textContent=f.name,s.line.textContent=w,w?s.flag.dataset.chat="1":delete s.flag.dataset.chat,H(s.drawing,s.label,s.folded&&!w)),!!w||f.target.el!==s.lastTarget.el||f.target.x!==s.lastTarget.x||f.target.y!==s.lastTarget.y?(s.lastTarget={...f.target},s.lastMoved=u,s.folded&&(s.folded=!1,s.flag.removeAttribute("data-folded"),H(s.drawing,s.label,!1))):!s.folded&&u-s.lastMoved>oe&&(s.folded=!0,s.flag.setAttribute("data-folded","1"),H(s.drawing,s.label,!0)),s.spring.step(x.x,x.y,m,o),s.el.style.transform=`translate(${s.spring.x}px, ${s.spring.y}px)`;let L=ne(s.corner,s.spring.x,s.spring.y,s.label.offsetWidth+T.offset+8,s.label.offsetHeight+T.offset+6);L!==s.corner&&(s.corner=L,s.body.dataset.corner=L),o||Ce(s,m),y+=1}y>0?r=requestAnimationFrame(h):p=0}let b=()=>{!r&&e.store.getState().cursors.size>0&&(r=requestAnimationFrame(h))},C=e.store.subscribe(i=>i.cursors,b);return b(),()=>{C(),r&&cancelAnimationFrame(r);for(let i of n.values())i.el.remove();n.clear()}}function Le(e){let t=we({send:l=>{e.sendEphemeral("cursor:update",l).catch(()=>{})}}),o=null,n=()=>{o&&e.store.getState().connection==="connected"&&t.sample(o.x,o.y)},r=l=>{o={x:l.clientX,y:l.clientY},n()};return globalThis.addEventListener("pointermove",r,{passive:!0}),globalThis.addEventListener("scroll",n,{passive:!0,capture:!0}),globalThis.addEventListener("resize",n,{passive:!0}),()=>{globalThis.removeEventListener("pointermove",r),globalThis.removeEventListener("scroll",n,{capture:!0}),globalThis.removeEventListener("resize",n),t.destroy()}}var R={x:16,y:22},Me=34;function Re(e){let t=e.composedPath()[0];return t instanceof HTMLInputElement||t instanceof HTMLTextAreaElement||t instanceof HTMLSelectElement||t instanceof HTMLElement&&t.isContentEditable}function ke(e,t){let o=v("span",{class:"sf-own-name"}),n=v("span",{class:"sf-own-said"}),r=v("textarea",{class:"sf-own-input",rows:1,maxlength:K,autocomplete:"off",spellcheck:"false",placeholder:I.presence.chatPlaceholder,aria:{label:I.presence.chatLabel}}),l=v("span",{class:"sf-own-flag"},[o,n,r]);t.append(l);let c=()=>{let{name:a,color:E}=e.store.getState().identity;o.textContent=a,l.style.background=E,l.style.color=re(E)};c();let p=e.store.subscribe(a=>a.identity,c),h=null,b="tl",C,i="off",u,d,m=0,y,g="",f=a=>{a?l.dataset.shown="1":delete l.dataset.shown},s=()=>{clearTimeout(C),C=setTimeout(()=>{i==="off"&&f(!1)},oe)},x=()=>{if(!h)return;let a=l.offsetWidth,E=l.offsetHeight;b=ne(b,h.x,h.y,a+R.x,E+R.y);let ue=b==="tr"||b==="br",de=b==="bl"||b==="br",pe=ue?h.x-R.x-a:h.x+R.x,me=de?h.y-R.y-E:h.y+R.y;l.style.transform=`translate(${pe}px, ${me}px)`},w=a=>{clearTimeout(d),d=void 0,a!==g&&(g=a,m=performance.now(),e.sendEphemeral("cursor:chat",{text:a}).catch(()=>{}))},D=()=>{let a=Me-(performance.now()-m);a<=0?w(r.value.trim()):d??=setTimeout(()=>w(r.value.trim()),a)},L=()=>{r.style.height="auto",r.style.height=`${r.scrollHeight}px`},S=a=>{i=a,a==="off"?delete l.dataset.chat:l.dataset.chat=a,x()},le=()=>{clearTimeout(u),r.value="",S("typing"),L(),f(!0),clearTimeout(C),F(),r.focus({preventScroll:!0})},F=()=>{clearTimeout(y),y=setTimeout(()=>k(!1),P)},k=a=>{if(i!=="typing")return;clearTimeout(y);let E=a?r.value.trim():"";if(w(E),!E){S("off"),r.blur(),s();return}n.textContent=E,S("said"),r.blur(),u=setTimeout(()=>{S("off"),g="",s()},P)},G=a=>{if(a.pointerType!=="touch"){if(i==="off"&&a.target instanceof Node&&e.isWidgetNode(a.target)){f(!1);return}h={x:a.clientX,y:a.clientY},f(!0),i==="off"&&s(),x()}},V=a=>{a.relatedTarget===null&&i==="off"&&f(!1)},Y=a=>{a.key!=="/"||a.defaultPrevented||i==="typing"||a.metaKey||a.ctrlKey||a.altKey||Re(a)||!h||!se(e.store.getState())||(a.preventDefault(),le())},ie=a=>{a.stopPropagation(),!a.isComposing&&(a.key==="Enter"?(a.preventDefault(),k(!0)):a.key==="Escape"&&(a.preventDefault(),k(!1)))},fe=()=>k(!0),ce=()=>{L(),x(),F(),D()};return r.addEventListener("input",ce),r.addEventListener("keydown",ie),r.addEventListener("blur",fe),globalThis.addEventListener("pointermove",G,{passive:!0}),globalThis.addEventListener("keydown",Y),globalThis.document?.addEventListener("pointerout",V),()=>{(i==="typing"||i==="said")&&w(""),globalThis.removeEventListener("pointermove",G),globalThis.removeEventListener("keydown",Y),globalThis.document?.removeEventListener("pointerout",V),p(),clearTimeout(C),clearTimeout(u),clearTimeout(y),clearTimeout(d),l.remove()}}function Ve(){let e=J(),t=Q(),o=(0,N.useRef)(null),n=Z(se);return(0,N.useEffect)(()=>{let r=o.current;if(!r)return;B(e.root,ee);let l=Se(e,r,t),c=Le(e),p=ke(e,r);return()=>{l(),c(),p()}},[e,t]),(0,ae.jsx)("div",{ref:o,className:"sf-cursor-layer","data-shown":n?"1":void 0,"aria-hidden":"true"})}export{Ve as Cursors,we as createCursorThrottle,ve as cursorClientPoint,Te as tagPath};
