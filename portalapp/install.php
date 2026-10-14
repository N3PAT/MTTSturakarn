<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#3b1480">
<title>ติดตั้งแอปพลิเคชัน MTTS</title>
<link rel="manifest" href="manifest.json">
<link rel="icon" type="image/png" href="icon-192x192.png">
<link rel="apple-touch-icon" href="icon-192x192.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--v:#5b21b6;--vd:#3b1480;--ink:#1b1033;--mute:#6b6584;--line:#ece8f5;--soft:#f5f1fd;--gold:#f5b301}
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
[hidden]{display:none!important}
html{background:var(--vd)}
body{font-family:'Prompt',system-ui,sans-serif;color:var(--ink);line-height:1.6}
.i{width:1.1em;height:1.1em;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0}

.wrap{max-width:460px;min-height:100vh;min-height:100dvh;margin:0 auto;display:flex;flex-direction:column}

/* ส่วนหัว: แถบทแยงคล้ายแถบยศ + ไอคอนแอปขอบทอง */
.hero{padding:calc(40px + env(safe-area-inset-top)) 24px 60px;text-align:center;color:#fff;
  background:repeating-linear-gradient(135deg,rgba(255,255,255,.055) 0 12px,transparent 12px 24px),linear-gradient(180deg,#5b21b6,var(--vd))}
.icon{width:96px;height:96px;margin:0 auto 18px;padding:3px;border-radius:26px;background:#fff;
  box-shadow:0 0 0 4px var(--gold),0 20px 32px -8px rgba(0,0,0,.5);animation:settle .8s cubic-bezier(.2,1.3,.4,1) both}
.icon img{display:block;width:100%;height:100%;border-radius:23px;object-fit:cover}
h1{font-size:1.4rem;font-weight:700;line-height:1.35}
.ver{display:inline-block;margin-top:8px;padding:2px 14px;border:1px solid rgba(245,179,1,.65);border-radius:999px;color:var(--gold);font-size:.8rem;font-weight:500}
.lead{max-width:300px;margin:14px auto 0;font-size:.9rem;color:rgba(255,255,255,.82)}

/* แผ่นเนื้อหา */
.sheet{flex:1;margin-top:-28px;padding:26px 22px calc(28px + env(safe-area-inset-bottom));background:#fff;border-radius:28px 28px 0 0;animation:rise .6s .15s cubic-bezier(.2,.8,.2,1) both}

.btn{width:100%;display:flex;align-items:center;justify-content:center;gap:10px;padding:16px;border:0;border-radius:16px;background:var(--v);color:#fff;font:inherit;font-size:1.05rem;font-weight:600;text-decoration:none;cursor:pointer;box-shadow:0 10px 22px -8px rgba(91,33,182,.7);transition:transform .15s,background .2s}
.btn:hover{background:#6d33cc}
.btn:active{transform:scale(.98)}
.btn:focus-visible,.tab:focus-visible,summary:focus-visible{outline:3px solid #c4b5fd;outline-offset:3px}

.warn{display:flex;gap:10px;margin-bottom:16px;padding:12px 14px;border-radius:14px;background:#fff8e1;color:#7a5b00;font-size:.86rem}
.warn .i{margin-top:3px}
.done{display:flex;align-items:center;gap:12px;margin-bottom:12px;padding:14px;border-radius:16px;background:#ecfdf5;color:#065f46}
.done .i{width:1.6em;height:1.6em}
.done b{display:block;font-weight:600}
.done span{font-size:.85rem}
#doneLink{margin-top:12px}

/* วิธีติดตั้งด้วยตัวเอง */
.tabs{display:flex;gap:6px;margin-bottom:12px}
.tab{flex:1;padding:9px;border:1.5px solid var(--line);border-radius:12px;background:#fff;color:var(--mute);font:inherit;font-size:.9rem;font-weight:500;cursor:pointer}
.tab[aria-selected=true]{background:var(--soft);border-color:var(--v);color:var(--v);font-weight:600}
.steps{list-style:none;counter-reset:s}
.steps li{counter-increment:s;display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-top:1px solid var(--line);font-size:.92rem}
.steps li:first-child{border-top:0}
.steps li::before{content:counter(s);flex-shrink:0;display:grid;place-items:center;width:26px;height:26px;margin-top:1px;border-radius:50%;background:var(--v);color:#fff;font-size:.8rem;font-weight:600}
.k{display:inline-flex;align-items:center;gap:4px;padding:1px 8px;border-radius:8px;background:var(--soft);color:var(--v);font-weight:600;white-space:nowrap}
.hint{margin-top:8px;padding:10px 12px;border-radius:12px;background:#fff8e1;color:#7a5b00;font-size:.84rem}

.perks{list-style:none;margin-top:22px}
.perks li{display:flex;gap:12px;align-items:center;padding:12px 0;border-top:1px solid var(--line);font-size:.9rem}
.perks .i{width:36px;height:36px;padding:9px;border-radius:10px;background:var(--soft);color:var(--v)}
.perks b{display:block;font-weight:600;line-height:1.4}
.perks span{color:var(--mute);font-size:.82rem}

.log summary{display:flex;justify-content:space-between;align-items:center;padding:14px 0;border-top:1px solid var(--line);font-size:.92rem;font-weight:600;list-style:none;cursor:pointer}
.log summary::-webkit-details-marker{display:none}
.log summary::after{content:"";width:8px;height:8px;border-right:2px solid var(--mute);border-bottom:2px solid var(--mute);transform:rotate(45deg);transition:transform .2s}
.log[open] summary::after{transform:rotate(-135deg)}
.log ul{list-style:none;padding-bottom:6px}
.log li{position:relative;padding:0 0 8px 22px;color:var(--mute);font-size:.86rem;line-height:1.5}
.log li::before{content:"✓";position:absolute;left:0;color:#059669;font-weight:700}

@keyframes settle{from{opacity:0;transform:translateY(-26px) scale(.85) rotate(-6deg)}}
@keyframes rise{from{opacity:0;transform:translateY(40px)}}
@media (prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important}}
</style>
</head>
<body>

<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>
<symbol id="i-down" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></symbol>
<symbol id="i-share" viewBox="0 0 24 24"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></symbol>
<symbol id="i-plus" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></symbol>
<symbol id="i-dots" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></symbol>
<symbol id="i-bell" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></symbol>
<symbol id="i-zap" viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></symbol>
<symbol id="i-phone" viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></symbol>
<symbol id="i-ext" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></symbol>
<symbol id="i-check" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="8 12 11 15 16 9"/></symbol>
</defs></svg>

<div class="wrap">
  <header class="hero">
    <div class="icon"><img src="icon-192x192.png" alt="" width="96" height="96"></div>
    <h1 id="appName">แอปพลิเคชัน พัน นร. 1 - MTTS</h1>
    <span class="ver" id="ver">เว็บแอปพลิเคชัน</span>
    <p class="lead">เปิดจากหน้าจอโฮมได้ทันที และรับการแจ้งเตือนแม้ปิดแอปอยู่</p>
  </header>

  <main class="sheet">
    <div class="warn" id="warn" hidden>
      <svg class="i"><use href="#i-ext"/></svg>
      <div>เปิดลิงก์นี้ด้วย Safari หรือ Chrome จึงจะติดตั้งได้ ถ้าเปิดจากแอปอย่าง LINE ให้แตะเมนู <b>⋯</b> แล้วเลือก "เปิดในเบราว์เซอร์"</div>
    </div>

    <div class="done" id="done" hidden>
      <svg class="i"><use href="#i-check"/></svg>
      <div><b id="doneT"></b><span id="doneS"></span></div>
    </div>
    <a class="btn" id="doneLink" href="main.php" hidden>เข้าใช้งาน</a>

    <button type="button" class="btn" id="pwaBtn" hidden>
      <svg class="i"><use href="#i-down"/></svg> เพิ่มลงหน้าจอโฮม
    </button>

    <section id="guide" hidden>
      <div class="tabs" role="tablist" aria-label="เลือกอุปกรณ์">
        <button type="button" class="tab" id="tab-ios" role="tab" aria-selected="false">iPhone</button>
        <button type="button" class="tab" id="tab-android" role="tab" aria-selected="true">Android</button>
      </div>
      <ol class="steps" id="steps-ios" hidden>
        <li><div>เปิดหน้านี้ด้วย Safari แล้วแตะ <span class="k"><svg class="i"><use href="#i-share"/></svg>แชร์</span> ที่แถบด้านล่าง</div></li>
        <li><div>เลื่อนลงแล้วเลือก <span class="k"><svg class="i"><use href="#i-plus"/></svg>เพิ่มไปยังหน้าจอโฮม</span></div></li>
        <li><div>แตะ <b>เพิ่ม</b> ที่มุมขวาบน แล้วเปิดแอปจากไอคอนบนหน้าจอโฮม</div></li>
      </ol>
      <ol class="steps" id="steps-android">
        <li><div>แตะเมนู <span class="k"><svg class="i"><use href="#i-dots"/></svg></span> มุมขวาบนของ Chrome</div></li>
        <li><div>เลือก <span class="k">ติดตั้งแอป</span> หรือ <span class="k">เพิ่มลงในหน้าจอหลัก</span></div></li>
        <li><div>แตะ <b>ติดตั้ง</b> แล้วเปิดแอปจากไอคอนบนหน้าจอโฮม</div></li>
      </ol>
      <p class="hint" id="iosHint" hidden>บน iPhone ต้องติดตั้งแอปก่อน จึงจะรับการแจ้งเตือนได้</p>
    </section>

    <ul class="perks">
      <li><svg class="i"><use href="#i-bell"/></svg><div><b>แจ้งเตือนทันที</b><span>รู้ประกาศใหม่แม้ปิดแอปอยู่</span></div></li>
      <li><svg class="i"><use href="#i-zap"/></svg><div><b>เปิดเร็ว</b><span>แตะไอคอนบนหน้าจอโฮม ไม่ต้องพิมพ์ลิงก์</span></div></li>
      <li><svg class="i"><use href="#i-phone"/></svg><div><b>ไม่ต้องโหลดจากสโตร์</b><span>ติดตั้งจากหน้านี้ ใช้พื้นที่เครื่องน้อย</span></div></li>
    </ul>

    <details class="log" id="log" hidden>
      <summary>มีอะไรใหม่ในเวอร์ชันนี้</summary>
      <ul id="logList"></ul>
    </details>
  </main>
</div>

<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var ua = navigator.userAgent;
  var isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var inApp = /Line\/|FBAN|FBAV|Instagram|MicroMessenger/i.test(ua);
  var standalone = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
  var btn = $('pwaBtn'), guide = $('guide'), deferred = null;

  function tab(os) {
    ['ios', 'android'].forEach(function (k) {
      $('tab-' + k).setAttribute('aria-selected', String(k === os));
      $('steps-' + k).hidden = (k !== os);
    });
    $('iosHint').hidden = (os !== 'ios');
  }
  $('tab-ios').onclick = function () { tab('ios'); };
  $('tab-android').onclick = function () { tab('android'); };

  function showDone(title, sub, link) {
    btn.hidden = true; guide.hidden = true;
    $('doneT').textContent = title; $('doneS').textContent = sub;
    $('doneLink').hidden = !link; $('done').hidden = false;
  }

  /* Android Chrome: ติดตั้งได้ด้วยปุ่มเดียว */
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault(); deferred = e;
    btn.hidden = false; guide.hidden = true;
  });
  btn.addEventListener('click', function () {
    if (!deferred) return;
    deferred.prompt();
    deferred.userChoice.then(function (c) {
      deferred = null; btn.hidden = true;
      if (c.outcome !== 'accepted') guide.hidden = false;   // ปฏิเสธแล้ว: แสดงวิธีติดตั้งเอง
    });
  });
  window.addEventListener('appinstalled', function () {
    showDone('ติดตั้งแล้ว', 'เปิดแอปจากไอคอนบนหน้าจอโฮมได้เลย', false);
  });

  tab(isIOS ? 'ios' : 'android');
  if (standalone) {
    showDone('ใช้งานผ่านแอปอยู่แล้ว', 'พร้อมใช้งานและรับการแจ้งเตือน', true);
  } else if (inApp) {
    $('warn').hidden = false;
  } else {
    setTimeout(function () { if (!deferred && $('done').hidden) guide.hidden = false; }, 1000);
  }

  /* ชื่อแอป เวอร์ชัน และรายการอัปเดต */
  fetch('api/api_version.php').then(function (r) {
    if (!r.ok) throw new Error('http');
    return r.json();
  }).then(function (d) {
    if (d.appName) $('appName').textContent = d.appName;
    if (d.version) $('ver').textContent = 'เวอร์ชัน ' + d.version;
    if (d.changelog && d.changelog.length) {
      d.changelog.forEach(function (t) {
        var li = document.createElement('li'); li.textContent = t; $('logList').appendChild(li);
      });
      $('log').hidden = false;
    }
  }).catch(function () {});
})();
</script>
</body>
</html>
