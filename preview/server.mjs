import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT_DIR = path.resolve(__dirname, '..');
const PORT = Number(process.env.PORT || 3000);

const DOWNLOADABLE_FILES = {
	'ai-site-commander.zip': path.join(ROOT_DIR, 'releases', 'ai-site-commander.zip'),
	'ai-gsc-autopilot.zip': path.join(ROOT_DIR, 'releases', 'ai-gsc-autopilot.zip'),
	'mcp-server.zip': path.join(ROOT_DIR, 'releases', 'mcp-server.zip'),
};

const HTML_PAGE = `<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>مجموعه دو افزونه هوشمند وردپرس و اتوپایلوت سرچ کنسول</title>
<style>
  :root {
    --bg: #0b1120;
    --card: #111827;
    --card-2: #1e293b;
    --border: #334155;
    --text: #f8fafc;
    --muted: #94a3b8;
    --blue: #3b82f6;
    --emerald: #10b981;
    --amber: #f59e0b;
    --danger: #ef4444;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    background: var(--bg);
    color: var(--text);
    font-family: Tahoma, "Vazirmatn", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    line-height: 1.65;
  }
  .container {
    max-width: 1220px;
    margin: 0 auto;
    padding: 28px 20px 60px;
  }
  .hero {
    background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #064e3b 100%);
    border: 1px solid #334155;
    border-radius: 18px;
    padding: 28px 30px;
    margin-bottom: 24px;
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
  }
  .hero h1 { margin: 0 0 8px; font-size: 24px; }
  .hero p { margin: 0; color: #cbd5e1; font-size: 14px; max-width: 720px; }
  .downloads {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 16px;
    margin-bottom: 26px;
  }
  .dl-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
  }
  .dl-card h3 { margin: 0 0 8px; font-size: 17px; display: flex; align-items: center; gap: 8px; }
  .dl-card p { margin: 0 0 16px; color: var(--muted); font-size: 13px; }
  .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: 10px;
    border: none;
    cursor: pointer;
    font-family: inherit;
    font-weight: 700;
    font-size: 13px;
    text-decoration: none;
    transition: opacity 0.15s ease, transform 0.1s ease;
  }
  .btn:hover { opacity: 0.92; }
  .btn:active { transform: scale(0.99); }
  .btn-blue { background: var(--blue); color: #fff; }
  .btn-emerald { background: var(--emerald); color: #022c22; }
  .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text); }
  .btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 8px; }

  .workspace {
    background: #f8fafc;
    color: #0f172a;
    border-radius: 16px;
    overflow: hidden;
    border: 1px solid #cbd5e1;
    box-shadow: 0 20px 40px rgba(0,0,0,0.35);
  }
  .wp-topbar {
    background: #1d2327;
    color: #f0f0f1;
    padding: 10px 18px;
    font-size: 13px;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .wp-layout {
    display: grid;
    grid-template-columns: 230px 1fr;
    min-height: 540px;
  }
  @media (max-width: 820px) {
    .wp-layout { grid-template-columns: 1fr; }
  }
  .wp-sidebar {
    background: #1d2327;
    color: #c3c4c7;
    padding: 14px 0;
  }
  .wp-menu-item {
    display: block;
    width: 100%;
    text-align: right;
    background: none;
    border: none;
    color: #f0f0f1;
    padding: 11px 16px;
    font-family: inherit;
    font-size: 13px;
    cursor: pointer;
    border-right: 4px solid transparent;
  }
  .wp-menu-item:hover { background: #2c3338; }
  .wp-menu-item.active {
    background: #2271b1;
    border-right-color: #72aee6;
    font-weight: 700;
  }
  .wp-content {
    padding: 22px;
    background: #f1f5f9;
  }
  .sim-banner {
    padding: 18px 22px;
    border-radius: 12px;
    color: #fff;
    margin-bottom: 16px;
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
  }
  .banner-asc { background: linear-gradient(135deg, #0f172a, #1e3a8a); }
  .banner-gsc { background: linear-gradient(135deg, #064e3b, #0f766e); }
  .stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 12px;
    margin-bottom: 18px;
  }
  .stat {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px;
  }
  .stat .lbl { font-size: 12px; color: #64748b; }
  .stat .val { font-size: 23px; font-weight: 700; margin: 4px 0; color: #0f172a; }
  .stat .sub { font-size: 11px; color: #475569; }
  .white-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 16px;
  }
  table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
  }
  th, td {
    text-align: right;
    padding: 10px 8px;
    border-bottom: 1px solid #e2e8f0;
    vertical-align: top;
  }
  th { color: #475569; font-weight: 700; background: #f8fafc; }
  .tag {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
  }
  .tag-crit { background: #fee2e2; color: #991b1b; }
  .tag-high { background: #ffedd5; color: #9a3412; }
  .tag-med { background: #fef9c3; color: #854d0e; }
  .tag-ok { background: #dcfce7; color: #166534; }
  .tag-blue { background: #dbeafe; color: #1e40af; }
  pre.code {
    background: #0f172a;
    color: #e2e8f0;
    padding: 14px;
    border-radius: 10px;
    overflow-x: auto;
    font-size: 12px;
    direction: ltr;
    text-align: left;
  }
  .input-ltr {
    padding: 8px 12px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    direction: ltr;
    width: 100%;
    max-width: 340px;
    font-family: monospace;
  }
</style>
</head>
<body>
<div class="container">
  <div class="hero">
    <div>
      <h1>پکیج کامل دو افزونه هوشمند وردپرس + سرچ کنسول</h1>
      <p>هر دو افزونه به‌صورت استاندارد کدنویسی، تست سینتکس AST و پکیج (.zip) شده‌اند و آماده نصب مستقیم از بخش «افزونه‌ها ← افزودن افزونه ← بارگذاری افزونه» در پیشخوان وردپرس شما هستند.</p>
    </div>
    <div>
      <span class="tag tag-ok" style="padding:6px 14px;font-size:12px;">✓ آماده نصب روی WordPress 6.x و PHP 7.4/8.x</span>
    </div>
  </div>

  <div class="downloads">
    <div class="dl-card">
      <div>
        <h3>۱. افزونه فرمانده هوشمند سایت</h3>
        <p>اتصال مستقیم به وردپرس و ووکامرس برای ویرایش نوشته‌ها، سئو (Rank Math / Yoast)، ریدایرکت‌ها، کدهای سفارشی و قالب همراه با <strong>Snapshot و بازگشت یک‌کلیکی (Rollback)</strong>.</p>
      </div>
      <a class="btn btn-blue" href="download/ai-site-commander.zip">دانلود ai-site-commander.zip</a>
    </div>

    <div class="dl-card">
      <div>
        <h3>۲. افزونه اتوپایلوت سرچ کنسول</h3>
        <p>اتصال به API رسمی Google Search Console، شناسایی خطاهای ۴۰۴، noindex، افت کلیک، CTR پایین و کنیبالیزیشن، تحلیل هوشمند و <strong>اجرای مستقیم اصلاح در سایت</strong>.</p>
      </div>
      <a class="btn btn-emerald" href="download/ai-gsc-autopilot.zip">دانلود ai-gsc-autopilot.zip</a>
    </div>

    <div class="dl-card">
      <div>
        <h3>۳. سرور اتصال ایجنت خارجی (MCP)</h3>
        <p>پل ارتباطی پروتکل MCP (بدون نیاز به نصب پکیج اضافی) جهت اتصال مستقیم Claude، Cursor یا ایجنت‌های خارجی با ۱۸ ابزار اختصاصی به هر دو افزونه.</p>
      </div>
      <a class="btn btn-outline" href="download/mcp-server.zip">دانلود mcp-server.zip</a>
    </div>
  </div>

  <h2 style="margin:0 0 12px;font-size:18px;">پیش‌نمایش زنده تعاملی پیشخوان وردپرس (شبیه‌ساز چرخه تشخیص، تحلیل، اصلاح و Rollback)</h2>

  <div class="workspace">
    <div class="wp-topbar">
      <span>پیشخوان وردپرس — پیش‌نمایش تعاملی دو افزونه</span>
      <span id="bridge-indicator" style="color:#4ade80;">● پل ارتباطی بین دو افزونه: متصل و فعال</span>
    </div>
    <div class="wp-layout">
      <div class="wp-sidebar">
        <button class="wp-menu-item active" data-view="gsc" onclick="switchPlugin('gsc', this)">اتوپایلوت سرچ کنسول</button>
        <button class="wp-menu-item" data-view="asc" onclick="switchPlugin('asc', this)">فرمانده هوشمند سایت</button>
        <button class="wp-menu-item" data-view="mcp" onclick="switchPlugin('mcp', this)">تولید تنظیمات اتصال MCP</button>
      </div>

      <div class="wp-content">
        <!-- VIEW 1: GSC AUTOPILOT -->
        <div id="view-gsc">
          <div class="sim-banner banner-gsc">
            <div>
              <strong style="font-size:17px;">اتوپایلوت هوشمند سرچ کنسول (AI Search Console Autopilot)</strong>
              <div style="font-size:12px;opacity:0.9;">Property متصل: sc-domain:yoursite.com | تحلیلگر هوشمند فعال</div>
            </div>
            <div style="display:flex;gap:8px;">
              <button class="btn btn-sm" style="background:#fff;color:#064e3b;" onclick="addSimulatedSyncIssue()">همگام‌سازی زنده با گوگل</button>
              <button class="btn btn-sm btn-emerald" style="background:#34d399;" onclick="fixAllIssues()">اصلاح خودکار همه (Autopilot)</button>
            </div>
          </div>

          <div class="stats-row">
            <div class="stat"><div class="lbl">کلیک ۲۸ روز اخیر</div><div class="val">۱۴,۸۲۰</div><div class="sub">نرخ کلیک میانگین: ۳.۸٪</div></div>
            <div class="stat"><div class="lbl">نمایش در گوگل (Impressions)</div><div class="val">۳۸۹,۴۰۰</div><div class="sub">جایگاه میانگین: ۸.۴</div></div>
            <div class="stat"><div class="lbl">مشکلات باز سرچ کنسول</div><div class="val" id="stat-open-issues">3</div><div class="sub">آماده تحلیل و اصلاح</div></div>
            <div class="stat"><div class="lbl">اصلاح‌شده در سایت</div><div class="val" id="stat-fixed-issues">0</div><div class="sub">همراه با Snapshot پشتیبان</div></div>
          </div>

          <div class="white-card">
            <h3 style="margin-top:0;">مشکلات دریافت‌شده از Google Search Console</h3>
            <table>
              <thead>
                <tr>
                  <th>شدت</th>
                  <th>نوع مشکل</th>
                  <th>آدرس و داده سرچ کنسول</th>
                  <th>تحلیل هوش مصنوعی و برنامه اقدام</th>
                  <th>وضعیت</th>
                  <th>عملیات</th>
                </tr>
              </thead>
              <tbody id="gsc-issues-tbody"></tbody>
            </table>
          </div>
        </div>

        <!-- VIEW 2: AI SITE COMMANDER -->
        <div id="view-asc" style="display:none;">
          <div class="sim-banner banner-asc">
            <div>
              <strong style="font-size:17px;">فرمانده هوشمند سایت (AI Site Commander)</strong>
              <div style="font-size:12px;opacity:0.9;">موتور سئو: Rank Math | ووکامرس: فعال | حالت اجرا: مستقیم + بکاپ خودکار</div>
            </div>
            <div>
              <button class="btn btn-sm btn-blue" onclick="generateDemoKey()">+ ساخت کلید API جدید</button>
            </div>
          </div>

          <div id="demo-key-box" class="white-card" style="display:none;background:#ecfdf5;border-color:#34d399;">
            <strong>کلید API جدید ساخته شد:</strong>
            <code id="demo-key-val" dir="ltr" style="display:block;margin-top:6px;padding:8px;background:#fff;border-radius:6px;"></code>
          </div>

          <div class="white-card">
            <h3 style="margin-top:0;">نسخه‌های پشتیبان خودکار و بازگشت یک‌کلیکی (Snapshots & Rollback)</h3>
            <p style="font-size:12px;color:#475569;margin-top:0;">هر تغییری که ایجنت هوش مصنوعی یا اتوپایلوت سرچ کنسول روی سایت اعمال کند در اینجا ذخیره می‌شود و با یک کلیک قابل بازگشت است:</p>
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  <th>عملیات</th>
                  <th>بخش تغییریافته</th>
                  <th>دلیل تغییر</th>
                  <th>وضعیت / بازگردانی</th>
                </tr>
              </thead>
              <tbody id="asc-snapshots-tbody"></tbody>
            </table>
          </div>
        </div>

        <!-- VIEW 3: MCP CONFIG GENERATOR -->
        <div id="view-mcp" style="display:none;">
          <div class="white-card">
            <h3 style="margin-top:0;">تولیدکننده تنظیمات اتصال مستقیم ایجنت (MCP & REST API)</h3>
            <p style="font-size:13px;color:#475569;">آدرس سایت وردپرسی و کلید API تولیدشده در افزونه اول را وارد کنید تا کد آماده اتصال ساخته شود:</p>
            <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
              <input class="input-ltr" id="cfg-url" value="https://example.com" oninput="renderMcpConfig()" placeholder="https://yoursite.com" />
              <input class="input-ltr" id="cfg-key" value="asc_live_9f8e7d6c5b4a3210..." oninput="renderMcpConfig()" placeholder="asc_live_..." />
            </div>
            <pre class="code" id="mcp-json-preview"></pre>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const state = {
  issues: [
    {
      id: 101,
      severity: 'critical',
      sevLabel: 'CRITICAL',
      type: 'خطای ۴۰۴ در ایندکس گوگل',
      url: '/blog/wordpress-speed-guide-old',
      metric: '42 برخورد ۴۰۴ | گزارش‌شده در URL Inspection',
      analyzed: false,
      fixed: false,
      diagnosis: 'صفحه قدیمی حذف شده اما بک‌لینک‌ها و ایندکس گوگل هنوز به آن اشاره دارند. نزدیک‌ترین مقاله فعال «/blog/wordpress-speed-optimization» شناسایی شد → ثبت ریدایرکت ۳۰۱.',
      actionSummary: 'save_redirect (301: /blog/wordpress-speed-guide-old -> /blog/wordpress-speed-optimization)'
    },
    {
      id: 102,
      severity: 'critical',
      sevLabel: 'CRITICAL',
      type: 'مسدود شده با تگ noindex',
      url: '/product/pro-seo-package',
      metric: 'صفحه محصول منتشرشده اما خارج از ایندکس',
      analyzed: false,
      fixed: false,
      diagnosis: 'تگ متا robots روی noindex جا مانده است. حذف noindex در Rank Math، تنظیم کنونیکال استاندارد و ارسال مجدد نقشه سایت به گوگل.',
      actionSummary: 'update_seo (noindex: false + canonical) + submit_sitemap'
    },
    {
      id: 103,
      severity: 'med',
      sevLabel: 'MEDIUM',
      type: 'ایمپرشن بالا (۱۲,۴۰۰) اما CTR پایین (۱.۱٪)',
      url: '/technical-seo-checklist',
      metric: 'رتبه ۶.۲ گوگل | ۱۲,۴۰۰ نمایش | ۱۳۶ کلیک',
      analyzed: false,
      fixed: false,
      diagnosis: 'عنوان سئو جذابیت کافی ندارد و اسکیما ندارد. بازنویسی Meta Title و Meta Description بر اساس کوئری‌های برتر سرچ کنسول + تزریق اسکیمای FAQPage JSON-LD.',
      actionSummary: 'update_seo (Meta Title + Meta Desc + FAQ Schema JSON-LD)'
    }
  ],
  snapshots: [
    {
      id: 1,
      action: 'update_post',
      target: 'برگه صفحه اصلی (#12)',
      reason: 'بهینه‌سازی ساختار هدینگ H2 توسط ایجنت مستقیم',
      rolledBack: false,
      linkedIssueId: null
    }
  ],
  nextSnapId: 2
};

function switchPlugin(view, btn) {
  document.querySelectorAll('.wp-menu-item').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('view-gsc').style.display = view === 'gsc' ? 'block' : 'none';
  document.getElementById('view-asc').style.display = view === 'asc' ? 'block' : 'none';
  document.getElementById('view-mcp').style.display = view === 'mcp' ? 'block' : 'none';
  if (view === 'mcp') renderMcpConfig();
}

function renderTables() {
  const gscBody = document.getElementById('gsc-issues-tbody');
  gscBody.innerHTML = state.issues.map(iss => {
    const sevClass = iss.severity === 'critical' ? 'tag-crit' : (iss.severity === 'high' ? 'tag-high' : 'tag-med');
    const stHtml = iss.fixed
      ? '<span class="tag tag-ok">اصلاح شد ✓</span>'
      : (iss.analyzed ? '<span class="tag tag-blue">تحلیل شده</span>' : '<span class="tag">باز</span>');
    const diagHtml = iss.analyzed || iss.fixed
      ? '<div>' + iss.diagnosis + '<div style="margin-top:4px;font-family:monospace;font-size:11px;color:#0f766e;" dir="ltr">' + iss.actionSummary + '</div></div>'
      : '<em style="color:#64748b;">برای ریشه‌یابی روی «تحلیل هوشمند» کلیک کنید</em>';
    const btns = iss.fixed
      ? '<button class="btn btn-sm btn-outline" style="color:#0f172a;border-color:#cbd5e1;" onclick="switchToSnapshots()">مشاهده Snapshot</button>'
      : '<button class="btn btn-sm btn-outline" style="color:#0f172a;border-color:#cbd5e1;margin-left:4px;" onclick="analyzeIssue(' + iss.id + ')">تحلیل هوشمند</button>' +
        '<button class="btn btn-sm btn-emerald" onclick="fixIssue(' + iss.id + ')">اجرای اصلاح در سایت</button>';
    return '<tr>' +
      '<td><span class="tag ' + sevClass + '">' + iss.sevLabel + '</span></td>' +
      '<td><strong>' + iss.type + '</strong></td>' +
      '<td><code dir="ltr">' + iss.url + '</code><div style="font-size:11px;color:#64748b;">' + iss.metric + '</div></td>' +
      '<td>' + diagHtml + '</td>' +
      '<td>' + stHtml + '</td>' +
      '<td>' + btns + '</td>' +
    '</tr>';
  }).join('');

  document.getElementById('stat-open-issues').textContent = state.issues.filter(i => !i.fixed).length;
  document.getElementById('stat-fixed-issues').textContent = state.issues.filter(i => i.fixed).length;

  const snapBody = document.getElementById('asc-snapshots-tbody');
  snapBody.innerHTML = state.snapshots.slice().reverse().map(s => {
    const rb = s.rolledBack
      ? '<span class="tag">بازگردانی شد (Rolled Back)</span>'
      : '<button class="btn btn-sm btn-outline" style="color:#b91c1c;border-color:#fca5a5;" onclick="rollbackSnap(' + s.id + ')">بازگشت به حالت قبل (Rollback)</button>';
    return '<tr>' +
      '<td>#' + s.id + '</td>' +
      '<td><code dir="ltr">' + s.action + '</code></td>' +
      '<td><strong>' + s.target + '</strong></td>' +
      '<td>' + s.reason + '</td>' +
      '<td>' + rb + '</td>' +
    '</tr>';
  }).join('');
}

function analyzeIssue(id) {
  const item = state.issues.find(i => i.id === id);
  if (item) { item.analyzed = true; renderTables(); }
}

function fixIssue(id) {
  const item = state.issues.find(i => i.id === id);
  if (!item || item.fixed) return;
  item.analyzed = true;
  item.fixed = true;
  state.snapshots.push({
    id: state.nextSnapId++,
    action: item.actionSummary.split(' ')[0],
    target: item.url,
    reason: 'اصلاح خودکار مشکل سرچ کنسول #' + item.id + ' (' + item.type + ')',
    rolledBack: false,
    linkedIssueId: item.id
  });
  renderTables();
}

function fixAllIssues() {
  state.issues.forEach(i => { if (!i.fixed) fixIssue(i.id); });
}

function addSimulatedSyncIssue() {
  if (state.issues.some(i => i.id === 104)) return;
  state.issues.unshift({
    id: 104,
    severity: 'high',
    sevLabel: 'HIGH',
    type: 'کلمه کلیدی در آستانه صفحه اول (رتبه ۸.۱)',
    url: '/woocommerce-seo-guide',
    metric: 'کوئری «آموزش سئو ووکامرس» — ۳,۱۰۰ ایمپرشن',
    analyzed: false,
    fixed: false,
    diagnosis: 'صفحه در رتبه ۸.۱ قرار دارد اما عبارت کلیدی دقیق در تایتل سئو و لینک‌های داخلی تکرار نشده است. تقویت Meta Title + تزریق لینک داخلی از مقاله مرتبط.',
    actionSummary: 'update_seo + inject_internal_link'
  });
  renderTables();
}

function switchToSnapshots() {
  const btn = document.querySelector('[data-view="asc"]');
  switchPlugin('asc', btn);
}

function rollbackSnap(snapId) {
  const snap = state.snapshots.find(s => s.id === snapId);
  if (!snap || snap.rolledBack) return;
  snap.rolledBack = true;
  if (snap.linkedIssueId) {
    const iss = state.issues.find(i => i.id === snap.linkedIssueId);
    if (iss) iss.fixed = false;
  }
  renderTables();
}

function generateDemoKey() {
  const rand = Array.from({ length: 32 }, () => Math.floor(Math.random() * 16).toString(16)).join('');
  const key = 'asc_live_' + rand;
  document.getElementById('demo-key-val').textContent = key;
  document.getElementById('demo-key-box').style.display = 'block';
  document.getElementById('cfg-key').value = key;
}

function renderMcpConfig() {
  const url = (document.getElementById('cfg-url').value || 'https://yoursite.com').replace(/\\/+$/, '');
  const key = document.getElementById('cfg-key').value || 'asc_live_YOUR_API_KEY';
  const cfg = {
    mcpServers: {
      "wordpress-commander": {
        command: "node",
        args: ["/path/to/mcp-server/index.mjs"],
        env: {
          WP_SITE_URL: url,
          ASC_API_KEY: key
        }
      }
    }
  };
  document.getElementById('mcp-json-preview').textContent = JSON.stringify(cfg, null, 2);
}

renderTables();
renderMcpConfig();
</script>
</body>
</html>`;

const server = http.createServer((req, res) => {
	const reqUrl = new URL(req.url || '/', 'http://localhost');

	if (reqUrl.pathname.startsWith('/download/')) {
		const fileName = path.basename(reqUrl.pathname);
		const filePath = DOWNLOADABLE_FILES[fileName];
		if (filePath && fs.existsSync(filePath)) {
			const stat = fs.statSync(filePath);
			res.writeHead(200, {
				'Content-Type': 'application/zip',
				'Content-Length': stat.size,
				'Content-Disposition': `attachment; filename="${fileName}"`,
				'Cache-Control': 'no-cache',
			});
			fs.createReadStream(filePath).pipe(res);
			return;
		}
		res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
		res.end('File not found');
		return;
	}

	if (reqUrl.pathname === '/health') {
		res.writeHead(200, { 'Content-Type': 'application/json' });
		res.end(JSON.stringify({ ok: true }));
		return;
	}

	res.writeHead(200, {
		'Content-Type': 'text/html; charset=utf-8',
		'Cache-Control': 'no-cache',
	});
	res.end(HTML_PAGE);
});

server.listen(PORT, '0.0.0.0', () => {
	console.log(`WordPress Plugins Portal listening on http://0.0.0.0:${PORT}`);
});
