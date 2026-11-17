import{a as de}from"./chunk-FGRW3PCW.js";import{a as ie}from"./chunk-I5FROBDK.js";import{c as ee,e as M,f as te,h as q,v as H}from"./chunk-WQKWELXU.js";import{a as ce,b as fe}from"./chunk-XDVMX3GD.js";import{a as Z}from"./chunk-56PBR2DQ.js";import{a as v,f as re,g as ne}from"./chunk-Y6RYWYJU.js";import{a as C}from"./chunk-5R2CZELW.js";import{b as D,c as se}from"./chunk-UTPTYCGH.js";import{d as oe,o as N,p as ae}from"./chunk-T4WTB5OG.js";import{a as ue,b as _}from"./chunk-DHQ4JGNE.js";import{a as le}from"./chunk-7YDESRNM.js";import{c as j}from"./chunk-7SZRL6FY.js";var Y={composer:{label:"New comment",newPlaceholder:"Add a comment\u2026",replyPlaceholder:"Reply\u2026",submitNew:"Comment",submitReply:"Reply",reconnecting:"Reconnecting\u2026",sending:"Sending\u2026",cancel:"Cancel",empty:"Can't post silence. Type something first.",discardQuestion:"Discard this comment?",discard:"Discard",keepWriting:"Keep writing",selectParent:"Select the element around this one",selectChild:"Select the element inside this one",onQuote:e=>`On \u201C${e}\u201D`,onDrawing:"On your drawing"},mention:{list:"People to mention"},identity:{renameHint:e=>`You're commenting as ${e}. Click to change your name.`,dialogTitle:"What should we call you?",dialogLede:"Your name shows next to your comments.",nameLabel:"Name",emailLabel:"Email (optional)",replyEmails:"Reply emails",useAccount:"Use your Spacefast account",emailHint:"Shows your Gravatar. Confirm from your inbox to get replies. Never shown publicly.",emailSaveFailed:"Couldn't save that email. Try again.",save:"Save",cancel:"Cancel"},capture:{failed:"Posted, but the screenshot didn't take."},attach:{add:"Attach a file",uploading:"Uploading\u2026",remove:"Remove attachment",retry:"Retry upload",tooLarge:"Over 5 MB",blocked:"Can't upload that file type",failed:"Upload failed",download:"Download"},guest:{label:"Guest",tooltip:"Guest via shared access"},comment:{from:e=>`Comment from ${e}`,you:"You",delete:"Delete reply",deleteConfirm:"Delete this reply?",deleteVerb:"Delete",blockGuest:"Block this guest",blockGuestConfirm:"They can still read, but can't comment here.",blockVerb:"Block"},spam:{pill:"Spam",mark:"Mark as spam",markConfirm:"Off your pages, into Spam?",markVerb:"Mark as spam",marked:"Marked as spam",notSpam:"Not spam",restored:"Marked not spam",gone:"This comment isn't held anymore."},reaction:{add:"React"},anchor:{orphanTitle:"Can't find this spot anymore",orphanBody:"The page changed since this comment. Re-pin it to put it back.",rePin:"Re-pin",onPath:e=>`on ${e}`,offPage:"off-page",copySelector:"Copy selector",screenshot:"View screenshot"},row:{pageComment:"Page comment",drawing:"Drawing",orphan:"Lost its spot"},version:{chipTooltip:e=>`Made on ${e}`,openThere:e=>`Open this comment on ${e}`,openOn:e=>`Open on ${e} \u2197`,viewLive:"View live",older:"Older version"},draw:{yourColor:"Your color",undo:"Undo"},toast:{offline:"You're offline. Comments send when you're back."},activity:{rail:"Recent comment activity",commented:"commented",replied:"replied",resolved:"resolved a comment",reopened:"reopened a comment"}};var y={orb:{tooltip:"Comments",signedInAs:e=>`signed in as ${e}`,done:"Done",doneLabel:"Done commenting",doneDrawingLabel:"Done drawing",finishFirst:"Post or cancel this comment first.",viewing:e=>e===1?"1 other viewing":`${e} others viewing`},presence:{title:"Here now",you:"You",rename:"Change your name",cursorsAlways:"Always show cursors",chatLabel:"Say something at your cursor",chatPlaceholder:"Say something\u2026",chatHint:{before:"Press",after:"to chat at your cursor"}},toolbar:{label:"Comments",comment:"Comment",commentOn:"Comment on an element",draw:"Draw",sidebar:"Sidebar",telemetry:"Telemetry",versions:"Versions"},telemetry:{tuningIn:"Tuning in\u2026",unreachable:"The live feed isn't answering right now.",hereNow:"Here now",onThisPage:e=>e===1?"1 on this page":`${e} on this page`,peopleInTabs:(e,t)=>`${e} ${e===1?"person":"people"} in ${t} ${t===1?"tab":"tabs"}`,counted:e=>e===1?"1 counted":`${e} counted`,undecided:e=>`${e} not asked yet`,declined:e=>e===1?"1 declined, not counted":`${e} declined, not counted`,everyoneCounts:"Everyone here counts.",arrivals:"Arrivals, last minute",arrived:e=>e===1?"1 arrived":`${e} arrived`,arrivalsSlot:"Arrivals need the analytics tracker.",arrivalsMetric:"Arrivals",secondsFromNow:"Seconds from now",lastMinute:"last minute",visitors:"Visitors",scrolled:"Scrolled",page:"Page",source:"Source",country:"Country",pages:"Pages",here:"Here now",elsewhere:"Elsewhere on this Space",empty:"Nobody is here right now.",seatsSlot:"Live analytics counts visitors without listing them.",you:"you",visitor:"visitor",from:e=>`from ${e}`,down:e=>`${e}% down`,sources:"Sources",direct:"direct",countries:"Countries",sourcesEmpty:"Sources show for visitors who count.",moments:"Moments",momentsSlot:"Purchases, sign-ups, and other moments land here once the Space sends events.",momentsEmpty:"Nothing in the last few minutes. Purchases and sign-ups show up here as they happen.",engaged:"Engaged",medianEngaged:"Median engaged",engagedSlot:"Engaged time needs the analytics tracker.",fromPresence:"Live from Cast presence",fromAnalytics:"Live from analytics",behind:e=>`${e} ms behind`,dropped:e=>e===1?"1 batch dropped":`${e} batches dropped`,asOf:e=>`as of ${e} ago`,ago:e=>`${e} ago`,week:(e,t)=>{let a=`${e.toLocaleString("en-US")} ${e===1?"page view":"page views"} in 7 days`;if(t===0)return a;let o=Math.round((e-t)/t*100);return`${a}, ${o>0?"+":""}${o}% vs the 7 before`},now:"Now",range:"Range",lastDays:e=>`Last ${e} days`},trends:{loading:"Reading the history\u2026",unreachable:"Trends couldn't be reached. Close the drawer and open it again.",noAnswer:"No answer came back for this one.",visitors:"Visitors",pageViews:"Page views",engagedTime:"Engaged time",topPages:"Top pages",pagesThenAndNow:"Pages, then and now",engagedByPage:"Engaged time by page",referrers:"Where readers come from",scroll:"How far people read"},panel:{sidebar:"Sidebar",comments:"Comments",activity:"Activity",telemetry:"Telemetry",thisPage:"On this page",allPages:"All pages",show:"Show comments",open:"Open",resolved:"Resolved",empty:"No comments yet. Click Comment, then anything on the page.",close:"Close",replies:e=>e===1?"1 reply":`${e} replies`,back:"All comments",previous:"Previous comment",next:"Next comment",options:"Comment options",markAllRead:"Mark all as read",resolveAll:"Resolve all",resolvedAll:e=>e===1?"Resolved 1 comment":`Resolved ${e} comments`,screenshots:"Attach screenshots",position:(e,t)=>`${e} of ${t}`,poweredBy:"Powered by Spacefast"},thread:{resolve:"Resolve",resolved:"Resolved",reopen:"Reopen",reopened:"Reopened",copyLink:"Copy link",linkCopied:"Link copied",undo:"Undo",commentPosted:"Comment posted",replyPosted:"Reply posted",openOnPage:"Open on the page",close:"Close",more:"More",cancel:"Cancel"},pins:{list:"Comments on the page",unread:"unread"},time:{now:"just now"},error:{sendFailed:"Couldn't send that. Try again.",anonymousOff:"Sign in to comment here.",readOnly:"You can read comments here, but not add them.",disabled:"Comments are off for this Space."},timeline:{open:"Timeline",title:"Timeline",close:"Close",today:"Today",yesterday:"Yesterday",more:"Show more",loading:"Loading\u2026",published:e=>`${e} went live`,empty:"Quiet so far. Comments and publishes will show up here.",failed:"Couldn't load the timeline."},element:{connecting:"Connecting\u2026",threadGone:"That conversation isn't here.",threadUnaddressed:"No conversation picked yet.",presenceHere:e=>e.length===0?"Nobody else here":`Here now: ${e.join(", ")}`}};var pe=["https://gravatar.com","https://i0.wp.com"],De=pe[0],F=pe[1],B=2048;function Ue(e){let t=e.trim();if(!t||t.length>B)return null;try{let a=new URL(t);if(a.protocol!=="https:"&&a.protocol!=="http:"||a.username!==""||a.password!=="")return null;if(a.origin===De||a.origin===F)return a.href.length<=B?a.href:null;let o=new URL(F);return o.pathname=`/${a.host}${a.pathname}`,o.search=a.search,a.protocol==="https:"&&o.searchParams.set("ssl","1"),o.protocol!=="https:"||o.origin!==F?null:o.href.length<=B?o.href:null}catch{return null}}function Ve(e,t=()=>!1){let a=new Map;for(let o of e.values()){if(t(o))continue;let r=o.actor_id||o.session_id,n=a.get(r);(!n||o.last_seen>n.last_seen)&&a.set(r,o)}return[...a.values()]}function he(e){let t=e.session?.actor_id;return Ve(e.roster,a=>a.is_self||t!==void 0&&a.actor_id===t)}function Ie(e){return e.config&&!M(e.config)?y.error.disabled:e.session&&!e.session.capabilities.comments_create?y.error.readOnly:null}function W(e){let t=Ie(e);return t?{state:"blocked",reason:t}:e.connection!=="connected"?{state:"reconnecting"}:{state:"ready"}}function be(e){let t=0;for(let a of e.threads.values())a.status==="open"&&a.unread&&(t+=1);return t}function me(e){for(let t of e.threads.values())if(t.status==="open"&&t.unread&&t.mentioned)return!0;return!1}function G(e){return e.config?.artifact.id??null}var ge=C.looseObject({source:C.looseObject({version_id:C.string().optional(),canonical_path:C.string().optional()}).optional()});function X(e){return ge.safeParse(e).data?.source?.version_id??null}function xe(e){return ge.safeParse(e.anchor).data?.source?.canonical_path??null}function yt(e,t){let a=e?.resolve;if(a?.status!=="attached")return;(a.target.kind==="element"?a.target.element:a.target.range.startContainer.parentElement??null)?.scrollIntoView?.({block:"center",behavior:t?"smooth":"auto"})}function kt(e){let t=G(e);return t?[...e.threads.values()].filter(a=>{let o=X(a.anchor);return a.status==="open"&&o!=null&&o!==t}):[]}function Ct(e){return new Intl.Segmenter(void 0,{granularity:"grapheme"}).segment(e||"?").containing(0)?.segment?.toUpperCase()??"?"}function ze(e,t="Guest"){return e?e.profile.name?e.profile.name:e.display_name&&e.display_name!=="Anonymous"?e.display_name:e.anonymous_id?q(e.anonymous_id).name:t:t}function qe(e){return e?.profile.color?te(e.profile.color):e?.anonymous_id?q(e.anonymous_id).color:"#687076"}function He(e){return[e?.profile.avatar_url,e?.profile.image_url,e?.profile.avatar].find(t=>typeof t=="string"&&t.trim()!=="")?.trim()}function K(e){return[e?.id??"",ze(e),qe(e),He(e)??""].join(",")}function St(e,t=Date.now()){let a=Date.parse(e);if(Number.isNaN(a))return"";let o=Math.max(0,Math.round((t-a)/1e3));if(o<45)return y.time.now;let r=Math.round(o/60);if(r<60)return`${r}m`;let n=Math.round(r/60);if(n<24)return`${n}h`;let s=Math.round(n/24);return s<7?`${s}d`:new Date(a).toLocaleDateString(void 0,{month:"short",day:"numeric"})}var Ye=/[\\?#\x00-\x1f\x7f]/;function Fe(e){let t=H.safeParse(e);if(!t.success)return null;let a=t.data;if(a===""||a[0]!=="/"||a.startsWith("//")||Ye.test(a))return null;let o=[];for(let n of a.split("/")){let s=n.normalize("NFC");if(!(s===""||s===".")){if(s===".."){if(o.length===0)return null;o.pop();continue}o.push(s)}}let r=o.length===0?"/":`/${o.join("/")}`;return H.safeParse(r).success?r:null}function ve(e){let t=Fe(e);if(t===null)return null;if(t==="/")return"/";try{return`/${t.slice(1).split("/").map(a=>encodeURIComponent(a)).join("/")}`}catch{return null}}var we=/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i,Be=/^cmt_([0-9a-f]{8})([0-9a-f]{4})([0-9a-f]{4})([0-9a-f]{4})([0-9a-f]{12})$/i;function U(e){return we.test(e)?`cmt_${e.replaceAll("-","").toLowerCase()}`:e}function _t(e){if(we.test(e))return e.toLowerCase();let t=Be.exec(e);return t?t.slice(1).join("-").toLowerCase():null}var We=50;function Ge(e){return new URL(Z,new URL(e.sourceUrl).origin)}function Xe(e){if(!Array.isArray(e))return[];let t=[];for(let a of e){let o=a;typeof o?.id!="string"||typeof o.url!="string"||t.push({id:o.id,url:o.url,number:typeof o.number=="number"?o.number:null,createdAt:typeof o.createdAt=="string"?o.createdAt:null})}return t}var ye=new WeakMap;function V(e){let t=ye.get(e.store);if(t)return t;let a=s=>({sessionId:s,entries:new Map,inflight:new Map,denied:!1}),o=a(e.store.getState().session?.id??null),r=()=>{let s=e.store.getState().session?.id??null;return o.sessionId!==s&&(o=a(s)),o},n={get:s=>r().entries.get(s)??null,async ensure(s){let d=r(),{entries:b,inflight:u}=d;if(d.denied)return!1;let g=[...new Set(s.filter(Boolean))],x=g.filter(i=>!b.has(i)),m=x.filter(i=>!u.has(i)).slice(0,We);if(m.length>0){let i=(async()=>{let l=await fetch(Ge(e.manifest),{method:"POST",credentials:"same-origin",headers:{"content-type":"application/json"},body:JSON.stringify({pagePath:e.manifest.pagePath,ids:m})});if(d!==r())return;if(l.status===403||l.status===404){d.denied=!0;return}if(!l.ok)throw new Error(`version-urls request failed (${l.status})`);let p=await l.json();if(d===r())for(let h of Xe(p?.data))b.set(h.id,h)})().catch(()=>{}).finally(()=>{for(let l of m)u.delete(l)});for(let l of m)u.set(l,i)}let c=[];for(let i of g){let l=u.get(i);l&&c.push(l)}return await Promise.all(c),d===r()&&x.some(i=>b.has(i))}};return ye.set(e.store,n),n}function ke(e,t,a){let o=new URL(e.endsWith("/")?e:`${e}/`),r=ve(t===""?"/":t);if(!r)return null;let n=new URL(r,o);return n.origin!==o.origin||n.protocol!=="https:"&&n.protocol!=="http:"?null:(n.hash=`${N}=${encodeURIComponent(U(a))}`,n.href)}function Ce(e,t){return t?.number!=null?`v${t.number}`:e.length>12?`${e.slice(0,12)}\u2026`:e}function $t(e,t){return[...e].sort((a,o)=>{let r=t(a),n=t(o);return(r?.number??null)!==(n?.number??null)?r?.number==null?1:n?.number==null?-1:n.number-r.number:(n?.createdAt??"").localeCompare(r?.createdAt??"")||o.localeCompare(a)})}var J=25,Se={events:[],next:null,hasMore:!1,failed:!1};function R(e){return typeof e=="object"&&e!==null?e:null}function w(e){return typeof e=="string"&&e.length>0?e:null}function Ke(e){let t=e&&w(e.id);if(!e||!t)return null;let a=R(e.profile)??{},o=w(a.name),r=w(a.color),n=w(a.avatar_url);return{id:t,kind:e.kind==="user"?"user":"anonymous",display_name:w(e.display_name)??"",profile:{...o?{name:o}:{},...r?{color:r}:{},...n?{avatar_url:n}:{}}}}function Je(e){let t=R(R(e)?.data),a=Array.isArray(t?.events)?t.events:[],o=R(t?.cursor),r=[];for(let n of a){let s=R(n),d=s&&w(s.id),b=s&&w(s.kind),u=s&&w(s.inserted_at);!s||!d||!b||!u||r.push({id:d,kind:b,thread_id:w(s.thread_id),room_key:w(s.room_key),path:w(s.path),version_id:w(s.version_id),actor:Ke(R(s.actor)),snippet:w(s.snippet),inserted_at:u})}return{events:r,next:o?w(o.next):null,hasMore:o?.has_more===!0,failed:!1}}function Nt(e){return e?{id:e.id,kind:e.kind,external_id:null,anonymous_id:null,display_name:e.display_name,profile:e.profile}:null}async function Te(e){let t=e.apiBase.endsWith("/")?e.apiBase:`${e.apiBase}/`,a=new URL(`api/resources/${encodeURIComponent(e.resourceKey)}/timeline`,t);a.searchParams.set("limit",String(e.limit??J)),e.cursor&&a.searchParams.set("cursor",e.cursor);try{let o=await fetch(a,{credentials:"omit",headers:e.token?{authorization:`Bearer ${e.token}`}:{}});return o.ok?Je(await o.json()):{...Se,failed:o.status!==404&&o.status!==501}}catch{return{...Se,failed:!0}}}var S=j(ce(),1);function Pe(e){let t=new AbortController,a=[],o=new Set,r=u=>{if(t.signal.aborted){u();return}a.push(u)},n=globalThis.matchMedia(`(max-width: ${D-1}px)`),s=globalThis.matchMedia("(prefers-reduced-motion: reduce)"),d=()=>{for(let u of o)u()};return n.addEventListener("change",d),s.addEventListener("change",d),{ctx:{store:e.store,manifest:e.manifest,root:e.root,layer:u=>ie(e.root,u),onCleanup:r,subscribe:(u,g)=>{t.signal.aborted||r(e.store.subscribe(u,g))},signal:t.signal,isReduced:()=>s.matches,isMobile:()=>n.matches,onMediaChange:u=>{t.signal.aborted||(o.add(u),r(()=>o.delete(u)))},isWidgetNode:u=>u===e.host||e.host.contains(u),captureSource:()=>ee(e.manifest),anchorRoot:()=>document.body??document.documentElement,getToken:()=>e.getToken?.()??null,sendEphemeral:(u,g)=>e.sendEphemeral?.(u,g)??Promise.resolve({})},destroy(){t.abort(),n.removeEventListener("change",d),s.removeEventListener("change",d),o.clear();for(let u of a.splice(0))u()}}}var Ae=`
/* ================= the layer ================= */
.sf-root { position: fixed; inset: 0; pointer-events: none; z-index: 30; contain: layout style; }
.sf-root > * { pointer-events: auto; }
/* pass-through boxes; only what is inside them is hit-tested (or never, for cursors) */
.sf-pins-host { position: absolute; inset: 0; pointer-events: none; }
.sf-cursor-layer { position: absolute; inset: 0; pointer-events: none; }
/* Drawings hang from their pins (react/sketches.tsx): placed by their spot, and
   stepping aside with them. */
.sf-sketches { position: absolute; inset: 0; pointer-events: none; }
.sf-sketch {
  position: absolute; left: 0; top: 0; width: 1px; height: 1px; overflow: visible;
  transition: opacity var(--sf-collab-dur-2) var(--sf-collab-ease-out);
}
.sf-sketch[data-hushed="1"] { opacity: 0; transition-timing-function: var(--sf-collab-ease-in); }
/* Pointed at, not opened: a preview of what opening the pin will show. */
.sf-sketch[data-peek="1"] { opacity: 0.55; }

/* ================= ORB ================= */
/* The orb is an accent disc that stays where it is. Opened, its tools come out
   of it as a column of round buttons, on every viewport. The faces of who is
   here float beside it. */
.sf-orb-dock {
  position: fixed; display: flex; align-items: center;
  height: 40px; padding: 0;
  border-radius: var(--sf-collab-radius-full);
  pointer-events: auto;
  background: var(--sf-collab-accent);
  box-shadow: var(--sf-collab-shadow-sm), inset 0 0 0 1px var(--sf-collab-accent-soft);
  /* The Sidebar's edge pushes the dock along: same curve, same duration. A drag
     sets its position every frame and must not lag. */
  transition: left var(--sf-collab-dur-sheet) var(--sf-collab-ease-sheet),
              right var(--sf-collab-dur-sheet) var(--sf-collab-ease-sheet),
              background-color var(--sf-collab-dur-2) var(--sf-collab-ease-out),
              box-shadow var(--sf-collab-dur-2) var(--sf-collab-ease-out),
              padding var(--sf-collab-dur-2) var(--sf-collab-ease-out),
              transform var(--sf-collab-dur-1) var(--sf-collab-ease-out);
  animation: sf-orb-in var(--sf-collab-dur-3) var(--sf-collab-ease-out);
}
.sf-orb-dock[data-drag="1"] { transition: none; }
.sf-orb-dock[data-drag="1"], .sf-orb-dock[data-drag="1"] * { cursor: grabbing; }
.sf-orb-dock[data-corner$="left"] { flex-direction: row; }
.sf-orb-dock[data-corner$="right"] { flex-direction: row-reverse; }
/* only the orb itself wakes the disc: the faces beside it are not the orb */
.sf-orb-dock:not([data-expanded="1"]):not([data-drag="1"]):has(> .sf-orb:hover) { transform: scale(1.05); }
/* The orb: the comment glyph in the ink that reads on the accent \u2014 the same
   thing the runtime's placeholder paints, so the boot swaps nothing. */
.sf-orb {
  position: relative; flex: none;
  height: 40px; min-width: 40px;
  padding: 0; border-radius: var(--sf-collab-radius-full);
  pointer-events: auto;
  background: none;
  display: flex; align-items: center; justify-content: center; gap: 6px;
  color: var(--sf-collab-accent-ink);
  cursor: grab;
  touch-action: none;
  transition: height var(--sf-collab-dur-2) var(--sf-collab-ease-out),
              min-width var(--sf-collab-dur-2) var(--sf-collab-ease-out),
              color var(--sf-collab-dur-2) var(--sf-collab-ease-out),
              background-color var(--sf-collab-dur-1) var(--sf-collab-ease-out);
}
.sf-orb[data-drag="1"] { cursor: grabbing; transition: none; }
/* A tool in hand: the orb is the one control left, and it reads Done. */
.sf-orb-dock[data-run] .sf-orb { padding: 0 16px 0 12px; }
.sf-orb-done {
  display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;
  font: var(--sf-collab-text-sm); font-weight: 600;
}
.sf-orb-mark { display: grid; place-items: center; color: inherit; width: 20px; }
.sf-orb-mark .sf-ico {
  display: block;
  transition: transform var(--sf-collab-dur-3) var(--sf-collab-ease-out);
}
/* The glyph is the bubble at rest and the cross when out. One stroke is
   unwound while the other is drawn, and the pair makes a quarter turn \u2014 so it
   reads as one mark changing, not two swapping. */
.sf-orb-glyph {
  stroke-dasharray: 1; stroke-dashoffset: 0; opacity: 1;
  transition: stroke-dashoffset var(--sf-collab-dur-3) var(--sf-collab-ease-out),
              opacity var(--sf-collab-dur-1) var(--sf-collab-ease-out);
}
.sf-orb-glyph[data-glyph="close"] { stroke-dashoffset: 1; opacity: 0; }
.sf-orb[aria-expanded="true"] .sf-ico { transform: rotate(90deg); }
.sf-orb[aria-expanded="true"] .sf-orb-glyph[data-glyph="comment"] { stroke-dashoffset: 1; opacity: 0; }
.sf-orb[aria-expanded="true"] .sf-orb-glyph[data-glyph="close"] { stroke-dashoffset: 0; opacity: 1; }
/* the one ring: an accent hairline that brightens on wake */
.sf-orb::after {
  content: ""; position: absolute; inset: 0; border-radius: inherit;
  box-shadow: inset 0 0 0 1px var(--sf-collab-accent-soft);
  pointer-events: none;
}
.sf-orb:hover::after, .sf-orb:focus-visible::after { box-shadow: inset 0 0 0 1px var(--sf-collab-accent); }
/* Signed in: your own face on the ring's lower-left, clear of the connection dot. */
.sf-orb-self {
  position: absolute; bottom: -4px; left: -4px;
  display: grid; border-radius: var(--sf-collab-radius-full);
  box-shadow: 0 0 0 2px var(--sf-collab-surface-solid);
  pointer-events: none;
}
/* connection: a 6px dot on the ring, only when NOT connected. Connected is silent. */
.sf-orb-conn {
  position: absolute; bottom: -1px; right: -1px;
  width: 6px; height: 6px; border-radius: var(--sf-collab-radius-full);
  background: var(--sf-collab-text-faint);
  box-shadow: 0 0 0 1.5px var(--sf-collab-surface-solid);
}
.sf-orb-conn[data-conn="connecting"], .sf-orb-conn[data-conn="reconnecting"] {
  background: var(--sf-collab-warn); animation: sf-pulse 1.4s ease-in-out infinite;
}
/* Presence: the faces of who is here, floating beside the orb on the page's
   side with nothing around them. They ride the dock's page-inward end, so they
   follow the orb to whichever corner it is dragged to. */
.sf-presence {
  position: absolute; top: 50%; transform: translateY(-50%);
  display: flex; align-items: center; pointer-events: auto;
  height: 24px; padding: 0; border: 0; background: none;
  border-radius: var(--sf-collab-radius-full);
  transition: opacity var(--sf-collab-dur-1) var(--sf-collab-ease-out);
}
.sf-presence .sf-avatar { transition: transform var(--sf-collab-dur-1) var(--sf-collab-ease-out); }
.sf-presence:hover .sf-avatar, .sf-presence[aria-expanded="true"] .sf-avatar { transform: translateY(-2px); }
.sf-orb-dock[data-corner$="left"] > .sf-presence { left: calc(100% + 8px); }
.sf-orb-dock[data-corner$="right"] > .sf-presence { right: calc(100% + 8px); }
/* mid-drag the faces ride along on their springs (react/dock-physics.ts) */
.sf-orb-dock[data-drag="1"] > .sf-presence { pointer-events: none; }
.sf-presence-row { cursor: default; }
button.sf-presence-row { cursor: pointer; }
.sf-menu-sep { height: 1px; margin: 6px 4px; background: var(--sf-collab-border); }
/* A quiet line of help in a menu, above the setting it is about. */
.sf-menu-hint { margin: 4px 10px 2px; font: var(--sf-collab-text-xs); color: var(--sf-collab-text-muted); }
.sf-kbd {
  display: inline-block; min-width: 18px; padding: 0 5px; text-align: center;
  font: inherit; font-weight: 600; color: var(--sf-collab-text);
  border: 1px solid var(--sf-collab-border-strong); border-bottom-width: 2px;
  border-radius: var(--sf-collab-radius-sm);
}
/* A setting in a menu: what it does, and a switch that says whether it is on. */
.sf-switch-row { gap: 12px; justify-content: space-between; }
.sf-switch-text { min-width: 0; }
.sf-switch {
  position: relative; flex: none; width: 30px; height: 18px; border-radius: var(--sf-collab-radius-full);
  background: var(--sf-collab-border-strong);
  transition: background-color var(--sf-collab-dur-1) var(--sf-collab-ease-out);
}
.sf-switch::after {
  content: ""; position: absolute; top: 2px; left: 2px; width: 14px; height: 14px;
  border-radius: var(--sf-collab-radius-full); background: #fff;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.25);
  transition: transform var(--sf-collab-dur-2) var(--sf-collab-ease-out);
}
.sf-switch-row[aria-checked="true"] .sf-switch { background: var(--sf-collab-accent); }
.sf-switch-row[aria-checked="true"] .sf-switch::after { transform: translateX(12px); background: var(--sf-collab-accent-ink); }
.sf-faces { display: inline-flex; flex: none; }
/* A face has no border of its own. In a stack each one overlaps the one before
   it, and the one underneath has a bite taken out of it where its neighbour
   sits: the gap between them is the page showing through, not a painted ring
   that only matches one background. */
.sf-faces { --sf-face-overlap: 6px; --sf-face-gap: 2px; }
.sf-faces .sf-avatar { margin-left: calc(var(--sf-face-overlap) * -1); }
.sf-faces .sf-avatar:first-child { margin-left: 0; }
.sf-faces .sf-avatar:not(:last-child) {
  -webkit-mask-image: radial-gradient(
    circle calc(var(--sf-face) / 2 + var(--sf-face-gap)) at calc(var(--sf-face) * 1.5 - var(--sf-face-overlap)) 50%,
    transparent 97%, #000 100%
  );
  mask-image: radial-gradient(
    circle calc(var(--sf-face) / 2 + var(--sf-face-gap)) at calc(var(--sf-face) * 1.5 - var(--sf-face-overlap)) 50%,
    transparent 97%, #000 100%
  );
}
.sf-faces-more { font: var(--sf-collab-text-xs); color: var(--sf-collab-text-muted); margin-left: 4px; }

/* ================= TOOLS ================= */
/* Folded away, the tools are out of reach as well as out of sight: they stay
   mounted through a commenting run, and a control nobody can see must not be
   one the keyboard can still land on. */
.sf-rail {
  display: flex; align-items: center;
  visibility: hidden;
  transition: visibility 0s linear var(--sf-collab-dur-2);
}
.sf-orb-dock[data-expanded="1"] .sf-rail { visibility: visible; transition: visibility 0s; }
/* The open tools are a handle for the dock: their gaps say so. */
.sf-orb-dock[data-expanded="1"] .sf-rail { cursor: grab; }
.sf-railbtn {
  position: relative;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: var(--sf-collab-radius-full);
  color: var(--sf-collab-text);
}
.sf-railbtn[disabled] { color: var(--sf-collab-text-faint); cursor: default; }
.sf-railbtn .sf-ico { flex: none; }
.sf-railbtn-count {
  position: absolute; top: -4px; right: -4px;
  min-width: 16px; height: 16px; padding: 0 4px;
  border-radius: var(--sf-collab-radius-full);
  background: var(--sf-collab-accent); color: var(--sf-collab-accent-ink);
  box-shadow: 0 0 0 2px var(--sf-collab-surface-solid);
  font: var(--sf-collab-text-xs); font-weight: 600;
  display: grid; place-items: center;
}
/* which version this is, when it is not the live one */
.sf-rail-vchip {
  display: inline-flex; align-items: center; gap: 4px; flex: none;
  height: 22px; padding: 0 7px; border-radius: var(--sf-collab-radius-full);
  background: var(--sf-collab-surface-solid);
  font: var(--sf-collab-text-tag); color: var(--sf-collab-text-muted);
  white-space: nowrap; text-decoration: none;
}
a.sf-rail-vchip:hover { color: var(--sf-collab-text); }

/* ---- The stack: flat round buttons in a column off the
   orb, nearest first, above it \u2014 or below it, near a top edge. ---- */
.sf-orb-dock .sf-rail-slot {
  position: absolute; left: 50%; transform: translateX(-50%);
}
.sf-orb-dock[data-corner^="bottom"] .sf-rail-slot { bottom: calc(100% + 10px); }
.sf-orb-dock[data-corner^="top"] .sf-rail-slot { top: calc(100% + 10px); }
.sf-orb-dock .sf-rail { flex-direction: column-reverse; gap: 10px; }
.sf-orb-dock[data-corner^="top"] .sf-rail { flex-direction: column; }
.sf-orb-dock .sf-railbtn {
  --sf-stack-step: 0;
  flex: none; width: 36px; height: 36px;
  background: var(--sf-collab-control); color: var(--sf-collab-control-ink);
  /* Folded: tucked back into the orb, and small. */
  opacity: 0;
  transform: translateY(calc((var(--sf-stack-step) + 1) * 18px)) scale(0.4);
}
.sf-orb-dock[data-corner^="top"] .sf-railbtn {
  transform: translateY(calc((var(--sf-stack-step) + 1) * -18px)) scale(0.4);
}
.sf-orb-dock .sf-railbtn:nth-child(2) { --sf-stack-step: 1; }
.sf-orb-dock .sf-railbtn:nth-child(3) { --sf-stack-step: 2; }
.sf-orb-dock .sf-railbtn:nth-child(4) { --sf-stack-step: 3; }
.sf-orb-dock .sf-railbtn:nth-child(5) { --sf-stack-step: 4; }
.sf-orb-dock[data-expanded="1"] .sf-railbtn { opacity: 1; transform: none; }
.sf-orb-dock[data-expanded="1"] .sf-railbtn:hover:not([disabled]) { transform: scale(1.08); }
/* A tool that is on (an armed picker, an open drawer) turns inside out, with a
   rim in the control's own color so it still reads against a page of its ink. */
.sf-orb-dock .sf-railbtn[aria-pressed="true"] {
  background: var(--sf-collab-control-ink); color: var(--sf-collab-control);
  box-shadow: 0 0 0 2px var(--sf-collab-control);
}
.sf-orb-dock .sf-railbtn[disabled] {
  color: color-mix(in srgb, var(--sf-collab-control-ink) 40%, transparent);
}
.sf-orb-dock .sf-railbtn-count {
  background: var(--sf-collab-control-ink); color: var(--sf-collab-control);
  box-shadow: 0 0 0 2px var(--sf-collab-control);
}
.sf-orb-dock .sf-rail-vchip {
  background: var(--sf-collab-control); color: var(--sf-collab-control-ink); opacity: 0;
}
.sf-orb-dock[data-expanded="1"] .sf-rail-vchip { opacity: 1; }
/* The motion, where motion is wanted: out, each springs to its place a beat
   after the one below it; folded, they drop back into the orb together. Tools
   already open on the last page have no entrance (data-instant, react/orb.tsx). */
@media (prefers-reduced-motion: no-preference) {
  .sf-orb-dock:not([data-instant="1"]) .sf-railbtn {
    transition: transform var(--sf-collab-dur-2) var(--sf-collab-ease-in),
                opacity var(--sf-collab-dur-1) var(--sf-collab-ease-in),
                color var(--sf-collab-dur-1) var(--sf-collab-ease-out),
                background-color var(--sf-collab-dur-1) var(--sf-collab-ease-out);
  }
  .sf-orb-dock[data-expanded="1"]:not([data-instant="1"]) .sf-railbtn {
    transition: transform 320ms cubic-bezier(0.34, 1.56, 0.64, 1) calc(var(--sf-stack-step) * 45ms),
                opacity var(--sf-collab-dur-1) var(--sf-collab-ease-out) calc(var(--sf-stack-step) * 45ms),
                color var(--sf-collab-dur-1) var(--sf-collab-ease-out),
                background-color var(--sf-collab-dur-1) var(--sf-collab-ease-out);
  }
  .sf-orb-dock[data-expanded="1"] .sf-railbtn:hover:not([disabled]) { transition-delay: 0s; }
  .sf-orb-dock:not([data-instant="1"]) .sf-rail-vchip {
    transition: opacity var(--sf-collab-dur-1) var(--sf-collab-ease-in);
  }
  .sf-orb-dock[data-expanded="1"]:not([data-instant="1"]) .sf-rail-vchip {
    transition: opacity var(--sf-collab-dur-2) var(--sf-collab-ease-out) 180ms;
  }
}
.sf-orb-dock[data-instant="1"] :is(.sf-orb, .sf-ico, .sf-orb-glyph) { transition: none; }

/* ================= PANEL ================= */
.sf-sidebar {
  position: fixed; top: 0; right: 0; bottom: 0; width: ${se}px; max-width: 100vw;
  display: flex; flex-direction: column;
  background: var(--sf-collab-surface);
  backdrop-filter: var(--sf-collab-blur); -webkit-backdrop-filter: var(--sf-collab-blur);
  box-shadow: var(--sf-collab-shadow), inset 1px 0 0 var(--sf-collab-border);
  /* In from the edge it lives on and back out to it, on the curve the dock
     rides (react/sidebar.tsx keeps it mounted until it is all the way out). */
  animation: sf-sidebar-in var(--sf-collab-dur-sheet) var(--sf-collab-ease-sheet) both;
}
.sf-sidebar[data-leaving="1"] { animation-name: sf-sidebar-out; pointer-events: none; }
.sf-sidebar[data-variant="sheet"] {
  top: auto; left: 0; right: 0; width: auto; height: 70vh;
  border-radius: var(--sf-collab-radius-lg) var(--sf-collab-radius-lg) 0 0;
  padding-bottom: env(safe-area-inset-bottom, 0px);
  box-shadow: var(--sf-collab-shadow), inset 0 1px 0 var(--sf-collab-border);
  animation-name: sf-sheet-in;
}
.sf-sidebar[data-variant="sheet"][data-leaving="1"] { animation-name: sf-sheet-out; }
/* What is inside follows the glass a beat behind, so the slide reads as a
   surface arriving with its contents, not a picture of one. */
.sf-sidebar:not([data-leaving="1"]) > * {
  animation: sf-sidebar-content var(--sf-collab-dur-sheet) var(--sf-collab-ease-sheet) 40ms both;
}
.sf-sidebar-head { display: flex; align-items: center; gap: 8px; padding: 10px 10px 8px 14px; flex: none; }
.sf-sidebar-title { font: var(--sf-collab-text-lg); }
.sf-tabs { display: flex; gap: 2px; margin-right: auto; }
.sf-tab {
  height: 26px; padding: 0 10px; border-radius: var(--sf-collab-radius-full);
  font: var(--sf-collab-text-sm); color: var(--sf-collab-text-muted);
}
.sf-tab[aria-selected="true"] { background: var(--sf-collab-surface-raised); color: var(--sf-collab-text); }
/* Telemetry's range: a row of pressable choices. */
.sf-filter { display: flex; gap: 2px; padding: 0 12px 8px; flex: none; }
.sf-filter button {
  height: 24px; padding: 0 9px; border-radius: var(--sf-collab-radius-full);
  font: var(--sf-collab-text-xs); color: var(--sf-collab-text-muted);
  box-shadow: inset 0 0 0 1px var(--sf-collab-border);
}
.sf-filter button[aria-pressed="true"] {
  background: var(--sf-collab-accent-soft); color: var(--sf-collab-text);
  box-shadow: inset 0 0 0 1px var(--sf-collab-accent);
}
/* The page filter: says what the list shows, and opens to change it. */
.sf-filter-trigger {
  display: inline-flex; align-items: center; gap: 2px; height: 26px; padding: 0 6px 0 10px;
  border-radius: var(--sf-collab-radius-full);
  font: var(--sf-collab-text-sm); color: var(--sf-collab-text-muted);
}
.sf-filter-trigger:hover, .sf-filter-trigger[aria-expanded="true"] {
  background: var(--sf-collab-surface-raised); color: var(--sf-collab-text);
}
.sf-menu-tick { display: inline-flex; width: 14px; flex: none; }
/* Inside one comment: up and down the list, and where this one is in it. */
.sf-steps { display: flex; align-items: center; gap: 2px; margin-right: auto; }
.sf-steps .sf-iconbtn:disabled { color: var(--sf-collab-text-faint); cursor: default; }
.sf-steps-count { margin-left: 6px; font: var(--sf-collab-text-xs); color: var(--sf-collab-text-muted); font-variant-numeric: tabular-nums; }
/* Under the tabs: what the list below them shows. */
.sf-sidebar-bar { flex: none; display: flex; align-items: center; padding: 0 10px 6px 10px; }
.sf-sidebar-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; overscroll-behavior: contain; }
/* The wordmark's one home. Quiet enough to ignore, present enough to credit. */
.sf-sidebar-foot {
  flex: none; display: flex; align-items: center; justify-content: center; gap: 6px;
  padding: 8px 14px calc(8px + env(safe-area-inset-bottom, 0px));
  border-top: 1px solid var(--sf-collab-border);
  font: var(--sf-collab-text-xs); color: var(--sf-collab-text-faint);
}
.sf-sidebar-foot .sf-mark { display: block; }
.sf-foot-link {
  display: inline-flex; align-items: center; gap: 6px; color: inherit; text-decoration: none;
  border-radius: var(--sf-collab-radius-sm);
  transition: color var(--sf-collab-dur-1) var(--sf-collab-ease-out);
}
.sf-foot-link:hover { color: var(--sf-collab-text-muted); }
.sf-sidebar-body[data-thread="1"] { padding: 0 14px 14px; }

/* thread rows */
.sf-group-label {
  padding: 10px 14px 4px; font: var(--sf-collab-text-xs); font-weight: 600;
  text-transform: uppercase; letter-spacing: 0.06em; color: var(--sf-collab-text-muted);
}
.sf-row {
  display: flex; align-items: flex-start; gap: 8px; width: 100%;
  padding: 8px 14px; text-align: left; color: inherit; text-decoration: none;
}
.sf-row:hover { background: var(--sf-collab-surface-raised); }
/* A row's screenshot, beside the Sidebar while the row is pointed at. */
.sf-row-peek {
  position: fixed; z-index: 2147483001; pointer-events: none; padding: 6px;
  border-radius: var(--sf-collab-radius-md); ${_}
  box-shadow: var(--sf-collab-shadow), inset 0 0 0 1px var(--sf-collab-border);
  animation: sf-card-in var(--sf-collab-dur-2) var(--sf-collab-ease-out);
}
.sf-row-peek-shot { position: relative; display: block; border-radius: var(--sf-collab-radius-sm); overflow: hidden; background: #fff; }
.sf-row-peek-shot img { display: block; width: 100%; height: auto; }
.sf-row[aria-current="true"] { background: var(--sf-collab-accent-soft); }
.sf-row-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
/* one line: a long first line ends in an ellipsis before the unread dot */
.sf-row-line {
  display: block; font: var(--sf-collab-text-md);
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.sf-row-meta {
  display: flex; align-items: center; gap: 6px; margin-top: 2px;
  font: var(--sf-collab-text-xs); color: var(--sf-collab-text-muted);
}
.sf-row-meta .sf-ico { color: var(--sf-collab-text-faint); }
/* a thread with no pin: a page comment reads as a plain fact, an orphan as a warning */
.sf-row-loose { color: var(--sf-collab-text-muted); }
.sf-row-loose[data-orphan="1"] { color: var(--sf-collab-warn); }
.sf-row-unread {
  width: 7px; height: 7px; margin-top: 6px; flex: none;
  border-radius: var(--sf-collab-radius-full); background: var(--sf-collab-accent);
}
.sf-empty { padding: 28px 20px; text-align: center; font: var(--sf-collab-text-md); color: var(--sf-collab-text-muted); }

/* activity lane */
.sf-tl-day {
  padding: 10px 14px 4px; font: var(--sf-collab-text-xs); font-weight: 600;
  text-transform: uppercase; letter-spacing: 0.06em; color: var(--sf-collab-text-muted);
}
.sf-tl-row {
  display: flex; align-items: center; gap: 8px; width: 100%;
  padding: 6px 14px; color: inherit; text-align: left; text-decoration: none;
}
.sf-tl-row:hover { background: var(--sf-collab-surface-raised); }
.sf-tl-line { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font: var(--sf-collab-text-md); }
.sf-tl-who { font-weight: 600; }
.sf-tl-snip { color: var(--sf-collab-text-muted); }
/* A publish is a beat between events, not an event with a face. */
.sf-tl-divider {
  display: flex; align-items: center; gap: 8px; padding: 10px 14px;
  font: var(--sf-collab-text-xs); color: var(--sf-collab-text-faint); white-space: nowrap;
}
.sf-tl-divider::before, .sf-tl-divider::after { content: ""; flex: 1; height: 1px; background: var(--sf-collab-border); }
.sf-tl-more { width: 100%; padding: 10px; font: var(--sf-collab-text-sm); color: var(--sf-collab-accent); text-align: center; }
.sf-tl-more[disabled] { color: var(--sf-collab-text-faint); cursor: default; }

/* ================= TELEMETRY HUD ================= */
/* One frame, a stack of small views. Marks carry the chart palette's hue;
   every label wears text ink. A section the source cannot fill is a
   dashed slot, so a gap never passes for a zero. */
.sf-hud { padding-bottom: 8px; }
.sf-hud-tabs { display: flex; justify-content: flex-end; padding: 0 12px 8px; }
.sf-sidebar-slot { margin-left: auto; }
/* One control of joined segments: the ends are round, and each shares an edge with the next. */
.sf-segmented { gap: 0; padding: 0; }
.sf-segmented button { border-radius: 0; margin-left: -1px; }
.sf-segmented button:first-child { margin-left: 0; border-radius: var(--sf-collab-radius-full) 0 0 var(--sf-collab-radius-full); }
.sf-segmented button:last-child { border-radius: 0 var(--sf-collab-radius-full) var(--sf-collab-radius-full) 0; }
.sf-segmented button[aria-pressed="true"] { position: relative; z-index: 1; }
/* <ua-view> paints with its own tokens. */
.sf-hud, .sf-trends {
  /* What a chart is set on is the drawer's: its ink, its rules and its surface. */
  --ua-charts-color-label: var(--sf-collab-text);
  --ua-charts-color-label-secondary: color-mix(in srgb, var(--sf-collab-text) 70%, transparent);
  --ua-charts-color-grid: var(--sf-collab-border);
  --ua-charts-color-axis-x: var(--sf-collab-border-strong);
  --ua-charts-color-background: var(--sf-collab-surface-solid);
  --ua-charts-color-focus: var(--sf-collab-focus);
  /* What it draws with is the chart grammar's own palette, as its tokens resolve, light then dark:
     the series, the ten steps of a grid of cells, a change for better or worse, and the readout. */
  --ua-charts-color-label-inverse: #000000;
  --ua-charts-color-tooltip-surface: #d8f24b;
  --ua-charts-color-tooltip-surface-secondary: #ffffff;
  --ua-charts-color-label-screen: light-dark(#121407, #e3fb67);
  --ua-charts-color-series-1: light-dark(#689d00, #d8f24b);
  --ua-charts-color-series-1-text: light-dark(#4b750d, #d8f24b);
  --ua-charts-color-series-1-soft: light-dark(#c0e22b, #212e11);
  --ua-charts-color-series-2: light-dark(#00a275, #45d289);
  --ua-charts-color-series-2-text: light-dark(#007763, #45d289);
  --ua-charts-color-series-2-soft: light-dark(#8de7aa, #0c2f2b);
  --ua-charts-color-series-3: light-dark(#009f99, #00a09a);
  --ua-charts-color-series-3-text: light-dark(#00757e, #00a09a);
  --ua-charts-color-series-3-soft: light-dark(#aedce2, #032e3b);
  --ua-charts-color-series-4: light-dark(#395c85, #3d6089);
  --ua-charts-color-series-4-text: light-dark(#395c85, #5483bb);
  --ua-charts-color-series-4-soft: light-dark(#bcd7ff, #15157f);
  --ua-charts-color-series-5: #f5006b;
  --ua-charts-color-series-5-text: light-dark(#cd0c64, #f81b6f);
  --ua-charts-color-series-5-soft: light-dark(#ffc5d3, #550629);
  --ua-charts-color-series-6: light-dark(#f8580a, #ff701e);
  --ua-charts-color-series-6-text: light-dark(#c4312a, #ff701e);
  --ua-charts-color-series-6-soft: light-dark(#ffc9a5, #590020);
  --ua-charts-color-seq-1: light-dark(#cbed00, #3c3764);
  --ua-charts-color-seq-2: light-dark(#80e955, #3f5183);
  --ua-charts-color-seq-3: light-dark(#22de7e, #336c9b);
  --ua-charts-color-seq-4: light-dark(#00c99d, #1186a9);
  --ua-charts-color-seq-5: light-dark(#00b3ab, #009dae);
  --ua-charts-color-seq-6: light-dark(#009dae, #00b3ab);
  --ua-charts-color-seq-7: light-dark(#1186a9, #00c99d);
  --ua-charts-color-seq-8: light-dark(#336c9b, #22de7e);
  --ua-charts-color-seq-9: light-dark(#3f5183, #80e955);
  --ua-charts-color-seq-10: light-dark(#3c3764, #cbed00);
  --ua-charts-color-seq-none: light-dark(#e6e4dd, #2c2924);
  --ua-charts-color-annotation: light-dark(#6e695d, #a5a095);
  --ua-charts-color-comparison: light-dark(#6e695d, #a5a095);
  --ua-charts-color-delta-good: light-dark(#2e7829, #7cda74);
  --ua-charts-color-delta-bad: light-dark(#d50000, #fa2610);
  --ua-charts-color-trend-neutral: light-dark(#6e695d, #a5a095);
  --ua-charts-color-live: light-dark(#646f16, #e3fb67);
  --ua-charts-color-trend-up: light-dark(#2e7829, #7cda74);
  --ua-charts-color-trend-down: light-dark(#d50000, #fa2610);
  --ua-charts-border-radius-tooltip: 0px;
  --ua-charts-font-family-tick: "Ans Mono", "Ans Mono Scroll", ui-monospace, monospace;
  --ua-charts-font-family-value: "Ans Mono", "Ans Mono Scroll", ui-monospace, monospace;
  --ua-charts-font-size-tick: 8px;
  --ua-charts-font-size-value: 12px;
  --ua-charts-motion-duration-entrance: var(--sf-collab-dur-2);
  --ua-charts-motion-duration-update: var(--sf-collab-dur-3);
  --ua-charts-motion-duration-exit: var(--sf-collab-dur-1);
}
.sf-hud-chart { padding: 0 14px 6px; min-width: 0; }
.sf-hud-chart .ua-row { height: 64px; }
.sf-hud-more { padding: 0 14px 6px; }
.sf-trends {
  display: flex; flex-direction: column; gap: 24px; padding: 0 12px 12px;
  transition: opacity var(--sf-collab-dur-2) var(--sf-collab-ease-out);
}
.sf-trends[aria-busy="true"] { opacity: 0.5; }
/* Three numbers across, close set: each wants room for five characters at the number's size. */
.sf-trends-tiles { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 4px; }
.sf-trends-tiles .ua-row { height: 72px; }
.sf-trends-card { min-width: 0; }
.sf-trends-tile { min-width: 0; }
.sf-trends-label { padding-bottom: 2px; font: var(--sf-collab-text-xs); font-weight: 600; color: var(--sf-collab-text); }
/* A view's title in the drawer is sentence case, as its charts' own words are. */
.sf-hud .sf-group-label { text-transform: none; letter-spacing: 0; color: var(--sf-collab-text); }
.sf-hud-hero { display: flex; flex-wrap: wrap; gap: 8px 16px; padding: 6px 14px 4px; }
.sf-hud-stat { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1 1 140px; }
.sf-hud-stat .ua-row { height: 48px; }
.sf-hud-stat-label { font: var(--sf-collab-text-xs); font-weight: 600; color: var(--sf-collab-text); }
.sf-hud-stat-sub { font: var(--sf-collab-text-xs); color: var(--sf-collab-text-muted); }
.sf-hud-stat { position: relative; }
.sf-hud-tipped {
  display: inline-flex; align-items: center; gap: 4px;
  align-self: flex-start; padding: 0; border: 0; background: none; cursor: help;
}
.sf-hud-tipped .sf-ico { color: var(--sf-collab-text-muted); }
.sf-hud-tipped:focus-visible { outline: 2px solid var(--sf-collab-focus); outline-offset: 2px; border-radius: 2px; }
.sf-hud-tip {
  position: absolute; z-index: 2; top: 20px; left: 0; display: none; flex-direction: column; gap: 4px;
  width: max-content; max-width: 260px; padding: 8px 10px; border-radius: var(--sf-collab-radius-sm);
  background: var(--sf-collab-surface-solid); box-shadow: inset 0 0 0 1px var(--sf-collab-border), var(--sf-collab-shadow-sm);
}
.sf-hud-tipped:hover + .sf-hud-tip, .sf-hud-tipped:focus-visible + .sf-hud-tip { display: flex; }
/* The second stat is at the drawer's edge, so its tip opens back toward the middle. */
.sf-hud-stat[data-stat="engaged"] .sf-hud-tip { left: auto; right: 0; }
.sf-hud-consent { font: var(--sf-collab-text-xs); color: var(--sf-collab-text-muted); }
.sf-hud-consent[data-undecided="1"] { color: var(--sf-collab-warn); }
.sf-hud-section { padding-bottom: 4px; }
/* With no title over it, a section keeps the room one would have left above it. */
.sf-hud-section[data-untitled="1"] { padding-top: 10px; }
.sf-hud-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 0 8px; }
.sf-hud-blank {
  margin: 0 14px 6px; padding: 10px 12px;
  border-radius: var(--sf-collab-radius-sm);
  font: var(--sf-collab-text-xs); color: var(--sf-collab-text-muted);
  background: var(--sf-collab-surface-raised);
}
.sf-hud-blank[data-slot="1"] { background: none; border: 1px dashed var(--sf-collab-border-strong); color: var(--sf-collab-text-faint); }
.sf-hud-list { display: flex; flex-direction: column; gap: 6px; padding: 0 14px 6px; }
.sf-hud-page-line { display: flex; align-items: center; gap: 6px; min-width: 0; }
.sf-hud-page-line .sf-vchip { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; }
.sf-hud-moment .sf-hud-meta { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sf-hud-count { margin-left: auto; font: var(--sf-collab-text-sm); font-variant-numeric: tabular-nums; }
.sf-hud-meta { font: var(--sf-collab-text-xs); color: var(--sf-collab-text-muted); }
.sf-hud-seat[data-self="1"] .sf-tl-who { color: var(--sf-collab-accent); }
.sf-hud-dot { width: 8px; height: 8px; margin: 0 6px; border-radius: var(--sf-collab-radius-full); background: var(--sf-collab-text-muted); flex: none; }
.sf-hud-dot[data-consent="undecided"] { background: none; box-shadow: inset 0 0 0 1.5px var(--sf-collab-text-faint); }
.sf-hud-foot { padding: 8px 14px 0; font: var(--sf-collab-text-xs); color: var(--sf-collab-text-faint); }
.sf-hud-foot[data-warn="1"], .sf-hud[data-stale="1"] .sf-hud-foot { color: var(--sf-collab-warn); }

/* ================= THREAD ================= */
.sf-thread { position: relative; display: flex; flex-direction: column; min-width: 0; }
/* The wrapper owns the position and the card owns the box the placement solver
   measures: a fixed child inside this glass measures 0x0. */
.sf-floating { position: fixed; width: 320px; max-width: calc(100vw - 32px); }
/* As tall as the viewport allows, inside the solver's 16px margins: the
   replies are the point of opening a thread, not a scroll below the fold. */
.sf-thread[data-variant="floating"] {
  position: relative; width: 100%; max-height: min(760px, calc(100vh - 32px));
  border-radius: var(--sf-collab-radius-lg);
  ${_}
  box-shadow: var(--sf-collab-shadow), inset 0 0 0 1px var(--sf-collab-border);
  overflow: hidden;
  animation: sf-card-in var(--sf-collab-dur-3) var(--sf-collab-ease-out);
}
.sf-thread[data-variant="panel"] { padding-top: 8px; }
.sf-head { display: flex; align-items: center; gap: 8px; padding: 10px 12px; }
.sf-thread[data-variant="panel"] > .sf-head { padding: 0 0 8px; }
.sf-time { font: var(--sf-collab-text-xs); color: var(--sf-collab-text-muted); flex: none; }
.sf-spacer { margin-left: auto; }
.sf-iconbtn {
  width: 28px; height: 28px; border-radius: var(--sf-collab-radius-sm); flex: none;
  display: grid; place-items: center; color: var(--sf-collab-text-muted);
}
.sf-iconbtn:hover { background: var(--sf-collab-surface-raised); color: var(--sf-collab-text); }
.sf-ico { display: block; }
.sf-guest-badge {
  border: 1px solid var(--sf-collab-border); border-radius: var(--sf-collab-radius-full);
  color: var(--sf-collab-text-muted); font: var(--sf-collab-text-xs); padding: 1px 5px;
}
.sf-statuspill {
  display: inline-flex; align-items: center; padding: 1px 7px; flex: none;
  border-radius: var(--sf-collab-radius-full);
  box-shadow: inset 0 0 0 1px var(--sf-collab-border);
  background: var(--sf-collab-surface-raised);
  font: var(--sf-collab-text-xs); color: var(--sf-collab-text-muted);
}
.sf-statuspill[data-tone="spam"] { color: var(--sf-collab-danger); box-shadow: inset 0 0 0 1px var(--sf-collab-danger); }
.sf-vchip, .sf-tagchip {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 6px; border-radius: var(--sf-collab-radius-sm);
  background: var(--sf-collab-surface-raised); box-shadow: inset 0 0 0 1px var(--sf-collab-border);
  font: var(--sf-collab-text-tag); color: var(--sf-collab-text-muted); flex: none;
  text-decoration: none;
}
a.sf-vchip:hover, .sf-vchip[data-link="1"]:hover { color: var(--sf-collab-text); }

/* What the comment is about. The card's top line names it; this is the rest \u2014
   the quote, the selector, the screenshot \u2014 set plainly under that line. No
   rule down its side: it is the card's subject, not an aside in it. */
.sf-subject { display: inline-flex; align-items: center; gap: 6px; min-width: 0; overflow: hidden; color: var(--sf-collab-text-muted); }
/* An element is named by its words now, which can be long: the chip gives way
   to the card's own buttons and ends in an ellipsis rather than running under them. */
.sf-subject .sf-tagchip { display: block; flex: 0 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sf-subject .sf-ico { flex: none; }
.sf-subject-text { font: var(--sf-collab-text-sm); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sf-subject[data-orphan="1"] { color: var(--sf-collab-warn); }
.sf-about { display: flex; flex-direction: column; gap: 8px; padding: 0 12px 10px; }
.sf-thread[data-variant="panel"] .sf-about { padding: 0 0 12px; }
.sf-about[data-orphan="1"] {
  margin: 0 12px 10px; padding: 10px; border-radius: var(--sf-collab-radius-md);
  background: color-mix(in srgb, var(--sf-collab-warn) 12%, transparent);
}
.sf-thread[data-variant="panel"] .sf-about[data-orphan="1"] { margin: 0 0 12px; }
.sf-about-note { font: var(--sf-collab-text-xs); color: var(--sf-collab-text-muted); display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.sf-quote { font: var(--sf-collab-text-md); font-style: italic; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
.sf-quote-ctx { font-style: normal; color: var(--sf-collab-text-muted); }
.sf-offpage { border: 1px solid var(--sf-collab-warn); border-radius: var(--sf-collab-radius-full); color: var(--sf-collab-warn); font: var(--sf-collab-text-xs); padding: 0 6px; }
.sf-selchip {
  display: flex; align-items: center; gap: 4px; max-width: 100%;
  background: var(--sf-collab-surface-raised); border-radius: var(--sf-collab-radius-sm);
  box-shadow: inset 0 0 0 1px var(--sf-collab-border); padding: 2px 2px 2px 6px;
}
.sf-selchip-text { font: var(--sf-collab-text-tag); color: var(--sf-collab-text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sf-selchip .sf-iconbtn { width: 22px; height: 22px; }
.sf-shot { position: relative; height: 96px; border-radius: var(--sf-collab-radius-sm); overflow: hidden; cursor: zoom-in; background: #fff; }
.sf-shot-frame { position: absolute; inset: 0; }
.sf-shot img { display: block; width: 100%; height: 100%; object-fit: cover; }
/* A framed shot already has the capture's page aspect; fill keeps the PNG's
   rounded pixel height from nudging it off the outline the way cover would. */
.sf-shot-frame[data-framed="1"] img { object-fit: fill; }
.sf-shot-crop { position: absolute; inset: 0; overflow: hidden; pointer-events: none; border-radius: inherit; }
.sf-shot-crop-box { position: absolute; border: 2px solid var(--sf-collab-accent); border-radius: 2px; box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.4); }

/* The subject and the conversation scroll as one (react/thread.tsx), between a
   head and a composer that stay put. */
.sf-scroll { flex: 1 1 auto; min-height: 0; overflow-y: auto; overscroll-behavior: contain; }
.sf-thread[data-variant="panel"] .sf-scroll { overflow: visible; }
/* The conversation, read like a chat: a face beside every comment, then who
   and when, then the words. Everyone is on the same side \u2014 including the
   person reading \u2014 so whose comment is whose never depends on which way a
   bubble leans. */
.sf-list {
  padding: 10px 12px; display: flex; flex-direction: column; gap: 12px;
  border-top: 1px solid var(--sf-collab-border);
}
.sf-thread[data-variant="panel"] .sf-list { padding: 12px 0; }
.sf-msg { display: flex; align-items: flex-start; gap: 8px; min-width: 0; }
.sf-msg-face { flex: none; display: grid; }
.sf-msg-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
/* the byline is exactly as tall as the face beside it, and centred on it */
.sf-msg-head { display: flex; align-items: center; gap: 6px; height: 24px; min-width: 0; }
.sf-msg-head .sf-row-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; line-height: 24px; }
.sf-msg-head .sf-time { line-height: 24px; }
.sf-row-name { font: var(--sf-collab-text-sm); font-weight: 600; }
.sf-body { font: var(--sf-collab-text-md); overflow-wrap: anywhere; }
.sf-md > * { margin: 0; }
.sf-md > * + * { margin-top: 6px; }
.sf-md ul, .sf-md ol { padding-left: 18px; }
.sf-md ul { list-style: disc; }
.sf-md ol { list-style: decimal; }
.sf-md blockquote { padding-left: 8px; border-left: 2px solid var(--sf-collab-border); color: var(--sf-collab-text-muted); }
.sf-md code { font-family: var(--sf-collab-font-mono); font-size: 0.92em; padding: 0 3px; border-radius: 4px; background: var(--sf-collab-surface); }
.sf-md pre { overflow-x: auto; padding: 6px 8px; border-radius: 6px; background: var(--sf-collab-surface); }
.sf-md pre code { padding: 0; background: none; white-space: pre; }
.sf-md a { color: inherit; text-decoration: underline; text-underline-offset: 2px; }
/* a mention: the named person's own weight, tinted so it reads as a person, not a word */
.sf-mention { font-weight: 600; padding: 0 2px; border-radius: 4px; background: var(--sf-collab-accent-soft); }
/* one comment: just its words. A second from the same person sits a little
   apart from the first, under the same face. */
.sf-bub { position: relative; padding-right: 24px; }
.sf-bub[data-follow="1"] { margin-top: 4px; }
.sf-bub[data-pending="1"] { opacity: 0.6; }
/* The reply a link pointed at, lit for a moment on arrival. */
.sf-bub[data-linked="1"] { animation: sf-linked 1600ms var(--sf-collab-ease-out); border-radius: var(--sf-collab-radius-sm); }
@keyframes sf-linked { from, 30% { background: var(--sf-collab-accent-soft); box-shadow: 0 0 0 6px var(--sf-collab-accent-soft); } }
/* A comment's time is the link to it; pressing it copies. */
a.sf-time { text-decoration: none; }
a.sf-time:hover { color: var(--sf-collab-text); text-decoration: underline; text-underline-offset: 2px; }
.sf-del { position: absolute; top: -2px; right: -2px; opacity: 0; transition: opacity var(--sf-collab-dur-1) var(--sf-collab-ease-out); }
.sf-bub:hover .sf-del, .sf-del:focus-visible { opacity: 1; }
.sf-del-confirm { font: var(--sf-collab-text-xs); color: var(--sf-collab-danger); }

.sf-reactions { display: flex; flex-wrap: wrap; gap: 6px; padding: 6px 0 0; }
.sf-chip {
  display: inline-flex; align-items: center; gap: 4px;
  height: 26px; padding: 0 8px; border-radius: var(--sf-collab-radius-full);
  background: var(--sf-collab-surface-raised); box-shadow: inset 0 0 0 1px var(--sf-collab-border);
  font: var(--sf-collab-text-xs);
}
.sf-chip[data-mine="1"] { background: var(--sf-collab-accent-soft); box-shadow: inset 0 0 0 1px var(--sf-collab-accent); }
/* portalled out of the card (see react/popover.tsx): both of these are
   viewport-positioned, so neither carries its own offsets.

   They also carry their own pointer-events, for the same reason the lightbox
   does: portalled to the shadow root they are siblings of .sf-root, not
   children, so the pointer-events:auto it hands its children never reaches
   them and the host reset (theme/tokens.ts:35, :host pointer-events:none)
   stands. Without this the overflow menu and the emoji palette rendered
   perfectly and swallowed nothing \u2014 every click went straight through to the
   page underneath. */
.sf-palette {
  display: flex; gap: 2px; padding: 4px;
  border-radius: var(--sf-collab-radius-md); ${_}
  box-shadow: var(--sf-collab-shadow), inset 0 0 0 1px var(--sf-collab-border);
  z-index: 2147483001;
  pointer-events: auto;
}
.sf-palette button { width: 32px; height: 32px; border-radius: var(--sf-collab-radius-sm); font-size: 16px; }
.sf-palette button:hover { background: var(--sf-collab-surface-raised); }
.sf-menu {
  padding: 6px;
  border-radius: var(--sf-collab-radius-md); ${_}
  box-shadow: var(--sf-collab-shadow), inset 0 0 0 1px var(--sf-collab-border);
  z-index: 2147483001;
  pointer-events: auto;
}
.sf-menu-row {
  display: flex; align-items: center; gap: 10px; width: 100%;
  height: 32px; padding: 0 10px; border-radius: var(--sf-collab-radius-sm);
  font: var(--sf-collab-text-sm); color: var(--sf-collab-text); text-align: left;
}
.sf-menu-row:hover { background: var(--sf-collab-surface-raised); }
.sf-menu-row[data-destructive="1"] { color: var(--sf-collab-danger); }
.sf-footer { padding: 6px 12px 10px; }
.sf-thread[data-variant="panel"] > .sf-footer { padding: 10px 0 0; }
.sf-archive {
  width: 100%; height: 32px; border-radius: var(--sf-collab-radius-sm);
  font: var(--sf-collab-text-sm); color: var(--sf-collab-text-muted);
  display: flex; align-items: center; justify-content: center; gap: 6px;
}
.sf-archive:hover { color: var(--sf-collab-danger); background: rgba(255, 107, 100, 0.1); }
.sf-lightbox {
  position: fixed; inset: 0; background: var(--sf-collab-scrim); display: grid; place-items: center;
  cursor: zoom-out; animation: sf-fade var(--sf-collab-dur-2); z-index: 2147483002;
  pointer-events: auto;
}
/* Captures are opaque; a pasted transparent image still gets a page-white
   backing, so the scrim never shows through it. */
.sf-lightbox-shot {
  position: relative; overflow: hidden; background: #fff;
  border-radius: var(--sf-collab-radius-sm); box-shadow: var(--sf-collab-shadow);
}
.sf-lightbox-shot img { display: block; max-width: 90vw; max-height: 90vh; }
.sf-lightbox-shot[data-sized="1"] img { width: 100%; height: 100%; max-width: none; max-height: none; }
/* full size is for reading the context: the outline marks the element, no dimming */
.sf-lightbox-shot .sf-shot-crop-box { box-shadow: none; }

/* a destructive row asks before it acts \u2014 inline in the menu, never a modal */
.sf-confirm { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 4px 6px; }
.sf-confirm-q { font: var(--sf-collab-text-sm); flex: 1 1 100%; }
.sf-confirm-yes { font: var(--sf-collab-text-sm); font-weight: 600; color: var(--sf-collab-danger); }
.sf-confirm-no { font: var(--sf-collab-text-sm); color: var(--sf-collab-text-muted); }

/* A card can be picked up by its header and put somewhere else
   (react/card-drag.ts). The header says so; the touch that drags it must not
   also scroll the page under it. */
.sf-floating .sf-head, .sf-newthread .sf-composer-head, .sf-pin-card .sf-head {
  cursor: grab; touch-action: none; user-select: none; -webkit-user-select: none;
}
.sf-floating[data-dragging="1"], .sf-newthread[data-dragging="1"],
.sf-floating[data-dragging="1"] .sf-head, .sf-newthread[data-dragging="1"] .sf-composer-head,
.sf-pin[data-dragging="1"] .sf-pin-card .sf-head { cursor: grabbing; user-select: none; }

/* ================= COMPOSER ================= */
.sf-composer { padding: 10px 12px; border-top: 1px solid var(--sf-collab-border); }
.sf-thread[data-variant="panel"] > .sf-composer { padding: 12px 0 0; }
/* the new-thread card: the same composer, in its own glass beside the thing
   it is about (or docked to the orb when there is no such thing) */
.sf-newthread {
  position: fixed; width: 320px; max-width: calc(100vw - 16px); padding: 12px;
  border-radius: var(--sf-collab-radius-lg);
  ${_}
  box-shadow: var(--sf-collab-shadow), inset 0 0 0 1px var(--sf-collab-border);
  animation: sf-card-in var(--sf-collab-dur-3) var(--sf-collab-ease-out);
}
.sf-newthread > .sf-composer { padding: 0; border-top: 0; }
.sf-composer-head {
  display: flex; align-items: center; gap: 2px; min-height: 28px; margin-bottom: 8px;
  font: var(--sf-collab-text-sm); color: var(--sf-collab-text-muted);
}
/* what is selected, in the picker's own words \u2014 and room for the two steps */
.sf-target {
  flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  font: var(--sf-collab-text-tag); color: var(--sf-collab-text);
}
.sf-iconbtn:disabled { color: var(--sf-collab-text-faint); cursor: default; background: none; }
/* The thing being commented on, lit: a ring on it, a scrim on the rest. The
   scrim is the ring's own shadow, so there is exactly one hole and it is always
   exactly where the ring is. */
.sf-spotlight {
  /* hidden until its first position is written (react/default-ui.tsx) */
  position: fixed; top: 0; left: 0; pointer-events: none; visibility: hidden;
  border-radius: var(--sf-collab-radius-sm);
  box-shadow: 0 0 0 2px var(--sf-collab-accent), 0 0 0 200vmax var(--sf-collab-scrim);
  animation: sf-fade var(--sf-collab-dur-2) var(--sf-collab-ease-out);
}
/* One quiet field: the card's own surface, a hairline, and a focus that is the
   hairline turning accent with a soft halo \u2014 not a second, heavier ring. The
   browser's own border is switched off: nothing else resets it, and left on it
   draws a dark 2px bevel inside the hairline. */
.sf-field-box, .sf-input {
  width: 100%; border-radius: var(--sf-collab-radius-md);
  background: var(--sf-collab-surface-solid); color: var(--sf-collab-text);
  box-shadow: inset 0 0 0 1px var(--sf-collab-border);
  transition: box-shadow var(--sf-collab-dur-1) var(--sf-collab-ease-out);
}
.sf-input {
  appearance: none; border: 0; margin: 0; outline: none;
  height: 34px; padding: 0 10px; font: var(--sf-collab-text-md);
}
/* The comment box is the field; the textarea inside it is only the words. The
   writer's own face sits at its start, so whose comment this is reads at a
   glance beside a thread of other people's. */
.sf-field-box { display: flex; align-items: flex-start; gap: 8px; padding: 8px 10px 8px 8px; cursor: text; }
.sf-ta {
  appearance: none; border: 0; margin: 0; outline: none; padding: 0;
  flex: 1; min-width: 0; min-height: 20px; max-height: 120px; resize: none; overflow-y: hidden;
  background: none; color: inherit; font: var(--sf-collab-text-md); line-height: 20px;
}
.sf-composer[data-intent="new"] .sf-ta { min-height: 48px; }
/* the base focus ring belongs to the field, not to the words inside it */
.sf-ta:focus, .sf-ta:focus-visible { box-shadow: none; }
.sf-field-box:focus-within, .sf-input:focus, .sf-input:focus-visible {
  box-shadow: inset 0 0 0 1px var(--sf-collab-accent), 0 0 0 3px var(--sf-collab-accent-soft);
}
.sf-ta::placeholder, .sf-input::placeholder { color: var(--sf-collab-text-faint); }
/* The writer's face. For a guest it is a button: its tooltip is their name and
   pressing it changes it. */
.sf-me { flex: none; display: grid; place-items: center; border-radius: var(--sf-collab-radius-full); }
button.sf-me { cursor: pointer; transition: transform var(--sf-collab-dur-1) var(--sf-collab-ease-out); }
button.sf-me:hover { transform: scale(1.12); }
/* the @mention picker: in the composer's flow, under the words being typed,
   so no clipping ancestor (a scrolling thread, a dashboard card) can cut it */
.sf-mentions {
  margin-top: 6px; max-height: 188px; overflow-y: auto; padding: 4px;
  border-radius: var(--sf-collab-radius-md);
  background: var(--sf-collab-surface-solid); box-shadow: inset 0 0 0 1px var(--sf-collab-border), var(--sf-collab-shadow-sm);
}
.sf-mention-option {
  display: flex; align-items: center; gap: 8px; min-width: 0; padding: 5px 6px;
  border-radius: var(--sf-collab-radius-sm); cursor: pointer; font: var(--sf-collab-text-sm);
}
.sf-mention-option[aria-selected="true"] { background: var(--sf-collab-accent-soft); }
.sf-mention-name { flex: none; font-weight: 600; }
.sf-composer-foot { display: flex; align-items: center; gap: 4px; margin-top: 8px; }
.sf-submit, .sf-cancel, .sf-discard {
  height: 28px; padding: 0 10px; flex: none;
  border-radius: var(--sf-collab-radius-md);
  font: var(--sf-collab-text-sm);
}
.sf-submit { background: var(--sf-collab-accent); color: var(--sf-collab-accent-ink); }
.sf-cancel { color: var(--sf-collab-text-muted); }
.sf-cancel:hover { background: var(--sf-collab-surface-raised); color: var(--sf-collab-text); }
.sf-discard { color: var(--sf-collab-danger); font-weight: 600; }
.sf-discard:hover { background: var(--sf-collab-surface-raised); }
/* leaving a comment with words in it asks first, in the foot's own place */
.sf-composer-discard { margin-top: 8px; padding: 0; flex-wrap: nowrap; gap: 6px; }
.sf-composer-discard .sf-confirm-q { flex: 1 1 auto; color: var(--sf-collab-text); white-space: nowrap; }
/* said when Comment is pressed on an empty box: a nudge, not an error */
.sf-composer-note { font: var(--sf-collab-text-xs); color: var(--sf-collab-text-muted); margin-top: 6px; text-align: right; }
/* Cannot send right now: a muted surface with faint ink, not the accent at
   half strength \u2014 a washed-out brand colour under white text reads as broken
   rather than as "not yet". */
.sf-submit:disabled {
  background: var(--sf-collab-surface-raised); color: var(--sf-collab-text-faint);
  box-shadow: inset 0 0 0 1px var(--sf-collab-border);
  cursor: default;
}
.sf-submit:hover:not(:disabled) { background: var(--sf-collab-accent-hover); }
.sf-composer-error { font: var(--sf-collab-text-xs); color: var(--sf-collab-danger); margin-top: 6px; }
.sf-composer[data-drop="1"] .sf-field-box { box-shadow: inset 0 0 0 1px var(--sf-collab-accent), 0 0 0 3px var(--sf-collab-accent-soft); }
.sf-att-input { display: none; }
.sf-att-row { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.sf-att-chip {
  display: flex; align-items: center; gap: 6px; max-width: 100%;
  padding: 4px 6px; border-radius: var(--sf-collab-radius-sm);
  background: var(--sf-collab-surface-raised); box-shadow: inset 0 0 0 1px var(--sf-collab-border);
  font: var(--sf-collab-text-xs); color: var(--sf-collab-text);
}
.sf-att-chip[data-status="failed"] { box-shadow: inset 0 0 0 1px var(--sf-collab-danger); }
.sf-att-thumb { width: 28px; height: 28px; border-radius: var(--sf-collab-radius-sm); object-fit: cover; flex: none; }
.sf-att-name { max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sf-att-note { color: var(--sf-collab-text-muted); white-space: nowrap; }
.sf-att-note[data-failed="1"] { color: var(--sf-collab-danger); }
.sf-att-imgs { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.sf-att-imgbtn { width: 96px; height: 72px; border-radius: var(--sf-collab-radius-sm); overflow: hidden; cursor: zoom-in; }
.sf-att-img { width: 100%; height: 100%; object-fit: cover; display: block; }
.sf-att-file {
  display: flex; align-items: center; gap: 6px; margin-top: 6px; padding: 4px 8px;
  border-radius: var(--sf-collab-radius-sm);
  background: var(--sf-collab-surface-raised); box-shadow: inset 0 0 0 1px var(--sf-collab-border);
  font: var(--sf-collab-text-xs); color: var(--sf-collab-text); text-decoration: none;
}
.sf-att-size { color: var(--sf-collab-text-faint); white-space: nowrap; }
.sf-account { color: var(--sf-collab-accent); text-decoration: none; white-space: nowrap; }
.sf-account:hover { text-decoration: underline; }

/* ================= DIALOG ================= */
/* A real modal, for the one thing that is a form: who you are. It is portalled
   to the overlay's root, outside .sf-root, so it takes the pointer back itself. */
.sf-modal-scrim {
  position: fixed; inset: 0; z-index: 2147483002; pointer-events: auto;
  display: grid; place-items: center; padding: 16px;
  background: var(--sf-collab-scrim);
  animation: sf-fade var(--sf-collab-dur-2) var(--sf-collab-ease-out);
}
.sf-modal {
  width: 340px; max-width: 100%; padding: 20px;
  display: flex; flex-direction: column; gap: 12px;
  border-radius: var(--sf-collab-radius-lg);
  ${_}
  box-shadow: var(--sf-collab-shadow), inset 0 0 0 1px var(--sf-collab-border);
  color: var(--sf-collab-text);
  animation: sf-card-in var(--sf-collab-dur-3) var(--sf-collab-ease-out);
}
.sf-modal-title { font: var(--sf-collab-text-lg); }
.sf-modal-lede { font: var(--sf-collab-text-sm); color: var(--sf-collab-text-muted); margin-top: -8px; }
.sf-field { display: flex; flex-direction: column; gap: 4px; }
.sf-field-label { font: var(--sf-collab-text-xs); color: var(--sf-collab-text-muted); }
.sf-field-hint { font: var(--sf-collab-text-xs); color: var(--sf-collab-text-faint); }
.sf-field-hint[data-error="1"] { color: var(--sf-collab-danger); }
.sf-modal-foot { display: flex; align-items: center; justify-content: flex-end; gap: 6px; margin-top: 4px; }
.sf-modal-foot .sf-account { margin-right: auto; font: var(--sf-collab-text-xs); }

/* ================= TOOLTIP ================= */
/* The layer's one tooltip (react/tooltips.tsx), in the dashboard's own dress:
   an inverted surface, small type, a short fade. It is portalled to the
   overlay's root, above every card and menu, and never takes the pointer. */
.sf-tip {
  position: fixed; top: 0; left: 0; z-index: 2147483003; visibility: hidden;
  max-width: 240px; padding: 5px 8px; border-radius: var(--sf-collab-radius-sm);
  background: var(--sf-collab-text); color: var(--sf-collab-surface-solid);
  font: var(--sf-collab-text-xs); font-weight: 500; line-height: 1.35;
  box-shadow: var(--sf-collab-shadow-sm);
  pointer-events: none; user-select: none;
  animation: sf-tip-in var(--sf-collab-dur-1) var(--sf-collab-ease-out);
}
@keyframes sf-tip-in { from { opacity: 0; } to { opacity: 1; } }

/* ================= TOASTS ================= */
/* The bottom offset is set inline: the stack clears the dock (react/toast.tsx). */
.sf-toasts {
  position: fixed; left: 50%; bottom: 20px; transform: translateX(-50%);
  display: flex; flex-direction: column-reverse; gap: 8px; align-items: center;
  pointer-events: none;
}
.sf-toast {
  display: flex; align-items: center; gap: 10px;
  min-height: 40px; max-width: 360px; padding: 8px 12px;
  border-radius: var(--sf-collab-radius-md);
  ${ue}
  font: var(--sf-collab-text-sm); pointer-events: auto;
  animation: sf-toast-in var(--sf-collab-dur-2) var(--sf-collab-ease-out);
}
.sf-toast-action { color: var(--sf-collab-accent); font: var(--sf-collab-text-sm); margin-left: auto; }
.sf-toast .sf-ico { flex: none; }
.sf-toast[data-kind="error"] .sf-ico { color: var(--sf-collab-danger); }
.sf-toast[data-kind="success"] .sf-ico { color: var(--sf-collab-success); }

/* ================= motion ================= */
@keyframes sf-orb-in { from { opacity: 0; transform: scale(0.8); } to { opacity: 1; transform: none; } }
@keyframes sf-sidebar-in { from { transform: translateX(100%); } }
@keyframes sf-sidebar-out { to { transform: translateX(100%); } }
@keyframes sf-sheet-in { from { transform: translateY(100%); } }
@keyframes sf-sheet-out { to { transform: translateY(100%); } }
@keyframes sf-sidebar-content { from { opacity: 0; transform: translateX(24px); } }
@keyframes sf-fade-away { to { opacity: 0; } }
@keyframes sf-card-in { from { opacity: 0; transform: translateY(6px) scale(0.98); } to { opacity: 1; transform: none; } }
@keyframes sf-toast-in { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }

@media (pointer: coarse) {
  .sf-iconbtn, .sf-chip, .sf-palette button, .sf-account, .sf-del, .sf-tab, .sf-filter button, .sf-filter-trigger { position: relative; }
  /* invisible centered hit area \u2014 grows the target, never the visual */
  .sf-iconbtn::before, .sf-chip::before, .sf-palette button::before,
  .sf-account::before, .sf-del::before, .sf-tab::before, .sf-filter button::before, .sf-filter-trigger::before {
    content: ""; position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%);
    width: max(100%, 40px); height: max(100%, 40px);
  }
  .sf-railbtn { height: 40px; }
}

/* Reduce means reduce.
   Every selector that declares an animation above is repeated here VERBATIM,
   attribute qualifiers and all: .sf-sidebar[data-variant="sheet"] outranks its
   bare class, so naming only the class left the sheet sliding under a
   preference that asks it not to. A new animated surface belongs in this list
   the moment it exists. */
@media (prefers-reduced-motion: reduce) {
  .sf-orb-dock,
  .sf-sidebar, .sf-sidebar[data-variant="sheet"],
  .sf-newthread, .sf-thread[data-variant="floating"], .sf-toast, .sf-modal, .sf-tip,
  .sf-row-peek {
    animation-duration: 80ms; animation-name: sf-fade;
  }
  /* Still played, so the Sidebar still hears that it is gone. */
  .sf-sidebar[data-leaving="1"], .sf-sidebar[data-variant="sheet"][data-leaving="1"] {
    animation-duration: 80ms; animation-name: sf-fade-away;
  }
  .sf-sidebar:not([data-leaving="1"]) > * { animation: none; }
  .sf-orb-conn[data-conn] { animation: none; }
  /* The dock still MOVES for the drawer and its tools still come out; they
     just get there at once. (The stack's own motion is only declared under
     no-preference, above.) */
  .sf-orb-dock, .sf-orb, .sf-orb-mark .sf-ico, .sf-orb-glyph { transition: none; }
}
`;var O=j(fe(),1),I=(0,S.createContext)(null);function Qe(e){let t=e.host,a=t.shadowRoot;if(!a)throw new Error("collab host has no shadow root to render into");le(a,Ae);let o=Pe({store:e.store,manifest:e.manifest,host:t,root:a,sendEphemeral:(r,n)=>e.sendEphemeral(r,n),getToken:()=>e.getToken()});return{runtime:{collab:e,ctx:o.ctx},destroy:o.destroy}}function Gt(e){let[t,a]=(0,S.useState)(null);return(0,S.useEffect)(()=>{let o=Qe(e.collab);return a(o),()=>{o.destroy(),a(r=>r===o?null:r)}},[e.collab]),!t||t.runtime.collab!==e.collab?(0,O.jsx)(O.Fragment,{}):(0,O.jsx)(I.Provider,{value:t.runtime,children:e.children})}function Xt(){return(0,S.useContext)(I)}function $(){let e=(0,S.useContext)(I);if(!e)throw new Error("useCollab needs a <CollabProvider collab={\u2026}> above it");return e.collab}function _e(){let e=(0,S.useContext)(I);if(!e)throw new Error("this component needs a <CollabProvider collab={\u2026}> above it");return e.ctx}var f=j(ce(),1);function k(e){return typeof e=="string"&&e.length>0?e:null}function P(e){return typeof e=="number"&&Number.isFinite(e)?e:null}var z=24;function Ze(e){if(!v(e))return null;let t=P(e.x),a=P(e.y),o=P(e.width),r=P(e.height);return t===null||a===null||o===null||r===null||t<0||a<0||o<=0||r<=0||t>1||a>1||o>1||r>1?null:{x:t,y:a,width:Math.min(o,1-t),height:Math.min(r,1-a)}}function et(e,t){let a={crop:null,size:null};if(!e)return a;let o=v(t.viewport)?t.viewport:null,r=o?P(o.doc_width):null,n=o?P(o.doc_height):null;if(r===null||n===null||r<=0||n<=0)return a;let s={x:e.x*r,y:e.y*n,width:e.width*r,height:e.height*n},d=Math.max(0,s.x-z),b=Math.max(0,s.y-z),u=Math.min(r,s.x+s.width+z),g=Math.min(n,s.y+s.height+z),x=u-d,m=g-b;return x<=0||m<=0?a:{crop:{x:(s.x-d)/x,y:(s.y-b)/m,width:s.width/x,height:s.height/m},size:{width:x,height:m}}}var Le=8,tt=1/3;function to(e,t,a){let o=Math.max(e.width/t.width,e.height/t.height),r=(e.width-2*Le)/(a.width*t.width),n=r<o&&r>=tt?r:o,s=t.width*n,d=t.height*n,b=Math.min(e.height,d);return{left:Re(e.width,s,a.x,a.width),top:Re(b,d,a.y,a.height),width:s,height:d,boxHeight:b}}function Re(e,t,a,o){if(t<=e)return(e-t)/2;let r=e/2-(a+o/2)*t,n=Le-a*t;return Math.min(0,Math.max(e-t,Math.max(r,n)))}function Ee(e){let t;try{t=new URL(e)}catch{return null}return!(t.protocol==="https:"||t.protocol==="http:"&&t.origin===globalThis.location?.origin)||t.username!==""||t.password!==""||t.search!==""||t.hash!==""?null:t}function ot(e,t,a){if(e.kind==="none")return null;if(e.kind==="proxied"){let n=Ee(e.base);return n&&n.pathname.endsWith("/storage/")?`${n.href}${t}`:null}if(e.kind==="composed"){let n=Ee(e.base);return!n||n.pathname!=="/__stattic/u/"||e.key===""?null:re({base:n.href,key:encodeURIComponent(e.key)},t)}if(a===null)return null;let o;try{o=new URL(a)}catch{return null}let r=[...o.searchParams.keys()];return o.protocol!=="https:"||o.origin!==e.origin||o.username!==""||o.password!==""||o.pathname!==`/__stattic/u/${t}`||o.hash!==""||r.length!==1||r[0]!=="k"||!o.searchParams.get("k")?null:o.href}function at(e,t){let a=ne(e),o=[];for(let r of a){let n=v(e)&&Array.isArray(e.attachments)?e.attachments.find(b=>v(b)&&b.id===r.id):void 0,s=v(n)?k(n.url):null,d=ot(t,r.id,s);d&&o.push({...r,url:d})}return o}function oo(e,t,a){let o=at(e,a).find(n=>n.kind==="screenshot");if(!o)return null;let r=v(t)?t:{};return{url:o.url,...et(Ze(o.crop),r)}}function Me(e){if(oe(e)||!v(e))return{kind:"page",path:null,offPage:!1};let t=v(e.source)?e.source:null,a=t?k(t.canonical_path):null;if(e.v!==2)return{kind:"page",path:a,offPage:!0};let o=v(e.selectors)?e.selectors:null,r=k(e.type);if(r==="text"){let n=o&&v(o.text_quote)?o.text_quote:null,s=n?k(n.exact):null;return s?{kind:"text",quote:s,prefix:n?k(n.prefix):null,suffix:n?k(n.suffix):null,path:a}:{kind:"page",path:a,offPage:!1}}if(r==="element"){let n=o&&v(o.fingerprint)?o.fingerprint:null;return{kind:"element",label:(n?k(n.accessible_name)??k(n.own_text):null)??(n?k(n.tag):null)??"element",selector:o?k(o.css):null,path:a}}if(r==="file"){let n="file"in e&&v(e.file)?e.file:null,s=n?k(n.path):null;if(n&&s){let d=v(n.lines)?n.lines:null,b=d?P(d.start):null,u=d?P(d.end):null;return{kind:"page",path:de({file:b!==null&&u!==null?{path:s,lines:{start:b,end:u}}:{path:s}}),offPage:!1}}}return r==="page"?{kind:"page",path:a,offPage:!1}:{kind:"page",path:a,offPage:!0}}function rt(e){return[e.status,e.actor_id,K(e.author),e.inserted_at,e.body??"",e.comments.map(t=>[t.id,t.actor_id,K(t.author),t.inserted_at,t.deleted_at??"",t.body??"",Oe(t.metadata)].join(":")).join("|"),e.resolve?.status??"",JSON.stringify(Me(e.anchor)),Oe(e.metadata)]}var nt=C.looseObject({attachments:C.array(C.looseObject({id:C.string()})).optional()});function Oe(e){let t=nt.safeParse(e);return t.success?(t.data.attachments??[]).map(a=>a.id).join(","):""}function $e(e,t,a,o){let r=new Set,n=()=>{for(let i of r)i()};function s(i=e.store.getState()){return i.threads.get(t)??null}function d(i){let l=X(i.anchor);if(!l||l===G(e.store.getState()))return null;let p=V(e),h=p.get(l),T=i.anchor?.source?.canonical_path??globalThis.location?.pathname??"/";if(!h)return p.ensure([l]).then(Ne=>{Ne&&n()}),{kind:"label",version:{id:l,label:l,live:!1,published_at:null}};let L={id:l,label:Ce(l,h),live:!1,published_at:h.createdAt},Q=ke(h.url,T,i.id);return Q===null?{kind:"label",version:L}:{kind:"href",href:Q,version:L}}function b(){let i=e.store.getState(),l=s(i),p=l!==null&&l.actor_id===i.session?.actor_id;return{thread:l,viewerActorIds:i.session?[i.session.actor_id]:[],capabilities:{react:!0,archive:p||i.session?.capabilities.comments_moderate===!0,deleteOwnReplies:!0,moderate:!1,repin:p&&l?.resolve?.status==="orphaned",copyLink:!0},composer:W(i),pageLink:l?d(l):null,viewedPath:e.captureSource().canonical_path,pendingReply:null,moderation:"clear",attachmentSource:i.config?.uploads?{kind:"composed",...i.config.uploads}:{kind:"none"},mentionable:i.mentionable}}function u(i){let l=s(i);return l?[...rt(l),Object.entries(l.reactions).map(([p,h])=>`${p}=${h.count}:${Object.keys(h.actors).join("+")}`).join(","),(i.drafts.replies.get(t)?.attachments??[]).map(p=>`${p.localId}=${p.status}`).join(","),i.connection,i.session?.id??"",i.session?.actor_id??"",String(i.session?.capabilities.comments_create??!1),String(M(i.config)),i.mentionable?.map(({userId:p,name:h})=>`${p}=${h}`).join(",")??"-"].join("\xA7"):""}let g=e.store.subscribe(u,n),x={read:()=>e.store.getState().drafts.replies.get(t)?.attachments??[],add:i=>e.store.actions.addDraftAttachments({kind:"reply",threadId:t},i),remove:i=>e.store.actions.removeDraftAttachment({kind:"reply",threadId:t},i)},m={read:b,searchMentionable:(i,l)=>e.store.actions.searchMentionable(i,l),subscribe(i){return r.add(i),()=>r.delete(i)},readDraft:()=>e.store.getState().drafts.replies.get(t)?.body??"",writeDraft(i){e.store.setState(l=>{let h=l.drafts.replies.get(t)?.attachments??[],T=new Map(l.drafts.replies);return i||h.length>0?T.set(t,{body:i,attachments:h}):T.delete(t),{drafts:{...l.drafts,replies:T}}})},async reply(i,l){try{await e.store.actions.reply(t,i,l),e.store.actions.toast({text:y.thread.replyPosted,kind:"success"})}catch(p){throw e.store.actions.toast({text:y.error.sendFailed,kind:"error"}),p}},react(i,l){e.store.actions.react(t,i)},setStatus(i){let l=s();if(l){if(i==="open"){e.store.actions.unarchive(t),e.store.actions.toast({text:y.thread.reopened,kind:"success"});return}a(l),e.store.actions.openThread(null),e.store.actions.archive(t),e.store.actions.toast({text:y.thread.resolved,kind:"success",action:{label:y.thread.undo,run:()=>{e.store.actions.unarchive(t),e.store.actions.toast({text:y.thread.reopened,kind:"success"}),e.store.actions.openThread(t)}}})}},deleteReply:i=>e.store.actions.deleteComment(t,i),setSpam:()=>{},repin(){e.store.actions.beginRepin(t),e.store.actions.openThread(null)},commentLink(i){let l=new URL(globalThis.location.href),p=`${N}=${encodeURIComponent(U(t))}`;return l.hash=i===t?p:`${p}&${ae}=${encodeURIComponent(i)}`,l.href},copyLink(i=t){let l=m.commentLink(i);l!==null&&(globalThis.navigator?.clipboard?.writeText(l).catch(()=>{}),e.store.actions.toast({text:y.thread.linkCopied,kind:"success"}))},openPage:()=>{},...o===void 0?{}:{close:o},destroy(){g(),r.clear()}},c=e.store.getState().config;return c?.features.attachments&&c.uploads&&(m.attachments=x),m}function st(e,t){let a=e.target;if(a.kind!=="region"||!e.drawing)return a;let{rect:o}=a,{spot:r}=e.drawing,n=r.x-t.x-(o.left+o.width/2),s=r.y-t.y-(o.top+o.height/2);return{kind:"region",rect:new DOMRect(o.x+n,o.y+s,o.width,o.height)}}function ho(e){let t=new Set,a=()=>{for(let c of t)c()},o=e.store.getState().config,r=(o?.features.capture??!1)&&o?.uploads!=null,n=null;function s(){let c=e.store.getState().drafts.newThread;return c?st(c,{x:globalThis.scrollX,y:globalThis.scrollY}):{kind:"page"}}function d(){if(!r||!e.store.getState().ui.screenshots)return;let c=s();n=import("./capture-AZI7RN26.js").then(i=>e.signal.aborted?null:i.createCaptureProvider(e)(c)).catch(()=>null)}function b(c){e.store.setState(i=>i.drafts.newThread?{drafts:{...i.drafts,newThread:{...i.drafts.newThread,body:c}}}:{})}function u(c){let i=n;if(!i)return;let l=c.then(p=>p,()=>{});Promise.all([l,i]).then(([p,h])=>{if(!(e.signal.aborted||p===void 0)){if(!h){e.store.actions.toast({text:Y.capture.failed,kind:"info"});return}p&&e.store.actions.attachEvidence(p,h)}}).catch(()=>{e.signal.aborted||e.store.actions.toast({text:Y.capture.failed,kind:"info"})})}let g=e.store.subscribe(c=>[c.connection,c.mode,String(c.session?.capabilities.comments_create??!1),String(M(c.config)),(c.drafts.newThread?.attachments??[]).map(i=>`${i.localId}=${i.status}`).join(","),c.mentionable?.map(({userId:i,name:l})=>`${i}=${l}`).join(",")??"-"].join("\xA7"),a),x={searchMentionable:(c,i)=>e.store.actions.searchMentionable(c,i),read:()=>{let c=e.store.getState();return{thread:null,viewerActorIds:c.session?[c.session.actor_id]:[],capabilities:{react:!1,archive:!1,deleteOwnReplies:!1,moderate:!1,repin:!1,copyLink:!1},composer:W(c),pageLink:null,viewedPath:e.captureSource().canonical_path,pendingReply:null,moderation:"clear",attachmentSource:c.config?.uploads?{kind:"composed",...c.config.uploads}:{kind:"none"},mentionable:c.mentionable}},subscribe(c){return t.add(c),()=>t.delete(c)},readDraft:()=>e.store.getState().drafts.newThread?.body??"",writeDraft:b,async reply(c,i){b(c),d();let l=e.store.actions.submitComposer(i);u(l),await l,e.store.actions.toast({text:y.thread.commentPosted,kind:"success"})},react:()=>{},setStatus:()=>{},deleteReply:()=>{},setSpam:()=>{},repin:()=>{},commentLink:()=>null,copyLink:()=>{},openPage:()=>{},close:()=>e.store.actions.cancelComposer(),destroy(){g(),t.clear()}},m=e.store.getState().config;return m?.features.attachments&&m.uploads&&(x.attachments={read:()=>e.store.getState().drafts.newThread?.attachments??[],add:c=>e.store.actions.addDraftAttachments({kind:"new"},c),remove:c=>e.store.actions.removeDraftAttachment({kind:"new"},c)}),x}function A(e,t){if(Object.is(e,t))return!0;if(Array.isArray(e)&&Array.isArray(t))return e.length===t.length&&e.every((o,r)=>Object.is(o,t[r]));if(!je(e)||!je(t))return!1;let a=Object.keys(e);return a.length===Object.keys(t).length&&a.every(o=>Object.is(e[o],t[o]))}function je(e){if(e===null||typeof e!="object")return!1;let t=Object.getPrototypeOf(e);return t===Object.prototype||t===null}function E(e){return it($().store,e)}function it(e,t){let a=(0,f.useRef)(t);a.current=t;let o=(0,f.useRef)(null),r=(0,f.useCallback)(s=>e.subscribe(d=>d,s),[e]),n=(0,f.useCallback)(()=>{let s=a.current(e.getState());return o.current&&A(o.current.value,s)?o.current.value:(o.current={value:s},s)},[e]);return(0,f.useSyncExternalStore)(r,n,n)}function ko(e){let t=(0,f.useRef)(null),a=(0,f.useCallback)(r=>e.subscribe(r),[e]),o=(0,f.useCallback)(()=>{let r=e.read();return t.current&&lt(t.current,r)?t.current:(t.current=r,r)},[e]);return(0,f.useSyncExternalStore)(a,o,o)}function Co(e){let t=(0,f.useCallback)(o=>e.subscribe(o),[e]),a=(0,f.useCallback)(()=>(e.attachments?.read()??[]).map(o=>`${o.localId}=${o.status}`).join(","),[e]);return(0,f.useSyncExternalStore)(t,a,a)}function lt(e,t){return Object.is(e.thread,t.thread)&&e.pendingReply===t.pendingReply&&e.viewedPath===t.viewedPath&&e.moderation===t.moderation&&A(e.attachmentSource,t.attachmentSource)&&A(e.composer,t.composer)&&A(e.capabilities,t.capabilities)&&ct(e.pageLink,t.pageLink)&&A(e.viewerActorIds,t.viewerActorIds)&&A(e.mentionable,t.mentionable)}function ct(e,t){if(e===null||t===null)return e===t;let{version:a,...o}=e,{version:r,...n}=t;return A(o,n)&&A(a,r)}function So(){return E(e=>e.connection)}function To(){return E(he)}function Po(){return E(e=>e.session?.actor_kind==="user"?{name:e.identity.name,color:e.identity.color,avatar_url:e.identity.avatar_url}:null)}function Ao(){return E(e=>({count:be(e),mentioned:me(e)}))}function _o(){let{store:e}=$(),{tool:t,mode:a}=E(r=>({tool:r.tool,mode:r.mode})),o=(0,f.useCallback)(r=>e.actions.setTool(r),[e]);return{tool:t,mode:a,setTool:o}}function Ro(e={}){let t=$().manifest.pagePath,a=e.page??"page",o=e.archived??!1,r=E(n=>[...n.threads.values()]);return(0,f.useMemo)(()=>r.filter(s=>{if(s.status==="archived"!==o)return!1;if(a==="all")return!0;let d=xe(s);return d===null||d===t}).sort((s,d)=>d.inserted_at.localeCompare(s.inserted_at)),[r,a,o,t])}function Eo(e){let t=_e(),[a,o]=(0,f.useState)(null);return(0,f.useEffect)(()=>{if(!e){o(null);return}let r=$e(t,e,()=>{});return o(r),()=>{r.destroy(),o(null)}},[t,e]),e===null?null:a}function dt(e){let t=new Date(e);return Number.isNaN(t.getTime())?e:t.toDateString()}function ft(e,t,a){let o=new Date(e);if(Number.isNaN(o.getTime()))return"";let r=Math.round((new Date(t.toDateString()).getTime()-new Date(o.toDateString()).getTime())/864e5);return r<=0?a.today:r===1?a.yesterday:o.toLocaleDateString(void 0,{month:"short",day:"numeric"})}function Lo(e){let t=$(),[a,o]=(0,f.useState)([]),[r,n]=(0,f.useState)(null),[s,d]=(0,f.useState)(!1),[b,u]=(0,f.useState)(!1),[g,x]=(0,f.useState)(!0),m=(0,f.useRef)(!1),c=(0,f.useCallback)(async l=>{if(m.current)return;m.current=!0,x(!0);let p=await Te({apiBase:t.manifest.cast.apiBase,resourceKey:t.manifest.cast.resourceKey,token:t.getToken(),cursor:l,limit:J});await V(t).ensure(p.events.flatMap(h=>h.version_id?[h.version_id]:[])),o(h=>[...h,...p.events]),n(p.next),d(p.hasMore&&p.next!==null),u(p.failed),x(!1),m.current=!1},[t]);return(0,f.useEffect)(()=>{c(null)},[c]),{lanes:(0,f.useMemo)(()=>{let l=new Date,p=[];for(let h of a){let T=dt(h.inserted_at),L=p.at(-1);L?.key===T?L.events.push(h):p.push({key:T,label:ft(h.inserted_at,l,e),events:[h]})}return p},[a,e]),loading:g,failed:b,hasMore:s,loadMore:()=>{c(r)}}}function Mo(e,t){let[a,o]=(0,f.useState)(e),r=(0,f.useRef)(a===null);return(0,f.useEffect)(()=>{if(e===null)return;if(r.current){r.current=!1,o(e);return}let n=setTimeout(()=>o(e),t);return()=>clearTimeout(n)},[e,t]),a}function Oo(e){let[t,a]=(0,f.useState)(()=>Date.now());return(0,f.useEffect)(()=>{let o=setInterval(()=>a(Date.now()),e);return()=>clearInterval(o)},[e]),t}function $o(){let e=`(max-width: ${D-1}px)`,t=(0,f.useCallback)(a=>{let o=globalThis.matchMedia(e);return o.addEventListener("change",a),()=>o.removeEventListener("change",a)},[e]);return(0,f.useSyncExternalStore)(t,()=>globalThis.matchMedia(e).matches,()=>!1)}function jo(){let e=(0,f.useCallback)(t=>{let a=globalThis.matchMedia("(prefers-reduced-motion: reduce)");return a.addEventListener("change",t),()=>a.removeEventListener("change",t)},[]);return(0,f.useSyncExternalStore)(e,()=>globalThis.matchMedia("(prefers-reduced-motion: reduce)").matches,()=>!1)}export{to as a,at as b,oo as c,Me as d,_t as e,Y as f,y as g,Ue as h,Ie as i,G as j,X as k,xe as l,yt as m,kt as n,Ct as o,ze as p,qe as q,He as r,St as s,V as t,ke as u,Ce as v,$t as w,ho as x,Nt as y,Gt as z,Xt as A,$ as B,_e as C,E as D,it as E,ko as F,Co as G,So as H,To as I,Po as J,Ao as K,_o as L,Ro as M,Eo as N,Lo as O,Mo as P,Oo as Q,$o as R,jo as S};
