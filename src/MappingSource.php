<?php

declare(strict_types=1);

namespace Mattmy\FillMap;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Captures input that can be mapped independently to one or more models. */
final readonly class MappingSource
{
    /**
     * Stores input captured by a mapper entry point.
     *
     * @internal Use AttributeMapper::from() or AttributeMapper::fromRequestInput().
     *
     * @param  array<string, mixed>  $input
     */
    public function __construct(private array $input) {}

    /**
     * Starts a mapping chain for a model instance or a class constructible without arguments.
     *
     * @template TModel of Model
     *
     * @param  TModel|class-string<TModel>  $model
     * @return AttributeMapper<TModel>
     *
     * @throws InvalidArgumentException
     */
    public function forModel(string|Model $model): AttributeMapper
    {
        return new AttributeMapper($this->input, $model);
    }
}
