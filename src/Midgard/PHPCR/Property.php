<?php
namespace Midgard\PHPCR;

use PHPCR\PropertyInterface;
use PHPCR\ItemInterface;
use PHPCR\PropertyType;
use PHPCR\NodeType\PropertyDefinitionInterface; 
use PHPCR\ValueFormatException;
use PHPCR\RepositoryException;
use IteratorAggregate;
use DateTime;
use Midgard\PHPCR\Utils\NodeMapper;
use Midgard\PHPCR\Utils\ValueFactory;
use Midgard\PHPCR\Utils\StringValue;
use Midgard\PHPCR\Utils\Value;
use midgard_blob;
use midgard_node_property;

class Property extends Item implements IteratorAggregate, PropertyInterface
{
    protected $propertyName = null;
    protected $type = PropertyType::UNDEFINED;
    protected $definition = null;

    public function __construct(Node $node, $propertyName, PropertyDefinitionInterface $definition = null, $type = null)
    {
        $this->propertyName = $propertyName; 
        $this->parent = $node;
        $this->session = $node->getSession();
        $this->definition = $definition;

        if ($definition) {
            $this->type = $definition->getRequiredType();
        } elseif ($type) {
            $this->type = $type;
        }
    }

    protected function populateParent()
    {
    }

    public function getParentNode()
    {
        return $this->parent;
    }

    private function determineType($value)
    {
        if (is_long($value)) {
            return PropertyType::LONG;
        }

        if (is_double($value)) {
            return PropertyType::DOUBLE;
        }

        if (is_string($value)) {
            return PropertyType::STRING;
        }

        if (is_bool($value)) {
            return PropertyType::BOOLEAN;
        }

        if (is_array($value)) {
            return $this->determineType($value[0]);
        }
    }

    private function validateValue($value, $type)
    {
        /*
        if (is_array($value) && !$this->isMultiple()) {
xdebug_print_function_stack();
            throw new \PHPCR\ValueFormatException("Attempted to set array as value to a non-multivalued property");
        }
        */

        if ($type == PropertyType::PATH) {
            if (strpos($value, ' ') !== false) {
                throw new ValueFormatException("Invalid empty element in path");
            }
        }

        if ($type == PropertyType::URI) {
            if (strpos($value, '\\') !== false) {
                throw new ValueFormatException("Invalid '\' URI character");
            }
        }

        if ($type == PropertyType::NAME)
        {
            if (strpos($value, ':') !== false) {
                $nsregistry = $this->getSession()->getWorkspace()->getNamespaceRegistry();
                $nsmanager = $nsregistry->getNamespaceManager();
                if (!$nsmanager->getPrefix($value)) {
                    throw new \PHPCR\ValueFormatException("Invalid '\' URI character");
                }
            }
        }
    }

    private function normalizePropertyValue($value, $type)
    {
        /*
         * The type detection follows PropertyType::determineType. 
         * Thus, passing a Node object without an explicit type (REFERENCE or WEAKREFERENCE) will create a REFERENCE property. 
         * If the specified node is not referenceable, a ValueFormatException is thrown.
         */ 
        if (is_a($value, 'Node')) {
            if (!$value->isReferenceable()) {
                throw new ValueFormatException("Node " . $value->getPath() . " is not referencable"); 
            }

            if ($type == null) {
                $type = PropertyType::REFERENCE;
            }

            return $value->getProperty('jcr:uuid')->getString();
        }
        else if (is_a($value, 'DateTime'))
        {
            return $value->format("c");
        }
        else if (is_a($value, '\Midgard\PHPCR\Property'))
        {
            return $value->getString(); 
        }
        return $value;
    }

    public function setValue($value, $type = null, $weak = FALSE)
    { 
        /* \PHPCR\ValueFormatException */
        $this->validateValue($value, $type);

        /* Check if property is registered.
         * If it is, we need to validate if conversion follows the spec: "3.6.4 Property Type Conversion" */
        if ($this->definition && $type) {
            Value::checkTransformable($this->getType(), $type);
        }

        /* TODO, handle:
         * \PHPCR\Version\VersionException 
         * \PHPCR\Lock\LockException
         * \PHPCR\ConstraintViolationException
         * \PHPCR\RepositoryException
         * \InvalidArgumentException
         */ 

        $normalizedValue = $this->normalizePropertyValue($value, $type);
        $this->type = $type;
        $this->setMidgard2PropertyValue($this->getName(), $this->isMultiple(), $normalizedValue);
    }
    
    public function addValue($value)
    {
        throw new \PHPCR\RepositoryException("Not allowed");
    }

    public function getValue()
    {
        $type = $this->getType();

        switch ($type) 
        {
        case \PHPCR\PropertyType::DATE:
            return $this->getDate();

        case \PHPCR\PropertyType::BINARY:
            return $this->getBinary();

        case \PHPCR\PropertyType::REFERENCE:
        case \PHPCR\PropertyType::WEAKREFERENCE:
            return $this->getNode();

        default:
            return ValueFactory::transformValue($this->getNativeValue(), \PHPCR\PropertyType::STRING, $type);
        } 
    }

    public function getNativeValue()
    {
        if ($this->type == PropertyType::BINARY) {
            return $this->getBinary();
        } 

        return $this->getMidgard2PropertyValue($this->getName(), $this->isMultiple());
    }

    public function getString()
    {
        $type = $this->getType();
        return ValueFactory::transformValue($this->getNativeValue(), $type, \PHPCR\PropertyType::STRING);
    }
    
    public function getBinary()
    {
        if ($this->getType() != PropertyType::BINARY) {
            $sv = new StringValue();
            return ValueFactory::transformValue($this->getNativeValue(), $this->type, PropertyType::BINARY);
        }

        $object = $this->getMidgard2PropertyStorage($this->getName(), $this->isMultiple());

        $constraints = array(
            'name' => $this->getName(),
        );

        $ret = array();
        $attachments = array();
        if (is_array($object)) {
            foreach ($object as $propertyObject) {
                if (!is_object($object)) {
                    continue;
                }
                if (!$object->guid) {
                    continue;
                }
                $attachments = array_merge($attachments, $propertyObject->find_attachments($constraints));
            }
        } elseif ($object->guid) {
            $attachments = $object->find_attachments($constraints);
        }

        foreach ($attachments as $att) {
            $blob = new midgard_blob($att);
            $ret[] = $blob->get_handler('r');
        }

        if (empty($ret)) {
            // FIXME: We should use temporary files in this case
            throw new RepositoryException('Unable to load attachments of non-persistent object');
        }

        if ($this->isMultiple()) {
            return $ret;
        }
        return $ret[0];
    }
    
    public function getLong()
    {
        $type = $this->getType();
        if ($type == \PHPCR\PropertyType::DATE
            || $type == \PHPCR\PropertyType::BINARY
            || $type == \PHPCR\PropertyType::DECIMAL
            || $type == \PHPCR\PropertyType::REFERENCE
            || $type == \PHPCR\PropertyType::WEAKREFERENCE)
        {
            throw new \PHPCR\ValueFormatException("Can not convert {$this->propertyName} (of type " . \PHPCR\PropertyType::nameFromValue($type) . ") to LONG."); 
        } 
        
        return ValueFactory::transformValue($this->getNativeValue(), $type, \PHPCR\PropertyType::LONG);
    }
    
    public function getDouble()
    {
        $type = $this->getType();
        if ($type == \PHPCR\PropertyType::DATE
            || $type == \PHPCR\PropertyType::BINARY
            || $type == \PHPCR\PropertyType::REFERENCE) 
        {
            throw new \PHPCR\ValueFormatException("Can not convert {$this->propertyName} (of type " . \PHPCR\PropertyType::nameFromValue($type) . ") to DOUBLE."); 
        } 

        return ValueFactory::transformValue($this->getNativeValue(), $type, \PHPCR\PropertyType::DOUBLE);
    }
    
    public function getDecimal()
    {
        $type = $this->getType();
        return ValueFactory::transformValue($this->getNativeValue(), $type, \PHPCR\PropertyType::DECIMAL);
    }
    
    public function getDate()
    {
        $type = $this->getType();
        if ($type == \PHPCR\PropertyType::DATE
            || $type == \PHPCR\PropertyType::STRING)
        {
            try 
            {
                $v = $this->getNativeValue();
                if (is_array($v))
                {
                    foreach ($v as $value)
                    {
                        $ret[] = new DateTime($value);
                    }
                    return $ret;
                }
                if ($v instanceof DateTime)
                {
                    $date = $v;
                }
                else 
                {
                    $date = new DateTime($this->getNativeValue());
                }
                return $date;
            }
            catch (\Exception $e)
            {
                /* Silently ignore */
            }
        }
        /*
        var_dump($this->getMidgard2Node());
        var_dump($this->getParent()->getMidgard2Node());
        var_dump($type);
        var_dump($this->getNativeValue());
        var_dump($this->getMidgard2PropertyStorage($this->getName(), $this->isMultiple()));
        die();*/
        throw new \PHPCR\ValueFormatException("Can not convert {$this->propertyName} (of type " . \PHPCR\PropertyType::nameFromValue($type)  . ") to DateTime object.");
    }
    
    public function getBoolean()
    {
        $type = $this->getType();
        return ValueFactory::transformValue($this->getNativeValue(), $type, \PHPCR\PropertyType::BOOLEAN);
    }

    public function getName()
    {
        return $this->propertyName;
    }

    public function getNode()
    {
        $type = $this->getType();
        if ($type == \PHPCR\PropertyType::PATH) {
            $path = $this->getNativeValue();
            if (is_array($path)) {
                return $this->getSession()->getNodes($path);
            }
            /* TODO, handle /./ path */
            if (strpos($path, ".") == false)
            {
                try 
                {
                    $node = $this->parent->getNode($path);
                    return $node;
                }
                catch (\PHPCR\PathNotFoundException $e)
                {
                    throw new \PHPCR\ItemNotFoundException($e->getMessage());
                }
            }
            return $this->getSession()->getNode($path);
        }

        if ($type == \PHPCR\PropertyType::REFERENCE || $type == \PHPCR\PropertyType::WEAKREFERENCE)
        {
            try {
                $v = $this->getNativeValue();
                if (is_array($v)) {
                    $ret = array();
                    foreach ($v as $id) {
                        $ret[] = $this->parent->getSession()->getNodeByIdentifier($id);
                    } 
                    return $ret;
                } 
                return $this->parent->getSession()->getNodeByIdentifier($v);
            }
            catch (\PHPCR\PathNotFoundException $e)
            {
                throw new \PHPCR\ItemNotFoundException($e->getMessage());
            }
        }
   
        throw new \PHPCR\ValueFormatException("Can not convert {$this->propertyName} (of type " . \PHPCR\PropertyType::nameFromValue($type) . ") to Node type."); 

        return $this->parent;
    }
    
    public function getProperty()
    {
        $type = $this->getType();
        if ($type != \PHPCR\PropertyType::PATH)
        {
            throw new \PHPCR\ValueFormatException("Can not convert {$this->propertyName} (of type " . \PHPCR\PropertyType::nameFromValue($type) . ") to PATH type.");
        } 

        $path = $this->getValue();
        if (is_array($path))
        {
            foreach ($path as $v)
            {
                $ret[] = $this->parent->getProperty($v);
            }
            return $ret;
        }

        return $this->parent->getProperty($path);
    }
    
    public function getLength()
    {
        $v = $this->getNativeValue();
        if (is_array($v))
        {
            return $this->getLengths();
        }

        if ($this->type === \PHPCR\PropertyType::BINARY)
        {
            $stat = fstat($v);
            return $stat['size'];
        }
        return strlen($this->getString());
    }
    
    public function getLengths()
    {
        $v = $this->getNativeValue();
        if (is_array($v))
        {
            /* Native values are always strings */
            foreach ($v as $values)
            {
                if ($this->type == \PHPCR\PropertyType::BINARY)
                {
                    $stat = fstat($values);
                    $ret[] = $stat['size'];
                    continue;
                }
                $ret[] = strlen($values);
            }
            return $ret;
        }
        throw new \PHPCR\ValueFormatException("Can not get lengths of single value");
    }
    
    public function getDefinition()
    {
        return $this->definition;
    }

    public function getType()
    {
        if ($this->type) {
            // Type either given at instantiation or from definition
            return $this->type;
        }

        $object = $this->getMidgard2PropertyStorage($this->getName(), false, true, false);
        if (!$object) {
            return PropertyType::UNDEFINED;
        }

        if (is_array($object)) {
            $object = $object[0];
        }

        if (is_a($object, 'midgard_node_property')) {
            // Unknown additional property, read type from storage object
            return $object->type;
        }
        return NodeMapper::getPHPCRPropertyType(get_class($object), NodeMapper::getMidgardPropertyName($this->getName()));
    }
    
    public function isMultiple()
    {
        if ($this->definition) {
            return $this->definition->isMultiple();
        }
        $object = $this->getMidgard2PropertyStorage($this->getName(), false);
        if ($object && $object->multiple) {
            return true;
        }

        return false;
    }

    public function isNode()
    {
        return false;
    }
    
    public function getIterator()
    {
        $v = $this->getValue();
        return new \ArrayIterator(is_array($v) ? $v : array($v));
    }

    public function isSame(ItemInterface $item)
    {
        if (!$item instanceof PropertyInterface) {
            return false;
        }

        if ($item->getName() == $this->getName()) {
            if ($item->getParent()->isSame($this->getParent())) {
                return true;
            } 
        }

        return false;
    }

    public function save()
    {
        $object = $this->getMidgard2PropertyStorage($this->getName(), $this->isMultiple());
        if (is_array($object)) {
            foreach ($object as $propertyObject) {
                if ($propertyObject->guid) {
                    $propertyObject->update();
                    continue;
                }
                $propertyObject->create();
            }
            $this->setUnmodified();
            return;
        }

        if (!is_a($object, 'midgard_node_property')) {
            return;
        }

        if ($object->guid) {
            $object->update();
            $this->setUnmodified();
            return;
        }

        $object->create();
        $this->setUnmodified();
    }

    public function refresh($keepChanges)
    {
        if ($keepChanges && ($this->isModified() || $this->isNew())) {
            return;
        }
    }

    public function remove()
    {
        $this->parent->setProperty($this->getName(), null);
    }
}
