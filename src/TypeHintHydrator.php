<?php

namespace Xact\TypeHintHydrator;

use Doctrine\ORM\EntityManagerInterface;
use JMS\Serializer\SerializerInterface;
use Laminas\Hydrator\ReflectionHydrator;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class TypeHintHydrator
{
    protected const JSON_FORMAT = 'json';

    /**
     * @var \Symfony\Component\Validator\Validator\ValidatorInterface
     */
    protected $validator;

    /**
     * @var \Doctrine\ORM\EntityManagerInterface
     */
    protected $em;

    /**
     * @var \JMS\Serializer\SerializerInterface
     */
    protected $serializer;

    /**
     * @var \Xact\TypeHintHydrator\ClassMetadata|null
     */
    protected $classMetadata = null;

    /**
     * @var \Symfony\Component\Validator\ConstraintViolationListInterface
     */
    protected $errors;

    /**
     * @var object|null
     */
    protected $currentTarget;

    /**
     * @var \ReflectionClass|null
     */
    protected $reflectionTarget;

    public function __construct(ValidatorInterface $validator, EntityManagerInterface $em, SerializerInterface $serializer)
    {
        $this->validator = $validator;
        $this->em = $em;
        $this->serializer = $serializer;
    }

    /**
     * @param mixed[] $values
     * @param Constraint|Constraint[] $constraints  The constraint(s) to validate against
     * @param string|GroupSequence|(string|GroupSequence)[]|null $groups  The validation groups to validate. If none is given, "Default" is assumed
     *
     * @throws \Laminas\Hydrator\Exception\InvalidArgumentException
     */
    public function hydrateObject(array $values, object $target, bool $validate = true, $constraints = null, $groups = null): object
    {
        $this->currentTarget = $target;
        $this->reflectionTarget = new ReflectionClass($target);
        $this->classMetadata = (new AnnotationHandler())->loadMetadataForClass($this->reflectionTarget);

        if ($this->classMetadata->exclude) {
            return $target;
        }

        /**
         * Build a list of strategies for each property in the target object.
         */
        $strategies = [];
        $properties = $this->reflectionTarget->getProperties();
        foreach ($properties as $property) {
            $propertyName = $property->getName();
            $propertyMetadata = $this->classMetadata->getPropertyMetadata($propertyName);
            if ($propertyMetadata === null || !$propertyMetadata->exclude) {
                $strategies[$propertyName] = new PropertyTypeHintStrategy($property, $this->em, $this->reflectionTarget, $this, $target);
            }
        }

        $hydrator = new ReflectionHydrator();
        foreach ($strategies as $key => $strategy) {
            $hydrator->addStrategy($key, $strategy);
        }

        $hydratedObject = $hydrator->hydrate($values, $target);

        $this->errors = $validate ? $this->validator->validate($hydratedObject, $constraints, $groups) : [];

        return $hydratedObject;
    }

    /**
     * @param Constraint|Constraint[] $constraints  The constraint(s) to validate against
     * @param string|GroupSequence|(string|GroupSequence)[]|null $groups  The validation groups to validate. If none is given, "Default" is assumed
     */
    public function handleRequest(Request $request, object $target, bool $validate = true, $constraints = null, $groups = null): object
    {
        return $this->hydrateObject($request->request->all(), $target, $validate, $constraints, $groups);
    }

    public function isValid(): bool
    {
        return (count($this->errors) === 0);
    }

    public function getErrors(): ConstraintViolationListInterface
    {
        return $this->errors;
    }

    public function getJsonErrors(): string
    {
        return $this->serializer->serialize($this->errors, self::JSON_FORMAT);
    }

    public function getEntityManager(): EntityManagerInterface
    {
        return $this->em;
    }

    public function getClassMetadata(): ?ClassMetadata
    {
        return $this->classMetadata;
    }

    /**
     * @return mixed
     *
     * @throws \ReflectionException
     */
    public function getOriginalValue(string $propertyName)
    {
        if ($this->currentTarget && $this->reflectionTarget && $this->reflectionTarget->hasProperty($propertyName)) {
            $property = $this->reflectionTarget->getProperty($propertyName);
            $property->setAccessible(true);
            return $property->getValue($this->currentTarget);
        }

        return null;
    }
}