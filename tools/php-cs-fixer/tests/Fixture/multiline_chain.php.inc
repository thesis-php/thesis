<?php

declare(strict_types=1);

$a = MyFactory::create()
    ->y()
    ->z()
;
-----
<?php

declare(strict_types=1);

$a = MyFactory
    ::create()
    ->y()
    ->z()
;
