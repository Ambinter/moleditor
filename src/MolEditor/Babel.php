<?php
// babel.php
namespace MolEditor;

Class Babel
{
	public function __construct()
	{
		
	}

	// function for compute descriptors
	public function descriptors($input, $descriptors)
	{
		// Managing of aliases for descriptors names between displaying in moleditor column management form and keyword use in openbabel
		$descriptors_alias = array ('Formula'=>'formula', 'Mol Weight'=>'MW', 'logP'=>'logP', 'Acceptor'=>'HBA2', 'Donor'=>'HBD');
		foreach ($descriptors as $k=>$descriptor)
		{
			if (isset($descriptors_alias[$descriptor]))
			{
				$descriptors_reverse_alias[$descriptor]=$descriptor;
				$descriptors[$k]=$descriptors_alias[$descriptor];
			}
		}
		$output= __DIR__.'/../tmp/desc'.rand(0,1000000).'.txt';
		exec ('obabel '.$input.' -O '. $output.' --append "'.implode(' ', $descriptors).'"');

		if (file_exists($output))
		{
			$file=file ($output);
			$i=0;
			foreach ($file as $line)
			{
				$explode = explode(' ', $line);
				$id=$explode[0];
				array_shift($explode);
				foreach ($descriptors as $k=>$desc)
				{
					if (isset ($descriptors_reverse_alias[$descriptor]))
					{
						$desc=$descriptors_reverse_alias[$descriptor];
					}
					if (isset ($explode[$k]))
					{
						$tabdesc[$id][$desc]=trim($explode[$k]);
					}
				}
			}
			unlink($output);
			return $tabdesc;
		}
	}

	public function get3D($input, $type='3D')
	{
		$output= __DIR__.'/../tmp/3d'.rand(0,1000000).'.sdf';
		exec ('obabel '.$input.' -O '. $output.' --gen'.$type);
		if (file_exists($output))
		{
			$sdf=file_get_contents($output);
			unlink($output);
			return $sdf;
		}
		return false;
	}
}
