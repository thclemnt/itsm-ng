// SPDX-License-Identifier: GPL-2.0-or-later
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';
import { login } from '../helpers.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const config = process.env.PLAYWRIGHT_DROPDOWN_CONFIG;

function fixture(action: string): Record<string, number> {
  const output = execFileSync('php', [path.join(root, 'tests/playwright/fixtures/dropdown-choices-web-fixture.php'), config!, action], {
    cwd: root, env: process.env, encoding: 'utf8',
  });
  return action === 'clean' ? {} : JSON.parse(output);
}

async function openChoices(page: Page, field: string, expected: string): Promise<void> {
  const select = page.locator(`select[name="${field}"]`).first();
  await expect(select).toHaveClass(/select2-hidden-accessible/);
  const id = await select.getAttribute('id');
  const response = page.waitForResponse(response => response.url().includes('/ajax/getDropdownValue.php')
    && response.request().method() === 'POST');
  await page.locator(`[aria-labelledby="select2-${id}-container"]`).click();
  const completed = await response;
  expect(completed.status()).toBe(200);
  const form = new URLSearchParams(completed.request().postData() || '');
  expect(form.get('_idor_token')).toBeTruthy();
  await expect(page.locator('.select2-results__option').filter({ hasText: expected }).first()).toBeVisible();
  await page.keyboard.press('Escape');
}

test('Project and asset forms issue working tokens through their real dropdown components', async ({ page }) => {
  test.skip(!config, 'Set PLAYWRIGHT_DROPDOWN_CONFIG to an isolated installation for this database fixture.');
  const seed = fixture('seed');
  try {
    await login(page);
    await page.goto('/front/central.php?active_entity=all&is_recursive=1');
    await page.goto(`/front/project.form.php?id=${seed.project}`);
    await openChoices(page, 'projects_id', 'DropdownHTTPFixtureParent');
    await page.goto(`/front/project.form.php?id=${seed.project}&forcetab=Item_Project$1`);
    const linkedChoices = page.waitForResponse(response => response.url().includes('/ajax/getDropdownValue.php')
      && response.request().method() === 'POST');
    await page.locator('#dropdown_itemtypeForProject').selectOption('Computer');
    const linkedResponse = await linkedChoices;
    expect(linkedResponse.status()).toBe(200);
    expect(new URLSearchParams(linkedResponse.request().postData() || '').get('_idor_token')).toBeTruthy();
    await expect(page.locator(`#dropdown_items_idForProject option[value="${seed.computerA}"]`)).toHaveText('DropdownHTTPFixtureComputerA');
    await page.goto(`/front/computer.form.php?id=${seed.computerA}`);
    // This form renders through expandSelect, rather than Dropdown::show.
    await openChoices(page, 'locations_id', 'DropdownHTTPFixtureLocation');
  } finally {
    fixture('clean');
  }
});
