<?php

declare(strict_types=1);

namespace Thesis\Monorepo;

/**
 * Mutators of Infection that are not applied to a method of a package, or to one line of it: their mutants
 * are equivalent, no test can tell them from the code.
 *
 * @api
 */
final readonly class InfectionSkip
{
    /**
     * @param non-empty-list<non-empty-string> $mutators the names of the mutators in Infection: 'Coalesce', 'Plus'
     * @param non-empty-string $class a class of src/ of the package, `::class` of it
     * @param non-empty-string $method a method of the class
     * @param non-empty-string $reason why no test can kill the mutants, it goes to the generated tools/infection.json5
     * @param ?non-empty-string $code a line of the method as it is written, without the indentation: only its mutants
     *     are skipped. Infection matches a line by its text alone, in every class, so it must be distinctive
     */
    public function __construct(
        public array $mutators,
        public string $class,
        public string $method,
        public string $reason,
        public ?string $code = null,
    ) {}
}
