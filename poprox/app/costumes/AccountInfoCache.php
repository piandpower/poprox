<?php
/*
 * Copyright (C) 2015 Blackmoon Info Tech Services
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

namespace BitsTheater\costumes;
use BitsTheater\costumes\ASimpleCostume as BaseCostume;
{//namespace begin

/**
 * Helper class for session caching current non-sensitive login account info.
 */
class AccountInfoCache extends BaseCostume {
	/** @var integer */
	public $account_id = null;
	/** @var string */
	public $account_name = null;
	/** @var integer */
	public $external_id = null;
	/** @var string */
	public $email = null;
	/** @var array */
	public $groups = array(0);
	
}//end class

}//end namespace
