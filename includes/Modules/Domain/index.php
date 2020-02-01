<?php
/*

Official Website: http://www.cctld.net
Author - Lipatov Yuriy
Email - richman@mail.ru

*/
ini_set('log_errors', 'On');
ini_set('error_log', '/log.txt');

class cctld extends DomainModule {
	public $pass=0;
	protected $description	= 'CCTLD domain registrar module';
	protected $modname		= "cctld";
	protected $version		= "1.0";
	protected $client_data	= array();
	protected $configuration= array(
		'user_login' => array(
			'value'		=> '',
			'type'		=> 'input',
			'default'	=> false
		),
		'user_password' => array(
			'value'		=> '',
			'type'		=> 'input',
			'default'	=> false
		),
		'url' => array(
			'value'		=> '',
			'type'		=> 'input',
			'default'	=> false
		),
	);
	protected $lang = array(
			'english' => array(
			'user_login'=> 'User Login',
			'user_password'	=> 'User Password',
			'url'	=> 'Call URL',
		)
	);
	protected $commands = array(
								'UpdateContact',
								'Register',
								'Renew',
								'RecreateContact',
								'Test');
	protected $clientCommands = array(
        'ContactInfo',
		'DNSManagement',
		'RegisterNameServers',
		'EppCode');
	 private $apiSession = null;
	 
	 //test connection 
	public function testConnection(){
		$cookie=$this->auth_call();
		if (!empty($cookie)){
			return 'success';
		}
	}
	
	//getting activation and save cookie
	private function auth_call() {
    	$address_auth = $this->configuration['url']['value'];
		$data ='<data><sLogin>'.$this->configuration['user_login']['value'].'</sLogin><sPass>'.$this->configuration['user_password']['value'].'</sPass></data>';
		$action = 'auth';
    	$ch = curl_init();
			curl_setopt($ch,CURLOPT_URL,$address_auth);
			curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
            curl_setopt($ch,CURLOPT_VERBOSE ,true);
			curl_setopt($ch, CURLOPT_HEADER, 1);
			curl_setopt($ch,CURLOPT_TIMEOUT,90);
			curl_setopt($ch,CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch,CURLOPT_COOKIE,"0");
            curl_setopt($ch,CURLOPT_USERAGENT,"HostBill/{$this->version} (PHP ".phpversion().")");
            curl_setopt($ch,CURLOPT_POST,true);
            curl_setopt($ch, CURLOPT_POSTFIELDS,"action=$action&data=$data");
           	
    	$response = curl_exec($ch);
		curl_close($ch);
		
		preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $response, $matches);
		$cookies = array();
		foreach($matches[1] as $item) {
			parse_str($item, $cookie);
			$cookies = array_merge($cookies, $cookie);
		}
		$cookie='PHPSESSID='.$cookies['PHPSESSID'];
		return $cookie;
    }
	
	//send any data to CCTLD
	private function call($data,$action) {
    	$ch = curl_init();
			curl_setopt($ch,CURLOPT_URL,$this->configuration['url']['value']);
			curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
			curl_setopt($ch, CURLOPT_HEADER, 0);
			curl_setopt($ch,CURLOPT_COOKIE,$GLOBALS["$pass"]); 
            curl_setopt($ch,CURLOPT_POST,true);
            curl_setopt($ch, CURLOPT_POSTFIELDS,"action=$action&data=$data");
			$response = curl_exec($ch);
		curl_close($ch);
		return $response;
    }
	
	//scheduled synchronization domain status 
	public function synchInfo(){
		$GLOBALS["$pass"]=$this->auth_call();
		$res=$this->getDomainInfo();
		$xml = new SimpleXMLElement($res);
		switch ($xml->dStatus){
			case "ACTIV":
				$return['status'] = "Active";
				$return['expires']=substr($xml->dExpired,0,10);
				$return['date_created']=substr($xml->dRegistered,0,10);
			return $return;
			case "R_REG":
				$return['status'] = "Pending Registration";
				$this->addInfo("Домен регистрируется");
			return $return;
		if 	(($xml->rcode)==0){
			$return['status'] = "Pending";
		}
		}
	}
	
	//register new domain name (executing after successful payment)
	public function Register(){
		$GLOBALS["$pass"]=$this->auth_call();
		$clientid=$this->check_cctld_exist();
		if (empty($clientid)){
			$clientid=$this->addContact();
		}
		$this->set_client_cctld_id($clientid);
		$this->addNewDomain($clientid);
		$this->addDomain('Pending Registration');
		$this->addInfo('Domain registration pending');
		$this->addInfo('Domain registered on'.$this->options['numyears'].' years');
		return true;
	}
	
	//send request for renewing domain period
	public function Renew(){
		$GLOBALS["$pass"]=$this->auth_call();
		$domeninfo=$this->getDomainInfo();
		$xml = new SimpleXMLElement($domeninfo);
		$data='<data>
					<id>'.$xml->dID.'</id>
					<year>'.$this->options['numyears'].'</year>
				</data>';
		$action='ddeleg';
		$res=$this->call($data,$action);
		if (($xml->rcode)==1){
			$this->addPeriod();
			$this->addInfo('Domain has been renewed');
			return true;
			}
		else{
			$this->addError('Unknown error'); 
			return false;}
	}
	
	//getting existing domain info from CCTLD by domain name
	public function getDomainInfo(){
		$data ='<data><dname>'.$this->options['sld'].'.'.$this->options['tld'].'</dname></data>';
		$action = 'dnamelist';
		$response=$this->call($data,$action);
		return $response;
	}
	
	public function Transfer(){}
	
	//send contact data to CCTLD for registering new contact
	public function addContact(){
			$type=$this->get_user_type();
			$client_data = new ApiWrapper();
			$params = ['id'=>$this->client_data['id']];
			$return = $client_data->getClientDetails($params);
			$region_id=$this->get_region_id();
			$passport_date=$this->get_passport_date();
			$data ='<data>
					<sContract>N</sContract>
					<sOrgEN>'.$this->client_data['companyname'].'</sOrgEN>
					<sOrgRU>'.$this->client_data['companyname'].'</sOrgRU>
					<sOrgUZ>'.$this->client_data['companyname'].'</sOrgUZ>
					<sNameEN>'.$this->client_data['lastname'].' '.$this->client_data['firstname'].' '.$this->client_data['fathername'].'</sNameEN>
					<sNameRU>'.$this->client_data['lastname'].' '.$this->client_data['firstname'].' '.$this->client_data['fathername'].'</sNameRU>
					<sNameUZ>'.$this->client_data['lastname'].' '.$this->client_data['firstname'].' '.$this->client_data['fathername'].'</sNameUZ>
					<sPassportCode>'.$this->client_data['passportseries'].'</sPassportCode>
					<sPassport>'.$this->client_data['passportnumber'].'</sPassport>
					<sPassportWhoEN>'.$this->client_data['issuingauthority'].'</sPassportWhoEN>
					<sPassportWhoRU>'.$this->client_data['issuingauthority'].'</sPassportWhoRU>
					<sPassportWhoUZ>'.$this->client_data['issuingauthority'].'</sPassportWhoUZ>
					<sPassportYear>'.$passport_date['year'].'</sPassportYear>
					<sPassportMonth>'.$passport_date['month'].'</sPassportMonth>
					<sPassportDay>'.$passport_date['day'].'</sPassportDay>
					<sPhone>'.$this->client_data['phonenumber'].'</sPhone>
					<sFax></sFax>
					<sEMail>'.$this->client_data['email'].'</sEMail>
					<sPost></sPost>
					<sAddressEN>'.$this->client_data['address1'].'</sAddressEN>
					<sAddressRU>'.$this->client_data['address1'].'</sAddressRU>
					<sAddressUZ>'.$this->client_data['address1'].'</sAddressUZ>
					<sCityEN>'.$this->client_data['city'].'</sCityEN>
					<sCityRU>'.$this->client_data['city'].'</sCityRU>
					<sCityUZ>'.$this->client_data['city'].'</sCityUZ>
					<sRegion>'.$region_id.'</sRegion>
					<sCountryEN>'.$return['client']['country'].'</sCountryEN>
					<sCountryRU>'.$return['client']['country'].'</sCountryRU>
					<sCountryUZ>'.$return['client']['country'].'</sCountryUZ>
					<sState>UZ</sState>
					<sBankNameEN>'.$this->client_data['bankname'].'</sBankNameEN>
					<sBankNameRU>'.$this->client_data['bankname'].'</sBankNameRU>
					<sBankNameUZ>'.$this->client_data['bankname'].'</sBankNameUZ>
					<sRS>'.$this->client_data['bankaccount'].'</sRS>
					<sINN>'.$this->client_data['taxid'].'</sINN>
					<sMFO>'.$this->client_data['mfo'].'</sMFO>
					<sOKONH>'.$this->client_data['oked'].'</sOKONH>
					<sType>'.$type.'</sType>
					<sIduzUrl>id.uz</sIduzUrl>
					<sNick></sNick>
			</data>';
			$action = 'cadd';
		$response=$this->call($data,$action);
		$xml = new SimpleXMLElement($response);
		$this->addInfo($xml->msg); 
		return $xml->id;
		}
	
	//updating existing contact info in case update them in hostbill
	public function UpdateContact(){
		$GLOBALS["$pass"]=$this->auth_call();
		$client_id=$this->check_cctld_exist();
		$client_data = new ApiWrapper();
		$params = ['id'=>$this->client_data['id']];
        $return = $client_data->getClientDetails($params);
		$region_id=$this->get_region_id();
		$type=$this->get_user_type();
		$passport_date=$this->get_passport_date();
		$data ='<data>
					<id>'.$client_id.'</id>
					<sContract>N</sContract>
					<sOrgEN>'.$this->client_data['companyname'].'</sOrgEN>
					<sOrgRU>'.$this->client_data['companyname'].'</sOrgRU>
					<sOrgUZ>'.$this->client_data['companyname'].'</sOrgUZ>
					<sNameEN>'.$this->client_data['lastname'].' '.$this->client_data['firstname'].' '.$this->client_data['fathername'].'</sNameEN>
					<sNameRU>'.$this->client_data['lastname'].' '.$this->client_data['firstname'].' '.$this->client_data['fathername'].'</sNameRU>
					<sNameUZ>'.$this->client_data['lastname'].' '.$this->client_data['firstname'].' '.$this->client_data['fathername'].'</sNameUZ>
					<sPassportCode>'.$this->client_data['passportseries'].'</sPassportCode>
					<sPassport>'.$this->client_data['passportnumber'].'</sPassport>
					<sPassportWhoEN>'.$this->client_data['authorityissuingthepassport'].'</sPassportWhoEN>
					<sPassportWhoRU>'.$this->client_data['authorityissuingthepassport'].'</sPassportWhoRU>
					<sPassportWhoUZ>'.$this->client_data['authorityissuingthepassport'].'</sPassportWhoUZ>
					<sPassportYear>'.$passport_date['year'].'</sPassportYear>
					<sPassportMonth>'.$passport_date['month'].'</sPassportMonth>
					<sPassportDay>'.$passport_date['day'].'</sPassportDay>
					<sPhone>'.$this->client_data['phonenumber'].'</sPhone>
					<sFax></sFax>
					<sEMail>'.$this->client_data['email'].'</sEMail>
					<sPost></sPost>
					<sAddressEN>'.$this->client_data['address1'].'</sAddressEN>
					<sAddressRU>'.$this->client_data['address1'].'</sAddressRU>
					<sAddressUZ>'.$this->client_data['address1'].'</sAddressUZ>
					<sCityEN>'.$this->client_data['city'].'</sCityEN>
					<sCityRU>'.$this->client_data['city'].'</sCityRU>
					<sCityUZ>'.$this->client_data['city'].'</sCityUZ>
					<sRegion>'.$region_id.'</sRegion>
					<sCountryEN>'.$return['client']['countryname'].'</sCountryEN>
					<sCountryRU>'.$return['client']['countryname'].'</sCountryRU>
					<sCountryUZ>'.$return['client']['countryname'].'</sCountryUZ>
					<sState>'.$this->client_data['country'].'</sState>
					<sBankNameEN>'.$return['client']['bankname'].'</sBankNameEN>
					<sBankNameRU>'.$return['client']['bankname'].'</sBankNameRU>
					<sBankNameUZ>'.$return['client']['bankname'].'</sBankNameUZ>
					<sRS>'.$return['client']['bankaccount'].'</sRS>
					<sINN>'.$return['client']['taxid'].'</sINN>
					<sMFO>'.$return['client']['mfo'].'</sMFO>
					<sOKONH>'.$return['client']['bankname'].'</sOKONH>
					<sType>'.$type.'</sType>
					<sIduzUrl>id.uz</sIduzUrl>
					<sNick></sNick>
		</data>';
		$action = 'cedit';
		$response=$this->call($data,$action);
		$xml = new SimpleXMLElement($response);
		if (($xml->rcode)==1){
			
			$this->addInfo('Contact updated'); 
			return true;
		}else{
			$this->addError('Contact not found'); 
			return false;
		}
		
	}
	
	public function updateContactInfo() {}
	
	//send domain data to CCTLD for registering new domain name
	public function addNewDomain($clientid){
		if ($this->options['idprotection']=="0"){
			$whois=0;
		}else{
			$whois=1;
		}
		$data ='<data>
					<name>'.$this->options['sld'].'.'.$this->options['tld'].'</name>
					<sCustID>'.$clientid.'</sCustID>
					<sAdminID>'.$clientid.'</sAdminID>
					<sTechID>'.$clientid.'</sTechID>
					<sBillID>'.$clientid.'</sBillID>
					<pnsID></pnsID>
					<snsID></snsID>
					<tnsID></tnsID>
					<qnsID></qnsID>
					<sDesc></sDesc>
					<lWhois>'.$whois.'</lWhois>
				</data>';
		$action = 'dadd';
		
		$response=$this->call($data,$action);
		$file = dirName(__FILE__).'/logFile.txt';
		file_put_contents($file,$data, FILE_APPEND);
		$xml = new SimpleXMLElement($response);		
		$data='<data>
				<id>'.$xml->id.'</id>
				<year>'.$this->options['numyears'].'</year>
			</data>';
		$action='ddeleg';
		$res=$this->call($data,$action);
		file_put_contents($file,$res, FILE_APPEND);		
	}
	
	//get region_ID by text choosing by user while registering
	public function get_region_id(){
		switch($this->client_data['newstate']){
				case "РЕСПУБЛИКА КАРАКАЛПАКСТАН":
					return $region_id="1";
				case "АНДИЖАНСКАЯ ОБЛАСТЬ":
					return $region_id="2";
				case "БУХАРСКАЯ ОБЛАСТЬ":
					return $region_id="3";
				case "ДЖИЗАКСКАЯ ОБЛАСТЬ":
					return $region_id="4";
				case "КАШКАДАРЬИНСКАЯ ОБЛАСТЬ":
					return $region_id="5";
				case "НАВОИЙСКАЯ ОБЛАСТЬ":
					return $region_id="6";
				case "НАМАНГАНСКАЯ ОБЛАСТЬ":
					return $region_id="7";
				case "САМАРКАНДСКАЯ ОБЛАСТЬ":
					return $region_id="8";
				case "СУРХАНДАРЬИНСКАЯ ОБЛАСТЬ":
					return $region_id="9";
				case "СЫРДАРЬИНСКАЯ ОБЛАСТЬ":
					return $region_id="10";
				case "ТАШКЕНТСКАЯ ОБЛАСТЬ":
					return $region_id="11";
				case "ФЕРГАНСКАЯ ОБЛАСТЬ":
					return $region_id="12";
				case "ХОРЕЗМСКАЯ ОБЛАСТЬ":
					return $region_id="13";
				case "ГОРОД ТАШКЕНТ":
					return $region_id="14";
		}
	}
	
	//saving client ID getting from CCTLD into CCTLDID field in client profile
	private function set_client_cctld_id($cctldid) {
		$set_client_cctld = new ApiWrapper();
		$params = ['id'=>$this->client_data['id'],'cctldid'=>$cctldid];
		$set_client_cctld->setClientDetails($params);
	}
	
	/*//getting contact info from CCTLD by contact CCTLDID field in hostbill
	private function get_client_cctld_id() {
		$data='<data><id>'.$this->client_data['cctldid'].'</id></data>';
		$action='cidlist';
		$res=$this->call($data,$action);
		$xml = new SimpleXMLElement($res);
		if (($xml->rcode)==1){
			return $xml->id;
		}else{
			$this->addInfo('User not found'); 
		}
	}*/
	private function get_user_type(){
		if (empty($this->client_data['companyname'])){return $type='p';}else{return $type='l';	}
	}
	public function check_cctld_exist(){
		$type=$this->get_user_type();
		$GLOBALS["$pass"]=$this->auth_call();
		if ($type=='p'){
		$data ='<data>
					<passport>'.$this->client_data['passportnumber'].'</passport><code>'.$this->client_data['passportseries'].'</code>
		</data>';
		$action = 'cpassportlist';
		$response=$this->call($data,$action);
		}elseif ($type=='l'){
			$data ='<data>
					<org>'.$this->client_data['companyname'].'</org>
		</data>';
		$action = 'corglist';
		$response=$this->call($data,$action);
		}
		$xml = new SimpleXMLElement($response);
		if (($xml->rcode)==1){
			return $xml->id;
		}else{
			$this->addError('Contact not found'); 
		}
	}
	public function RecreateContact(){
		$cctld_exists=$this->check_cctld_exist();
		if (empty($cctld_exists)){
			$type=$this->get_user_type();
			$client_data = new ApiWrapper();
			$params = ['id'=>$this->client_data['id']];
			$return = $client_data->getClientDetails($params);
			$region_id=$this->get_region_id();
			$data ='<data>
					<sContract>N</sContract>
					<sOrgEN>'.$this->client_data['companyname'].'</sOrgEN>
					<sOrgRU>'.$this->client_data['companyname'].'</sOrgRU>
					<sOrgUZ>'.$this->client_data['companyname'].'</sOrgUZ>
					<sNameEN>'.$this->client_data['lastname'].' '.$this->client_data['firstname'].' '.$this->client_data['fathername'].'</sNameEN>
					<sNameRU>'.$this->client_data['lastname'].' '.$this->client_data['firstname'].' '.$this->client_data['fathername'].'</sNameRU>
					<sNameUZ>'.$this->client_data['lastname'].' '.$this->client_data['firstname'].' '.$this->client_data['fathername'].'</sNameUZ>
					<sPassportCode>'.$this->client_data['passportseries'].'</sPassportCode>
					<sPassport>'.$this->client_data['passportnumber'].'</sPassport>
					<sPassportWhoEN>'.$this->client_data['issuingauthority'].'</sPassportWhoEN>
					<sPassportWhoRU>'.$this->client_data['issuingauthority'].'</sPassportWhoRU>
					<sPassportWhoUZ>'.$this->client_data['issuingauthority'].'</sPassportWhoUZ>
					<sPassportYear>'.$this->client_data['passportyear'].'</sPassportYear>
					<sPassportMonth>'.$this->client_data['passportmonth'].'</sPassportMonth>
					<sPassportDay>'.$this->client_data['passportday'].'</sPassportDay>
					<sPhone>'.$this->client_data['phonenumber'].'</sPhone>
					<sFax></sFax>
					<sEMail>'.$this->client_data['email'].'</sEMail>
					<sPost></sPost>
					<sAddressEN>'.$this->client_data['address1'].'</sAddressEN>
					<sAddressRU>'.$this->client_data['address1'].'</sAddressRU>
					<sAddressUZ>'.$this->client_data['address1'].'</sAddressUZ>
					<sCityEN>'.$this->client_data['city'].'</sCityEN>
					<sCityRU>'.$this->client_data['city'].'</sCityRU>
					<sCityUZ>'.$this->client_data['city'].'</sCityUZ>
					<sRegion>'.$region_id.'</sRegion>
					<sCountryEN>'.$return['client']['country'].'</sCountryEN>
					<sCountryRU>'.$return['client']['country'].'</sCountryRU>
					<sCountryUZ>'.$return['client']['country'].'</sCountryUZ>
					<sState>UZ</sState>
					<sBankNameEN>'.$this->client_data['bankname'].'</sBankNameEN>
					<sBankNameRU>'.$this->client_data['bankname'].'</sBankNameRU>
					<sBankNameUZ>'.$this->client_data['bankname'].'</sBankNameUZ>
					<sRS>'.$this->client_data['bankaccount'].'</sRS>
					<sINN>'.$this->client_data['taxid'].'</sINN>
					<sMFO>'.$this->client_data['mfo'].'</sMFO>
					<sOKONH>'.$this->client_data['oked'].'</sOKONH>
					<sType>'.$type.'</sType>
					<sIduzUrl>id.uz</sIduzUrl>
					<sNick></sNick>
			</data>';
			$action = 'cadd';
		$response=$this->call($data,$action);
		$this->set_client_cctld_id($response);
		$this->addInfo('Contact added');
		}else{
			$this->set_client_cctld_id($cctld_exists);
		}
	}
	public function get_passport_date(){
		$passport_date=[];
		$passport_date['day']=substr($this->client_data['dateofissue'],0,2);
		$passport_date['month']=substr($this->client_data['dateofissue'],3,2);
		$passport_date['year']=substr($this->client_data['dateofissue'],6,4);
		return $passport_date;
	}
	
	public function Test(){
				
				$params = $this->domain_contacts['billing'];
				ob_start();
				var_dump($params);
				$output = ob_get_clean();
				$file = dirName(__FILE__).'/logFile.txt';
				file_put_contents($file,$output, FILE_APPEND);
		
	}
}

