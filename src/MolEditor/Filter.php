<?php
// filter.php
namespace MolEditor;
use Silex\Provider\DoctrineServiceProvider;
use Silex\Application;
use Silex\Provider\FormServiceProvider;
use Symfony\Component\HttpFoundation\Request;


Class Filter
{
	public function __construct (Application $app)
	{
		$this->app=$app;
	}

	public function getFormRange($key, $id, $filtername)
	{ 
	    $form = $this->app['form.factory']->createBuilder('form')
	    ->setAction($this->app['url_generator']->generate('workflow-filter', array('key'=>$key, 'id'=>$id, 'filtername'=>$filtername)))
    
	    ->add('range1', 'text', array('attr'=>array('class'=>'form-control  col-md-1'),
					      'required'=>false,
					      'label'=>'Between '
					      ))
	    ->add('range2', 'text', array('attr'=>array('class'=>'form-control  col-md-1'),
					      'required'=>false,
					      'label'=>' and ',
					      
					      ))
	    ->getForm();
	    return $form;
	}
	
	public function getFormCheckbox($key, $id, $filtername)
	{
		$form = $this->app['form.factory']->createBuilder('form')
		->setAction($this->app['url_generator']->generate('workflow-filter', array('key'=>$key, 'id'=>$id, 'filtername'=>$filtername)))
		->add('checkbox', 'checkbox', array(	'label'=>'Apply filter',
							'required'=>false,
							 'attr'=>array('class'=>'')
							 ))
		->getForm();
		return $form;
	}

	// $id of the input file 
	public function setInput($id)
	{
	    $req = $this->app['db']->prepare('SELECT id, path, hash FROM workflow_file WHERE id=:id ORDER BY id DESC');
	    $req->bindValue(':id', $id);
	    $this->id=$id;
	    $res=$req->execute();
	    while ($tab = $req->fetch(\PDO::FETCH_ASSOC))
	    {
		$this->hash=$tab['hash'];
		$this->path=$tab['path'];
		if (file_exists($this->path))
		{
		    $this->firstInput=$this->path;
		    $this->currentInput=$this->path;
		    $this->setOutput();
		}
	    }
	}

	protected function setOutput()
	{
	    $this->output =  dirname($this->firstInput).'/'.basename($this->firstInput, '.sdf').'_'.rand(0,100000).'.sdf';
	    return $this->output;
	}
	
	public function setFilter(Array $filter, Array $data)
	{
	    $filterType=$filter['param'];
	    $filterTypeLong=$filter['longfiltername'];
	    $range1=$data['range1'];
	    $range2=$data['range2'];

	    if ($range1 && $range2)
	    {
		$param['filterBasic'][$filterType.'>='.$range1]=$filterTypeLong.'>='.$range1.', <='.$range2 ;

	    }
	    elseif ($range1)
	    {
		$param['filterBasic'][$filterType.'>='.$range1]=$filterTypeLong.'>='.$range1;
	    }
	    elseif ($range2)
	    {
		$param['filterBasic'][$filterType.'<='.$range2]=$filterTypeLong.'<='.$range2;
	    }
	    
	    if (isset ($data['checkbox']))
	    {
		$param['filterBasic'][$filterType]=$filterTypeLong;
	    }		
		    
	    // openbabel
	    $this->output=$this->setOutput();
	    $exec='obabel \''.$this->currentInput.'\' -O\''.$this->output.'\' --filter "'.implode(' ', array_keys($param['filterBasic'])).'"';
	    $this->currentInput=$this->output;
	    $this->filter=json_encode($param);
	    exec($exec);
	}
	
	public function apply ()
	{
	    $parent='';
	    
	    $req = $this->app['db']->prepare('SELECT parent FROM workflow_file WHERE id=:id AND hash=:hash');
	    $req->bindValue(':hash', $this->hash);
	    $req->bindValue(':id', $this->id);
	    $req->execute();
	    while ($tab = $req->fetch(\PDO::FETCH_ASSOC))
	    {
		    $parent=$tab['parent'].'-'.$this->id.'-';
	    }
	    
	    $req = $this->app['db']->prepare('INSERT INTO workflow_file (path, hash, parent, filter) VALUES (:path, :hash, :parent, :filter)');
	    $req->bindValue(':hash', $this->hash);
	    $req->bindValue(':path', $this->output);
	    $req->bindValue(':parent', $parent);
	    $req->bindValue(':filter', $this->filter);
	    $req->execute();
	    
	    return $this->output;
	}
}
