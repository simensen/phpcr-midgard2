<?php
namespace Midgard\PHPCR\NodeType;

use Midgard\PHPCR\Utils\NodeMapper;
use PHPCR\NodeType\NodeTypeDefinitionInterface;
use ReflectionClass;
use midgard_reflector_object;
use midgard_reflection_property;

class NodeTypeDefinition implements NodeTypeDefinitionInterface
{
    protected $nodeTypeManager = null;
    protected $childNodeDefinitions = null;
    protected $propertyDefinitions = null;
    protected $supertypeNames = null;
    protected $name = null;
    protected $primaryItemName = null;
    protected $hasOrderableChildNodes = false;
    protected $isAbstract = false;
    protected $isMixin = false;
    protected $isQueryable = false;

    public function __construct($name = null, NodeTypeManager $mgr)
    {
        $this->name = $name;
        $this->nodeTypeManager = $mgr;
    }

    public function getDeclaredChildNodeDefinitions() 
    {

    }

    public function getDeclaredPropertyDefinitions()
    {
        if (!is_null($this->propertyDefinitions)) {
            return $this->propertyDefinitions;
        }

        $midgardName = NodeMapper::getMidgardName($this->name);
        $this->propertyDefinitions = array();
        $properties = midgard_reflector_object::list_defined_properties($midgardName);
        foreach ($properties as $property => $value) {
            $propertyPHPCR = NodeMapper::getPHPCRProperty($property);
            if (!$propertyPHPCR) {
                continue;
            }
            $this->propertyDefinitions[$property] = new PropertyDefinition($this, $propertyPHPCR); 
        }

        return $this->propertyDefinitions;
    }

    public function getDeclaredSupertypeNames()
    {
        if (!is_null($this->supertypeNames)) {
            return $this->supertypeNames;
        }

        $midgardName = NodeMapper::getMidgardName($this->name);
        $reflector = new ReflectionClass($midgardName);
        $parentReflector = $reflector->getParentClass();
        if (!$parentReflector) {
            $this->supertypeNames = array();
            return $this->supertypeNames;
        }

        $this->supertypeNames = array();
        $crName = NodeMapper::getPHPCRName($parentReflector->getName());
        if (!$crName) {
            return $this->supertypeNames;
        }
        $this->supertypeNames[] = $crName;
        return $this->supertypeNames;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getPrimaryItemName()
    {
        if (!is_null($this->primaryItemName))
        {
            return $this->primaryItemName;
        }

        $mgdName = NodeMapper::getMidgardName($this->name);       
        $this->primaryItemName = midgard_reflector_object::get_schema_value($mgdName, 'PrimaryItemName');
        return $this->primaryItemName;
    }

    public function hasOrderableChildNodes()
    {
        return $this->hasOrderableChildNodes;
    }

    public function isAbstract()
    {
        return $this->isAbstract;
    }

    public function isMixin()
    {
        return $this->isMixin;
    }

    public function isQueryable()
    {
        return $this->isQueryable;
    }

    protected function getPropertyReflector($name)
    {
        /* If this is MidgardObject derived property, return null.
         * We have no session available at this point, so check prefix
         * directly */
        if (strpos($name, ':') !== false)
        {
            $parts = explode(':', $name); 
            if ($parts[0] == 'mgd')
            {
                return null;
            }
        }

        $midgardName = NodeMapper::getMidgardName($this->name);
        if (!$midgardName)
        {
            return null;
        }

        $reflector = new midgard_reflection_property($midgardName);
        if (!$reflector)
        {
            return null;
        }

        $midgardPropertyName = NodeMapper::getMidgardPropertyName($name);
        if (!$midgardPropertyName)
        {
            return null;
        }

        $midgardType = $reflector->get_midgard_type($name);
        /* Property is not registered for this type */
        if ($midgardType == MGD_TYPE_NONE)
        {
            return null;
        }

        return $reflector;
    }
}
