<?php

namespace App\Support;

use App\Models\User;
use App\Modules\ModuleRegistry;
use Closure;

/**
 * The protocol lists behind GET /me/protocols (FireTab incidents, eNOTF protocols). Modules register a source with
 * the rows of the account's own records; a source of a switched-off module is skipped. Core never names a module class.
 */
class OwnProtocols
{
    /** @var array<string, array{module: string, label: string, rows: Closure(User): list<array{title: string, sub: ?string, href: string}>}> */
    private array $sources = [];

    /** @param  Closure(User): list<array{title: string, sub: ?string, href: string}>  $rows  at most ~50 rows, newest first */
    public function register(string $module, string $key, string $label, Closure $rows): void
    {
        $this->sources[$key] = ['module' => $module, 'label' => $label, 'rows' => $rows];
    }

    /**
     * @return array{sources: list<array{key: string, label: string}>, groups: list<array{key: string, label: string, rows: list<array{title: string, sub: ?string, href: string}>}>}
     */
    public function for(User $user, ?string $only): array
    {
        $active = array_filter($this->sources, fn (array $s) => ModuleRegistry::active($s['module']));
        $groups = [];
        foreach ($active as $key => $s) {
            if ($only === null || $only === $key) {
                $groups[] = ['key' => $key, 'label' => $s['label'], 'rows' => ($s['rows'])($user)];
            }
        }

        return ['sources' => array_map(fn (string $k) => ['key' => $k, 'label' => $active[$k]['label']], array_keys($active)), 'groups' => $groups];
    }
}
