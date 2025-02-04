<?php

namespace Based\Fluent;

use Based\Fluent\Relations\AbstractRelation;
use Based\Fluent\Relations\OneRelation;
use Based\Fluent\Relations\Relation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;

/** @mixin \Based\Fluent\HasFluentBindings */
trait HasRelations
{
    protected static Collection $fluentRelations;

    protected static function bootHasRelations()
    {
        static::getFluentRelations()
            ->reject(fn (ReflectionProperty $property) => method_exists(static::class, $property->getName()))
            ->each(function (ReflectionProperty $property) {
                /** @var ReflectionAttribute $attribute */
                $attribute = collect($property->getAttributes())
                    ->first(function (ReflectionAttribute $attribute) {
                        return $attribute->getName() != Relation::class
                            && is_subclass_of($attribute->getName(), AbstractRelation::class);
                    });

                if (! $attribute) {
                    return;
                }

                $methodName = Str::camel(class_basename($attribute->getName()));
                $arguments = $attribute->getArguments();

                if (is_subclass_of($attribute->getName(), OneRelation::class)) {
                    if (! count($arguments)) {
                        $arguments[] = Str::snake($property->getName()) . '_' . self::getModel()->getKeyName();
                    }

                    array_unshift($arguments, $property->getType()->getName());
                }

                self::resolveRelationUsing($property->getName(), function (self $model) use ($methodName, $arguments) {
                    return $model->{$methodName}(...$arguments);
                });
            });
    }

    /**
     * Get relations defined as public properties.
     *
     * @return \Illuminate\Support\Collection<ReflectionProperty>|ReflectionProperty[]
     */
    protected static function getFluentRelations(): Collection
    {
        if (isset(static::$fluentRelations)) {
            return static::$fluentRelations;
        }

        $reflection = new ReflectionClass(static::class);

        return static::$fluentRelations = collect($reflection->getProperties(ReflectionProperty::IS_PUBLIC))
            ->filter(fn (ReflectionProperty $property) => $property->class === self::class)
            ->filter(fn (ReflectionProperty $property) => $property->hasType())
            ->filter(function (ReflectionProperty $property) {
                return is_subclass_of($property->getType()->getName(), Model::class)
                    || !empty($property->getAttributes(AbstractRelation::class, ReflectionAttribute::IS_INSTANCEOF));
            });
    }

    /**
     * Get fluently defined relation.
     *
     * @param  string  $key
     * @return null|\ReflectionProperty
     */
    public static function getFluentRelation(string $key): ?ReflectionProperty
    {
        return static::getFluentRelations()
            ->filter(fn (ReflectionProperty $property) => $property->getName() === $key)
            ->first();
    }

    /**
     * Overload the method to populate public property
     * Set the given relationship on the model.
     *
     * @param  string  $relation
     * @param  mixed  $value
     * @return $this
     */
    public function setRelation($relation, $value)
    {
        $this->relations[$relation] = $value;

        $fluentRelation = static::getFluentRelation($relation);

        if (! $fluentRelation) {
            return $this;
        }

        $fluentRelationType = $fluentRelation->getType();

        if ($fluentRelationType->allowsNull() || ! is_null($value)) {
            $this->{$relation} = is_null($value) || $fluentRelationType->getName() != Collection::class
                ? $value
                : collect($value);
        }

        return $this;
    }

    /**
     * Overload the method to unset public property
     * Unset a loaded relationship.
     *
     * @param  string  $relation
     * @return $this
     */
    public function unsetRelation($relation)
    {
        unset($this->relations[$relation]);

        if (static::getFluentRelation($relation)) {
            unset($this->{$relation});
        }

        return $this;
    }

    /**
     * Overload the method to populate public properties
     * Set the entire relations array on the model.
     *
     * @param  array  $relations
     * @return $this
     */
    public function setRelations(array $relations)
    {
        // unset any relation key that should no longer be present after this call
        foreach (array_diff(array_keys($relations), array_keys($this->relations)) as $relation) {
            $this->unsetRelation($relation);
        }

        foreach ($relations as $relation => $value) {
            $this->setRelation($relation, $value);
        }

        return $this;
    }

    /**
     * Overload the method to unset public properties
     * Unset all the loaded relations for the instance.
     *
     * @return $this
     */
    public function unsetRelations()
    {
        foreach (array_keys($this->relations) as $relation) {
            if (static::getFluentRelation($relation)) {
                unset($this->{$relation});
            }
        }

        $this->relations = [];

        return $this;
    }
}
