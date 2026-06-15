<?php

declare(strict_types=1);

use App\Services\Audit\StdoutDeliveryAdapter;

it('writes every artefact content to standard output', function (): void {
    $adapter = new StdoutDeliveryAdapter;

    ob_start();
    $adapter->deliver(
        artefacts: ['a.html' => '<h1>one</h1>', 'b.sig' => 'sha256:two'],
        channelConfig: [],
        templateVars: [],
    );
    $output = ob_get_clean();

    expect($output)->toBe('<h1>one</h1>sha256:two');
});

it('writes nothing when there are no artefacts', function (): void {
    $adapter = new StdoutDeliveryAdapter;

    ob_start();
    $adapter->deliver(artefacts: [], channelConfig: [], templateVars: []);
    $output = ob_get_clean();

    expect($output)->toBe('');
});
