<?php

declare(strict_types=1);

$a = Foo :: bar();
$b = Foo:: bar();
$c = Foo::
    bar();
-----
<?php

declare(strict_types=1);

$a = Foo::bar();
$b = Foo::bar();
$c = Foo::bar();
