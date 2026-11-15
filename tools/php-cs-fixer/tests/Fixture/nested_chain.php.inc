<?php

declare(strict_types=1);

$a = new Config()
    ->setFinder(
        Finder::create()
            ->in('x'),
    )
    ->setRules([])
;
-----
<?php

declare(strict_types=1);

$a = new Config()
    ->setFinder(
        Finder
            ::create()
            ->in('x'),
    )
    ->setRules([])
;
