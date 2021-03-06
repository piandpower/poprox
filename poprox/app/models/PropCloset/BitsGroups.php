<?php
/*
 * Copyright (C) 2012 Blackmoon Info Tech Services
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace BitsTheater\models\PropCloset;
use BitsTheater\Model as BaseModel;
use BitsTheater\costumes\IFeatureVersioning;
use BitsTheater\costumes\SqlBuilder;
use BitsTheater\models\SetupDb as MetaModel;
use com\blackmoonit\exceptions\DbException;
use com\blackmoonit\Arrays;
use com\blackmoonit\Strings;
use PDO;
use PDOException;
use BitsTheater\BrokenLeg ;
use BitsTheater\Scene;
use BitsTheater\outtakes\RightsException ;
use Exception;
use BitsTheater\costumes\WornForFeatureVersioning;
{//begin namespace

/**
 * Groups were made its own model so that you could have a 
 * auth setup where groups and group memebership were 
 * defined by another entity (BBS auth or WordPress or whatever).
 */
class BitsGroups extends BaseModel implements IFeatureVersioning
{
	use WornForFeatureVersioning;
	
	/**
	 * Used by meta data mechanism to keep the database up-to-date with the code.
	 * A non-NULL string value here means alter-db-schema needs to be managed.
	 * @var string
	 */
	const FEATURE_ID = 'BitsTheater/groups';
	const FEATURE_VERSION_SEQ = 3; //always ++ when making db schema changes

	public $tnGroups;			const TABLE_Groups = 'groups';
	public $tnGroupMap;			const TABLE_GroupMap = 'groups_map';
	public $tnGroupRegCodes;	const TABLE_GroupRegCodes = 'groups_reg_codes';

	/** The constant, assumed ID of the "unregistered user" group. */
	const UNREG_GROUP_ID = 0 ;
	/** The constant, assumed ID of the "titan" superuser group. */
	const TITAN_GROUP_ID = 1 ;

	public function setupAfterDbConnected() {
		parent::setupAfterDbConnected();
		$this->tnGroups = $this->tbl_.self::TABLE_Groups;
		$this->tnGroupMap = $this->tbl_.self::TABLE_GroupMap;
		$this->tnGroupRegCodes = $this->tbl_.self::TABLE_GroupRegCodes;
	}
	
	/**
	 * Future db schema updates may need to create a temp table of one
	 * of the table definitions in order to update the contained data,
	 * putting schema here and supplying a way to provide a different name
	 * allows this process.
	 * @param string $aTABLEconst - one of the defined table name consts.
	 * @param string $aTableNameToUse - (optional) alternate name to use.
	 */
	protected function getTableDefSql($aTABLEconst, $aTableNameToUse=null) {
		switch($aTABLEconst) {
		case self::TABLE_Groups:
			$theTableName = (!empty($aTableNameToUse)) ? $aTableNameToUse : $this->tnGroups;
			switch ($this->dbType()) {
			case self::DB_TYPE_MYSQL: default:
				return "CREATE TABLE IF NOT EXISTS {$theTableName} ".
						"( group_id INT NOT NULL AUTO_INCREMENT".
						", group_name NCHAR(60) NOT NULL".
						", parent_group_id INT NULL".
						//", group_desc NCHAR(200) NULL".
						", PRIMARY KEY (group_id)".
						") CHARACTER SET utf8 COLLATE utf8_general_ci";
			}//switch dbType
		case self::TABLE_GroupMap:
			$theTableName = (!empty($aTableNameToUse)) ? $aTableNameToUse : $this->tnGroupMap;
			switch ($this->dbType()) {
			case self::DB_TYPE_MYSQL: default:
				return "CREATE TABLE IF NOT EXISTS {$theTableName} ".
						"( account_id INT NOT NULL".
						", group_id INT NOT NULL".
						", PRIMARY KEY (account_id, group_id)".
						//", UNIQUE KEY (group_id, account_id)".  IDK if it'd be useful
						") CHARACTER SET utf8 COLLATE utf8_general_ci";
			}//switch dbType
		case self::TABLE_GroupRegCodes:
			$theTableName = (!empty($aTableNameToUse)) ? $aTableNameToUse : $this->tnGroupRegCodes;
			switch ($this->dbType()) {
			case self::DB_TYPE_MYSQL: default:
				return "CREATE TABLE IF NOT EXISTS {$theTableName} ".
						"( group_id INT NOT NULL".
						", reg_code NCHAR(64) NOT NULL".
						", PRIMARY KEY (reg_code, group_id)".
						") CHARACTER SET utf8 COLLATE utf8_general_ci COMMENT='Auto-assign group_id if Registration Code matches reg_code'";
			}//switch dbType
		}//switch TABLE const
	}
	
	/**
	 * Called during website installation and db re-setupDb feature.
	 * Never assume the database is empty.
	 */
	public function setupModel() {
		$this->setupTable( self::TABLE_Groups, $this->tnGroups ) ;
		$this->setupTable( self::TABLE_GroupMap, $this->tnGroupMap ) ;
		$this->setupTable( self::TABLE_GroupRegCodes, $this->tnGroupRegCodes ) ;
	}
	
	/**
	 * When tables are created, default data may be needed in them. Check
	 * the table(s) for isEmpty() before filling it with default data.
	 * @param Scene $aScene - (optional) extra data may be supplied
	 */
	public function setupDefaultData($aScene) {
		if ($this->isEmpty()) {
			$group_names = $aScene->getRes('AuthGroups/group_names');
			$default_data = array(
					array('group_id'=>1, 'group_name'=>$group_names[1],),
					array('group_id'=>2, 'group_name'=>$group_names[2],),
					array('group_id'=>3, 'group_name'=>$group_names[3],),
					array('group_id'=>4, 'group_name'=>$group_names[4],),
					array('group_id'=>5, 'group_name'=>$group_names[0],),
			);
			$theSql = "INSERT INTO {$this->tnGroups} ".
					"(group_id, group_name) VALUES (:group_id, :group_name)";
			$theParamTypes = array('group_id'=>PDO::PARAM_INT, 'group_name'=>PDO::PARAM_STR,);
			$this->execMultiDML($theSql,$default_data,$theParamTypes);
			//set group_id 5 to 0, cannot set 0 on insert since auto-inc columns in MySQL interpret 0 as "next id" instead of just 0.
			$theSql = 'UPDATE '.$this->tnGroups.' SET group_id=0 WHERE group_id=5';
			$this->execDML($theSql);
		}
		if ($this->isEmpty($this->tnGroupRegCodes)) {
			$theSql = "INSERT INTO {$this->tnGroupRegCodes} SET group_id=3, reg_code=".'"'.$this->director->app_id.'"';
			$this->execDML($theSql);
		}
	}
	
	/**
	 * Other models may need to query ours to determine our version number
	 * during Site Update. Without checking SetupDb, determine what version
	 * we may be running as.
	 * @param Scene $aScene - (optional) extra data may be supplied
	 */
	public function determineExistingFeatureVersion($aScene) {
		switch ($this->dbType()) {
			case self::DB_TYPE_MYSQL: default:
				if (!$this->exists($this->tnGroupRegCodes))
					return 1 ;
				else if( $this->isFieldExists( 'group_type', $this->tnGroups ) )
					return 2 ;
		}//switch
		return self::FEATURE_VERSION_SEQ ;
	}
	
	/**
	 * Check current feature version and compare it to the
	 * current version, upgrading the db schema as needed.
	 * @param array $aFeatureMetaData - the models current feature metadata.
	 * @param Scene $aScene - (optional) extra data may be supplied
	 */
	public function upgradeFeatureVersion($aFeatureMetaData, $aScene) {
		$theSeq = $aFeatureMetaData['version_seq'];
		switch (true) {
		//cases should always be lo->hi, never use break; so all changes are done in order.
		case ($theSeq<2):
			//create new GroupRegCodes table
			$this->setupModel();
			$this->setupDefaultData($aScene);
		case ($theSeq<3):
			if( $this->isFieldExists( 'group_type', $this->tnGroups ) )
			{
				//two step process to remove the unused field: re-number the group_id=5, 0-group-type to ID=0
				$theSql = 'UPDATE '.$this->tnGroups.' SET group_id=0 WHERE group_id=5 AND group_type=0 LIMIT 1';
				$this->execDML($theSql);
				//now alter the table and drop the column
				$theSql = 'ALTER TABLE '.$this->tnGroups.' DROP group_type';
				$this->execDML($theSql);
			}
		}//switch
	}
	
	protected function exists($aTableName=null) {
		return parent::exists( empty($aTableName) ? $this->tnGroups : $aTableName );
	}
	
	public function isEmpty($aTableName=null) {
		return parent::isEmpty( empty($aTableName) ? $this->tnGroups : $aTableName );
	}
	
	/**
	 * Retrieve a single group row.
	 * @param string $aGroupId - the group_id to get.
	 * @param string $aFieldList - which fields to return, default is all of them.
	 * @return array Returns the row data.
	 */
	public function getGroup($aGroupId, $aFieldList=null) {
		$theResults = null;
		if ($this->isConnected() && !empty($aGroupId)) try {
			$theSql = SqlBuilder::withModel($this)->obtainParamsFrom(array('group_id' => $aGroupId));
			$theSql->startWith('SELECT')->addFieldList($aFieldList)->add('FROM')->add($this->tnGroups);
			$theSql->startWhereClause()->mustAddParam('group_id', null, PDO::PARAM_INT)->endWhereClause();
			$theResults = $theSql->getTheRow();
		} catch (PDOException $pdoe) {
			throw new DbException($pdoe, __METHOD__.' failed.');
		}
		return $theResults;
	}
	
	/**
	 * Insert a group record.
	 * @param Object|Scene $aDataObject - the (usually Scene) object containing the POST data used for insert.
	 * @return Returns the data posted to the database.
	 */
	public function add($aDataObject) {
		$theResultSet = null;
		if ($this->isConnected() && !empty($aDataObject)) try {
			$theSql = SqlBuilder::withModel($this)->obtainParamsFrom($aDataObject);
			$theSql->startWith('INSERT INTO')->add($this->tnGroups);
			$theSql->add('SET')->mustAddParam('group_name')->setParamPrefix(', ');
			$theSql->mustAddParam('parent_group_id', null, PDO::PARAM_INT);
			$theSql->addParamIfDefined('group_id', 0, PDO::PARAM_INT);
			//$this->debugLog($this->debugStr($theSql));
			$theSql->execDML();
			$theResultSet = $theSql->myParams;
		} catch (PDOException $pdoe) {
			throw new DbException($pdoe, __METHOD__.' failed.');
		}
		return $theResultSet;
	}
	
	/**
	 * Remove a group and its child data.
	 * @param integer $aGroupId - the group ID.
	 * @return Returns an array('group_id'=>$aGroupId).
	 */
	public function del($aGroupId) {
		$theGroupId = intval($aGroupId);
		$theResultSet = null;
		if ($this->isConnected() && $theGroupId>self::TITAN_GROUP_ID) try {
			$this->db->beginTransaction();
			
			$theSql = SqlBuilder::withModel($this)->obtainParamsFrom(array('group_id' => $theGroupId));
			$theSql->startWith('DELETE FROM')->add($this->tnGroupMap);
			$theSql->startWhereClause()->mustAddParam('group_id', null, PDO::PARAM_INT)->endWhereClause();
			$theSql->execDML();
			$theSql->reset()->startWith('DELETE FROM')->add($this->tnGroups);
			$theSql->startWhereClause()->mustAddParam('group_id', null, PDO::PARAM_INT)->endWhereClause();
			$theSql->execDML();
			
			$this->db->commit();
			$theResultSet = $theSql->myParams;
		} catch (PDOException $pdoe) {
			$this->db->rollBack();
			throw new DbException($pdoe, __METHOD__.' failed.');
		}
		return $theResultSet;
	}
	
	/**
	 * Add a record to the account/group map table.
	 * @param integer $aGroupId - the group ID.
	 * @param integer $aAcctId - the account ID.
	 * @return Returns the data added.
	 */
	public function addAcctMap($aGroupId, $aAcctId) {
		$theGroupId = intval($aGroupId);
		$theAcctId = intval($aAcctId);
		$theResultSet = null;
		if ($this->isConnected() && $theGroupId>=self::UNREG_GROUP_ID) try {
			$theSql = SqlBuilder::withModel($this)->obtainParamsFrom(array(
					'group_id' => $theGroupId,
					'account_id' => $theAcctId,
			));
			$theSql->startWith('INSERT INTO')->add($this->tnGroupMap);
			$theSql->add('SET')->mustAddParam('group_id', null, PDO::PARAM_INT)->setParamPrefix(', ');
			$theSql->mustAddParam('account_id', null, PDO::PARAM_INT);
			$theSql->execDML();
			$theResultSet = $theSql->myParams;
		} catch (PDOException $pdoe) {
			throw new DbException($pdoe, __METHOD__.' failed.');
		}
		return $theResultSet;
	}
	
	/**
	 * Remove a record from the account/group map table.
	 * @param integer $aGroupId - the group ID.
	 * @param integer $aAcctId - the account ID.
	 * @return Returns the data removed.
	 */
	public function delAcctMap($aGroupId, $aAcctId) {
		$theGroupId = intval($aGroupId);
		$theAcctId = intval($aAcctId);
		$theResultSet = null;
		if ($this->isConnected() && $theGroupId>=self::UNREG_GROUP_ID) try {
			$theSql = SqlBuilder::withModel($this)->obtainParamsFrom(array(
					'group_id' => $theGroupId,
					'account_id' => $theAcctId,
			));
			$theSql->startWith('DELETE FROM')->add($this->tnGroupMap);
			$theSql->startWhereClause();
			$theSql->mustAddParam('group_id', null, PDO::PARAM_INT)->setParamPrefix(' AND ');
			$theSql->mustAddParam('account_id', null, PDO::PARAM_INT);
			$theSql->endWhereClause();
			$theSql->execDML();
			$theResultSet = $theSql->myParams;
		} catch (PDOException $pdoe) {
			throw new DbException($pdoe, __METHOD__.' failed.');
		}
		return $theResultSet;
	}
	
	/**
	 * Get the groups a particular account belongs to.
	 * @param integer $aAcctId - the account ID.
	 * @return Returns the array of group IDs.
	 */
	public function getAcctGroups($aAcctId) {
		$theAcctId = intval($aAcctId);
		$theResultSet = null;
		if ($this->isConnected() && isset($theAcctId)) try {
			$theSql = SqlBuilder::withModel($this)->obtainParamsFrom(array(
					'account_id' => $theAcctId,
			));
			$theSql->startWith('SELECT group_id FROM')->add($this->tnGroupMap);
			$theSql->startWhereClause();
			$theSql->mustAddParam('account_id', null, PDO::PARAM_INT);
			$theSql->endWhereClause();
			$rs = $theSql->query();
			$theResultSet = $rs->fetchAll(PDO::FETCH_COLUMN, 0);
			foreach ($theResultSet as &$theGroupId) {
				$theGroupId = intval($theGroupId);
			}
		} catch (PDOException $pdoe) {
			throw new DbException($pdoe, __METHOD__.' failed.');
		}
		return $theResultSet;
	}
	
	/**
	 * Creates a new user group.
	 * @param string $aGroupName the new group's name
	 * @param integer $aGroupParentId the ID of the group from which permission
	 *  settings should be inherited (default null) (deprecated in Pulse 3.0)
	 * @param string $aGroupRegCode the group's registration code (default
	 *  blank)
	 * @param integer $aGroupCopyID the ID of a group from which permissions
	 *  should be *copied* into the new group.
	 * @throws DbException if something goes wrong in the DB
	 */
	public function createGroup( $aGroupName, $aGroupParentId=null, $aGroupRegCode=null, $aGroupCopyID=null )
	{
		if( empty( $this->db ) || ! $this->isConnected() )
			throw BrokenLeg::toss( $this, 'DB_CONNECTION_FAILED' ) ;
		
		$theGroupParentId = intval($aGroupParentId);

		$theSql = SqlBuilder::withModel($this)->obtainParamsFrom(array(
				'group_name' => $aGroupName,
				'parent_group_id' => (!empty($theGroupParentId)) ? $theGroupParentId : null,
		));
		$theSql->startWith( 'INSERT INTO ' . $this->tnGroups ) ;
		$theSql->add('SET')->mustAddParam('group_name')->setParamPrefix(', ');
		$theSql->addParam('parent_group_id');
		$theGroupID = 0 ;
		try { $theGroupID = $theSql->addAndGetId() ; }
		catch( PDOException $pdox )
		{
			throw new DbException( $pdox, __METHOD__
					. ' failed when inserting a new user group.' ) ;
		}
		
		if( ! empty( $theGroupID ) && ! empty( $aGroupRegCode ) )
			$this->insertGroupRegCode( $theGroupID, $aGroupRegCode ) ;

		$theResults = array(
				'group_id' => $theGroupID,
				'group_name' => $aGroupName,
				'parent_group_id' => $aGroupParentId,
				'reg_code' => (!empty($aGroupRegCode)) ? $aGroupRegCode : null,
		);

		if( ! empty( $theGroupID ) && ! empty( $aGroupCopyID ) )
		{
			$dbPerms = $this->getProp('Permissions') ;
			try
			{
				$theCopyResult =
					$dbPerms->copyPermissions( $aGroupCopyID, $theGroupID ) ;
				$theResults['copied_group'] = $aGroupCopyID ;
				$theResults['copied_perms'] = $theCopyResult['count'] ;
			}
			catch( RightsException $rx )
			{
				$this->debugLog( __METHOD__
						. ' failed to copy permissions for group ['
						. $aGroupCopyID . '] because of a RightsException: '
						. $rx->getMessage()
						);
				$theResults['copied_group'] = -1 ;
				$theResults['group_copy_error'] = $rx->getMessage() ;
			}
			catch( BrokenLeg $blx )
			{
				$this->debugLog( __METHOD__
						. ' failed to copy permissions for group ['
						. $aGroupCopyID . '] because of a BrokenLeg: '
						. $blx->getMessage()
					);
				$theResults['copied_group'] = -1 ;
				$theResults['group_copy_error'] = $blx->getMessage() ;
			}
			catch( DbException $dbx )
			{
				$this->debugLog( __METHOD__
						. ' failed to copy permissions for group ['
						. $aGroupCopyID . '] because of a DbException: '
						. $dbx->getMessage()
						);
				$theResults['copied_group'] = -1 ;
				$theResults['group_copy_error'] = $dbx->getMessage() ;
			}
			catch( Exception $x )
			{
				$this->debugLog( __METHOD__
						. ' failed to copy permissions for group ['
						. $aGroupCopyID . ']: '
						. $x->getMessage()
						);
				$theResults['copied_group'] = -1 ;
				$theResults['group_copy_error'] = $x->getMessage() ;
			}
		}

		return $theResults ;
	}

	/**
	 * Updates an existing group.
	 * @param Scene $aScene a scene containing usergroup data
	 * @throws DbException if something goes wrong in the DB
	 */
	public function modifyGroup( Scene $v )
	{
		if( empty( $this->db ) || ! $this->isConnected() )
			throw BrokenLeg::toss( $this, 'DB_CONNECTION_FAILED' ) ;

		$theSql = SqlBuilder::withModel($this)->obtainParamsFrom($v)
			->startWith( 'UPDATE ' . $this->tnGroups )
			->add( 'SET' )
			->mustAddParam( 'group_name' )
			->setParamPrefix( ', ' )
			->mustAddParam( 'parent_group_id', null, PDO::PARAM_INT )
			->startWhereClause()
			->mustAddParam( 'group_id', null, PDO::PARAM_INT )
			->endWhereClause()
			;
		try { $theSql->execDML() ; }
		catch( PDOException $pdox )
		{
			throw new DbException( $pdox, __METHOD__
				. ' failed when updating the group data.' ) ;
		}
								
		$theSql = SqlBuilder::withModel($this)->obtainParamsFrom($v)
			->startWith( 'DELETE FROM ' . $this->tnGroupRegCodes )
			->startWhereClause()
			->mustAddParam( 'group_id' )
			->endWhereClause()
			;
		try { $theSql->execDML() ; }
		catch( PDOException $pdox )
		{
			throw new DbException( $pdox, __METHOD__
				. ' failed when deleting the old registration code.' ) ;
		}

		if( isset( $v->reg_code ) && ! empty( $v->reg_code ) )
			$this->insertGroupRegCode( $v->group_id, $v->reg_code ) ;

		return array(
				'group_id' => $v->group_id,
				'group_name' => $v->group_name,
				'parent_group_id' => $v->parent_group_id,
				'reg_code' => $v->reg_code,
		);
	}

	/**
	 * Consumed by createGroup() and modifyGroup() to insert a new registration
	 * code for an existing group ID.
	 * @param integer $aGroupID the group ID
	 * @param string $aRegCode the new registration code
	 */
	protected function insertGroupRegCode( $aGroupID, $aRegCode )
	{
		$theGroupId = intval($aGroupID);
		$theRegCode = trim($aRegCode);
		if ($theGroupId>self::UNREG_GROUP_ID && !empty($theRegCode))
		{
			$theSql = SqlBuilder::withModel($this)->obtainParamsFrom(array(
					'group_id' => $theGroupId,
					'reg_code' => $theRegCode,
			));
			$theSql->startWith( 'INSERT INTO ' . $this->tnGroupRegCodes );
			$theSql->add('SET')->mustAddParam('group_id')->setParamPrefix(', ');
			$theSql->mustAddParam('reg_code');
			try { $theSql->execDML() ; }
			catch( PDOException $pdox )
			{ throw new DbException( $pdox, __METHOD__ . ' failed.' ) ; }
		}
	}

	/**
	 * @return Return array(group_id => reg_code).
	 */
	public function getGroupRegCodes() {
		$theSql = "SELECT * FROM {$this->tnGroupRegCodes} ORDER BY group_id";
		$ps = $this->query($theSql);
		$theResult = Arrays::array_column_as_key($ps->fetchAll(), 'group_id');
		return $theResult;
	}
	
	/**
	 * See if an entered registration code matches a group_id.
	 * @param string $aAppId - the site app_id.
	 * @param string $aRegCode - the entered registration code.
	 * @return integer Returns the group_id which matches or 0 if none.
	 */
	public function findGroupIdByRegCode($aAppId, $aRegCode) {
		$theRegCode = trim($aRegCode);
		if (!$this->isEmpty($this->tnGroupRegCodes)) {
			$theSql = SqlBuilder::withModel($this)->obtainParamsFrom(array(
					'reg_code' => $theRegCode,
			));
			$theSql->startWith('SELECT group_id FROM')->add($this->tnGroupRegCodes);
			$theSql->startWhereClause()->mustAddParam('reg_code')->endWhereClause();
			$theRow = $theSql->getTheRow();
			return (!empty($theRow)) ? $theRow['group_id']+0 : self::UNREG_GROUP_ID;
		} else {
			return ($theRegCode==$aAppId) ? 3 : self::UNREG_GROUP_ID;
		}
	}

	/**
	 * Returns a dictionary of all permission group data.
	 * @param $bIncludeSystemGroups boolean indicates whether to include the
	 *  "unregistered" and "titan" groups that are defined by default when the
	 *  system is installed
	 * @throws BrokenLeg if a DB connection can't be established
	 * @throws DbException if an error happens in the query itself
	 */
	public function getAllGroups( $bIncludeSystemGroups=false )
	{
		if( empty( $this->db ) || ! $this->isConnected() )
			throw BrokenLeg::toss( $this, 'DB_CONNECTION_FAILED' ) ;

		$theSql = SqlBuilder::withModel($this)
			->startWith( 'SELECT G.group_id, G.group_name,' )
			->add( 'G.parent_group_id, GRC.reg_code' )
			->add( 'FROM ' . $this->tnGroups . ' AS G' )
			->add( 'LEFT JOIN ' . $this->tnGroupRegCodes )
			->add(   'AS GRC USING (group_id)' )
			;
		if( ! $bIncludeSystemGroups )
		{
			$theSql->startWhereClause()
				->setParamOperator( '<>' )
			 	->addFieldAndParam( 'group_id', 'unreg_group_id',
			 			self::UNREG_GROUP_ID, PDO::PARAM_INT )
			 	->setParamPrefix( ' AND ' )
				->addFieldAndParam( 'group_id', 'titan_group_id',
						self::TITAN_GROUP_ID, PDO::PARAM_INT )
				->endWhereClause()
				;
		}
		$theSql->add( 'ORDER BY G.group_id' ) ;

		try { return $theSql->query()->fetchAll() ; }
		catch( PDOException $pdox )
		{ throw new DbException( $pdox, __METHOD__ . ' failed.' ) ; }
	}

	/**
	 * Indicates whether a group with the specified ID is defined.
	 * @param integer $aGroupID the sought group ID
	 * @return boolean true if the group is found, false otherwise
	 */
	public function groupExists( $aGroupID=null )
	{
		$theGroupId = intval($aGroupID);
		if( $theGroupId <= self::UNREG_GROUP_ID ) return false ;

		$theSql = SqlBuilder::withModel($this)
			->startWith( 'SELECT group_id FROM ' . $this->tnGroups )
			->startWhereClause()
			->mustAddParam( 'group_id', $theGroupId, PDO::PARAM_INT )
			->endWhereClause()
			;
		$theResult = $theSql->getTheRow() ;
		return ( empty( $theResult ) ? false : true ) ;
	}

}//end class

}//end namespace
