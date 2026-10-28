<?php

namespace Modules\Enotf\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Poi;
use App\Support\SqlLike;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /enotf/pois/search?q= (crew:enotf): active POIs for the destination picker, at most 20. */
class PoiSearchController extends Controller
{
    private const LIMIT = 20;

    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);
        $query = Poi::query()->where('is_active', true);
        SqlLike::anyOf($query, ['name', 'city', 'legacy_identifier'], $q);

        return response()->json(['data' => $query->orderBy('name')->orderBy('id')->limit(self::LIMIT)->get()->map(fn (Poi $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'type' => $p->type,
            'identifier' => $p->legacy_identifier,
            'address' => ['strasse' => $p->street, 'hnr' => $p->house_number, 'ort' => $p->city, 'ortsteil' => $p->district],
        ])->all()]);
    }
}
