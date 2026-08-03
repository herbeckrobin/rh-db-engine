<?php

declare(strict_types=1);

namespace RhDbEngine;

/**
 * Signal, dass dieser Dump sich nicht über Schattentabellen einspielen lässt.
 *
 * Wird erst mitten in der SQL-Phase erkannt, weil die Tabellennamen nicht im Manifest
 * stehen, sondern in den CREATE-Anweisungen. Der Import verwirft dann seine Schattentabellen
 * und beginnt die SQL-Phase im direkten Modus von vorn. Das kostet einen Neuanlauf und
 * passiert nur bei sehr langen Tabellennamen, ist aber allemal besser als ein Abbruch.
 */
final class SwapUnavailable extends \RuntimeException
{
}
