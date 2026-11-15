<?php

declare(strict_types=1);

$a = Foo
::create()
        ->y()
  ->z()
;
-----
<?php

declare(strict_types=1);

$a = Foo
    ::create()
    ->y()
    ->z()
;
