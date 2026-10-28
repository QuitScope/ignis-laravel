import { Ambulance, Bell, Calendar, FileText, Flame, FolderOpen, Mail, Settings, Wrench, type LucideIcon } from '@lucide/vue';

// Lucide names the server sends (NotificationKinds); module kinds add theirs here. Unknown names get the bell.
const icons: Record<string, LucideIcon> = { 'folder-open': FolderOpen, wrench: Wrench, settings: Settings, 'file-text': FileText, calendar: Calendar, mail: Mail, flame: Flame, ambulance: Ambulance };

export const kindIcon = (name: string): LucideIcon => (Object.hasOwn(icons, name) ? icons[name] : Bell);
