<?php
/* translators: the following are textarea element settings */

class fm_textareaControl extends fm_controlBase{
	public function getTypeName(){ return "textarea"; }
	
	/* translators: this appears in the 'Add Form Element' menu */
	public function getTypeLabel(){ return __("Text Area", 'wordpress-form-manager'); }
	
	public function showItem($uniqueName, $itemInfo){
		$elem=array('type' => 'textarea',
					'default' => $itemInfo['extra']['value'],
					'attributes' => array('name' => $uniqueName,
											'id'=> $uniqueName,
											'style' => "width:".$itemInfo['extra']['cols']."px;height:".$itemInfo['extra']['rows']."px;"
											)					
					);											
		return fe_getElementHTML($elem);
	}	
	
	public function itemDefaults(){
		$itemInfo = array();
		$itemInfo['label'] = __("New Text Area", 'wordpress-form-manager');
		$itemInfo['description'] = __("Item Description", 'wordpress-form-manager');
		$itemInfo['extra'] = array('cols'=>'300', 'rows' => '100');
		$itemInfo['nickname'] = '';
		$itemInfo['required'] = 0;
		$itemInfo['validator'] = "";
		$ItemInfo['validation_msg'] = "";
		$itemInfo['db_type'] = "TEXT";
		
		return $itemInfo;
	}
	
	public function editItem($uniqueName, $itemInfo){
		$elem=array('type' => 'textarea',
					'default' => $itemInfo['extra']['value'],
					'attributes' => array('name' => $uniqueName."-edit-value",
											'id'=> $uniqueName."-edit-value",
											'rows'=> 2,
											'cols'=> 18,
											'readonly' => 'readonly'
											)
					);											
		return fe_getElementHTML($elem);
	}
	
	public function getPanelItems($uniqueName, $itemInfo){
		$arr=array();
		$arr[] = new fm_editPanelItemBase($uniqueName, 'label', __('Label', 'wordpress-form-manager'), array('value' => $itemInfo['label']));
		$arr[] = new fm_editPanelItemBase($uniqueName, 'value', __('Default Value', 'wordpress-form-manager'), array('value' => $itemInfo['extra']['value']));
		$arr[] = new fm_editPanelItemBase($uniqueName, 'rows', __('Height (in pixels)', 'wordpress-form-manager'), array('value' => $itemInfo['extra']['rows']));
		$arr[] = new fm_editPanelItemBase($uniqueName, 'cols', __('Width (in pixels)', 'wordpress-form-manager'), array('value' => $itemInfo['extra']['cols']));
		$arr[] = new fm_editPanelItemCheckbox($uniqueName, 'required', __('Required', 'wordpress-form-manager'), array('checked'=>$itemInfo['required']));
		return $arr;
	}
	
	public function getPanelScriptOptions(){
		$opt = $this->getPanelScriptOptionDefaults();		
		$opt['extra'] = $this->extraScriptHelper(array('value'=>'value', 'rows'=>'rows', 'cols'=>'cols'));
		$opt['required'] = $this->checkboxScriptHelper('required');
		
		return $opt;
	}
	
	public function getShowHideCallbackName(){
		return "fm_textarea_show_hide";
	}
	
	public function getRequiredValidatorName(){ 
		return 'fm_base_required_validator';
	}

	protected function showExtraScripts(){
		?><script type="text/javascript">
		function fm_textarea_show_hide(itemID, isDone){
			if(isDone){
				document.getElementById(itemID + '-edit-label').innerHTML = document.getElementById(itemID + '-label').value;
				document.getElementById(itemID + '-edit-value').innerHTML = document.getElementById(itemID + '-value').value;
				if(document.getElementById(itemID + '-required').checked)
					document.getElementById(itemID + '-edit-required').innerHTML = "<em>*</em>";
				else
					document.getElementById(itemID + '-edit-required').innerHTML = "";
			}
		}
		</script>
		<?php
	}
	
	protected function getPanelKeys(){
		return array('label','required');
	}	
}

?>