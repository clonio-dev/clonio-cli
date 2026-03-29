<?php

it('displays about info for clonio', function (): void {
    $this->artisan('about')->assertExitCode(0);
});
