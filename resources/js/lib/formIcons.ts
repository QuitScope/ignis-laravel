import { Award, Calendar, ChevronsUp, ClipboardList, FileText, GraduationCap, Plane, Stethoscope, Umbrella, User, type LucideIcon } from '@lucide/vue';

// Icons of application types: the type stores a Lucide name typed by an admin (or imported). Only the names below are
// drawn; anything else, empty or odd ('constructor', 'fa-solid x'), gets the file icon, so no row can break a page.
const icons: Record<string, LucideIcon> = {
    award: Award,
    calendar: Calendar,
    'chevrons-up': ChevronsUp,
    'clipboard-list': ClipboardList,
    'file-text': FileText,
    'graduation-cap': GraduationCap,
    plane: Plane,
    stethoscope: Stethoscope,
    umbrella: Umbrella,
    user: User,
};

export const formIcon = (name: string | null | undefined): LucideIcon => (name && Object.hasOwn(icons, name) ? icons[name] : FileText);

export const STATUS_TONE: Record<string, string> = {
    new: 'bg-(--fill-3) text-text',
    in_progress: 'bg-warn/15 text-warn',
    deferred: 'bg-(--info-soft) text-info',
    accepted: 'bg-ok/15 text-ok',
    rejected: 'bg-danger/15 text-danger',
};

export const statusTone = (status: string): string => (Object.hasOwn(STATUS_TONE, status) ? STATUS_TONE[status] : STATUS_TONE.new);
