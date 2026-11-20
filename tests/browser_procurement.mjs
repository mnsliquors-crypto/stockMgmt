import fs from 'node:fs/promises';

const base = process.env.APP_BASE_URL || 'http://127.0.0.1:8081/';
if (new URL(base).port !== '8081') throw new Error('Use the disposable app on port 8081.');
const screenshotDir = process.env.ERP_SCREENSHOT_DIR || '/tmp/stockmgmt-procurement-browser/screenshots';
await fs.mkdir(screenshotDir, {recursive: true});
const version = await (await fetch((process.env.CHROME_DEBUG_URL || 'http://127.0.0.1:9223') + '/json/version')).json();
const socket = new WebSocket(version.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {socket.addEventListener('open', resolve, {once: true}); socket.addEventListener('error', reject, {once: true});});
let sequence = 0;
const pending = new Map();
const browserErrors = [];
socket.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    if (message.method === 'Runtime.exceptionThrown') browserErrors.push(message.params.exceptionDetails.text);
    const waiter = pending.get(message.id);
    if (waiter) { pending.delete(message.id); message.error ? waiter.reject(new Error(message.error.message)) : waiter.resolve(message.result); }
});
function command(method, params = {}, sessionId) {
    return new Promise((resolve, reject) => {
        const id = ++sequence;
        const timeout = setTimeout(() => {pending.delete(id); reject(new Error('CDP timeout: ' + method));}, 15000);
        pending.set(id, {resolve: value => {clearTimeout(timeout); resolve(value);}, reject: error => {clearTimeout(timeout); reject(error);}});
        socket.send(JSON.stringify({id, method, params, sessionId}));
    });
}
const target = await command('Target.createTarget', {url: 'about:blank'});
const {sessionId} = await command('Target.attachToTarget', {targetId: target.targetId, flatten: true});
const send = (method, params) => command(method, params, sessionId);
await send('Page.enable'); await send('Runtime.enable');
async function evaluate(expression) {
    const result = await send('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true});
    if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
    return result.result.value;
}
async function waitFor(expression, label) {
    const deadline = Date.now() + 15000;
    while (Date.now() < deadline) {
        if (await evaluate(expression)) return;
        await new Promise(resolve => setTimeout(resolve, 80));
    }
    throw new Error('Browser wait failed: ' + label + ': ' + await evaluate('document.body.innerText.slice(-1800)'));
}
async function navigate(path) {
    await send('Page.navigate', {url: base + path});
    await waitFor('document.readyState === "complete" && location.href === ' + JSON.stringify(new URL(path, base).href) + ' && !!document.querySelector("meta[name=csrf-token]")', path);
    if (await evaluate('/Fatal error|Uncaught |Warning:/.test(document.body.innerText)')) throw new Error('PHP error: ' + path);
}
async function login(username, password) {
    await send('Network.clearBrowserCookies');
    await navigate('admin/login.php');
    const result = await evaluate(`(async()=>{
        const response=await fetch(${JSON.stringify(base + 'classes/Login.php?f=login')},{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':document.querySelector('meta[name=csrf-token]').content},body:new URLSearchParams(${JSON.stringify({username,password})})});
        return (await response.json()).status;
    })()`);
    if (result !== 'success') throw new Error('Login failed for ' + username);
}
async function screenshot(name) {
    const shot = await send('Page.captureScreenshot', {format: 'png', captureBeyondViewport: false});
    await fs.writeFile(screenshotDir + '/' + name + '.png', Buffer.from(shot.data, 'base64'));
}
async function layout(label) {
    const size = await evaluate('({width:innerWidth,scroll:document.documentElement.scrollWidth})');
    if (size.scroll > size.width + 2) throw new Error(label + ' overflow: ' + JSON.stringify(size));
    return size;
}
try {
    await send('Emulation.setDeviceMetricsOverride', {width: 1366,height: 1000,deviceScaleFactor: 1,mobile: false});
    await login('admin', 'admin123');
    await navigate('admin/?page=transactions/procurement&new=1');
    await waitFor('typeof jQuery!=="undefined" && !!document.querySelector("#purchase-order-form")', 'order form');
    await evaluate(`(()=>{
        const vendor=document.querySelector('#po-vendor');vendor.value=vendor.options[1].value;jQuery(vendor).trigger('change');
        const item=document.querySelector('.po-item');item.value=item.options[1].value;jQuery(item).trigger('change');
        document.querySelector('.po-quantity').value='5';document.querySelector('.po-price').value='10.55';document.querySelector('#po-tax-rate').value='13';
        document.querySelector('#po-notes').value='Browser procurement workflow';
        document.querySelector('#purchase-order-form').requestSubmit();
    })()`);
    await waitFor('new URL(location.href).searchParams.has("id") && !!document.querySelector(".po-transition[data-action=submit]")', 'save draft');
    const id = await evaluate('new URL(location.href).searchParams.get("id")');
    await evaluate('document.querySelector(".po-transition[data-action=submit]").click()');
    await waitFor('document.querySelector(".procurement-workspace .badge")?.innerText === "Pending"', 'submit for approval');
    await screenshot('order-pending-desktop');
    await login('procurement_browser_checker', 'ProcurementBrowser123!');
    await navigate('admin/?page=transactions/procurement&id=' + id);
    await waitFor('!!document.querySelector(".po-transition[data-action=approve]")', 'independent approval');
    await evaluate('document.querySelector(".po-transition[data-action=approve]").click()');
    await waitFor('!!document.querySelector(".po-receive-quantity")', 'approved receiving form');
    await screenshot('order-approved-desktop');
    const desktop = await layout('Desktop order');
    await login('procurement_browser_receiver', 'ProcurementBrowser123!');
    await navigate('admin/?page=transactions/procurement&id=' + id);
    const separate = await evaluate('document.querySelector("#po-receive-form").dataset.separate === "1"');
    const invoice = 'BROWSER-' + Date.now();
    await evaluate(`document.querySelector('.po-receive-quantity').value='2';document.querySelector('#po-invoice').value=${JSON.stringify(invoice)};document.querySelector('#po-receive-form').requestSubmit();`);
    if (separate) {
        await waitFor('!!document.querySelector(".pc-workspace") && location.href.includes("item_receipts&id=")', 'partial item receipt');
        await navigate('admin/?page=transactions/procurement&id=' + id);
        await waitFor('document.querySelector(".procurement-workspace .badge")?.innerText === "Part Received"', 'partial order');
        await evaluate('document.querySelector("#po-fill-outstanding").click();document.querySelector("#po-receive-form").requestSubmit()');
        await waitFor('!!document.querySelector(".pc-workspace") && location.href.includes("item_receipts&id=")', 'final item receipt');
        await navigate('admin/?page=transactions/procurement&id=' + id);
    } else {
    await waitFor('document.querySelector(".procurement-workspace .badge")?.innerText === "Part Received"', 'partial delivery');
    await evaluate(`document.querySelector('#po-fill-outstanding').click();document.querySelector('#po-invoice').value=${JSON.stringify(invoice)};document.querySelector('#po-receive-form').requestSubmit();`);
    await waitFor('document.querySelector("#procurement-message").innerText.includes("invoice")', 'duplicate invoice rejection');
    await evaluate(`document.querySelector('#po-invoice').value=${JSON.stringify(invoice + '-FINAL')};document.querySelector('#po-receive-form').requestSubmit();`);
    }
    await waitFor('document.querySelector(".procurement-workspace .badge")?.innerText === "Received"', 'final delivery');
    if (await evaluate('document.querySelector("#po-receive-form").querySelectorAll(".po-receive-quantity").length') !== 0) throw new Error('Completed order can still receive goods');
    await screenshot('order-received-desktop');
    await evaluate('window.print=()=>{window.printWasCalled=true;};document.querySelector("#print-purchase-order").click()');
    if (!await evaluate('window.printWasCalled')) throw new Error('Print command did not run');
    await send('Emulation.setEmulatedMedia', {media: 'print'});
    await screenshot('purchase-order-print');
    if (!await evaluate('getComputedStyle(document.querySelector(".po-print-brand")).display === "flex" && getComputedStyle(document.querySelector(".po-order-operations")).display === "none"')) throw new Error('Print layout includes internal operations or lacks company branding');
    await send('Emulation.setEmulatedMedia', {media: 'screen'});
    await send('Emulation.setDeviceMetricsOverride', {width: 390,height: 844,deviceScaleFactor: 1,mobile: true});
    await screenshot('order-received-mobile');
    const mobile = await layout('Mobile order');
    await navigate('admin/?page=transactions/procurement&view=replenishment');
    await screenshot('replenishment-mobile'); await layout('Mobile replenishment');
    await navigate('admin/?page=transactions/procurement&new=1');
    await screenshot('order-form-mobile'); await layout('Mobile order form');
    await evaluate('document.querySelector("#po-add-line").click()');
    if (await evaluate('document.querySelectorAll("#po-lines tbody tr").length') !== 2) throw new Error('Add item failed');
    await evaluate('document.querySelectorAll(".po-remove-line")[1].click()');
    if (await evaluate('document.querySelectorAll("#po-lines tbody tr").length') !== 1) throw new Error('Remove item failed');
    await evaluate('document.querySelector("#erp-mobile-menu-toggle").click()');
    await waitFor('document.querySelector("#erp-main-menu").classList.contains("is-open")', 'mobile navigation');
    if (browserErrors.length) throw new Error('Browser errors: ' + JSON.stringify(browserErrors));
    console.log(JSON.stringify({result: 'Browser order creation, independent approval, partial receiving, duplicate invoice protection, completion, print, and mobile checks passed',orderId:id,desktop,mobile,screenshotDir}));
} finally {
    await command('Target.closeTarget', {targetId: target.targetId});
    socket.close();
}
