// SPDX-License-Identifier: GPL-2.0-or-later
import { execFile } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { closeApiSession, createItem, getItem, initApiSession, type ApiSession } from '../helpers.mjs';

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

for (const [boundary, date] of [['undated', null], ['expired', '2000-01-01 00:00:00']] as const) {
  test(`${boundary} cookie-only admission denies a refused rotation and deletes the delivered cookie`, async ({ page, context, browser, request }) => {
    let admin: ApiSession | undefined;
    let onlyCookie: BrowserContext | undefined;
    try {
      admin = await initApiSession(request);
      await loginOwned(page, 'remember', true);
      const remembered = (await cookie(context, seed.rememberName))!;
      const changed = await request.put(`${admin.apiUrl}User/${seed.users.remember}`, {
        headers: { 'App-Token': process.env.PLAYWRIGHT_APP_TOKEN!, 'Session-Token': admin.sessionToken },
        data: { input: { id: seed.users.remember, cookie_token_date: date } },
      });
      expect(changed.ok(), 'Actual administrator API persists the owned date boundary before cookie-only admission.').toBe(true);
      const before = await fixture<{ matches: boolean; dateMatches: boolean }>('cookie-check', {
        guard: seed.guard, cookie: remembered.value, expectedDate: date,
      });
      expect(before.matches && before.dateMatches, 'The delivered old credential matches the stored hash with the exact requested date boundary.').toBe(true);
      expect((await fixture<{ captured: boolean }>('cookie-snapshot', { guard: seed.guard })).captured).toBe(true);
      const vetoes = (await fixture<Observation>('observe', { guard: seed.guard })).cookieVeto;
      await mode({ vetoCookie: true });
      onlyCookie = await browser.newContext({ baseURL });
      // This context receives no password admission, PHP session, REST token,
      // or administrator cookie; only the originally delivered credential.
      await onlyCookie.addCookies([remembered]);
      const next = await onlyCookie.newPage();
      const scriptErrors: string[] = [];
      next.on('pageerror', () => scriptErrors.push('pageerror'));
      const response = await next.goto('/index.php');
      expect(response?.status(), 'Cookie-only refusal must return an application response without a server error.').toBeLessThan(500);
      await next.waitForLoadState('networkidle');
      await expect(next.getByText('Invalid cookie data', { exact: false })).toBeVisible();
      await expect(next.locator('#login_name')).toBeVisible();
      expect(scriptErrors.length === 0 && !/\b(?:Warning|Deprecated|Notice|Fatal error)\b/.test(await next.content()),
        'The actual refused-cookie response must contain no script error or PHP warning output.').toBe(true);
      expect(!await cookie(onlyCookie, seed.rememberName), 'Actual cookie-only refusal delivers deletion of the remembered browser cookie.').toBe(true);
      const anonymous = await cookie(onlyCookie, seed.cookieName);
      expect(Boolean(anonymous), 'The refusal retains an actual anonymous PHP session cookie.').toBe(true);
      const denied = await onlyCookie.request.get('/apirest.php/getFullSession', {
        headers: { 'App-Token': process.env.PLAYWRIGHT_APP_TOKEN!, 'Session-Token': anonymous!.value },
      });
      expect(denied.status(), 'The refused cookie-only context must not obtain an authenticated API session.').toBe(401);
      const after = await fixture<{ matches: boolean; dateMatches: boolean; unchanged: boolean; historyUnchanged: boolean }>('cookie-check', {
        guard: seed.guard, cookie: remembered.value, expectedDate: date,
      });
      expect(after.matches && after.dateMatches && after.unchanged && after.historyUnchanged,
        'Refused rotation preserves the exact previous credential/date and history, despite deleting its browser delivery.').toBe(true);
      expect((await fixture<Observation>('observe', { guard: seed.guard })).cookieVeto === vetoes + 1,
        'The actual public User rotation veto executes exactly once in cookie-only admission.').toBe(true);
    } finally {
      try {
        await mode({ vetoCookie: false });
      } finally {
        try {
          if (onlyCookie) await onlyCookie.close();
        } finally {
          if (admin) await closeApiSession(request, admin);
        }
      }
    }
  });
}


test('status configuration requires its own mutation rights even with a valid browser CSRF token', async ({ page, context, request }) => {
  let admin: ApiSession | undefined;
  let rightId: number | undefined;
  let primaryError: unknown;
  try {
    admin = await initApiSession(request);
    const headers = { 'App-Token': process.env.PLAYWRIGHT_APP_TOKEN!, 'Session-Token': admin.sessionToken };
    const statuses = async (): Promise<Array<Record<string, any>>> => {
      const response = await request.get(`${admin!.apiUrl}SpecialStatus/`, {
        headers, params: { range: '0-9999', sort: 'id', get_hateoas: false },
      });
      expect(response.status(), 'The administrator snapshot must contain the complete status collection.').toBe(200);
      const rows = await response.json();
      expect(Array.isArray(rows) && rows.length > 0, 'Refusal controls require real stored statuses.').toBe(true);
      return rows;
    };
    const ticketCodes = async (): Promise<Array<[number, number]>> => {
      const rows: Array<[number, number]> = [];
      expect(seed.owned.Ticket.length, 'The fixture must have actual stored ticket owners.').toBeGreaterThan(0);
      for (const id of seed.owned.Ticket) {
        const ticket = await getItem<{ status: number | string }>(request, admin!, 'Ticket', id);
        rows.push([id, Number(ticket.status)]);
      }
      return rows;
    };
    const beforeStatuses = await statuses();
    const beforeTickets = await ticketCodes();
    const unchanged = async () => {
      expect(await statuses(), 'Refusal preserves every stored status field and the complete collection.').toEqual(beforeStatuses);
      expect(await ticketCodes(), 'Refusal must not remap the actual owned ticket status codes.').toEqual(beforeTickets);
    };
    await loginOwned(page, 'direct');
    expect(Number((await session(context)).glpiactiveprofile.status_ticket || 0)).toBe(0);
    for (const route of ['/front/specialstatus.php', '/front/specialstatus.form.php']) {
      await page.goto(route);
      await expect(page.getByText("You don't have permission to perform this action.", { exact: true })).toBeVisible();
      await expect(page.locator('table[aria-label="Special Status"]')).toHaveCount(0);
    }
    await unchanged();
    // This profile belongs to this existing fixture and has no status grant.
    // Use the real administrator API/lifecycle; do not forge browser session rights.
    rightId = await createItem(request, admin, 'ProfileRight', {
      profiles_id: seed.profile, name: 'status_ticket', rights: 1, // READ
    });
    await loginOwned(page, 'direct');
    expect(Number((await session(context)).glpiactiveprofile.status_ticket)).toBe(1);
    await page.goto('/front/specialstatus.php');
    await expect(page.locator('table[aria-label="Special Status"]')).toBeVisible();
    await expect(page.locator('input[name^="weight_"]').first()).toBeDisabled();
    await expect(page.locator('input[name="update"]')).toHaveCount(0);
    await expect(page.locator('a[href*="specialstatus.showStatusModal"]')).toHaveCount(0);

    const update: Record<string, string> = { update: '1' };
    for (const row of beforeStatuses) {
      update[`weight_${row.id}`] = String(row.weight);
      update[`is_active_${row.id}`] = String(Number(row.is_active));
      if (row.color !== null) update[`color_${row.id}`] = String(row.color);
    }
    // A real changed weight makes a missing admission guard observable.
    update[`weight_${beforeStatuses[0].id}`] = String(Number(beforeStatuses[0].weight) + 13);
    const refusedPost = async (route: string, values: Record<string, string>) => {
      await page.goto('/front/specialstatus.php');
      const token = await page.locator('form[aria-label="Informations"] input[name="_glpi_csrf_token"]').inputValue();
      expect(token.length).toBeGreaterThan(0);
      expect((await session(context))._glpi_csrf_token, 'Send the actual unexpired token held by this browser session.').toBe(token);
      const response = await context.request.post(route, {
        form: { ...values, _glpi_csrf_token: token }, headers: { Referer: page.url() },
      });
      expect(response.status(), 'Admission refusal must not fail with a server error.').toBeLessThan(500);
      const body = await response.text();
      expect(body).toContain("You don't have permission to perform this action.");
      expect(body, 'This must be a permission refusal, not an invalid-token shortcut.').not.toContain('CSRF token is invalid');
      // Bootstrap consumes the submitted token; the rendered header may then
      // issue a replacement. The original token must no longer be current.
      expect((await session(context))._glpi_csrf_token).not.toBe(token);
      await unchanged();
    };
    await refusedPost('/front/specialstatus.php', update);
    await refusedPost('/front/specialstatus.form.php', {
      update: '1', name: seed.prefix + 'forbidden-status', weight: '1', is_active: '1', color: '#123456',
    });
    await refusedPost('/front/specialstatus.php', { delete: String(beforeStatuses[0].id) });
    const beforeConfirmation = await session(context);
    const confirmation = await context.request.get('/ajax/specialstatus.php', {
      params: { status: '1', id: String(beforeStatuses[0].id) },
    });
    expect(confirmation.status()).toBeLessThan(500);
    expect(await confirmation.text()).toContain("You don't have permission to perform this action.");
    expect((await session(context)).id, 'Refused AJAX confirmation must not select a later purge target.').toEqual(beforeConfirmation.id);
    await unchanged();
    await refusedPost('/front/specialstatus.php', { force: '1' });

    // UPDATE is granted independently of PURGE. A mixed request must refuse
    // before its permitted UPDATE can change statuses or remap ticket owners.
    const granted = await request.put(`${admin.apiUrl}ProfileRight/${rightId}`, {
      headers, data: { input: { id: rightId, rights: 3 } }, // READ | UPDATE
    });
    expect(granted.ok(), 'Real administrator lifecycle persists the separate UPDATE grant.').toBe(true);
    await loginOwned(page, 'direct');
    expect(Number((await session(context)).glpiactiveprofile.status_ticket)).toBe(3);
    await page.goto('/front/specialstatus.php');
    await expect(page.locator('input[name^="weight_"]').first()).toBeEnabled();
    await expect(page.locator('input[name="update"]')).toBeVisible();
    await expect(page.locator('a[href*="specialstatus.showStatusModal"]')).toHaveCount(0);
    await refusedPost('/front/specialstatus.php', { ...update, force: '1' });
  } catch (error) {
    primaryError = error;
  } finally {
    const failures: unknown[] = primaryError ? [primaryError] : [];
    if (admin && rightId !== undefined) {
      try {
        const removed = await request.delete(`${admin.apiUrl}ProfileRight/${rightId}`, {
          headers: { 'App-Token': process.env.PLAYWRIGHT_APP_TOKEN!, 'Session-Token': admin.sessionToken },
          params: { force_purge: true },
        });
        expect(removed.ok(), 'Purge only this test-created status grant through the actual administrator API.').toBe(true);
      } catch (error) { failures.push(error); }
    }
    if (admin) {
      try { await closeApiSession(request, admin); } catch (error) { failures.push(error); }
    }
    if (failures.length) throw new AggregateError(failures, 'Status permission flow or owned grant cleanup failed');
  }
});
