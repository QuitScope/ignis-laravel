import { describe, expect, it, vi } from 'vitest';
import { addAddresses, composeHref, debounce, errorText, folderHref, formatSize, mailRequest, nextIndex, parseAddresses, uploadAttachment } from '@/lib/mail';

class FakeXhr {
    method = '';
    url = '';
    headers: Record<string, string> = {};
    body: FormData | null = null;
    status = 0;
    responseText = '';
    upload: { onprogress: ((e: { lengthComputable: boolean; loaded: number; total: number }) => void) | null } = { onprogress: null };
    onload: (() => void) | null = null;
    onerror: (() => void) | null = null;
    open(method: string, url: string) { this.method = method; this.url = url; }
    setRequestHeader(k: string, v: string) { this.headers[k] = v; }
    send(body: FormData) { this.body = body; }
    respond(status: number, text: string) { this.status = status; this.responseText = text; this.onload?.(); }
}

describe('uploadAttachment', () => {
    const file = () => new File(['Dienstplan'], 'plan.txt', { type: 'text/plain' });

    it('posts the file and the mailbox as multipart, reports progress and the stored attachment', async () => {
        const xhr = new FakeXhr();
        const progress: number[] = [];
        const done = uploadAttachment(41, file(), 3, (p) => progress.push(p), () => xhr as unknown as XMLHttpRequest);

        expect(xhr.method).toBe('POST');
        expect(xhr.url).toBe('/mail/drafts/41/attachments');
        expect(xhr.headers.Accept).toBe('application/json');
        expect(xhr.headers).toHaveProperty('X-XSRF-TOKEN');
        expect((xhr.body?.get('file') as File).name).toBe('plan.txt');
        expect(xhr.body?.get('mailbox_id')).toBe('3');
        xhr.upload.onprogress?.({ lengthComputable: true, loaded: 5, total: 10 });
        xhr.upload.onprogress?.({ lengthComputable: false, loaded: 7, total: 0 });
        xhr.respond(201, '{"attachment":{"id":9,"name":"plan.txt","size":10}}');

        expect(await done).toEqual({ ok: true, status: 201, data: { attachment: { id: 9, name: 'plan.txt', size: 10 } } });
        expect(progress).toEqual([50]);
    });

    it('hands back refusals, non-JSON answers and network errors without throwing', async () => {
        let xhr = new FakeXhr();
        let done = uploadAttachment(41, file(), 3, () => {}, () => xhr as unknown as XMLHttpRequest);
        xhr.respond(422, '{"message":"Im Postfach ist kein Platz mehr"}');
        const refused = await done;
        expect(refused.ok).toBe(false);
        expect(errorText(refused)).toBe('Im Postfach ist kein Platz mehr');

        xhr = new FakeXhr();
        done = uploadAttachment(41, file(), 3, () => {}, () => xhr as unknown as XMLHttpRequest);
        xhr.respond(413, '<html>Too large</html>');
        expect(await done).toEqual({ ok: false, status: 413, data: {} });

        xhr = new FakeXhr();
        done = uploadAttachment(41, file(), 3, () => {}, () => xhr as unknown as XMLHttpRequest);
        xhr.onerror?.();
        expect(await done).toEqual({ ok: false, status: 0, data: {} });
    });

    it('formats sizes', () => {
        expect(formatSize(10)).toBe('1 KB');
        expect(formatSize(300 * 1024)).toBe('300 KB');
        expect(formatSize(5 * 1024 * 1024)).toBe('5.0 MB');
    });
});

describe('mail urls', () => {
    it('always name the mailbox when there is one', () => {
        expect(folderHref('inbox', 7)).toBe('/mail/inbox?mailbox=7');
        expect(folderHref('archive', 7, 12)).toBe('/mail/archive/12?mailbox=7');
        expect(folderHref('sent', null)).toBe('/mail/sent');
        expect(composeHref('reply-all', 12, 3)).toBe('/mail/compose/reply-all/12?mailbox=3');
        expect(composeHref('draft', 12, null)).toBe('/mail/compose/draft/12');
    });
});

describe('addresses', () => {
    it('splits typed or pasted text, lower-cased, without repeats', () => {
        expect(parseAddresses(' A@ignis.ef, b@ignis.ef;a@IGNIS.ef\nc@ignis.ef ')).toEqual(['a@ignis.ef', 'b@ignis.ef', 'c@ignis.ef']);
        expect(parseAddresses(' ,; ')).toEqual([]);
    });

    it('adds without repeating, case-insensitive', () => {
        expect(addAddresses(['A@ignis.ef'], ['a@ignis.ef', 'b@ignis.ef', 'b@ignis.ef'])).toEqual(['A@ignis.ef', 'b@ignis.ef']);
    });
});

describe('nextIndex', () => {
    it('moves and clamps on arrows, Home and End', () => {
        expect(nextIndex('ArrowDown', 0, 3)).toBe(1);
        expect(nextIndex('ArrowDown', 2, 3)).toBe(2);
        expect(nextIndex('ArrowUp', 0, 3)).toBe(0);
        expect(nextIndex('Home', 2, 3)).toBe(0);
        expect(nextIndex('End', 0, 3)).toBe(2);
        expect(nextIndex('Enter', 0, 3)).toBeNull();
        expect(nextIndex('ArrowDown', 0, 0)).toBeNull();
    });
});

describe('debounce', () => {
    it('calls once after the quiet time and can be cancelled', () => {
        vi.useFakeTimers();
        const fn = vi.fn();
        const d = debounce(fn, 100);
        d(1);
        d(2);
        vi.advanceTimersByTime(99);
        expect(fn).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);
        expect(fn).toHaveBeenCalledExactlyOnceWith(2);
        d(3);
        d.cancel();
        vi.advanceTimersByTime(200);
        expect(fn).toHaveBeenCalledTimes(1);
        vi.useRealTimers();
    });
});

describe('mailRequest', () => {
    it('sends JSON with the XSRF token and reads the answer', async () => {
        document.cookie = 'XSRF-TOKEN=abc%3D';
        const send = vi.fn(async () => new Response(JSON.stringify({ messageId: 5 }), { status: 201 }));
        const res = await mailRequest('POST', '/mail/drafts', { subject: 'x' }, send as unknown as typeof fetch);

        expect(res).toEqual({ ok: true, status: 201, data: { messageId: 5 } });
        const [url, init] = send.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe('/mail/drafts');
        expect(init.method).toBe('POST');
        expect((init.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('abc=');
        expect(init.body).toBe('{"subject":"x"}');
    });

    it('never throws and names the first error', async () => {
        const down = await mailRequest('PUT', '/x', {}, (() => Promise.reject(new Error('offline'))) as unknown as typeof fetch);
        expect(down.status).toBe(0);

        const invalid = await mailRequest('PUT', '/x', {}, (async () => new Response(JSON.stringify({ message: 'm', errors: { subject: ['Zu lang'] } }), { status: 422 })) as unknown as typeof fetch);
        expect(errorText(invalid)).toBe('Zu lang');
        expect(errorText({ ok: false, status: 409, data: { message: 'Schon gesendet' } })).toBe('Schon gesendet');
        expect(errorText({ ok: true, status: 200, data: {} })).toBeNull();
    });
});
