// SPDX-License-Identifier: GPL-2.0-or-later
// Execute actual PHP-rendered team handlers with real permitted API rows in Chromium.
// Transport is controlled to exercise race ordering; this is not a live HTTP app smoke.
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { chromium } from '@playwright/test';

const fixtures = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
let checks = 0;
try {
  for (const [kind, html] of [['project', fixtures.project], ...fixtures.tasks.map(html => ['task', html])]) {
    const page = await browser.newPage();
    const scripts = [...html.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/g)].map(match => match[1]);
    const script = scripts.find(source => source.includes('choiceTokens ='));
    if (!script) throw new Error('Missing actual rendered team handler');
    const taskFunction = script.match(/function (updateItemsDropdown\d+)\(/)?.[1];
    await page.setContent(html.replace(/<script[^>]*>[\s\S]*?<\/script>/g, ''));
    await page.addScriptTag({ path: resolve('node_modules/jquery/dist/jquery.js') });
    await page.evaluate(() => {
      window.requests = [];
      $.ajax = options => { window.requests.push(options); return {}; };
    });
    await page.evaluate(source => { (0, eval)(source); }, script);
    if (kind === 'project') {
      await page.waitForFunction(() => $._data(document.querySelector('#dropdown_itemtype'), 'events')?.change?.length);
    }
    const select = page.locator(kind === 'project' ? '#dropdown_items_id' : 'select[name=items_id]');
    async function change(type) {
      await page.evaluate(({ type, taskFunction }) => {
        if (taskFunction) window[taskFunction](type);
        else $('#dropdown_itemtype').val(type).trigger('change');
      }, { type, taskFunction });
    }
    await change('Group');
    await change('Contact');
    const requests = await page.evaluate(() => window.requests.map(request => request.data));
    if (requests.length !== 2 || requests[0].itemtype !== 'Group' || requests[1].itemtype !== 'Contact'
      || !requests.every(request => request._idor_token) || requests[0]._idor_token === requests[1]._idor_token) {
      throw new Error('Each rendered request must carry its own kind token');
    }
    await page.evaluate(response => window.requests[1].success(response), fixtures.responses.Contact);
    const contactOptions = await select.locator('option').evaluateAll(options => options.map(option => [option.value, option.textContent]));
    if (!contactOptions.some(([id, label]) => id === '4294967801' && label.includes('Contact <img src=x data-team-choice> label'))
      || contactOptions.filter(([id]) => id !== '0').length !== 1 || await page.locator('[data-team-choice]').count()) {
      throw new Error('Contact choice must preserve a wide ID and literal plain-text label, without creating elements');
    }
    if (kind === 'project' && await select.locator('optgroup > option[value="4294967801"]').count() !== 1) {
      throw new Error('Grouped rows must belong to their actual optgroup');
    }
    await page.evaluate(response => window.requests[0].success(response), fixtures.responses.Group);
    if (JSON.stringify(await select.locator('option').evaluateAll(options => options.map(option => [option.value, option.textContent]))) !== JSON.stringify(contactOptions)) {
      throw new Error('Older different-kind response must not replace current choices with overlapping identifiers');
    }
    await change('Group');
    await change('0');
    const clearedOptions = await select.locator('option').evaluateAll(options => options.map(option => [option.value, option.textContent]));
    await page.evaluate(response => window.requests[2].success(response), fixtures.responses.Group);
    await page.evaluate(() => window.requests[2].error?.());
    if (JSON.stringify(await select.locator('option').evaluateAll(options => options.map(option => [option.value, option.textContent]))) !== JSON.stringify(clearedOptions)) {
      throw new Error('Success/error after clearing the kind must not repopulate the widget');
    }
    await change('Group');
    await page.evaluate(response => window.requests[3].success(response), fixtures.responses.Group);
    if (await select.locator('option[value="4294967801"]').count() !== 1 || await page.locator('[data-team-choice]').count()) {
      throw new Error('A new valid selection must remain usable after stale responses');
    }
    ++checks;
    await page.close();
  }
  console.log(`Project team Chromium DOM: ${checks}/3 handlers passed (project, direct task, recursive task)`);
} finally {
  await browser.close();
}
