<?php

require 'vendor/autoload.php';

if ($argc < 2) {
    echo "Usage 'php importer.php \$csvFilename'";
}

$csvHasHeader = true;

$user = 'administrator';
$password = 'administrator';
$host = 'localhost';
$port = '1443';
$contactId = 32;
$billingId = 32;
$filename = $argv[1];


$file = fopen($filename, 'r');
$line = 0;

$fields = [
    'customer_id',
    'username',
    'domain',
    'password',
    'primary_number',
    'email',
    'webusername',
    'webpassword',
    'external_id',
    'permanent_contact',
    'alias_numbers',
    'allowed_clis',
    'e164_to_ruri',
    'language',
    'rewrite_rule_set',
    'ncos',
    'concurrent_max',
    'concurrent_max_out',
    'allowed_ips'
];

$sipWise = new \Barnetik\SipWiseApi($user, $password, $host, $port);

if (!is_null($contactId) && !is_null($billingId)) {
    $sipWise->setContactId($contactId);
    $sipWise->setBillingId($billingId);
}

while ($subscriber = fgetcsv($file)) {
    $line++;
    if ($line === 1 && $csvHasHeader) {
        continue;
    }
    $subscriber = array_combine($fields, $subscriber);
    $sipWise->import($subscriber);
}

fclose($file);
