<?php
namespace Midgard2CR;

class Repository implements \PHPCR\RepositoryInterface
{    
    public function login($credentials = NULL, $workspaceName = NULL)
    {
        if (   $credentials instanceof \PHPCR\GuestCredentials
            || is_null($credentials))
        {
            // Anonymous session
            return new Session($this);
        }
        $connection = $this->midgard2Connect();

        /* Create workspace if it doesn't exist and such has been requested */
        if ($workspaceName != null)
        {
            $ws = new \midgard_workspace();
            $wmanager = new \midgard_workspace_manager($connection);
            if ($wmanager->path_exists($workspaceName) == false)
            {
                $ws->name = $workspaceName;
                $wmanager->create_workspace($ws, "");
            }
            else 
            {
                $wmanager->get_workspace_by_path($ws, $workspaceName);   
            }

            $connection->enable_workspace(true);
            $connection->set_workspace($ws);
        }

        $user = $this->midgard2Login($credentials);
        $rootObject = $this->getRootObject($workspaceName);
        
        return new Session($connection, $this, $user, $rootObject);
    }
    
    private function midgard2Connect()
    {
        $mgd = \midgard_connection::get_instance();
        if ($mgd->is_connected())
        {
            return $mgd;
        }

        $filepath = ini_get('midgard.configuration_file');
        $config = new \midgard_config();
        $config->read_file_at_path($filepath);
        $mgd = \midgard_connection::get_instance();
        if (!$mgd->open_config($config))
        {
            throw new \PHPCR\RepositoryException($mgd->get_error_string());
        }
        return $mgd;
    }
    
    private function midgard2Login($credentials)
    {
        // TODO: Handle different authtypes
        $tokens = array
        (
            'login' => $credentials->getUserID(),
            'password' => $credentials->getPassword(),
            'authtype' => 'Plaintext',
            'active' => true
        );
        
        try
        {
            $user = new \midgard_user($tokens);
            $user->login();
        }
        catch (\midgard_error_exception $e)
        {
            throw new \PHPCR\LoginException($e->getMessage());
        }
        
        return $user;
    }
    
    private function getRootObject($workspacename)
    {
        $rootnodes = $this->getRootNodes();
        if (empty($rootnodes))
        {
            throw new \PHPCR\NoSuchWorkspaceException('No workspaces defined');
        }
        return $rootnodes[0];
    }
    
    private function getRootNodes()
    {
        $q = new \midgard_query_select(new \midgard_query_storage('midgardmvc_core_node'));
        $q->set_constraint(new \midgard_query_constraint(new \midgard_query_property('up'), '=', new \midgard_query_value(0)));
        $q->execute();
        return $q->list_objects();
    }
    
    public function getDescriptorKeys()
    {
        return array();
    }
    
    public function isStandardDescriptor($key)
    {
        return false;
    }
    
    public function getDescriptor($key)
    {
        return '';
    }
}
