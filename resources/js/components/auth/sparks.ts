// Rising sparks of the sign-in stage, port of the legacy ignis/public/assets/js/ui/login-stage.js. Pure decoration:
// the colour is the canvas' CSS `color`, `prefers-reduced-motion` gets one still frame, a hidden tab draws nothing.

export const MAX_SPARKS = 26;

type Spark = { x: number; y: number; speed: number; size: number; phase: number };

export function sparkLayout(width: number, height: number, dpr: number) {
    const ratio = Math.min(dpr || 1, 1.5);

    return { ratio, width: Math.round(width * ratio), height: Math.round(height * ratio), count: Math.min(MAX_SPARKS, Math.round(width / 36)) };
}

export function sparks(canvas: HTMLCanvasElement): () => void {
    const ctx = canvas.getContext('2d');
    if (!ctx) return () => {};
    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)');
    let w = 0;
    let h = 0;
    let list: Spark[] = [];
    let frame = 0;
    let last = 0;
    let color = getComputedStyle(canvas).color;

    const spawn = (p: Spark, anywhere: boolean): Spark => {
        p.x = w * (0.5 + (Math.random() + Math.random() - 1) * 0.42);
        p.y = anywhere ? h * (0.25 + Math.random() * 0.75) : h + 8;
        p.speed = 16 + Math.random() * 30;
        p.size = 0.6 + Math.random() * 1.1;
        p.phase = Math.random() * 6.28;
        return p;
    };
    const dot = (x: number, y: number, r: number) => {
        ctx.beginPath();
        ctx.arc(x, y, r, 0, 6.28);
        ctx.fill();
    };
    const draw = (dt: number) => {
        ctx.clearRect(0, 0, w, h);
        ctx.globalCompositeOperation = 'lighter';
        ctx.fillStyle = color;
        for (const p of list) {
            p.y -= p.speed * dt;
            p.phase += dt * 1.4;
            if (p.y < h * 0.2) spawn(p, false);
            const alpha = Math.min(1, (p.y - h * 0.2) / (h * 0.5)) * (0.55 + 0.45 * Math.sin(p.phase * 3));
            const x = p.x + Math.sin(p.phase) * 10;
            ctx.globalAlpha = alpha * 0.18;
            dot(x, p.y, p.size * 3.2);
            ctx.globalAlpha = alpha * 0.9;
            dot(x, p.y, p.size);
        }
    };
    const tick = (t: number) => {
        draw(Math.min((t - last) / 1000, 0.05));
        last = t;
        frame = requestAnimationFrame(tick);
    };
    const stop = () => {
        cancelAnimationFrame(frame);
        frame = 0;
    };
    const update = () => {
        stop();
        color = getComputedStyle(canvas).color;
        if (reduced?.matches) draw(0);
        else if (!document.hidden)
            frame = requestAnimationFrame((t) => {
                last = t;
                tick(t);
            });
    };
    const resize = () => {
        w = canvas.clientWidth;
        h = canvas.clientHeight;
        const l = sparkLayout(w, h, window.devicePixelRatio);
        canvas.width = l.width;
        canvas.height = l.height;
        ctx.setTransform(l.ratio, 0, 0, l.ratio, 0, 0);
        list = Array.from({ length: l.count }, () => spawn({ x: 0, y: 0, speed: 0, size: 0, phase: 0 }, true));
        if (!frame) draw(0);
    };

    const observer = 'ResizeObserver' in window ? new ResizeObserver(resize) : null;
    observer?.observe(canvas);
    document.addEventListener('visibilitychange', update);
    reduced?.addEventListener?.('change', update);
    resize();
    update();

    return () => {
        stop();
        observer?.disconnect();
        document.removeEventListener('visibilitychange', update);
        reduced?.removeEventListener?.('change', update);
    };
}
