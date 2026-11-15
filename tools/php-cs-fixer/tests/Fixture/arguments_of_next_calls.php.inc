<?php

declare(strict_types=1);

return RectorConfig::configure()
    ->withPaths([
        'a',
        'b',
    ])
    ->setFinder(
        Finder::create()
            ->append([
                'c',
            ]),
    )
;
-----
<?php

declare(strict_types=1);

return RectorConfig
    ::configure()
    ->withPaths([
        'a',
        'b',
    ])
    ->setFinder(
        Finder
            ::create()
            ->append([
                'c',
            ]),
    )
;
