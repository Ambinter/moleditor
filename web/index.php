<?php

/*****************************************************************************
    Copyright 2013 - Ambinter (a brand of Greenpharma)
    contact: sylvain.blondeau@greenpharma.com

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see http://www.gnu.org/licenses
**************************************************************************** */

// web/index.php
use Silex\Provider\FormServiceProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;
use MolEditor\Depict;
use MolEditor\Babel;

require_once __DIR__.'/../vendor/autoload.php';
$app = new Silex\Application();


#### DEFINITIONS #######
$app['debug'] = true;

// twig
$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/../src/views',
));

// Form
$app->register(new FormServiceProvider());
$app->register(new Silex\Provider\UrlGeneratorServiceProvider());
$app->register(new Silex\Provider\ValidatorServiceProvider());
$app->register(new Silex\Provider\TranslationServiceProvider(), array(
    'translator.messages' => array(),
));

// Doctrine for SQLite 
$app->register(new Silex\Provider\DoctrineServiceProvider(), array(
    'db.options' => array(
        'driver'   => 'pdo_sqlite',
        'path'     => __DIR__.'/../src/db/molEditor.db',
    ),
));

// hinclude for sub_request
$app->register(new Silex\Provider\HttpFragmentServiceProvider()
);
$app['fragment.renderer.hinclude.global_template'] = 'loading.twig';

// session
$app->register(new Silex\Provider\SessionServiceProvider());
$app['session']->start();

// The token is the session ID (you could implement an authentication form instead)
$app['token'] = $app['session']->getId();


// MolEditor specific services for depiction and openbabel use
$app['depict'] = $app->share(function () {
    return new Depict();
});
$app['babel'] = $app->share(function () {
    return new Babel();
});


#### CONTROLLERS ####


// form for database creation
$app->match('/', function () use ($app) {
    $app['session']->set('config', '');
    $token = $app['token'];
    $form = $app['form.factory']->createBuilder('form')
        ->add('dbname', 'text', array('attr'=>array('placeholder'=>'myFirstBase',
						    'class'=>'input-lg form-control text-info')))
        ->getForm();

    $request = $app['request'];

    if ($request->isMethod('POST'))
    {
        $form->bind($request);
        if ($form->isValid())
        {
            $data = $form->getData();
            $dbname = $data['dbname'];
	    // replace spaces in database name 
	    $dbname = str_replace(' ', '_', $dbname);
	    
	    // error if database name start with a digit
	    if (preg_match('/^[0-9]/', $dbname))
	    {
		$app['session']->getFlashBag()->add(
		    'danger',
		    'Invalid work name '.$dbname.'. Please do not start it with a number !'
		);
		return $app->redirect('/moleditor/web/');
	    }

	    // requests for database creation
	    $results = $app['db']->executeQuery('DROP TABLE IF EXISTS '.$dbname.$token.'_columns ');
	    $results = $app['db']->executeQuery('CREATE TABLE '.$dbname.$token.'_columns (column_name TEXT, column_order INT, alias INT, type VARCHAR(20), coltype VARCHAR(20))');
	    $results = $app['db']->executeQuery('DROP TABLE IF EXISTS '.$dbname.$token.'_sdf ');
	    $results = $app['db']->executeQuery('CREATE TABLE '.$dbname.$token.'_sdf ( ID INTEGER PRIMARY KEY, structure TEXT, header TEXT, availability TEXT)');
	    $app['session']->set('modal', 'import');
	    
	    return $app->redirect('/moleditor/web/display/'.$dbname);           
	    
        }
    }
    return $app['twig']->render('index.twig', array(
        'form' => $form->createView()
    ));
})->bind('index');


// List of current database of user (displayed in header navbar)
$app->match('/db/list', function () use ($app) {
    $token = $app['token'];
    $req = $app['db']->prepare('SELECT name FROM sqlite_master WHERE name like :token ORDER BY name');
    $req->bindValue(':token', '%'.$token.'%');
    $res=$req->execute();
    $dbs='';
    while ($row = $req->fetch(PDO::FETCH_ASSOC))
    {
	$db=str_replace($token, '', $row['name']);
	$db=str_replace('_columns', '', $db);
	$db=str_replace('_sdf', '', $db);
	$dbs[$db]=$db;
    }
    return $app['twig']->render('listDB.twig', array('dbs'=>$dbs));
})->bind('listDB');



// Database configuration (rename/delete)
$app->match('/database/config/{dbname}', function ($dbname) use ($app) {
    $app['session']->set('config', '');
    $token = $app['token'];
    $formdata['dbname']=$dbname;
    $form = $app['form.factory']->createBuilder('form', $formdata)
    ->add('dbname', 'text', array('required'=>true,
				    'attr'=>array('class'=>' '),
				    'label'=>'New database name'
				    )
	)
    ->getForm();
    $request = $app['request'];
    if ($request->isMethod('POST'))
    {
        $form->bind($request);
        if ($form->isValid())
        {
            $data = $form->getData();
	    $new_dbname=$data['dbname'];

	// replace spaces in new database name
	    $new_dbname = str_replace(' ', '_', $new_dbname);

	// error if new database name start with a digit
	    if (preg_match('/^[0-9]/', $new_dbname))
	    {
		$app['session']->getFlashBag()->add(
		    'danger',
		    'Invalid name '.$new_dbname.'. Please do not start it with a number !'
		);
		return $app->redirect('/moleditor/web/display/'.$dbname);
	    }
	    
	// check if name did not exist yet
	    $req = $app['db']->prepare('SELECT name FROM sqlite_master WHERE name like :token ORDER BY name');
	    $req->bindValue(':token', '%'.$token.'%');
	    $res=$req->execute();
	    $dbs='';
	    while ($row = $req->fetch(PDO::FETCH_ASSOC))
	    {
		$db=str_replace($token, '', $row['name']);
		$db=str_replace('_columns', '', $db);
		$db=str_replace('_sdf', '', $db);
		$dbs[$db]=$db;
	    }
	    if (in_array($new_dbname, $dbs))
	    {
		$app['session']->getFlashBag()->add(
		    'danger',
		    'The database name '.$new_dbname.' already exist, please choose another one !'
		);
		return $app->redirect('/moleditor/web/display/'.$dbname);
	    }

	// request for updating database name
	    $app['db']->executeQuery('ALTER TABLE '.$dbname.$token.'_columns RENAME TO '.$new_dbname.$token.'_columns');
	    $app['db']->executeQuery('ALTER TABLE '.$dbname.$token.'_sdf RENAME TO '.$new_dbname.$token.'_sdf');

	    $app['session']->getFlashBag()->add(
		'success',
		'The database "'.$dbname.'" has been successfully renamed in "'.$new_dbname.'"!'
	    );
	    return $app->redirect('/moleditor/web/display/'.$new_dbname);           
	}
    }
    return $app['twig']->render('dbConfig.twig', array(
        'form' => $form->createView(), 'dbname'=>$dbname
    ));

})->bind('dbConfig');


// delete database
$app->get('/database/delete/{dbname}', function ($dbname) use ($app) {
    $token = $app['token'];
    $results = $app['db']->executeQuery('DROP TABLE IF EXISTS '.$dbname.$token.'_columns ');
    $results = $app['db']->executeQuery('DROP TABLE IF EXISTS '.$dbname.$token.'_sdf ');
    $app['session']->getFlashBag()->add(
	'success',
	'The database '.$dbname.' has been deleted !'
    );
   return $app->redirect('/moleditor/web/');           

})->bind('deleteDB');


// form for file importation
$app->match('/import/{dbname}', function ($dbname) use ($app) {
    $app['session']->set('config', '');
    $token = $app['token'];
    $form = $app['form.factory']->createBuilder('form')
        ->add('FileUpload', 'file', array('required'=>true,
					  'attr'=>array('class'=>'')
					)
	    )
        ->getForm();

    $request = $app['request'];

    // $valid check if upload is done. If yes, it propose tags selection when SDF is imported
    if ($request->isMethod('POST'))
    {
        $form->bind($request);
        if ($form->isValid())
        {
            $data = $form->getData();
            $files = $request->files->get($form->getName());

	// if file exist, it is renamed and moved
	    if ($files['FileUpload'])
	    {
		$path = __DIR__.'/../src/tmp/';
		$extension = strtolower($files['FileUpload']->getClientOriginalExtension());
		$filename = $dbname.$token;

		// file extension checking (only sdf or smiles are allowed)
		if(in_array($extension, array('sdf', 'smi')))
		{
		    $files['FileUpload']->move($path,$filename.'.'.$extension);
		    if ($extension=='smi')
		    {
			$app['depict']->convertSmilesFileToSdfFile($path.$filename.'.'.$extension);	
		    }
		    $modal = $app['session']->set('modal', 'import2');
		    return $app->redirect('/moleditor/web/display/'.$dbname);           
		}
		else
		{
		    $app['session']->getFlashBag()->add(
			'danger',
			'Importation error: The file extenstion in unknown. Allowed extensions are .sdf and .smi !'
		    );
		    $modal = $app['session']->set('modal', '');
		    return $app->redirect('/moleditor/web/display/'.$dbname);           

		}
	    }
        }
	// error if upload problem is detected
	else
	{
	    $app['session']->getFlashBag()->add(
		'danger',
		'Upload error !'
	    );
	    $modal = $app['session']->set('modal', 'import');
	    return $app->redirect('/moleditor/web/display/'.$dbname);           
	}
    }
    return $app['twig']->render('import.twig', array(
        'form' => $form->createView(), 'dbname'=>$dbname
    ));
})->bind('import');


// importation step 2 : get and select tags
$app->match('/import2/{dbname}', function ($dbname) use ($app) {
    $app['session']->set('config', '');
    $token = $app['token'];

    $request = $app['request'];
    $path = __DIR__.'/../src/tmp/'. $dbname.$token.'.sdf';

    $nb_mol=0;
    $exist_tag=null;

    // error if file does not exist
    if (!is_file($path))
    {
	$app['session']->getFlashBag()->add(
	    'danger',
	    'Upload problem, the file does not exit!'
	);
	return $app->redirect('/moleditor/web/');           
    }
    // if file exist, data are parsed and database is hydrated 
    else
    {
	$file = file ($path);
	foreach ($file as $line)
	{
	    // get SDF tags
	    if (preg_match('/^>\s+<(.+)>/', $line, $preg_res))
	    {
		$tag=$preg_res[1];
		$tags[$tag]=$tag;
	    }
	    // detect end of molecule in SDF
	    if (trim($line)=='M  END')
	    {
		$nb_mol++;
	    }
	}
	
	$preselect=null;
	$i=0;
	if (isset($tags))
	{
	    foreach ($tags as $tag)
	    {
		$preselect['existTag'.$i]=$tag;
		$i++;
	    }
	}
	
	//get existing tag in database (when it is not the first file import in the DB)
	$res = $app['db']->executeQuery('SELECT alias, column_name FROM '.$dbname.$token.'_columns WHERE type="tag"');
	foreach($res as $result)
	{
	    $colname=$result['column_name'];
	    $alias=$result['alias'];
	    $exist_tag[$colname]=$colname;
	}
	$i=0;

	// form where the tags will be displayed and propose to user. He could choose to import or not a tag and make correspondance between new and existing files. 
	$builder = $app['form.factory']->createBuilder('form', $preselect);
	if (isset ($tags))
	{
	    foreach ($tags as $tag)
	    {
		$builder->add('suppr'.$i, 'checkbox', array('required'=>false,
							'label'=>$tag,
							'attr'=>array('checked'=>'checked'),
							
							)
		);
		if (is_array($exist_tag))
		{
		    $builder->add('existTag'.$i, 'choice', array('required'=>false,
							 'attr'=>array('class'=>''),
							 'choices'=>$exist_tag,
    					    )
		    );
		}
		$i++;
	    }
	}
    }
    $form=$builder->getForm();

    // if form is submit
    if ($request->isMethod('POST'))
    {	
	$form->bind($request);
	// and valid
	if ($form->isValid())
	{
	    $data = $form->getData();
	    $i=0;

	// rename tag with name already in database in "name_2", "name_3"...
	    $res = $app['db']->executeQuery('SELECT alias, column_name FROM '.$dbname.$token.'_columns WHERE type="tag"');
	    foreach($res as $result)
	    {
		$colname=$result['column_name'];
		$existingCol[]=$colname;
	    }

	    if (isset($tags))
	    {
		foreach ($tags as $tag)
		{
		    $name='';
		    if (isset ($data['existTag'.$i]))
		    {
			$name=$data['existTag'.$i];
		    }
		    $importTags[$tag]['suppr']=$data['suppr'.$i];
		    $importTags[$tag]['name']=$name;
	    
		    $i++;
		}
	    }
	    
	// database hydratation with uploaded file
	    $i=0;
	    $m_end=0;
	    $next=1;
	    $header='';
	    $firstline=0;
	    $tag='';
	    $renamed_tag = '';
	    $i=0;
	    $fileok=0;
	    foreach ($file as $line)
	    {
		if ($firstline==0)
		{
		    $tab[$i]["header"]=trim($line);
		    $firstline=1;
		}
		elseif(!$m_end)
		{
		    if (!isset($tab[$i]["structure"]))
		    {
			$tab[$i]["structure"]='';
		    }
		    $tab[$i]["structure"].=$line;
		}

		$line=trim($line);
		if ($next==1 && $tag)
		{
		    $columns[$tag]=Array('type'=>'tag');
		    $tab[$i][$tag]=trim($line);
		    $next=0;
		    $tag='';
		}

		if ($line=='M  END')
		{
		    $m_end=1;
		    $fileok=1;
		}
		if (preg_match('/^>\s+<(.+)>/', $line, $preg_res))
		{
		    $tag=trim($preg_res[1]);

		    // depending of tag selection in form, tag is ignored, new column is created or tag is renamed to match other selected column name
		    if(isset($importTags[$tag]))
		    {
			// si on a coché, on import le tag
			if ($importTags[$tag]['suppr'])
			{		
			    // rename si on a selectionné un field pré-existant
			    if ($importTags[$tag]['name'])
			    {
				$tag=$importTags[$tag]['name'];
			    }
			    elseif(isset($existingCol))
			    {
				$doublon_count=2;
				$pretag=$tag;
				while(in_array($tag, $existingCol))
				{
				    $tag=$pretag.'_'.$doublon_count;
				    $doublon_count++;
				}
			    }
			}
			else
			{
			    $tag='';
			}
		    }
		    
		// bug if dash in column name
		    $tag=str_replace('-','_',$tag);
		    
		// rename tag id = 'ID' or 'structure' or 'header' or 'availability'
		    if (in_array($tag, array('ID','structure','header','availability')))
		    {
			$renamed_tag[$tag]=$tag;
			$tag = $tag.'_2';
		    }
		    
		    $next=1;
		}

		// detect end of molecules in sdf file
		if ($line=='$$$$')
		{        
		    $i++;
		    $m_end=0;
		    $firstline=0;
		}
	    }

	    // Check if at least one "M END" in the SDFile, else error and redirection to home page
	    if (!$fileok)
	    {
		$app['session']->getFlashBag()->add(
		    'danger',
		    'Invalid file structure. Please check if it is a correct SDF format !'
		    );
		return $app->redirect('/moleditor/web/');           
	    }

	    // Alert to inform of renamed tags
	    if ($renamed_tag)
	    {
		foreach ($renamed_tag as $rtag)
		{
		    $app['session']->getFlashBag()->add(
			'warning',
			'The SDF tag '.$rtag.' could not be used in MolEditor, it has been automatically renamed in '.$rtag.'_2 !'
			);
		}
	    }

	// if no "M END" at the very end of file (eg last line empty), last 'mol' is deleted if structure is null (else file with only one mol are not correctly record)
	    $last=count($tab)-1;
	    if ($m_end==0)
	    {
		if (!isset($tab[$last]['structure']))
		{
		    array_pop($tab);
		}
		elseif (!$tab[$last]['structure'])
		{
		    array_pop($tab);
		}
	    }
	    $nb_mol=$i;

	// Hydrate colums
	    $i=0;
	    $req = $app['db']->executeQuery('SELECT * FROM '.$dbname.$token.'_columns');
	    $result = $req->fetchAll();
	    $insert_columns=$columns;
	    if ($result)
	    {
		foreach ($result as $row)
		{
		    $col_name=$row['column_name'];
		    // If one column already exist, it is not create again in column table
		    if (isset($insert_columns[$col_name]))
		    {
			unset($insert_columns[$col_name]);
		    }

		    $aliases[]=$row['alias'];
		    $i++;
		}
	    }
	    $maxalias=0;

	// column name are inserted in column table
	    if (isset ($insert_columns))
	    {
		$req = $app['db']->prepare('INSERT INTO '.$dbname.$token.'_columns (column_name, column_order, alias, type, coltype) VALUES (:column,:order,:alias,:type,:coltype)');
		if (isset ($aliases))
		{
		    $maxalias=max($aliases)+1;
		}
		foreach ($insert_columns as $col=>$val)
		{			
		    $fields[]='col'.$maxalias.' TEXT';
		    
		    $req->bindValue(':column', $col);
		    $req->bindValue(':order', $i);
		    $req->bindValue(':alias', $maxalias);
		    $req->bindValue(':type', $val['type']);
		    $req->bindValue(':coltype', 'text');
		    $alter_tab[]=$maxalias;
		    $i++;
		    $maxalias++;
		    $req->execute();
		}
		if (isset($alter_tab))
		{
		    foreach($alter_tab as $al)
		    {
			$app['db']->executeQuery('ALTER TABLE '.$dbname.$token.'_sdf ADD COLUMN col'.$al.' TEXT');
		    }
		}
	    }

	    // get column list after column table update
	    $req = $app['db']->executeQuery('SELECT * FROM '.$dbname.$token.'_columns WHERE type="tag"');
	    $result = $req->fetchAll();
	    if ($result)
	    {
		foreach ($result as $row)
		{
		    $colname=$row['column_name'];
		    $alias=$row['alias'];
		    $aliascol[$colname]=$alias;
		    $table_colnames[]=$colname;		
		    $cols[]='col'.$alias;		
		    $var[]=':col'.$alias;		
		}
	    }

	    $addfields=$addvars='';
	    if (isset($cols))
	    {
		$addfields = ', '.implode(', ',$cols);
		$addvars = ', '.implode(', ',$var);
	    }

	// Data insertion in database (structure, header and tags values)
	    $app['db']->executeQuery('BEGIN TRANSACTION');

	    foreach ($tab as $k=>$v)
	    {
		$req = $app['db']->prepare('INSERT INTO '.$dbname.$token.'_sdf (structure, header '. $addfields .') VALUES (:structure, :header'. $addvars.')');
		$req->bindValue(':structure', $tab[$k]["structure"]);
		$req->bindValue(':header', $tab[$k]["header"]);
		if (isset ($table_colnames))
		{
		    foreach ($table_colnames as $col)
		    {
			if (!isset ($tab[$k][$col]))
			{
			    $req->bindValue(':col'.$aliascol[$col], '');
			}
			else
			{
			    $req->bindValue(':col'.$aliascol[$col], $tab[$k][$col]);
			}
		    }
		}
		$req->execute();
	   }

	// update of exsisting descriptors type columns after a new insertion
	    $req=$app['db']->executeQuery('SELECT column_name, alias FROM '.$dbname.$token.'_columns WHERE type="descriptor"');
	    $res = $req->fetchAll();
	    foreach ($res as $val)
	    {
		$colname=$val['column_name'];
		$descriptors[]= $colname;
		$col_descriptors[$colname]=$val['alias'];
	    }
	    
	    // existing descriptors are re-computed
	    if (isset($descriptors))
	    {
		$req=$app['db']->executeQuery('SELECT ID, structure FROM '.$dbname.$token.'_sdf');
		$sdf='';
		while ($tab = $req->fetch(PDO::FETCH_ASSOC))
		{
		    $struct= $tab['structure'];
		    $sdf.= $tab['ID']."\n";
		    $sdf.= $tab['structure']."\n\n";
		    $sdf.="$$$$\n";
		}

	    // Descriptors computation
		// creation of temporary SDF file
		$desc_sdf_path = __DIR__.'/../src/tmp/sdfdesc'.$dbname.$token.'.sdf';
		$f = fopen ($desc_sdf_path, 'w+');
		fwrite ($f, $sdf);
		fclose($f);
		
		if (file_exists ($desc_sdf_path))
		{
		    $tabdesc=$app['babel']->descriptors($desc_sdf_path, $descriptors);
		    unlink($desc_sdf_path);
		}
		foreach($tabdesc as $id => $descval)
		{
		    foreach ($descval as $desc=>$val)
		    {
			echo $col_descriptors[$desc];
			$req = $app['db']->prepare('UPDATE '.$dbname.$token.'_sdf SET col'.$col_descriptors[$desc].'=:val WHERE ID=:id' );
			$req->bindValue(':id', $id);
			$req->bindValue(':val', $val);
			$req->execute();
		    }
		}
	    }
	    $app['db']->executeQuery('END TRANSACTION');

	    // delete SDF file after insertion in DB
	    unlink($path);

	    return $app->redirect('/moleditor/web/display/'.$dbname);
	}
    }
    
    $modal = $app['session']->set('modal', '');

    $nb_tags=0;
    if (isset($tags))
    {
	$nb_tags=count($tags);
    }

    // display IMPORT2 modal form
    return $app['twig']->render('sdfTagsForm.twig', array(
        'form' => $form->createView(), 'dbname'=>$dbname, 'nb_mol'=>$nb_mol, 'nb_tags'=>$nb_tags, 'existTags'=>$exist_tag
    ));
    
})->bind('import2');



// Table sort 
$app->get('/sort/{dbname}/{sort}/{dir}', function ($dbname, $sort, $dir) use ($app) {
    $config=$app['session']->get('config');
    $config['sort']=$sort;
    $config['dir']=$dir;
    $app['session']->set('config', $config);
    return $app->redirect('/moleditor/web/display/'.$dbname);           
})->bind('sort');



//pagination
$app->get('/page/{dbname}/{offset}', function ($dbname, $offset) use ($app) {
    $config=$app['session']->get('config');
    $config['offset']=$offset;
    if ($offset<=0)
    {
	$offset=0;
    }
    $app['session']->set('config', $config);
    return $app->redirect('/moleditor/web/display/'.$dbname);           
})->bind('page');



// thumbnail view 
$app->get('/preview/{dbname}/{offset}', function ($dbname, $offset) use ($app) {
    $app['session']->set('config', null);
    $token = $app['token'];
    $result = $app['db']->fetchAll('SELECT ID, structure, header FROM '.$dbname.$token.'_sdf LIMIT '.($offset*200) .', 200');
    $structures='';
    foreach ($result as $row)
    {
	$structure = $row['structure'];
	$header = $row['header'];
	if(!$header)
	{	
	    $header="";
	}
	$id = $row['ID'];
	$structures[$id] = array('header'=>$header, 'structure'=>$app['depict']->getSVG($header."\n".$structure));  
    }
    $res = $app['db']->fetchAll('SELECT count(*) nb FROM '.$dbname.$token.'_sdf');
    $nbmol=0;
    foreach ($res as $row)
    {
       $nbmol= $row['nb'];
    }
    
    return $app['twig']->render('preview.twig', array(
        'dbname'=>$dbname, 'structures' => $structures, 'offset'=>$offset, 'nbmol'=>$nbmol
    ));
})->bind('preview');



//preview one mol (in thumbnail view)
$app->get('/preview-one/{dbname}/{id}', function ($dbname, $id) use ($app) {
    $app['session']->set('config', null);
    $token = $app['token'];
    $req = $app['db']->prepare('SELECT * FROM '.$dbname.$token.'_sdf WHERE ID=:id');
    $req->bindValue(':id', $id);
    $req->execute();
    while ($tab = $req->fetch(PDO::FETCH_ASSOC))
    {
	foreach ($tab as $key=>$val)
	{
	    $tag='';
	    if ($key=='ID')
	    {
		$tag='ID';
	    }
	    elseif ($key=='header')
	    {		
		$tag='header';
	    }
	    elseif ($key=='structure')
	    {
		$val= $app['depict']->getSVG("header\n".$val);
		$tag='structure';
	    }
	    else
	    {
		$reqc = $app['db']->prepare('SELECT column_name FROM '.$dbname.$token.'_columns WHERE alias=:colnum');
		$reqc->bindValue(':colnum', str_replace('col', '', $key));
		$resc=$reqc->execute();
		while ($row = $reqc->fetch(PDO::FETCH_ASSOC))
		{
		    $tag=$row['column_name'];
		}
	    }
	    if ($tag)
	    {
		$structures[$tag] = $val;
	    }
	}  
    }
    $offset = $app['request']->get('offset');

    return $app['twig']->render('previewOne.twig', array(
        'dbname'=>$dbname, 'structures' => $structures, 'offset'=>$offset
    ));
})->bind('previewOne');


// Search reset
$app->match('/display/{dbname}/reset-search', function ($dbname) use ($app) {
    $config=$app['session']->get('config');
    $config['clausewhere']='';
    $app['session']->set('config', $config);
    return $app->redirect('/moleditor/web/display/'.$dbname);   
})->bind('reset');


// Search form
$app->match('/display/{dbname}/search-by', function ($dbname) use ($app) {
    $config=$app['session']->get('config');

    $token = $app['token'];
    $result = $app['db']->fetchAll('SELECT column_name, alias,column_order, type, coltype FROM '.$dbname.$token.'_columns ORDER BY column_order');
    
    $colselect['header']='header';
    $tabcoltype['header']='text';
    $tabcoltype['ID']='numeric';
    foreach ($result as $row)
    {
	// Get the column name from the results
	$row_name=$row['column_name'];
	$al = 'col'.$row['alias'];
	$colselect[$al]=$row_name;
	$tabcoltype[$al]=$row['coltype'];
    }
    
    $datasearch=array();
    $clausewhere = $config['clausewhere'];

    if (preg_match ('/^WHERE (.+) [LIKE<>=]+ (.+)$/', $clausewhere, $res))
    {
	$datasearch['col']=$res[1];
	$datasearch['search']=preg_replace ('/[%"]/', '', $res[2]);
    }
    
    $request = $app['request'];

    // form (dynamic according to column type, numeric or text)
    $builder = $app['form.factory']->createBuilder('form', $datasearch);
    $builder->add('searchtypenumeric', 'choice', array('choices'=>array('sup'=>'>', 'supe'=>'>=', 'exact'=>'=','infe'=>'<=', 'inf'=>'<')));
    $builder->add('searchtypetext', 'choice', array('choices'=>array('start'=>'Start with', 'contains'=>'Contains', 'end'=>'End with','exact'=>'Exact')));
    
    $builder->add('col', 'choice', array('choices'=>$colselect))
	    ->add('search', 'search', array('attr'=>array('class'=>'input-sm form-control')));
    $formsearch = $builder->getForm();
    
    if ($request->isMethod('POST'))
    {
	$formsearch->bind($request);
	if ($formsearch->isValid())
	{
	    $data = $formsearch->getData();
	    $search=$data['search'];
	    $col=$data['col'];
	    $searchNum=$searchText='';

	    $colType=$tabcoltype[$col];

	    $searchType=$data['searchtype'.$colType];
	    if ($search)
	    {
		$searchEq = 'LIKE';
		if ($searchType=='start')
		{
		    $search .= '%';
		}
		if ($searchType=='end')
		{
		    $search = '%'.$search;
		}
		if ($searchType=='contain')
		{
		    $search = '%'.$search.'%';
		}

		if ($searchType == 'exact')
		{
		    $searchEq = '=';
		}

		if($searchType == 'sup')
		{
		    $searchEq = '>';		    
		}
		if($searchType == 'supe')
		{
		    $searchEq = '>=';		    
		}
		if($searchType == 'inf')
		{
		    $searchEq = '<';		    
		}
		if($searchType == 'infe')
		{
		    $searchEq = '<=';		    
		}
		
		if ($colType=='text')
		{
		    $search = '"'.$search.'"';
		}			
		if ($colType=='numeric')
		{
		    $col = 'CAST('.$col.' AS INT)';
		}	
		$clausewhere = 'WHERE '.$col.' '.$searchEq.' '.$search;
		//echo $clausewhere;

		// enregistrement de la clausewhere dans les var de session
		$config['offset']=0;
		$config['clausewhere']=$clausewhere;
		$app['session']->set('config', $config);
		
		return $app->redirect('/moleditor/web/display/'.$dbname);
	    }
	}
    }  

    return $app['twig']->render('searchForm.twig', array(
        'dbname'=>$dbname, 'coltypes'=>$tabcoltype, 'formsearch'=>$formsearch->createView()
    ));

})->bind('search');



// Database display (in HTML Table)
$app->match('/display/{dbname}', function ($dbname) use ($app) {
    $token = $app['token'];

    // Session variable initialization
    if ($app['session']->get('config') === null)
    {
	$app['session']->set('config', array('offset'=>0, 'dir'=>'asc', 'sort'=>'ID'));
    }	
    if ($app['session']->get('availability') === null)
    {
	$app['session']->set('availability', 1);
    }
    $config=$app['session']->get('config');
    
    // clausewhere for search request
    if (!isset($config['clausewhere']))
    {
	$clausewhere='';
    }
    else
    {
	$clausewhere=$config['clausewhere'];
    }

    $lim=10;
    
    $result = $app['db']->fetchAll('SELECT column_name, alias,column_order, type, coltype FROM '.$dbname.$token.'_columns ORDER BY column_order');
    $columns = array();
    $alias = array();

    $coltab['ID'] = array ('alias'=>'ID', 'name'=>'ID', 'type'=>'fixed', 'coltype'=>'numeric');
    $coltab['structure'] = array ('alias'=>'structure', 'name'=>'structure', 'type'=>'fixed', 'coltype'=>'text');
    $coltab['header'] = array ('alias'=>'header', 'name'=>'header', 'type'=>'fixed', 'coltype'=>'text');

    $colselect['header']='header';
    $tabcoltype['header']='text';
    $tabcoltype['ID']='numeric';
    
    // Get the column info from the results
    foreach ($result as $row)
    {
	$row_name=$row['column_name'];
	$al = 'col'.$row['alias'];
	$columns[$row_name] = $row_name;
	$alias[$row_name] = $al;
	$order[$row_name] = $row['column_order'];
	$coltab[$row_name] = array ('alias'=>$al, 'name'=>$row_name, 'order'=>$row['column_order'], 'type'=>$row['type'], 'coltype'=>$row['coltype']);
	$colselect[$al]=$row_name;
	$tabcoltype[$al]=$row['coltype'];
    }

    $request = $app['request'];

    // if one cell update
    if ($request->isMethod('POST'))
    {
	$id=$request->get('id');
	$col=$request->get('col');
	if ($id && $col)
	{
	    $newval=$request->get('newval');
	    $req = $app['db']->prepare('UPDATE '.$dbname.$token.'_sdf SET '.$col.'=:newval WHERE id=:id');
	    $req->bindValue(':id', ($id));
	    $req->bindValue(':newval', $newval);
	    $res=$req->execute();
	}
    }

    // Session variables management (before search)
    if (!isset($config['offset']))
    {
	$offset=0;
    }
    else
    {
	$offset=$config['offset'];	 
    }
    if (!isset($config['dir']))
    {
	$dir='asc';
    }
    else
    {
	$dir=$config['dir'];	 
    }
    if (!isset($config['sort']))
    {
	$sort='ID';
    }
    else
    {
	$sort=$config['sort'];
    }

    $app['session']->set('config', array('offset'=>$offset, 'dir'=>$dir, 'sort'=>$sort, 'clausewhere'=>$clausewhere));


    $i=0;
    $res = $app['db']->fetchAll('SELECT count(*) nb FROM '.$dbname.$token.'_sdf '.$clausewhere);
    $nbmol=0;
    foreach ($res as $row)
    {
       $nbmol= $row['nb'];
    }

    // add_col fix the case where no tag in sdf
    $add_col='';
    if ($alias)
    {
	$add_col=', '.implode(',',array_values($alias));
    }

    // check column type (numeric or text)
    $orderby='ID';
    if (isset ($tabcoltype[$sort]))
    {
	if ($tabcoltype[$sort]=='numeric')
	{
	    // SQLite columns type is always TEXT (could not be changed after column creation), so we use CAST function for 'numeric type' columns to simulate a correct type.
	    $orderby='CAST('. $sort.' AS INT)';
	}
	else
	{
	    $orderby=$sort;
	}
    }
    $req = $app['db']->prepare('SELECT ID, structure, header '.$add_col.' FROM '.$dbname.$token.'_sdf '.$clausewhere.' ORDER BY '. $orderby.' '. $dir.' LIMIT :offset, :limit');
    $req->bindValue(':limit', ($lim));
    $req->bindValue(':offset', $offset*$lim);

    $res=$req->execute();
    $tags=array();
    while ($tab = $req->fetch(PDO::FETCH_ASSOC))
    {
	$values=$id='';
	foreach ($tab as $tag=>$val)
	{
	    if ($tag=='ID')
	    {
		$values['ID']=$val;
		$id=$val;
	    }

	    $header="\n";
	    if ($tag=='header')
	    {
		$header = $val;  
	    }
	    if ($tag=='structure')
	    {
		$values['structure'] = $app['depict']->getSVG($header.$val);  
	    }
	    elseif ($tag!='ID')
	    {
		$values[$tag]=$val;
	    }
	}
	$tags[$id]=$values;
    }

    // display modal 
    $modal = $app['session']->get('modal');
    if ($modal=='import' && $nbmol)
    {
	$modal = $app['session']->set('modal', '');
    }
    
    return $app['twig']->render('sdf.twig', array(
	'dbname'=>$dbname, 'columns' => $coltab, 'tags'=>$tags, 'lim'=>$lim, 'nbmol'=>$nbmol, 'search'=>$clausewhere, 'modal'=>$modal
    ));
})
->bind('display');


// Delete a line 
$app->get('/delete-row/{dbname}/{id}', function ($dbname,$id) use ($app) {
    $token = $app['token'];
    $req = $app['db']->prepare('DELETE FROM '.$dbname.$token.'_sdf WHERE ID=:id');
    $req->bindValue(':id',$id);					
    $req->execute();
    $offset = $app['request']->get('offset');

    if (isset($offset))
    {
	return $app->redirect('/moleditor/web/preview/'.$dbname.'/'.$offset);   
    }
    else
    {
	return $app->redirect('/moleditor/web/display/'.$dbname);   
    }
    
})->bind('deleteRow');


// Delete a column    
$app->get('/delete-col/{dbname}/{col_order}', function ($dbname,$col_order) use ($app) {
    $token = $app['token'];
    $request = $app['request'];
    
    // get alias
    $req = $app['db']->prepare('SELECT alias FROM '.$dbname.$token.'_columns WHERE column_order=:col_order'); 
    $req->bindValue(':col_order', $col_order);
    $req->execute();
    while ($tab = $req->fetch(PDO::FETCH_ASSOC))
    {
        $alias= $tab['alias'];
    }   
    
    // delete corresponding line in table column
    $req = $app['db']->prepare('DELETE FROM '.$dbname.$token.'_columns WHERE column_order=:col_order'); 
    $req->bindValue(':col_order', $col_order);
    $req->execute();
    $req = $app['db']->prepare('UPDATE '.$dbname.$token.'_columns SET column_order=(column_order-1) WHERE column_order > :col_order'); 
    $req->bindValue(':col_order', $col_order);
    $req->execute();

    $req = $app['db']->executeQuery('SELECT alias FROM '.$dbname.$token.'_columns'); 
    $fields=$fields_type='';
    while ($tab = $req->fetch(PDO::FETCH_ASSOC))
    {
        $tab_fields[]= 'col'.$tab['alias'];
        $tab_fields_type[]= 'col'.$tab['alias'].' TEXT';
    }
    if (isset($tab_fields))
    {
	$fields=','.implode(',',$tab_fields);
	$fields_type=','.implode(',',$tab_fields_type);
    }    

    // SQLite could not delete a column. Fix: temp table need to be created, copy data without deleted column, then drop old table and rename new one  
    $app['db']->executeQuery('BEGIN TRANSACTION'); 
    $app['db']->executeQuery('CREATE TABLE '.$dbname.$token.'_sdf_backup (ID INTEGER PRIMARY KEY, structure TEXT, header TEXT, availability TEXT '.$fields.');'); 
    $app['db']->executeQuery('INSERT INTO  '.$dbname.$token.'_sdf_backup SELECT ID, structure, header, availability '.$fields.' FROM '.$dbname.$token.'_sdf'); 
    $app['db']->executeQuery('DROP TABLE '.$dbname.$token.'_sdf'); 
    $app['db']->executeQuery('ALTER TABLE '.$dbname.$token.'_sdf_backup RENAME TO '.$dbname.$token.'_sdf'); 
    $app['db']->executeQuery('COMMIT'); 
  
    if ($request->isXmlHttpRequest())
    {
	return $app->redirect('/moleditor/web/column-management/'.$dbname);   
    }
    else
    {
	return $app->redirect('/moleditor/web/display/'.$dbname);   
    }
})->bind('deleteCol');


// form for update a column type    
$app->match('/change-column-type/{dbname}/{alias}', function ($dbname, $alias) use ($app) {
    $token = $app['token'];
    $request = $app['request'];

    $alias = str_replace ('col', '', $alias);
    $req = $app['db']->prepare('SELECT type, coltype FROM '.$dbname.$token.'_columns WHERE alias=:alias'); 
    $req->bindValue(':alias', $alias);
    $req->execute();  
    $disabled=false;
    while ($tab = $req->fetch(PDO::FETCH_ASSOC))
    {
        $formdata['coltype']= $tab['coltype'];
	if ($tab['type']=='descriptor')
	{
	    $disabled=true;
	}
    }

    $form = $app['form.factory']->createBuilder('form', $formdata)
	->add('coltype', 'choice', array('choices'=>array('numeric'=>'numeric', 'text'=>'text'),
					 'attr'=>array('class'=>''),
					 'disabled'=>$disabled
					)
	    )
    ->getForm();

    if ($request->isMethod('POST'))
    {
	$form->bind($request);
	//if ($form->isValid())
	//{
	    $data=$form->getData();
	    $coltype=$data['coltype'];
	    $req = $app['db']->prepare('UPDATE '.$dbname.$token.'_columns SET coltype=:coltype WHERE alias=:alias'); 
	    $req->bindValue(':alias', $alias);
	    $req->bindValue(':coltype', $coltype);
	    $req->execute();
	//}
	    return $app->redirect('/moleditor/web/column-management/'.$dbname);   
    }
    
    return $app['twig']->render('colTypeUpdate.twig', array(
	'dbname'=>$dbname, 'alias'=>$alias, 'form' => $form->createView()
    ));

})->bind('changeColType');


// Change column order
$app->get('/move-col/{dbname}/{col_order}/{dir}', function ($dbname, $col_order, $dir) use ($app) {
    $token = $app['token'];

	$req = $app['db']->executeQuery('SELECT min(column_order) minorder, max(column_order) maxorder FROM '.$dbname.$token.'_columns WHERE type IN ("tag", "descriptor")');
	$result = $req->fetchAll();
	$maxorder=$result[0]['maxorder'];
	$minorder=$result[0]['minorder'];
	
	// position for column to move is temporarely -1
	$req = $app['db']->prepare('UPDATE '.$dbname.$token.'_columns SET column_order=-1 WHERE column_order=:col_order'); 
	$req->bindValue(':col_order', $col_order);
	$req->execute();
	if ($dir=='down')
	{
	    $new_order=($col_order-1);
	}
	if ($new_order<=$minorder)
	{
	    $new_order=$minorder;
	}
	if ($dir=="up")
	{
	    $new_order=($col_order+1);
	}
	if ($new_order>=$maxorder)
	{
	    $new_order=$maxorder;
	}
	// target column get position of column to move
	$req = $app['db']->prepare('UPDATE '.$dbname.$token.'_columns SET column_order=:col_order WHERE column_order=:new_order'); 
	$req->bindValue(':col_order', $col_order);
	$req->bindValue(':new_order', $new_order);
	$req->execute();

	// column to move (temp -1) get position of target column
	$req = $app['db']->prepare('UPDATE '.$dbname.$token.'_columns SET column_order=:new_order WHERE column_order=-1'); 
	$req->bindValue(':new_order', $new_order);
	$req->execute();
    return $app->redirect('/moleditor/web/column-management/'.$dbname);   
	
})->bind('moveCol');


// Exportation modal
$app->get('/export-modal/{dbname}', function ($dbname) use ($app) {
    return $app['twig']->render('export.twig', array(
        'dbname'=>$dbname
    ));

})->bind('exportModal');


// Exportation form
$app->get('/export/{dbname}/{type}', function ($dbname, $type) use ($app) {
    $token = $app['token'];

    // get session variable
    $config=$app['session']->get('config');
    if (!isset($config['clausewhere']))
    {
	$clausewhere='';
    }
    else
    {
	$clausewhere=$config['clausewhere'];	 
    }
    if (!isset($config['dir']))
    {
	$dir='asc';
    }
    else
    {
	$dir=$config['dir'];	 
    }
    if (!isset($config['sort']))
    {
	$sort='ID';
    }
    else
    {
	$sort=$config['sort'];
    }

    $columns = array();

    
    $req = $app['db']->executeQuery('SELECT column_name, alias,column_order,coltype FROM '.$dbname.$token.'_columns ORDER BY column_order');
    $result = $req->fetchAll();
    $alias = array();
    $sdf=$csv='';
    foreach ($result as $row)
    {
        // Get the column name from the results
        $row_name=$row['column_name'];
        $columns[$row_name] = $row_name;
        $alias[$row_name] = 'col'.$row['alias'];
        $rowalias='col'.$row['alias'];
        $coltype[$rowalias] = $row['coltype'];
        $inv_alias[$rowalias] = $row_name;
    }

    $avail='';
    if($app['session']->get('availability'))
    {
        $inv_alias['availability'] = 'availability';
	$avail=",availability";
        $columns['availability'] = 'availability';
    }

    // spreadsheet
    if (in_array($type, array('xls', 'ods', 'pdf')))
    {
	$workbook = new PHPExcel;
	//$workbook->setActiveSheetIndex(0);
	$sheet =$workbook->getActiveSheet();
	$sheet->setTitle($dbname);
  	// header
	array_unshift($columns, 'structure');
	array_unshift($columns, 'header');
	$i=0;
	foreach ($columns as $val)
	{
	    $colhead='ABCDEFGHIJKLMNOPQRSTUVWXYZ';
	    $colLetter=substr($colhead, $i, 1);
	    $styleA1 = $sheet->getStyle($colLetter.'1');
	    $styleFont = $styleA1->getFont();
	    $styleFont->setBold(true);
	    $sheet->getColumnDimension($colLetter)->setWidth(20);
	    $sheet->setCellValueByColumnAndRow($i, 1, $val);
	    $i++;
	}
    }
    
    // CSV header
    if ($type=='csv')
    {
	$csv='"header","structure","'.implode('","',$columns).'"'."\n";
    }
    $smi='';
    
    // add_col fix if no tag in SDF
    $add_col='';
    if ($alias)
    {
	$add_col=','.implode(',',array_values($alias));
    }
    
    // get the column type (numeric ou text)
    $orderby=$sort;
    if (isset($coltype[$sort]))
    {
	if ($coltype[$sort]=='numeric')
	{
	    // SQLite columns type is always TEXT (could not be changed after column creation), so we use CAST function for 'numeric type' columns to simulate a correct type.
	    $orderby='CAST('. $sort.' AS INT)';
	}
    }
    
 
    $req = $app['db']->prepare('SELECT structure, header'.$add_col.$avail.' FROM '.$dbname.$token.'_sdf '.$clausewhere.' ORDER BY '.$orderby.' '. $dir);

    $res=$req->execute();
    $j=2;
    while ($tab = $req->fetch(PDO::FETCH_ASSOC))
    {
        $colheader= $tab['header'];
        $colstruct= $tab['structure'];
	if ($type=='csv')
	{
	    $smiles = $app['depict']->getSmiles("\n".$colstruct."\n$$$$");
	    $csv.='"'.$colheader.'","'.$smiles.'"';
	}
	if ($type=='smi')
	{
	    $smi.= trim($app['depict']->getSmiles("\n".$colstruct."\n$$$$")).'	'.$colheader."\r\n";
	}

	// XLS format
	if (in_array($type, array('xls', 'ods', 'pdf')))
	{
	    $sheet->getRowDimension($j)->setRowHeight(90);
	    $sheet->setCellValueByColumnAndRow(0, $j, $colheader);
	    $struct = $app['depict']->getSVG("\n".$colstruct."\n$$$$", 'png');
	    $objDrawing = new PHPExcel_Worksheet_Drawing();
	    $objDrawing->setName('struct'.$j);
	    $objDrawing->setDescription('structure of compound '. $j);
	    $objDrawing->setPath($struct);
	    $objDrawing->setHeight(130);
	    $objDrawing->setCoordinates('B'.$j);
	    $objDrawing->setWorksheet($sheet);
	}
        $sdf.= $colheader."\n";
        $sdf.= $colstruct."\n";
        unset($tab['header']);
        unset($tab['structure']);
	
	$i=2;
        foreach ($tab as $tag=>$val)
        {
	    if($tag=='availability')
	    {
		if($val)
		{
		    if($val!='NA')
		    {
			$val='Amb'.$val;
		    }
		}
		else
		{
		    $val='Not checked';
		}
	    }
            $sdf.='>	<'.$inv_alias[$tag].">\n";
            $sdf.=$val."\n\n";
	    if (in_array($type, array('xls', 'ods', 'pdf')))
	    {
		$sheet->setCellValueByColumnAndRow($i, $j, $val);
	    }
	    $i++;
	    $csv.=",".str_replace('"','\"', $val);
        }
	$j++;
	$csv.= "\n";
        $sdf.="$$$$\n";
    }
    
    
    // creation of exported file    
    $filename = __DIR__.'/../src/tmp/'.$dbname.'.'.$type;
    $outpath = __DIR__.'/../src/tmp/export'.$dbname.$token.'.'.$type;
    $f = fopen ($outpath, 'w+');
    if ($type=='sdf')
    {
	fwrite ($f, $sdf);
	$contentType='text/sdf';
    }
    if ($type=='csv')
    {
	fwrite ($f, $csv);
	$contentType='text/csv';
    }
    if ($type=='smi')
    {
	fwrite ($f, $smi);
	$contentType='text/smi';
    }
    fclose($f);

    if (in_array($type, array('xls', 'ods', 'pdf')))
    {
	if ($type=='xls')
	{
	    $writer = new PHPExcel_Writer_Excel5($workbook);
	    $contentType='application/vnd.ms-excel';
	}
	elseif ($type=='ods')
	{
	    $writer = new PHPExcel_Writer_OOCalc($workbook);
	    $contentType='vnd.oasis.opendocument.spreadsheet';
	}
	elseif ($type=='pdf')
	{
	    $writer = new PHPExcel_Writer_PDF($workbook);
	    $contentType='application/pdf';
	}
	$writer->save($outpath);
    }


    $stream = function () use ($outpath) {
        readfile($outpath);
    };
    return $app->stream($stream, 200, array(
        'Content-Type' => $contentType,
        'Content-Disposition' => 'attachment; filename="'.basename($filename).'"'	
        ));
    })
->bind('export');


// Column management
$app->match('/column-management/{dbname}', function ($dbname) use ($app) {
    $token = $app['token'];
    $request = $app['request'];
   
    $result = $app['db']->fetchAll('SELECT column_name, alias,column_order, type, coltype FROM '.$dbname.$token.'_columns WHERE type IN ("tag", "descriptor") ORDER BY column_order');
    $columns = array();
    $alias = array();
    $coltab = array();

    foreach ($result as $row)
    {
	// Get the column name from the results
	$row_name=$row['column_name'];
	$row_alias=$row['alias'];
	$row_coltype=$row['coltype'];
	if (!in_array($row_name, array('ID', 'structure')))
	{
	    $columns[$row_alias] = $row_name;
	    $alias[$row_alias] = 'col'.$row['alias'];
	    $order[$row_alias] = $row['column_order'];
	    $coltab[$row_alias] = array ('alias'=>'col'.$row['alias'], 'name'=>$row_name, 'order'=>$row['column_order'], 'type'=>$row['type'],'coltype'=>$row_coltype);
	}
    }
    
    return $app['twig']->render('colManagement.twig', array(
        'dbname'=>$dbname, 'columns' => $coltab
    ));
 
})->bind('colManagement');


// Add a new descriptor
$app->match('/newdesc/{dbname}', function ($dbname) use ($app) {
    $token = $app['token'];
    $request = $app['request'];
    $descriptors_array = array ('formula'=> array('label'=> 'Formula','coltype'=>'text'),
				'MW'=> array('label'=> 'Mol Weight','coltype'=>'numeric'),
				'logP'=> array('label'=> 'logP','coltype'=>'numeric'),
				'HBA2'=> array('label'=> 'Acceptor','coltype'=>'numeric'),
				'HBD'=> array('label'=> 'Donor','coltype'=>'numeric')
				);
				
    foreach($descriptors_array as $key => $val)
    {
	$descriptor_coltype[$key] = $val['coltype'];
	$descriptor_list[$key] = $val['label'];
    }
    
    // Get columns corresponding to descriptors
    $req=$app['db']->executeQuery('SELECT column_name, alias, type FROM '.$dbname.$token.'_columns');
    
    $res = $req->fetchAll();
    $doublon_colname='';
    foreach ($res as $val)
    {
	$colname = $val['column_name'];
	$type = $val['type'];
	foreach ($descriptor_list as $shortdesc=>$longdesc)
	{
	    if ($colname==$longdesc && $type=='descriptor')
	    {
		unset($descriptor_list[$shortdesc]);
	    }	    
	    if ($colname==$longdesc && $type=='tag')
	    {
		$doublon_colname=$shortdesc;         
	    }
	}
    }
   
    $form = $app['form.factory']->createBuilder('form')
    ->add('coldesc', 'choice', array('choices'=>$descriptor_list
				)	    
    )
    ->getForm();
	
    if ($request->isMethod('POST'))
    {
        $form->bind($request);
        if ($form->isValid())
        {
            $data = $form->getData();
	    $colname=$data['coldesc'];
	    if ($colname!=$doublon_colname)
	    {
		$req = $app['db']->executeQuery('SELECT max(alias) maxalias, max(column_order) maxorder FROM '.$dbname.$token.'_columns');
		$result = $req->fetchAll();

		$newalias=$result[0]['maxalias']+1;
		$neworder=$result[0]['maxorder']+1;

		$req = $app['db']->prepare('INSERT INTO '.$dbname.$token.'_columns (column_name, column_order, alias, type, coltype) VALUES (:column,:order,:alias, :type, :coltype)');
		$req->bindValue(':alias', $newalias);
		$req->bindValue(':order', $neworder);
		$req->bindValue(':column', $descriptor_list[$colname]);
		$req->bindValue(':type', 'descriptor');
		$req->bindValue(':coltype', $descriptor_coltype[$colname]);
		$res=$req->execute();

		$req=$app['db']->executeQuery('PRAGMA table_info('.$dbname.$token.'_sdf)');
		$no_alter=0;
		while ($tab = $req->fetch(PDO::FETCH_ASSOC))
		{
		    if ($tab['name']=='col'.$newalias)
		    {
			$no_alter=1;
		    }
		}
		if (!$no_alter)
		{
		    $app['db']->executeQuery('ALTER TABLE '.$dbname.$token.'_sdf ADD COLUMN col'.$newalias.' TEXT');
		}
		// create a tmp SDF with structure+ID as header
		$req=$app['db']->executeQuery('SELECT ID, structure FROM '.$dbname.$token.'_sdf');
		$sdf='';
		while ($tab = $req->fetch(PDO::FETCH_ASSOC))
		{
		    $struct= $tab['structure'];
		    $sdf.= $tab['ID']."\n";
		    $sdf.= $tab['structure']."\n\n";
		    $sdf.="$$$$\n";
		}

		// creation of temp SDF file
		$desc_sdf_path = __DIR__.'/../src/tmp/sdfdesc'.$dbname.$token.'.sdf';
		$f = fopen ($desc_sdf_path, 'w+');
		fwrite ($f, $sdf);
		fclose($f);

		// compute descriptors from sdf
		if (file_exists ($desc_sdf_path))
		{
		    $tabdesc=$app['babel']->descriptors($desc_sdf_path, array($colname));
		    unlink($desc_sdf_path);
		}

		// Update database
		$sql = 'BEGIN TRANSACTION';
		$app['db']->executeQuery($sql);
		$req = $app['db']->prepare('UPDATE '.$dbname.$token.'_sdf SET col'.$newalias.'=:val WHERE ID=:id' );
		foreach($tabdesc as $id => $val)
		{
		    $req->bindValue(':id', $id);
		    $req->bindValue(':val', $val[$colname]);
		    $req->execute();
		}
		$sql = 'END TRANSACTION';
		$app['db']->executeQuery($sql);
	    }
	    else
	    {
		$app['session']->getFlashBag()->add(
		    'danger',
		    'A column named "'.$colname.'" already exists. Please rename it first before adding this descriptor column !'
		);
	    }
	    return $app->redirect('/moleditor/web/display/'.$dbname);           
	}
    }
    return $app['twig']->render('colNewDesc.twig', array(
        'dbname'=>$dbname, 'form' => $form->createView()
    ));

})->bind('newDesc');


// Add a new column
$app->match('/newcol/{dbname}', function ($dbname) use ($app) {
    $request = $app['request'];
    $token = $app['token'];

    $form = $app['form.factory']->createBuilder('form')
        ->add('colname')
        ->getForm();
	
    if ($request->isMethod('POST'))
    {
        $form->bind($request);
        if ($form->isValid())
        {
            $data = $form->getData();
	    $colname=$data['colname'];

	    // get all existing columns
	    $req = $app['db']->executeQuery('SELECT column_name FROM '.$dbname.$token.'_columns');
	    $res= $req->fetchAll();
	    $allcol=array('ID','header','structure','availability');
	    foreach ($res as $row)
	    {
		$allcol[]=$row['column_name'];
	    }
	    if (in_array($colname, $allcol))
	    {
		    $app['session']->getFlashBag()->add(
		    'danger',
		    'The column '.$colname.' already exists. Please choose another name !'
		);
	    }
	    else
	    {
		$req = $app['db']->executeQuery('SELECT max(alias) maxalias, max(column_order) maxorder FROM '.$dbname.$token.'_columns');
		$result = $req->fetchAll();

		$newalias=$result[0]['maxalias']+1;
		$neworder=$result[0]['maxorder']+1;

		$req = $app['db']->prepare('INSERT INTO '.$dbname.$token.'_columns (column_name, column_order, alias, type, coltype) VALUES (:column,:order,:alias, :type, :coltype)');
		$req->bindValue(':alias', $newalias);
		$req->bindValue(':order', $neworder);
		$req->bindValue(':column', $colname);
		$req->bindValue(':type', 'tag');
		$req->bindValue(':coltype', 'text');
		$res=$req->execute();

		$req=$app['db']->executeQuery('PRAGMA table_info('.$dbname.$token.'_sdf)');
		$no_alter=0;
		while ($tab = $req->fetch(PDO::FETCH_ASSOC))
		{
		    if ($tab['name']=='col'.$newalias)
		    {
			$no_alter=1;
		    }
		}
		if (!$no_alter)
		{
		    $app['db']->executeQuery('ALTER TABLE '.$dbname.$token.'_sdf ADD COLUMN col'.$newalias.' TEXT');
		}
	    }
	    return $app->redirect('/moleditor/web/display/'.$dbname);           
	}
    }
    return $app['twig']->render('colNew.twig', array(
        'dbname'=>$dbname, 'form' => $form->createView()
    ));
    
})->bind('newCol');



// Update column
$app->match('/column-update/{dbname}/{col}', function ($dbname, $col) use ($app) {
    $token = $app['token'];

    $config=$app['session']->get('config');
    if ($col=='colheader')
    {
	$col='header';
    }

    $req = $app['db']->prepare('SELECT column_name, alias, column_order, type, coltype FROM '.$dbname.$token.'_columns where alias=:alias');
    $req->bindValue(':alias', str_replace('col','',$col));
    $req->execute();
    $results= $req->fetchAll();
    foreach ($results as $tab)
    {
	$column['name']=$tab['column_name'];
	$column['alias']=$tab['alias'];
	$column['coltype']=$tab['coltype'];
	$column['order']=$tab['column_order'];
	$type = $tab['type'];
    }
    if ($col=='header')
    { 	  
	$column['name']='header';
	$column['alias']='header';
	$column['coltype']='text';
	$type='fixed';
    }

    // See if sorted column is TEXT or NUMERIC type (because sorting is different for number as strings or numerics), and use CAST function if needed
    $colTypeSort='';
    $colsort=$config['sort'];
    $req = $app['db']->prepare('SELECT  alias, coltype FROM '.$dbname.$token.'_columns where alias=:alias');
    $req->bindValue(':alias', str_replace('col','',$config['sort']));
    $req->execute();
    $results= $req->fetchAll();
    foreach ($results as $tab)
    {
	$colTypeSort=$tab['coltype'];
    }
    if ($colTypeSort=='numeric')
    {
	$colsort = 'CAST('.$config['sort'].' AS INT)';
    }

    $req = $app['db']->executeQuery('SELECT ID, '.$col.' FROM '.$dbname.$token.'_sdf ORDER BY '.$colsort.' '.$config['dir']);
    $results= $req->fetchAll();
    foreach ($results as $tab)
    {
	$ids[]=$tab['ID'];
	$values[]=$tab[$col];
    }
    if (trim($values[0])=='')
    {
	$values[0]="\n";
    }
    $implode = implode("\n",$values);
    $data=array('colname'=>$column['name'], 'values'=>$implode, 'coltype'=>$column['coltype']);

    $request = $app['request'];
    // form for updating column features and/or values
    $builder = $app['form.factory']->createBuilder('form', $data);
    
    // If updated column is the header, field for renaming column is disabled
    $disabled_colname=$disabled_values=$disabled_coltype=false;
    if ($col=='header')
    {
        $disabled_colname=true;					    
	$disabled_coltype=true;
    }
    if ($type=='descriptor')
    {
	$disabled_coltype=true;
    }
    $builder->add('colname', 'text', array('attr'=>array('class'=>'form-control'),
					    'disabled'=>$disabled_colname
					    )
    );
    $builder->add('coltype', 'choice', array('attr'=>array('class'=>'form-control'),
					    'choices'=>array('text'=>'text','numeric'=>'numeric'),
					    'disabled'=>$disabled_coltype
					    )
    );
  
    if ($type=='descriptor')
    {
        $disabled_values=true;		    
    }
    $builder->add('values', 'textarea', array('attr'=>array('class'=>'form-control input-sm',
							'rows'=>10),
					      'required'=>false,
					      'disabled'=>$disabled_values	    
					    )
	);
    $builder->add('sameForAll', 'text', array('attr'=>array('class'=>'form-control input-sm',
							),
					      'required'=>false,
					      'disabled'=>$disabled_values	    
					    )
	);
    
    $form = $builder->getForm();

    if ($request->isMethod('POST'))
    {
        $form->bind($request);
        if ($form->isValid())
        {
            $data = $form->getData();
	    $colname = $data['colname'];
	    // name update (if not empty)
	    $coltype = $data['coltype'];
	    if ($coltype)
	    {
		$req = $app['db']->prepare('UPDATE '.$dbname.$token.'_columns SET coltype=:coltype where alias=:alias');
		$req->bindValue(':coltype', $coltype);
		$req->bindValue(':alias', str_replace('col','',$col));
		$res=$req->execute();
	    }
	    
	    if ($colname)
	    {
		// get all existing columns
		$req = $app['db']->executeQuery('SELECT column_name FROM '.$dbname.$token.'_columns');
		$res= $req->fetchAll();
		$allcol=array('ID','structure','availability');
		foreach ($res as $row)
		{
		    if ($row['column_name']!=$column['name'])
		    {
			$allcol[]=$row['column_name'];
		    }
		}
		if (in_array($colname, $allcol))
		{
			$app['session']->getFlashBag()->add(
			'danger',
			'The column '.$colname.' already exists. Please choose another name !'
		    );
		    $updateOk=0;
		}
		else
		{
		    $updateOk=1;
		}
		if ($updateOk)
		{
		    $req = $app['db']->prepare('UPDATE '.$dbname.$token.'_columns SET column_name=:colname where alias=:alias');
		    $req->bindValue(':colname', $colname);
		    $req->bindValue(':alias', str_replace('col','',$col));
		    $res=$req->execute();
		}
	    }
 	    // values update
	    $newvalues = explode("\n",$data['values']);
	    if ($updateOk)
	    {
		$sql = 'BEGIN TRANSACTION';
		$app['db']->executeQuery($sql);
		if ($data['sameForAll'])
		{
		    $req = $app['db']->prepare('UPDATE '.$dbname.$token.'_sdf SET '.$col.'=:newval');
		    $req->bindValue(':newval', $data['sameForAll']);
		    $res=$req->execute();
		}
		else
		{
		    foreach ($ids as $key=>$id)
		    {
			if (isset($newvalues[$key]))
			{
			    if ($newvalues[$key]!=$values[$key])
			    {
				$req = $app['db']->prepare('UPDATE '.$dbname.$token.'_sdf SET '.$col.'=:newval where ID=:id');
				$req->bindValue(':newval', trim(str_replace("\n", '',$newvalues[$key])));
				$req->bindValue(':id', $id);
				$res=$req->execute();
			    }
			}
		    }
		}
		$sql = 'END TRANSACTION';
		$app['db']->executeQuery($sql);
	    }
    	    return $app->redirect('/moleditor/web/display/'.$dbname);           
	}
    }
    
    return $app['twig']->render('colUpdate.twig', array(
        'dbname'=>$dbname, 'form' => $form->createView(), 'column'=>$column

    ));
 
})->bind('colUpdate');


// Sketcher management (draw new or update existing structure)
$app->match('/ketcher/{dbname}/{id}', function ($dbname, $id) use ($app) {
    
    $token = $app['token'];
    $request = $app['request'];

    if ($id=='new')
    {
	$datastruct['molstruct']='';
    }
    else
    {
	$req = $app['db']->prepare('SELECT ID, structure, header FROM '.$dbname.$token.'_sdf WHERE ID=:id');
	$req->bindValue(':id', $id);
	$res=$req->execute();
	while ($row = $req->fetch(PDO::FETCH_ASSOC))
	{
	    $header=$row['header'];
	    $struct=$header."\n".$row['structure'];
	}
	$datastruct['molstruct']=$struct;
	$datastruct['offset']=$request->get('offset');
    }


    $form = $app['form.factory']->createBuilder('form', $datastruct)
        ->add('molstruct', 'hidden', array('attr'=>array('id'=>'molstruct')))
	->add('offset', 'hidden')
       ->getForm();

    if ($request->isMethod('POST'))
    {
        $form->bind($request);
        if ($form->isValid())
        {
	    $data = $form->getData();
	    $newstruct = $data['molstruct'];
	    $offset = $data['offset'];

	    if ($id=='new')
	    {
		$req = $app['db']->executeQuery('SELECT MAX(ID) maxid FROM '.$dbname.$token.'_sdf');
		$result = $req->fetchAll();
		$maxid=($result[0]['maxid']+1);

		$req = $app['db']->prepare('INSERT INTO '.$dbname.$token.'_sdf (ID) VALUES (:id)');
		$req->bindValue(':id', $maxid);
		$res=$req->execute();
		$id=$maxid;
	    }

	    $req = $app['db']->prepare('UPDATE '.$dbname.$token.'_sdf SET structure=:newstruct, availability="" WHERE ID=:id');
	    $req->bindValue(':newstruct', $newstruct);
	    $req->bindValue(':id', $id);
	    $res=$req->execute();

	// Update of computed descriptors
	    $req=$app['db']->executeQuery('SELECT column_name, alias FROM '.$dbname.$token.'_columns WHERE type="descriptor"');
	    $res = $req->fetchAll();

	    foreach ($res as $val)
	    {
		$colname=$val['column_name'];
		$descriptors[]= $colname;
		$col_descriptors[$colname]=$val['alias'];
	    }
	    // recompute existing descriptors
	    if (isset($descriptors))
	    {
		$req=$app['db']->prepare('SELECT ID, structure FROM '.$dbname.$token.'_sdf WHERE ID=:id');
		$req->bindValue(':id', $id);
		$res=$req->execute();
		$sdf='';
		while ($tab = $req->fetch(PDO::FETCH_ASSOC))
		{
		    $sdf.= $tab['ID']."\n";
		    $sdf.= $tab['structure']."\n\n";
		    $sdf.="$$$$\n";
		}

		// create a temp SDF
		$desc_sdf_path = __DIR__.'/../src/tmp/sdfdesc'.$dbname.$token.'.sdf';
		$f = fopen ($desc_sdf_path, 'w+');
		fwrite ($f, $sdf);
		fclose($f);

		
		// Computation of descriptors from SDF
		if (file_exists ($desc_sdf_path))
		{
		    $tabdesc=$app['babel']->descriptors($desc_sdf_path, $descriptors);
		    unlink($desc_sdf_path);
		}

		foreach($tabdesc as $id => $descval)
		{
		    foreach ($descval as $desc=>$val)
		    {
			$req = $app['db']->prepare('UPDATE '.$dbname.$token.'_sdf SET col'.$col_descriptors[$desc].'=:val WHERE ID=:id' );
			$req->bindValue(':id', $id);
			$req->bindValue(':val', $val);
			$req->execute();
		    }
		}
	    }
	    if ($offset!='')
	    {
		return $app->redirect('/moleditor/web/preview/'.$dbname.'/'.$offset);           
	    }
	    
	    return $app->redirect('/moleditor/web/display/'.$dbname);           
	}
    }

    return $app['twig']->render('ketcher.twig', array(
        'form' => $form->createView(), 'dbname'=>$dbname, 'id'=>$id
    ));
})->bind('ketcher');


// Form to enable or disable checking of availability
$app->match('/check-availability/{dbname}', function ($dbname) use ($app) {
    $av = $app['session']->get('availability');
    if ($av)
    {
	$app['session']->set('availability', 0);
    }
    else
    {
	$app['session']->set('availability', 1);
    }
    return $app->redirect('/moleditor/web/column-management/'.$dbname);           
})->bind('check-availability');


// Check availability on Ambinter website
$app->match('/commercial-availability/{dbname}/{id}', function ($dbname, $id) use ($app) {
    $token = $app['token'];
    $request = $app['request'];
    $available='';
    
    $req = $app['db']->prepare('SELECT ID, structure, header, availability FROM '.$dbname.$token.'_sdf WHERE ID=:id ');
    $req->bindValue(':id', $id);
    $res=$req->execute();
    while ($row = $req->fetch(PDO::FETCH_ASSOC))
    {
	$header=$row['header'];
	$available=$row['availability'];
	$struct=$header."\n".$row['structure'];
    }
    if (!$available)
    {
	$smiles = $app['depict']->getSmiles($struct);
	$smiles = urlencode($smiles);
	
	// workaround for encoding slash (By default, apache do not want to encode/decode slash into %2F, need to replace by %252F, else this return a 404 error
	$smiles = str_ireplace('%2F', '%252F', $smiles);

	if ($smiles)
	{
	    $file = file('http://www.ambinter.com/api/search/'.$smiles);
	    if (isset($file[0]))
	    {
		$available=$file[0];
	    }
	    else
	    {
		$available='NA';
	    }
	}
	$req = $app['db']->prepare('UPDATE '.$dbname.$token.'_sdf SET availability=:available WHERE ID=:ID');
	$req->bindValue(':ID', $id);
	$req->bindValue(':available', $available);
	$res=$req->execute();
    }		

    return $app['twig']->render('availability.twig', array(
	'available'=>$available
    ));
 
})->bind('availability');


## MOLEDITOR WEBSITE PAGES ##
// page learn-more
$app->match('/learn-more', function () use ($app) {
    return $app['twig']->render('learnMore.twig');
})->bind('learnMore');
// page about-us
$app->match('/about-us', function () use ($app) {
return $app['twig']->render('aboutUs.twig');
})->bind('aboutUs');
// page help
$app->match('/help', function () use ($app) {
return $app['twig']->render('help.twig');
})->bind('help');
// page contribute
$app->match('/contribute', function () use ($app) {
return $app['twig']->render('contribute.twig');
})->bind('contribute');
// page license
$app->match('/license', function () use ($app) {
return $app['twig']->render('license.twig');
})->bind('license');

$app->run();
