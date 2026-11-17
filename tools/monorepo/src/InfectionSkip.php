<?php

declare(strict_types=1);

namespace Thesis\Monorepo;

/**
 * A mutator of Infection that is not applied to a method of a package: its mutant is equivalent,
 * no test can tell it from the code.
 *
 * @api
 */
final readonly class InfectionSkip
{
    /**
     * @param non-empty-string $mutator the name of the mutator in Infection: 'Coalesce', 'IncrementInteger'
     * @param non-empty-string $class a class of src/ of the package, `::class` of it
     * @param non-empty-string $method a method of the class
     * @param non-empty-string $reason why no test can kill the mutant, it goes to the generated tools/infection.json5
     */
    public function __construct(
        public string $mutator,
        public string $class,
        public string $method,
        public string $reason,
    ) {}
}
