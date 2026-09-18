<?php

declare(strict_types=1);

namespace Tests\Postgres;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Fluent;

/** SQLite permits forward FK references in the mirror; PG needs all tables first. */
final class DeferredForeignKeysGrammar extends PostgresGrammar
{
    /** @var list<string> */
    public array $foreignKeys = [];

    public function compileForeign(Blueprint $blueprint, Fluent $command)
    {
        $this->foreignKeys[] = parent::compileForeign($blueprint, $command);

        return [];
    }
}
