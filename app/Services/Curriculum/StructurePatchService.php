<?php

namespace App\Services\Curriculum;

use App\Exceptions\ValidationException;

class StructurePatchService
{
    /**
     * @param  array<string, mixed>  $structure
     * @param  array<int, array<string, mixed>>  $operations
     * @return array<string, mixed>
     */
    public function apply(array $structure, array $operations): array
    {
        if ($structure === [] || $operations === []) {
            throw new ValidationException('Invalid structure patch');
        }

        $next = json_decode(json_encode($structure, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                continue;
            }

            if (($operation['action'] ?? null) === 'rename' && ! empty($operation['key']) && ! empty($operation['title'])) {
                $this->mutateNode($next, (string) $operation['key'], function (array &$node) use ($operation): void {
                    $node['title'] = $operation['title'];
                });
            }

            if (
                ($operation['action'] ?? null) === 'adjust_pages'
                && ! empty($operation['key'])
                && is_numeric($operation['start_page'] ?? null)
                && is_numeric($operation['end_page'] ?? null)
            ) {
                $this->mutateNode($next, (string) $operation['key'], function (array &$node) use ($operation): void {
                    $node['start_page'] = (int) $operation['start_page'];
                    $node['end_page'] = (int) $operation['end_page'];
                });
            }

            if (($operation['action'] ?? null) === 'delete' && ! empty($operation['key'])) {
                $next = $this->deleteNode($next, (string) $operation['key']);
            }
        }

        return $next;
    }

    /**
     * @param  array<string, mixed>  $structure
     */
    private function mutateNode(array &$structure, string $key, callable $mutator): bool
    {
        if (($structure['key'] ?? null) === $key) {
            $mutator($structure);

            return true;
        }

        foreach ($structure['children'] ?? [] as $index => $child) {
            if (! is_array($child)) {
                continue;
            }

            if ($this->mutateNode($structure['children'][$index], $key, $mutator)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $structure
     * @return array<string, mixed>
     */
    private function deleteNode(array $structure, string $key): array
    {
        if (($structure['key'] ?? null) === $key) {
            throw new ValidationException('Cannot delete root book node');
        }

        $prune = function (array &$node) use (&$prune, $key): void {
            $node['children'] = array_values(array_filter(
                $node['children'] ?? [],
                fn ($child) => is_array($child) && ($child['key'] ?? null) !== $key
            ));

            foreach ($node['children'] as &$child) {
                if (is_array($child)) {
                    $prune($child);
                }
            }
        };

        $prune($structure);

        return $structure;
    }
}
