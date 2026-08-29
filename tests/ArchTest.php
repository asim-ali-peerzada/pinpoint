<?php

arch('src does not use debug or dump helpers')
    ->expect('AsimAli\Pinpoint')
    ->not->toUse(['dd', 'dump', 'ray', 'var_dump', 'print_r']);

arch('src is clean of environment access outside config')
    ->expect('AsimAli\Pinpoint')
    ->not->toUse('env');
