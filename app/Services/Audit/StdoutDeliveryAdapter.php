<?php

declare(strict_types=1);

namespace App\Services\Audit;

class StdoutDeliveryAdapter
{
    /**
     * Write artefact content to STDOUT.
     *
     * @param  array<string, string>  $artefacts  filename => content
     */
    public function deliver(array $artefacts): void
    {
        foreach ($artefacts as $content) {
            fwrite(STDOUT, $content);
        }
    }
}
