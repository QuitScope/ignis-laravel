// The sidebar, the settings overview and the palette's local actions from one definition (legacy config/navigation.php,
// the plugin fragments and Navigation::quickActions). The rights mirror the server gates; the server still checks every page.
import type { FaIconName } from '@/lib/faIcons';

export type NavUser = { is_super_admin: boolean; permissions: string[] };

/**
 * `label` (and `description`, settings tiles only) are translation keys; `module` names the module the page belongs to;
 * `counter` a key of `nav.counters`; `match` further path prefixes under which the item is active; `external` opens a
 * new tab (the tablet apps).
 */
export type NavItem = {
    href: string;
    label: string;
    icon: FaIconName;
    module?: string;
    counter?: string;
    match?: string[];
    external?: boolean;
    description?: string;
};

export type NavGroup = { key: string; heading: string | null; items: NavItem[] };

export type ModuleSwitches = string[];

export type NavCounters = Record<string, number> | unknown[];

export function navBadge(i: NavItem, counters: NavCounters | undefined): string | null {
    const n = i.counter === undefined || counters === undefined || Array.isArray(counters) ? 0 : (counters[i.counter] ?? 0);

    return n > 0 ? (n > 99 ? '99+' : String(n)) : null;
}

export function can(user: NavUser | null, ...permissions: string[]): boolean {
    return !!user && (user.is_super_admin || user.permissions.some((p) => p === 'admin' || permissions.includes(p)));
}

export function moduleOn(i: NavItem, modules: ModuleSwitches): boolean {
    return i.module === undefined || modules.includes(i.module);
}

const item = (visible: boolean, i: NavItem): NavItem[] => (visible ? [i] : []);

const visibleGroups = (groups: NavGroup[], modules: ModuleSwitches): NavGroup[] =>
    groups.map((g) => ({ ...g, items: g.items.filter((i) => moduleOn(i, modules)) })).filter((g) => g.items.length > 0);

export function settingsGroups(user: NavUser | null, modules: ModuleSwitches = []): NavGroup[] {
    const personnelView = can(user, 'personnel.view');
    const usersView = can(user, 'users.view');
    const vehiclesView = can(user, 'vehicles.view');
    const admin = can(user);
    const tile = (visible: boolean, href: string, key: string, icon: FaIconName, module?: string): NavItem[] =>
        item(visible, { href, label: `nav.${key}`, description: `nav.${key}_desc`, icon, ...(module === undefined ? {} : { module }) });

    return visibleGroups(
        [
            {
                key: 'personnel',
                heading: 'nav.group.personnel',
                items: [
                    ...tile(personnelView, '/settings/personnel/ranks', 'ranks', 'medal'),
                    ...tile(personnelView, '/settings/personnel/fire-qualifications', 'fire_qualifications', 'fire'),
                    ...tile(personnelView, '/settings/personnel/ems-qualifications', 'ems_qualifications', 'kit-medical'),
                    ...tile(personnelView, '/settings/personnel/specialties', 'specialties', 'layer-group'),
                    ...tile(personnelView, '/settings/personnel/titles', 'titles', 'graduation-cap'),
                ],
            },
            {
                key: 'access',
                heading: 'nav.group.access',
                items: [
                    ...tile(usersView, '/users/roles', 'roles', 'user-shield'),
                    ...tile(can(user, 'users.create'), '/users/invitations', 'invitations', 'ticket'),
                    ...tile(can(user, 'audit.view'), '/users/audit-log', 'audit', 'clock-rotate-left'),
                ],
            },
            {
                key: 'content',
                heading: 'nav.group.content',
                items: [
                    ...tile(can(user, 'personnel.documents.manage'), '/settings/documents/editor-templates', 'templates', 'file-lines'),
                    ...tile(admin, '/settings/forms', 'form_types', 'list-check', 'forms'),
                    ...tile(can(user, 'dashboard.manage'), '/settings/dashboard', 'dashboard_settings', 'table-cells-large'),
                ],
            },
            {
                key: 'vehicles',
                heading: 'nav.group.vehicles',
                items: [
                    ...tile(vehiclesView, '/settings/vehicles/loadouts', 'loadouts', 'boxes-stacked'),
                    ...tile(vehiclesView, '/settings/vehicles/symbol-templates', 'symbol_templates', 'shapes'),
                    ...tile(can(user, 'vehicles.manage'), '/vehicles/registry', 'registry', 'clipboard-list'),
                ],
            },
            {
                key: 'enotf',
                heading: 'nav.group.enotf',
                items: [
                    ...tile(can(user, 'pois.view', 'pois.manage'), '/settings/pois', 'pois', 'location-dot'),
                    ...tile(can(user, 'enotf.settings'), '/settings/medications', 'medications', 'pills', 'enotf'),
                    ...tile(can(user, 'enotf.settings'), '/settings/enotf/quicklinks', 'quicklinks', 'bolt', 'enotf'),
                    ...tile(can(user, 'enotf.settings'), '/settings/enotf/categories', 'quicklink_categories', 'folder-tree', 'enotf'),
                ],
            },
            {
                key: 'mail',
                heading: 'nav.group.mail',
                items: [
                    ...tile(can(user, 'mail.lists.manage'), '/mail/lists', 'mail_lists', 'people-group', 'mail'),
                    ...tile(can(user, 'mail.admin'), '/settings/mail/mailboxes', 'mailboxes', 'envelopes-bulk', 'mail'),
                    ...tile(can(user, 'mail.admin'), '/settings/mail', 'mail_settings', 'sliders', 'mail'),
                ],
            },
            {
                key: 'system',
                heading: 'nav.group.system',
                items: [
                    ...tile(admin, '/settings/system/config', 'system_config', 'sliders'),
                    ...tile(admin, '/settings/system/modules', 'modules', 'puzzle-piece'),
                    ...tile(admin, '/settings/system', 'maintenance', 'screwdriver-wrench'),
                    ...tile(admin, '/settings/system/jobs', 'jobs', 'clock'),
                    ...tile(admin, '/settings/federation', 'federation', 'diagram-project', 'federation'),
                    ...tile(admin, '/settings/system/discord', 'discord', 'discord'),
                ],
            },
        ],
        modules,
    );
}

export function navGroups(user: NavUser | null, modules: ModuleSwitches = []): NavGroup[] {
    const settings = settingsGroups(user, modules).flatMap((g) => g.items.map((i) => i.href));
    const vehiclesView = can(user, 'vehicles.view');
    const formsAdmin = can(user, 'application.view', 'application.edit');

    return visibleGroups(
        [
            {
                key: 'start',
                heading: null,
                items: [
                    ...item(!!user, { href: '/', label: 'nav.dashboard', icon: 'house' }),
                    ...item(can(user, 'calendar.view', 'calendar.manage'), { href: '/calendar', label: 'nav.calendar', icon: 'calendar-days', module: 'calendar' }),
                    ...item(!!user, { href: '/lexicon', label: 'nav.lexicon', icon: 'book-medical', module: 'knowledgebase' }),
                    ...item(can(user, 'mail.use'), { href: '/mail', label: 'nav.mail', icon: 'envelope', module: 'mail', counter: 'mail' }),
                ],
            },
            {
                key: 'personnel',
                heading: 'nav.group.personnel',
                items: [
                    ...item(can(user, 'personnel.view'), { href: '/personnel', label: 'nav.personnel', icon: 'id-badge' }),
                    ...item(!!user, { href: formsAdmin ? '/forms/admin' : '/forms', label: 'nav.forms', icon: 'file-signature', module: 'forms', match: ['/forms'] }),
                ],
            },
            {
                key: 'protocols',
                heading: 'nav.group.protocols',
                items: [
                    ...item(can(user, 'enotf.view', 'edivi.view'), { href: '/enotf', label: 'nav.enotf_open', icon: 'file-medical', module: 'enotf', external: true }),
                    ...item(can(user, 'edivi.view', 'edivi.edit'), { href: '/enotf/admin', label: 'nav.enotf_admin', icon: 'file-medical', module: 'enotf', match: ['/enotf/qm'] }),
                    ...item(!!user, { href: '/firetab', label: 'nav.firetab_open', icon: 'fire', module: 'firetab', external: true }),
                    ...item(can(user, 'fire.incident.qm'), { href: '/firetab/qm', label: 'nav.firetab_qm', icon: 'clipboard-check', module: 'firetab' }),
                    ...item(can(user, 'mci.view', 'mci.manage'), { href: '/mci', label: 'nav.mci', icon: 'truck-medical', module: 'manvboard' }),
                ],
            },
            {
                key: 'vehicles',
                heading: 'nav.group.vehicles',
                items: [
                    ...item(vehiclesView, { href: '/vehicles', label: 'nav.vehicles', icon: 'truck' }),
                    ...item(vehiclesView, { href: '/vehicles/defects', label: 'nav.defects', icon: 'triangle-exclamation' }),
                    ...item(can(user, 'logbook.view', 'logbook.manage'), { href: '/logbook', label: 'nav.logbook', icon: 'road', module: 'logbook' }),
                ],
            },
            {
                key: 'admin',
                heading: 'nav.group.admin',
                items: [
                    ...item(can(user, 'users.view'), { href: '/users', label: 'nav.users', icon: 'users' }),
                    ...item(settings.length > 0, { href: '/settings', label: 'nav.settings', icon: 'gear', match: settings }),
                ],
            },
        ],
        modules,
    );
}

export function activeHref(groups: NavGroup[], url: string): string | null {
    const path = url.split(/[?#]/)[0] || '/';
    let best: { href: string; len: number } | null = null;
    for (const i of groups.flatMap((g) => g.items)) {
        if (i.external) continue;
        for (const p of [i.href, ...(i.match ?? [])]) {
            const hit = p === '/' ? path === '/' : path === p || path.startsWith(`${p}/`);
            if (hit && (best === null || p.length > best.len)) best = { href: i.href, len: p.length };
        }
    }

    return best?.href ?? null;
}

export function quickActions(user: NavUser | null, modules: ModuleSwitches = []): NavItem[] {
    return [
        ...item(can(user, 'personnel.edit'), { href: '/personnel/create', label: 'search.new.personnel', icon: 'id-badge' }),
        ...item(can(user, 'vehicles.manage'), { href: '/vehicles/create', label: 'search.new.vehicle', icon: 'truck' }),
        ...item(can(user, 'vehicles.manage', 'vehicles.view'), { href: '/vehicles/defects/create', label: 'search.new.defect', icon: 'triangle-exclamation' }),
        ...item(can(user, 'personnel.documents.manage'), { href: '/settings/documents/editor-templates/create', label: 'search.new.template', icon: 'file-lines' }),
        ...item(can(user, 'mci.manage'), { href: '/mci/create', label: 'manvboard.new_incident', icon: 'truck-medical', module: 'manvboard' }),
        ...item(can(user, 'mail.use'), { href: '/mail/compose', label: 'mail.new', icon: 'envelope', module: 'mail' }),
    ].filter((i) => moduleOn(i, modules));
}
