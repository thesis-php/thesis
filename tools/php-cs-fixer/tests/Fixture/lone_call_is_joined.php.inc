<?php

declare(strict_types=1);

$a = Foo
    ::create();
$b = Foo
    ::BAR;
-----
<?php

declare(strict_types=1);

$a = Foo::create();
$b = Foo::BAR;
