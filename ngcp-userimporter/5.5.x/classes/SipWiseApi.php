<?php
namespace Barnetik;

use \Httpful\Request;
use Hackzilla\PasswordGenerator\Generator\ComputerPasswordGenerator;

class SipWiseApi
{
    protected $user;
    protected $password;
    protected $baseUrl;

    protected $contactId = null;
    protected $billingId = null;

    protected $subscriberProperties = array(
        'customer_id',
        'alias_numbers',
        'domain',
        'email',
        'external_id',
        'password',
        'primary_number',
        'username',
        'webpassword',
        'webusername'
    );

    protected $subscriberPreferences = array(
        'allowed_clis',
        'e164_to_ruri',
        'language',
        'rewrite_rule_set',
        'ncos',
        'concurrent_max',
        'concurrent_max_out',
        'allowed_ips'
    );

    public function __construct($user, $password, $host = 'localhost', $port = '1443', $protocol = 'https')
    {
        $this->user = $user;
        $this->password = $password;
        $this->baseUrl = $protocol . '://' . $host . ':' . $port . '/api';
    }

    public function setBillingId($id)
    {
        $this->billingId = $id;
    }

    public function setContactId($id)
    {
        $this->contactId = $id;
    }

    public function import($subscriber)
    {
        $this->log('Creating ' . $subscriber['username'] . ' subscriber...');
        $sanitizedSubscriber = $this->sanitizeData($subscriber);
        if ($sanitizedSubscriber === false) {
            $this->log('Warning: Subscriber not created.');
            $this->log("");
            return false;
        }
        $subscriberId = $this->addSubscriber($sanitizedSubscriber);
        if ($subscriberId === false) {
            return false;
        }
        $this->addPreferences($subscriberId, $sanitizedSubscriber);
        $this->addRegistration($subscriberId, $sanitizedSubscriber);
        $this->log("");
    }

    public function sanitizeData($subscriber)
    {
        $sanitized = [];

        if (!$this->checkDomain(trim($subscriber['domain']))) {
            return false;
        }
        $sanitized['domain'] = trim($subscriber['domain']);

        if (!trim($subscriber['username'])) {
            throw new \Exception('Ivalid username: ' . var_export($subscriber, true));
        }
        if (!$this->checkNewUsername(trim($subscriber['username']))) {
            $this->log('Warning: Username ' . $subscriber['username'] . ' already exists');
            return false;
        }
        $sanitized['username'] = trim($subscriber['username']);

        if (!$subscriber['password'] || strlen($subscriber['password']) < 6) {
            $this->log('Password generated for: ' . $subscriber['username']);
            $subscriber['password'] = $this->generatePassword();
        }
        $sanitized['password'] = trim($subscriber['password']);

        if (trim($subscriber['alias_numbers'])) {
            $numbers = explode('|', trim($subscriber['alias_numbers']));
            foreach ($numbers as $number) {
                $numberObject = $this->splitNumber($number);
                if ($numberObject === false) {
                    return false;
                }
                $sanitized['alias_numbers'][] = $numberObject;
            }
        }

        if (trim($subscriber['email'])) {
            $sanitized['email'] = trim($subscriber['email']);
        }

        if (trim($subscriber['external_id'])) {
            $sanitized['external_id'] = trim($subscriber['external_id']);
        }

        if (trim($subscriber['primary_number'])) {
            $sanitized['primary_number'] = $this->splitNumber(trim($subscriber['primary_number']));
            if ($sanitized['primary_number'] === false){
                return false;
            }
        }

        if ($subscriber['webpassword']) {
            $sanitized['webpassword'] = $subscriber['webpassword'];
        }

        if (trim($subscriber['webusername'])) {
            $sanitized['webusername'] = trim($subscriber['webusername']);
        }

        // PREFERENCES
        if (trim($subscriber['allowed_clis'])) {
            $sanitized['allowed_clis'] = explode('|', trim($subscriber['allowed_clis']));
        }

        if (trim($subscriber['allowed_ips'])) {
            $sanitized['allowed_ips'] = explode('|', trim($subscriber['allowed_ips']));
        }

        if (trim($subscriber['e164_to_ruri']) && in_array(strtolower(trim($subscriber['e164_to_ruri'])), ['sí', 'si', 'yes', 'true', '1'])) {
            $sanitized['e164_to_ruri'] = true;
        }

        if (trim($subscriber['language'])) {
            $sanitized['language'] = trim($subscriber['language']);
        }

        if (trim($subscriber['rewrite_rule_set'])) {
            if ($this->checkRewriteRuleSet(trim($subscriber['rewrite_rule_set'])) === false) {
                $this->log("Warning: Specified rewrite rule set does not exist");
                return false;
            }
            $sanitized['rewrite_rule_set'] = trim($subscriber['rewrite_rule_set']);
        }

        if (trim($subscriber['ncos'])) {
            $level = $this->checkNcosLevel(trim($subscriber['ncos']));
            if ($level === false) {
                $this->log("Warning: Specified ncos level does not exist");
                return false;
            }
            $sanitized['ncos'] = $level;
        }

        if (trim($subscriber['concurrent_max'])) {
            $sanitized['concurrent_max'] = trim($subscriber['concurrent_max']);
        }

        if (trim($subscriber['concurrent_max_out'])) {
            $sanitized['concurrent_max_out'] = trim($subscriber['concurrent_max_out']);
        }

        if (trim($subscriber['permanent_contact'])) {
            $sanitized['permanent_contact'] = trim($subscriber['permanent_contact']);
        }


        // CHECK CUSTOMER IF NONE SPECIFIED CREATE ONE
        if ($subscriber['customer_id']) {
            if (!$this->checkCustomerId($subscriber['customer_id'])) {
                throw new \Exception('ERROR: Invalid customer id: ' . var_export($subscriber, true));
            };
            $sanitized['customer_id'] = $subscriber['customer_id'];
        } else {
            $newCustomerId = $this->createNewCustomer($sanitized['external_id']);
            if (false === $newCustomerId) {
                throw new \Exception('ERROR: No customer id. New customer could not be created. Please specify Customer and Billing id\'s: ' . var_export($subscriber, true));
            }
            $sanitized['customer_id'] = $newCustomerId;
        }

        return $sanitized;
    }

    protected function splitNumber($number)
    {
        $numberParts = explode(' ', $number);
        if (sizeof($numberParts) !== 3) {
            $this->log('Wrong number format: ' . $number);
            return false;
        }
        return array_combine(['cc', 'ac', 'sn'], $numberParts);
    }

    protected function baseUrl($element)
    {
        return $this->baseUrl . $element;
    }

    public function checkCustomerId($customerId)
    {
        $response = Request::get($this->baseUrl('/customers/' . $customerId))
            ->authenticateWithBasic($this->user, $this->password)
            ->expectsJson()
            ->send();
        return $response->code == 200;
    }

    public function checkNewUsername($username)
    {
        $response = Request::get($this->baseUrl('/subscribers/?username=' . $username))
            ->authenticateWithBasic($this->user, $this->password)
            ->expectsJson()
            ->send();
        return $response->body->total_count === 0;
    }

    public function checkRewriteRuleSet($rewriteRuleSet)
    {
        $response = Request::get($this->baseUrl('/rewriterulesets/?name=' . $rewriteRuleSet))
            ->authenticateWithBasic($this->user, $this->password)
            ->expectsJson()
            ->send();
        return $response->body->total_count > 0;
    }

    public function checkNcosLevel($ncosLevel)
    {
        $response = Request::get($this->baseUrl('/ncoslevels/' . $ncosLevel))
            ->authenticateWithBasic($this->user, $this->password)
            ->expectsJson()
            ->send();
        if ($response->code !== 200) {
            return false;
        }
        return $response->body->level;
    }

    public function createNewCustomer($externalId)
    {
        if (is_null($this->contactId) || is_null($this->billingId)) {
            return false;
        }

        $customerData = array(
            'contact_id' => $this->contactId,
            'billing_profile_definition' => 'id',
            'billing_profile_id' => $this->billingId,
            'status' => 'active',
            'type' => 'sipaccount',
            'external_id' => $externalId
        );

        $response = Request::post($this->baseUrl('/customers/'))
            ->authenticateWith($this->user, $this->password)
            ->expectsJson()
            ->sendsJson()
            ->body(json_encode($customerData))
            ->send();
        if ($response->code == 422) {
            $this->log('Warning: ' . $response->body->message);
            $this->log('Warning: Customer could not be created.');
            return false;
        } else if ($response->code == 201) {
            $response = Request::get($this->baseUrl(sprintf('/customers/?contact_id=%s&order_by=id&order_by_direction=desc', $this->contactId)))
                ->expectsJson()
                ->authenticateWith($this->user, $this->password)
                ->send();
            $id = $response->body->_embedded->{'ngcp:customers'}[0]->id;
            $this->log('New customer generated: (id: ' . $id . ')');
            return $id;
        }
    }

    public function checkDomain($domain)
    {
        if ($domain === '') {
            $this->log('Error, domain cannot be empty: ' . var_export($subscriber, true));
            return;
        }

        $response = Request::get($this->baseUrl('/domains/?domain=' . $domain))
            ->authenticateWithBasic($this->user, $this->password)
            ->expectsJson()
            ->send();
        return $response->body->total_count > 0;
    }

    public function generatePassword()
    {
        $generator = new ComputerPasswordGenerator();

        $generator
          ->setUppercase()
          ->setLowercase()
          ->setNumbers()
          ->setSymbols(false)
          ->setLength(16);

        return $generator->generatePassword(1);
    }

    public function addSubscriber($subscriber)
    {
        $subscriberData = [];
        foreach ($this->subscriberProperties as $property) {
            if (isset($subscriber[$property])) {
                $subscriberData[$property] = $subscriber[$property];
            }
        }

        $response = Request::post($this->baseUrl('/subscribers/'))
            ->authenticateWith($this->user, $this->password)
            ->expectsJson()
            ->sendsJson()
            ->body(json_encode($subscriberData))
            ->send();
        if ($response->code == 422) {
            $this->log('Warning: ' . $response->body->message);
            $this->log('Warning: Subscriber not created.');
            $this->log("");
            return false;
        } else if ($response->code == 201) {
            $response = Request::get($this->baseUrl(sprintf('/subscribers/?username=%s&domain=%s&customer_id=%s', $subscriber['username'], $subscriber['domain'], $subscriber['customer_id'])))
                ->expectsJson()
                ->authenticateWith($this->user, $this->password)
                ->send();

            $id = $response->body->_embedded->{'ngcp:subscribers'}[0]->id;
            $this->log('New subscriber generated: ' . $subscriber['username'] . ' (id: ' . $id . ')');
            return $id;
        }
    }

    public function addPreferences($subscriberId, $subscriber)
    {
        $subscriberPreferences = [];
        foreach ($this->subscriberPreferences as $property) {
            if (isset($subscriber[$property])) {
                $subscriberPreferences[] = [
                    'op' => 'add',
                    'path' => '/' . $property,
                    'value' =>  $subscriber[$property]
                ];
            }
        }

        $response = Request::patch($this->baseUrl('/subscriberpreferences/' . $subscriberId))
            ->authenticateWith($this->user, $this->password)
            ->sendsType('application/json-patch+json')
            ->expectsJson()
            ->body(json_encode($subscriberPreferences))
            ->send();

        if ($response->code == 422) {
            $this->log('Warning: ' . $response->body->message);
            $this->log('Warning: Subscriber preferences not set.');
        } else if ($response->code == 201) {
            $this->log('Subscriber preferences added');
        }
    }

    public function addRegistration($subscriberId, $subscriber)
    {
        if (!isset($subscriber['permanent_contact']) || !$subscriber['permanent_contact']) {
            return;
        }

        $subscriberRegistrations = [
            'contact' => $subscriber['permanent_contact'],
            'subscriber_id' => $subscriberId,
            'q' => 1,
            'expires' => '2155-01-25 06:53:09'
        ];

        $response = Request::post($this->baseUrl('/subscriberregistrations/'))
            ->authenticateWith($this->user, $this->password)
            ->expectsJson()
            ->sendsJson()
            ->body(json_encode($subscriberRegistrations))
            ->send();

        if ($response->code == 422) {
            $this->log('Warning: ' . $response->body->message);
            $this->log('Warning: Subscriber registrations not set.');
        } else if ($response->code == 201) {
            $this->log('Subscriber registrations added');
        } else {
            var_dump($response->body);
        }
    }

    public function log($message)
    {
        echo $message . "\n";
    }

}
