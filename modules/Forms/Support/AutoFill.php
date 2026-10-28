<?php

namespace Modules\Forms\Support;

use App\Models\Personnel;
use App\Models\Rank;
use Modules\Forms\Models\FormField;

/**
 * Values from the applicant's own record (legacy resolveAutoFillValue): the source of truth for readonly fields and
 * the prefill of the others. The rank follows the gender (Rank::labelFor); a record without a rank gives ''.
 */
final class AutoFill
{
    public static function forField(FormField $field, Personnel $personnel): string
    {
        return match ($field->auto_fill) {
            'fullname_dienstnr' => self::nameWithNumber($personnel),
            'fullname' => $personnel->full_name,
            'dienstnr' => $personnel->service_number,
            'dienstgrad' => self::rank($personnel),
            'discordtag' => (string) $personnel->discord_id,
            default => (string) $field->default_value,
        };
    }

    public static function nameWithNumber(Personnel $personnel): string
    {
        return mb_substr($personnel->full_name.' ('.$personnel->service_number.')', 0, 255);
    }

    public static function rank(Personnel $personnel): string
    {
        $rank = $personnel->getRelationValue('rank');

        return $rank instanceof Rank ? $rank->labelFor($personnel->gender) : '';
    }
}
