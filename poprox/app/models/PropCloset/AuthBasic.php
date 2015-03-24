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
use BitsTheater\models\PropCloset\AuthBase as BaseModel;
use BitsTheater\models\SetupDb as MetaModel;
use BitsTheater\models\Accounts; /* @var $dbAccounts Accounts */
use BitsTheater\costumes\IFeatureVersioning;
use BitsTheater\costumes\SqlBuilder;
use BitsTheater\costumes\AccountInfoCache;
use BitsTheater\costumes\HttpAuthHeader;
use com\blackmoonit\exceptions\DbException;
use com\blackmoonit\Strings;
use com\blackmoonit\Arrays;
use \PDO;
{//namespace begin

class AuthBasic extends BaseModel implements IFeatureVersioning {
	/**
	 * Used by meta data mechanism to keep the database up-to-date with the code.
	 * A non-NULL string value here means alter-db-schema needs to be managed.
	 * @var string
	 */
	const FEATURE_ID = 'BitsTheater/AuthBasic';
	const FEATURE_VERSION_SEQ = 3; //always ++ when making db schema changes

	const TYPE = 'basic';
	const ALLOW_REGISTRATION = true;
	const REGISTRATION_SUCCESS = 0;
	const REGISTRATION_NAME_TAKEN = 1;
	const REGISTRATION_EMAIL_TAKEN = 2;
	const REGISTRATION_REG_CODE_FAIL = 3;
	const REGISTRATION_UNKNOWN_ERROR = 4;
	
	const REGISTRATION_ASK_EMAIL = true;
	const REGISTRATION_ASK_PW = true;

	const KEY_cookie = 'seasontickets';
	const KEY_token = 'ticketmaster';

	public $tnAuth; const TABLE_Auth = 'auth';
	public $tnAuthTokens; const TABLE_AuthTokens = 'auth_tokens';
	public $tnAuthMobile; const TABLE_AuthMobile = 'auth_mobile';
	
	/**
	 * @var Config
	 */
	protected $dbConfig = null;
		
	public function setupAfterDbConnected() {
		parent::setupAfterDbConnected();
		if ($this->director->canConnectDb()) {
			$this->dbConfig = $this->getProp('Config');
		}
		$this->tnAuth = $this->tbl_.self::TABLE_Auth;
		$this->tnAuthTokens = $this->tbl_.self::TABLE_AuthTokens;
		$this->tnAuthMobile = $this->tbl_.self::TABLE_AuthMobile;
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
		case self::TABLE_Auth:
			$theTableName = (!empty($aTableNameToUse)) ? $aTableNameToUse : $this->tnAuth;
			switch ($this->dbType()) {
			case self::DB_TYPE_MYSQL: default:
				return "CREATE TABLE IF NOT EXISTS {$theTableName} ".
						"( auth_id CHAR(36) CHARACTER SET ascii NOT NULL COLLATE ascii_bin PRIMARY KEY".
						", email NCHAR(255) NOT NULL".		//store as typed, but collate as case-insensitive
						", account_id INT NOT NULL".		//link to Accounts
						", pwhash CHAR(85) CHARACTER SET ascii NOT NULL COLLATE ascii_bin".	//blowfish hash of pw & its salt
						", verified DATETIME".				//UTC when acct was verified
						", is_reset INT".					//force pw reset in effect since this unix timestamp (if set)
						", _created TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00'".
						", _changed TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP".
						", UNIQUE KEY IdxEmail (email)".
						", INDEX IdxAcctId (account_id)".
						") CHARACTER SET utf8 COLLATE utf8_general_ci";
			}//switch dbType
		case self::TABLE_AuthTokens:
			$theTableName = (!empty($aTableNameToUse)) ? $aTableNameToUse : $this->tnAuthTokens;
			switch ($this->dbType()) {
			case self::DB_TYPE_MYSQL: default:
				return "CREATE TABLE IF NOT EXISTS {$theTableName} ".
						"( auth_id CHAR(36) NOT NULL".
						", account_id INT NOT NULL".
						", token CHAR(128) NOT NULL".
						", _changed TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP".
						", INDEX IdxAuthIdToken (auth_id, token)".
						", INDEX IdxAcctIdToken (account_id, token)".
						", INDEX IdxAuthToken (token, _changed)".
						") CHARACTER SET ascii COLLATE ascii_bin";
			}//switch dbType
		case self::TABLE_AuthMobile: //added in v3
			$theTableName = (!empty($aTableNameToUse)) ? $aTableNameToUse : $this->tnAuthMobile;
			switch ($this->dbType()) {
			case self::DB_TYPE_MYSQL: default:
				return "CREATE TABLE IF NOT EXISTS {$theTableName} ".
						"( `mobile_id` char(36) NOT NULL".
						", `auth_id` CHAR(36) NOT NULL".
						", `account_id` int NOT NULL".
						", `auth_type` char(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'FULL_ACCESS'".
						", `account_token` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'STRANGE_TOKEN'".
						", `device_name` char(64) CHARACTER SET utf8 COLLATE utf8_general_ci DEFAULT NULL".
						", `latitude` decimal(11,8) DEFAULT NULL".
						", `longitude` decimal(11,8) DEFAULT NULL".
						/* might be considered "sensitive", storing hash instead
						", `device_id` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL".
						", `app_version_name` char(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL".
						", `device_memory` BIGINT DEFAULT NULL".
						", `locale` char(8) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL".
						", `app_fingerprint` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL".
						*/
						", `fingerprint_hash` char(85) DEFAULT NULL".
						", `created_ts` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00'".
						", `updated_ts` timestamp ON UPDATE CURRENT_TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP".
						", PRIMARY KEY (`mobile_id`)".
						", KEY `account_id` (`account_id`)".
						", KEY `auth_id` (`auth_id`)".
						") CHARACTER SET ascii COLLATE ascii_bin";
			}//switch dbType
			
		}//switch TABLE const
	}
	
	/**
	 * Called during website installation to create whatever the models needs.
	 * Check the database to be sure anything needs to be done and do not assume
	 * a blank database as updates/reinstalls against recovered databases may
	 * occur as well.
	 * @throws DbException
	 */
	public function setupModel() {
		switch ($this->dbType()) {
		case self::DB_TYPE_MYSQL: default:
			try {
				$theSql = $this->getTableDefSql(self::TABLE_Auth);
				$this->execDML($theSql);
				$this->debugLog('Create table (if not exist) "'.$this->tnAuth.'" succeeded.');
				$theSql = $this->getTableDefSql(self::TABLE_AuthTokens);
				$this->execDML($theSql);
				$this->debugLog('Create table (if not exist) "'.$this->tnAuthTokens.'" succeeded.');
				$theSql = $this->getTableDefSql(self::TABLE_AuthMobile);
				$this->execDML($theSql);
				$this->debugLog('Create table (if not exist) "'.$this->tnAuthMobile.'" succeeded.');
			} catch (PDOException $pdoe){
				throw new DbException($pdoe,$theSql);
			}
			break;
		}
	}
	
	/**
	 * Returns the current feature metadata for the given feature ID.
	 * @param string $aFeatureId - the feature ID needing its current metadata.
	 * @return array Current feature metadata.
	 */
	public function getCurrentFeatureVersion($aFeatureId=null) {
		return array(
				'feature_id' => self::FEATURE_ID,
				'model_class' => $this->mySimpleClassName,
				'version_seq' => self::FEATURE_VERSION_SEQ,
		);
	}
	
	/**
	 * Meta data may be necessary to make upgrades-in-place easier. Check for
	 * existing meta data and define if not present.
	 * @param Scene $aScene - (optional) extra data may be supplied
	 */
	public function setupFeatureVersion($aScene) {
		/* @var $dbMeta MetaModel */
		$dbMeta = $this->getProp('SetupDb');
		$theFeatureData = $dbMeta->getFeature(self::FEATURE_ID);
		if (empty($theFeatureData)) {
			$theFeatureData = $this->getCurrentFeatureVersion();
			$bKeepChecking = true;
			//added an auth_id field in v2
			if ($bKeepChecking) try {
				$theSql = 'SELECT auth_id FROM '.$this->tnAuth.' LIMIT 1';
				$this->query($theSql);
				$bKeepChecking = true;
			} catch(DbException $e) {
				$theFeatureData['version_seq'] = 1;
				$bKeepChecking = false;
			}
			//auth_mobile table added in v3
			if ($bKeepChecking) {
				if (!$this->exists($this->tnAuthMobile)) {
					$theFeatureData['version_seq'] = 2;
					$bKeepChecking = false;
				}
			}
			$dbMeta->insertFeature($theFeatureData);
		}
		$this->returnProp($dbMeta);
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
			//update the cookie table first since its easier and we should empty it
			$tnAuthCookies = $this->tbl_.'auth_cookie'; //v1 table, renamed auth_tokens
			$this->execDML("DROP TABLE IF EXISTS {$tnAuthCookies}");
			$this->execDML("DROP TABLE IF EXISTS {$this->tnAuthTokens}");
			$this->execDML($this->getTableDefSql(self::TABLE_AuthTokens));

			//now update the Auth table... it is a bit trickier.
			//change the default to _changed field (defaulted to 0 rather than current ts)
			$theColDef = "_changed TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP";
			$this->execDML("ALTER TABLE {$this->tnAuth} MODIFY {$theColDef}");
			//remove the primary key
			$this->execDML('ALTER TABLE '.$this->tnAuth.' DROP PRIMARY KEY');
			//add auth_id
			$theColDef = "auth_id CHAR(36) CHARACTER SET ascii NOT NULL DEFAULT 'I_NEED_A_UUID' COLLATE ascii_bin";
			$this->execDML("ALTER TABLE {$this->tnAuth} ADD {$theColDef} FIRST");
			//update all existing records to change default to a UUID()
			$this->execDML("UPDATE {$this->tnAuth} SET auth_id=UUID() WHERE auth_id='I_NEED_A_UUID'");
			//remove default for auth_id
			$theColDef = "auth_id CHAR(36) CHARACTER SET ascii NOT NULL COLLATE ascii_bin";
			$this->execDML("ALTER TABLE {$this->tnAuth} MODIFY {$theColDef}");
			//re-apply primary key
			$this->execDML('ALTER TABLE '.$this->tnAuth.' ADD PRIMARY KEY (auth_id)');
			//put unique key constraint back on email
			$this->execDML('ALTER TABLE '.$this->tnAuth.' ADD UNIQUE KEY (email)');
		case ($theSeq<3):
			//add new table
			$theSql = $this->getTableDefSql(self::TABLE_AuthMobile);
			$this->execDML($theSql);
			$this->debugLog('Create table (if not exist) "'.$this->tnAuthMobile.'" succeeded.');
		}
	}
	
	protected function exists($aTableName=null) {
		return parent::exists( empty($aTableName) ? $this->tnAuth : $aTableName );
	}
	
	public function isEmpty($aTableName=null) {
		return parent::isEmpty( empty($aTableName) ? $this->tnAuth : $aTableName );
	}
	
	public function getAuthByEmail($aEmail) {
		$theSql = "SELECT * FROM {$this->tnAuth} WHERE email = :email";
		return $this->getTheRow($theSql,array('email'=>$aEmail));
	}
	
	public function getAuthByAccountId($aAccountId) {
		$theSql = "SELECT * FROM {$this->tnAuth} WHERE account_id=:id";
		return $this->getTheRow($theSql, array('id' => $aAccountId), array('id' => PDO::PARAM_INT));
	}
	
	public function getAuthByAuthId($aAuthId) {
		$theSql = "SELECT * FROM {$this->tnAuth} WHERE auth_id=:id";
		return $this->getTheRow($theSql, array('id'=>$aAuthId));
	}
	
	public function getAuthTokenRow($aAuthId, $aAuthToken) {
		$theSql = SqlBuilder::withModel($this)->obtainParamsFrom(array(
				'auth_id' => $aAuthId,
				'token' => $aAuthToken,
		));
		$theSql->startWith('SELECT * FROM')->add($this->tnAuthTokens);
		$theSql->startWhereClause()->mustAddParam('auth_id');
		$theSql->setParamPrefix(' AND ')->mustAddParam('token');
		$theSql->endWhereClause();
		return $theSql->getTheRow();
	}
	
	public function getAuthMobilesByAccountId($aAccountId) {
		$theSql = "SELECT * FROM {$this->tnAuthMobile} WHERE account_id=:id";
		$ps = $this->query($theSql, array('id' => $aAccountId), array('id' => PDO::PARAM_INT));
		if (!empty($ps)) {
			return $ps->fetchAll();
		}
	}
	
	public function getAuthMobilesByAuthId($aAuthId) {
		$theSql = "SELECT * FROM {$this->tnAuthMobile} WHERE auth_id=:id";
		$ps = $this->query($theSql, array('id' => $aAuthId));
		if (!empty($ps)) {
			return $ps->fetchAll();
		}
	}
	
	/**
	 * Create and store an auth token mapped to an account (by account_id).
	 * The token is guaranteed to be universally unique.
	 * @param string $aAuthId - token mapped to auth record by this id.
	 * @param number $aAcctId - the account which will map to this token.
	 * @param string $aTweak - (optional) token generation tweak.
	 * @return string Return the token generated.
	 */
	public function generateAuthToken($aAuthId, $aAcctId, $aTweak=null) {
		//64chars of unique gibberish
		$theAuthToken = $aTweak.Strings::urlSafeRandomChars(64-36-1-strlen($aTweak)).':'.Strings::createUUID();
		//save in token table
		$theSql = SqlBuilder::withModel($this)->setDataSet(array(
				'auth_id' => $aAuthId,
				'account_id' => $aAcctId,
				'token' => $theAuthToken,
		));
		$theSql->startWith('INSERT INTO')->add($this->tnAuthTokens);
		$theSql->add('SET')->mustAddParam('auth_id');
		$theSql->setParamPrefix(', ')->mustAddParam('account_id');
		$theSql->mustAddParam('token');
		$theSql->execDML();
		return $theAuthToken;
	}
	
	/**
	 * Return the $delta to add to time() to generate the expiration date.
	 * @param string $aDuration - (optional) one of the config settings, NULL for what is
	 * stored in configuration.
	 * @return void|number Returns the $delta needed to add to time() to get the
	 * cookie expiration date; NULL = no end date, 0 means do not use cookies.
	 */
	public function getCookieDurationInDays($aDuration=null) {
		//check cookie duration
		$delta = 1; //multiplication factor, which is why it is not 0.
		$theDuration = (!empty($aDuration)) ? $aDuration : $this->dbConfig['auth/cookie_freshness_duration'];
		switch ($theDuration) {
			case 'duration_3_months': // => '3 Months',
				$delta = $delta*3;
			default:
			case 'duration_1_month': // => '1 Month',
				$delta = $delta*4;
			case 'duration_1_week': // => '1 Week',
				$delta = $delta*7;
			case 'duration_1_day': // => '1 Day',
				break;
			case 'duration_forever': // => 'Never go stale (not recommended)',
				$delta = null;
				break;
			case 'duration_0': // => 'Do not use cookies!',
				$delta = 0;
				return;
		}//switch
		return $delta;
	}
	
	/**
	 * Return the cookie expriation time based on Config settings.
	 * @return number|null Returns the cookie expiration timestamp parameter. 
	 */
	public function getCookieStaleTimestamp() {
		$delta = $this->getCookieDurationInDays();
		return (!empty($delta)) ? time()+($delta*(60*60*24)) : null;
	}
	
	/**
	 * Create the set of cookies which will be used the next session to re-auth.
	 * @param string $aAuthId - the auth_id used by the account
	 * @param integer $aAcctId
	 */
	public function updateCookie($aAuthId, $aAcctId) {
		try {
			$theAuthToken = $this->generateAuthToken($aAuthId, $aAcctId, 'cA');
			$theStaleTime = $this->getCookieStaleTimestamp();
			setcookie(self::KEY_userinfo, $this->director->app_id.'-'.$aAuthId, $theStaleTime, BITS_URL);
			setcookie(self::KEY_token, $theAuthToken, $theStaleTime, BITS_URL);
		} catch (DbException $e) {
			//do not care if setting cookies fails, log it so admin knows about it, though
			$this->debugLog(__METHOD__.' '.$e->getErrorMsg());
		}
	}
	
	/**
	 * Delete stale cookie tokens.
	 */
	protected function removeStaleCookies() {
		try {
			$delta = $this->getCookieDurationInDays();
			if (!empty($delta)) {
				$theSql = 'DELETE FROM '.$this->tnAuthTokens;
				$theSql .= " WHERE token LIKE 'cA%' AND _changed < (NOW() - INTERVAL {$delta} DAY)";
				$this->execDML($theSql);
			}
		} catch (DbException $e) {
			//do not care if removing stale cookies fails, log it so admin knows about it, though
			$this->debugLog(__METHOD__.' '.$e->getErrorMsg());
		}
	}
	
	/**
	 * Delete stale mobile auth tokens.
	 */
	protected function removeStaleMobileAuthTokens() {
		try {
			$delta = 1;
			if (!empty($delta)) {
				$theSql = 'DELETE FROM '.$this->tnAuthTokens;
				$theSql .= " WHERE token LIKE 'mA%' AND _changed < (NOW() - INTERVAL {$delta} DAY)";
				$this->execDML($theSql);
			}
		} catch (DbException $e) {
			//do not care if removing stale cookies fails, log it so admin knows about it, though
			$this->debugLog(__METHOD__.' '.$e->getErrorMsg());
		}
	}
	
	/**
	 * Returns the token row if it existed and removes it.
	 * @param string $aAuthId
	 * @param string $aAuthToken
	 */
	public function getAndEatCookie($aAuthId, $aAuthToken) {
		//toss out stale cookies first
		$this->removeStaleCookies();
		//now see if our cookie token still exists
		$theAuthTokenRow = $this->getAuthTokenRow($aAuthId, $aAuthToken);
		if (!empty($theAuthTokenRow)) {
			//consume this particular cookie
			$theSql = SqlBuilder::withModel($this)->obtainParamsFrom(array(
					'auth_id' => $aAuthId,
					'token' => $aAuthToken,
			));
			$theSql->startWith('DELETE FROM')->add($this->tnAuthTokens);
			$theSql->startWhereClause()->mustAddParam('auth_id');
			$theSql->setParamPrefix(' AND ')->mustAddParam('token');
			$theSql->endWhereClause();
			$theSql->execDML();
		}
		return $theAuthTokenRow;
	}
	
	/**
	 * Loads all the appropriate data about an account for caching purposes.
	 * @param Accounts $dbAcct - the accounts model.
	 * @param integer $aAccountId - the account id.
	 * @return AccountInfoCache|NULL Returns the data if found, else NULL.
	 */
	protected function getAccountInfoCache(Accounts $dbAccounts, $aAccountId) {
		$theResult = AccountInfoCache::fromArray($dbAccounts->getAccount($aAccountId));
		if (!empty($theResult) && !empty($theResult->account_name)) {
			$theAuthRow = $this->getAuthByAccountId($aAccountId);
			$theResult->email = $theAuthRow['email'];
			$theResult->groups = $this->belongsToGroups($aAccountId);
			return $theResult;
		} else {
			return null;
		}
	}
	
	/**
	 * Check PHP session data for account information.
	 * @param Accounts $dbAcct - the accounts model.
	 * @param object $aScene - var container object for user/pw info; 
	 * if account name is non-empty, skip session data check.
	 * @return boolean Returns TRUE if account was found and successfully loaded.
	 */
	protected function checkSessionForTicket(Accounts $dbAccounts, $aScene) {
		$theUserInput = $aScene->{self::KEY_userinfo};
		//see if session remembers user
		if (isset($this->director[self::KEY_userinfo]) && empty($theUserInput)) {
			$theAccountId = $this->director[self::KEY_userinfo];
			$this->director->account_info = $this->getAccountInfoCache($dbAccounts, $theAccountId);
			if (empty($this->director->account_info)) {
				//something seriously wrong if session data had values, but failed to load
				$this->ripTicket();
			}
		}
		return (!empty($this->director->account_info));
	}
	
	/**
	 * Check submitted webform data for account information.
	 * @param Accounts $dbAcct - the accounts model.
	 * @param object $aScene - var container object for user/pw info.
	 * @return boolean Returns TRUE if account was found and successfully loaded.
	 */
	protected function checkWebFormForTicket(Accounts $dbAccounts, $aScene) {
		if (!empty($aScene->{self::KEY_userinfo}) && !empty($aScene->{self::KEY_pwinput})) {
			$theUserInput = $aScene->{self::KEY_userinfo};
			$theAuthInput = $aScene->{self::KEY_pwinput};
			$theAuthRow = null;
			if ($theAccountRow = $dbAccounts->getByName($theUserInput)) {
				$theAuthRow = $this->getAuthByAccountId($theAccountRow['account_id']);
			} else {
				$theAuthRow = $this->getAuthByEmail($theUserInput);
			}
			if (!empty($theAuthRow)) {
				//check pwinput against crypted one
				$pwhash = $theAuthRow['pwhash'];
				if (Strings::hasher($theAuthInput,$pwhash)) {
					//authorized, load account data
					$this->director->account_info = $this->getAccountInfoCache($dbAccounts, $theAuthRow['account_id']);
					if (!empty($this->director->account_info)) {
						//data retrieval succeeded, save the account id in session cache
						$this->director[self::KEY_userinfo] = $theAuthRow['account_id'];
						//if user asked to remember, save a cookie
						if (isset($_POST[self::KEY_cookie])) {
							$this->updateCookie($theAuthRow['auth_id'], $theAuthRow['account_id']);
						}
					}
				} else {
					//auth fail!
					$this->director->account_info = null;
				}
				unset($theAuthRow);
				unset($pwhash);
			}
			unset($theUserInput);
			unset($theAuthInput);
		}
		unset($aScene->{self::KEY_pwinput});
		unset($_GET[self::KEY_pwinput]);
		unset($_POST[self::KEY_pwinput]);
		unset($_REQUEST[self::KEY_pwinput]);

		return (!empty($this->director->account_info));
	}
	
	/**
	 * Cookies might remember our user if the session forgot and they have 
	 * not tried to login.
	 * @param Accounts $dbAcct - the accounts model.
	 * @param object $aCookieMonster - an object representing cookie keys and data.
	 * @return boolean Returns TRUE if cookies successfully logged the user in.
	 */
	protected function checkCookiesForTicket(Accounts $dbAccounts, $aCookieMonster) {
		if (empty($aCookieMonster[self::KEY_userinfo]) || empty($aCookieMonster[self::KEY_token]))
			return false;
		
		$theAuthId = Strings::strstr_after($aCookieMonster[self::KEY_userinfo], $this->director->app_id.'-');
		if (empty($theAuthId))
			return false;
		
		$theAuthToken = $aCookieMonster[self::KEY_token];
		try {
			//our cookie mechanism consumes cookie on use and creates a new one
			//  by having rotating cookie tokens, stolen cookies have a limited window
			//  in which to crack them before a new one is generated.
			$theAuthTokenRow = $this->getAndEatCookie($theAuthId, $theAuthToken);
			if (!empty($theAuthTokenRow)) {
				$theAccountId = $theAuthTokenRow['account_id'];
				//authorized, load account data
				$this->director->account_info = $this->getAccountInfoCache($dbAccounts, $theAccountId);
				if (!empty($this->director->account_info)) {
					//data retrieval succeeded, save the account id in session cache
					$this->director[self::KEY_userinfo] = $theAccountId;
					//bake (create) a new cookie for next time
					$this->updateCookie($theAuthId, $theAccountId);
				}
				unset($theAuthTokenRow);
			}
		} catch (DbException $e) {
			//do not care if getting cookie fails, log it so admin knows about it, though
			$this->debugLog(__METHOD__.' '.$e->getErrorMsg());
		}
		return (!empty($this->director->account_info));
	}
	
	/**
	 * HTTP Headers may contain authorization information, check for that information and populate whatever we find
	 * for subsequent auth mechanisms to find and evaluate.
	 * @param Accounts $dbAcct - the accounts model.
	 * @param object $aScene - var container object for user/pw info.
	 * @return boolean Returns TRUE if account was found and successfully loaded.
	 */
	protected function checkHeadersForTicket(Accounts $dbAccounts, $aScene) {
		//PHP has some built in auth vars, check them and use if not empty
		if (!empty($_SERVER['PHP_AUTH_USER']) && !empty($_SERVER['PHP_AUTH_PW'])) {
			$aScene->{self::KEY_userinfo} = $_SERVER['PHP_AUTH_USER'];
			$aScene->{self::KEY_pwinput} = $_SERVER['PHP_AUTH_PW'];
			unset($_SERVER['PHP_AUTH_PW']);
			return $this->checkWebFormForTicket($dbAccounts, $aScene);
		}
		//check for HttpAuth header
		$theAuthHeader = new HttpAuthHeader($aScene->HTTP_AUTHORIZATION);
		switch ($theAuthHeader->auth_scheme) {
			case 'Basic':
				$aScene->{self::KEY_userinfo} = $theAuthHeader->username;
				$aScene->{self::KEY_pwinput} = $theAuthHeader->pw_input;
				unset($this->HTTP_AUTHORIZATION); //keeping lightly protected pw in memory can be bad.
				return $this->checkWebFormForTicket($dbAccounts, $aScene);
			case 'Broadway':
				$this->debugLog(__METHOD__.' chkhdr='.$this->debugStr($theAuthHeader));
				if (!empty($theAuthHeader->auth_id) && !empty($theAuthHeader->auth_token)) {
					$this->removeStaleMobileAuthTokens();
					$theAuthTokenRow = $this->getAuthTokenRow($theAuthHeader->auth_id, $theAuthHeader->auth_token);
					$this->debugLog(__METHOD__.' arow='.$this->debugStr($theAuthTokenRow));
					if (!empty($theAuthTokenRow)) {
						$theAuthMobileRows = $this->getAuthMobilesByAuthId($theAuthHeader->auth_id);
						foreach ($theAuthMobileRows as $theMobileRow) {
							$theFingerprintStr = $theAuthHeader->fingerprints;
							$this->debugLog(__METHOD__.' fstr1='.$theFingerprintStr);
							if (Strings::hasher($theFingerprintStr, $theMobileRow['fingerprint_hash'])) {
								$this->debugLog(__METHOD__.' fmatch?=true');
								
								//TODO
								//barring checking circumstances like is GPS outside pre-determined bounds, we authenticated!
								
								$theAccountId = $theAuthTokenRow['account_id'];
								//authorized, load account data
								$this->director->account_info = $this->getAccountInfoCache($dbAccounts, $theAccountId);
								if (!empty($this->director->account_info)) {
									//data retrieval succeeded, save the account id in session cache
									$this->director[self::KEY_userinfo] = $theAccountId;
								}
								unset($theAuthTokenRow);
								return true;
							}
						}
					}//if auth token row !empty
				}
				break;
		}//end switch
	}
	
	/**
	 * Check various mechanisms for authentication.
	 * @see \BitsTheater\models\PropCloset\AuthBase::checkTicket()
	 */
	public function checkTicket($aScene) {
		if ($this->director->canConnectDb()) {
			$dbAccounts = $this->getProp('Accounts');

			if ($this->checkSessionForTicket($dbAccounts, $aScene)) return;
			if ($this->checkHeadersForTicket($dbAccounts, $aScene)) return;
			if ($this->checkWebFormForTicket($dbAccounts, $aScene)) return;
			if ($this->checkCookiesForTicket($dbAccounts, $_COOKIE)) return;
			
			$this->returnProp($dbAccounts);
			//all checks failed to authenticate, call parent to try more (if any)
			parent::checkTicket($aScene);
		}
	}
	
	/**
	 * Log the current user out and wipe the slate clean.
	 * @see \BitsTheater\models\PropCloset\AuthBase::ripTicket()
	 */
	public function ripTicket() {
		try {
			setcookie(self::KEY_userinfo);
			setcookie(self::KEY_token);
			
			$this->removeStaleCookies();
			
			//remove all cookie records for current login (logout means from everywhere but mobile)
			$theSql = SqlBuilder::withModel($this)->obtainParamsFrom(array(
					'account_id' => $this->director->account_info->account_id,
			));
			$theSql->startWith('DELETE FROM')->add($this->tnAuthTokens);
			$theSql->startWhereClause()->mustAddParam('account_id', 0, PDO::PARAM_INT);
			$theSql->setParamPrefix(" AND token LIKE 'cA%'");
			$theSql->endWhereClause();
			$theSql->execDML();
		} catch (DbException $e) {
			//do not care if removing cookies fails, log it so admin knows about it, though
			$this->debugLog(__METHOD__.' '.$e->getErrorMsg());
		}
		parent::ripTicket();
	}

	/**
	 * Given the parameters, can a user register with them?
	 * @see \BitsTheater\models\PropCloset\AuthBase::canRegister()
	 */
	public function canRegister($aAcctName, $aEmailAddy) {
		$dbAccounts = $this->getProp('Accounts');
		$theResult = self::REGISTRATION_SUCCESS;
		if ($dbAccounts->getByName($aAcctName)) {
			$theResult = self::REGISTRATION_NAME_TAKEN;
		} else if ($this->getAuthByEmail($aEmailAddy)) {
			$theResult = self::REGISTRATION_EMAIL_TAKEN;
		}
		$this->returnProp($dbAccounts);
		return $theResult;
	}
		
	/**
	 * Register an account with our website.
	 * @param array $aUserData - email, account_id, pwinput, verified_timestamp.
	 * @param number $aDefaultGroup - (optional) default group membership.
	 * @return boolean Returns TRUE if succeeded, FALSE otherwise.
	 */
	public function registerAccount($aUserData, $aDefaultGroup=0) {
		if ($this->isEmpty()) {
			$aDefaultGroup = 1;
		}
		$theSql = SqlBuilder::withModel($this)->setDataSet(array(
				'email' => $aUserData['email'],
				'account_id' => $aUserData['account_id'],
				'pwhash' => Strings::hasher($aUserData[self::KEY_pwinput]),
				'verified' => $aUserData['verified_timestamp'],
		));
		$theSql->startWith('INSERT INTO '.$this->tnAuth);
		$theSql->add('SET _created=NOW(), auth_id=UUID()')->setParamPrefix(', ');
		$theSql->mustAddParam('email');
		$theSql->mustAddParam('account_id', 0, PDO::PARAM_STR);
		$theSql->mustAddParam('pwhash');
		$theSql->addParam('verified');		
		if ($theSql->execDML()) {
			$dbGroupMap = $this->getProp('AuthGroups');
			$dbGroupMap->addAcctMap($aDefaultGroup,$aUserData['account_id']);
			$this->returnProp($dbGroupMap);
			return true;
		} else {
			return false;
		}
	}
	
	/**
	 * Sudo-type mechanism where "manager override" needs to take place.
	 * It can also be used as a way to prove the current user is still there.
	 * @param number $aAcctId - the account id of the user to auth.
	 * @param string $aPwInput - the entered pw.
	 * @return boolean Returns TRUE if user/pw matched.
	 */
	public function cudo($aAcctId, $aPwInput) {
		$theAuthData = $this->getAuthByAccountId($aAcctId);
		if (!empty($theAuthData['pwhash'])) {
			return (Strings::hasher($aPwInput,$theAuthData['pwhash']));
		} else {
			return false;
		}
	}

	/**
	 * Return currently logged in person's group memberships.
	 */
	public function belongsToGroups($aAcctId) {
		if (empty($aAcctId))
			return array();
		$dbAuthGroups = $this->getProp('AuthGroups');
		$theResult = $dbAuthGroups->getAcctGroups($aAcctId);
		$this->returnProp($dbAuthGroups);
		return $theResult;
	}
	
	/**
	 * Return the defined permission groups.
	 * @see \BitsTheater\models\PropCloset\AuthBase::getGroupList()
	 */
	public function getGroupList() {
		$dbAuthGroups = $this->getProp('AuthGroups');
		$theSql = "SELECT * FROM {$dbAuthGroups->tnGroups} ";
		$r = $dbAuthGroups->query($theSql);
		$theResult = $r->fetchAll();
		$this->returnProp($dbAuthGroups);
		return $theResult;
	}
	
	/**
	 * Standard mechanism to convert the fingerprint array to a string so it can be
	 * hashed or matched against a prior hash. This should match how the mobile app
	 * will be creating the string inside the http auth header.
	 * @param string[] $aFingerprints - the fingerprint array
	 * @return string Returns the array converted to a string.
	 */
	protected function cnvFingerprintArrayToString($aFingerprints) {
		return '['.implode(', ', $aFingerprints).']';
	}
	
	/**
	 * Store device data so that we can determine if user/pw are required again.
	 * @param array $aAuthRow - an array containing the auth row data.
	 * @param $aFingerprints - the device's information to store
	 * @param $aCircumstances - mobile device circumstances (gps, timestamp, etc.)
	 * @return array Returns the field data saved.
	 */
	public function registerMobileFingerprints($aAuthRow, $aFingerprints, $aCircumstances) {
		if (!empty($aAuthRow) && !empty($aFingerprints)) {
			$theSql = SqlBuilder::withModel($this)->obtainParamsFrom($aCircumstances);
			$theSql->startWith('INSERT INTO '.$this->tnAuthMobile);
			$theSql->add('SET created_ts=NOW(), mobile_id=UUID()')->setParamPrefix(', ');
			$theSql->mustAddParam('auth_id', $aAuthRow['auth_id']);
			$theSql->mustAddParam('account_id', $aAuthRow['account_id'], PDO::PARAM_INT);
			$theUserToken = Strings::urlSafeRandomChars(64-36-1).':'.Strings::createUUID(); //unique 64char gibberish
			$theSql->mustAddParam('account_token', $theUserToken);
			$theSql->addParam('device_name');
			$theSql->addParam('latitude');
			$theSql->addParam('longitude');

			//do not store the fingerprints as if db is compromised, this might be
			//  considered "sensitive". just keep a hash instead, like a password.
			//$this->debugLog(__METHOD__.' aid='.$aAuthRow['account_id'].' fp='.$this->debugStr($aFingerprints));
			/*
			$theSql->addParam('device_id');
			$theSql->addParam('app_version_name');
			$theSql->addParam('device_memory');
			$theSql->addParam('locale');
			$theSql->addParam('app_fingerprint');
			*/
			//mimics Java's Arrays.toString(arr) so we do not have to parse the auth header value
			$theFingerprintStr = $this->cnvFingerprintArrayToString($aFingerprints);
			$theFingerprintHash = Strings::hasher($theFingerprintStr);
			$theSql->mustAddParam('fingerprint_hash', $theFingerprintHash);
			//$theSql->mustAddParam('fingerprint_str', $theFingerprintStr);			
			
			$theSql->execDML();
			
			//secret should remain secret, don't blab it back to caller.
			unset($theSql->myParams['fingerprint_hash']);
			
			return $theSql->myParams;
		}
	}
	
	/**
	 * It has been determined that the requestor has made a valid request, generate
	 * a new auth token and return it as well as place it as a cookie with duration of 1 day.
	 * @param number $aAcctId - the account id.
	 * @param string $aAuthId - the auth id.
	 * @return string Returns the auth token generated.
	 */
	protected function generateAuthTokenForMobile($aAcctId, $aAuthId) {
		//generate a token with "mA" so we can tell them apart from cookie tokens
		$theAuthToken = $this->generateAuthToken($aAuthId, $aAcctId, 'mA');
		$theStaleTime = time()+($this->getCookieDurationInDays('duration_1_day')*(60*60*24));
		setcookie(self::KEY_userinfo, $this->director->app_id.'-'.$aAuthId, $theStaleTime, BITS_URL);
		setcookie(self::KEY_token, $theAuthToken, $theStaleTime, BITS_URL);
		return $theAuthToken;
	}
	
	/**
	 * Someone entered a user/pw combo correctly from a mobile device, give them tokens!
	 * @param AccountInfoCache $aAcctInfo - successfully logged in account info.
	 * @param $aFingerprints - mobile device info
	 * @param $aCircumstances - mobile device circumstances (gps, timestamp, etc.)
	 * @return array|NULL Returns the tokens needed for ez-auth later.
	 */
	public function requestMobileAuthAfterPwLogin(AccountInfoCache $aAcctInfo, $aFingerprints, $aCircumstances) {
		$theResults = null;
		$theAuthRow = $this->getAuthByAccountId($aAcctInfo->account_id);
		if (!empty($theAuthRow) && !empty($aFingerprints)) {
			$theMobileRow = null;
			//see if they have a mobile auth row already
			$theAuthMobileRows = $this->getAuthMobilesByAccountId($aAcctInfo->account_id);
			if (!empty($theAuthMobileRows)) {
				$theFingerprintStr = $this->cnvFingerprintArrayToString($aFingerprints);
				//see if fingerprints match any of the existing records and return that user_token if so
				foreach ($theAuthMobileRows as $theAuthMobileRow) {
					if (Strings::hasher($theFingerprintStr, $theAuthMobileRow['fingerprint_hash'])) {
						$theMobileRow = $theAuthMobileRow;
						break;
					}
				}
			}
			//$this->debugLog('mobile_pwlogin'.' mar='.$this->debugStr($theAuthMobileRow));
			if (empty($theMobileRow)) {
				//first time they logged in via this mobile device, record it
				$theMobileRow = $this->registerMobileFingerprints($theAuthRow, $aFingerprints, $aCircumstances);
			}
			if (!empty($theMobileRow)) {
				$this->removeStaleMobileAuthTokens();
				$theAuthToken = $this->generateAuthTokenForMobile($aAcctInfo->account_id, $theAuthRow['auth_id']);
				$theResults = array(
						'account_name' => $aAcctInfo->account_name,
						'auth_id' => $theAuthRow['auth_id'],
						'user_token' => $theMobileRow['account_token'],
						'auth_token' => $theAuthToken,
						'api_version_seq' => $this->getRes('website/api_version_seq'),
				);
			}
		}
		//$this->debugLog('mobile_pwlogin'.' r='.$this->debugStr($theResults));
		return $theResults;
	}
	
	/**
	 * A mobile app is trying to automagically log someone in based on a previously
	 * generated user token and their device fingerprints. If they mostly match, log them in
	 * and generate the proper token cookies.
	 * @param string $aAuthId - the account's auth_id
	 * @param string $aUserToken - the user token previously given by this website
	 * @param $aFingerprints - mobile device info
	 * @param $aCircumstances - mobile device circumstances (gps, timestamp, etc.)
	 * @return array|NULL Returns the tokens needed for ez-auth later.
	 */
	public function requestMobileAuthAutomatedByTokens($aAuthId, $aUserToken, $aFingerprints, $aCircumstances) {
		$theResults = null;
		$dbAccounts = $this->getProp('Accounts');
		$theAuthRow = $this->getAuthByAuthId($aAuthId);
		$theAcctRow = (!empty($theAuthRow)) ? $dbAccounts->getAccount($theAuthRow['account_id']) : null;
		if (!empty($theAcctRow) && !empty($theAuthRow) && !empty($aFingerprints)) {
			//$this->debugLog(__METHOD__.' c='.$this->debugStr($aCircumstances));
			//they must have a mobile auth row already
			$theAuthMobileRows = $this->getAuthMobilesByAccountId($theAcctRow['account_id']);
			if (!empty($theAuthMobileRows)) {
				$theFingerprintStr = $this->cnvFingerprintArrayToString($aFingerprints);
				//see if fingerprints match any of the existing records and return that user_token if so
				foreach ($theAuthMobileRows as $theAuthMobileRow) {
					if (Strings::hasher($theFingerprintStr, $theAuthMobileRow['fingerprint_hash'])) {
						$theUserToken = $theAuthMobileRow['account_token'];
						break;
					}
				}
				//$this->debugLog(__METHOD__.' ut='.$theUserToken.' param='.$aUserToken);
				//if the user_token we found equals the one passed in as param, then authentication SUCCESS
				if (!empty($theUserToken) && $theUserToken===$aUserToken) {
					//$this->debugLog(__METHOD__.' \o/');
					$theAuthToken = $this->generateAuthTokenForMobile($theAcctRow['account_id'], $theAuthRow['auth_id']);
					$theResults = array(
							'account_name' => $theAcctRow['account_name'],
							'user_token' => $theUserToken,
							'auth_token' => $theAuthToken,
							'api_version_seq' => $this->getRes('website/api_version_seq'),
					);
				}
			}
		}
		return $theResults;
	}

}//end class

}//end namespace