<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Personnel;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Hover cards (legacy Api\PersonnelController::card/cardByDienstnr): a short, read-only summary that
 * leaves out phone and Discord id. The right is checked before any lookup and a missing record answers
 * exactly like any other 404, so the card cannot be used to probe which ids or numbers exist.
 */
class PersonnelCardController extends Controller
{
    public function personnel(string $id): JsonResponse
    {
        $this->authorize('view', Personnel::class);

        return $this->respond(preg_match('/^\d{1,18}$/D', $id) === 1 ? Personnel::find((int) $id) : null);
    }

    public function byServiceNumber(string $number): JsonResponse
    {
        $this->authorize('view', Personnel::class);

        return $this->respond(Personnel::where('service_number', $number)->first());
    }

    /**
     * The account's own data always; the linked record's fields only for those who may view personnel,
     * the same line the account pages draw.
     */
    public function user(string $id): JsonResponse
    {
        $this->authorize('viewList', User::class);

        $user = preg_match('/^\d{1,18}$/D', $id) === 1 ? User::with('personnel')->find((int) $id) : null;
        if ($user === null) {
            return $this->notFound();
        }
        $person = $this->actor()->can('view', Personnel::class) ? $user->personnel : null;

        return $this->json($person !== null ? $this->card($person) : [
            'id' => null, 'name' => $user->name ?? $user->username, 'rank' => null, 'service_number' => null,
            'ems' => null, 'fire' => null, 'picture_url' => null,
            'linked_account' => $user->personnel_id !== null ? ['username' => $user->username] : null,
        ]);
    }

    private function actor(): User
    {
        $actor = request()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function respond(?Personnel $person): JsonResponse
    {
        return $person === null ? $this->notFound() : $this->json($this->card($person));
    }

    /** @return array<string, mixed> */
    private function card(Personnel $p): array
    {
        $p->loadMissing(['rank', 'title', 'emsQualification', 'fireQualification', 'user']);
        // The account name is the viewer's business only with the users right.
        $account = $p->user !== null && $this->actor()->can('viewList', User::class) ? ['username' => $p->user->username] : null;

        return [
            'id' => $p->id,
            'name' => $p->displayName(),
            'rank' => ['label' => $p->rank->labelFor($p->gender), 'badge' => $p->rank->badge],
            'service_number' => $p->service_number,
            'ems' => $p->emsQualification->is_none ? null : $p->emsQualification->labelFor($p->gender),
            'fire' => $p->fireQualification->is_none ? null : $p->fireQualification->short_name,
            'picture_url' => $p->pictureUrl(),
            'linked_account' => $account,
        ];
    }

    /** @param array<string, mixed> $card */
    private function json(array $card): JsonResponse
    {
        return response()->json($card)->header('Cache-Control', 'private, max-age=60');
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Not found.'], 404);
    }
}
