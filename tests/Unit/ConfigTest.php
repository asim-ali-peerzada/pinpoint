<?php

test('config file is loadable without a booted application', function () {
    // The published config must not call app()->environment(): real apps load
    // config via LoadConfiguration, which runs before DetectEnvironment binds
    // the 'env' container entry, so app() crashes with "Target class [env]
    // does not exist". Regression test: the config must only use env().
    $config = require __DIR__.'/../../config/pinpoint.php';

    expect($config)->toHaveKey('enabled');
    expect(is_bool($config['enabled']))->toBeTrue();
});
