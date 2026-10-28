<?php

namespace App\Import;

use App\Documents\DocumentValidator;
use App\Documents\InvalidDocument;
use DomainException;
use RuntimeException;

/**
 * intra_document_templates (editor templates, legacy state T3) => document_templates. The legacy system knows
 * system templates only by name, so a seeded system template of the same name (case-insensitive) is adopted
 * and overwritten with the legacy values (LookupImporter). Content must pass DocumentValidator in template
 * mode, otherwise the row fails (counted, the run goes on). created_by goes through the id map (entity
 * `user`); an account that was not imported leaves it empty (one warning).
 */
final class DocumentTemplateImporter extends LookupImporter implements ChecksLegacyState
{
    public const LEGACY_VERSION = 20261016100001;

    private const ONLY_HINT = ' To import everything else without documents, name the entities, e.g.'
        .' --only=account_link,personnel_log,audit,auth_settings (their dependencies come along).';

    /** @var array<int|string, int> */
    private array $users = [];

    /** @var list<string> legacy ids whose creator was not imported */
    private array $noCreator = [];

    /** @var list<string> legacy ids that would not open in the editor */
    private array $shape = [];

    public function entity(): string
    {
        return 'document_template';
    }

    public function dependsOn(): array
    {
        return ['user'];
    }

    public function checkLegacyState(LegacyReader $legacy): void
    {
        self::assertEditorState($legacy);
    }

    /**
     * Only the editor state (T3) is imported. phinxlog must contain the analysed migration and the
     * pre-editor template table must be gone; older states have a different document schema.
     */
    public static function assertEditorState(LegacyReader $legacy): void
    {
        if (! $legacy->tableExists('phinxlog') || ! $legacy->table('phinxlog')->where('version', self::LEGACY_VERSION)->exists()) {
            throw new RuntimeException('Documents: the legacy phinxlog does not contain migration '.self::LEGACY_VERSION
                .'. Bring the legacy system to its last state (php cli/intra.php migrate) before importing documents.'.self::ONLY_HINT);
        }
        if ($legacy->tableExists('intra_dokument_templates')) {
            throw new RuntimeException('Documents: the legacy table intra_dokument_templates still exists (state before the editor).'
                .' Bring the legacy system to its last state (php cli/intra.php migrate) before importing documents.'.self::ONLY_HINT);
        }
    }

    public function import(ImportContext $ctx): void
    {
        $this->users = $ctx->ids()->forEntity('user');
        parent::import($ctx);
        [$noCreator, $shape] = [$this->noCreator, $this->shape];
        $this->noCreator = $this->shape = [];
        if ($noCreator !== []) {
            $ctx->report()->warn(count($noCreator).' document template(s): creator account not imported, left empty (legacy ids: '.implode(', ', array_slice($noCreator, 0, 20)).')');
        }
        if ($shape !== []) {
            $ctx->report()->warn(count($shape).' document template(s) would not open in the editor, imported as they are (legacy ids: '.implode(', ', array_slice($shape, 0, 20)).')');
        }
    }

    protected function legacyTable(): string
    {
        return 'intra_document_templates';
    }

    protected function table(): string
    {
        return 'document_templates';
    }

    protected function values(object $row): array
    {
        $content = json_decode((string) $row->content, true);
        if (! is_array($content)) {
            throw new DomainException('content is not a JSON object');
        }
        try {
            $content = DocumentValidator::validate($content, true);
        } catch (InvalidDocument $e) {
            throw new DomainException('content rejected by the validator: '.$e->getMessage());
        }
        if (DocumentImporter::editorShapeProblem($content) !== null) {
            $this->shape[] = (string) $row->id;
        }
        $createdBy = null;
        if ($row->created_by !== null) {
            $createdBy = $this->users[(string) $row->created_by] ?? null;
            if ($createdBy === null) {
                $this->noCreator[] = (string) $row->id;
            }
        }

        return [
            'name' => (string) $row->name,
            'category' => $row->category,
            'content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'is_active' => (bool) $row->is_active,
            'created_by' => $createdBy,
            'updated_at' => LegacyClock::toApp($row->updated_at) ?? LegacyClock::toApp($row->created_at) ?? now()->toDateTimeString(),
        ];
    }
}
