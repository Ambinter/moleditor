<?php
// Access.php
namespace MolEditor;
use Silex\Provider\DoctrineServiceProvider;
use Silex\Application;

Class Access
{
	public function __construct (Application $app)
	{
		$this->app=$app;
	}

	// function to get a hash (public or private)
	public function getHash($key, $privateOnly='')
	{
		$hash='';
		$req = $this->app['db']->prepare('SELECT hash, private FROM user_db WHERE hash=:hash OR private=:private OR public=:public');
		$req->bindValue(':private', $key);
		$req->bindValue(':public', $key);
		$res=$req->execute();
		while ($row = $req->fetch(\PDO::FETCH_ASSOC))
		{
			// Protection against use of direct route if no admin access > error message and exit
			if($privateOnly)
			{
				if ($key!=$row['private'])
				{
					echo 'You have not access to this page !';
					exit();
				}
				
			}
			$hash=$row['hash'];
		}
		return $hash;
	}
	
	public function getKeyType($key)
	{
		$hash='';
		$req = $this->app['db']->prepare('SELECT public, private FROM user_db WHERE hash=:hash OR private=:private OR public=:public');
		$req->bindValue(':private', $key);
		$req->bindValue(':public', $key);
		$res=$req->execute();
		while ($row = $req->fetch(\PDO::FETCH_ASSOC))
		{
			if ($key==$row['private'])
			{
				return 'private';
			}
			elseif ($key==$row['public'])
			{
				return 'public';
			}
		}
		return false;
	}
}
