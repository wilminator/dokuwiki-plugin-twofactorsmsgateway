<?php
class helper_plugin_twofactorsmsgateway extends Twofactor_Auth_Module {
	/** 
	 * If the user has a valid email address in their profile, then this can be used.
	 */
    public function canUse($user = null){		
		return ($this->attribute->exists("twofactorsmsgateway", "verified", $user) && $this->getConf('enable') === 1);
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
			$phone = $this->attribute->exists("twofactor", "phone") ? $this->attribute->get("twofactor", "phone") : '';
			$elements['phone'] = form_makeTextField('phone', $phone, $this->_getSharedLang('phone'), '', 'block', array('size'=>'50'));
			$providers = array_keys($this->_getProviders());
			$provider = $this->attribute->exists("twofactorsmsgateway", "provider") ? $this->attribute->get("twofactorsmsgateway", "provider") : $providers[0];
			$elements[] = form_makeListboxField('smsgateway_provider', $providers, $provider, $this->getLang('provider'), '', 'block');			 

			// If the phone number has not been verified, then do so here.
			if ($phone) {
				if (!$this->attribute->exists("twofactorsmsgateway", "verified")) {
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
			// Do not delete the phone number. It is shared.
			$this->attribute->del("twofactorsmsgateway", "provider");
			// Also delete the verified setting.  Otherwise the system will still expect the user to login with OTP.
			$this->attribute->del("twofactorsmsgateway", "verified");
			return true;
		}
		$oldphone = $this->attribute->exists("twofactor", "phone") ? $this->attribute->get("twofactor", "phone") : '';
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
					$this->attribute->set("twofactorsmsgateway", "verified", true);
					return 'verified';
				}					
			}							
		}
		
		$changed = null;
		$phone = $INPUT->str('phone', '');
		if (preg_match('/^[0-9]{5,}$/',$phone) != false) { 
			if ($phone != $oldphone) {
				if ($this->attribute->set("twofactor","phone", $phone)== false) {
					msg("TwoFactor: Error setting phone.", -1);
				}
				// Delete the verification for the phone number if it was changed.
				$this->attribute->del("twofactorsmsgateway", "verified");
				$changed = true;
			}
		}
		
		$oldprovider = $this->attribute->get("twofactorsmsgateway", "provider", $success);
		$provider = $INPUT->str('smsgateway_provider', '');
		if (!$success  || $provider != $oldprovider) {
			if ($this->attribute->set("twofactorsmsgateway","provider", $provider)== false) {
				msg("TwoFactor: Error setting provider.", -1);
			}
			// Delete the verification for the phone number if the carrier was changed.
			$this->attribute->del("twofactorsmsgateway", "verified");
			$changed = true;
		}
		
		// If the data changed and we have everything needed to use this module, send an otp.
		if ($changed && $this->attribute->exists("twofactorsmsgateway", "provider") && $this->attribute->get("twofactor", "phone") !='') {
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
	public function transmitMessage($message, $force = false){
		if (!$this->canUse()  && !$force) { return false; }
		global $USERINFO, $conf;
		// Disable HTML for text messages.	
		$oldconf = $conf['htmlmail'];
		$conf['htmlmail'] = 0;			
		$number = $this->attribute->get("twofactor", "phone");
		if (!$number) {
			msg("TwoFactor: User has not defined a phone number.  Failing.", -1);
			// If there is no phone number, then fail.
			return false;
		}
		$gateway = $this->attribute->get("twofactorsmsgateway", "provider");
		//msg ("$number@$gateway");
		$providers = $this->_getProviders();
		if (array_key_exists($gateway, $providers)) {
			$to = "{$number}@{$providers[$gateway]}";
		}
		else {
			$to = '';
		}
		if (!$to) {
			msg("TwoFactor: Unable to define To field for email.  Failing.", -1);
			// If there is no recipient address, then fail.
			return false;
		}
		// Create the email object.
		$mail = new Mailer();
		$subject = $conf['title'].' login verification';
		$mail->to($to);
		$mail->subject($subject);
		$mail->setText($message);			
		$result = $mail->send();
		// Reset the email config in case another email gets sent.
		$conf['htmlmail'] = $oldconf;
		return $result;
		}
	
	/**
	 * 	This module uses the default authentication.
	 */
    //public function processLogin($code);

	
    /**
     * Produce an array of SMS gateway email domains with the keys as the
     * cellular providers.  Reads the gateway.txt file to generate the list.
     * @return array - keys are providers, values are the email domains used
     *      to email an SMS to a phone user.
     */
    private function _getProviders() {
		$filename = dirname(__FILE__).'/gateway.txt';
		$providers = array();
		$contents = explode("\n", io_readFile($filename));		
		foreach($contents as $line) {
			if (strstr($line, '@')) {
				list($provider, $domain) = explode("@", trim($line), 2);
				$providers[$provider] = $domain;
			}
		}
		return $providers;
	}
}