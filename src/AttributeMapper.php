<?php

declare(strict_types=1);

namespace Mattmy\FillMap;

use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Ramsey\Uuid\UuidInterface;
use ReflectionClass;
use ReflectionReference;

/**
 * Maps one source to a model's declared fillable attributes.
 *
 * @template TModel of Model
 */
final class AttributeMapper
{
    private ?InputKeyStyle $style = null;

    /** @var array<string, string>|null */
    private ?array $aliases = null;

    /** @var array<string, mixed> */
    private array $additions = [];

    /** @var array<string, true>|null */
    private ?array $included = null;

    /** @var array<string, true> */
    private array $excluded = [];

    /** @var array<string, true>|null */
    private ?array $blankScope = [];

    /**
     * Starts a mapping chain for a validated source and target model.
     *
     * @internal Use MappingSource::forModel() to start a chain.
     *
     * @param  array<string, mixed>  $input
     * @param  TModel|class-string<TModel>  $model
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        private readonly array $input,
        private readonly Model|string $model,
    ) {
        if (\is_string($model)) {
            self::validateModelClass($model);
        }
    }

    /**
     * Captures prepared data or the current Form Request validation result.
     *
     * @param  array<array-key, mixed>|FormRequest  $input
     *
     * @throws InvalidArgumentException
     */
    public static function from(array|FormRequest $input): MappingSource
    {
        $attributes = $input instanceof FormRequest ? $input->validated() : $input;

        return new MappingSource(self::validateInputKeys($attributes, 'from()'));
    }

    /**
     * Captures only the named fields from the current parsed request body.
     *
     * @param  list<non-empty-string>  $keys
     *
     * @throws InvalidArgumentException
     */
    public static function fromRequestInput(Request $request, array $keys): MappingSource
    {
        $selected = self::normalizeFieldSet($keys, 'fromRequestInput()');
        if ($selected === []) {
            return new MappingSource([]);
        }

        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        $input = [];
        $activeReferences = [];

        foreach (\array_keys($selected) as $key) {
            if (\array_key_exists($key, $body)) {
                $input[$key] = self::copyBodyValue($body[$key], $activeReferences);
            }
        }

        return new MappingSource($input);
    }

    /**
     * Selects the rule used to derive source keys from model attributes.
     *
     * @return self<TModel>
     */
    public function inputKeys(InputKeyStyle $style): self
    {
        $branch = clone $this;
        $branch->style = $style;

        return $branch;
    }

    /**
     * Sets one complete source-key to model-attribute mapping.
     *
     * @param  array<array-key, mixed>  $aliases
     * @return self<TModel>
     *
     * @throws InvalidArgumentException
     */
    public function aliases(array $aliases): self
    {
        if ($this->aliases !== null) {
            throw new InvalidArgumentException('aliases() may only be configured once.');
        }

        $targets = [];
        foreach ($aliases as $source => $attribute) {
            $source = self::validateFieldName($source, 'aliases()');
            $attribute = self::validateFieldName($attribute, 'aliases()');
            if (isset($targets[$attribute])) {
                throw new InvalidArgumentException('aliases() maps more than one source key to the same attribute.');
            }

            $targets[$attribute] = $source;
        }

        $branch = clone $this;
        $branch->aliases = $targets;

        return $branch;
    }

    /**
     * Adds attributes explicitly authorized by the caller, including non-fillable fields.
     *
     * @param  array<array-key, mixed>  $attributes
     * @return self<TModel>
     *
     * @throws InvalidArgumentException
     */
    public function merge(array $attributes): self
    {
        return $this->withAdditions(self::validateInputKeys($attributes, 'merge()'));
    }

    /**
     * Adds a positive integer ID or the default sentinel zero.
     *
     * @param  non-empty-string  $attribute
     * @return self<TModel>
     *
     * @throws InvalidArgumentException
     */
    public function setId(mixed $value = null, mixed $attribute = 'id'): self
    {
        $name = self::validateFieldName($attribute, 'setId()');
        if ($value !== null && (! \is_int($value) || $value <= 0)) {
            throw new InvalidArgumentException('setId() requires a positive integer or null.');
        }

        return $this->withAdditions([$name => $value ?? 0]);
    }

    /**
     * Adds a UUID as a string, generating it once when the value is omitted.
     *
     * @param  non-empty-string  $attribute
     * @return self<TModel>
     *
     * @throws InvalidArgumentException
     */
    public function setUuid(mixed $value = null, mixed $attribute = 'uuid'): self
    {
        $name = self::validateFieldName($attribute, 'setUuid()');
        if ($value === null) {
            $value = Str::uuid();
        }

        if ($value instanceof UuidInterface) {
            $value = $value->toString();
        } elseif (! \is_string($value) || ! Str::isUuid($value)) {
            throw new InvalidArgumentException('setUuid() requires a valid UUID or null.');
        }

        return $this->withAdditions([$name => $value]);
    }

    /**
     * Keeps the intersection of this and all preceding only() selections.
     *
     * @param  non-empty-string|list<non-empty-string>  $attributes
     * @return self<TModel>
     *
     * @throws InvalidArgumentException
     */
    public function only(mixed $attributes): self
    {
        $fields = self::normalizeFieldSet($attributes, 'only()');
        $branch = clone $this;
        $branch->included = $this->included === null ? $fields : \array_intersect_key($this->included, $fields);

        return $branch;
    }

    /**
     * Adds attributes to the exclusion set.
     *
     * @param  non-empty-string|list<non-empty-string>  $attributes
     * @return self<TModel>
     *
     * @throws InvalidArgumentException
     */
    public function except(mixed $attributes): self
    {
        $branch = clone $this;
        $branch->excluded += self::normalizeFieldSet($attributes, 'except()');

        return $branch;
    }

    /**
     * Drops blank values in the given scope, or in every field when omitted.
     *
     * @param  non-empty-string|list<non-empty-string>|null  $attributes
     * @return self<TModel>
     *
     * @throws InvalidArgumentException
     */
    public function withoutBlank(mixed $attributes = null): self
    {
        $branch = clone $this;
        $branch->blankScope = $attributes === null ? null : self::normalizeFieldSet($attributes, 'withoutBlank()');

        return $branch;
    }

    /**
     * Returns mapped attributes in fillable order, followed by additions outside the list.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function toArray(): array
    {
        [$attributes, $outsideFillable] = $this->map($this->resolveTarget());

        return $attributes + $outsideFillable;
    }

    /**
     * Returns the same raw attributes as a collection.
     *
     * @return Collection<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function collect(): Collection
    {
        return new Collection($this->toArray());
    }

    /**
     * Fills the target and writes explicit additions outside the fillable list.
     *
     * @return TModel
     *
     * @throws InvalidArgumentException
     * @throws MassAssignmentException
     */
    public function toModel(): Model
    {
        $model = $this->resolveTarget();
        [$attributes, $outsideFillable] = $this->map($model);
        $model->fill($attributes);
        foreach ($outsideFillable as $name => $value) {
            $model->setAttribute($name, $value);
        }

        return $model;
    }

    /**
     * Applies explicit additions to a new branch in call order.
     *
     * @param  array<string, mixed>  $attributes
     * @return self<TModel>
     */
    private function withAdditions(array $attributes): self
    {
        $branch = clone $this;
        $branch->additions = \array_replace($this->additions, $attributes);

        return $branch;
    }

    /**
     * Resolves the target only when producing a result.
     *
     * @return TModel
     */
    private function resolveTarget(): Model
    {
        return \is_string($this->model) ? new ($this->model) : $this->model;
    }

    /**
     * Returns the attributes for fill() and additions for direct assignment.
     *
     * @return array{array<string, mixed>, array<string, mixed>}
     *
     * @throws InvalidArgumentException
     */
    private function map(Model $model): array
    {
        $fillable = [];
        foreach ($model->getFillable() as $name) {
            $fillable[self::validateFieldName($name, 'getFillable()')] = true;
        }

        $aliases = $this->aliases ?? [];
        if (\array_diff_key($aliases, $fillable) !== []) {
            throw new InvalidArgumentException('Alias targets must be declared fillable.');
        }

        $style = $this->style ?? self::defaultInputKeyStyle();
        // Exact naming without aliases reuses the already unique fillable keys.
        $sourceKeysCanCollide = $style !== InputKeyStyle::Exact || $aliases !== [];
        $outsideFillable = \array_diff_key($this->additions, $fillable);
        $sourceKeys = [];
        $attributes = [];
        foreach (\array_keys($fillable) as $attribute) {
            $source = $attribute;
            if ($sourceKeysCanCollide) {
                $source = $aliases[$attribute] ?? self::deriveSourceKey($attribute, $style);
                if (isset($sourceKeys[$source])) {
                    throw new InvalidArgumentException('Different fillable attributes map to the same source key.');
                }

                $sourceKeys[$source] = true;
            }

            if (\array_key_exists($attribute, $this->additions)) {
                $attributes[$attribute] = $this->additions[$attribute];
            } elseif (\array_key_exists($source, $this->input)) {
                $attributes[$attribute] = $this->input[$source];
            }
        }

        if ($this->included === null && $this->excluded === [] && $this->blankScope === []) {
            return [$attributes, $outsideFillable];
        }

        $attributes += $outsideFillable;

        if ($this->included !== null) {
            $attributes = \array_intersect_key($attributes, $this->included);
        }

        if ($this->excluded !== []) {
            $attributes = \array_diff_key($attributes, $this->excluded);
        }

        if ($this->blankScope !== []) {
            foreach ($attributes as $attribute => $value) {
                if (($this->blankScope === null || isset($this->blankScope[$attribute])) && blank($value)) {
                    unset($attributes[$attribute]);
                }
            }
        }

        if ($outsideFillable === []) {
            return [$attributes, []];
        }

        return [\array_diff_key($attributes, $outsideFillable), \array_intersect_key($attributes, $outsideFillable)];
    }

    /**
     * Checks an incoming model class without instantiating it.
     *
     * @throws InvalidArgumentException
     */
    private static function validateModelClass(string $model): void
    {
        if (! \is_subclass_of($model, Model::class)) {
            throw new InvalidArgumentException('forModel() requires an Eloquent model class.');
        }

        $reflection = new ReflectionClass($model);
        if (! $reflection->isInstantiable() || ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
            throw new InvalidArgumentException('forModel() requires a model constructible without arguments.');
        }
    }

    /**
     * Resolves the configured naming rule, including when used outside a booted application.
     *
     * @throws InvalidArgumentException
     */
    private static function defaultInputKeyStyle(): InputKeyStyle
    {
        if (! app()->bound('config')) {
            return InputKeyStyle::Exact;
        }

        $style = config('attribute-mapper.input_key_style', InputKeyStyle::Exact);
        if ($style instanceof InputKeyStyle) {
            return $style;
        }

        if (\is_string($style)) {
            foreach (InputKeyStyle::cases() as $case) {
                if (\strcasecmp($style, $case->name) === 0) {
                    return $case;
                }
            }
        }

        throw new InvalidArgumentException('attribute-mapper.input_key_style must be an InputKeyStyle case or its name.');
    }

    /** Derives a literal source key using the selected naming rule. */
    private static function deriveSourceKey(string $attribute, InputKeyStyle $style): string
    {
        return match ($style) {
            InputKeyStyle::Exact => $attribute,
            InputKeyStyle::Camel => Str::camel($attribute),
            InputKeyStyle::Snake => Str::snake($attribute),
            InputKeyStyle::Pascal => Str::studly($attribute),
            InputKeyStyle::Kebab => Str::kebab(Str::studly($attribute)),
        };
    }

    /**
     * Validates top-level keys while preserving the caller's original values.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private static function validateInputKeys(array $input, string $method): array
    {
        foreach (\array_keys($input) as $key) {
            self::validateFieldName($key, $method);
        }

        /** @var array<string, mixed> $input All keys have passed the string-key check. */
        return $input;
    }

    /**
     * Converts a single name or a consecutive list of names to a lookup set.
     *
     * @return array<string, true>
     *
     * @throws InvalidArgumentException
     */
    private static function normalizeFieldSet(mixed $attributes, string $method): array
    {
        if (! \is_array($attributes)) {
            $attributes = [$attributes];
        }

        if (! \array_is_list($attributes)) {
            throw new InvalidArgumentException("{$method} requires a field name or a list of field names.");
        }

        $fields = [];
        foreach ($attributes as $attribute) {
            $fields[self::validateFieldName($attribute, $method)] = true;
        }

        return $fields;
    }

    /**
     * Accepts only nonempty strings that PHP preserves as string array keys.
     *
     * @return non-empty-string
     *
     * @throws InvalidArgumentException
     */
    private static function validateFieldName(mixed $name, string $method): string
    {
        // PHP converts decimal names in canonical integer form to integer array keys.
        if (! \is_string($name) || $name === '' || (string) (int) $name === $name) {
            throw new InvalidArgumentException("{$method} requires a nonempty field name that remains a string array key.");
        }

        return $name;
    }

    /**
     * Checks selected body structure while copying it without PHP references.
     *
     * @param  array<string, true>  $activeReferences
     * @return ($value is array ? array<array-key, mixed> : bool|float|int|string|null)
     *
     * @throws InvalidArgumentException
     */
    private static function copyBodyValue(mixed $value, array &$activeReferences): array|bool|float|int|string|null
    {
        if ($value === null || \is_scalar($value)) {
            return $value;
        }

        if (! \is_array($value)) {
            throw new InvalidArgumentException('fromRequestInput() selected an unsupported value type.');
        }

        if (! \array_is_list($value)) {
            foreach (\array_keys($value) as $key) {
                if (\is_string($key) && (string) (int) $key === $key) {
                    // Copy by position so PHP keeps keys that assignment would coerce.
                    $items = self::copyBodyValue(\array_values($value), $activeReferences);
                    $index = 0;

                    return \array_map(static function () use ($items, &$index): mixed {
                        return $items[$index++];
                    }, $value);
                }
            }
        }

        $copy = [];
        foreach ($value as $key => $item) {
            $id = \is_array($item) ? ReflectionReference::fromArrayElement($value, $key)?->getId() : null;
            if ($id !== null) {
                if (isset($activeReferences[$id])) {
                    throw new InvalidArgumentException('fromRequestInput() selected a circular array.');
                }

                $activeReferences[$id] = true;
            }

            $copy[$key] = self::copyBodyValue($item, $activeReferences);
            if ($id !== null) {
                unset($activeReferences[$id]);
            }
        }

        return $copy;
    }
}
