<?php
/*
 * This file is part of the Sidus/EAVModelBundle package.
 *
 * Copyright (c) 2015-2019 Vincent Chalnot
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sidus\EAVModelBundle\Serializer\Normalizer;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Sidus\EAVModelBundle\Entity\DataInterface;
use Sidus\EAVModelBundle\Exception\EAVExceptionInterface;
use Sidus\EAVModelBundle\Exception\InvalidValueDataException;
use Sidus\EAVModelBundle\Model\AttributeInterface;
use Sidus\EAVModelBundle\Serializer\AttributesHandlerTrait;
use Sidus\EAVModelBundle\Serializer\ByReferenceHandler;
use Sidus\EAVModelBundle\Serializer\CircularReferenceHandler;
use Sidus\EAVModelBundle\Serializer\MaxDepthHandler;
use Symfony\Component\PropertyAccess\Exception\ExceptionInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\PropertyInfo\PropertyTypeExtractorInterface;
use Symfony\Component\Serializer\Exception\CircularReferenceException;
use Symfony\Component\Serializer\Exception\RuntimeException;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Exception\InvalidArgumentException;
use function count;
use function in_array;
use function is_array;

/**
 * Standard normalizer for EAV Data
 *
 * @author Vincent Chalnot <vincent@sidus.fr>
 */
class EAVDataNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;
    use AttributesHandlerTrait;

    const GROUPS = 'groups';
    const SERIALIZER_OPTIONS = 'serializer';
    const EXPOSE_KEY = 'expose';
    const MINIMAL_KEY = 'minimal';
    const CONTEXT_KEY = 'context';

    /** @var ClassMetadataFactoryInterface */
    protected $classMetadataFactory;

    /** @var NameConverterInterface */
    protected $nameConverter;

    /** @var PropertyAccessorInterface */
    protected $propertyAccessor;

    /** @var PropertyTypeExtractorInterface */
    protected $propertyTypeExtractor;

    /** @var MaxDepthHandler */
    protected $maxDepthHandler;

    /** @var CircularReferenceHandler */
    protected $circularReferenceHandler;

    /** @var ByReferenceHandler */
    protected $byReferenceHandler;

    /**
     * @param ClassMetadataFactoryInterface|null  $classMetadataFactory
     * @param NameConverterInterface|null         $nameConverter
     * @param PropertyAccessorInterface|null      $propertyAccessor
     * @param PropertyTypeExtractorInterface|null $propertyTypeExtractor
     * @param MaxDepthHandler                     $maxDepthHandler
     * @param CircularReferenceHandler            $circularReferenceHandler
     * @param ByReferenceHandler                  $byReferenceHandler
     */
    public function __construct(
        ClassMetadataFactoryInterface $classMetadataFactory = null,
        NameConverterInterface $nameConverter = null,
        PropertyAccessorInterface $propertyAccessor = null,
        PropertyTypeExtractorInterface $propertyTypeExtractor = null,
        MaxDepthHandler $maxDepthHandler,
        CircularReferenceHandler $circularReferenceHandler,
        ByReferenceHandler $byReferenceHandler
    ) {
        $this->classMetadataFactory = $classMetadataFactory;
        $this->nameConverter = $nameConverter;
        $this->propertyAccessor = $propertyAccessor ?: PropertyAccess::createPropertyAccessor();
        $this->propertyTypeExtractor = $propertyTypeExtractor;
        $this->maxDepthHandler = $maxDepthHandler;
        $this->circularReferenceHandler = $circularReferenceHandler;
        $this->byReferenceHandler = $byReferenceHandler;
    }

    /**
     * Checks whether the given class is supported for normalization by this normalizer.
     *
     * @param mixed  $data   Data to normalize
     * @param string $format The format being (de-)serialized from or into
     *
     * @return bool
     */
    public function supportsNormalization($data, $format = null)
    {
        return $data instanceof DataInterface;
    }

    /**
     * Normalizes an object into a set of arrays/scalars.
     *
     * @param DataInterface $object  object to normalize
     * @param string        $format  format the normalization result will be encoded as
     * @param array         $context Context options for the normalizer
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws ExceptionInterface
     * @throws \Sidus\EAVModelBundle\Exception\EAVExceptionInterface
     * @throws InvalidValueDataException
     * @throws CircularReferenceException
     * @throws ReflectionException
     *
     * @return array
     */
    public function normalize($object, $format = null, array $context = [])
    {
        $this->maxDepthHandler->handleMaxDepth($context);

        if ($this->byReferenceHandler->isByShortReference($context)) {
            return $object->getIdentifier();
        }


        if ($this->circularReferenceHandler->isCircularReference($object, $context)) {
            return $this->circularReferenceHandler->handleCircularReference($object);
        }

        $data = [];

        $familyOptions = $object->getFamily()->getOption(self::SERIALIZER_OPTIONS, []);
        if (array_key_exists(self::MINIMAL_KEY, $familyOptions) && $familyOptions[self::MINIMAL_KEY]) {
            $attributeValue = $this->getAttributeValue($object, 'identifier', $format, $context);
            $data = $this->updateData($data, 'identifier', $attributeValue);
        } else {
            foreach ($this->extractStandardAttributes($object, $format, $context) as $attribute) {
                $subContext = $context; // Copy context and force by reference
                $subContext[ByReferenceHandler::BY_REFERENCE_KEY] = true; // Keep in mind that the normalizer might not support it
                $attributeValue = $this->getAttributeValue($object, $attribute, $format, $subContext);
                $data = $this->updateData($data, $attribute, $attributeValue);
            }
        }

        foreach ($this->extractEAVAttributes($object, $format, $context) as $attribute) {
            $attributeValue = $this->getEAVAttributeValue($object, $attribute, $format, $context);
            $data = $this->updateData($data, $attribute->getCode(), $attributeValue);
        }

        return $data;
    }

    /**
     * Sets an attribute and apply the name converter if necessary.
     *
     * @param array  $data
     * @param string $attribute
     * @param mixed  $attributeValue
     *
     * @return array
     */
    protected function updateData(array $data, $attribute, $attributeValue)
    {
        if ($this->nameConverter) {
            $attribute = $this->nameConverter->normalize($attribute);
        }

        $data[$attribute] = $attributeValue;

        return $data;
    }

    /**
     * @param DataInterface $object
     * @param string        $attribute
     * @param string        $format
     * @param array         $context
     *
     * @throws ExceptionInterface
     *
     * @return mixed
     */
    protected function getAttributeValue(
        DataInterface $object,
        $attribute,
        $format = null,
        array $context = []
    ) {
        $rawValue = $this->propertyAccessor->getValue($object, $attribute);
        $subContext = $this->getAttributeContext($object, $attribute, $rawValue, $context);

        return $this->normalizer->normalize($rawValue, $format, $subContext);
    }

    /**
     * @param DataInterface $object
     * @param string        $attribute
     * @param mixed         $rawValue
     * @param array         $context
     *
     * @return array
     */
    protected function getAttributeContext(
        DataInterface $object,
        $attribute,
        /** @noinspection PhpUnusedParameterInspection */
        $rawValue,
        array $context
    ) {
        return array_merge(
            $context,
            [
                'parent' => $object,
                'attribute' => $attribute,
            ]
        );
    }

    /**
     * @param DataInterface      $object
     * @param AttributeInterface $attribute
     * @param string             $format
     * @param array              $context
     *
     * @throws EAVExceptionInterface
     *
     * @return mixed
     */
    protected function getEAVAttributeValue(
        DataInterface $object,
        AttributeInterface $attribute,
        $format = null,
        array $context = []
    ) {
        $rawValue = $object->get($attribute->getCode());
        $subContext = $this->getEAVAttributeContext($object, $attribute, $rawValue, $context);

        return $this->normalizer->normalize($rawValue, $format, $subContext);
    }

    /**
     * @param DataInterface      $object
     * @param AttributeInterface $attribute
     * @param mixed              $rawValue
     * @param array              $context
     *
     * @return array
     */
    protected function getEAVAttributeContext(
        DataInterface $object,
        AttributeInterface $attribute,
        /** @noinspection PhpUnusedParameterInspection */
        $rawValue,
        array $context
    ) {
        $options = $attribute->getOption(static::SERIALIZER_OPTIONS, []);

        $byReference = $attribute->getType()->isRelation();
        if (array_key_exists(ByReferenceHandler::BY_REFERENCE_KEY, $options)) {
            $byReference = $options[ByReferenceHandler::BY_REFERENCE_KEY];
        }

        $byShortReference = false;
        if (array_key_exists(ByReferenceHandler::BY_SHORT_REFERENCE_KEY, $options)) {
            $byShortReference = $options[ByReferenceHandler::BY_SHORT_REFERENCE_KEY];
        }

        $maxDepth = $context[MaxDepthHandler::MAX_DEPTH_KEY];
        if (array_key_exists(MaxDepthHandler::MAX_DEPTH_KEY, $options)) {
            $maxDepth = $options[MaxDepthHandler::MAX_DEPTH_KEY];
        }

        $additionalContext = [];
        if (array_key_exists(self::CONTEXT_KEY, $options)) {
            $additionalContext = $options[self::CONTEXT_KEY];
        }

        return array_merge(
            $context,
            [
                MaxDepthHandler::MAX_DEPTH_KEY => $maxDepth,
                ByReferenceHandler::BY_REFERENCE_KEY => $byReference,
                ByReferenceHandler::BY_SHORT_REFERENCE_KEY => $byShortReference,
                'parent' => $object,
                'attribute' => $attribute->getCode(),
                'eav_attribute' => $attribute,
            ],
            $additionalContext
        );
    }

    /**
     * @param DataInterface $object
     * @param string        $format
     * @param array         $context
     *
     * @throws InvalidArgumentException
     * @throws ReflectionException
     *
     * @return array
     */
    protected function extractStandardAttributes(DataInterface $object, $format = null, array $context = [])
    {
        // If not using groups, detect manually
        $attributes = [];

        // methods
        $reflClass = new ReflectionClass($object);
        foreach ($reflClass->getMethods(ReflectionMethod::IS_PUBLIC) as $reflMethod) {
            if (0 !== $reflMethod->getNumberOfRequiredParameters() ||
                $reflMethod->isStatic() ||
                $reflMethod->isConstructor() ||
                $reflMethod->isDestructor()
            ) {
                continue;
            }

            $name = $reflMethod->name;
            $attributeName = null;

            if (0 === strpos($name, 'get') || 0 === strpos($name, 'has')) {
                // getters and hassers
                $attributeName = lcfirst(substr($name, 3));
            } elseif (0 === strpos($name, 'is')) {
                // issers
                $attributeName = lcfirst(substr($name, 2));
            }

            // Skipping eav attributes
            if ($object->getFamily()->hasAttribute($attributeName)) {
                continue;
            }

            if (null !== $attributeName && $this->isAllowedAttribute($object, $attributeName, $format, $context)) {
                $attributes[$attributeName] = true;
            }
        }

        return array_keys($attributes);
    }

    /**
     * @param DataInterface $object
     * @param string        $format
     * @param array         $context
     *
     * @throws InvalidArgumentException
     *
     * @return \Sidus\EAVModelBundle\Model\AttributeInterface[]
     */
    protected function extractEAVAttributes(DataInterface $object, $format = null, array $context = [])
    {
        $allowedAttributes = [];
        foreach ($object->getFamily()->getAttributes() as $attribute) {
            if ($this->isAllowedEAVAttribute($object, $attribute, $format, $context)) {
                $allowedAttributes[] = $attribute;
            }
        }

        return $allowedAttributes;
    }

    /**
     * Is this EAV attribute allowed?
     *
     * @param DataInterface      $object
     * @param AttributeInterface $attribute
     * @param string|null        $format
     * @param array              $context
     *
     * @throws InvalidArgumentException
     *
     * @return bool
     */
    protected function isAllowedEAVAttribute(
        DataInterface $object,
        AttributeInterface $attribute,
        /** @noinspection PhpUnusedParameterInspection */
        $format = null,
        array $context = []
    ) {
        $options = $attribute->getOption(static::SERIALIZER_OPTIONS, []);

        // Ignore attributes set as serializer: expose: false
        if (array_key_exists(static::EXPOSE_KEY, $options) && !$options[static::EXPOSE_KEY]) {
            return false;
        }

        // If normalizing by reference, we just check if it's among the allowed attributes
        if ($this->byReferenceHandler->isByReference($context)) {
            return in_array($attribute->getCode(), $this->referenceAttributes, true);
        }

        // Also check ignored attributes
        if (in_array($attribute->getCode(), $this->ignoredAttributes, true)) {
            return false;
        }

        return $this->isEAVGroupAllowed($object, $attribute, $context);
    }

    /**
     * Gets attributes to normalize using groups.
     *
     * @param DataInterface      $object
     * @param AttributeInterface $attribute
     * @param array              $context
     *
     * @throws InvalidArgumentException
     *
     * @return bool
     */
    protected function isEAVGroupAllowed(/** @noinspection PhpUnusedParameterInspection */
        DataInterface $object,
        AttributeInterface $attribute,
        array $context
    ) {
        if (!isset($context[static::GROUPS]) || !is_array($context[static::GROUPS])) {
            return true;
        }

        $serializerOptions = $attribute->getOption(static::SERIALIZER_OPTIONS, []);
        if (!array_key_exists(static::GROUPS, $serializerOptions)) {
            return false;
        }

        $groups = $serializerOptions[static::GROUPS];
        if (!is_array($groups)) {
            throw new InvalidArgumentException(
                "Invalid 'serializer.groups' option for attribute {$attribute->getCode()} : should be an array"
            );
        }

        return 0 < count(array_intersect($groups, $context[static::GROUPS]));
    }

    /**
     * Is this attribute allowed?
     *
     * @param DataInterface $object
     * @param string        $attribute
     * @param string|null   $format
     * @param array         $context
     *
     * @throws InvalidArgumentException
     *
     * @return bool
     */
    protected function isAllowedAttribute(
        DataInterface $object,
        $attribute,
        /** @noinspection PhpUnusedParameterInspection */
        $format = null,
        array $context = []
    ) {
        // If normalizing by reference, we just check if it's among the allowed attributes
        if ($this->byReferenceHandler->isByReference($context)) {
            return in_array($attribute, $this->referenceAttributes, true);
        }

        // Check ignored attributes
        if (in_array($attribute, $this->ignoredAttributes, true)) {
            return false;
        }

        return $this->isGroupAllowed($object, $attribute, $context);
    }

    /**
     * Gets attributes to normalize using groups.
     *
     * @param DataInterface $object
     * @param string        $attribute
     * @param array         $context
     *
     * @throws InvalidArgumentException
     *
     * @return bool
     */
    protected function isGroupAllowed(DataInterface $object, $attribute, array $context)
    {
        if (!$this->classMetadataFactory || !isset($context[static::GROUPS]) || !is_array($context[static::GROUPS])) {
            return true;
        }

        $attributesMetadatas = $this->classMetadataFactory->getMetadataFor($object)->getAttributesMetadata();
        foreach ($attributesMetadatas as $attributeMetadata) {
            // Alright, it's completely inefficient...
            if ($attributeMetadata->getName() === $attribute) {
                return 0 < count(array_intersect($attributeMetadata->getGroups(), $context[static::GROUPS]));
            }
        }

        return false;
    }
}
