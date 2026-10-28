import { CalendarDays, GraduationCap, PlaneTakeoff, Siren, Users, type LucideIcon } from '@lucide/vue';

// The colour is never the only hint: every category has its own icon (and a text label in the dialogs).
const icons: Record<string, LucideIcon> = { general: CalendarDays, meeting: Users, training: GraduationCap, duty: Siren, absence: PlaneTakeoff };

export const categoryIcon = (category: string): LucideIcon => (Object.hasOwn(icons, category) ? icons[category] : CalendarDays);
