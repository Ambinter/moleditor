<?php
// filter.php
namespace MolEditor;
use Silex\Provider\DoctrineServiceProvider;
use Silex\Application;
use Silex\Provider\FormServiceProvider;
use Symfony\Component\HttpFoundation\Request;


Class FilterClean extends Filter
{
	public function getFormCheckbox($key, $id, $filtername)
	{
	    $form = $this->app['form.factory']->createBuilder('form')
	    ->setAction($this->app['url_generator']->generate('workflow-filter', array('key'=>$key, 'id'=>$id, 'filtername'=>$filtername)))
	    ->add('checkbox', 'checkbox', array(    'label'=>'Apply filter',
						    'required'=>false,
						     'attr'=>array('class'=>'')
						     ))
	    
	    ->getForm();
	    return $form;
	}


	
	public function setFilter(Array $filter, Array $data)
	{
	    $filterType=$filter['param'];
	    $filterTypeLong=$filter['longfiltername'];
	    if (isset ($data['checkbox']))
	    {
		$param['filterClean'][$filterType]=$filterTypeLong;
	    }		

	    // openbabel
	    $this->output=$this->setOutput();
	    $exec='obabel \''.$this->currentInput.'\' -O\''.$this->output.'\' '.implode(' ', array_keys($param['filterClean']));
	    $this->currentInput=$this->output;
	    $this->filter=json_encode($param);
	    exec($exec);
	}
	
}
