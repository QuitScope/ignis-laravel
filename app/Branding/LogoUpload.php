<?php

namespace App\Branding;

use App\Models\Setting;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\ImageUpload;
use App\Support\Settings;
use App\Support\UploadRejected;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The installation's logo: one file on the private `branding` disk, its random name in `system.logo`
 * (legacy SystemController::uploadLogo, with ImageUpload's checks). A stored name is trusted only if it
 * matches PATTERN, so a hostile or hand-edited setting can neither be served nor delete anything.
 */
final class LogoUpload
{
    public const DISK = 'branding';

    public const KEY = 'system.logo';

    public const PATTERN = '/^[a-f0-9]{32}\.(png|jpg|webp)$/D';

    public const MAX_BYTES = 2 * 1024 * 1024;

    public const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public const MIME = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];

    public function __construct(private readonly Settings $settings) {}

    public function current(): ?string
    {
        $name = $this->settings->get(self::KEY);

        return is_string($name) && preg_match(self::PATTERN, $name) === 1 ? $name : null;
    }

    /** @throws UploadRejected */
    public function store(UploadedFile $file, ?User $actor): void
    {
        $name = ImageUpload::store($file, self::DISK, self::MAX_BYTES, self::TYPES);
        $this->swap($name, fn () => AuditLogger::log($actor, 'Logo geändert', null, 'System', true));
    }

    /**
     * The legacy import: a local file the caller already confined to its folder, checked like an upload and stored
     * under a new random name; replaces the current logo. No audit line (the import logs its own).
     *
     * @throws UploadRejected
     */
    public function storeFromPath(string $path): void
    {
        $name = bin2hex(random_bytes(16)).'.'.ImageUpload::check($path, self::MAX_BYTES, self::TYPES);
        $bytes = ImageUpload::clean($path, self::MAX_BYTES);
        try {
            $written = Storage::disk(self::DISK)->put($name, $bytes);
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($name);
            throw $e;
        }
        if (! $written) {
            Storage::disk(self::DISK)->delete($name);
            throw new UploadRejected(trans('upload.failed'));
        }
        $this->swap($name);
    }

    /**
     * Makes $name the logo. The old name is read under a lock on the setting's row, so a second upload racing this one
     * sees this one's file as old (and deletes it) instead of both leaking the same predecessor. The old file goes
     * after the commit; a failure leaves no orphan of the new one.
     */
    private function swap(string $name, ?callable $inside = null): void
    {
        try {
            $old = DB::transaction(function () use ($name, $inside) {
                $stored = Setting::where('key', self::KEY)->lockForUpdate()->value('value');
                $old = is_string($stored) ? json_decode($stored, true) : null;
                $this->settings->set(self::KEY, $name);
                $inside && $inside();

                return is_string($old) ? $old : null;
            });
        } catch (Throwable $e) {
            $this->settings->refresh();
            Storage::disk(self::DISK)->delete($name);
            throw $e;
        }
        $this->settings->refresh();
        $this->delete($old);
    }

    public function remove(?User $actor): void
    {
        if (! $this->settings->has(self::KEY)) {
            return;
        }
        $old = $this->current();
        $this->settings->forget(self::KEY);
        AuditLogger::log($actor, 'Logo entfernt', null, 'System', true);
        $this->delete($old);
    }

    private function delete(?string $name): void
    {
        if ($name !== null && preg_match(self::PATTERN, $name) === 1) {
            Storage::disk(self::DISK)->delete($name);
        }
    }
}
