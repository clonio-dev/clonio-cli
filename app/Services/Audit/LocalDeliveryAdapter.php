<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Contracts\DeliveryAdapterInterface;
use Illuminate\Support\Facades\Storage;

class LocalDeliveryAdapter implements DeliveryAdapterInterface
{
    public function deliver(array $artefacts, array $channelConfig, array $templateVars): void
    {
        $path = is_string($channelConfig['path'] ?? null) ? $channelConfig['path'] : '.';

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
