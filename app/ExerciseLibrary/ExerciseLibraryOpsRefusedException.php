<?php

namespace App\ExerciseLibrary;

use RuntimeException;

/**
 * An ops file the exercise library refuses to apply, with every reason it was refused.
 */
class ExerciseLibraryOpsRefusedException extends RuntimeException
{
    /**
     * @param  list<string>  $refusals
     */
    public function __construct(public readonly array $refusals)
    {
        parent::__construct('The ops were refused: '.implode(' ', $refusals));
    }
}
