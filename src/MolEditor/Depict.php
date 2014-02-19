<?php
// depict.php
// IMPORTANT - REQUIREMENT: library GLIBC_2.14 required by indigo-cano 
namespace MolEditor;

Class Depict
{
	public function __construct()
	{
	}

	// Depiction management using Indigo tools (from GGAsoftware) 
	public function getSVG($mol, $type='svg')
	{
      // browser detection (fallback for IE<8)
        if (isset ($_SERVER['HTTP_USER_AGENT']))
        {
            $browser = $_SERVER['HTTP_USER_AGENT'];
            if (preg_match ('/MSIE [6-8]\.0/', $browser) || preg_match ('/Firefox\/[2-3]\.[0-9]/', $browser) || preg_match ('/WebKit\/[4-5]/', $browser))
            {
                $type='png';
            }
        }

		$indigo_depict_path = 'cd '.__DIR__.'/../software;';
		$taille=250;
		$rand=rand(0,10000000);
		$molfile_path= __DIR__.'/../tmp/mol'.$rand;
		$f=fopen ($molfile_path.'.mol', 'w+');
		fputs ($f, $mol);
		fclose($f);

		exec ($indigo_depict_path.' ./indigo-depict_64 '. $molfile_path.'.mol '.$molfile_path.'.'.$type.' -w '.$taille .' -h '.$taille .' -thickness 1.3 -margins  20 20');

		if (is_file($molfile_path.'.'.$type))
		{
			if ($type=='svg')
			{
				$pre_out = file_get_contents($molfile_path.'.'.$type);
				$pre_out2 = str_replace ('<?xml version="1.0" encoding="UTF-8"?>', '', $pre_out);
			// add a salt to 'glyph' term in SVG, else def of first structure in the page (color, spacing...) are used for all other molecules, and drawings are incorrects
				$out = preg_replace ('/glyph/', 'glyph'.$rand, $pre_out2);
				unlink($molfile_path.'.'.$type);
			}
			else
			{
                $out = 'No SVG support';
			}
		}
		else
		{
			$out="No structure";
		}
		unlink($molfile_path.'.mol');
		return $out;  
	}

	// Smiles to SDF convertion using indigo
	public function convertSmilesFileToSdfFile($smi_path)
	{
		$indigo_depict_path = 'cd '.__DIR__.'/../software;';
		if (is_file($smi_path))
		{
			$molfile_path = __DIR__.'/../tmp/'.basename($smi_path, '.smi').'.sdf';
			exec ($indigo_depict_path.' ./indigo-depict_64 '. $smi_path.' '.$molfile_path);

			if (is_file($molfile_path))
			{
				$sdf = file_get_contents($molfile_path);
				unlink($smi_path);
			}
		}

		return true;    

	}

	// SDF to Smiles using indigo-cano (used for Ambinter availability checking and smiles exportations)
	public function getSmiles($mol)
	{
		$indigo_cano_path = 'cd '.__DIR__.'/../software;';
		$smiles='';
		$molfile_path= __DIR__.'/../tmp/mol';
		$f=fopen ($molfile_path.'.mol', 'w+');
		fputs ($f, $mol);
		fclose($f);
		// IMPORTANT - REQUIREMENT: library GLIBC_2.14 required by indigo-cano 
		exec ($indigo_cano_path.' ./indigo-cano_64 '. $molfile_path.'.mol >'.$molfile_path.'.smi');
		if (is_file($molfile_path.'.smi'))
		{
			$smiles = file_get_contents($molfile_path.'.smi');
			unlink($molfile_path.'.smi');
		}
		unlink($molfile_path.'.mol');
		return $smiles;  

	}
}
