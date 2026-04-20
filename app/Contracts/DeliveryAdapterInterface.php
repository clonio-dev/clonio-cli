<?php

declare(strict_types=1);

namespace App\Contracts;

interface DeliveryAdapterInterface
{
    /**
     * Deliver artefacts to the channel.
     *
     * @param  array<string, string>  $artefacts  filename => content pairs
     * @param  array<string, mixed>  $channelConfig  channel-specific configuration from clonio.json
     * @param  array<string, string>  $templateVars  path template variables (year, month, day, source, target, timestamp)
     */
    public function deliver(array $artefacts, array $channelConfig, array $templateVars): void;
}
