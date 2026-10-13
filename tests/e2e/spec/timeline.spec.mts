import { readFile } from 'node:fs/promises';
import { expect } from '@playwright/test';
import {
  fillRichTextForm,
  getInnerHtml,
  login,
  openTicket,
  seedTicket,
  submitAddForm,
  uploadRichTextFixture,
  test,
} from '../helpers.mjs';

test.beforeEach(async ({ page }) => {
  await login(page);
});

test('shows the seeded ticket timeline context', async ({ page, request, ticketFixtures }) => {
  const anonymous = await request.get('/ajax/ticketmonths.ajax.php', { maxRedirects: 0 });
  expect(anonymous.status()).toBe(302);
  expect(anonymous.headers().location).toContain('/index.php');

  const beforeResponse = await page.request.get('/ajax/ticketmonths.ajax.php');
  expect(beforeResponse.ok()).toBeTruthy();
  const before: number[] = await beforeResponse.json();
  expect(before).toHaveLength(6);
  expect(before.every((count) => Number.isInteger(count) && count >= 0)).toBeTruthy();

  const seed = await seedTicket(request, ticketFixtures);

  const afterResponse = await page.request.get('/ajax/ticketmonths.ajax.php');
  expect(afterResponse.ok()).toBeTruthy();
  const after: number[] = await afterResponse.json();
  expect(after).toEqual([...before.slice(0, 5), before[5] + 1]);

  await openTicket(page, seed);

  await expect(page.locator('.title', { hasText: seed.ticketName })).toBeVisible();
  await expect(page.getByTestId('timeline-history')).toContainText(seed.ticketContent);
});

test('creates a followup from the timeline', async ({ page, request, ticketFixtures }) => {
  const seed = await seedTicket(request, ticketFixtures);
  const followupContent = `E2E followup ${Date.now()}`;

  await openTicket(page, seed);
  await page.getByTestId('timeline-add-followup').click();

  const form = page.getByTestId('timeline-editor').locator('form').first();
  await fillRichTextForm(form, followupContent);
  await submitAddForm(form, page);

  const followupItem = page
    .locator('[data-testid="timeline-item"][data-item-type="ITILFollowup"]')
    .filter({ hasText: followupContent })
    .first();

  await expect(followupItem).toBeVisible();

  await page.reload();
  await expect(
    page
      .locator('[data-testid="timeline-item"][data-item-type="ITILFollowup"]')
      .filter({ hasText: followupContent })
      .first()
  ).toBeVisible();
});

test('uploads an image into the followup editor before submit', async ({ page, request, ticketFixtures }) => {
  const seed = await seedTicket(request, ticketFixtures);
  const followupContent = `E2E image upload ${Date.now()}`;

  await openTicket(page, seed);
  await page.getByTestId('timeline-add-followup').click();

  const form = page.getByTestId('timeline-editor').locator('form').first();
  await fillRichTextForm(form, `<p>${followupContent}</p>`);

  const image = Array.from(await readFile(new URL('../../fixtures/uploads/foo.png', import.meta.url)));
  const imageTypes = await page.evaluate(async (bytes) => {
    const detector = Reflect.get(window, 'fileType');
    const data = new Uint8Array(bytes);
    return [await detector.fromBuffer(data), await detector.fromBlob(new Blob([data]))];
  }, image);
  expect(imageTypes).toEqual([
    { ext: 'png', mime: 'image/png' },
    { ext: 'png', mime: 'image/png' },
  ]);

  await uploadRichTextFixture(form, 'tests/fixtures/uploads/foo.png', ticketFixtures);

  await expect(form.locator('input[name^="_content["]')).toHaveCount(1);
  await expect(form.locator('input[name^="_prefix_content["]')).toHaveCount(1);
  await expect(form.locator('input[name^="_tag_content["]')).toHaveCount(1);
  await expect(form.locator('.ck-content img[src^="blob:"]').first()).toBeVisible();
});

test('creates a followup with an uploaded image from the timeline', async ({ page, request, ticketFixtures }) => {
  const seed = await seedTicket(request, ticketFixtures);
  const followupContent = `E2E followup with image ${Date.now()}`;

  await openTicket(page, seed);
  await page.getByTestId('timeline-add-followup').click();

  const form = page.getByTestId('timeline-editor').locator('form').first();
  await fillRichTextForm(form, `<p>${followupContent}</p>`);
  await uploadRichTextFixture(form, 'tests/fixtures/uploads/foo.png', ticketFixtures);
  await submitAddForm(form, page);

  const followupItem = page
    .locator('[data-testid="timeline-item"][data-item-type="ITILFollowup"]')
    .filter({ hasText: followupContent })
    .first();

  await expect(followupItem).toBeVisible();
  await expect(followupItem.locator('img').first()).toBeVisible();
  await expect.poll(async () => (await getInnerHtml(followupItem)).includes('/front/document.send.php?docid=')).toBe(true);
  await expect.poll(async () => (await getInnerHtml(followupItem)).includes('blob:')).toBe(false);
  await expect.poll(async () => (await getInnerHtml(followupItem)).includes('data:image/')).toBe(false);

  await page.reload();

  const persistedFollowupItem = page
    .locator('[data-testid="timeline-item"][data-item-type="ITILFollowup"]')
    .filter({ hasText: followupContent })
    .first();

  await expect(persistedFollowupItem).toBeVisible();
  await expect(persistedFollowupItem.locator('img').first()).toBeVisible();
  await expect.poll(async () => (await getInnerHtml(persistedFollowupItem)).includes('/front/document.send.php?docid=')).toBe(true);
  await expect.poll(async () => (await getInnerHtml(persistedFollowupItem)).includes('blob:')).toBe(false);
  await expect.poll(async () => (await getInnerHtml(persistedFollowupItem)).includes('data:image/')).toBe(false);
});

test('creates a task from the timeline', async ({ page, request, ticketFixtures }) => {
  const seed = await seedTicket(request, ticketFixtures);
  const taskContent = `E2E task ${Date.now()}`;

  await openTicket(page, seed);
  await page.getByTestId('timeline-add-task').click();

  const form = page.getByTestId('timeline-editor').locator('form').first();
  await fillRichTextForm(form, taskContent);
  await submitAddForm(form, page);

  const taskItem = page
    .locator('[data-testid="timeline-item"][data-item-type="TicketTask"]')
    .filter({ hasText: taskContent })
    .first();

  await expect(taskItem).toBeVisible();

  await page.reload();
  await expect(
    page
      .locator('[data-testid="timeline-item"][data-item-type="TicketTask"]')
      .filter({ hasText: taskContent })
      .first()
  ).toBeVisible();
});

test('marks a seeded todo task as done from the timeline', async ({ page, request, ticketFixtures }) => {
  const seed = await seedTicket(request, ticketFixtures, { withTaskState: 'todo' });

  await openTicket(page, seed);

  const taskItem = page.locator(`[data-testid="timeline-item"][data-item-id="${seed.taskId}"]`);
  const toggle = taskItem.getByTestId('timeline-task-state-toggle');

  await expect(toggle).toHaveAttribute('data-state', '1');
  await toggle.click();
  await expect(toggle).toHaveAttribute('data-state', '2');

  await page.reload();
  await expect(
    page
      .locator(`[data-testid="timeline-item"][data-item-id="${seed.taskId}"]`)
      .getByTestId('timeline-task-state-toggle')
  ).toHaveAttribute('data-state', '2');
});

test('marks a seeded done task back to todo from the timeline', async ({ page, request, ticketFixtures }) => {
  const seed = await seedTicket(request, ticketFixtures, { withTaskState: 'done' });

  await openTicket(page, seed);

  const taskItem = page.locator(`[data-testid="timeline-item"][data-item-id="${seed.taskId}"]`);
  const toggle = taskItem.getByTestId('timeline-task-state-toggle');

  await expect(toggle).toHaveAttribute('data-state', '2');
  await toggle.click();
  await expect(toggle).toHaveAttribute('data-state', '1');

  await page.reload();
  await expect(
    page
      .locator(`[data-testid="timeline-item"][data-item-id="${seed.taskId}"]`)
      .getByTestId('timeline-task-state-toggle')
  ).toHaveAttribute('data-state', '1');
});
