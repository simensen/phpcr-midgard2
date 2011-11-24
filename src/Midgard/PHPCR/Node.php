<?php
namespace Midgard\PHPCR;

use ArrayIterator;
use IteratorAggregate;
use InvalidArgumentException;
use Midgard\PHPCR\Utils\NodeMapper;
use PHPCR\NodeInterface;
use PHPCR\ItemInterface;
use PHPCR\NodeType\ConstraintViolationException; 
use PHPCR\PropertyType;
use PHPCR\PathNotFoundException;
use PHPCR\ItemExistsException;
use PHPCR\RepositoryException;
use PHPCR\NodeType\NoSuchNodeTypeException;
use PHPCR\ItemNotFoundException;
use midgard_node;
use midgard_query_select;
use midgard_query_constraint;
use midgard_query_constraint_group;
use midgard_query_storage;
use midgard_query_property;
use midgard_query_value;
use Midgard\PHPCR\NodeType\PropertyDefinition;

class Node extends Item implements IteratorAggregate, NodeInterface
{
    protected $children = null;
    protected $properties = null;
    protected $midgardPropertyNodes = null;
    protected $is_removed = false;
    protected $removeProperties = array();
    protected $isRoot = false;

    public function __construct(midgard_node $midgardNode = null, Node $parent = null, Session $session)
    {
        $this->parent = $parent;
        $this->session = $session;

        $this->setMidgard2Node($midgardNode);

        if ($parent == null) {
            if ($midgardNode->guid && $midgardNode->parent == 0) {
                $this->isRoot = true;
                $this->setMidgard2ContentObject($midgardNode);
            }
        }
    }

    protected function getTypeName($checkContentObject = true)
    {
        if ($this->isRoot) {
            return 'nt:folder';
        }

        $typeName = $this->getMidgard2PropertyValue('jcr:primaryType', false, $checkContentObject);
        if ($typeName) {
            return $typeName;
        }

        if ($this->getMidgard2Node()->typename) {
            return NodeMapper::getPHPCRNAME($this->getMidgard2Node()->typename);
        }

        return 'nt:unstructured';
    }

    private function appendNode($relPath, $primaryNodeTypeName = null)
    {
        if ($this->hasNode($relPath)) {
            throw new ItemExistsException("Node '{$relPath}' exists under " . $this->getPath());
        } 

        // LockException
        // TODO

        // VersionException 
        // TODO

        $midgardNode = new midgard_node();
        $midgardNode->typename = NodeMapper::getMidgardName($primaryNodeTypeName);
        $midgardNode->name = $relPath;
        $midgardNode->parent = $this->getMidgard2Node()->id;
        $midgardNode->parentguid = $this->getMidgard2Node()->guid;

        $newNode = new Node($midgardNode, $this, $this->getSession());
        $this->children[$relPath] = $newNode;

        $this->is_modified = true;

        return $newNode;
    }

    public function addNode($relPath, $primaryNodeTypeName = NULL)
    {
        $pos = strpos('/', $relPath);
        if ($pos === 0) {
            throw new InvalidArgumentException("Can not add Node at absolute path"); 
        }

        /* RepositoryException - If the last element of relPath has an index or if another error occurs. */
        if (strpos($relPath, '[') !== false) {
            throw new RepositoryException("Index not allowed");
        }

        if ($primaryNodeTypeName == null) {
            $def = $this->getDefinition();
            //$primaryNodeTypeName = $def->getDefaultPrimaryTypeName();
            if ($primaryNodeTypeName == null) {
                if ($this->getPath() == '/') {
                    $primaryNodeTypeName = 'nt:unstructured';
                } else {
                    /* ConstraintViolationException - if a node type or implementation-specific constraint 
                    * is violated or if an attempt is made to add a node as the child of a property and 
                    * this implementation performs this validation immediately.*/ 
                    throw new ConstraintViolationException("Can not determine default node type name for " . $this->getName() . "when trying to add '{$relPath}'");
                }
            }
        }
        else {
            $ntm = $this->session->getWorkspace()->getNodeTypeManager();
            $nt = $ntm->getNodeType($primaryNodeTypeName);
        }

        $parts = explode('/', $relPath);
        $pathElements = count($parts);
        if ($pathElements == 1) {
            return $this->appendNode($parts[0], $primaryNodeTypeName);
        }

        /* ConstraintViolationException:
         * "if a node type or implementation-specific constraint is violated or 
         *  if an attempt is made to add a node as the child of a property and 
         *  this implementation performs this validation immediately." */
        if ($this->hasProperty($parts[0])) {
            throw new \PHPCR\NodeType\ConstraintViolationException("Can not add node to '{$relPath}' Item which is a Property under " . $this->getPath());
        }

        if (!$this->hasNode($parts[0])) {
            $node = $this->appendNode(array_shift($parts));
            return $node->addNode(implode('/', $parts), $primaryNodeTypeName);
        }
    }

    public function orderBefore($srcChildRelPath, $destChildRelPath)
    {
        throw new \PHPCR\UnsupportedRepositoryOperationException();
    }

    private function getNodeDefinitionThatHasProperty($name)
    {
        $primary = $this->getPrimaryNodeType();
        if ($primary->hasRegisteredProperty($name)) {
            return $primary;
        }

        foreach ($this->getMixinNodeTypes() as $mixin) {
            if ($mixin->hasRegisteredProperty($name)) {
                return $mixin;
            }
        }
        return null;
    }

    public function setProperty($name, $value, $type = null)
    {
        if (strpos($name, '/') !== false) {
            throw new InvalidArgumentException("Can not set property name with '/' delimeter");
        }

        $nodeDef = $this->getNodeDefinitionThatHasProperty($name);
        if (is_null($value)) {
            if ($nodeDef && !$nodeDef->canRemoveProperty($name)) {
                throw new ConstraintViolationException("Can not remove property {$name} which is mandatory for {$this->primaryNodeTypeName }");
            }

            return $this->removeProperty($name);
        }

        $propertyDef = null;
        if ($type != null && $nodeDef) {
            $propertyDefs = $nodeDef->getPropertyDefinitions();
            if (isset($propertyDefs[$name])) {
                $propertyDef = $propertyDefs[$name];
                $requiredType = $propertyDefs[$name]->getRequiredType();

                if ($requiredType != 0 && $requiredType != $type) {
                    throw new ConstraintViolationException("Wrong type for {$name} property. " . PropertyType::nameFromValue($type) . " given. Expected " . PropertyType::nameFromValue($requiredType));
                }

                if ($requiredType != 0 && ($requiredType != null && $requiredType != $type) && property_exists($this->getMidgard2ContentObject(), $name)) {
                    throw new ConstraintViolationException("Wrong type for {$name} property. " . PropertyType::nameFromValue($type) . " given. Expected " . PropertyType::nameFromValue($requiredType));
                }
            }
        }

        $origValue = null;
        try {
            $property = $this->getProperty($name);
            $origValue = $property->getValue();
        } 
        catch (PathNotFoundException $e) { 
            $this->properties[$name] = new Property($this, $name, $propertyDef, $type);
            $property = $this->properties[$name];
        }
        $property->setValue($value, $type);
       
        if (is_null($origValue) || $value != $origValue) {
            $this->is_modified = true;
        }

        return $property;
    }

    public function getNode($relPath)
    {
        /* Convert to relative path when absolute one has been given */
        /* FIXME, Remove this part once absolute path is considered invalid
         * https://github.com/phpcr/phpcr-api-tests/issues/9 */
        $pos = strpos($relPath, '/');
        if ($pos === 0) {
            $relPath = substr($relPath, 1);
            $pos = strpos($relPath, '/');
        }

        $remainingPath = '';
        if ($pos !== false) {
            $parts = explode('/', $relPath);
            $relPath = array_shift($parts);
            $remainingPath = implode('/', $parts);
        }

        if ($relPath == '..') {
            if (!$this->getParent()) {
                throw new PathNotFoundException("Node at path '{$relPath}' not found under " . $this->getPath());
            }
            if ($remainingPath) {
                return $this->getParent()->getNode($remainingPath);
            } 
            return $this->getParent();
        }

        if (is_null($this->children)) {
            $this->populateChildren();
        }
        
        if (!isset($this->children[$relPath]) || $this->children[$relPath]->is_removed) {
            throw new PathNotFoundException("Node at path '{$relPath}' not found under " . $this->getPath());
        }

        if ($remainingPath != '') {
            return $this->children[$relPath]->getNode($remainingPath);
        }

        return $this->children[$relPath];        
    }

    private function getItemsSimilar($items, $nsname, $isNode)
    {
        $ret = array();

        $nsregistry = $this->getSession()->getWorkspace()->getNamespaceRegistry();
        $nsmanager = $nsregistry->getNamespaceManager();

        foreach ($items as $n => $o)
        { 
            $prefixMatch = false;
            $nameMatch = false;
            $itemName = $n;
            $node_prefix = $nsmanager->getPrefix($itemName);
            $prefix = $nsname[0];
            $name = $nsname[1];

            if ($prefix != "")
            {
                /* Compare prefix */
                if ($node_prefix == $prefix)
                {
                    $prefixMatch = true;
                }
            }
            else if ($prefix == '*'
                || $prefix == '')
            {
                /* Empty prefix or wildcard, everything matches */
                $prefixMatch = true;
            }

            if ($name != '' && $name != '*') 
            {
                /* Clean given name and item name:
                 * From name remove wildcard and from item's one - prefix. */
                $name = str_replace('*', '', $name);
                $itemName = str_replace($prefix . ':' , '', $itemName);
                $pos = strpos($itemName, $name);
               
                if ($pos !== false)
                {
                    $nameMatch = true;
                }
            }
            else if ($name == '*')
            {
                /* Wildcard so everything matches */
                $nameMatch = true;
            }

            if ($prefixMatch == true && $nameMatch == true)
            {
                $ret[$n] = $isNode ? $this->getNode($n) : $this->getProperty($n);
            }
        }

        return $ret;
    }

    private function getItemsEqual($items, $nsnames)
    {
        $ret = array();

        $prefix = $nsnames[0];
        $name = $nsnames[1];

        if (array_key_exists($name, $this->children))
        {
            $ret[$name] = $items[$name];
        }
            
        return $ret;
    }

    /* Return array of prefixes and names.
     * Any prefix or name might be empty or null:
     * 
     * jcr:*
     * prefix = "jcr", name = "*"
     *
     * my doc
     * prefix = "", name = "my doc"
     *
     * jcr:created
     * prefix = "jcr", name = "created"
     */
    private function getFiltersFromString($filter)
    {
        $filters = array();
        $filtered = array();
        $parts = explode('|', $filter);
        if (!isset($parts[1]))
        {
            $filters[] = $filter;
        }
        else 
        {
            foreach($parts as $p)
            {
                $filters[] = trim($p);
            }
        }
        foreach ($filters as $f)
        {
            $parts = explode(':', $f);
            $prefix = "";
            $name = "";
            if (isset($parts[1]))
            {
                $prefix = $parts[0];
                $name = $parts[1]; 
            }
            else 
            {
                $name = $parts[0];
            }
            $filtered[] = array($prefix, $name);
        }

        return $filtered; 
    }

    private function getFiltersFromArray($filter)
    {
        $allFilters = array();
        foreach ($filter as $p)
        {
            $allFilters = array_merge($allFilters, $this->getFiltersFromString($p));
        }
        return $allFilters;
    }

    private function getItemsFiltered($items, $filter = null, $isNode)
    {
        if ($filter == null) {
            return new ArrayIterator($items);
        } 

        $filteredItems = array();

        if (is_string($filter)) {
            $filters = $this->getFiltersFromString($filter);
        }

        if(is_array($filter)) {
            $filters = $this->getFiltersFromArray($filter);
        } 

        foreach ($filters as $i => $f) {
            if (strpos($f[0], '*') !== false || strpos($f[1], '*') !== false) { 
                $filteredItems = array_merge($filteredItems, $this->getItemsSimilar($items, $f, $isNode));
            }
            else  { 
                $filteredItems = array_merge($filteredItems, $this->getItemsEqual($items, $f, $isNode));
            }
        }

        return new ArrayIterator($filteredItems);   
    }

    public function getNodes($filter = null)
    {
        $this->populateChildren();
        $nodes = array();
        if ($this->children) {
            foreach ($this->children as $child) {
                if ($child->is_removed) {
                    continue;
                }
                $nodes[] = $child;
            }
        }
        return $this->getItemsFiltered($nodes, $filter, true); 
    }

    protected function populateParent()
    {
        if ($this->isRoot) {
            return;
        }

        $parentMidgardNode = new midgard_node($this->getMidgard2Node()->parent);
        $this->parent = new Node($parentMidgardNode, null, $this->getSession());
        $this->midgardNode->parent = $parentMidgardNode->id;
        $this->midgardNode->parentguid = $parentMidgardNode->guid;
    }

    private function populateChildren()
    {
        if (!is_null($this->children)) {
            return;
        }

        /* Node is not saved, so DO NOT list children */
        if (!$this->getMidgard2Node()->guid) {
            return;
        }

        $children = $this->getMidgard2Node()->list();
        $this->children = array();
        foreach ($children as $child) {
            $this->children[$child->name] = new Node($child, $this, $this->getSession());
        }
    }

    private function populateProperty(PropertyDefinition $definition)
    {
        $propertyName = $definition->getName();
        if (isset($this->properties[$propertyName])) {
            return;
        }

        $this->properties[$propertyName] = new Property($this, $propertyName, $definition);
    }

    private function populatePropertiesUndefined()
    {
        if (!$this->getMidgard2Node()->id) {
            return;
        }
        $q = new midgard_query_select(new midgard_query_storage('midgard_node_property'));
        $cg = new midgard_query_constraint_group('AND');
        $cg->add_constraint(
            new midgard_query_constraint(
                new midgard_query_property('parent'),
                '=',
                new midgard_query_value($this->getMidgard2Node()->id)
            )
        );
        $cg->add_constraint(
            new midgard_query_constraint(
                new midgard_query_property('name'),
                'NOT IN',
                new midgard_query_value(array_keys($this->properties))
            )
        );
        $q->set_constraint($cg);
        $q->execute();
        $properties = $q->list_objects();
        foreach ($properties as $property) {
            $crName = NodeMapper::getPHPCRProperty($property->name);
            $this->properties[$crName] = new Property($this, $crName);
        }
    }

    private function populateProperties()
    {
        if ($this->contentObject == null) {
            $this->populateContentObject();
        }

        if (is_null($this->properties)) {
            $this->properties = array();
        }

        $primary = $this->getPrimaryNodeType();
        foreach ($primary->getPropertyDefinitions() as $property) {
            $this->populateProperty($property);
        }

        $mixins = $this->getMixinNodeTypes();
        foreach ($mixins as $mixin) {
            foreach ($mixin->getPropertyDefinitions() as $property) {
                $this->populateProperty($property);
            }
        }

        $this->populatePropertiesUndefined();
    }

    public function getProperty($relPath)
    {
        $remainingPath = '';
        if (strpos($relPath, '/') !== false) {
            $parts = explode('/', $relPath);
            $property_name = array_pop($parts);
            $remainingPath = implode('/', $parts); 
            return $this->getNode($remainingPath)->getProperty($property_name);
        }

        if (!$this->hasProperty($relPath) || !isset($this->properties[$relPath])) {
            throw new PathNotFoundException("Property at path '{$relPath}' not found at node " . $this->getName() . " at path " . $this->getPath());
        }

        return $this->properties[$relPath];
    }
    
    public function getPropertyValue($name, $type=null)
    {
        return $this->getProperty($name)->getValue();
    }
    
    public function getProperties($filter = null)
    {
        $this->populateProperties();
        $ret = $this->getItemsFiltered($this->properties, $filter, false);
        return new \ArrayIterator($ret);
    }

    public function getPropertiesValues($filter = null, $dereference = true)
    {
        $properties = $this->getProperties($filter);
        $ret = array();
        foreach ($properties as $name => $property) {
            $type = $property->getType();
            if ($type == PropertyType::WEAKREFERENCE || $type == PropertyType::REFERENCE || $type == PropertyType::PATH) {
                if ($dereference == true) {
                    $ret[$name] = $property->getNode();
                }
                else {
                    $ret[$name] = $property->getString();
                }
            }
            else {
                $ret[$name] = $property->getValue(); 
            }
        }
        return $ret;
    }   
    
    public function getPrimaryItem()
    {
        $nt = $this->getPrimaryNodeType();
        $primaryItem = $nt->getPrimaryItemName();
        if (!$primaryItem) {
            throw new ItemNotFoundException("PrimaryItem not found for {$this->getName()} node");
        }

        if ($this->hasNode($primaryItem)) {
            return $this->getNode($primaryItem);
        }
        
        return $this->getProperty($primaryItem);
    }
    
    public function getIdentifier()
    {
        $this->populateProperties();
        if ($this->hasProperty('jcr:uuid')) {
            return $this->getPropertyValue('jcr:uuid');
        }

        /* Return guid if uuid is not found */
        return $this->contentObject->guid;
    }
    
    public function getIndex()
    {
        /* We do not support same name siblings */
        return 1;
    }
    
    private function getReferencesByType($name = null, $type)
    {
        $ret = array();
        $this->populateProperties();

        $uuid = $this->getIdentifier();
        if (!$uuid) {
            // No referenceable identifier, skip
            return new \ArrayIterator($ret);
        }
         
        $q = new \midgard_query_select(new \midgard_query_storage('midgard_node_property'));
        $group = new \midgard_query_constraint_group('AND');
        $group->add_constraint(
            new \midgard_query_constraint(
                new \midgard_query_property('value'),
                '=',
                new \midgard_query_value($uuid)));

        $group->add_constraint(
            new \midgard_query_constraint(
                new \midgard_query_property('type'),
                '=',
                new \midgard_query_value($type)
            )
        );

        if ($name != null) {
            $group->add_constraint(
                new \midgard_query_constraint(
                    new \midgard_query_property('name'),
                    '=',
                    new \midgard_query_value(NodeMapper::getMidgardPropertyName($name))
                )
            );
        }

        $q->set_constraint($group);
        $q->execute();
        if ($q->get_results_count() < 1)
        {
            return new \ArrayIterator($ret);
        }

        $nodeProperties = $q->list_objects();

        /* TODO, query properties only, once tree and nodes scope is provided by Property */

        /* query references */
        foreach ($nodeProperties as $midgardProperty)
        {
            $midgardNode = \midgard_object_class::factory('midgard_node', $midgardProperty->parent);
            $path = self::getMidgardPath($midgardNode);
            /* Convert to JCR path */
            $path = str_replace ('/jackalope', '', $path);
            $path = str_replace('/root', '', $path);
            $node = $this->session->getNode($path);
            $ret[] = $node->getProperty($midgardProperty->title);
        } 
        return new \ArrayIterator($ret);
    }

    public function getReferences($name = null)
    {
        return $this->getReferencesByType($name, \PHPCR\PropertyType::REFERENCE);
    }

    public function getWeakReferences($name = NULL)
    {
        return $this->getReferencesByType($name, \PHPCR\PropertyType::WEAKREFERENCE);
    }
    
    public function hasNode($relPath)
    {
        /* FIXME, optimize this.
         * Do not get node, check children array instead */
        $pos = strpos($relPath, '/');
        if ($pos === 0)
        {
            throw new \InvalidArgumentException("Expected relative path. Absolute given");
            /* Take few glasses if Absolute given ;) */
        }

        try 
        {
            $this->getNode($relPath);
            return true;
        }
        catch (PathNotFoundException $e) 
        {
            return false;
        }
    }
    
    public function hasProperty($relPath)
    {
        $this->populateProperties();
        return isset($this->properties[$relPath]);
    }
    
    public function hasNodes()
    {
        $this->populateChildren();
        foreach ($this->children as $node) {
            if (!$node->is_removed) {
                return true;
            }
        }
        return false;
    }
    
    public function hasProperties()
    {
        $this->populateProperties();
        if (empty($this->properties)) {
            return false;
        }

        return true;
    }
    
    public function getPrimaryNodeType()
    {
        $primaryType = $this->getTypeName();
        $ntm = $this->session->getWorkspace()->getNodeTypeManager();
        $nt = $ntm->getNodeType($primaryType);
        if (!$nt) {
            $name = $this->getName();
            throw new RepositoryException("Failed to get NodeType from current '{$name}' node ({$primaryType})");
        }
        return $nt;
    }
    
    public function getMixinNodeTypes()
    {
        $mixins = $this->getMidgard2PropertyValue('jcr:mixinTypes', true);
        $ret = array();
        if (!$mixins) {
            return $ret;
        }

        $ntm = $this->session->getWorkspace()->getNodeTypeManager();
        if (!is_array($mixins)) {
            $tmp[] = $mixins;
            $mixins = $tmp;
        }

        foreach ($mixins as $mixin) {
            if (!$mixin) {
                continue;
            }
            $ret[] = $ntm->getNodeType($mixin);
        }

        return $ret;
    }
    
    public function isNodeType($nodeTypeName)
    {
        $primary = $this->getPrimaryNodeType();
        if ($primary->isNodeType($nodeTypeName)) {
            return true;
        }

        $mixins = $this->getMixinNodeTypes();
        foreach ($mixins as $mixin) {
            if ($mixin->isNodeType($nodeTypeName)) {
                return true;
            }
        }

        return false;
    }
    
    public function setPrimaryType($nodeTypeName)
    {
        throw new \PHPCR\RepositoryException("Not supported");
    }
    
    public function addMixin($mixinName)
    {
        if (!$this->canAddMixin($mixinName)) {
            throw new NoSuchNodeTypeException("{$mixinName} is not registered mixin type"); 
        }

        // Check if we already have such a mixin
        $mixins = $this->getMixinNodeTypes();
        foreach ($mixins as $mixin) {
            if ($mixin->getName() == $mixinName) {
                return;
            }
        }

        $this->setProperty('jcr:mixinTypes', $mixinName);
        $properties = \midgard_reflector_object::list_defined_properties ($midgardMixinName);
        foreach ($properties as $name => $v)
        {
            $jcrName = NodeMapper::getPHPCRProperty($name);
            if($this->hasProperty($jcrName))
            {
                continue;
            }

            /* FIXME, determine default value */
            $this->setProperty($jcrName, ' ');
        }
    }
    
    public function removeMixin($mixinName)
    {
    }
    
    public function canAddMixin($mixinName)
    {
        $midgardMixinName = NodeMapper::getMidgardName($mixinName);
        if ($midgardMixinName == null || !interface_exists($midgardMixinName, false)) {
            return false;
        }

        if (is_subclass_of($midgardMixinName, 'MidgardBaseMixin')) {
            return false;
        }

        return true;
    }
    
    public function getDefinition()
    {
        return $this->getPrimaryNodeType();
    }
    
    public function update($srcWorkspace)
    {
        throw new \PHPCR\RepositoryException("Not supported");
    }
    
    public function getCorrespondingNodePath($workspaceName)
    {
        throw new \PHPCR\RepositoryException("Not supported");
    }
    
    public function getSharedSet()
    {
        return new \ArrayIterator(array($this));
    }
    
    public function removeSharedSet()
    {
        throw new \PHPCR\RepositoryException("Not supported");
    }
    
    public function removeShare()
    {
        throw new \PHPCR\RepositoryException("Not supported");
    }
    
    public function isCheckedOut()
    {
        return false;
    }

    public function isLocked()
    {
        return false;
    }

    public function followLifecycleTransition($transition)
    {
        throw new \UnsupportedRepositoryOperationException();
    }

    public function getAllowedLifecycleTransitions()
    {
        throw new \UnsupportedRepositoryOperationException();
    }

    public function getIterator()
    {
        return $this->getNodes();
    }

    public function isSame(ItemInterface $item)
    {
        if (!$item instanceof NodeInterface) {
            return false;
        }

        /* TODO */
        /* Check session */
       
        $thisContentObject = $this->getMidgard2ContentObject();
        if (!$thisContentObject) {
            return false;
        }
        $itemContentObject = $item->getMidgard2ContentObject();
        if (!$itemContentObject) {
            return false;
        }

        if ($thisContentObject->guid == $itemContentObject->guid) {
            return true;
        }

        return false;
    }

    private static function getMidgardRelativePath($object)
    {
        $storage = new \midgard_query_storage('midgard_node');

        $joined_storage = new \midgard_query_storage('midgard_node');
        $left_property_join = new \midgard_query_property('id');
        $right_property_join = new \midgard_query_property('parent', $joined_storage);    

        $q = new \midgard_query_select($storage);
        $q->add_join("INNER", $left_property_join, $right_property_join);

        /* Set name and guid constraints */
        $group = new \midgard_query_constraint_group('AND');
        $group->add_constraint(
            new \midgard_query_constraint(
                new \midgard_query_property('name', $joined_storage), 
                '=', 
                new \midgard_query_value($object->name)
            )
        );
        $group->add_constraint(
            new \midgard_query_constraint(
                new \midgard_query_property('id', $joined_storage), 
                '=', 
                new \midgard_query_value($object->id)
            )
        );

        $q->set_constraint($group);
        
        $q->execute();

        /* Relative path is : $returnedobjects[0]->name / $object->name */

        return $q->list_objects();
    }

    public static function getMidgardPath($object)
    {
        $elements = array();

        /* We expect last object to have up property = 0.
         * Last object is root node. */
        do 
        {
            array_unshift($elements, $object->name);
            $objects = self::getMidgardRelativePath($object, null);
            if (empty($objects))
            {
                break;
            }
            $object = $objects[0];

        } while (!empty($objects));

        return '/' . implode("/", $elements);
    }

    public function move($dstNode, $dstName)
    {
        /* Unset parent's child */
        unset($this->parent->children[$this->getName()]);

        /* Set new parent */
        $this->parent = $dstNode;

        /* Update Midgard2 Node's properties, so it points to valid parent object */
        $this->midgardNode->parent = $dstNode->midgardNode->id;
        $this->midgardNode->parentguid = $dstNode->midgardNode->guid;
        $this->midgardNode->name = $dstName;

        /* Update parent's children */
        $dstNode->children[$dstName] = $this;

        /* Update node's state */
        if (!$this->midgardNode->guid)
        {
            $this->is_new = true;
            $this->is_modified = false;
        }
        else
        {
            $this->is_modified = true;
            $this->is_new = false;
        } 
    }

    public function save()
    {
        $mobject = $this->getMidgard2ContentObject();
        $midgardNode = $this->getMidgard2Node();

        /* Remove self instance if marked such */
        if ($this->is_removed == true) {
            $self::removeFromStorage($this);
            return;
        }

        /* Remove properties marked to be removed */
        if (!empty($this->removeProperties)) {
            foreach ($this->removeProperties as $property) {
                $property->removeMidgard2PropertyStorage($property->getName(), $property->isMultiple());
            }
        }

        if (!$midgardNode->guid) {
            $midgardNode->create();
        }

        if (!$mobject->guid) {
            if ($mobject->create()) { 
                $midgardNode->typename = get_class($mobject);
                $midgardNode->objectguid = $mobject->guid;
                $midgardNode->update();
            }
            else {
                throw new \Exception(\midgard_connection::get_instance()->get_error_string());
            }
        } else {
            if ($mobject->update()) {    
                $midgardNode->update();
            } else {
                throw new \PHPCR\RepositoryException(\midgard_connection::get_instance()->get_error_string());
            }
        }

        $this->is_modified = false;
        $this->is_new = false;

        if (!$this->properties) {
            return;
        }

        foreach ($this->properties as $name => $property) {
            $this->properties[$name]->save();
        }
    }

    public function refresh($keepChanges)
    {
        if ($keepChanges && ($this->isModified() || $this->isNew())) {
            return;
        }

        if ($this->midgardNode->guid) {
            $this->midgardNode = new midgard_node($this->midgardNode->guid);
        }
        $this->is_removed = false;
        $this->removeProperties = array();
        
        if ($this->children) {
            foreach ($this->children as $node) {
                $node->refresh($keepChanges);
            }
        }

        if ($this->properties) {
            foreach ($this->properties as $name => $property) {
                $this->getProperty($name)->refresh($keepChanges);
            }
        }
        $this->contentObject = null;
    }

    public function remove()
    {
        if ($this->is_removed == true) {
            return;
        }

        $this->is_removed = true;
        $this->session->removeNode($this);
    }

    public function removeMidgard2Node()
    {
        self::removeFromStorage($this);
    }

    private function removeFromStorage($node)
    { 
        $mobject = $node->getMidgard2ContentObject();
        $midgardNode = $node->getMidgard2Node();

        /* Remove properties first */
        $node->populateProperties();
        if (!empty($node->midgardPropertyNodes))
        {
            foreach ($node->midgardPropertyNodes as $properties)
            {
                foreach ($properties as $mnp)
                {
                    if (!$mnp->guid) {
                        continue;
                    }

                    $mnp->purge_attachments(true);
                    Repository::checkMidgard2Exception($mnp);
                    if (!$mnp->purge()) {
                        // Object's connection was somehow lost, refresh
                        try {
                            $mnp = new \midgard_node_property($mnp->guid);
                        } catch (\midgard_error_exception $e) {
                            // Object isn't in DB any longer, just skip
                            continue;
                        }
                        $mnp->purge();
                        Repository::checkMidgard2Exception($mnp);
                    }
                }
            }
        }

        /* Remove child objects */
        $children = $node->getNodes();
        foreach ($children as $child)
        {
            $this->removeFromStorage($child);
        }

        if ($mobject->purge() == true)
        {
            $midgardNode->purge();
            Repository::checkMidgard2Exception($midgardNode);
            /* TODO, FIXME, Remove properties from Propertymanager */
        }
    }

    private function removeProperty($name)
    {
        if (!isset($this->properties[$name])) {
            return;
        } 
        $this->removeProperties[] = $this->properties[$name];
        unset($this->properties[$name]);
    }

    /**
     * Shortcut to check if mix:referenceable is value of jcr:mixinTypes
     */
    public function isReferenceable()
    {
        $mixins = $this->getMixinNodeTypes();
        foreach ($mixins as $mixin) {
            if ($mixin->getName() == 'mix:referenceable') {
                return true;
            }
        }
        return false;
    } 
}
