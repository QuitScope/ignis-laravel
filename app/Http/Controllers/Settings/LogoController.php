<?php

namespace App\Http\Controllers\Settings;

use App\Branding\LogoUpload;
use App\Http\Controllers\Controller;
use App\Support\UploadRejected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/** Upload and removal of the logo (system.admin); the file itself is served by BrandingController. */
class LogoController extends Controller
{
    public function store(Request $request, LogoUpload $logo): RedirectResponse
    {
        $this->authorize('system.admin');
        $file = $request->file('logo');
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['logo' => trans('upload.missing')]);
        }

        try {
            $logo->store($file, $request->user());
        } catch (UploadRejected $e) {
            throw ValidationException::withMessages(['logo' => $e->getMessage()]);
        }

        return $this->back('settings.logo.saved');
    }

    public function destroy(Request $request, LogoUpload $logo): RedirectResponse
    {
        $this->authorize('system.admin');
        $logo->remove($request->user());

        return $this->back('settings.logo.removed');
    }

    private function back(string $status): RedirectResponse
    {
        return redirect('/settings/system/config?tab=general')->with('status', $status);
    }
}
