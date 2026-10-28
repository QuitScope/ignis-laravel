/**
 * Helpers of the mail workspace and the compose form (module Mail). URLs always name the mailbox (?mailbox=), so a
 * page opened from a group mailbox stays in it; requests send JSON with the XSRF cookie, like the editor drafts.
 */
import { xsrfToken } from '@/lib/draftSave';

export const FOLDERS = ['inbox', 'sent', 'drafts', 'archive', 'trash'] as const;
export type Folder = (typeof FOLDERS)[number];
export type ComposeMode = 'reply' | 'reply-all' | 'forward';
export type AddressKind = 'personal' | 'group' | 'list';
export type AddressEntry = { address: string; name: string; kind: AddressKind };

export type MailboxRef = { id: number; address: string; display_name: string; kind: 'personal' | 'group' };
export type Person = { name: string; address: string };
export type MessageRow = { id: number; subject: string; from: Person; to: string[]; date: string | null; unread: boolean; flagged: boolean; draft: boolean; preview: string };
export type OpenMessage = {
    id: number;
    folder: Folder;
    role: 'sender' | 'to' | 'cc' | 'bcc';
    subject: string;
    from: Person;
    sentBy: string | null;
    header: { to: string[]; cc: string[]; bcc: string[] };
    date: string | null;
    html: string;
    broken: boolean;
    draft: boolean;
    flagged: boolean;
    unread: boolean;
    attachments: (AttachmentRef & { missing: boolean })[];
};
export type AttachmentRef = { id: number; name: string; size: number };
export type ComposeProps = {
    mode: 'new' | ComposeMode | 'draft';
    mailbox: MailboxRef;
    draftId: number | null;
    subject: string;
    recipients: { to: string[]; cc: string[]; bcc: string[] };
    body: Record<string, unknown>;
    inReplyTo: number | null;
    forwardFrom: number | null;
    forwardNames: string[];
    attachments: AttachmentRef[];
};

export function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' }) : '';
}

const withMailbox =(path: string, mailboxId: number | null | undefined) => (mailboxId ? `${path}?mailbox=${mailboxId}` : path);

export function folderHref(folder: Folder, mailboxId?: number | null, messageId?: number): string {
    return withMailbox(messageId === undefined ? `/mail/${folder}` : `/mail/${folder}/${messageId}`, mailboxId);
}

export function composeHref(mode: ComposeMode | 'draft', messageId: number, mailboxId?: number | null): string {
    return withMailbox(`/mail/compose/${mode}/${messageId}`, mailboxId);
}

export function parseAddresses(text: string): string[] {
    return [...new Set(text.split(/[\s,;]+/).map((a) => a.trim().toLowerCase()).filter((a) => a !== ''))];
}

export function addAddresses(list: string[], added: string[]): string[] {
    const seen = new Set(list.map((a) => a.toLowerCase()));
    return [...list, ...added.filter((a) => !seen.has(a.toLowerCase()) && seen.add(a.toLowerCase()))];
}

export function nextIndex(key: string, current: number, length: number): number | null {
    if (length === 0) return null;
    switch (key) {
        case 'ArrowDown':
            return Math.min(current + 1, length - 1);
        case 'ArrowUp':
            return Math.max(current - 1, 0);
        case 'Home':
            return 0;
        case 'End':
            return length - 1;
        default:
            return null;
    }
}

export function debounce<A extends unknown[]>(fn: (...args: A) => void, wait: number): ((...args: A) => void) & { cancel: () => void } {
    let timer: ReturnType<typeof setTimeout> | undefined;
    const call = (...args: A) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), wait);
    };
    return Object.assign(call, { cancel: () => clearTimeout(timer) });
}

export type MailResponse<T extends object = Record<string, unknown>> = { ok: boolean; status: number; data: T & { message?: string; errors?: Record<string, string[]>; retry_after?: number } };

export async function mailRequest<T extends object = Record<string, unknown>>(method: string, url: string, body?: unknown, send: typeof fetch = fetch): Promise<MailResponse<T>> {
    try {
        const res = await send(url, {
            method,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        const data = await res.json().catch(() => ({}));
        return { ok: res.ok, status: res.status, data };
    } catch {
        return { ok: false, status: 0, data: {} as MailResponse<T>['data'] };
    }
}

export const ATTACHMENT_MAX_MB = 5;
export const MAIL_ATTACHMENTS_MAX_MB = 10;
export const ATTACHMENT_ACCEPT = 'image/png,image/jpeg,image/webp,image/gif,application/pdf,text/plain';

export function formatSize(bytes: number): string {
    return bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

export function uploadAttachment(
    draftId: number,
    file: File,
    mailboxId: number,
    onProgress: (percent: number) => void,
    make: () => XMLHttpRequest = () => new XMLHttpRequest(),
): Promise<MailResponse<{ attachment: AttachmentRef }>> {
    return new Promise((resolve) => {
        const xhr = make();
        const body = new FormData();
        body.append('file', file);
        body.append('mailbox_id', String(mailboxId));
        xhr.open('POST', `/mail/drafts/${draftId}/attachments`);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-XSRF-TOKEN', xsrfToken());
        xhr.upload.onprogress = (e) => {
            if (e.lengthComputable && e.total > 0) onProgress(Math.round((e.loaded / e.total) * 100));
        };
        xhr.onload = () => {
            let data = {};
            try {
                data = JSON.parse(xhr.responseText);
            } catch {
            }
            resolve({ ok: xhr.status >= 200 && xhr.status < 300, status: xhr.status, data: data as MailResponse<{ attachment: AttachmentRef }>['data'] });
        };
        xhr.onerror = () => resolve({ ok: false, status: 0, data: {} as MailResponse<{ attachment: AttachmentRef }>['data'] });
        xhr.send(body);
    });
}

export function errorText(res: MailResponse<object>): string | null {
    if (res.ok) return null;
    const first = res.data.errors ? Object.values(res.data.errors)[0]?.[0] : undefined;
    return first ?? res.data.message ?? null;
}
