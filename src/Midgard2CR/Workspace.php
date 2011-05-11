<?php
namespace Midgard2CR;

class Workspace implements \PHPCR\WorkspaceInterface
{
    protected $session = null;
    protected $query_manager = null;
    protected $namespace_registry = null;

    public function Workspace (\Midgard2CR\Session $session)
    {
        $this->session = $session;
    }

    public function getSession()
    {
        throw new \PHPCR\RepositoryException("Not supported");        
    }

    public function getName()
    {
        throw new \PHPCR\RepositoryException("Not supported");        
    }

    public function copy($srcAbsPath, $destAbsPath, $srcWorkspace = NULL)
    {
        throw new \PHPCR\RepositoryException("Not supported");        
    }

    public function klone($srcWorkspace, $srcAbsPath, $destAbsPath, $removeExisting)
    {
        throw new \PHPCR\RepositoryException("Not supported");        
    }

    public function move($srcAbsPath, $destAbsPath)
    {
        throw new \PHPCR\RepositoryException("Not supported");        
    }

    public function getLockManager()
    {
        throw new \PHPCR\RepositoryException("Not supported");        
    }

    public function getQueryManager()
    {
        if ($this->query_manager == null)
        {
            $this->query_manager = new \Midgard2CR\Query\QueryManager($this->session);
        }

        return $this->query_manager;
    }

    public function getNamespaceRegistry()
    {
        if ($this->namespace_registry == null)
        {
            $this->namespace_registry = new \Midgard2CR\NamespaceRegistry($this->session);
        }

        return $this->namespace_registry;
    }

    public function getNodeTypeManager()
    {
        throw new \PHPCR\RepositoryException("Not supported");        
    }

    public function getObservationManager()
    {
        throw new \PHPCR\RepositoryException("Not supported");        
    }

    public function getVersionManager()
    {
        throw new \PHPCR\RepositoryException("Not supported");        
    }

    public function getAccessibleWorkspaceNames()
    {
        throw new \PHPCR\RepositoryException("Not supported");        
    }

    public function getImportContentHandler($parentAbsPath, $uuidBehavior)
    {
        throw new \PHPCR\RepositoryException("Not supported");        
    }

    public function importXML($parentAbsPath, $in, $uuidBehavior)
    {
        throw new \PHPCR\RepositoryException("Not supported");        
    }

    public function createWorkspace($name, $srcWorkspace = NULL)
    {
        throw new \PHPCR\RepositoryException("Not supported");        
    }

    public function deleteWorkspace($name)
    {
        throw new \PHPCR\RepositoryException("Not supported");        
    }
}
