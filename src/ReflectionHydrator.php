<?php

declare(strict_types=1);

namespace Xact\TypeHintHydrator;

use Laminas\Hydrator\AbstractHydrator;
use ReflectionClass;
use ReflectionProperty;

/**
 * TODO: Remove this class when the base hydrator issue 114 is resolved. https://github.com/laminas/laminas-hydrator/issues/114
 */
class ReflectionHydrator extends AbstractHydrator
{
    /**
     * Simple in-memory array cache of ReflectionProperties used.
     *
     * @var ReflectionProperty[][]
     */
    protected static $reflProperties = [];

    /**
     * Extract values from an object
     *
     * {@inheritDoc}
     */
    public function extract(object $object, bool $includeParentProperties = false): array
    {
        $result = [];
        foreach (static::getReflProperties($object, $includeParentProperties) as $property) {
            $propertyName = $this->extractName($property->getName(), $object);
            if (! $this->getCompositeFilter()->filter($propertyName)) {
                continue;
            }

            $value                 = $property->getValue($object);
            $result[$propertyName] = $this->extractValue($propertyName, $value, $object);
        }

        return $result;
    }

    /**
     * Hydrate $object with the provided $data.
     *
     * {@inheritDoc}
     */
    public function hydrate(array $data, object $object, bool $includeParentProperties = false)
    {
        $reflProperties = static::getReflProperties($object, $includeParentProperties);
        foreach ($data as $key => $value) {
            $name = $this->hydrateName($key, $data);
            if (isset($reflProperties[$name])) {
                $reflProperties[$name]->setValue($object, $this->hydrateValue($name, $value, $data));
            }
        }
        return $object;
    }

    /**
     * Get a reflection properties for an object.
     * If $includeParentProperties is true, return return all parent properties as well.
     *
     * @return ReflectionProperty[]
     */
    protected static function getReflProperties(object $input, bool $includeParentProperties): array
    {
        $class = get_class($input);

        if (isset(static::$reflProperties[$class])) {
            return static::$reflProperties[$class];
        }

        static::$reflProperties[$class] = [];
        $reflClass = new ReflectionClass($class);

        do {
            foreach ($reflClass->getProperties() as $property) {
                $property->setAccessible(true);
                static::$reflProperties[$class][$property->getName()] = $property;
            }
        } while ($includeParentProperties === true && ($reflClass = $reflClass->getParentClass()) !== false);

        return static::$reflProperties[$class];
    }
}
