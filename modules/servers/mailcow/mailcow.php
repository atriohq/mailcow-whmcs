<?php
/**
 * Mailcow WHMCS Module
 *
 * (c) 2026 Atrio Limited (https://atrio.dev)
 * Based on https://github.com/rorry47/mailcow_module_WHMCS
 * and https://github.com/websavers/WHMCS-mailcow by Websavers
 *
 * MIT License
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/lib/MailcowAPI.php';

use Mailcow\MailcowAPI;
use WHMCS\Input\Sanitize;
use WHMCS\Database\Capsule;

function mailcow_MetaData()
{
    return array(
        'DisplayName' => 'MailCow',
        'APIVersion' => '3.1', // Use API Version 1.1
        'RequiresServer' => true, // Set true if module requires a server to work
        'DefaultNonSSLPort' => '80', // Default Non-SSL Connection Port
        'DefaultSSLPort' => '443', // Default SSL Connection Port
        'ServiceSingleSignOnLabel' => 'Login to Panel as User',
        'AdminSingleSignOnLabel' => 'Login to Panel as Admin',
    );
}

function mailcow_ConfigOptions(){

    return array(
        'Default Mailbox Quota' => array(
            'Type' => 'text',
            'Size' => '25',
            'Default' => '1024',
            'Description' => 'When specifying per-account limits, this storage limit will be applied to each mailbox. Enter in megabytes',
        )
    );
}

function mailcow_CreateAccount(array $params)
{
    try {
        $mailcow = new MailcowAPI($params);
        $mailcow->addDomain($params);
		$mailcow->addDomainAdmin($params);
    } catch (\Throwable $e) {
        return $e->getMessage();
    }

    return 'success';
}

function mailcow_SuspendAccount(array $params)
{
    try {
        $mailcow = new MailcowAPI($params);
        $result = $mailcow->disableDomain($params);
    } catch (\Throwable $e) {
        return $e->getMessage();
    }

    try {
        $mailcow = new MailcowAPI($params);
        $result = $mailcow->disableDomainAdmin($params);
    } catch (\Throwable $e) {
        return $e->getMessage();
    }

    return 'success';
}

function mailcow_UnsuspendAccount(array $params)
{
    try {
        $mailcow = new MailcowAPI($params);
        $result = $mailcow->activateDomain($params);
    } catch (\Throwable $e) {
        return $e->getMessage();
    }

    try {
        $mailcow = new MailcowAPI($params);
        $result = $mailcow->activateDomainAdmin($params);
    } catch (\Throwable $e) {
        return $e->getMessage();
    }

    return 'success';
}

function mailcow_TerminateAccount(array $params)
{
    if ($params['status'] == "Terminated") {
        return 'Account has already been deleted!';
    } else {
        try {
          //Del MailBoxes
          $mailcow = new MailcowAPI($params);
          $result = $mailcow->removeDomainMailbox($params);

          //Del Aliases
          $mailcow = new MailcowAPI($params);
          $result = $mailcow->removeDomainAliases($params);

          //Remove Domain
          $mailcow = new MailcowAPI($params);
          $result = $mailcow->removeDomain($params);

          //Remove Domain Admin
          $mailcow = new MailcowAPI($params);
          $result = $mailcow->removeDomainAdmin($params);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return 'success';
    }
}

function mailcow_ChangePassword(array $params)
{
    try {
        $mailcow = new MailcowAPI($params);
        $result = $mailcow->changePasswordDomainAdmin($params);
      
        logModuleCall(
            'mailcow',
            __FUNCTION__,
            print_r($params, true),
            print_r($result, true),
            null
        );
    } catch (\Throwable $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'mailcow',
            __FUNCTION__,
            print_r($params, true),
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }

    return 'success';
}

function mailcow_TestConnection(array $params)
{
    try {
        new MailcowAPI($params);

        return [
            'success' => true,
            'error' => '',
        ];
    } catch (\Throwable $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * @param $params
 * @return string
 */
function mailcow_ClientArea(array $params)
{
    $address = ($params['serverhostname']) ? $params['serverhostname'] : $params['serverip'];
    if (empty($address)) {
        return '';
    }

    $ch_dkim = curl_init();
    curl_setopt($ch_dkim, CURLOPT_URL, "https://$address/api/v1/get/dkim/{$params['domain']}");
    curl_setopt($ch_dkim, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch_dkim, CURLOPT_HEADER, FALSE);
    curl_setopt($ch_dkim, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "X-API-Key: {$params['serveraccesshash']}",
    ));
    
    $response_dkim = curl_exec($ch_dkim);
    curl_close($ch_dkim);

    $data_dkim = json_decode($response_dkim, true);

    if (empty($data_dkim['dkim_txt'])) {
        $dkim = '<form method="POST"><input type="submit" name="gen_dkim" value="Add"></form>';

        if (isset($_POST['gen_dkim'])){
            $ch_dkim_add = curl_init();
            curl_setopt($ch_dkim_add, CURLOPT_URL, "https://$address/api/v1/add/dkim");
            curl_setopt($ch_dkim_add, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch_dkim_add, CURLOPT_HEADER, FALSE);
            curl_setopt($ch_dkim_add, CURLOPT_POST, TRUE);
            curl_setopt($ch_dkim_add, CURLOPT_POSTFIELDS, "{
              \"domains\": \"{$params['domain']}\",
              \"dkim_selector\": \"dkim\",
              \"key_size\": \"2048\"
            }");
            curl_setopt($ch_dkim_add, CURLOPT_HTTPHEADER, array(
                    "Content-Type: application/json",
                    "X-API-Key: {$params['serveraccesshash']}",
            ));
            $response_dkim = curl_exec($ch_dkim_add);
            
            curl_close($ch_dkim_add);
            
            header("Location: " . $_SERVER['REQUEST_URI']);
        }
    } else {
        $dkim = $data_dkim['dkim_txt'];
    }

	$form = sprintf(
      '<div class="row">
<div class="col-sm-5 text-right">
<strong>Username</strong>
</div>
<div class="col-sm-7 text-left">
' . $params["username"] . '
</div>
</div>

<div class="row">
<div class="col-sm-5 text-right">
<strong>Mail Server</strong>
</div>
<div class="col-sm-7 text-left">
<a href="https://' . $address . '" target="_blank">' . $address . '</a>
</div>
</div>
<hr> 

<div class="row">
<center>
<strong>DNS Records</strong>
</center>
<br>
</div>

<div class="row">
<div class="col-sm-5 text-right">
<strong>mail.' . $params['domain'] . ' (A):</strong>
</div>
<div class="col-sm-7 text-left">
<pre>' . $params['serverip'] . '</pre>
</div>
</div>

<div class="row">
<div class="col-sm-5 text-right">
<strong>dkim._domainkey.' . $params['domain'] . ' (TXT):</strong>
</div>
<div class="col-sm-7 text-left">
<pre>' . $dkim . '</pre>
</div>
</div>

<div class="row">
<div class="col-sm-5 text-right">
<strong>' . $params['domain'] . ' (MX):</strong>
</div>
<div class="col-sm-7 text-left">
<pre>mail.' . $params['domain'] . '</pre>
</div>
</div>

<div class="row">
<div class="col-sm-5 text-right">
<strong>_dmarc.' . $params['domain'] . ' (TXT):</strong>
</div>
<div class="col-sm-7 text-left">
<pre>v=DMARC1;p=none</pre>
</div>
</div>

<div class="row">
<div class="col-sm-5 text-right">
<strong>' . $params['domain'] . ' (TXT):</strong>
</div>
<div class="col-sm-7 text-left">
<pre>v=spf1 a mx -all</pre>
</div>
</div>
'
  );

    return $form;
}