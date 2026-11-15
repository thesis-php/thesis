<?php

declare(strict_types=1);

$a = Foo::create() // why
    ->y()
;
-----
<?php

declare(strict_types=1);

$a = Foo
    ::create() // why
    ->y()
;
