<?php

declare(strict_types=1);

use App\Services\Schema\SchemaInspector;

// SchemaInspector requires a live database connection which is complex to mock
// at the DB layer. Integration-level testing is preferred for this service.
// Placeholder to satisfy directory structure and future test additions.

it('schema inspector is a placeholder for integration tests', function (): void {
    expect(class_exists(SchemaInspector::class))->toBeTrue();
});
