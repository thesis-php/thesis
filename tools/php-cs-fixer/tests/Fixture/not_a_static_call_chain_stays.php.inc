<?php

declare(strict_types=1);

$a = Status::Active->value;
$b = Foo::class;
$c = Foo::$bar;
$d = $x
    ->b::c()
    ->d()
;
$e = new Foo()::create()
    ->y()
;
