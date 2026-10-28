<?php

namespace App\Documents;

use App\Models\Document;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Document -> PDF on the 'documents' disk (`editor/{public_id}.pdf`). The only input Dompdf sees is the
 * output of DocumentRenderer (validated, allowlisted tags, escaped text) inside our own template; nothing from
 * a document reaches a style, src or href. On top of that Dompdf is locked down: no remote or local resources
 * (remote off, no protocols, chroot is an empty directory), no inline PHP, no JavaScript.
 *
 * Page numbers "n von m" are stamped after render with Dompdf's canvas page_text(), which resolves
 * {PAGE_NUM}/{PAGE_COUNT} at output time (this is not the PHP/JS script feature that stays disabled).
 */
final class PdfGenerator
{
    private const TIME_LIMIT = 60;

    private const MEMORY_LIMIT = '256M';

    private const A4_WIDTH = 595.28;

    private const A4_HEIGHT = 841.89;

    /**
     * Renders and stores the PDF atomically; returns the path relative to the 'documents' disk.
     *
     * @throws InvalidDocument
     * @throws RuntimeException
     */
    public function generate(Document $doc): string
    {
        $html = $this->html($doc);
        $path = 'editor/'.$doc->public_id.'.pdf';
        $disk = Storage::disk('documents');
        $tmp = 'editor/.'.$doc->public_id.'.'.bin2hex(random_bytes(6)).'.tmp';

        try {
            $disk->put($tmp, $this->render($html)) || throw new RuntimeException('PDF konnte nicht gespeichert werden.');
            $disk->move($tmp, $path) || throw new RuntimeException('PDF konnte nicht abgelegt werden.');
        } catch (Throwable $e) {
            $disk->delete($tmp);

            throw $e instanceof RuntimeException ? $e : new RuntimeException('PDF konnte nicht abgelegt werden.', 0, $e);
        }

        return $path;
    }

    public function html(Document $doc): string
    {
        $vars = $doc->status === Document::ISSUED ? ($doc->frozen_values ?? []) : VariableCatalog::resolve($doc);
        $body = (new DocumentRenderer)->render($doc->content, $vars, blankUnresolved: true)->html;

        return view('pdf.document', ['title' => $doc->title, 'body' => $body])->render();
    }

    public function options(): Options
    {
        $chroot = storage_path('framework/pdf-chroot');
        $cache = storage_path('framework/cache/dompdf');
        foreach ([$chroot, $cache] as $dir) {
            is_dir($dir) || @mkdir($dir, 0775, true);
        }

        $o = new Options;
        $o->setIsRemoteEnabled(false);
        $o->setIsPhpEnabled(false);
        $o->setIsJavascriptEnabled(false);
        // Nothing to read below an empty directory, and no protocol (file, http, data, phar) may load anything.
        $o->setChroot([$chroot]);
        $o->setAllowedProtocols([]);
        $o->setDefaultFont('DejaVu Sans');
        $o->setDefaultPaperSize('a4');
        $o->setDefaultPaperOrientation('portrait');
        $o->setTempDir($cache);
        $o->setFontCache($cache);

        return $o;
    }

    private function render(string $html, ?string $startMemory = null): string
    {
        $time = (string) ini_get('max_execution_time');
        $memory = $startMemory ?? (string) ini_get('memory_limit');
        set_time_limit(self::TIME_LIMIT);
        if ($memory !== '-1' && self::bytes($memory) < self::bytes(self::MEMORY_LIMIT)) {
            ini_set('memory_limit', self::MEMORY_LIMIT);
        }

        try {
            $dompdf = new Dompdf($this->options());
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $dompdf->getCanvas()->page_text(
                self::A4_WIDTH - 51 - 45, self::A4_HEIGHT - 40, '{PAGE_NUM} von {PAGE_COUNT}',
                $dompdf->getFontMetrics()->getFont('DejaVu Sans'), 8, [0.4, 0.4, 0.4],
            );
            $output = (string) $dompdf->output();
            unset($dompdf);
            gc_collect_cycles();

            return $output;
        } finally {
            set_time_limit((int) $time);
            @ini_set('memory_limit', $memory);
        }
    }

    private static function bytes(string $v): int
    {
        $n = (int) $v;

        return match (strtolower(substr(trim($v), -1))) {
            'g' => $n << 30,
            'm' => $n << 20,
            'k' => $n << 10,
            default => $n,
        };
    }
}
