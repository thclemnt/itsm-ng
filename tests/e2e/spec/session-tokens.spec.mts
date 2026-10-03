// SPDX-License-Identifier: GPL-2.0-or-later
import { execFile } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { closeApiSession, initApiSession, type ApiSession } from '../helpers.mjs';

interface Seed {
  guard: string; prefix: string; users: Record<string, number>; names: Record<string, string>;
  personalTokens: Record<string, string>; parent: number; child: number; grandchild: number; foreign: number;
  profile: number; groups: Record<string, number>; computer: number; computerName: string; location: number;
  tasks: Record<string, number>; owned: Record<string, number[]>; cookieName: string; rememberName: string;
}
interface Observation { provisioned: number; closedBeforeToken: number; cookieVeto: number; }
const config = process.env.PLAYWRIGHT_SESSION_CONFIG;
const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
let seed: Seed;

async function fixture<T>(action: string, input: Record<string, unknown> = {}): Promise<T> {
  return new Promise((resolve, reject) => {
    const child = execFile('php', ['tests/database-portability/fixtures/session-token-web-fixture.php', config!, action],
      { cwd: root }, (error, stdout) => {
        // Capabilities and cookie plaintext travel on stdin, never in argv or errors.
        if (error) return reject(new Error(`Session HTTP CLI fixture ${action} failed (${error.code}).`));
        try { resolve(JSON.parse(stdout) as T); }
        catch { reject(new Error(`Session HTTP CLI fixture ${action} returned invalid JSON.`)); }
      });
    child.stdin!.end(JSON.stringify(input));
  });
}
async function mode(input: Record<string, boolean>): Promise<void> {
  await fixture('mode', { guard: seed.guard, ...input });
}
async function cookie(context: BrowserContext, name: string) {
  return (await context.cookies(baseURL)).find(value => value.name === name);
}
async function loginOwned(page: Page, role: string, remember = false): Promise<void> {
  await page.goto('/index.php?noAUTO=1');
  await page.locator('#login_name').fill(seed.names[role]);
  await page.locator('#login_password').fill('SessionHTTP1!');
  const control = page.locator('#login_remember');
  await expect(control).toBeVisible();
  await control.setChecked(remember);
  await expect(page.locator('form[aria-label="Login Form"] input[name="_glpi_csrf_token"]')).toHaveCount(1);
  await page.locator('form[aria-label="Login Form"] input[type="submit"]').click();
  await page.waitForLoadState('networkidle');
  await expect(page).not.toHaveURL(/\/index\.php/);
}
async function session(context: BrowserContext): Promise<Record<string, any>> {
  const current = await cookie(context, seed.cookieName);
  expect(Boolean(current), 'The browser must retain an actual PHP session cookie.').toBe(true);
  const response = await context.request.get('/apirest.php/getFullSession', {
    headers: { 'App-Token': process.env.PLAYWRIGHT_APP_TOKEN!, 'Session-Token': current!.value },
  });
  expect(response.ok(), 'The actual API must accept the browser-effective session, not an old captured ID.').toBe(true);
  const data = await response.json();
  expect(Boolean(data.session?.glpiID), 'The next actual request must retain an authenticated account.').toBe(true);
  return data.session;
}
function preserved(before: Record<string, any>, after: Record<string, any>): boolean {
  const fields = ['glpiID', 'glpiactiveprofile', 'glpiactive_entity', 'glpiactiveentities',
    'glpigroups', 'glpilanguage', 'valid_id', '_glpi_csrf_token', 'csrf_token_time', 'glpicsrftokens', 'glpiidortokens'];
  return fields.every(field => JSON.stringify(before[field]) === JSON.stringify(after[field]));
}
async function calendar(context: BrowserContext, role: string, entity: number, recursive: boolean, groups = false): Promise<string> {
  const response = await context.request.get('/front/planning.php', {
    params: { genical: 1, token: seed.personalTokens[role], uID: groups ? 0 : seed.users[role],
      gID: groups ? 'mine' : 0, limititemtype: 'TicketTask', entities_id: entity, is_recursive: recursive ? 1 : 0 },
  });
  expect(response.status(), 'The real planning route must not fail with a server error.').toBeLessThan(500);
  return response.text();
}
function eventIDs(body: string): number[] {
  return [...body.matchAll(/(?:^|\r?\n)UID:TicketTask#(\d+)(?:\r?\n|$)/g)].map(match => Number(match[1])).sort((a, b) => a - b);
}
function exactEvents(body: string, keys: string[]): boolean {
  return body.includes('BEGIN:VCALENDAR')
    && JSON.stringify(eventIDs(body)) === JSON.stringify(keys.map(key => seed.tasks[key]).sort((a, b) => a - b));
}
function hasChoice(value: any, id: number): boolean {
  if (Array.isArray(value)) return value.some(item => hasChoice(item, id));
  return Boolean(value && typeof value === 'object'
    && (Number(value.id) === id || hasChoice(value.results || value.children, id)));
}

test.beforeAll(async () => {
  test.skip(!config || !baseURL || !process.env.PLAYWRIGHT_APP_TOKEN,
    'Set PLAYWRIGHT_SESSION_CONFIG, PLAYWRIGHT_BASE_URL and the authorized PLAYWRIGHT_APP_TOKEN; use the private Session router.');
  seed = await fixture<Seed>('seed');
});
test.afterAll(async ({ request }) => {
  if (!seed) return;
  await mode({ provision: false, closeBeforeToken: false, vetoCookie: false });
  let admin: ApiSession | undefined;
  try {
    admin = await initApiSession(request);
    // Explicitly use the actual authorized administrator API and native purge
    // policy. The CLI finalizer refuses to remove an unpurged owned domain row.
    for (const type of ['TicketTask', 'Ticket', 'Computer', 'Location', 'Group_User', 'Profile_User', 'User', 'Group', 'ProfileRight', 'Profile', 'Entity']) {
      for (const id of [...(seed.owned[type] || [])].reverse()) {
        const response = await request.delete(`${admin.apiUrl}${type}/${id}`, {
          headers: { 'App-Token': process.env.PLAYWRIGHT_APP_TOKEN!, 'Session-Token': admin.sessionToken },
          params: { force_purge: true },
        });
        expect(response.ok(), `Actual administrator API purge must accept owned ${type}.`).toBe(true);
      }
    }
    await fixture('clean', { guard: seed.guard });
  } finally {
    if (admin) await closeApiSession(request, admin);
  }
});

test('refused personal-token admission and scope preserve the real browser session and existing form capabilities', async ({ page, context }) => {
  await loginOwned(page, 'direct');
  await page.goto(`/front/computer.form.php?id=${seed.computer}`);
  const name = page.locator('input[name="name"]').first();
  await expect(name).toHaveValue(seed.computerName); // Also refuses mismatched fixture/server databases.
  const select = page.locator('select[name="locations_id"]').first();
  const selectId = await select.getAttribute('id');
  const choices = page.waitForResponse(response => response.url().includes('/ajax/getDropdownValue.php') && response.request().method() === 'POST');
  await page.locator(`[aria-labelledby="select2-${selectId}-container"]`).click();
  const issued = await choices;
  expect(issued.status()).toBe(200);
  const issuedBody = issued.request().postData()!;
  expect(Boolean(new URLSearchParams(issuedBody).get('_idor_token')), 'The actual widget sends its issued IDOR capability.').toBe(true);
  await page.keyboard.press('Escape');
  const before = await session(context);
  expect(Number(before.glpiID) === seed.users.direct && Number(before.glpiactiveprofile.id) === seed.profile
    && before.glpigroups.includes(seed.groups.parent), 'The previous account has real owned profile and group grants.').toBe(true);
  expect(Boolean(before._glpi_csrf_token) && Object.keys(before.glpiidortokens || {}).length > 0,
    'The actual form must issue both real CSRF and IDOR capabilities.').toBe(true);
  const originalCookie = (await cookie(context, seed.cookieName))!.value;
  for (const [role, entity, recursive] of [
    ['inactive', seed.parent, false], ['deleted', seed.parent, false], ['future', seed.parent, false],
    ['expired', seed.parent, false], ['no_grants', seed.parent, false],
    ['direct', seed.child, false], ['direct', seed.parent, true], ['recursive', seed.foreign, false], ['remember', seed.child, false],
  ] as Array<[string, number, boolean]>) {
    expect(!(await calendar(context, role, entity, recursive)).includes('BEGIN:VCALENDAR'), 'Refused admission/scope must not export a calendar.').toBe(true);
    expect((await cookie(context, seed.cookieName))?.value === originalCookie,
      'The response must preserve the browser-effective session cookie.').toBe(true);
    expect(preserved(before, await session(context)), 'A following real API request must retain the original account/profile/scope/groups and page capabilities.').toBe(true);
  }
  // This one case is explicitly instrumented through a manifest-guarded fixture
  // post_init hook. The planning controller and token policy remain unchanged.
  await mode({ closeBeforeToken: true });
  try {
    expect(!(await calendar(context, 'direct', seed.child, false)).includes('BEGIN:VCALENDAR')).toBe(true);
    expect((await cookie(context, seed.cookieName))?.value === originalCookie && preserved(before, await session(context)),
      'Actual HTTP refusal must restore the effective cookie and persisted context after the fixture hook closes the prior PHP session.').toBe(true);
    expect((await fixture<Observation>('observe', { guard: seed.guard })).closedBeforeToken).toBeGreaterThan(0);
  } finally {
    await mode({ closeBeforeToken: false });
  }
  const acceptedChoice = await context.request.post('/ajax/getDropdownValue.php', {
    data: issuedBody, headers: { 'Content-Type': 'application/x-www-form-urlencoded', Referer: page.url() },
  });
  expect(acceptedChoice.status(), 'The ORIGINAL issued IDOR capability remains accepted at the actual choice endpoint.').toBe(200);
  const choiceBody = await acceptedChoice.json();
  expect(hasChoice(choiceBody, seed.location),
    'The retained scope still exposes its actual owned Location through the saved capability.').toBe(true);
  // Submit the ORIGINAL rendered form/token after all refusals, not a freshly
  // generated token. This proves retained CSRF remains accepted at its real route.
  const changed = `${seed.computerName} retained`;
  await name.fill(changed);
  const sent = page.waitForResponse(response => response.url().includes('/front/computer.form.php') && response.request().method() === 'POST');
  await page.locator('input[name="update"], button[name="update"]').first().click();
  expect((await sent).status()).toBeLessThan(400);
  await page.goto(`/front/computer.form.php?id=${seed.computer}`);
  await expect(page.locator('input[name="name"]').first()).toHaveValue(changed);
});

test('actual personal-token calendars retain direct, descendant, subtree and group scope and real provisioning hooks', async ({ browser }) => {
  const contexts: BrowserContext[] = [];
  const fresh = async () => {
    const context = await browser.newContext({ baseURL });
    contexts.push(context);
    return context;
  };
  try {
    expect(exactEvents(await calendar(await fresh(), 'direct', seed.parent, false), ['direct-parent']), 'Direct calendar excludes child/foreign tasks assigned to the same user.').toBe(true);
    expect(exactEvents(await calendar(await fresh(), 'remember', 0, false), ['remember-root']), 'Actual Entity ID0 direct grant retains its legitimate root calendar.').toBe(true);
    expect(exactEvents(await calendar(await fresh(), 'recursive', seed.child, false), ['recursive-child']), 'Explicit descendant-only calendar must not revert to default parent scope.').toBe(true);
    expect(exactEvents(await calendar(await fresh(), 'recursive', seed.child, true), ['recursive-child', 'recursive-grandchild']), 'Explicit descendant subtree calendar retains only that subtree.').toBe(true);
    expect(exactEvents(await calendar(await fresh(), 'recursive', seed.child, false, true), ['group-child']), 'Actual gID=mine resolves only groups/tasks eligible in the selected descendant scope.').toBe(true);
    await mode({ provision: true });
    expect(exactEvents(await calendar(await fresh(), 'provisioned', seed.parent, false), ['provisioned-parent']), 'The real init_session hook may create the grant before owning grant initialization.').toBe(true);
    expect((await fixture<Observation>('observe', { guard: seed.guard })).provisioned).toBe(1);
  } finally {
    await mode({ provision: false });
    for (const context of contexts) await context.close();
  }
});

test('real remembered login delivers a persisted credential and authenticates a later cookie-only browser', async ({ page, context, browser }) => {
  await loginOwned(page, 'remember', true);
  const remembered = await cookie(context, seed.rememberName);
  expect(Boolean(remembered?.httpOnly) && remembered!.expires > Date.now() / 1000, 'Remembered credential must actually reach the browser with its existing cookie attributes.').toBe(true);
  expect((await fixture<{ matches: boolean; dated: boolean }>('cookie-check', { guard: seed.guard, cookie: remembered!.value })).matches,
    'Cookie plaintext must verify against this owned user’s actually persisted hash.').toBe(true);
  const onlyCookie = await browser.newContext({ baseURL });
  try {
    // Transfer only the genuinely delivered remembered cookie, never PHP/REST
    // session credentials, and do not logout (logout deletes remembered cookies).
    await onlyCookie.addCookies([remembered!]);
    const next = await onlyCookie.newPage();
    await next.goto('/index.php');
    await next.waitForLoadState('networkidle');
    expect(Number((await session(onlyCookie)).glpiID) === seed.users.remember, 'The actual subsequent request authenticates from the remembered cookie alone.').toBe(true);
  } finally {
    await onlyCookie.close();
  }
});

test('a real remembered-token update veto preserves password admission and the prior delivered cookie', async ({ page, context }) => {
  await loginOwned(page, 'remember', true);
  const remembered = (await cookie(context, seed.rememberName))!;
  const before = (await fixture<Observation>('observe', { guard: seed.guard })).cookieVeto;
  await mode({ vetoCookie: true });
  try {
    await loginOwned(page, 'remember', true);
    expect(Number((await session(context)).glpiID) === seed.users.remember, 'A refused optional remembered credential must not reject accepted password authentication.').toBe(true);
    expect((await cookie(context, seed.rememberName))?.value === remembered.value, 'The browser receives no replacement unpersisted remembered credential.').toBe(true);
    expect((await fixture<{ matches: boolean }>('cookie-check', { guard: seed.guard, cookie: remembered.value })).matches).toBe(true);
    expect((await fixture<Observation>('observe', { guard: seed.guard })).cookieVeto === before + 1, 'The actual public cookie-token update hook veto occurs once.').toBe(true);
  } finally {
    await mode({ vetoCookie: false });
  }
});

test('expired and undated remembered credentials fail the actual cookie-only route and deliver deletion', async ({ page, context, browser, request }) => {
  let admin: ApiSession | undefined;
  const contexts: BrowserContext[] = [];
  try {
    admin = await initApiSession(request);
    for (const date of [null, '2000-01-01 00:00:00']) {
      await loginOwned(page, 'remember', true);
      const remembered = (await cookie(context, seed.rememberName))!;
      const changed = await request.put(`${admin.apiUrl}User/${seed.users.remember}`, {
        headers: { 'App-Token': process.env.PLAYWRIGHT_APP_TOKEN!, 'Session-Token': admin.sessionToken },
        data: { input: { id: seed.users.remember, cookie_token_date: date } },
      });
      expect(changed.ok(), 'Actual authorized administrator API sets the owned credential date boundary.').toBe(true);
      expect((await fixture<{ matches: boolean; dateMatches: boolean }>('cookie-check', {
        guard: seed.guard, cookie: remembered.value, expectedDate: date,
      })).dateMatches, 'The actual API date write must persist the intended NULL/expired boundary.').toBe(true);
      const onlyCookie = await browser.newContext({ baseURL });
      contexts.push(onlyCookie);
      await onlyCookie.addCookies([remembered]);
      const next = await onlyCookie.newPage();
      await next.goto('/index.php');
      await next.waitForLoadState('networkidle');
      await expect(next.getByText('Invalid cookie data', { exact: false })).toBeVisible();
      expect(!await cookie(onlyCookie, seed.rememberName), 'The failed real cookie-only authentication delivers deletion to the browser.').toBe(true);
      expect(!(await fixture<{ matches: boolean }>('cookie-check', { guard: seed.guard, cookie: remembered.value })).matches,
        'The old undated/expired plaintext no longer matches the actually stored credential.').toBe(true);
    }
  } finally {
    for (const current of contexts) await current.close();
    if (admin) await closeApiSession(request, admin);
  }
});
