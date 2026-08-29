<?php

use Illuminate\Support\Facades\File;

test('published migrations get a current timestamp, not a hardcoded one', function () {
    $target = database_path('migrations');

    File::ensureDirectoryExists($target);
    collect(File::glob($target.'/*pinpoint*'))->each(fn ($f) => File::delete($f));

    $this->artisan('vendor:publish --tag=pinpoint-migrations')->assertSuccessful();

    $published = collect(File::glob($target.'/*pinpoint*'))->map(fn ($f) => basename($f))->sort()->values();

    expect($published)->toHaveCount(4);

    foreach ($published as $name) {
        // Timestamp prefix must be "now", not a hardcoded 2026_01_01.
        expect(preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_create_pinpoint_/', $name))->toBe(1);
        expect(str_starts_with($name, '2026_01_01'))->toBeFalse();
    }

    collect(File::glob($target.'/*pinpoint*'))->each(fn ($f) => File::delete($f));
});
