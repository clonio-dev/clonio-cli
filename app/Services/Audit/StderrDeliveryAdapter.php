<?php

declare(strict_types=1);

namespace App\Services\Audit;

class StderrDeliveryAdapter
{
    /**
     * Write artefact content to STDERR.
     *
     * @param  array<string, string>  $artefacts  filename => content
     */
    public function deliver(array $artefacts): void
    {
        foreach ($artefacts as $content) {
            fwrite(STDERR, $content);
        }
    }
}
