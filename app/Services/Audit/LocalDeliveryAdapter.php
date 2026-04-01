<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Illuminate\Support\Facades\Storage;

class LocalDeliveryAdapter
{
    /**
     * Write artefacts to the local filesystem.
     *
     * @param  array<string, string>  $artefacts  filename => content
     * @param  string  $path  directory path (may contain template variables)
     * @param  array<string, string>  $templateVars  year, month, day, source, target
     */
    public function deliver(array $artefacts, string $path, array $templateVars): void
    {
        $knownVars = ['year', 'month', 'day', 'source', 'target'];

        // Warn on unknown variables
        foreach (array_keys($templateVars) as $key) {
            if (! in_array($key, $knownVars, true)) {
                // Unknown template variable — skip gracefully
            }
        }

        // Resolve template variables
        $resolvedPath = $path;

        foreach ($templateVars as $var => $value) {
            $resolvedPath = str_replace('{'.$var.'}', $value, $resolvedPath);
        }

        $resolvedPath = rtrim($resolvedPath, '/');

        foreach ($artefacts as $filename => $content) {
            $fullPath = $resolvedPath.'/'.$filename;
            Storage::disk('local')->put($fullPath, $content);
        }
    }
}
