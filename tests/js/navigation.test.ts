import { expect, it } from 'vitest';
import { activeHref, moduleOn, navBadge, navGroups, quickActions, settingsGroups } from '@/lib/navigation';

type U = { permissions: string[]; is_super_admin: boolean };
const user = (...permissions: string[]): U => ({ permissions, is_super_admin: false });
const admin = user('admin');
const all = ['calendar', 'knowledgebase', 'mail', 'forms', 'enotf', 'firetab', 'manvboard', 'logbook', 'federation'];
const hrefs = (u: U | null, modules: string[] = all) => navGroups(u, modules).flatMap((g) => g.items.map((i) => i.href));
const tiles = (u: U | null, modules: string[] = all) => settingsGroups(u, modules).flatMap((g) => g.items.map((i) => i.href));

it('shows an item of a module only while the module is switched on', () => {
    const item = { href: '/demo', label: 'demo.nav', icon: 'house' as const, module: 'demo' };

    expect(moduleOn(item, ['demo'])).toBe(true);
    expect(moduleOn(item, [])).toBe(false);
    expect(moduleOn({ href: '/', label: 'nav.dashboard', icon: 'house' }, [])).toBe(true);
});

it('builds the legacy sidebar: five groups, legacy labels, settings as one entry', () => {
    const groups = navGroups(admin, all);

    expect(groups.map((g) => [g.heading, g.items.map((i) => i.label)])).toEqual([
        [null, ['nav.dashboard', 'nav.calendar', 'nav.lexicon', 'nav.mail']],
        ['nav.group.personnel', ['nav.personnel', 'nav.forms']],
        ['nav.group.protocols', ['nav.enotf_open', 'nav.enotf_admin', 'nav.firetab_open', 'nav.firetab_qm', 'nav.mci']],
        ['nav.group.vehicles', ['nav.vehicles', 'nav.defects', 'nav.logbook']],
        ['nav.group.admin', ['nav.users', 'nav.settings']],
    ]);
    expect(groups.flatMap((g) => g.items).filter((i) => i.external).map((i) => i.href)).toEqual(['/enotf', '/firetab']);
    expect(navGroups(user('vehicles.view'), all).flatMap((g) => g.items).find((i) => i.href === '/settings')?.match).toEqual([
        '/settings/vehicles/loadouts',
        '/settings/vehicles/symbol-templates',
    ]);
    expect(navGroups({ permissions: [], is_super_admin: true }, all)).toEqual(groups);
    expect(navGroups(null, all)).toEqual([]);
});

it('keeps the rights per entry: viewer without admin rights gets no administration', () => {
    const viewer = user('personnel.view', 'vehicles.view', 'edivi.view', 'fire.incident.qm', 'application.view', 'mail.use', 'calendar.view', 'mci.view', 'logbook.view');

    expect(hrefs(viewer)).toEqual(['/', '/calendar', '/lexicon', '/mail', '/personnel', '/forms/admin', '/enotf', '/enotf/admin', '/firetab', '/firetab/qm', '/mci', '/vehicles', '/vehicles/defects', '/logbook', '/settings']);
    expect(tiles(viewer)).toEqual([
        '/settings/personnel/ranks',
        '/settings/personnel/fire-qualifications',
        '/settings/personnel/ems-qualifications',
        '/settings/personnel/specialties',
        '/settings/personnel/titles',
        '/settings/vehicles/loadouts',
        '/settings/vehicles/symbol-templates',
    ]);
    expect(hrefs(user())).toEqual(['/', '/lexicon', '/forms', '/firetab']);
    expect(hrefs(user('application.edit'))).toContain('/forms/admin');
    expect(hrefs(viewer, [])).toEqual(['/', '/personnel', '/vehicles', '/vehicles/defects', '/settings']);
    expect(hrefs(user('logbook.manage'))).toContain('/logbook');
    expect(hrefs(user('mci.manage'))).toContain('/mci');
    expect(hrefs(user('calendar.manage'))).toContain('/calendar');
});

it('lists every former settings entry as a tile with a description, by right and module', () => {
    expect(tiles(admin)).toEqual([
        '/settings/personnel/ranks',
        '/settings/personnel/fire-qualifications',
        '/settings/personnel/ems-qualifications',
        '/settings/personnel/specialties',
        '/settings/personnel/titles',
        '/users/roles',
        '/users/invitations',
        '/users/audit-log',
        '/settings/documents/editor-templates',
        '/settings/forms',
        '/settings/dashboard',
        '/settings/vehicles/loadouts',
        '/settings/vehicles/symbol-templates',
        '/vehicles/registry',
        '/settings/pois',
        '/settings/medications',
        '/settings/enotf/quicklinks',
        '/settings/enotf/categories',
        '/mail/lists',
        '/settings/mail/mailboxes',
        '/settings/mail',
        '/settings/system/config',
        '/settings/system/modules',
        '/settings/system',
        '/settings/system/jobs',
        '/settings/federation',
        '/settings/system/discord',
    ]);
    expect(settingsGroups(admin, all).flatMap((g) => g.items).every((i) => i.description === `${i.label}_desc`)).toBe(true);
    expect(tiles(user('mail.lists.manage'))).toEqual(['/mail/lists']);
    expect(tiles(user('mail.admin'))).toEqual(['/settings/mail/mailboxes', '/settings/mail']);
    expect(tiles(user('mail.admin'), [])).toEqual([]);
    expect(tiles(user('vehicles.manage'))).toEqual(['/vehicles/registry']);
    expect(tiles(user('dashboard.manage'))).toEqual(['/settings/dashboard']);
    expect(hrefs(user('mail.use'))).not.toContain('/settings');
});

it('marks the entry with the longest matching prefix as active', () => {
    const groups = navGroups(admin, all);

    expect(activeHref(groups, '/vehicles/defects/3')).toBe('/vehicles/defects');
    expect(activeHref(groups, '/vehicles/12?tab=x')).toBe('/vehicles');
    expect(activeHref(groups, '/settings/system/config')).toBe('/settings');
    expect(activeHref(groups, '/users/roles/2')).toBe('/settings');
    expect(activeHref(groups, '/mail/lists')).toBe('/settings');
    expect(activeHref(groups, '/users')).toBe('/users');
    expect(activeHref(groups, '/forms/admin/5')).toBe('/forms/admin');
    expect(activeHref(groups, '/enotf/qm/5')).toBe('/enotf/admin');
    expect(activeHref(groups, '/')).toBe('/');
    expect(activeHref(groups, '/?x=1')).toBe('/');
    expect(activeHref(groups, '/inbox')).toBeNull();
    expect(activeHref(groups, '/vehiclesx')).toBeNull();
});

it('shows a counter badge only for a positive count, capped at 99+', () => {
    const mail = { href: '/mail', label: 'nav.mail', icon: 'envelope' as const, module: 'mail', counter: 'mail' };

    expect(navBadge(mail, { mail: 3 })).toBe('3');
    expect(navBadge(mail, { mail: 120 })).toBe('99+');
    expect(navBadge(mail, { mail: 0 })).toBeNull();
    expect(navBadge(mail, [])).toBeNull();
    expect(navBadge(mail, undefined)).toBeNull();
    expect(navBadge({ href: '/', label: 'nav.dashboard', icon: 'house' }, { mail: 3 })).toBeNull();
});

it('offers the quick actions by right and module', () => {
    expect(quickActions(user('mci.manage'), ['manvboard']).map((i) => i.href)).toEqual(['/mci/create']);
    expect(quickActions(user('mci.view'), ['manvboard'])).toEqual([]);
    expect(quickActions(user('mail.use'), ['mail']).map((i) => i.href)).toEqual(['/mail/compose']);
    expect(quickActions(user('mail.use'), [])).toEqual([]);
});
