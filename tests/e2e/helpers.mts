import { randomUUID } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test as baseTest, type APIRequestContext, type APIResponse, type Locator, type Page } from '@playwright/test';

export interface SeedTicketOptions {
  withTaskState?: 'todo' | 'done';
}

export interface SeedTicketResult {
  ticketId: number;
  ticketName: string;
  ticketContent: string;
  ticketUrl: string;
  userId: number;
  taskId?: number;
  taskContent?: string;
  taskState?: string;
}

export type ActorPanelRole = 'requester' | 'observer' | 'assign';

export interface ApiSession {
  apiUrl: string;
  sessionToken: string;
  userId: number;
}

interface RichTextUploadReceipt {
  filename: string;
  prefix: string;
  tag: string;
}

/** Each test owns exact names before sending requests, including responses that fail to return IDs. */
export class TicketFixtures {
  private readonly tickets = new Map<string, Set<number>>();
  private readonly uploads: RichTextUploadReceipt[] = [];

  constructor(private readonly request: APIRequestContext) {}

  ownTicket(name: string, id?: number): void {
    const ids = this.tickets.get(name) ?? new Set<number>();
    if (id !== undefined) ids.add(id);
    this.tickets.set(name, ids);
  }

  ownUpload(receipt: RichTextUploadReceipt): void {
    this.uploads.push(receipt);
  }

  async cleanup(): Promise<unknown[]> {
    const errors: unknown[] = [];
    if (!this.tickets.size && !this.uploads.length) return errors;
    let session: ApiSession;
    try { session = await initApiSession(this.request); }
    catch (error) { return [error]; }
    try {
      for (const [name, ids] of this.tickets) {
        try {
          for (const row of await collection(this.request, session, 'Ticket/', { 'searchText[name]': name })) {
            if (row.name === name) ids.add(ownedId(row.id, 'Ticket'));
          }
        } catch (error) { errors.push(error); }
        for (const id of ids) {
          try {
            const ticket = await getItem<{ name: string }>(this.request, session, 'Ticket', id);
            expect(ticket.name, 'Only the exact owned ticket may be purged').toBe(name);
            const children: Array<[string, number]> = [];
            for (const type of ['TicketTask', 'ITILFollowup', 'Ticket_User', 'Document_Item']) {
              try {
                for (const row of await collection(this.request, session, `Ticket/${id}/${type}/`)) {
                  children.push([type, ownedId(row.id, type)]);
                  if (type === 'ITILFollowup') {
                    for (const link of await collection(this.request, session, `ITILFollowup/${row.id}/Document_Item/`)) {
                      children.push(['Document_Item', ownedId(link.id, 'Document_Item')]);
                    }
                  }
                }
              } catch (error) { errors.push(error); }
            }
            // Public Ticket purge invokes task/followup/actor/link hooks; documents have a separate owner below.
            await purgeItem(this.request, session, 'Ticket', id);
            for (const [type, child] of children) {
              try { await expectItemMissing(this.request, session, type, child); }
              catch (error) { errors.push(error); }
            }
          } catch (error) { errors.push(error); }
        }
      }
      for (const upload of this.uploads) {
        const ids = new Set<number>();
        try {
          for (const row of await collection(this.request, session, 'Document/', { 'searchText[tag]': upload.tag })) {
            if (row.tag === upload.tag) ids.add(ownedId(row.id, 'Document'));
          }
          if (!ids.size) {
            // Unsaved uploads and content-deduplicated attachments still own this exact temporary file.
            // Document's public upload path consumes it; its purge preserves files referenced by other documents.
            try {
              ids.add(await createItem(this.request, session, 'Document', {
                name: `E2E upload ${upload.tag}`, tag: upload.tag,
                _filename: [upload.filename], _prefix_filename: [upload.prefix], _only_if_upload_succeed: true,
              }));
            } catch (error) {
              errors.push(error);
              // Recover a committed document if its creation response failed after the file moved.
              for (const row of await collection(this.request, session, 'Document/', { 'searchText[tag]': upload.tag })) {
                if (row.tag === upload.tag) ids.add(ownedId(row.id, 'Document'));
              }
            }
          }
        } catch (error) { errors.push(error); }
        for (const id of ids) {
          try {
            const document = await getItem<{ tag: string }>(this.request, session, 'Document', id);
            expect(document.tag, 'A linked shared document is never an upload cleanup target').toBe(upload.tag);
            await purgeItem(this.request, session, 'Document', id);
          } catch (error) { errors.push(error); }
        }
      }
    } finally {
      try { await closeApiSession(this.request, session); }
      catch (error) { errors.push(error); }
    }
    return errors;
  }
}

export const test = baseTest.extend<{ ticketFixtures: TicketFixtures }>({
  ticketFixtures: [async ({ request }, use, testInfo) => {
    const owner = new TicketFixtures(request);
    try { await use(owner); }
    finally {
      const errors = await owner.cleanup();
      if (errors.length) {
        // Playwright retains the original assertion failure; include it with every teardown failure too.
        throw new AggregateError([...testInfo.errors, ...errors], 'Ticket browser flow or owned cleanup failed');
      }
    }
  }, { auto: true }],
});

function ownedId(value: unknown, type: string): number {
  const id = Number(value);
  if (!Number.isSafeInteger(id) || id <= 0) throw new Error(`Invalid owned ${type} ID: ${String(value)}`);
  return id;
}

function apiHeaders(session: ApiSession): Record<string, string> {
  return { 'App-Token': getAppToken(), 'Session-Token': session.sessionToken };
}

async function collection(
  request: APIRequestContext, session: ApiSession, resource: string, params: Record<string, string> = {}
): Promise<Array<Record<string, unknown>>> {
  const rows: Array<Record<string, unknown>> = [];
  for (let start = 0; ; start += 100) {
    const response = await request.get(`${session.apiUrl}${resource}`, {
      headers: apiHeaders(session), params: { ...params, range: `${start}-${start + 99}` },
    });
    const page = getCollectionItems(await parseJsonResponse<unknown>(response, `Reading owned ${resource}`));
    rows.push(...page);
    const total = Number(response.headers()['content-range']?.split('/')[1]);
    if (page.length < 100 || (Number.isFinite(total) && rows.length >= total)) return rows;
  }
}

async function expectItemMissing(request: APIRequestContext, session: ApiSession, type: string, id: number): Promise<void> {
  const response = await request.get(`${session.apiUrl}${type}/${id}`, { headers: apiHeaders(session) });
  expect(response.status(), `Owned ${type}#${id} must be gone after public purge`).toBe(404);
  const body = await response.json() as unknown[];
  expect(body[0]).toBe('ERROR_ITEM_NOT_FOUND');
}

async function purgeItem(request: APIRequestContext, session: ApiSession, type: string, id: number): Promise<void> {
  const response = await request.delete(`${session.apiUrl}${type}/${id}`, {
    headers: apiHeaders(session), params: { force_purge: true },
  });
  expect(response.status(), `Public purge of owned ${type}#${id}`).toBe(200);
  await expectItemMissing(request, session, type, id);
}

interface RichTextContext {
  editorId: string | null;
  page: Page;
  textarea: Locator;
}

const dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(dirname, '..', '..');

function resolveRepoPath(relativePath: string): string {
  return path.resolve(repoRoot, relativePath);
}

function getMimeType(filePath: string): string {
  const extension = path.extname(filePath).toLowerCase();
  switch (extension) {
    case '.png':
      return 'image/png';
    case '.jpg':
    case '.jpeg':
      return 'image/jpeg';
    case '.gif':
      return 'image/gif';
    case '.webp':
      return 'image/webp';
    default:
      throw new Error(`Unsupported fixture type for rich text upload: ${filePath}`);
  }
}

function getAppToken(): string {
  const appToken = process.env.PLAYWRIGHT_APP_TOKEN;
  if (!appToken) {
    throw new Error('Playwright app token is required for E2E API seeding.');
  }

  return appToken;
}

function getApiUrl(_request: APIRequestContext): string {
  const baseURL = process.env.PLAYWRIGHT_BASE_URL;
  if (!baseURL) {
    throw new Error('Playwright baseURL is required for E2E API seeding.');
  }

  return new URL('/apirest.php/', baseURL).toString();
}

async function parseJsonResponse<T>(response: APIResponse, context: string): Promise<T> {
  const body = await response.text();
  if (!response.ok()) {
    throw new Error(`${context} failed with ${response.status()}: ${body}`);
  }

  try {
    return JSON.parse(body) as T;
  } catch (error) {
    throw new Error(`${context} returned invalid JSON: ${body}`, { cause: error });
  }
}

export async function initApiSession(request: APIRequestContext): Promise<ApiSession> {
  const apiUrl = getApiUrl(request);
  const appToken = getAppToken();
  const credentials = Buffer.from('itsm:itsm').toString('base64');
  const response = await request.get(`${apiUrl}initSession`, {
    headers: {
      'App-Token': appToken,
      Authorization: `Basic ${credentials}`,
    },
    params: {
      get_full_session: 'true',
    },
  });
  const data = await parseJsonResponse<{ session_token?: string; session?: { glpiID?: number | string } }>(
    response,
    'API session initialization'
  );
  if (!data.session_token) {
    throw new Error(`API session initialization did not return a session token: ${JSON.stringify(data)}`);
  }

  const userId = Number(data.session?.glpiID);
  if (Number.isNaN(userId) || userId <= 0) {
    throw new Error(`API session initialization did not return a valid user id: ${JSON.stringify(data)}`);
  }

  return { apiUrl, sessionToken: data.session_token, userId };
}

export async function closeApiSession(request: APIRequestContext, session: ApiSession): Promise<void> {
  const response = await request.get(`${session.apiUrl}killSession`, {
    headers: {
      'App-Token': getAppToken(),
      'Session-Token': session.sessionToken,
    },
  });

  if (!response.ok()) {
    const body = await response.text();
    throw new Error(`API session shutdown failed with ${response.status()}: ${body}`);
  }
}

export async function createItem(
  request: APIRequestContext,
  session: ApiSession,
  itemtype: string,
  input: Record<string, unknown>
): Promise<number> {
  const response = await request.post(`${session.apiUrl}${itemtype}/`, {
    headers: {
      'App-Token': getAppToken(),
      'Content-Type': 'application/json',
      'Session-Token': session.sessionToken,
    },
    data: {
      input,
    },
  });

  const data = await parseJsonResponse<{ id?: number | string; message?: string }>(response, `Creating ${itemtype}`);
  if (data.id === undefined || Number.isNaN(Number(data.id))) {
    throw new Error(`Creating ${itemtype} did not return a valid id: ${JSON.stringify(data)}`);
  }

  return Number(data.id);
}

export async function getItem<T>(
  request: APIRequestContext,
  session: ApiSession,
  itemtype: string,
  id: number
): Promise<T> {
  const response = await request.get(`${session.apiUrl}${itemtype}/${id}`, {
    headers: {
      'App-Token': getAppToken(),
      'Session-Token': session.sessionToken,
    },
  });

  return parseJsonResponse<T>(response, `Fetching ${itemtype}#${id}`);
}

export async function seedTicket(request: APIRequestContext, owner: TicketFixtures, options: SeedTicketOptions = {}): Promise<SeedTicketResult> {
  const session = await initApiSession(request);
  let primary: unknown;
  let failed = false;

  try {
    const suffix = randomUUID();
    const ticketName = `E2E Ticket ${suffix}`;
    const ticketContent = `Seeded ticket description ${suffix}`;
    owner.ownTicket(ticketName);
    const ticketId = await createItem(request, session, 'Ticket', {
      name: ticketName,
      content: ticketContent,
      description: ticketContent,
      _users_id_requester: session.userId,
      _users_id_assign: session.userId,
    });

    owner.ownTicket(ticketName, ticketId);
    const result: SeedTicketResult = {
      ticketId,
      ticketName,
      ticketContent,
      ticketUrl: `/front/ticket.form.php?id=${ticketId}`,
      userId: session.userId,
    };

    if (options.withTaskState) {
      const taskStateMap = {
        todo: 1,
        done: 2,
      } as const;
      const taskContent = `Seeded task ${suffix}`;
      const taskId = await createItem(request, session, 'TicketTask', {
        tickets_id: ticketId,
        content: taskContent,
        state: taskStateMap[options.withTaskState],
        users_id_tech: session.userId,
      });
      const task = await getItem<{ content?: string; state?: number | string }>(request, session, 'TicketTask', taskId);

      result.taskId = taskId;
      result.taskContent = task.content ?? taskContent;
      result.taskState = String(task.state ?? taskStateMap[options.withTaskState]);
    }

    return result;
  } catch (error) {
    primary = error;
    failed = true;
    throw error;
  } finally {
    try { await closeApiSession(request, session); }
    catch (error) {
      if (failed) throw new AggregateError([primary, error], 'Ticket seed and API session shutdown failed');
      throw error;
    }
  }
}

function getCollectionItems(data: unknown): Array<Record<string, unknown>> {
  if (Array.isArray(data)) {
    return data.filter((item): item is Record<string, unknown> => typeof item === 'object' && item !== null);
  }

  if (typeof data !== 'object' || data === null) {
    return [];
  }

  return Object.entries(data)
    .filter(([key, value]) => /^\d+$/.test(key) && typeof value === 'object' && value !== null)
    .map(([, value]) => value as Record<string, unknown>);
}

export async function findTicketIdByName(request: APIRequestContext, ticketName: string): Promise<number | null> {
  const session = await initApiSession(request);

  try {
    const response = await request.get(`${session.apiUrl}Ticket/`, {
      headers: {
        'App-Token': getAppToken(),
        'Session-Token': session.sessionToken,
      },
      params: {
        'searchText[name]': ticketName,
      },
    });
    const data = await parseJsonResponse<unknown>(response, `Finding ticket "${ticketName}"`);
    const match = getCollectionItems(data).find((item) => item.name === ticketName);

    if (match?.id === undefined || Number.isNaN(Number(match.id))) {
      return null;
    }

    return Number(match.id);
  } finally {
    await closeApiSession(request, session);
  }
}

export async function waitForTicketIdByName(
  request: APIRequestContext,
  ticketName: string,
  timeoutMs = 10_000
): Promise<number> {
  const deadline = Date.now() + timeoutMs;

  while (Date.now() <= deadline) {
    const ticketId = await findTicketIdByName(request, ticketName);
    if (ticketId !== null) {
      return ticketId;
    }

    await new Promise((resolve) => setTimeout(resolve, 250));
  }

  throw new Error(`Unable to find ticket "${ticketName}" within ${timeoutMs}ms.`);
}

export async function login(page: Page): Promise<void> {
  await page.goto('/index.php');
  await page.locator('#login_name').fill('itsm');
  await page.locator('#login_password').fill('itsm');
  await page.locator('form[aria-label="Login Form"] input[type="submit"]').click();
  await page.waitForLoadState('networkidle');
}

export async function openTicket(page: Page, seed: SeedTicketResult): Promise<void> {
  await page.goto(seed.ticketUrl);
  await expect(page.getByTestId('timeline-history')).toBeVisible();
}

async function getRichTextContext(form: Locator): Promise<RichTextContext> {
  const textarea = form.locator('textarea[name="content"]').first();
  await expect(textarea).toBeAttached();
  const editorId = await textarea.getAttribute('id');
  const page = form.page();

  if (editorId) {
    await page.waitForFunction(
      (id) => {
        const editorRegistry = window as unknown as Record<string, unknown>;
        const ckEditor = editorRegistry[id];
        const tinyMceEditor = (window as Window & { tinymce?: { get: (editorId: string) => unknown } }).tinymce?.get(id);

        return ckEditor !== undefined || (tinyMceEditor !== undefined && tinyMceEditor !== null);
      },
      editorId,
      { timeout: 5_000 }
    ).catch(() => undefined);
  }

  return { editorId, page, textarea };
}

export function getActorPanel(page: Page, role: ActorPanelRole): Locator {
  return page.locator(`.itil-actor-card[data-actor-role="${role}"]`).first();
}

export async function waitForActorValueSelect(panel: Locator): Promise<Locator> {
  const selector = panel.locator('[data-role="selector-container"] select').first();
  await expect(selector).toBeAttached();
  return selector;
}

export async function submitForm(
  page: Page,
  submitName: 'add' | 'update',
  root: Locator | Page = page
): Promise<void> {
  const submitButton = root.locator(`button[name="${submitName}"], input[name="${submitName}"]:not([type="hidden"])`).first();
  await expect(submitButton).toBeAttached();
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    submitButton.click(),
  ]);
  await page.waitForLoadState('networkidle');
}

export async function fillRichTextForm(form: Locator, content: string): Promise<void> {
  const { editorId, page } = await getRichTextContext(form);

  await page.evaluate(
    ({ value, id }) => {
      const textareaById = id ? document.getElementById(id) : null;
      const textareaElement = textareaById instanceof HTMLTextAreaElement
        ? textareaById
        : document.querySelector('textarea[name="content"]') as HTMLTextAreaElement | null;
      if (!textareaElement) {
        throw new Error('Unable to find the timeline content textarea.');
      }

      const ckEditor = id
        ? (window as unknown as Record<string, unknown>)[id] as { setData?: (html: string) => void; getData?: () => string } | undefined
        : undefined;
      const tinyMceEditor = id && 'tinymce' in window
        ? (window as Window & { tinymce?: { get: (editorId: string) => { setContent: (html: string) => void; save: () => void } | null } }).tinymce?.get(id)
        : null;

      if (ckEditor?.setData) {
        ckEditor.setData(value);
        textareaElement.value = ckEditor.getData?.() ?? value;
      } else if (tinyMceEditor) {
        tinyMceEditor.setContent(value);
        tinyMceEditor.save();
      } else {
        textareaElement.value = value;
      }

      textareaElement.dispatchEvent(new Event('input', { bubbles: true }));
      textareaElement.dispatchEvent(new Event('change', { bubbles: true }));
    },
    { value: content, id: editorId }
  );
}

export async function uploadRichTextFixture(form: Locator, fixtureRelativePath: string, owner: TicketFixtures): Promise<void> {
  const fixturePath = resolveRepoPath(fixtureRelativePath);
  const fileBuffer = await readFile(fixturePath);
  const { editorId, page } = await getRichTextContext(form);
  const uploadResponsePromise = page.waitForResponse((response) => {
    return response.url().includes('/ajax/v2/richtext_image_upload.php')
      && response.request().method() === 'POST';
  });

  await page.evaluate(
    ({ id, fileName, fileBase64, mimeType }) => {
      if (!id) {
        throw new Error('Unable to resolve the rich text editor id.');
      }

      const editor = (window as unknown as Record<string, unknown>)[id] as
        | { ui?: { getEditableElement?: () => HTMLElement | null } }
        | undefined;

      if (!editor?.ui?.getEditableElement) {
        throw new Error(`Unable to resolve CKEditor instance "${id}".`);
      }

      const editable = editor.ui.getEditableElement();
      if (!(editable instanceof HTMLElement)) {
        throw new Error('Unable to resolve the CKEditor editable element.');
      }

      const binary = atob(fileBase64);
      const bytes = Uint8Array.from(binary, (character) => character.charCodeAt(0));
      const file = new File([bytes], fileName, { type: mimeType });
      const dataTransfer = new DataTransfer();
      dataTransfer.items.add(file);

      editable.focus();

      ['dragenter', 'dragover', 'drop'].forEach((type) => {
        const event = typeof DragEvent === 'function'
          ? new DragEvent(type, {
              bubbles: true,
              cancelable: true,
              dataTransfer,
            })
          : new Event(type, {
              bubbles: true,
              cancelable: true,
            });

        if (!(event instanceof DragEvent)) {
          Object.defineProperty(event, 'dataTransfer', { value: dataTransfer });
        }

        editable.dispatchEvent(event);
      });
    },
    {
      id: editorId,
      fileName: path.basename(fixturePath),
      fileBase64: fileBuffer.toString('base64'),
      mimeType: getMimeType(fixturePath),
    }
  );

  const uploadResponse = await uploadResponsePromise;
  const uploadPayload = await uploadResponse.json() as {
    filename?: string;
    prefix?: string;
    tag?: string;
  };
  if (typeof uploadPayload.filename === 'string' && typeof uploadPayload.prefix === 'string' && typeof uploadPayload.tag === 'string') {
    owner.ownUpload(uploadPayload as RichTextUploadReceipt);
  }
  expect(uploadResponse.ok()).toBeTruthy();
  expect(typeof uploadPayload.filename).toBe('string');
  expect(typeof uploadPayload.prefix).toBe('string');
  expect(typeof uploadPayload.tag).toBe('string');

  await expect(form.locator('input[name^="_content["]')).toHaveCount(1);
  await expect(form.locator('input[name^="_prefix_content["]')).toHaveCount(1);
  await expect(form.locator('input[name^="_tag_content["]')).toHaveCount(1);
  await expect(form.locator('.ck-content img[src^="blob:"]').first()).toBeVisible();
}

export async function getInnerHtml(locator: Locator): Promise<string> {
  return locator.evaluate((element) => element.innerHTML);
}

export async function submitAddForm(form: Locator, page: Page): Promise<void> {
  await submitForm(page, 'add', form);
}
