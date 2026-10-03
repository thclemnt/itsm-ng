// SPDX-License-Identifier: GPL-2.0-or-later
import { execFile } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';
import { expect, test, type Locator, type Page } from '@playwright/test';
import { closeApiSession, getItem, initApiSession, login, type ApiSession } from '../helpers.mjs';

interface Guard { token: string; prefix: string; }
interface Seed {
  source: number; destination: number; commercial: number; financialSupplier: number;
  domain: number; domainName: string; document: number; contract: number; mode: number;
}
interface Snapshot {
  domain: { id: number | string; entities_id: number | string; suppliers_id: number | string | null };
  financial: Array<{ id: number | string; suppliers_id: number | string; itemtype: string; items_id: number | string }>;
  documents: Array<{ id: number | string; documents_id: number | string; domains_id: number | string; itemtype: string; items_id: number | string }>;
  contracts: Array<{ id: number | string; contracts_id: number | string; domains_id: number | string; itemtype: string; items_id: number | string }>;
  history: Array<Record<string, unknown>>;
  targets: {
    document: { id: number | string; entities_id: number | string };
    contract: { id: number | string; entities_id: number | string };
    commercial: Record<string, unknown>;
    financialSupplier: Record<string, unknown>;
  };
}

const config = process.env.PLAYWRIGHT_TRANSFER_CONFIG;
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const execute = promisify(execFile);

async function fixture<T>(action: string, guard?: Guard): Promise<T> {
  const args = ['tests/database-portability/fixtures/transfer-web-fixture.php', config!, action];
  if (guard) args.push(JSON.stringify({ token: guard.token }));
  try {
    const { stdout } = await execute('php', args, { cwd: root });
    return JSON.parse(stdout) as T;
  } catch (error) {
    const failure = error as Error & { stdout?: string; stderr?: string };
    // Do not echo command arguments or private manifest/API capabilities.
    throw new Error(`Transfer CLI fixture ${action} failed\n${failure.stdout || ''}\n${failure.stderr || ''}`, { cause: error });
  }
}

async function addActualTransferList(page: Page, seed: Seed): Promise<void> {
  await page.goto(`/front/domain.form.php?id=${seed.domain}`);
  await expect(page.locator(`input[name="id"][value="${seed.domain}"]`).first()).toBeAttached();
  // Refuse a mismatched fixture/server database before any browser mutation.
  await expect(page.locator('input[name="name"]').first()).toHaveValue(seed.domainName);
  await page.locator('.navigationheader button[aria-label="Actions"]').click();
  const action = page.locator('.navigationheader [data-action="MassiveAction:add_transfer_list"]');
  await expect(action).toBeVisible();
  const specialized = page.waitForResponse(response => response.url().includes('/ajax/dropdownMassiveAction.php')
    && response.request().method() === 'POST');
  await action.click();
  expect((await specialized).ok()).toBe(true);
  const dialog = page.getByRole('dialog');
  await expect(dialog).toContainText('Are you sure you want to add this item to transfer list?');
  const form = dialog.locator('xpath=ancestor::form');
  await expect(form.locator('input[name="_glpi_csrf_token"]')).not.toHaveValue('');
  const added = page.waitForResponse(response => response.url().includes('/front/massiveaction.php')
    && response.request().method() === 'POST');
  await dialog.locator('[name="massiveaction"]').click();
  expect((await added).status()).toBeLessThan(400);
  await page.waitForURL('**/front/transfer.action.php');
  await expect(page.locator('table[aria-label="Items to transfer"]')).toContainText(seed.domainName);
}

async function actualTransferForm(page: Page, seed: Seed, preserve: boolean): Promise<Locator> {
  const mode = page.locator('table[aria-label="Items to transfer"] select[name="id"]');
  await expect(mode).toHaveCount(1);
  const loaded = page.waitForResponse(response => response.url().includes('/ajax/transfers.php')
    && response.request().method() === 'POST');
  await mode.selectOption(String(seed.mode));
  expect((await loaded).ok()).toBe(true);
  await page.waitForLoadState('networkidle');
  const form = page.locator('#transfer_form form').filter({ has: page.locator('select[name="to_entity"]') });
  await expect(form).toHaveCount(1);
  await expect(form.locator('input[name="_glpi_csrf_token"]')).not.toHaveValue('');
  const destination = form.locator('select[name="to_entity"]');
  // Use the real Entity dropdown's options; never insert fixture-created DOM options.
  await expect(destination.locator(`option[value="${seed.destination}"]`)).toHaveCount(1);
  await destination.selectOption(String(seed.destination));
  for (const name of ['keep_infocom', 'keep_document', 'keep_contract', 'keep_history']) {
    await form.locator(`select[name="${name}"]`).selectOption(preserve ? '1' : '0');
  }
  await form.locator('select[name="keep_supplier"]').selectOption('1');
  return form;
}

async function executeTransfer(page: Page, form: Locator): Promise<void> {
  const sent = page.waitForResponse(response => response.url().includes('/front/transfer.action.php')
    && response.request().method() === 'POST');
  await form.locator('input[name="transfer"]').click();
  expect((await sent).status()).toBeLessThan(400);
  await page.waitForLoadState('networkidle');
}

function transferResult(page: Page): Locator {
  // The controller result also contains its Back link; an exact text locator
  // for the outcome alone can only match an optional notification toast.
  return page.locator('div.b.center').filter({ has: page.locator('a[href="central.php"]') });
}

test('Transfer refuses incompatible commercial ownership, retains its real list and retries without losing financial roles', async ({ page, request }) => {
  test.skip(!config, 'Set PLAYWRIGHT_TRANSFER_CONFIG to a disposable canonical installation for private CLI fixtures.');
  // The shared harness retains its fixed 60-second timeout and zero local retries.
  const guard = await fixture<Guard>('guard');
  let session: ApiSession | undefined;
  let primaryError: unknown;
  let primaryFailed = false;
  try {
    const seed = await fixture<Seed>('seed', guard);
    const before = await fixture<Snapshot>('read', guard);
    expect(Number(before.domain.entities_id)).toBe(seed.source);
    expect(Number(before.domain.suppliers_id)).toBe(seed.commercial);
    expect(before.financial).toHaveLength(1);
    expect(Number(before.financial[0].suppliers_id)).toBe(seed.financialSupplier);
    expect(before.documents).toHaveLength(1);
    expect(before.contracts).toHaveLength(1);
    expect(before.history.length).toBeGreaterThan(0);

    await login(page);
    // Normal authorized entity switching supplies the actual multi-entity session.
    await page.goto('/front/central.php?active_entity=0&is_recursive=1');
    await addActualTransferList(page, seed);
    await executeTransfer(page, await actualTransferForm(page, seed, false));
    const refused = transferResult(page);
    await expect(refused).toHaveCount(1);
    await expect(refused).toBeVisible();
    await expect(refused).toHaveText(/^Transfer failed\s*Back$/);
    await expect(refused.filter({ hasText: /^Operation successful\s*Back$/ })).toHaveCount(0);
    await expect(page.getByText('Operation successful', { exact: true })).toHaveCount(0);
    expect(await fixture<Snapshot>('read', guard)).toEqual(before);
    // This proves ordinary preflight refusal preserves data. Late-hook DML
    // rollback is a separate focused contract, not inferred from this browser flow.
    await page.goto('/front/transfer.action.php');
    await expect(page.locator('table[aria-label="Items to transfer"]')).toContainText(seed.domainName);

    session = await initApiSession(request);
    const changed = await request.put(`${session.apiUrl}Domain/${seed.domain}`, {
      headers: { 'App-Token': process.env.PLAYWRIGHT_APP_TOKEN!, 'Session-Token': session.sessionToken },
      data: { input: { id: seed.domain, suppliers_id: 0 } },
    });
    expect(changed.ok()).toBe(true);
    const cleared = await getItem<{ suppliers_id: number | string | null }>(request, session, 'Domain', seed.domain);
    expect(Number(cleared.suppliers_id)).toBe(0);
    const afterEdit = await fixture<Snapshot>('read', guard);
    expect(afterEdit.financial).toEqual(before.financial);
    expect(afterEdit.documents).toEqual(before.documents);
    expect(afterEdit.contracts).toEqual(before.contracts);
    expect(afterEdit.targets).toEqual(before.targets);

    await executeTransfer(page, await actualTransferForm(page, seed, true));
    const accepted = transferResult(page);
    await expect(accepted).toHaveCount(1);
    await expect(accepted).toBeVisible();
    await expect(accepted).toHaveText(/^Operation successful\s*Back$/);
    await expect(accepted.filter({ hasText: /^Transfer failed\s*Back$/ })).toHaveCount(0);
    await expect(page.getByText('Transfer failed', { exact: true })).toHaveCount(0);
    const transferred = await fixture<Snapshot>('read', guard);
    expect(Number(transferred.domain.entities_id)).toBe(seed.destination);
    expect(Number(transferred.domain.suppliers_id)).toBe(0);
    expect(transferred.financial).toHaveLength(1);
    expect(Number(transferred.financial[0].id)).toBe(Number(before.financial[0].id));
    expect(Number(transferred.financial[0].suppliers_id)).toBe(seed.financialSupplier);
    expect(transferred.targets.financialSupplier).toEqual(before.targets.financialSupplier);
    expect(transferred.targets.commercial).toEqual(before.targets.commercial);
    expect(Number(transferred.targets.document.entities_id)).toBe(seed.destination);
    expect(Number(transferred.targets.contract.entities_id)).toBe(seed.destination);
    for (const rows of [transferred.documents, transferred.contracts]) {
      expect(rows).toHaveLength(1);
      expect(rows[0].itemtype).toBe('Domain');
      expect(Number(rows[0].items_id)).toBe(seed.domain);
      expect(Number(rows[0].domains_id)).toBe(seed.domain);
    }
    expect(Number(transferred.documents[0].documents_id)).toBe(seed.document);
    expect(Number(transferred.contracts[0].contracts_id)).toBe(seed.contract);
    expect(transferred.history.length).toBeGreaterThanOrEqual(afterEdit.history.length);
    await page.goto('/front/transfer.action.php');
    await expect(page.locator('table[aria-label="Items to transfer"]')).toHaveCount(0);
    await expect(page.getByText('No selected element or badly defined operation', { exact: true })).toBeVisible();
  } catch (error) {
    primaryError = error;
    primaryFailed = true;
  } finally {
    const failures: unknown[] = [];
    if (primaryFailed) failures.push(primaryError);
    if (session) {
      try { await closeApiSession(request, session); } catch (error) { failures.push(error); }
    }
    try { await fixture('clean', guard); } catch (error) { failures.push(error); }
    // Preserve Playwright's original assertion and source location when owned
    // cleanup succeeds, while still reporting every failure if cleanup fails.
    if (failures.length === 1) throw failures[0];
    if (failures.length) throw new AggregateError(failures, 'Transfer browser flow or owned cleanup failed');
  }
});
