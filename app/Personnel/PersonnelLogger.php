<?php

namespace App\Personnel;

use App\Models\Document;
use App\Models\Personnel;
use App\Models\PersonnelLogEntry;
use InvalidArgumentException;

/**
 * Writes history entries (legacy PersonalLogManager). Wording and types are the legacy ones, minus the
 * <strong> markup: pages print every text escaped, so tags would show up literally.
 */
class PersonnelLogger
{
    public function created(Personnel $personnel, string $author): PersonnelLogEntry
    {
        return $this->add($personnel, PersonnelLogEntry::CREATED, 'Mitarbeiterprofil wurde angelegt.', $author, ['change_type' => 'profile_created']);
    }

    public function rankChanged(Personnel $personnel, string $old, string $new, string $author): PersonnelLogEntry
    {
        return $this->add($personnel, PersonnelLogEntry::RANK_CHANGE, "Dienstgrad wurde von {$old} auf {$new} geändert.", $author,
            ['change_type' => 'rank', 'old_value' => $old, 'new_value' => $new]);
    }

    /** @param 'RD'|'FW' $area */
    public function qualificationChanged(Personnel $personnel, string $area, string $old, string $new, string $author): PersonnelLogEntry
    {
        return $this->add($personnel, PersonnelLogEntry::MODIFICATION, "Qualifikation ({$area}) wurde von {$old} auf {$new} geändert.", $author,
            ['change_type' => 'qualification', 'qualification_type' => $area, 'old_value' => $old, 'new_value' => $new]);
    }

    public function modified(Personnel $personnel, string $author): PersonnelLogEntry
    {
        return $this->add($personnel, PersonnelLogEntry::MODIFICATION, 'Profildaten wurden bearbeitet.', $author, ['change_type' => 'profile_data']);
    }

    public function specialtiesModified(Personnel $personnel, string $author): PersonnelLogEntry
    {
        return $this->add($personnel, PersonnelLogEntry::MODIFICATION, 'Fachdienste wurden bearbeitet.', $author, ['change_type' => 'departments']);
    }

    public function pictureChanged(Personnel $personnel, bool $removed, string $author): PersonnelLogEntry
    {
        return $this->add($personnel, PersonnelLogEntry::MODIFICATION, $removed ? 'Profilbild entfernt.' : 'Profilbild geändert.', $author,
            ['change_type' => 'profile_picture']);
    }

    public function documentIssued(Personnel $personnel, Document $document, string $author): PersonnelLogEntry
    {
        return $this->add($personnel, PersonnelLogEntry::DOCUMENT, "Dokument ausgestellt: {$document->title} (Kennung {$document->public_id}).", $author,
            ['change_type' => 'document_issued', 'document_id' => $document->id]);
    }

    public function note(Personnel $personnel, int $type, string $content, string $author): PersonnelLogEntry
    {
        if (! in_array($type, PersonnelLogEntry::NOTE_TYPES, true)) {
            throw new InvalidArgumentException('Invalid note type');
        }

        return $this->add($personnel, $type, $content, $author);
    }

    public function delete(PersonnelLogEntry $entry): bool
    {
        return (bool) $entry->delete();
    }

    /** @param array<string, mixed>|null $metadata */
    private function add(Personnel $personnel, int $type, string $content, string $author, ?array $metadata = null): PersonnelLogEntry
    {
        return PersonnelLogEntry::create([
            'personnel_id' => $personnel->id, 'type' => $type, 'content' => $content, 'author' => $author, 'metadata' => $metadata,
        ]);
    }
}
