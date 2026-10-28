// Shared by the defect list, the vehicle's defects tab and the defect dialog.

export type DefectEvent = { action: string; details: string | null; user: string | null; time: string | null };

export type DefectRow = {
    id: number;
    title: string;
    category: string;
    status: string;
    is_blocking: boolean;
    vehicle: { id: number; name: string };
    reporter: string | null;
    assigned_to: number | null;
    assignee: string | null;
    created_at: string | null;
    last_event: DefectEvent | null;
};

export type Assignable = { id: number; name: string };

export const defectTone: Record<string, string> = {
    open: 'bg-(--danger-soft) text-danger',
    in_progress: 'bg-(--warn-soft) text-warn',
    deferred: 'bg-(--info-soft) text-info',
    resolved: 'bg-(--ok-soft) text-ok',
};

export const DEFECT_STATUSES = ['open', 'in_progress', 'deferred', 'resolved'] as const;

export const time = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : '–');
