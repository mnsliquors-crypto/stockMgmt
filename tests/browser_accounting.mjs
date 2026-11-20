import fs from 'node:fs/promises';

const base = process.env.APP_BASE_URL || 'http://127.0.0.1:8081/';
if (new URL(base).port !== '8081') throw new Error('Browser regression requires the disposable app on port 8081.');
const endpoint = process.env.CHROME_DEBUG_URL || 'http://127.0.0.1:9223';
const screenshotDir = process.env.ERP_SCREENSHOT_DIR || '/tmp/stockmgmt-erp-browser';
await fs.mkdir(screenshotDir, { recursive: true });
const version = await (await fetch(endpoint + '/json/version')).json();
const socket = new WebSocket(version.webSocketDebuggerUrl);
await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, {once: true}); socket.addEventListener('error', reject, {once: true}); });
let sequence = 0;
const pending = new Map();
socket.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    const waiter = pending.get(message.id);
    if (waiter) { pending.delete(message.id); message.error ? waiter.reject(new Error(message.error.message)) : waiter.resolve(message.result); }
});
function command(method, params = {}, sessionId) {
    return new Promise((resolve, reject) => {
        const id = ++sequence;
        const timeout = setTimeout(() => { pending.delete(id); reject(new Error('CDP timeout: ' + method)); }, 15000);
        pending.set(id, {resolve: value => {clearTimeout(timeout); resolve(value);}, reject: error => {clearTimeout(timeout); reject(error);}});
        socket.send(JSON.stringify({id, method, params, sessionId}));
    });
}
const target = await command('Target.createTarget', {url: 'about:blank'});
const {sessionId} = await command('Target.attachToTarget', {targetId: target.targetId, flatten: true});
const send = (method, params) => command(method, params, sessionId);
await send('Page.enable');
await send('Runtime.enable');
async function evaluate(expression) {
    const result = await send('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true});
    if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
    return result.result.value;
}
async function waitFor(expression, label) {
    const deadline = Date.now() + 12000;
    while (Date.now() < deadline) {
        if (await evaluate(expression)) return;
        await new Promise(resolve => setTimeout(resolve, 80));
    }
    throw new Error('Browser wait failed: ' + label + ': ' + await evaluate('document.body.innerText.slice(-1000)'));
}
async function navigate(path) {
    await send('Page.navigate', {url: base + path});
    await waitFor('document.readyState === "complete" && location.href.startsWith(' + JSON.stringify(base) + ')', path);
    const errors = await evaluate('/Fatal error|Uncaught |Warning:/.test(document.body.innerText)');
    if (errors) throw new Error('PHP error in ' + path);
}
async function screenshot(name) {
    const result = await send('Page.captureScreenshot', {format: 'png', captureBeyondViewport: false});
    await fs.writeFile(screenshotDir + '/' + name + '.png', Buffer.from(result.data, 'base64'));
}
try {
    await send('Emulation.setDeviceMetricsOverride', {width: 1366, height: 900, deviceScaleFactor: 1, mobile: false});
    await navigate('admin/login.php');
    const login = await evaluate(`(async function(){
        const token=document.querySelector('meta[name="csrf-token"]').content;
        const response=await fetch(${JSON.stringify(base + 'classes/Login.php?f=login')},{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':token},body:new URLSearchParams({username:'admin',password:'admin123'})});
        return (await response.json()).status;
    })()`);
    if (login !== 'success') throw new Error('Browser login failed');
    await navigate('admin/?page=finance/accounting&new=1');
    await waitFor('typeof jQuery !== "undefined" && !!document.querySelector("#journal-form")', 'journal form');
    await evaluate(`(() => {
        document.querySelector('#entry-date').value='2199-02-15';
        document.querySelector('#journal-memo').value='Browser regression journal';
        const rows=document.querySelectorAll('#journal-lines tbody tr');
        const select=(row,code)=>{const s=row.querySelector('select');s.value=Array.from(s.options).find(o=>o.textContent.startsWith(code+' -')).value;jQuery(s).trigger('change');};
        select(rows[0],'1200');select(rows[1],'3000');
        rows[0].querySelector('.journal-debit').value='12.34';
        rows[1].querySelector('.journal-credit').value='12.34';
        document.querySelector('#journal-form').requestSubmit();
    })()`);
    await waitFor('new URL(location.href).searchParams.has("id") && !!document.querySelector("[data-action=post_journal]")', 'save draft');
    const journalId = await evaluate('new URL(location.href).searchParams.get("id")');
    await evaluate('document.querySelector("[data-action=post_journal]").click()');
    await waitFor('!!document.querySelector("[data-action=reverse_journal]")', 'post journal');
    await screenshot('journal-desktop');
    await evaluate('document.querySelector("[data-action=reverse_journal]").click()');
    await waitFor('document.querySelector("#accounting-reason-modal").classList.contains("show")', 'reversal modal');
    await evaluate(`document.querySelector('#reversal-date').value='2199-02-16';document.querySelector('#accounting-reason').value='Browser reversal check';document.querySelector('#accounting-reason-form').requestSubmit();`);
    await waitFor('new URL(location.href).searchParams.get("id") !== ' + JSON.stringify(journalId) + ' && document.querySelector("#journal-form") && !document.querySelector("[data-action=post_journal]")', 'reverse journal');
    await navigate('admin/?page=finance/accounting&view=reports&report=trial_balance&from=2199-02-01&to=2199-02-28');
    await screenshot('trial-balance-desktop');
    const desktop = await evaluate('({width:innerWidth,scroll:document.documentElement.scrollWidth,heading:document.querySelector(".accounting-workspace h5").innerText,offenders:Array.from(document.querySelectorAll("body *")).filter(e=>e.getBoundingClientRect().right>innerWidth+2).slice(-8).map(e=>({tag:e.tagName,cls:e.className,right:e.getBoundingClientRect().right}))})');
    await send('Emulation.setDeviceMetricsOverride', {width: 390, height: 844, deviceScaleFactor: 1, mobile: true});
    await screenshot('trial-balance-mobile');
    const mobile = await evaluate('({width:innerWidth,scroll:document.documentElement.scrollWidth})');
    if (desktop.scroll > desktop.width + 2) throw new Error('Desktop page overflows: ' + JSON.stringify(desktop));
    if (mobile.width > 392 || mobile.scroll > 392) throw new Error('Mobile page overflows: ' + JSON.stringify(mobile));
    await evaluate('document.querySelector("#erp-mobile-menu-toggle").click()');
    await waitFor('document.querySelector("#erp-main-menu").classList.contains("is-open")', 'mobile navigation');
    await evaluate('document.querySelector("#erp-mobile-menu-toggle").click()');
    await navigate('admin/?page=finance/accounting&view=accounts');
    await screenshot('accounts-mobile');
    console.log(JSON.stringify({result: 'Browser journal save, post, reversal, reports, and responsive checks passed', desktop, mobile, screenshotDir}));
} finally {
    await command('Target.closeTarget', {targetId: target.targetId});
    socket.close();
}
