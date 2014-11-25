<?php
// filter.php
namespace MolEditor;
use Silex\Provider\DoctrineServiceProvider;
use Silex\Application;
use Silex\Provider\FormServiceProvider;
use Symfony\Component\HttpFoundation\Request;


Class FilterSearch extends Filter
{
	public function getFormInput($key, $id, $filtername)
	{
		$form = $this->app['form.factory']->createBuilder('form')
		->setAction($this->app['url_generator']->generate('workflow-filter', array('key'=>$key, 'id'=>$id, 'filtername'=>$filtername)))
		->add('search', 'text', array('attr'=>array('class'=>'form-control'),
						  'required'=>false
						  ))
		->getForm();
		return $form;
	}


	
	public function setFilter(Array $filter, Array $data)
	{
	    $filterType=$filter['param'];
	    $filterTypeLong=$filter['longfiltername'];
	    $search=$data['search'];
	    $param['filterSearch']['s="'.$search.'"']='SMART search \''. $search.'\'';

	    // openbabel
	    $this->output=$this->setOutput();
	    $exec='obabel \''.$this->currentInput.'\' -O\''.$this->output.'\' --filter "'.implode(' ', array_keys($param['filterSearch'])).'"';
	    $this->currentInput=$this->output;
	    $this->filter=json_encode($param);
	    exec($exec);
	}
	
}
