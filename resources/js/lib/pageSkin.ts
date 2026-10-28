import { forceDark } from '@/lib/theme';

export type PageSkin = { skin: 'core' | 'classic'; dark: boolean };

/**
 * Which token set a page gets (resources/css/tokens.css): the tablet pages (eNOTF, fireTab, clinic portal, print) keep
 * the old set `classic`; their desktop admin, QM and settings pages get `core` like everything else. The sign-in page
 * is always dark, as in the legacy system. The installer (Install/*) keeps `classic` too.
 * Same rule in PHP for the first paint: app/Support/PageSkin.php (keep both in step).
 */
export function pageSkin(component: string): PageSkin {
    const tablet = /^(Enotf|FireTab)\//.test(component) && !/^(Enotf\/(Admin|Qm|Settings)|FireTab\/Qm)\//.test(component);

    return { skin: tablet || component.startsWith('Install/') ? 'classic' : 'core', dark: component === 'Auth/Login' };
}

export function applyPageSkin(component: string): void {
    const { skin, dark } = pageSkin(component);
    if (typeof document !== 'undefined') document.documentElement.dataset.skin = skin;
    forceDark(dark);
}
