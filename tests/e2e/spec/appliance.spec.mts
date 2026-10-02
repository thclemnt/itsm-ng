import { execFile } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';
import { expect, type Page, test } from '@playwright/test';
import { login } from '../helpers.mjs';

interface ApplianceFixture {
  appliance: number;
  applianceName: string;
  computers: number[];
  bindings: number[];
  relations: number[];
  newLocation: number;
  newLocationName: string;
  domain: number;
  readerName: string;
  owned: Record<string, number[]>;
}

interface NestedRelation {
  id: number;
  appliances_items_id: number;
  itemtype: string;
  items_id: number;
  locations_id: number | null;
  networks_id: number | null;
  domains_id: number | null;
}

const config = process.env.PLAYWRIGHT_APPLIANCE_CONFIG;
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const execute = promisify(execFile);

async function fixtureCommand<T>(action: string, fixture?: ApplianceFixture): Promise<T> {
  const args = ['tests/database-portability/appliance-web-fixture.php', config!, action];
  if (fixture) args.push(JSON.stringify(fixture));
  try {
    const { stdout } = await execute('php', args, { cwd: root });
    return JSON.parse(stdout) as T;
  } catch (error) {
    const failure = error as Error & { stdout?: string; stderr?: string };
    throw new Error(`${failure.message}\n${failure.stdout || ''}\n${failure.stderr || ''}`, { cause: error });
  }
}

async function openBindings(page: Page, kind: 'appliance' | 'computer', id: number): Promise<void> {
  await page.goto(`/front/${kind}.form.php?id=${id}`);
  const tab = page.getByRole('button', { name: kind === 'appliance' ? /^Items\b/ : /^Appliances\b/ });
  await expect(tab).toHaveCount(1);
  if ((await tab.getAttribute('aria-expanded')) !== 'true') await tab.click();
  await expect(page.locator('#tableForApplianceItem')).toBeVisible();
}

test('retains wide appliance binding identities through owner/reverse dialogs and read-only views', async ({ page, browser }) => {
  test.skip(!config, 'Set PLAYWRIGHT_APPLIANCE_CONFIG to a disposable canonical installation for CLI fixtures.');
  test.setTimeout(180_000);
  const fixture = await fixtureCommand<ApplianceFixture>('seed');
  let primaryError: unknown;
  try {
    await login(page);
    await openBindings(page, 'appliance', fixture.appliance);
    const selection = JSON.parse((await page.locator('#tableForApplianceItem_config').textContent())!).selection.values;
    expect(selection).toEqual(fixture.bindings.map(id => `item[Appliance_Item][${id}]`));
    for (const binding of fixture.bindings) {
      await expect(page.locator(`.add_relation[data-appliances-items-id="${binding}"]`)).toBeVisible();
    }
    await page.locator(`.add_relation[data-appliances-items-id="${fixture.bindings[0]}"]`).click();
    let dialog = page.locator('#add_relation_dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('input[name="appliances_items_id"]')).toHaveValue(String(fixture.bindings[0]));
    await dialog.locator('..').locator('.ui-dialog-titlebar-close').click();

    await openBindings(page, 'computer', fixture.computers[1]);
    await expect(page.locator('#tableForApplianceItem')).toContainText(fixture.applianceName);
    const reverseSelection = JSON.parse((await page.locator('#tableForApplianceItem_config').textContent())!).selection.values;
    expect(reverseSelection).toEqual([`item[Appliance_Item][${fixture.bindings[1]}]`]);
    // Remount the actual AJAX tab body before editing, so handlers must not multiply requests.
    const tab = page.getByRole('button', { name: /^Appliances\b/ });
    await tab.click();
    await tab.evaluate(button => {
      const target = document.getElementById(button.getAttribute('aria-controls')!);
      const body = target?.querySelector('.item-body');
      if (!body) throw new Error('Appliance tab body was not found');
      body.replaceChildren();
    });
    const remounted = page.waitForResponse(response => {
      const url = new URL(response.url());
      return url.pathname === '/ajax/common.tabs.php' && url.searchParams.get('_glpi_tab') === 'Appliance_Item$1';
    });
    await tab.click();
    expect((await remounted).ok()).toBe(true);
    await expect(page.locator('#tableForApplianceItem')).toBeVisible();
    await page.locator(`.add_relation[data-appliances-items-id="${fixture.bindings[1]}"]`).click();
    dialog = page.locator('#add_relation_dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('input[name="appliances_items_id"]')).toHaveValue(String(fixture.bindings[1]));
    // Hold the real Location response while a newer Domain selection completes.
    let locationArrived!: () => void;
    let releaseLocation!: () => void;
    let locationFinished!: () => void;
    const arrived = new Promise<void>(resolve => { locationArrived = resolve; });
    const released = new Promise<void>(resolve => { releaseLocation = resolve; });
    const finished = new Promise<void>(resolve => { locationFinished = resolve; });
    await page.route('**/ajax/dropdownAllItems.php', async route => {
      if (new URLSearchParams(route.request().postData() || '').get('idtable') !== 'Location') {
        await route.continue();
        return;
      }
      const response = await route.fetch();
      locationArrived();
      await released;
      await route.fulfill({ response });
      locationFinished();
    });
    await dialog.locator('select[name="itemtype"]').selectOption('Location');
    await arrived;
    await dialog.locator('select[name="itemtype"]').selectOption('Domain');
    await expect(dialog.locator(`select[name="items_id"] option[value="${fixture.domain}"]`)).toHaveCount(1);
    releaseLocation();
    await finished;
    await page.waitForLoadState('networkidle');
    await expect(dialog.locator(`select[name="items_id"] option[value="${fixture.domain}"]`)).toHaveCount(1);
    await expect(dialog.locator(`select[name="items_id"] option[value="${fixture.newLocation}"]`)).toHaveCount(0);
    await page.unroute('**/ajax/dropdownAllItems.php');
    await dialog.locator('select[name="itemtype"]').selectOption('Location');
    await expect(dialog.locator(`select[name="items_id"] option[value="${fixture.newLocation}"]`)).toHaveCount(1);
    await dialog.locator('select[name="items_id"]').selectOption(String(fixture.newLocation));
    await expect(dialog.locator('.select2-selection__rendered').filter({ hasText: fixture.newLocationName })).toHaveCount(1);
    await expect(dialog.locator('[data-appliance-fixture]')).toHaveCount(0);
    const writes: string[] = [];
    page.on('request', request => {
      if (request.method() === 'POST' && request.url().includes('/front/appliance_item_relation.form.php')) writes.push(request.postData() || '');
    });
    await Promise.all([page.waitForNavigation(), dialog.locator('[name="add"]').click()]);
    expect(writes).toHaveLength(1);
    let rows = await fixtureCommand<NestedRelation[]>('read', fixture);
    expect(rows.filter(row => row.appliances_items_id === fixture.bindings[0]).map(row => row.id)).toEqual([fixture.relations[0]]);
    const added = rows.find(row => row.items_id === fixture.newLocation)!;
    expect(added).toMatchObject({ appliances_items_id: fixture.bindings[1], itemtype: 'Location', locations_id: fixture.newLocation, networks_id: null, domains_id: null });
    expect(rows.filter(row => row.appliances_items_id === fixture.bindings[1])).toHaveLength(2);

    await openBindings(page, 'computer', fixture.computers[1]);
    writes.length = 0;
    await Promise.all([page.waitForNavigation(), page.locator(`.delete_relation[data-relations-id="${added.id}"]`).click()]);
    expect(writes).toHaveLength(1);
    expect(new URLSearchParams(writes[0]).get('id')).toBe(String(added.id));
    rows = await fixtureCommand<NestedRelation[]>('read', fixture);
    expect(rows.map(row => row.id)).toEqual(fixture.relations);

    const reader = await browser.newContext({ baseURL: process.env.PLAYWRIGHT_BASE_URL });
    try {
      const readerPage = await reader.newPage();
      await readerPage.goto('/index.php');
      await readerPage.locator('#login_name').fill(fixture.readerName);
      await readerPage.locator('#login_password').fill('E2EAppliance1!');
      await readerPage.locator('form[aria-label="Login Form"] input[type="submit"]').click();
      await readerPage.waitForLoadState('networkidle');
      for (const [kind, id] of [['appliance', fixture.appliance], ['computer', fixture.computers[1]]] as const) {
        await openBindings(readerPage, kind, id);
        await expect(readerPage.locator('#tableForApplianceItem')).toContainText('Browser location');
        await expect(readerPage.locator('.add_relation, .delete_relation, #add_relation_dialog')).toHaveCount(0);
      }
    } finally {
      await reader.close();
    }
  } catch (error) {
    primaryError = error;
    throw error;
  } finally {
    try {
      await fixtureCommand('clean', fixture);
    } catch (error) {
      if (primaryError) throw new AggregateError([primaryError, error], 'Browser verification and fixture cleanup failed');
      throw error;
    }
  }
});
