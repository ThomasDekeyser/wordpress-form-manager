<?php

class fm_db_class{

public $formsTable;
public $itemsTable;
public $conn;

function __construct($formsTable, $itemsTable, $conn){
	$this->formsTable = $formsTable;
	$this->itemsTable = $itemsTable;
	$this->conn = $conn;
	$this->cachedInfo = array();
}

//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////
//Cache 

//the fm_db_class appears stateless to the user; however we can keep track of some things to make fewer queries.
//	the $cachedInfo variable is an array of arrays indexed by formID; this should only be used to cache data
//	that will not change (such as data table names) and may be queried more than once. 
protected $cachedInfo;

protected function getCache($formID, $key){
	if(!isset($this->cachedInfo[$formID]) || !isset($this->cachedInfo[$formID][$key])) return null; //return null on cache miss, in order to distinguish from 'false'
	else return $this->cachedInfo[$formID][$key];
}
protected function setCache($formID, $key, $value){
	if(!isset($this->cachedInfo[$formID])) $this->cachedInfo[$formID] = array($key => $value);
	else $this->cachedInfo[$formID][$key] = $value;
}
//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////
//values are the form defaults
public $formSettingsKeys = array('title' => "New Form", 
					'labels_on_top' => 0, 
					'submitted_msg' => 'Thank you! Your data has been submitted.', 
					'submit_btn_text' => 'Submit', 
					'required_msg' => "\'%s\' is required.", 
					'validation_msg' => "\'%s\' is invalid.",
					'action' => '',
					'data_index' => '',
					'shortcode' => '',
					'show_title' => 1,
					'show_border' => 1,
					'label_width' => 200,
					'type' => 'form');			
					
public $itemKeys = array ('type' => 0,
				'index' => 0,
				'extra' => 0,
				'nickname' => 0,
				'label' => 0,
				'required' => 0,
				'validator' => 0,
				'validation_msg' => 0,
				'db_type' => 0,
				'description' => 0);
				

//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////
// Database setup & removal


function setupFormManager(){
	
	//////////////////////////////////////////////////////////////////
	//form definitions table - stoes ID, title, options, and name of data table for each form
	
	$q = "SHOW TABLES LIKE '".$this->formsTable."'";
	$res = $this->query($q);
	if(mysql_num_rows($res) == 0){
	
		$q = "CREATE TABLE `".$this->formsTable."` (".
				"`ID` INT NOT NULL ,".							//stores the unique integer ID of the form
				"`title` TEXT NOT NULL ,".
				"`labels_on_top` BOOL DEFAULT '0' NOT NULL ,".				//labels displayed on top or to the left
				"`submitted_msg` TEXT NOT NULL ,".				//message displayed when user submits data
				"`submit_btn_text` VARCHAR( 32 ) NOT NULL ,".	//text on the 'submit' button
				"`required_msg` TEXT NOT NULL ,".				//message shown in the 'required' popup; use %s in the string to show the field label. If no string is given, default message is used.
				"`validation_msg` TEXT NOT NULL ,".				//validation fail message; use %s in string to show field label. If no string is given, default message is used.
				"`data_table` VARCHAR( 32 ) NOT NULL ,".		//table where the form's submissions are stored
				"`action` TEXT NOT NULL ,".						//form 'action' attribute
				"`data_index` VARCHAR( 32 ) NOT NULL ,".		//data table primary key, if it has one
				"`shortcode` VARCHAR( 64 ) NOT NULL ,".			//shortcode for the form in question (wordpress only)
				"`show_title` BOOL DEFAULT '1' NOT NULL ,".					//display the form title
				"`show_border` BOOL DEFAULT '1' NOT NULL ,".				//display the form border
				"`label_width` VARCHAR( 32 ) NOT NULL ,".		//width of the labels, when displayed on the left
				"`type` VARCHAR( 32 ) NOT NULL ,".				//type of form ('form', 'template')
				"PRIMARY KEY ( `ID` )".
				")";
		$this->query($q);
	}
	
	//create a settings row
	$this->updateSettings(null);
	
	//////////////////////////////////////////////////////////////////
	//form items - stores the items on all forms
	
	$q = "SHOW TABLES LIKE '".$this->itemsTable."'";	
	$res = $this->query($q);
	if(mysql_num_rows($res) == 0){		
		$q = "CREATE TABLE `".$this->itemsTable."` (".
				"`ID` INT NOT NULL ,".							//corresponds to the 'ID' in the forms table
				"`index` INT NOT NULL ,".						//used to order the form items
				"`unique_name` VARCHAR( 64 ) NOT NULL ,".		//unique name given to all items using uniq_id()
				"`type` VARCHAR( 32 ) NOT NULL ,".				//'sanitized' type name - used as array key
				"`extra` TEXT NOT NULL ,".						//serialized array of type specific options
				"`nickname` TEXT NOT NULL ,".					//nickname for internal use, such as on query pages
				"`label` TEXT NOT NULL ,".						//label for the field; can be blank. displayed on top or to the left.
				"`required` BOOL NOT NULL ,".					//required field or not
				"`validator` TEXT NOT NULL ,".					//name of javascript function to validate; passed 'unique_name', returns true/false
				"`validation_msg` TEXT NOT NULL ,".				//overrides the form's default validation message
				"`db_type` VARCHAR( 32 ) NOT NULL ,".			//the type of column in the data table
				"`description` TEXT NOT NULL ,".			//the description of the item displayed below the main label
				"INDEX ( `ID` ) ,".
				"UNIQUE (`unique_name`)".
				");";
		
		$this->query($q);
	}
}

//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////

function removeFormManager(){
	$q = "SELECT `data_table` FROM `{$this->formsTable}`";	
	$res = $this->query($q);
	while($row=mysql_fetch_assoc($res)){
		if($row['data_table'] != ""){			
			$q = "SHOW TABLES LIKE '".$row['data_table']."'";
			$r = $this->query($q);
			if(mysql_num_rows($r) > 0){
				$q="DROP TABLE IF EXISTS `".$row['data_table']."`";				
				$this->query($q);
			}
			mysql_free_result($r);
		}
	}
	mysql_free_result($res);
		
	$q = "DROP TABLE IF EXISTS `{$this->formsTable}`";	
	$this->query($q);
	$q = "DROP TABLE IF EXISTS `{$this->itemsTable}`";	
	$this->query($q);
}

//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////

function query($q){
	//echo $q."<br />";
	$res = mysql_query($q, $this->conn) or die(mysql_error());
	return $res;
}

//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////
// Form Settings

// Update form settings - create a settings row if none exists.
//		$settings - associative array. Only the keyed options are updated.
//			'labels_on_top' must be 1 or 0 
//			'title', 'submitted_msg', 'submit_btn_text', 'required_msg', 'validation_msg' can be any string
//		`ID` stores the (negative of) next available integer ID. All other fields store the default settings, and cannot be set
function updateSettings($newSettings){	
	//see if a settings row exists
	$q = "SELECT * FROM `".$this->formsTable."` WHERE `ID` < 0";
	
	$res = $this->query($q);
	if(mysql_num_rows($res) == 0){
		$q = $this->getDefaultSettingsQuery();
	}
	else if($newSettings != null){
		$settingsRow = mysql_fetch_assoc($res);		
		//make sure only the settings keys are processed

		$toUpdate = array_intersect_key($newSettings,$this->formSettingsKeys);
		$toUpdate = $this->sanitizeFormSettings($toUpdate);
		//make sure we have sanitized settings remaining
		if(sizeof($toUpdate)>0){
			$strArr=array();
			foreach($toUpdate as $k=>$v)
				$strArr[] = "`{$k}` = '".addslashes($newSettings[$k])."'";
			$q = "UPDATE `".$this->formsTable."` SET ".implode(", ",$strArr)." WHERE `ID` = '".$settingsRow['ID']."'";
			$this->query($q);
		}
	}
	mysql_free_result($res);
	$this->query($q);
}

//generate the default form settings query
function getDefaultSettingsQuery(){
	$q = "INSERT INTO `".$this->formsTable."` SET `ID` = '-1' ";
	foreach($this->formSettingsKeys as $setting=>$value)
		$q.=", `{$setting}` = '{$value}'";
	return $q;
}

// Get the default settings row
function getSettings(){
	$q = "SELECT * FROM `".$this->formsTable."` WHERE `ID` < 0";
	$res = $this->query($q);
	$row = mysql_fetch_assoc($res);
	mysql_free_result($res);
	foreach($row as $k=>$v)
		$row[$k]=stripslashes($v);
	return $row;
}

// Get a particular setting
function getSetting($settingName){
	$q = "SELECT `".$settingName."` FROM `".$this->formsTable."` WHERE `ID` < 0";
	$this->query($q);
	$row = mysql_fetch_assoc($res);	
	mysql_free_result($res);
	return stripslashes($row[$settingName]);
}

// Get a new unique form (integer) ID
function getUniqueFormID(){
	$q = "SELECT `ID` FROM `".$this->formsTable."` WHERE `ID` < 0";
	$res = $this->query($q);
	$row = mysql_fetch_assoc($res);
	$intID = (int)$row['ID'];
	$nextID = $intID - 1;
	$q = "UPDATE `".$this->formsTable."` SET `ID` = '".$nextID."' WHERE `ID` = '".$intID."'";
	$this->query($q);
	mysql_free_result($res);
	return $intID*(-1);
}

function getUniqueItemID($type){
	return uniqid($type."-");
}

//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////
// Forms

//////////////////////////////////////////////////////////////////
//Submission data
function isForm($formID){
	$q = "SELECT `ID` FROM `{$this->formsTable}` WHERE `ID` = '{$formID}'";
	$res = $this->query($q);
	$n = mysql_num_rows($res);
	mysql_free_result($res);
	return ($n>0);
}
function processPost($formID, $extraInfo = null){	
	global $fm_controls;
	global $msg;
	
	$formInfo = $this->getForm($formID);
	$dataTable = $this->getDataTableName($formID);
	$postData = array();
	foreach($formInfo['items'] as $item){
		if($item['db_type'] != "NONE")
			$postData[$item['unique_name']] = $fm_controls[$item['type']]->processPost($item['unique_name'], $item);
	}
	if($extraInfo != null && is_array($extraInfo) && sizeof($extraInfo)>0){
		$postData = array_merge($postData, $extraInfo);
	}
	$this->insertSubmissionData($dataTable, $postData);
}
function insertSubmissionData($dataTable, $postData){
	$q = "INSERT INTO `{$dataTable}` SET ";
	$arr = array();
	foreach($postData as $k=>$v)
		$arr[] = "`{$k}` = '".$v."'";
	$q .= implode(",",$arr);
	$this->query($q);
}

function getFormSubmissionData($formID){
	global $fm_controls;
	
	$formInfo = $this->getForm($formID);	
	$postData = $this->getFormSubmissionDataRaw($formID);
	if($postData === false) return false;
	foreach($postData as $index=>$dataRow){
		foreach($formInfo['items'] as $item){
			$postData[$index][$item['unique_name']] = $fm_controls[$item['type']]->parseData($item['unique_name'], $item, $dataRow[$item['unique_name']]);
		}
	}
	
	return $postData;
}

function writeFormSubmissionDataCSV($formID, $fname){
	$formInfo = $this->getForm($formID);
	$data = $this->getFormSubmissionData($formID);
	
	//store the lines in an array
	$csvRows = array();
	
	//store each name in an array
	$fieldNames=array();
	
	//index form fields by unique_name, remove fields with no data
	$newItems = array();
	foreach($formInfo['items'] as $item){
		if($item['db_type'] != "NONE")
			$newItems[$item['unique_name']] = $item;
	}
	$formInfo['items'] = $newItems;
	
	//add the field headers
	$fieldNames[] = 'timestamp';
	$fieldNames[] = 'user';
	foreach($formInfo['items'] as $k=>$v){
		$label = isset($formInfo['items'][$k]) ? $formInfo['items'][$k]['label'] : $k;
		$fieldNames[] = $label;		
	}
	
	$csvRows[] = $fieldNames;	
	
	if($data !== false){
		foreach($data as $dataRow){
			$dataItems=array();
			$dataItems[] = $dataRow['timestamp'];
			$dataItems[] = $dataRow['user'];
			foreach($formInfo['items'] as $k=>$v){
				$dataItems[] = str_replace('"', '""', $dataRow[$k]);
			}
			$csvRows[] = $dataItems;
		}
	}

	$fp = @fopen($fname,'w') or die("Failed to open file: '".$php_errormsg."'");
	
	//use fputcsv instead of reinventing the wheel:
	foreach($csvRows as $csvRow){
		fputcsv($fp, $csvRow);	
	}
	
	fclose($fp);
}

function getFormSubmissionDataRaw($formID){
	$dataTable = $this->getDataTableName($formID);
	$q = "SELECT * FROM `{$dataTable}` ORDER BY `timestamp` DESC";
	$res = $this->query($q);
	if(mysql_num_rows($res) == 0) return false;
	$data=array();
	while($row = mysql_fetch_assoc($res)){
		$data[] = $row;
	}	
	mysql_free_result($res);
	return $data;
}
function deleteSubmissionDataRow($formID, $data){
	$dataTable = $this->getDataTableName($formID);	
	$cond=array();
	foreach($data as $k=>$v){
		if($this->isDataCol($formID, $k)){
			$cond[] = "`{$k}` = '".addslashes($v)."'";
		}
	}
	$q = "DELETE FROM `{$dataTable}` WHERE ".implode(" AND ",$cond)." LIMIT 1";
	$this->query($q);
}
//determines if $uniqueName is a "NONE" db_type or not
function isDataCol($formID, $uniqueName){
	$cacheKey = $uniqueName."-type";
	$type = $this->getCache($formID, $cacheKey);
	if($type == null){
		$q = "SELECT `db_type` FROM `".$this->itemsTable."` WHERE `unique_name` = '{$uniqueName}'";
		$res = $this->query($q);
		$row = mysql_fetch_assoc($res);
		mysql_free_result($res);
		$type = $row['db_type'];
		$this->setCache($formID, $cacheKey, $type);
	}	
	return ($type != "NONE");	
}
function getSubmissionDataNumRows($formID){
	$dataTable = $this->getDataTableName($formID);
	$q = "SELECT COUNT(*) FROM `{$dataTable}`";
	$res = $this->query($q);
	$row = mysql_fetch_row($res);
	mysql_free_result($res);
	return $row[0];
}
function getLastSubmission($formID){
	$dataTable = $this->getDataTableName($formID);
	$q = "SELECT * FROM `{$dataTable}` ORDER BY `timestamp` DESC LIMIT 1";
	$res = $this->query($q);
	$row = mysql_fetch_assoc($res);
	mysql_free_result($res);
	return $row;
}
//////////////////////////////////////////////////////////////////

//returns an indexed array of associative arrays with `ID`, `title`, and `data_table` keys
function getFormList(){
	$q = "SELECT * FROM `".$this->formsTable."` WHERE `ID` >= 0 ORDER BY `ID` ASC";
	$res = $this->query($q);
	$formList=array();
	while($row=mysql_fetch_assoc($res)){
		$row['title']=stripslashes($row['title']);
		$formList[]=$row;		
	}
	mysql_free_result($res);
	return $formList;
}

//gets an associative array containing the form settings and items; the array is the same format as that passed to 'updateForm'
function getForm($formID){
	$formInfo = $this->getFormSettings($formID, $this->formsTable, $this->conn);	
	$formInfo['items']=$this->getFormItems($formID, $this->itemsTable, $this->conn);
	return $formInfo;
}

function getFormID($slug){
	$q = "SELECT `ID` FROM `".$this->formsTable."` WHERE `shortcode` = '".$slug."'";
	$res = $this->query($q);
	if(mysql_num_rows($res)==0) return false;
	$row = mysql_fetch_assoc($res);
	mysql_free_result($res);
	return $row['ID'];
}

function getFormShortcode($formID){
	$q = "SELECT `shortcode` FROM `".$this->formsTable."` WHERE `ID` = '".$formID."'";
	$res = $this->query($q);
	if(mysql_num_rows($res)==0) return false;
	$row = mysql_fetch_assoc($res);
	mysql_free_result($res);
	return $row['shortcode'];
}

//gets a particular form's settings; uses defaults where blank
function getFormSettings($formID){
	global $msg;
	$q = "SELECT * FROM `".$this->formsTable."` WHERE `ID` = '".$formID."'";

	$res = $this->query($q);
 	if(mysql_num_rows($res)==0) return null;
 	$row = mysql_fetch_assoc($res);
 	foreach($row as $k=>$v)
		$row[$k]=stripslashes($v);
 	mysql_free_result($res);
	$defaultSettings = $this->getSettings($this->formsTable, $this->conn);
	foreach($defaultSettings as $k=>$v){
		if(trim($row[$k]) == "") $row[$k] = $v;
	}
 	return $row;
}

//update the settings for a particular form
function updateFormSettings($formID, $formInfo){
	if($formInfo!=null){
		$toUpdate = array_intersect_key($formInfo,$this->formSettingsKeys);
		$toUpdate = $this->sanitizeFormSettings($toUpdate);
		//make sure we have sanitized settings remaining
		if(sizeof($toUpdate)>0){
			$strArr=array();
			foreach($toUpdate as $k=>$v)
				$strArr[] = "`{$k}` = '".addslashes($formInfo[$k])."'";
			$q = "UPDATE `".$this->formsTable."` SET ".implode(", ",$strArr)." WHERE `ID` = '".$formID."'";
			$this->query($q);
		}
	}
}

//update the form. If $formInfoOld is 'null', assumes everything is new
function updateForm($formID, $formInfoNew){
	//update the settings
	$this->updateFormSettings($formID, $formInfoNew);
	//check the old form structure
	
	$formInfoOld = $this->getForm($formID);
	
	$compare = $this->compareFormItems($formInfoOld, $formInfoNew);
	
	foreach($compare['delete'] as $toDelete)  //deletions are stored as their unique name
		$this->deleteFormItem($formID, $toDelete);
	foreach($compare['update'] as $toUpdate) //updates list the entire item arrays
		$this->updateFormItem($formID, $toUpdate['unique_name'], $toUpdate);
	foreach($compare['create'] as $toCreate) //creations give the entire item arrays
		$this->createFormItem($formID, $toCreate['unique_name'], $toCreate);
}
//returns an array with three keys, 'delete', 'update', and 'create'
// 'delete' is an array of the unique names of the items to be deleted
// 'update' and 'create' contain 'item info' arrays of the updated / new values respectively
function compareFormItems($formInfoOld, $formInfoNew){
	$ret = array();
	$ret['delete'] = array();
	$ret['update'] = array();
	$ret['create'] = array();
	
	if(!isset($formInfoNew['items'])) // nothing to change
		return $ret;
		
	//special case: if $formInfoOld is 'null', then everything is new
	if($formInfoOld == null){
		$ret['create'] = $formInfoNew['items'];
		return $ret;
	}
	
	//loop through the old items to determine deletions and updates
	foreach($formInfoOld['items'] as $item){
		//see if the item from the old list is in the new list
		$newItem = $this->formInfoGetItem($item['unique_name'], $formInfoNew);
		//if not, to be deleted
		if($newItem == null) $ret['delete'][] = $item['unique_name'];
		//otherwise needs to be updated, unless nothing has changed
		else if(!$this->itemInfoIsEqual($item, $newItem)) $ret['update'][] = $newItem;
	}
	//loop through the new items to determine creations
	foreach($formInfoNew['items'] as $item){
		//see if the item from the new list is in the old list
		$tempItem = $this->formInfoGetItem($item['unique_name'], $formInfoOld);
		//if not, it is a new item; otherwise it was already added to the update list
		if($tempItem == null) $ret['create'][] = $item;
	}
	return $ret;
}

function deleteForm($formID){
	$dataTable = $this->getDataTableName($formID);
	$q = "DELETE FROM `".$this->formsTable."` WHERE `ID` = '".$formID."'";
	$this->query($q);
	$q = "DELETE FROM `".$this->itemsTable."` WHERE `ID` = '".$formID."'";
	$this->query($q);
	$q = "DROP TABLE IF EXISTS `".$dataTable."`";
	$this->query($q);	
}

//creates a form; returns the ID of the created form
function createForm($formInfo=null, $dataTablePrefix){	
	$newID = $this->getUniqueFormID();
	$dataTable = $dataTablePrefix."_".$newID;
	$q = "INSERT INTO `".$this->formsTable."` SET `ID` = '".$newID."', `data_table` = '".$dataTable."'";
	$this->query($q);
	if($formInfo != null)
		$this->updateForm($newID, $formInfo);
	$this->createDataTable($formInfo, $dataTable);
	return $newID;
}

//creates a data table associated with a form
function createDataTable($formInfo, $dataTable){
	$q = "CREATE TABLE `{$dataTable}` (".
		"`timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ,".
		"`user` VARCHAR( 64 ) NOT NULL";	
	if(isset($formInfo['items']) && sizeof($formInfo['items'])>0){
		$itemArr = array();
		foreach($formInfo['items'] as $item)
			$itemArr[] = "`".$item['unique_name']."` ".($item['db_type']==""||!isset($item['db_type'])?"TEXT":$item['db_type'])." NOT NULL";			
		$itemArr[] = "PRIMARY KEY (`timestamp`)";
		$q.=", ".implode(", ",$itemArr);
	}
	$q.= ") ENGINE = MYISAM ;";
	$this->query($q);
}


//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////
// Items


//change a 'unique_name' for a particular form item. Fails if duplicate, or of 'old' name does not exist
function changeUniqueName($old, $new){
	//first verify that the new name doesn't already exist
	$q = "SELECT `unique_name` FROM `".$this->itemsTable."` WHERE `unique_name` = '".$new."'";
	$res = $this->query($q);
	$n = mysql_num_rows($res);
	mysql_free_result($res);	
	if($n>0) return -1;
	
	//now make sure the old name exists
	$q = "SELECT `unique_name`, `ID`, `db_type` FROM `".$this->itemsTable."` WHERE `unique_name` = '".$old."'";
	$res =$this->query($q);
	$n = mysql_num_rows($res);
	$row = mysql_fetch_assoc($res);
	mysql_free_result($res);	
	if($n==0) return -2;
	$formID = $row['ID'];
	$dbType = $row['db_type'];
	
	//do the swap
	$q = "UPDATE `".$this->itemsTable."` SET `unique_name` = '".$new."' WHERE `unique_name` = '".$old."' LIMIT 1;";
	$this->query($q);
	
	if($dbType!="NONE") $this->changeDataFieldName($old, $new, $formID, $dbType);
	return true;
}
function changeDataFieldName($old, $new, $formID, $dbType){
	$dataTable = $this->getDataTableName($formID);	
	$q = "ALTER TABLE `".$dataTable."` CHANGE `".$old."` `".$new."` ".$dbType;
	$this->query($q);
}

//returns an indexed array of all items in a form
function getFormItems($formID){
	$items=array();
	$q = "SELECT * FROM `".$this->itemsTable."` WHERE `ID` = '".$formID."' ORDER BY `index` ASC";
	$res = $this->query($q);
	if(mysql_num_rows($res)==0) return array();
	$n = mysql_num_rows($res);
	for($x=0;$x<$n;$x++){		
		$items[] = $this->unpackItem(mysql_fetch_assoc($res));
	}
	mysql_free_result($res);
	return $items;
}

//gets an associative array for an individual form item
function getFormItem($uniqueName){
	$q = "SELECT * FROM `".$this->itemsTable."` WHERE `unique_name` = '".$uniqueName."'";
	$res = $this->query($q);
	if(mysql_num_rows($res)==0)	return null;
	$row = $this->unpackItem(mysql_fetch_assoc($res));
	mysql_free_result($res);
	return $row;
}

//by default, new items are placed at the end of the form. 
function createFormItem($formID, $uniqueName, $itemInfo){

	//see if an index has been specified
	if($itemInfo['index']== -1){
		//find the last index in the current table
		$q = "SELECT `index` FROM `".$this->itemsTable."` WHERE `ID` = '".$formID."' ORDER BY `index` DESC";
		$res = $this->query($q);
		$row = mysql_fetch_assoc($res);
		$itemInfo['index'] = $row['index'] + 1;
		mysql_free_result($res);
	}
		//now add the item to the items table
	if(!isset($itemInfo['db_type'])) $itemInfo['db_type'] = 'TEXT';
	$itemInfo = $this->packItem($itemInfo);

	$ignoreKeys = array();
	$setKeys = array();
	$setValues = array();
	$q = "INSERT INTO `".$this->itemsTable."` (`ID`, `unique_name`, ";
	foreach($this->itemKeys as $k=>$v){
		if(!in_array($k,$ignoreKeys)){
			$setKeys[] = "`".$k."`";
			$setValues[] = "'".$itemInfo[$k]."'";
		}
	}
	$q.= implode(",",$setKeys).") VALUES ( '".$formID."', '".$uniqueName."', ".implode(",",$setValues).")";				
	$this->query($q);
	
	//add a field to the data table
	if($itemInfo['db_type'] != "NONE") $this->createFormItemDataField($formID, $uniqueName, $itemInfo);
}	
function createFormItemDataField($formID, $uniqueName, $itemInfo){	
	$dataTable = $this->getDataTableName($formID);		
	$q = "ALTER TABLE `".$dataTable."` ADD `".$uniqueName."` ".$itemInfo['db_type']." NOT NULL";
	$this->query($q);
}

//$itemList contains associative array; key is 'unique_name', 'value' is an $itemInfo for updateFormItem()
function updateFormItemList($formID, $itemList){
	foreach($itemList as $uniqueName => $itemInfo)
		$this->updateFormItem($formID, $uniqueName, $itemInfo);
}

function updateFormItem($formID, $uniqueName, $itemInfo){
	$itemInfo = $this->packItem($itemInfo);
					
	$toUpdate = array_intersect_key($itemInfo,$this->itemKeys);
	$strArr=array();
	foreach($toUpdate as $k=>$v)
		$strArr[] = "`{$k}` = '".$itemInfo[$k]."'";
	$q = "UPDATE `".$this->itemsTable."` SET ".implode(", ",$strArr)." WHERE `unique_name` = '".$uniqueName."'";
	$this->query($q);
	
	//check if the db_type was updated
	if(isset($itemInfo['db_type']) && $itemInfo['db_type'] != "NONE"){
		//check if this is the table's index
		$formInfo = $this->getFormSettings($formID);
		$isIndex = ($formInfo['data_index'] == $uniqueName);
		if($isIndex) $this->removeDataFieldIndex($formID); //remove it and add it again; this would happen anyway, but we also have to deal with the 'text' and 'blob' prefix issue; better to just remove the index and use our own safe index adding function after we change the field type
		$this->updateDataFieldType($formID, $uniqueName, $itemInfo['db_type']);	
		if($isIndex) $this->setDataFieldIndex($formID, $uniqueName, false);
	}
}
function updateDataFieldType($formID, $uniqueName, $newType){
	$dataTable = $this->getDataTableName($formID);
	$q = "ALTER TABLE `".$dataTable."` MODIFY `".$uniqueName."` ".$newType;
	$this->query($q);
}
function setDataFieldIndex($formID, $uniqueName, $remove=true){
	global $msg;
	$indexItem = $this->getFormItem($uniqueName);
	$dbType = $indexItem['db_type'];
	$dataTable = $this->getDataTableName($formID);
	if($remove) $this->removeDataFieldIndex($formID);
	$prefixStr = (strtolower($dbType) == "text" || strtolower($dbType) == "blob")?"(10)":"";
	$q = "ALTER TABLE `".$dataTable."` ADD INDEX (`".$uniqueName."`{$prefixStr})";
	$this->query($q);	
}
function getDataFieldIndex($formID){
	$dataTable = $this->getDataTableName($formID);
	$q = "SHOW INDEXES FROM `".$dataTable."`";
	$res = $this->query($q);
	if(mysql_num_rows($res) == 0) return null;
	$row = mysql_fetch_assoc($res);	
	mysql_free_result($res);
	return $row['Column_name'];
}
function removeDataFieldIndex($formID){
	$dataTable = $this->getDataTableName($formID);
	$q = "SHOW INDEXES FROM `".$dataTable."`";
	$res = $this->query($q);
	while($row = mysql_fetch_assoc($res)){				
		$q = "ALTER TABLE `".$dataTable."` DROP INDEX `".$row['Column_name']."`";
		$this->query($q);
	}	
	mysql_free_result($res);	
}
function deleteFormItem($formID, $uniqueName){
	$q = "SELECT `db_type` FROM `".$this->itemsTable."` WHERE `unique_name` = '".$uniqueName."'";
	$res = $this->query($q);
	$row = mysql_fetch_assoc($res);
	$dbType = $row['db_type'];
	mysql_free_result($res);
	
	$q = "DELETE FROM `".$this->itemsTable."` WHERE `unique_name` = '".$uniqueName."'";
	$this->query($q);
	if($dbType != "NONE") $this->deleteDataField($formID, $uniqueName);
}
function deleteDataField($formID, $uniqueName){	
	$dataTable = $this->getDataTableName($formID);	
	$q = "ALTER TABLE `".$dataTable."` DROP `".$uniqueName."`";	
	$this->query($q);
}

function formInfoGetItem($uniqueName, $formInfo){
	foreach($formInfo['items'] as $item)
		if($item['unique_name'] == $uniqueName) return $item;
	return null;
}
function itemInfoIsEqual($itemA, $itemB){
	foreach($itemA as $k=>$v)
		if(!isset($itemB[$k]) || $itemB[$k] != $itemA[$k]) 
			return false;
	foreach($itemB as $k=>$v)
		if(!isset($itemA[$k])) return false;
	return true;
}

//////////////////////////////////////////////////////////////////
// Nonce

function getNonce(){
	if(!isset($_SESSION['fm-nonce']))
		$this->initNonces();
	
	$nonce = uniqid("fm-nonce-");
	$_SESSION['fm-nonce'][] = $nonce;
	return $nonce;
}
// if $remove is set to false, will not remove the nonce from the session variable
function checkNonce($nonce, $remove = true){
	if(!isset($_SESSION['fm-nonce'])) return false;
	foreach($_SESSION['fm-nonce'] as $k=>$v){
		if($v == $nonce){
			if($remove) unset($_SESSION['fm-nonce'][$k]);
			return true;
		}
	}
	return false;
}

function initNonces(){
	$_SESSION['fm-nonce'] = array();
}

//////////////////////////////////////////////////////////////////
// Helpers
function unpackItem($item){
	foreach($item as $k=>$v){
		if($k != 'extra')
			$item[$k] = stripslashes($item[$k]);
		else			
			$item['extra'] = unserialize($item['extra']);
	}
	return $item;
}

function packItem($item){
	if(!isset($item['extra']) || $item['extra']=="")
		$item['extra'] = array();
		
	foreach($item as $k=>$v){
		if($k != 'extra' || !is_array($item['extra']))
			$item[$k] = addslashes($item[$k]);
		else		
			$item['extra'] = addslashes(serialize($item['extra']));
	}
	return $item;
}

//removes any settings that are improperly formed
function sanitizeFormSettings($settings){
	if(isset($settings['labels_on_top']) && !((int)$settings['labels_on_top']==1 || (int)$settings['labels_on_top']==0))
		unset($settings['labels_on_top']);
	return $settings;
}

function sanitizeUniqueName($name){
	//replace spaces with '-' just to be nice
	$name = str_replace(" ","-",$name);
	//must be lowercase, alphanumeric, exceptions are dash and underscore
	$name = strtolower(preg_replace("/[^a-zA-Z0-9\-_]/","",$name));
	//must begin with a letter; if not, fail
	$firstChar = substr($name,0,1);	
	if(!preg_match("/[a-z]/",$name)) return false;
	return $name;		
}

//cached data table name (should not be changing within a page load)
function getDataTableName($formID){
	$dataTable = $this->getCache($formID, 'data-table');
	if($dataTable == null){
		$q = "SELECT `data_table` FROM `".$this->formsTable."` WHERE `ID` = '".$formID."'";
		$res = $this->query($q);
		$row = mysql_fetch_assoc($res);
		$dataTable = $row['data_table'];
		mysql_free_result($res);
		$this->setCache($formID, 'data-table', $dataTable);
	}
	return $dataTable;
}

}
?>