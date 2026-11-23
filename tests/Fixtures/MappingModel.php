<?php

declare(strict_types=1);

namespace Mattmy\FillMap\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class MappingModel extends Model
{
    public int $fillableReads = 0;

    /** @var array<string, mixed> */
    public array $received = [];

    /** @var list<string> */
    protected $fillable = ['end_time', 'id', 'uuid', 'user_id', 'preferences', 'active', 'count'];

    /** @var array<string, mixed> */
    protected $attributes = ['end_time' => 'default'];

    /** @var array<string, string> */
    protected $casts = ['count' => 'integer'];

    /** Records the current allow-list lookup, without reading a database. */
    public function getFillable(): array
    {
        $this->fillableReads++;

        return parent::getFillable();
    }

    /** Captures the exact input before Eloquent applies its own rules. */
    public function fill(array $attributes): self
    {
        $this->received = $attributes;

        return parent::fill($attributes);
    }
}
