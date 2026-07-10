<?php

namespace Z77\Persistence\Cleaning;

use Z77\Persistence\Cleaning\Filters\BoolFilter;
use Z77\Persistence\Cleaning\Filters\EmailFilter;
use Z77\Persistence\Cleaning\Filters\FilterInterface;
use Z77\Persistence\Cleaning\Filters\IdentFilter;
use Z77\Persistence\Cleaning\Filters\IntFilter;
use Z77\Persistence\Cleaning\Filters\ListFilter;
use Z77\Persistence\Cleaning\Filters\NullableFilter;
use Z77\Persistence\Cleaning\Filters\SlugFilter;
use Z77\Persistence\Cleaning\Filters\TextFilter;

final class FilterRegistry
{
    /** @var array<string, callable(): FilterInterface> */
    private array $factories = [];

    /** @var array<string, FilterInterface> */
    private array $instances = [];

    public function __construct()
    {
        $this->factories = [
            'text'  => fn() => new TextFilter(),
            'slug'  => fn() => new SlugFilter(),
            'ident' => fn() => new IdentFilter(),
            'email' => fn() => new EmailFilter(),
            'int'   => fn() => new IntFilter(),
            'bool'  => fn() => new BoolFilter(),
        ];
    }

    public function register(string $name, callable $factory): void
    {
        $this->factories[$name] = $factory;
        unset($this->instances[$name]);
    }

    public function compile(array $chain): FilterInterface
    {
        if ($chain === []) {
            throw new \InvalidArgumentException('Filter chain must not be empty');
        }
        $head = $chain[0];
        $rest = array_slice($chain, 1);

        if ($head === 'list') {
            if ($rest === []) {
                throw new \InvalidArgumentException('list filter requires an inner filter (e.g. #[Clean(\'list\', \'slug\')])');
            }
            return new ListFilter($this->compile($rest));
        }
        if ($head === 'nullable') {
            if ($rest === []) {
                throw new \InvalidArgumentException('nullable filter requires an inner filter (e.g. #[Clean(\'nullable\', \'slug\')])');
            }
            return new NullableFilter($this->compile($rest));
        }

        if ($rest !== []) {
            throw new \InvalidArgumentException("Filter '$head' does not accept inner filters");
        }
        return $this->get($head);
    }

    private function get(string $name): FilterInterface
    {
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException("Unknown filter: $name");
        }
        return $this->instances[$name] ??= ($this->factories[$name])();
    }
}
