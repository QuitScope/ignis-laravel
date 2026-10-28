import { expect, it } from 'vitest';
import { Bell, ChevronsUp, FileText, Mail, Umbrella } from '@lucide/vue';
import { formIcon, statusTone, STATUS_TONE } from '@/lib/formIcons';
import { kindIcon } from '@/components/notifications/icons';

it('draws known type icons and falls back to the file icon for anything else', () => {
    expect(formIcon('umbrella')).toBe(Umbrella);
    expect(formIcon('chevrons-up')).toBe(ChevronsUp);
    for (const odd of [null, undefined, '', 'nope', 'constructor', '__proto__', 'toString', 'fa-solid fa-truck']) {
        expect(formIcon(odd)).toBe(FileText);
    }
});

it('maps unknown statuses to the neutral badge', () => {
    expect(statusTone('accepted')).toBe(STATUS_TONE.accepted);
    expect(statusTone('deferred')).toBe(STATUS_TONE.deferred);
    expect(statusTone('constructor')).toBe(STATUS_TONE.new);
});

it('shows the file icon for application notifications and never an inherited property', () => {
    expect(kindIcon('file-text')).toBe(FileText);
    expect(kindIcon('mail')).toBe(Mail);
    expect(kindIcon('constructor')).toBe(Bell);
});
