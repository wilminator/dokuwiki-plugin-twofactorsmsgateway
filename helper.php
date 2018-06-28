<?php
class helper_plugin_twofactorsmsgateway extends Twofactor_Auth_Module {
	/** 
	 * If the user has a valid email address in their profile, then this can be used.
	 */
    public function canUse($user = null){		
		return ($this->_settingExists("verified", $user) && $this->getConf('enable') === 1);
	}
	
	/**
	 * This module can not provide authentication functionality at the main login screen.
	 */
    public function canAuthLogin() {
		return false;
	}
		
	/**
	 * This user will need to supply a phone number and their cell provider.
	 */
    public function renderProfileForm(){
		$elements = array();
        // Provide an input for the phone number.			
        $phone = $this->_settingGet("phone", '');
        # This is to move the phone number from shared settings into this 
        # module if not already present.
        if (!$phone) {
            $phone = $this->_sharedSettingGet('phone','');
            if ($phone) {                
                $this->_settingSet('phone', $phone);
                $this->attribute->del('twofactor', 'phone');
            }
        }
        
        $elements['phone'] = form_makeTextField('phone', $phone, $this->getLang('phone'), '', 'block', array('size'=>'50'));
        $providers = array_keys($this->_getProviders());
        $provider = $this->_settingGet("provider",$providers[0]);
        $elements[] = form_makeListboxField('smsgateway_provider', $providers, $provider, $this->getLang('provider'), '', 'block');			 

        // If the phone number has not been verified, then do so here.
        if ($phone) {
            if (!$this->_settingExists("verified")) {
                // Render the HTML to prompt for the verification/activation OTP.
                $elements[] = '<span>'.$this->getLang('verifynotice').'</span>';				
                $elements[] = form_makeTextField('smsgateway_verify', '', $this->getLang('verifymodule'), '', 'block', array('size'=>'50', 'autocomplete'=>'off'));
                $elements[] = form_makeCheckboxField('smsgateway_send', '1', $this->getLang('resendcode'),'','block');
            }
            // Render the element to remove the phone since it exists.
            $elements[] = form_makeCheckboxField('smsgateway_disable', '1', $this->getLang('killmodule'), '', 'block');
        }			
		return $elements;
	}

	/**
	 * Process any user configuration.
	 */	
    public function processProfileForm(){
		global $INPUT;
		if ($INPUT->bool('smsgateway_disable', false)) {
			$this->_settingDelete("phone");
			$this->_settingDelete("provider");
			// Also delete the verified setting.  Otherwise the system will still expect the user to login with OTP.
			$this->_settingDelete("verified");
			return true;
		}
		$oldphone = $this->_settingGet("phone", '');
		if ($oldphone) {
			if ($INPUT->bool('smsgateway_send', false)) {
				return 'otp';
			}
			$otp = $INPUT->str('smsgateway_verify', '');
			if ($otp) { // The user will use SMS.
				$checkResult = $this->processLogin($otp);
				// If the code works, then flag this account to use SMS Gateway.
				if ($checkResult == false) {
					return 'failed';
				}
				else {
					$this->_settingSet("verified", true);
					return 'verified';
				}					
			}							
		}
		
		$changed = null;
		$phone = $INPUT->str('phone', '');
		if (preg_match('/^[0-9]{5,}$/',$phone) != false) { 
			if ($phone != $oldphone) {
				if ($this->_settingSet("phone", $phone)== false) {
					msg("TwoFactor: Error setting phone.", -1);
				}
				// Delete the verification for the phone number if it was changed.
				$this->_settingDelete("verified");
				$changed = true;
			}
		}
        else {
            msg($this->getLang('invalid_number'), -1);
        }
		
		$oldprovider = $this->_settingGet("provider", '');
		$provider = $INPUT->str('smsgateway_provider', '');
		if ($provider != $oldprovider) {
			if ($this->_settingSet("provider", $provider)== false) {
				msg("TwoFactor: Error setting provider.", -1);
			}
			// Delete the verification for the phone number if the carrier was changed.
			$this->_settingDelete("verified");
			$changed = true;
		}
		
		// If the data changed and we have everything needed to use this module, send an otp.
		if ($changed && $this->_settingGet("provider", '') != '') {
			$changed = 'otp';
		}
		return $changed;
	}	
	
	/**
	 * This module can send messages.
	 */
	public function canTransmitMessage(){
		return true;
	}
	
	/**
	 * Transmit the message via email to the address on file.
	 * As a special case, configure the mail settings to send only via text.
	 */
	public function transmitMessage($subject, $message, $force = false){
		if (!$this->canUse()  && !$force) { return false; }
		global $USERINFO, $conf;
		// Disable HTML for text messages.	
		//$oldconf = $conf['htmlmail'];
		//$conf['htmlmail'] = 0;			
		$phone = $this->_settingGet("phone");
        # This is to move the phone number from shared settings into this 
        # module if not already present.
        if (!$phone) {
            $phone = $this->_sharedSettingGet('phone','');
            if ($phone) {                
                $this->_settingSet('phone', $phone);
                $this->_sharedSettingDelete('phone');
            }
        }
		if (!$phone) {
			msg("TwoFactor: User has not defined a phone number.  Failing.", -1);
			// If there is no phone number, then fail.
			return false;
		}
		$gateway = $this->_settingGet("provider");

		$providers = $this->_getProviders();
		if (array_key_exists($gateway, $providers)) {
			$to = "{$phone}@{$providers[$gateway]}";
		}
		else {
			$to = '';
		}
		if (!$to) {
			msg($this->getLang('invalidprovider'), -1);
			// If there is no recipient address, then fail.
			return false;
		}
		// Create the email object.
		$mail = new Mailer();
		$mail->to($to);
		$mail->subject($subject);
		$mail->setText($message);
        $mail->setHTML('');
		$result = $mail->send();
		// Reset the email config in case another email gets sent.
		//$conf['htmlmail'] = $oldconf;
		return $result;
		}
	
	/**
	 * 	This module uses the default authentication.
	 */
    //public function processLogin($code);

	
    /**
     * Produce an array of SMS gateway email domains with the keys as the
     * cellular providers.  Reads the gateway.txt and gateway.override 
	 * (if present) files to generate the list.  
	 * Create the gateway.override file to add your own custom gateways,
	 * otherwise your changes will be lost on upgrade.
     * @return array - keys are providers, values are the email domains used
     *      to email an SMS to a phone user.
     */
    private function _getProviders() {
		$filename = dirname(__FILE__).'/gateway.txt';
		$local_filename = dirname(__FILE__).'/gateway.override';
		$providers = array();
		$contents = explode("\n", io_readFile($filename));		
		$local_contents = io_readFile($local_filename);
		if ($local_contents) {
			// The override file IS processed twice- first to make its entries 
			// appear at the top, then again so they override any default 
			// values.
			$contents = array_merge(explode("\n", $local_contents), $contents, explode("\n", $local_contents));
		}
		foreach($contents as $line) {
			if (strstr($line, '@')) {
				list($provider, $domain) = explode("@", trim($line), 2);
				$providers[$provider] = $domain;
			}
		}
		return $providers;
	}
}