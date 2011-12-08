<?php
namespace Midgard\PHPCR\Query;

use Midgard\PHPCR\Utils\NodeMapper;
use PHPCR\NodeInterface;

class SQLQuery implements \PHPCR\Query\QueryInterface
{
    protected $session = null;
    protected $qs = null;
    protected $statement = null;
    protected $selectors = array();
    protected $node = null;
    protected $converter = null;
    protected $holder = null;
    protected $storageType = null;
    protected $source = null;
    protected $constraint = null;
    protected $orderings = null;
    protected $columns = null;
    protected $nodeTypeName = null;
    
    public function __construct (\Midgard\PHPCR\Session $session, $statement = null, \PHPCR\Query\QOM\SourceInterface $source = null,
            \PHPCR\Query\QOM\ConstraintInterface $constraint = null, array $orderings = null, array $columns = null)
    {
        $this->session = $session;
        $this->statement = $statement;
        $QOMFactory = new QOM\QueryObjectModelFactory($session);
        $this->converter = new \PHPCR\Util\QOM\Sql2ToQomQueryConverter($QOMFactory);
        $this->source = $source;
        $this->constraint = $constraint;
        $this->orderings = $orderings;
        $this->columns = $columns;

        if ($this->statement != null) {
            $query = $this->converter->parse($statement);
            $this->source = $query->getSource();
            $this->constraint = $query->getConstraint(); 
            $this->orderings = $query->getOrderings();
            $this->columns = $query->getColumns();
        }

        if (is_object($this->getSource())) { /* https://github.com/phpcr/phpcr-api-tests/issues/50 */
            $nodeTypeName = "";
            if ($this->getSource() instanceOf \PHPCR\Query\QOM\JoinInterface) 
                $nodeTypeName = $this->getSource()->getLeft()->getNodeTypeName();
            else 
                $nodeTypeName = $this->getSource()->getNodeTypeName();
            $this->storageType = NodeMapper::getMidgardName($nodeTypeName);
            $this->selectors[] = $nodeTypeName;
            $this->nodeTypeName = $nodeTypeName;
        }
    }

    public function getSource()
    {
        return $this->source;
    }

    public function getColumns()
    {
        return $this->columns;
    }

    public function getConstraint()
    {
        return $this->constraint;
    }

    public function getOrderings()
    {
        return $this->orderings;
    }

    public function getSelectors()
    {
        return $this->selectors;
    }

    public function getMidgardStorageName()
    {
        return $this->storageType;
    }

    private function getQuerySelectHolder()
    {
        if ($this->holder == null)
            $this->holder = new Utils\QuerySelectHolder($this);
        return $this->holder;
    }

    /* Add implicit join.
     * We join midgard_node_property.parent on midgard_node.id */
    private function addJoinIDToParent()
    {
        static $joined = false;
        if ($joined == true)
            return;

        $this->getQuerySelect()->add_join(
            'INNER',
            new \midgard_query_property('id'),
            new \midgard_query_property('parent', $this->getPropertyStorage())
        );
        $joined = true;
    }

    private function addOrders()
    {
        $orderings = $this->getOrderings();
        if (empty($orderings))
            return;

        //$this->addJoinIDToParent();

        foreach ($orderings as $order) {
            print_r($order->getOperand()->getPropertyName());
            $constraint = new \midgard_query_constraint (
                new \midgard_query_property ("value"),
                "=",
                new \midgard_query_value ($order->getOperand()->getPropertyName()),
                $this->getQuerySelectHolder()->getPropertyStorage()
            );
            $propertyStorage = new \midgard_query_storage ("midgard_node_property");
            $this->getQuerySelectHolder()->getQuerySelect()->add_join(
                'INNER',
                new \midgard_query_property('id'),
                new \midgard_query_property('parent', $propertyStorage)
            );
            $this->getQuerySelectHolder()->getQuerySelect()->add_order (new \midgard_query_property('value', $propertyStorage));
        }
    }

    public function bindValue($varName, $value)
    {
        throw new \PHPCR\RepositoryException("Not supported");
    }

    private function validate()
    {
        /* TODO , Validate statement if available */

        $ntm = $this->session->getWorkspace()->getNodeTypeManager();
        if (!$ntm->hasNodeType($this->nodeTypeName))
            throw new \PHPCR\Query\InvalidQueryException("Invalid node type " . $this->nodeTypeName);
    }

    public function execute()
    {
        $this->validate();

        $holder = $this->getQuerySelectHolder();
        $manager = Utils\ConstraintManagerBuilder::factory($this, $holder, $this->getConstraint());
        if ($manager != null)
            $manager->addConstraint();

        $qs = $holder->getQuerySelect();
        $this->addOrders();
        $qs->set_constraint($holder->getDefaultConstraintGroup());

        //echo "EXECUTE QUERY : " . $this->statement . "\n";
        //\midgard_connection::get_instance()->set_loglevel("debug");
        $qs->execute();
        //\midgard_connection::get_instance()->set_loglevel("warn");
        return new QueryResult($this, $qs, $this->session);
    }

    public function getBindVariableNames()
    {
        throw new \PHPCR\RepositoryException("Not supported");
    }

    public function setLimit($limit)
    {
        $this->getQuerySelectHolder()->getQuerySelect()->set_limit($limit);
    }
   
    public function setOffset($offset)
    {
        $this->getQuerySelectHolder()->getQuerySelect()->set_offfset($offset);
    }
  
    public function getStatement()
    {
        if ($this->statement == null) {
            $converter = new \PHPCR\Util\QOM\QomToSql2QueryConverter(new \PHPCR\Util\QOM\Sql2Generator());
            $this->statement = $converter->convert($this);
        }
        return $this->statement;
    }
     
    public function getLanguage()
    {
        return \PHPCR\Query\QueryInterface::JCR_SQL2; 
    }
     
    public function getStoredQueryPath()
    {
        if (!$this->node) {
            throw new \PHPCR\ItemNotFoundException("Query not stored"); 
        }
        return $this->node->getPath();
    }

    public function setNode(NodeInterface $node)
    {
        $this->node = $node;
    }

    public function storeAsNode($absPath)
    {
        throw new \PHPCR\RepositoryException("Not supported");
    }

}
