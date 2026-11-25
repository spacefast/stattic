import '__i18n__';
var Je=Object.create;var ne=Object.defineProperty;var Qe=Object.getOwnPropertyDescriptor;var et=Object.getOwnPropertyNames;var tt=Object.getPrototypeOf,rt=Object.prototype.hasOwnProperty;var x=(r,o)=>()=>(o||r((o={exports:{}}).exports,o),o.exports);var ot=(r,o,e,t)=>{if(o&&typeof o=="object"||typeof o=="function")for(let n of et(o))!rt.call(r,n)&&n!==e&&ne(r,n,{get:()=>o[n],enumerable:!(t=Qe(o,n))||t.enumerable});return r};var _=(r,o,e)=>(e=r!=null?Je(tt(r)):{},ot(o||!r||!r.__esModule?ne(e,"default",{value:r,enumerable:!0}):e,r));var se=x((Et,ae)=>{"use strict";ae.exports=window.React});var de=x((zt,he)=>{"use strict";var ce=!1,S,V,X,j,L,pe,N,q,Y,k,ue,K,$,le,fe;function v(){if(!ce){ce=!0;var r=navigator.userAgent,o=/(?:MSIE.(\d+\.\d+))|(?:(?:Firefox|GranParadiso|Iceweasel).(\d+\.\d+))|(?:Opera(?:.+Version.|.)(\d+\.\d+))|(?:AppleWebKit.(\d+(?:\.\d+)?))|(?:Trident\/\d+\.\d+.*rv:(\d+\.\d+))/.exec(r),e=/(Mac OS X)|(Windows)|(Linux)/.exec(r);if(K=/\b(iPhone|iP[ao]d)/.exec(r),$=/\b(iP[ao]d)/.exec(r),k=/Android/i.exec(r),le=/FBAN\/\w+;/i.exec(r),fe=/Mobile/i.exec(r),ue=!!/Win64/.exec(r),o){S=o[1]?parseFloat(o[1]):o[5]?parseFloat(o[5]):NaN,S&&document&&document.documentMode&&(S=document.documentMode);var t=/(?:Trident\/(\d+.\d+))/.exec(r);pe=t?parseFloat(t[1])+4:S,V=o[2]?parseFloat(o[2]):NaN,X=o[3]?parseFloat(o[3]):NaN,j=o[4]?parseFloat(o[4]):NaN,j?(o=/(?:Chrome\/(\d+\.\d+))/.exec(r),L=o&&o[1]?parseFloat(o[1]):NaN):L=NaN}else S=V=X=L=j=NaN;if(e){if(e[1]){var n=/(?:Mac OS X (\d+(?:[._]\d+)?))/.exec(r);N=n?parseFloat(n[1].replace("_",".")):!0}else N=!1;q=!!e[2],Y=!!e[3]}else N=q=Y=!1}}var J={ie:function(){return v()||S},ieCompatibilityMode:function(){return v()||pe>S},ie64:function(){return J.ie()&&ue},firefox:function(){return v()||V},opera:function(){return v()||X},webkit:function(){return v()||j},safari:function(){return J.webkit()},chrome:function(){return v()||L},windows:function(){return v()||q},osx:function(){return v()||N},linux:function(){return v()||Y},iphone:function(){return v()||K},mobile:function(){return v()||K||$||k||fe},nativeApp:function(){return v()||le},android:function(){return v()||k},ipad:function(){return v()||$}};he.exports=J});var ge=x((Dt,me)=>{"use strict";var W=!!(typeof window<"u"&&window.document&&window.document.createElement),nt={canUseDOM:W,canUseWorkers:typeof Worker<"u",canUseEventListeners:W&&!!(window.addEventListener||window.attachEvent),canUseViewport:W&&!!window.screen,isInWorker:!W};me.exports=nt});var Ce=x((At,we)=>{"use strict";var ve=ge(),ye;ve.canUseDOM&&(ye=document.implementation&&document.implementation.hasFeature&&document.implementation.hasFeature("","")!==!0);function it(r,o){if(!ve.canUseDOM||o&&!("addEventListener"in document))return!1;var e="on"+r,t=e in document;if(!t){var n=document.createElement("div");n.setAttribute(e,"return;"),t=typeof n[e]=="function"}return!t&&ye&&r==="wheel"&&(t=document.implementation.hasFeature("Events.wheel","3.0")),t}we.exports=it});var Pe=x((Mt,Re)=>{"use strict";var at=de(),st=Ce(),be=10,_e=40,xe=800;function Se(r){var o=0,e=0,t=0,n=0;return"detail"in r&&(e=r.detail),"wheelDelta"in r&&(e=-r.wheelDelta/120),"wheelDeltaY"in r&&(e=-r.wheelDeltaY/120),"wheelDeltaX"in r&&(o=-r.wheelDeltaX/120),"axis"in r&&r.axis===r.HORIZONTAL_AXIS&&(o=e,e=0),t=o*be,n=e*be,"deltaY"in r&&(n=r.deltaY),"deltaX"in r&&(t=r.deltaX),(t||n)&&r.deltaMode&&(r.deltaMode==1?(t*=_e,n*=_e):(t*=xe,n*=xe)),t&&!o&&(o=t<1?-1:1),n&&!e&&(e=n<1?-1:1),{spinX:o,spinY:e,pixelX:t,pixelY:n}}Se.getEventType=function(){return at.firefox()?"DOMMouseScroll":st("wheel")?"wheel":"mousewheel"};Re.exports=Se});var ze=x((Ot,Ee)=>{"use strict";Ee.exports=Pe()});var U=x((jt,je)=>{"use strict";je.exports=window.wp.element});var T=x((Ht,Ze)=>{"use strict";Ze.exports=window.ReactJSXRuntime});var B=function(r,o){return B=Object.setPrototypeOf||{__proto__:[]}instanceof Array&&function(e,t){e.__proto__=t}||function(e,t){for(var n in t)Object.prototype.hasOwnProperty.call(t,n)&&(e[n]=t[n])},B(r,o)};function ie(r,o){if(typeof o!="function"&&o!==null)throw new TypeError("Class extends value "+String(o)+" is not a constructor or null");B(r,o);function e(){this.constructor=r}r.prototype=o===null?Object.create(o):(e.prototype=o.prototype,new e)}var d=function(){return d=Object.assign||function(o){for(var e,t=1,n=arguments.length;t<n;t++){e=arguments[t];for(var i in e)Object.prototype.hasOwnProperty.call(e,i)&&(o[i]=e[i])}return o},d.apply(this,arguments)};var y=_(se(),1),Ie=_(ze(),1);function ct(r,o,e,t,n,i){i===void 0&&(i=0);var a=z(r,o,i),c=a.width,p=a.height,s=Math.min(c,e),u=Math.min(p,t);return s>u*n?{width:u*n,height:u}:{width:s,height:s/n}}function pt(r){return r.width>r.height?r.width/r.naturalWidth:r.height/r.naturalHeight}function M(r,o,e,t,n){n===void 0&&(n=0);var i=z(o.width,o.height,n),a=i.width,c=i.height;return{x:De(r.x,a,e.width,t),y:De(r.y,c,e.height,t)}}function De(r,o,e,t){var n=Math.abs(o*t/2-e/2);return Z(r,-n,n)}function Ae(r,o){return Math.sqrt(Math.pow(r.y-o.y,2)+Math.pow(r.x-o.x,2))}function Me(r,o){return Math.atan2(o.y-r.y,o.x-r.x)*180/Math.PI}function ut(r,o,e,t,n,i,a){i===void 0&&(i=0),a===void 0&&(a=!0);var c=a?lt:ft,p=z(o.width,o.height,i),s=z(o.naturalWidth,o.naturalHeight,i),u={x:c(100,((p.width-e.width/n)/2-r.x/n)/p.width*100),y:c(100,((p.height-e.height/n)/2-r.y/n)/p.height*100),width:c(100,e.width/p.width*100/n),height:c(100,e.height/p.height*100/n)},f=Math.round(c(s.width,u.width*s.width/100)),l=Math.round(c(s.height,u.height*s.height/100)),h=s.width>=s.height*t,m=h?{width:Math.round(l*t),height:l}:{width:f,height:Math.round(f/t)},w=d(d({},m),{x:Math.round(c(s.width-m.width,u.x*s.width/100)),y:Math.round(c(s.height-m.height,u.y*s.height/100))});return{croppedAreaPercentages:u,croppedAreaPixels:w}}function lt(r,o){return Math.min(r,Math.max(0,o))}function ft(r,o){return o}function ht(r,o,e,t,n,i){var a=z(o.width,o.height,e),c=Z(t.width/a.width*(100/r.width),n,i),p={x:c*a.width/2-t.width/2-a.width*c*(r.x/100),y:c*a.height/2-t.height/2-a.height*c*(r.y/100)};return{crop:p,zoom:c}}function dt(r,o,e){var t=pt(o);return e.height>e.width?e.height/(r.height*t):e.width/(r.width*t)}function mt(r,o,e,t,n,i){e===void 0&&(e=0);var a=z(o.naturalWidth,o.naturalHeight,e),c=Z(dt(r,o,t),n,i),p=t.height>t.width?t.height/r.height:t.width/r.width,s={x:((a.width-r.width)/2-r.x)*p,y:((a.height-r.height)/2-r.y)*p};return{crop:s,zoom:c}}function Oe(r,o){return{x:(o.x+r.x)/2,y:(o.y+r.y)/2}}function gt(r){return r*Math.PI/180}function z(r,o,e){var t=gt(e);return{width:Math.abs(Math.cos(t)*r)+Math.abs(Math.sin(t)*o),height:Math.abs(Math.sin(t)*r)+Math.abs(Math.cos(t)*o)}}function Z(r,o,e){return Math.min(Math.max(r,o),e)}function F(){for(var r=[],o=0;o<arguments.length;o++)r[o]=arguments[o];return r.filter(function(e){return typeof e=="string"&&e.length>0}).join(" ").trim()}var vt=`.reactEasyCrop_Container {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  overflow: hidden;
  user-select: none;
  touch-action: none;
  cursor: move;
  display: flex;
  justify-content: center;
  align-items: center;
}

.reactEasyCrop_Image,
.reactEasyCrop_Video {
  will-change: transform; /* this improves performances and prevent painting issues on iOS Chrome */
}

.reactEasyCrop_Contain {
  max-width: 100%;
  max-height: 100%;
  margin: auto;
  position: absolute;
  top: 0;
  bottom: 0;
  left: 0;
  right: 0;
}
.reactEasyCrop_Cover_Horizontal {
  width: 100%;
  height: auto;
}
.reactEasyCrop_Cover_Vertical {
  width: auto;
  height: 100%;
}

.reactEasyCrop_CropArea {
  position: absolute;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
  border: 1px solid rgba(255, 255, 255, 0.5);
  box-sizing: border-box;
  box-shadow: 0 0 0 9999em;
  color: rgba(0, 0, 0, 0.5);
  overflow: hidden;
}

.reactEasyCrop_CropAreaRound {
  border-radius: 50%;
}

.reactEasyCrop_CropAreaGrid::before {
  content: ' ';
  box-sizing: border-box;
  position: absolute;
  border: 1px solid rgba(255, 255, 255, 0.5);
  top: 0;
  bottom: 0;
  left: 33.33%;
  right: 33.33%;
  border-top: 0;
  border-bottom: 0;
}

.reactEasyCrop_CropAreaGrid::after {
  content: ' ';
  box-sizing: border-box;
  position: absolute;
  border: 1px solid rgba(255, 255, 255, 0.5);
  top: 33.33%;
  bottom: 33.33%;
  left: 0;
  right: 0;
  border-left: 0;
  border-right: 0;
}
`,yt=1,wt=3,Ct=1,Te=(function(r){ie(o,r);function o(){var e=r!==null&&r.apply(this,arguments)||this;return e.cropperRef=y.createRef(),e.imageRef=y.createRef(),e.videoRef=y.createRef(),e.containerPosition={x:0,y:0},e.containerRef=null,e.styleRef=null,e.containerRect=null,e.mediaSize={width:0,height:0,naturalWidth:0,naturalHeight:0},e.dragStartPosition={x:0,y:0},e.dragStartCrop={x:0,y:0},e.gestureZoomStart=0,e.gestureRotationStart=0,e.isTouching=!1,e.lastPinchDistance=0,e.lastPinchRotation=0,e.rafDragTimeout=null,e.rafPinchTimeout=null,e.wheelTimer=null,e.currentDoc=typeof document<"u"?document:null,e.currentWindow=typeof window<"u"?window:null,e.resizeObserver=null,e.previousCropSize=null,e.isInitialized=!1,e.state={cropSize:null,hasWheelJustStarted:!1,mediaObjectFit:void 0},e.initResizeObserver=function(){if(!(typeof window.ResizeObserver>"u"||!e.containerRef)){var t=!0;e.resizeObserver=new window.ResizeObserver(function(n){if(t){t=!1;return}e.computeSizes()}),e.resizeObserver.observe(e.containerRef)}},e.preventZoomSafari=function(t){return t.preventDefault()},e.cleanEvents=function(){e.currentDoc&&(e.currentDoc.removeEventListener("mousemove",e.onMouseMove),e.currentDoc.removeEventListener("mouseup",e.onDragStopped),e.currentDoc.removeEventListener("touchmove",e.onTouchMove),e.currentDoc.removeEventListener("touchend",e.onDragStopped),e.currentDoc.removeEventListener("gesturechange",e.onGestureChange),e.currentDoc.removeEventListener("gestureend",e.onGestureEnd),e.currentDoc.removeEventListener("scroll",e.onScroll))},e.clearScrollEvent=function(){e.containerRef&&e.containerRef.removeEventListener("wheel",e.onWheel),e.wheelTimer&&clearTimeout(e.wheelTimer)},e.onMediaLoad=function(){var t=e.computeSizes();t&&(e.previousCropSize=t,e.emitCropData(),e.setInitialCrop(t),e.isInitialized=!0),e.props.onMediaLoaded&&e.props.onMediaLoaded(e.mediaSize)},e.setInitialCrop=function(t){if(e.props.initialCroppedAreaPercentages){var n=ht(e.props.initialCroppedAreaPercentages,e.mediaSize,e.props.rotation,t,e.props.minZoom,e.props.maxZoom),i=n.crop,a=n.zoom;e.props.onCropChange(i),e.props.onZoomChange&&e.props.onZoomChange(a)}else if(e.props.initialCroppedAreaPixels){var c=mt(e.props.initialCroppedAreaPixels,e.mediaSize,e.props.rotation,t,e.props.minZoom,e.props.maxZoom),i=c.crop,a=c.zoom;e.props.onCropChange(i),e.props.onZoomChange&&e.props.onZoomChange(a)}},e.computeSizes=function(){var t,n,i,a,c,p,s=e.imageRef.current||e.videoRef.current;if(s&&e.containerRef){e.containerRect=e.containerRef.getBoundingClientRect(),e.saveContainerPosition();var u=e.containerRect.width/e.containerRect.height,f=((t=e.imageRef.current)===null||t===void 0?void 0:t.naturalWidth)||((n=e.videoRef.current)===null||n===void 0?void 0:n.videoWidth)||0,l=((i=e.imageRef.current)===null||i===void 0?void 0:i.naturalHeight)||((a=e.videoRef.current)===null||a===void 0?void 0:a.videoHeight)||0,h=s.offsetWidth<f||s.offsetHeight<l,m=f/l,w=void 0;if(h)switch(e.state.mediaObjectFit){default:case"contain":w=u>m?{width:e.containerRect.height*m,height:e.containerRect.height}:{width:e.containerRect.width,height:e.containerRect.width/m};break;case"horizontal-cover":w={width:e.containerRect.width,height:e.containerRect.width/m};break;case"vertical-cover":w={width:e.containerRect.height*m,height:e.containerRect.height};break}else w={width:s.offsetWidth,height:s.offsetHeight};e.mediaSize=d(d({},w),{naturalWidth:f,naturalHeight:l}),e.props.setMediaSize&&e.props.setMediaSize(e.mediaSize);var b=e.props.cropSize?e.props.cropSize:ct(e.mediaSize.width,e.mediaSize.height,e.containerRect.width,e.containerRect.height,e.props.aspect,e.props.rotation);return(((c=e.state.cropSize)===null||c===void 0?void 0:c.height)!==b.height||((p=e.state.cropSize)===null||p===void 0?void 0:p.width)!==b.width)&&e.props.onCropSizeChange&&e.props.onCropSizeChange(b),e.setState({cropSize:b},e.recomputeCropPosition),e.props.setCropSize&&e.props.setCropSize(b),b}},e.saveContainerPosition=function(){if(e.containerRef){var t=e.containerRef.getBoundingClientRect();e.containerPosition={x:t.left,y:t.top}}},e.onMouseDown=function(t){e.currentDoc&&(t.preventDefault(),e.currentDoc.addEventListener("mousemove",e.onMouseMove),e.currentDoc.addEventListener("mouseup",e.onDragStopped),e.saveContainerPosition(),e.onDragStart(o.getMousePoint(t)))},e.onMouseMove=function(t){return e.onDrag(o.getMousePoint(t))},e.onScroll=function(t){e.currentDoc&&(t.preventDefault(),e.saveContainerPosition())},e.onTouchStart=function(t){e.currentDoc&&(e.isTouching=!0,!(e.props.onTouchRequest&&!e.props.onTouchRequest(t))&&(e.currentDoc.addEventListener("touchmove",e.onTouchMove,{passive:!1}),e.currentDoc.addEventListener("touchend",e.onDragStopped),e.saveContainerPosition(),t.touches.length===2?e.onPinchStart(t):t.touches.length===1&&e.onDragStart(o.getTouchPoint(t.touches[0]))))},e.onTouchMove=function(t){t.preventDefault(),t.touches.length===2?e.onPinchMove(t):t.touches.length===1&&e.onDrag(o.getTouchPoint(t.touches[0]))},e.onGestureStart=function(t){e.currentDoc&&(t.preventDefault(),e.currentDoc.addEventListener("gesturechange",e.onGestureChange),e.currentDoc.addEventListener("gestureend",e.onGestureEnd),e.gestureZoomStart=e.props.zoom,e.gestureRotationStart=e.props.rotation)},e.onGestureChange=function(t){if(t.preventDefault(),!e.isTouching){var n=o.getMousePoint(t),i=e.gestureZoomStart-1+t.scale;if(e.setNewZoom(i,n,{shouldUpdatePosition:!0}),e.props.onRotationChange){var a=e.gestureRotationStart+t.rotation;e.props.onRotationChange(a)}}},e.onGestureEnd=function(t){e.cleanEvents()},e.onDragStart=function(t){var n,i,a=t.x,c=t.y;e.dragStartPosition={x:a,y:c},e.dragStartCrop=d({},e.props.crop),(i=(n=e.props).onInteractionStart)===null||i===void 0||i.call(n)},e.onDrag=function(t){var n=t.x,i=t.y;e.currentWindow&&(e.rafDragTimeout&&e.currentWindow.cancelAnimationFrame(e.rafDragTimeout),e.rafDragTimeout=e.currentWindow.requestAnimationFrame(function(){if(e.state.cropSize&&!(n===void 0||i===void 0)){var a=n-e.dragStartPosition.x,c=i-e.dragStartPosition.y,p={x:e.dragStartCrop.x+a,y:e.dragStartCrop.y+c},s=e.props.restrictPosition?M(p,e.mediaSize,e.state.cropSize,e.props.zoom,e.props.rotation):p;e.props.onCropChange(s)}}))},e.onDragStopped=function(){var t,n;e.isTouching=!1,e.cleanEvents(),e.emitCropData(),(n=(t=e.props).onInteractionEnd)===null||n===void 0||n.call(t)},e.onWheel=function(t){if(e.currentWindow&&!(e.props.onWheelRequest&&!e.props.onWheelRequest(t))){t.preventDefault();var n=o.getMousePoint(t),i=(0,Ie.default)(t).pixelY,a=e.props.zoom-i*e.props.zoomSpeed/200;e.setNewZoom(a,n,{shouldUpdatePosition:!0}),e.state.hasWheelJustStarted||e.setState({hasWheelJustStarted:!0},function(){var c,p;return(p=(c=e.props).onInteractionStart)===null||p===void 0?void 0:p.call(c)}),e.wheelTimer&&clearTimeout(e.wheelTimer),e.wheelTimer=e.currentWindow.setTimeout(function(){return e.setState({hasWheelJustStarted:!1},function(){var c,p;return(p=(c=e.props).onInteractionEnd)===null||p===void 0?void 0:p.call(c)})},250)}},e.getPointOnContainer=function(t,n){var i=t.x,a=t.y;if(!e.containerRect)throw new Error("The Cropper is not mounted");return{x:e.containerRect.width/2-(i-n.x),y:e.containerRect.height/2-(a-n.y)}},e.getPointOnMedia=function(t){var n=t.x,i=t.y,a=e.props,c=a.crop,p=a.zoom;return{x:(n+c.x)/p,y:(i+c.y)/p}},e.setNewZoom=function(t,n,i){var a=i===void 0?{}:i,c=a.shouldUpdatePosition,p=c===void 0?!0:c;if(!(!e.state.cropSize||!e.props.onZoomChange)){var s=Z(t,e.props.minZoom,e.props.maxZoom);if(p){var u=e.getPointOnContainer(n,e.containerPosition),f=e.getPointOnMedia(u),l={x:f.x*s-u.x,y:f.y*s-u.y},h=e.props.restrictPosition?M(l,e.mediaSize,e.state.cropSize,s,e.props.rotation):l;e.props.onCropChange(h)}e.props.onZoomChange(s)}},e.getCropData=function(){if(!e.state.cropSize)return null;var t=e.props.restrictPosition?M(e.props.crop,e.mediaSize,e.state.cropSize,e.props.zoom,e.props.rotation):e.props.crop;return ut(t,e.mediaSize,e.state.cropSize,e.getAspect(),e.props.zoom,e.props.rotation,e.props.restrictPosition)},e.emitCropData=function(){var t=e.getCropData();if(t){var n=t.croppedAreaPercentages,i=t.croppedAreaPixels;e.props.onCropComplete&&e.props.onCropComplete(n,i),e.props.onCropAreaChange&&e.props.onCropAreaChange(n,i)}},e.emitCropAreaChange=function(){var t=e.getCropData();if(t){var n=t.croppedAreaPercentages,i=t.croppedAreaPixels;e.props.onCropAreaChange&&e.props.onCropAreaChange(n,i)}},e.recomputeCropPosition=function(){var t,n;if(e.state.cropSize){var i=e.props.crop;if(e.isInitialized&&(!((t=e.previousCropSize)===null||t===void 0)&&t.width)&&(!((n=e.previousCropSize)===null||n===void 0)&&n.height)){var a=Math.abs(e.previousCropSize.width-e.state.cropSize.width)>1e-6||Math.abs(e.previousCropSize.height-e.state.cropSize.height)>1e-6;if(a){var c=e.state.cropSize.width/e.previousCropSize.width,p=e.state.cropSize.height/e.previousCropSize.height;i={x:e.props.crop.x*c,y:e.props.crop.y*p}}}var s=e.props.restrictPosition?M(i,e.mediaSize,e.state.cropSize,e.props.zoom,e.props.rotation):i;e.previousCropSize=e.state.cropSize,e.props.onCropChange(s),e.emitCropData()}},e.onKeyDown=function(t){var n,i,a=e.props,c=a.crop,p=a.onCropChange,s=a.keyboardStep,u=a.zoom,f=a.rotation,l=s;if(e.state.cropSize){t.shiftKey&&(l*=.2);var h=d({},c);switch(t.key){case"ArrowUp":h.y-=l,t.preventDefault();break;case"ArrowDown":h.y+=l,t.preventDefault();break;case"ArrowLeft":h.x-=l,t.preventDefault();break;case"ArrowRight":h.x+=l,t.preventDefault();break;default:return}e.props.restrictPosition&&(h=M(h,e.mediaSize,e.state.cropSize,u,f)),t.repeat||(i=(n=e.props).onInteractionStart)===null||i===void 0||i.call(n),p(h)}},e.onKeyUp=function(t){var n,i;switch(t.key){case"ArrowUp":case"ArrowDown":case"ArrowLeft":case"ArrowRight":t.preventDefault();break;default:return}e.emitCropData(),(i=(n=e.props).onInteractionEnd)===null||i===void 0||i.call(n)},e}return o.prototype.componentDidMount=function(){!this.currentDoc||!this.currentWindow||(this.containerRef&&(this.containerRef.ownerDocument&&(this.currentDoc=this.containerRef.ownerDocument),this.currentDoc.defaultView&&(this.currentWindow=this.currentDoc.defaultView),this.initResizeObserver(),typeof window.ResizeObserver>"u"&&this.currentWindow.addEventListener("resize",this.computeSizes),this.props.zoomWithScroll&&this.containerRef.addEventListener("wheel",this.onWheel,{passive:!1}),this.containerRef.addEventListener("gesturestart",this.onGestureStart)),this.currentDoc.addEventListener("scroll",this.onScroll),this.props.disableAutomaticStylesInjection||(this.styleRef=this.currentDoc.createElement("style"),this.styleRef.setAttribute("type","text/css"),this.props.nonce&&this.styleRef.setAttribute("nonce",this.props.nonce),this.styleRef.innerHTML=vt,this.currentDoc.head.appendChild(this.styleRef)),this.imageRef.current&&this.imageRef.current.complete&&this.onMediaLoad(),this.props.setImageRef&&this.props.setImageRef(this.imageRef),this.props.setVideoRef&&this.props.setVideoRef(this.videoRef),this.props.setCropperRef&&this.props.setCropperRef(this.cropperRef))},o.prototype.componentWillUnmount=function(){var e,t;!this.currentDoc||!this.currentWindow||(typeof window.ResizeObserver>"u"&&this.currentWindow.removeEventListener("resize",this.computeSizes),(e=this.resizeObserver)===null||e===void 0||e.disconnect(),this.containerRef&&this.containerRef.removeEventListener("gesturestart",this.preventZoomSafari),this.styleRef&&((t=this.styleRef.parentNode)===null||t===void 0||t.removeChild(this.styleRef)),this.cleanEvents(),this.props.zoomWithScroll&&this.clearScrollEvent())},o.prototype.componentDidUpdate=function(e){var t,n,i,a,c,p,s,u,f;e.rotation!==this.props.rotation?(this.computeSizes(),this.recomputeCropPosition()):e.aspect!==this.props.aspect?this.computeSizes():e.objectFit!==this.props.objectFit?this.computeSizes():e.zoom!==this.props.zoom?this.recomputeCropPosition():((t=e.cropSize)===null||t===void 0?void 0:t.height)!==((n=this.props.cropSize)===null||n===void 0?void 0:n.height)||((i=e.cropSize)===null||i===void 0?void 0:i.width)!==((a=this.props.cropSize)===null||a===void 0?void 0:a.width)?this.computeSizes():(((c=e.crop)===null||c===void 0?void 0:c.x)!==((p=this.props.crop)===null||p===void 0?void 0:p.x)||((s=e.crop)===null||s===void 0?void 0:s.y)!==((u=this.props.crop)===null||u===void 0?void 0:u.y))&&this.emitCropAreaChange(),e.zoomWithScroll!==this.props.zoomWithScroll&&this.containerRef&&(this.props.zoomWithScroll?this.containerRef.addEventListener("wheel",this.onWheel,{passive:!1}):this.clearScrollEvent()),e.video!==this.props.video&&((f=this.videoRef.current)===null||f===void 0||f.load());var l=this.getObjectFit();l!==this.state.mediaObjectFit&&this.setState({mediaObjectFit:l},this.computeSizes)},o.prototype.getAspect=function(){var e=this.props,t=e.cropSize,n=e.aspect;return t?t.width/t.height:n},o.prototype.getObjectFit=function(){var e,t,n,i;if(this.props.objectFit==="cover"){var a=this.imageRef.current||this.videoRef.current;if(a&&this.containerRef){this.containerRect=this.containerRef.getBoundingClientRect();var c=this.containerRect.width/this.containerRect.height,p=((e=this.imageRef.current)===null||e===void 0?void 0:e.naturalWidth)||((t=this.videoRef.current)===null||t===void 0?void 0:t.videoWidth)||0,s=((n=this.imageRef.current)===null||n===void 0?void 0:n.naturalHeight)||((i=this.videoRef.current)===null||i===void 0?void 0:i.videoHeight)||0,u=p/s;return u<c?"horizontal-cover":"vertical-cover"}return"horizontal-cover"}return this.props.objectFit},o.prototype.onPinchStart=function(e){var t=o.getTouchPoint(e.touches[0]),n=o.getTouchPoint(e.touches[1]);this.lastPinchDistance=Ae(t,n),this.lastPinchRotation=Me(t,n),this.onDragStart(Oe(t,n))},o.prototype.onPinchMove=function(e){var t=this;if(!(!this.currentDoc||!this.currentWindow)){var n=o.getTouchPoint(e.touches[0]),i=o.getTouchPoint(e.touches[1]),a=Oe(n,i);this.onDrag(a),this.rafPinchTimeout&&this.currentWindow.cancelAnimationFrame(this.rafPinchTimeout),this.rafPinchTimeout=this.currentWindow.requestAnimationFrame(function(){var c=Ae(n,i),p=t.props.zoom*(c/t.lastPinchDistance);t.setNewZoom(p,a,{shouldUpdatePosition:!1}),t.lastPinchDistance=c;var s=Me(n,i),u=t.props.rotation+(s-t.lastPinchRotation);t.props.onRotationChange&&t.props.onRotationChange(u),t.lastPinchRotation=s})}},o.prototype.render=function(){var e=this,t,n=this.props,i=n.image,a=n.video,c=n.mediaProps,p=n.cropperProps,s=n.transform,u=n.crop,f=u.x,l=u.y,h=n.rotation,m=n.zoom,w=n.cropShape,b=n.showGrid,g=n.roundCropAreaPixels,A=n.style,qe=A.containerStyle,Ye=A.cropAreaStyle,re=A.mediaStyle,G=n.classes,ke=G.containerClassName,Ke=G.cropAreaClassName,oe=G.mediaClassName,E=(t=this.state.mediaObjectFit)!==null&&t!==void 0?t:this.getObjectFit();return y.createElement("div",{onMouseDown:this.onMouseDown,onTouchStart:this.onTouchStart,ref:function($e){return e.containerRef=$e},"data-testid":"container",style:qe,className:F("reactEasyCrop_Container",ke)},i?y.createElement("img",d({alt:"",className:F("reactEasyCrop_Image",E==="contain"&&"reactEasyCrop_Contain",E==="horizontal-cover"&&"reactEasyCrop_Cover_Horizontal",E==="vertical-cover"&&"reactEasyCrop_Cover_Vertical",oe)},c,{src:i,ref:this.imageRef,style:d(d({},re),{transform:s||"translate(".concat(f,"px, ").concat(l,"px) rotate(").concat(h,"deg) scale(").concat(m,")")}),onLoad:this.onMediaLoad})):a&&y.createElement("video",d({autoPlay:!0,playsInline:!0,loop:!0,muted:!0,className:F("reactEasyCrop_Video",E==="contain"&&"reactEasyCrop_Contain",E==="horizontal-cover"&&"reactEasyCrop_Cover_Horizontal",E==="vertical-cover"&&"reactEasyCrop_Cover_Vertical",oe)},c,{ref:this.videoRef,onLoadedMetadata:this.onMediaLoad,style:d(d({},re),{transform:s||"translate(".concat(f,"px, ").concat(l,"px) rotate(").concat(h,"deg) scale(").concat(m,")")}),controls:!1}),(Array.isArray(a)?a:[{src:a}]).map(function(H){return y.createElement("source",d({key:H.src},H))})),this.state.cropSize&&y.createElement("div",d({ref:this.cropperRef,style:d(d({},Ye),{width:g?Math.round(this.state.cropSize.width):this.state.cropSize.width,height:g?Math.round(this.state.cropSize.height):this.state.cropSize.height}),tabIndex:0,onKeyDown:this.onKeyDown,onKeyUp:this.onKeyUp,"data-testid":"cropper",className:F("reactEasyCrop_CropArea",w==="round"&&"reactEasyCrop_CropAreaRound",b&&"reactEasyCrop_CropAreaGrid",Ke)},p)))},o.defaultProps={zoom:1,rotation:0,aspect:4/3,maxZoom:wt,minZoom:yt,cropShape:"rect",objectFit:"contain",showGrid:!0,style:{},classes:{},mediaProps:{},cropperProps:{},zoomSpeed:1,restrictPosition:!0,zoomWithScroll:!0,keyboardStep:Ct},o.getMousePoint=function(e){return{x:Number(e.clientX),y:Number(e.clientY)}},o.getTouchPoint=function(e){return{x:Number(e.clientX),y:Number(e.clientY)}},o})(y.Component);var Be=_(U(),1);var D=_(U(),1);var Le=Object.prototype.hasOwnProperty;function Ne(r,o,e){for(e of r.keys())if(R(e,o))return e}function R(r,o){var e,t,n;if(r===o)return!0;if(r&&o&&(e=r.constructor)===o.constructor){if(e===Date)return r.getTime()===o.getTime();if(e===RegExp)return r.toString()===o.toString();if(e===Array){if((t=r.length)===o.length)for(;t--&&R(r[t],o[t]););return t===-1}if(e===Set){if(r.size!==o.size)return!1;for(t of r)if(n=t,n&&typeof n=="object"&&(n=Ne(o,n),!n)||!o.has(n))return!1;return!0}if(e===Map){if(r.size!==o.size)return!1;for(t of r)if(n=t[0],n&&typeof n=="object"&&(n=Ne(o,n),!n)||!R(t[1],o.get(n)))return!1;return!0}if(e===ArrayBuffer)r=new Uint8Array(r),o=new Uint8Array(o);else if(e===DataView){if((t=r.byteLength)===o.byteLength)for(;t--&&r.getInt8(t)===o.getInt8(t););return t===-1}if(ArrayBuffer.isView(r)){if((t=r.byteLength)===o.byteLength)for(;t--&&r[t]===o[t];);return t===-1}if(!e||typeof r=="object"){t=0;for(e in r)if(Le.call(r,e)&&++t&&!Le.call(o,e)||!(e in o)||!R(r[e],o[e]))return!1;return Object.keys(o).length===t}}return r!==r&&o!==o}var C=_(U(),1);var O=r=>r>=0?r%360:(360+r%360)%360,bt=r=>new Promise((o,e)=>{let t=new Image;t.addEventListener("load",()=>o(t)),t.addEventListener("error",n=>e(n)),t.setAttribute("crossOrigin","anonymous"),t.src=r});function We(r){return r*Math.PI/180}function _t(r,o,e){let t=We(e);return{width:Math.abs(Math.cos(t)*r)+Math.abs(Math.sin(t)*o),height:Math.abs(Math.sin(t)*r)+Math.abs(Math.cos(t)*o)}}async function Fe(r,o,e=0,t={horizontal:!1,vertical:!1}){try{let n=await bt(r),i=document.createElement("canvas"),a=i.getContext("2d");if(!a)return null;let c=We(e),{width:p,height:s}=_t(n.width,n.height,e);i.width=p,i.height=s,a.translate(p/2,s/2),a.rotate(c),a.scale(t.horizontal?-1:1,t.vertical?-1:1),a.translate(-n.width/2,-n.height/2),a.drawImage(n,0,0);let u=document.createElement("canvas"),f=u.getContext("2d");return f?(u.width=o.width,u.height=o.height,f.drawImage(i,o.x,o.y,o.width,o.height,0,0,o.width,o.height),new Promise(l=>{u.toBlob(h=>{h&&l(URL.createObjectURL(h))},"image/jpeg")})):null}catch{return null}}var P={crop:{x:0,y:0,width:100,height:100},zoom:1,rotation:0,aspectRatio:1,flip:{horizontal:!1,vertical:!1}},xt={x:0,y:0},Q={crop:xt,croppedArea:P.crop,croppedAreaPixels:null,zoom:P.zoom,rotation:P.rotation,flip:P.flip,aspectRatio:P.aspectRatio,mediaSize:null};function ee(){let[r,o]=(0,C.useState)(Q),[e,t]=(0,C.useState)(null),n=(0,C.useCallback)(s=>{o(u=>{let l={...typeof s=="function"?s(u):s};return"rotation"in l&&l.rotation!==void 0&&(l.rotation=O(l.rotation)),{...u,...l}})},[]),i=(0,C.useCallback)((s=null)=>{if(!s){t(null),n(Q);return}if(typeof s=="object"){let u={...P,...s};t(u),n(u)}},[n,t]),a=(0,C.useCallback)(()=>{if(e){let s={crop:{x:0,y:0},croppedAreaPixels:null};e.crop&&(s.croppedArea=e.crop),e.zoom!==void 0&&(s.zoom=e.zoom),e.rotation!==void 0&&(s.rotation=e.rotation),e.aspectRatio!==void 0&&(s.aspectRatio=e.aspectRatio),e.flip!==void 0&&(s.flip=e.flip),n(s)}else n({...Q})},[e,n]),c=(0,C.useMemo)(()=>{if(e){let u={crop:r.croppedAreaPixels||r.croppedArea,zoom:r.zoom,rotation:O(r.rotation),aspectRatio:r.aspectRatio,flip:r.flip};return R(u,e)===!1}let s={crop:r.croppedArea,zoom:r.zoom,rotation:O(r.rotation),aspectRatio:r.aspectRatio,flip:r.flip};return R(s,P)===!1},[r,e]),p=(0,C.useCallback)(async s=>r.croppedAreaPixels?Fe(s,r.croppedAreaPixels,r.rotation,r.flip):null,[r.croppedAreaPixels,r.rotation,r.flip]);return(0,C.useMemo)(()=>({cropperState:r,setCropperState:n,resetState:e,setResetState:i,isDirty:c,reset:a,getCroppedImage:p}),[r,n,e,i,c,a,p])}var He=_(T(),1),Ue=(0,D.createContext)({cropperState:{crop:{x:0,y:0},croppedArea:{x:0,y:0,width:100,height:100},croppedAreaPixels:null,zoom:1,rotation:0,aspectRatio:1,flip:{horizontal:!1,vertical:!1},mediaSize:null},setCropperState:()=>{},resetState:null,setResetState:()=>{},isDirty:!1,reset:()=>{},getCroppedImage:()=>Promise.resolve(null)});function Ge({children:r}){let o=ee(),e=(0,D.useMemo)(()=>({...o}),[o]);return(0,He.jsx)(Ue.Provider,{value:e,children:r})}var te=()=>{let r=(0,D.useContext)(Ue);if(!r)throw new Error("Missing ImageCropperContext");return r};if(!document.head.querySelector("style[data-wp-hash='5307b02497']")){let r=document.createElement("style");r.setAttribute("data-wp-hash","5307b02497"),r.appendChild(document.createTextNode(`.reactEasyCrop_Container {
  user-select: none;
  touch-action: none;
  cursor: move;
  justify-content: center;
  align-items: center;
  display: flex;
  position: absolute;
  inset: 0;
  overflow: hidden;
}

.reactEasyCrop_Image, .reactEasyCrop_Video {
  will-change: transform;
}

.reactEasyCrop_Contain {
  max-width: 100%;
  max-height: 100%;
  margin: auto;
  position: absolute;
  inset: 0;
}

.reactEasyCrop_Cover_Horizontal {
  width: 100%;
  height: auto;
}

.reactEasyCrop_Cover_Vertical {
  width: auto;
  height: 100%;
}

.reactEasyCrop_CropArea {
  box-sizing: border-box;
  color: #00000080;
  border: 1px solid #ffffff80;
  position: absolute;
  top: 50%;
  left: 50%;
  overflow: hidden;
  transform: translate(-50%, -50%);
  box-shadow: 0 0 0 9999em;
}

.reactEasyCrop_CropAreaRound {
  border-radius: 50%;
}

.reactEasyCrop_CropAreaGrid:before {
  content: " ";
  box-sizing: border-box;
  border: 0;
  border-left: 1px solid #ffffff80;
  border-right: 1px solid #ffffff80;
  position: absolute;
  inset: 0 33.33%;
}

.reactEasyCrop_CropAreaGrid:after {
  content: " ";
  box-sizing: border-box;
  border: 1px solid #ffffff80;
  border-left: 0;
  border-right: 0;
  position: absolute;
  inset: 33.33% 0;
}

.image-cropper__container {
  overflow: visible;
}
`)),document.head.appendChild(r)}var Xe=_(T(),1);function Ve({src:r,onLoad:o,minZoom:e=1,maxZoom:t=5,...n}){let{cropperState:i,setCropperState:a}=te(),{crop:c,zoom:p,rotation:s,aspectRatio:u,flip:f}=i,l=g=>a({crop:g}),h=g=>a({zoom:g}),m=g=>a({rotation:g}),w=g=>a({mediaSize:g}),b=(0,Be.useCallback)((g,A)=>{a({croppedArea:g,croppedAreaPixels:A})},[a]);return(0,Xe.jsx)(Te,{classes:{containerClassName:"image-cropper__container",cropAreaClassName:"image-cropper__crop-area",mediaClassName:"image-cropper__image"},disableAutomaticStylesInjection:!0,minZoom:e,maxZoom:t,rotation:s,image:r,setMediaSize:w,crop:c,zoom:p,aspect:u,onCropChange:l,onZoomChange:h,onCropComplete:b,onMediaLoaded:g=>{o?.(g)},onRotationChange:m,transform:[`translate(${c.x}px, ${c.y}px)`,`rotateZ(${s}deg)`,`rotateY(${f.horizontal?180:0}deg)`,`rotateX(${f.vertical?180:0}deg)`,`scale(${p})`].join(" "),...n})}export{Ve as ImageCropper,Ge as ImageCropperProvider,O as normalizeRotation,te as useImageCropper};
/*! Bundled license information:

normalize-wheel/src/isEventSupported.js:
  (**
   * Checks if an event is supported in the current execution environment.
   *
   * NOTE: This will not work correctly for non-generic events such as `change`,
   * `reset`, `load`, `error`, and `select`.
   *
   * Borrows from Modernizr.
   *
   * @param {string} eventNameSuffix Event name, e.g. "click".
   * @param {?boolean} capture Check if the capture phase is supported.
   * @return {boolean} True if the event is supported.
   * @internal
   * @license Modernizr 3.0.0pre (Custom Build) | MIT
   *)
*/
