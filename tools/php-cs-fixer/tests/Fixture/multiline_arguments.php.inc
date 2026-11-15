<?php

declare(strict_types=1);

function f(): Foo
{
    return Foo::create(
        a: 1,
    )
        ->y()
    ;
}
-----
<?php

declare(strict_types=1);

function f(): Foo
{
    return Foo
        ::create(
            a: 1,
        )
        ->y()
    ;
}
