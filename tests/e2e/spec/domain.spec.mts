// SPDX-License-Identifier: GPL-2.0-or-later
import { execFile } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';
import { expect, test, type Locator, type Page } from '@playwright/test';
import { closeApiSession, createItem, getItem, initApiSession, login, type ApiSession } from '../helpers.mjs';

interface Guard { token: string; prefix: string; }
interface DomainRow { id: number; suppliers_id: number | null; is_helpdesk_visible: boolean | number | string; }
interface Readback {
  domain: DomainRow;
  financial: Array<{ id: number; suppliers_id: number; itemtype: string; items_id: number }>;
  documents: Array<{ id: number; documents_id: number; domains_id: number; itemtype: string; items_id: number }>;
}

const config = process.env.PLAYWRIGHT_DOMAIN_CONFIG;
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const execute = promisify(execFile);

async function fixture<T>(action: string, input?: Record<string, unknown>): Promise<T> {
  const args = ['tests/database-portability/fixtures/domain-web-fixture.php', config!, action];
  if (input) args.push(JSON.stringify(input));
  try {
    const { stdout } = await execute('php', args, { cwd: root });
    return JSON.parse(stdout) as T;
  } catch (error) {
    const failure = error as Error & { stdout?: string; stderr?: string };
    throw new Error(`${failure.message}\n${failure.stdout || ''}\n${failure.stderr || ''}`, { cause: error });
  }
}

async function choose(page: Page, select: Locator, name: string, id: number): Promise<void> {
  await expect(select).toHaveClass(/select2-hidden-accessible/);
  const selectId = await select.getAttribute('id');
  const response = page.waitForResponse(response => response.url().includes('/ajax/getDropdownValue.php')
    && response.request().method() === 'POST'
    && new URLSearchParams(response.request().postData() || '').get('searchText') === name);
  await page.locator(`[aria-labelledby="select2-${selectId}-container"]`).click();
  await page.locator('.select2-container--open .select2-search__field').fill(name);
  const choices = await response;
  expect(choices.status()).toBe(200);
  expect(new URLSearchParams(choices.request().postData() || '').get('_idor_token')).toBeTruthy();
  await page.getByRole('option', { name }).click();
  await expect(select).toHaveValue(String(id));
}

test('Domain supplier edits preserve financial ownership, visibility and real document associations', async ({ page, request }) => {
  test.skip(!config, 'Set PLAYWRIGHT_DOMAIN_CONFIG to a disposable canonical installation for private CLI fixtures.');
  // Keep the harness timeout: this covers actual form interactions, not history replay.
  const guard = await fixture<Guard>('guard');
  let session: ApiSession | undefined;
  let primaryError: unknown;
  try {
    session = await initApiSession(request);
    const seed = async (type: string, suffix: string, values: Record<string, unknown> = {}): Promise<number> => {
      const id = await createItem(request, session!, type, { name: guard.prefix + suffix, entities_id: 0, ...values });
      await fixture('own', { token: guard.token, itemtype: type, id });
      return id;
    };
    const initialSupplier = await seed('Supplier', 'initial-commercial');
    const directName = guard.prefix + 'edited-commercial';
    const directSupplier = await seed('Supplier', 'edited-commercial');
    const financialSupplier = await seed('Supplier', 'financial');
    const domain = await seed('Domain', 'domain', { suppliers_id: initialSupplier, is_helpdesk_visible: 1 });
    const documentName = guard.prefix + 'document';
    const document = await seed('Document', 'document');
    const financial = await fixture<{ id: number }>('financial', { token: guard.token, supplier: financialSupplier });

    await login(page);
    await page.goto(`/front/domain.form.php?id=${domain}`);
    const form = page.locator('form').filter({ has: page.locator('input[name="id"]') })
      .filter({ has: page.locator('input[type="checkbox"][name="is_helpdesk_visible"]') });
    await expect(form).toHaveCount(1);
    const visibility = form.locator('input[type="checkbox"][name="is_helpdesk_visible"]');
    await expect(visibility).toBeChecked();
    await choose(page, form.locator('select[name="suppliers_id"]'), directName, directSupplier);
    await visibility.uncheck();
    const saved = page.waitForResponse(response => response.url().includes('/front/domain.form.php')
      && response.request().method() === 'POST');
    await form.locator('button[name="update"], input[type="submit"][name="update"]').click();
    expect((await saved).status()).toBeLessThan(400);
    await page.waitForLoadState('networkidle');
    const edited = await getItem<DomainRow>(request, session, 'Domain', domain);
    expect(Number(edited.suppliers_id)).toBe(directSupplier);
    expect(Number(edited.is_helpdesk_visible)).toBe(0);
    await expect(visibility).not.toBeChecked();
    const financialRow = await getItem<{ suppliers_id: number | string }>(request, session, 'Infocom', financial.id);
    expect(Number(financialRow.suppliers_id)).toBe(financialSupplier);

    // A hidden helpdesk item still supports ordinary document associations.
    const documents = page.getByRole('button', { name: /^Documents\b/ });
    await expect(documents).toHaveCount(1);
    if ((await documents.getAttribute('aria-expanded')) !== 'true') await documents.click();
    const attachmentForm = page.locator('form').filter({ has: page.locator('select[name="documents_id"]') });
    await expect(attachmentForm).toHaveCount(1);
    await expect(attachmentForm.locator('input[name="itemtype"]')).toHaveValue('Domain');
    await expect(attachmentForm.locator('input[name="items_id"]')).toHaveValue(String(domain));
    await choose(page, attachmentForm.locator('select[name="documents_id"]'), documentName, document);
    const attached = page.waitForResponse(response => response.url().includes('/front/document_item.form.php')
      && response.request().method() === 'POST');
    await attachmentForm.locator('button[name="add"]').click();
    expect((await attached).status()).toBeLessThan(400);
    await page.waitForLoadState('networkidle');
    await expect(page.locator(`a[href*="document.form.php?id=${document}"]`).filter({ hasText: documentName }).first()).toBeVisible();
    const result = await fixture<Readback>('read', { token: guard.token });
    expect(Number(result.domain.suppliers_id)).toBe(directSupplier);
    expect(Number(result.domain.is_helpdesk_visible)).toBe(0);
    expect(result.financial).toHaveLength(1);
    expect(Number(result.financial[0].suppliers_id)).toBe(financialSupplier);
    expect(result.documents).toHaveLength(1);
    expect(Number(result.documents[0].documents_id)).toBe(document);
    expect(Number(result.documents[0].domains_id)).toBe(domain);
    expect(Number(result.documents[0].items_id)).toBe(domain);
    expect(result.documents[0].itemtype).toBe('Domain');
  } catch (error) {
    primaryError = error;
  } finally {
    const failures: unknown[] = [];
    if (primaryError) failures.push(primaryError);
    if (session) {
      try { await closeApiSession(request, session); } catch (error) { failures.push(error); }
    }
    try { await fixture('clean', { token: guard.token }); } catch (error) { failures.push(error); }
    if (failures.length) throw new AggregateError(failures, 'Domain browser flow or owned cleanup failed');
  }
});
