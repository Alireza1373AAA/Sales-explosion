(function(){
if(!window.EFPX) return;

if(Math.random()*100 > (EFPX.sampleRate || 45)) return;

let sid = localStorage.getItem("efpx_sid");
if(!sid){
  sid = "efpx_" + Math.random().toString(36).slice(2) + Date.now();
  localStorage.setItem("efpx_sid", sid);
}

let start = Date.now();
let device = window.innerWidth < 768 ? "mobile" : (window.innerWidth < 1024 ? "tablet" : "desktop");
let lastScroll = 0;

function send(type, text, tag, x, y){
  let fd = new FormData();
  fd.append("action","efpx_track");
  fd.append("nonce",EFPX.nonce);
  fd.append("sid",sid);
  fd.append("url",location.href);
  fd.append("title",document.title);
  fd.append("ref",document.referrer || "");
  fd.append("device",device);
  fd.append("type",type);
  fd.append("text",(text || "").substring(0,140));
  fd.append("tag",tag || "");
  fd.append("x",x || 0);
  fd.append("y",y || 0);
  fd.append("scroll",window.scrollY || 0);
  fd.append("duration",Math.round((Date.now()-start)/1000));

  if(navigator.sendBeacon){
    navigator.sendBeacon(EFPX.ajax, fd);
  } else {
    fetch(EFPX.ajax,{method:"POST",body:fd,credentials:"same-origin"}).catch(function(){});
  }
}

document.addEventListener("DOMContentLoaded", function(){
  send("view","", "", 0, 0);
});

document.addEventListener("click", function(e){
  let el = e.target.closest("a,button,img,input,[role=button],.single_add_to_cart_button,.add_to_cart_button");
  if(!el) return;

  let text = (el.innerText || el.value || el.alt || el.getAttribute("aria-label") || "").trim();
  if(!text && el.tagName === "IMG") text = "تصویر محصول";

  send("click", text, el.tagName || "", e.clientX, e.clientY);
}, {passive:true});

window.addEventListener("scroll", function(){
  let now = Date.now();
  if(now - lastScroll < 2500) return;
  lastScroll = now;
  send("scroll","", "", 0, 0);
}, {passive:true});

window.addEventListener("beforeunload", function(){
  send("exit","", "", 0, 0);
});
})();