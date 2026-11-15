<?php

declare(strict_types=1);

$a = self::create()['x']
    ?->items
    ->first()
    ::other()
;
$b = static::create()
    ->y()
;
-----
<?php

declare(strict_types=1);

$a = self
    ::create()['x']
    ?->items
    ->first()
    ::other()
;
$b = static
    ::create()
    ->y()
;
