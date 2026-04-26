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
use WHMCS\Database\Capsule;

function mailcow_MetaData()
{
    return array(
        'DisplayName' => 'MailCow',
        'APIVersion' => '3.1', // Use API Version 1.1
        'RequiresServer' => true, // Set true if module requires a server to work
        'DefaultNonSSLPort' => '80', // Default Non-SSL Connection Port
        'DefaultSSLPort' => '443', // Default SSL Connection Port
    );
}

function mailcow_ConfigOptions()
{
    return [
        'Default Mailbox Quota' => [
            'Type' => 'text',
            'Size' => '25',
            'Default' => '1024',
            'Description' => 'Per-mailbox storage limit in MB',
        ],
        'IMAP Hostname' => [
            'Type' => 'text',
            'Size' => '50',
            'Default' => 'imap.example.com',
            'Description' => 'Shown to customers as IMAP server',
        ],
        'SMTP Hostname' => [
            'Type' => 'text',
            'Size' => '50',
            'Default' => 'smtp.example.com',
            'Description' => 'Shown to customers as SMTP server',
        ],
        'POP3 Hostname' => [
            'Type' => 'text',
            'Size' => '50',
            'Default' => 'pop.example.com',
            'Description' => 'Shown to customers as POP3 server',
        ],
        'MX Hostname' => [
            'Type' => 'text',
            'Size' => '50',
            'Default' => 'email.example.com',
            'Description' => 'Shown to customers as MX target',
        ],
        'SPF Record' => [
            'Type' => 'text',
            'Size' => '80',
            'Default' => 'v=spf1 mx -all',
            'Description' => 'Shown to customers as SPF TXT record',
        ],
        'Max Mailboxes' => [
            'Type' => 'text',
            'Size' => '10',
            'Default' => '10',
            'Description' => 'Maximum number of mailboxes customer can create',
        ],
    ];
}

function mailcow_getAddress(array $params): string
{
    return trim((string)($params['serverhostname'] ?: $params['serverip'] ?: ''));
}

function mailcow_getServiceId(array $params): int
{
    $serviceId = (int)($params['serviceid'] ?? $params['accountid'] ?? 0);

    if ($serviceId <= 0) {
        throw new \RuntimeException('Unable to determine WHMCS service ID.');
    }

    return $serviceId;
}

function mailcow_ensureMappingTable(): void
{
    if (!Capsule::schema()->hasTable('mod_mailcow_domains')) {
        Capsule::schema()->create('mod_mailcow_domains', function ($table) {
            $table->increments('id');
            $table->integer('service_id')->unsigned()->index();
            $table->string('domain', 255)->index();
            $table->string('domain_admin', 255)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['service_id', 'domain']);
        });
    }
}

function mailcow_saveDomainMapping(array $params, string $domain, bool $primary = true): void
{
    mailcow_ensureMappingTable();

    $serviceId = mailcow_getServiceId($params);
    $now = date('Y-m-d H:i:s');

    $exists = Capsule::table('mod_mailcow_domains')
        ->where('service_id', $serviceId)
        ->where('domain', $domain)
        ->exists();

    if ($exists) {
        Capsule::table('mod_mailcow_domains')
            ->where('service_id', $serviceId)
            ->where('domain', $domain)
            ->update([
                'domain_admin' => $params['username'] ?? null,
                'is_primary' => $primary ? 1 : 0,
                'status' => 'active',
                'updated_at' => $now,
            ]);
    } else {
        Capsule::table('mod_mailcow_domains')->insert([
            'service_id' => $serviceId,
            'domain' => $domain,
            'domain_admin' => $params['username'] ?? null,
            'is_primary' => $primary ? 1 : 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

function mailcow_updateDomainMappingStatus(array $params, string $status): void
{
    mailcow_ensureMappingTable();

    Capsule::table('mod_mailcow_domains')
        ->where('service_id', mailcow_getServiceId($params))
        ->update([
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
}

function mailcow_CreateAccount(array $params)
{
    try {
        if (empty($params['domain']) || !filter_var($params['domain'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return 'Invalid domain name.';
        }

        mailcow_ensureMappingTable();

        $mailcow = new MailcowAPI($params);

        $mailcow->addDomain($params);

        try {
            $mailcow->addDomainAdmin($params);
        } catch (\Throwable $e) {
            try {
                $mailcow->removeDomain($params);
            } catch (\Throwable $rollbackError) {
                // Ignore rollback failure, original error is more useful.
            }

            throw $e;
        }

        // Generate DKIM automatically on provisioning.
        try {
            $mailcow->addDkim($params);
        } catch (\Throwable $e) {
            logModuleCall(
                'mailcow',
                __FUNCTION__ . ':addDkim',
                ['domain' => $params['domain']],
                $e->getMessage(),
                $e->getTraceAsString()
            );
        }

        mailcow_saveDomainMapping($params, $params['domain'], true);

    } catch (\Throwable $e) {
        return $e->getMessage();
    }

    return 'success';
}

function mailcow_SuspendAccount(array $params)
{
    try {
        $mailcow = new MailcowAPI($params);

        $mailcow->disableDomain($params);
        $mailcow->disableDomainAdmin($params);

        mailcow_updateDomainMappingStatus($params, 'suspended');

    } catch (\Throwable $e) {
        return $e->getMessage();
    }

    return 'success';
}

function mailcow_UnsuspendAccount(array $params)
{
    try {
        $mailcow = new MailcowAPI($params);

        $mailcow->activateDomain($params);
        $mailcow->activateDomainAdmin($params);

        mailcow_updateDomainMappingStatus($params, 'active');

    } catch (\Throwable $e) {
        return $e->getMessage();
    }

    return 'success';
}

function mailcow_TerminateAccount(array $params)
{
    try {
        $mailcow = new MailcowAPI($params);

        $steps = [
            'removeDomainMailbox',
            'removeDomainAliases',
            'removeDomainAdmin',
            'removeDomain',
        ];

        foreach ($steps as $method) {
            try {
                $mailcow->{$method}($params);
            } catch (\Throwable $e) {
                logModuleCall(
                    'mailcow',
                    __FUNCTION__ . ':' . $method,
                    ['domain' => $params['domain']],
                    $e->getMessage(),
                    $e->getTraceAsString()
                );
            }
        }

        mailcow_updateDomainMappingStatus($params, 'terminated');

    } catch (\Throwable $e) {
        return $e->getMessage();
    }

    return 'success';
}

function mailcow_ChangePassword(array $params)
{
    try {
        $mailcow = new MailcowAPI($params);
        $result = $mailcow->changePasswordDomainAdmin($params);
      
        logModuleCall(
            'mailcow',
            __FUNCTION__,
            [
                'serviceid' => $params['serviceid'] ?? null,
                'domain' => $params['domain'] ?? null,
                'username' => $params['username'] ?? null,
            ],
            print_r($result, true),
            null
        );
    } catch (\Throwable $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'mailcow',
            __FUNCTION__,
            [
                'serviceid' => $params['serviceid'] ?? null,
                'domain' => $params['domain'] ?? null,
                'username' => $params['username'] ?? null,
            ],
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
        $mailcow = new MailcowAPI($params);
        $mailcow->getStatus();

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

function mailcow_getMailboxLimit(array $params): int
{
    return (int)($params['configoption7'] ?? 10);
}

function mailcow_getDefaultMailboxQuota(array $params): int
{
    return (int)($params['configoption1'] ?? 1024);
}

function mailcow_generatePassword(int $length = 16): string
{
    return bin2hex(random_bytes((int)ceil($length / 2)));
}

/**
 * @param $params
 * @return string
 */
function mailcow_ClientArea(array $params)
{
    $address = mailcow_getAddress($params);

    if (empty($address)) {
        return '';
    }

    try {
        $mailcow = new MailcowAPI($params);
        $data_dkim = $mailcow->getDkim($params);
        $dkim = $data_dkim['dkim_txt'] ?? 'DKIM is not generated yet. Please contact support.';
    } catch (\Throwable $e) {
        $dkim = 'Unable to fetch DKIM record at this time.';
    }

    $mailboxMessage = '';

    try {
        $mailcow = new MailcowAPI($params);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mailcow_action'])) {
            $action = (string)$_POST['mailcow_action'];

            if ($action === 'create_mailbox') {
                $localPart = trim((string)($_POST['local_part'] ?? ''));
                $password = trim((string)($_POST['password'] ?? ''));

                if ($password === '') {
                    $password = mailcow_generatePassword();
                }

                $mailboxes = $mailcow->getMailboxes($params);
                $maxMailboxes = mailcow_getMailboxLimit($params);

                if (count($mailboxes) >= $maxMailboxes) {
                    throw new \RuntimeException('Mailbox limit reached.');
                }

                $mailcow->addMailbox(
                    $params,
                    $localPart,
                    $password,
                    mailcow_getDefaultMailboxQuota($params)
                );

                $mailboxMessage = 'Mailbox created. Password: ' . $password;
            }

            if ($action === 'delete_mailbox') {
                $email = trim((string)($_POST['email'] ?? ''));

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new \RuntimeException('Invalid mailbox address.');
                }

                if (!str_ends_with(strtolower($email), '@' . strtolower($params['domain']))) {
                    throw new \RuntimeException('Mailbox does not belong to this domain.');
                }

                $mailcow->deleteMailbox($email);
                $mailboxMessage = 'Mailbox deleted.';
            }
        }

        $mailboxes = $mailcow->getMailboxes($params);

    } catch (\Throwable $e) {
        $mailboxMessage = $e->getMessage();
        $mailboxes = [];
    }

    $e = static function ($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    $domain = $e($params['domain'] ?? '');
    $username = $e($params['username'] ?? '');
    $addressEsc = $e($address);

    $imapHost = $e($params['configoption2'] ?? 'imap.example.com');
    $smtpHost = $e($params['configoption3'] ?? 'smtp.example.com');
    $pop3Host = $e($params['configoption4'] ?? 'pop.example.com');
    $mxHost = $e($params['configoption5'] ?? 'email.example.com');
    $spfRecord = $e($params['configoption6'] ?? 'v=spf1 mx -all');
    $dkimEsc = $e($dkim);
    
    $mailboxRows = '';

    foreach ($mailboxes as $mailbox) {
        $email = $e($mailbox['username'] ?? '');
        $quota = $e($mailbox['quota'] ?? '');

        if ($email === '') {
            continue;
        }

        $mailboxRows .= '
    <tr>
        <td>' . $email . '</td>
        <td>' . $quota . '</td>
        <td>
            <form method="post" style="display:inline;">
                <input type="hidden" name="mailcow_action" value="delete_mailbox">
                <input type="hidden" name="email" value="' . $email . '">
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
        </td>
    </tr>';
    }

    if ($mailboxRows === '') {
        $mailboxRows = '<tr><td colspan="3">No mailboxes yet.</td></tr>';
    }

    $mailboxMessageEsc = $e($mailboxMessage);

    return '
<div class="row">
    <div class="col-sm-5 text-right"><strong>Username</strong></div>
    <div class="col-sm-7 text-left">' . $username . '</div>
</div>

<div class="row">
    <div class="col-sm-5 text-right"><strong>Mail Panel</strong></div>
    <div class="col-sm-7 text-left">
        <a href="https://' . $addressEsc . '" target="_blank" rel="noopener noreferrer">' . $addressEsc . '</a>
    </div>
</div>

<hr>

<div class="row">
    <center><strong>Mail Settings</strong></center><br>
</div>

<div class="row">
    <div class="col-sm-5 text-right"><strong>IMAP Server:</strong></div>
    <div class="col-sm-7 text-left"><pre>' . $imapHost . '</pre></div>
</div>

<div class="row">
    <div class="col-sm-5 text-right"><strong>SMTP Server:</strong></div>
    <div class="col-sm-7 text-left"><pre>' . $smtpHost . '</pre></div>
</div>

<div class="row">
    <div class="col-sm-5 text-right"><strong>POP3 Server:</strong></div>
    <div class="col-sm-7 text-left"><pre>' . $pop3Host . '</pre></div>
</div>

<hr>

<div class="row">
    <center><strong>Mailboxes</strong></center><br>
</div>

' . ($mailboxMessageEsc !== '' ? '
<div class="alert alert-info">' . $mailboxMessageEsc . '</div>
' : '') . '

<form method="post" class="form-inline" style="margin-bottom:15px;">
    <input type="hidden" name="mailcow_action" value="create_mailbox">

    <div class="form-group">
        <label>Mailbox</label>
        <input type="text" name="local_part" class="form-control" placeholder="info">
        <span>@' . $domain . '</span>
    </div>

    <div class="form-group" style="margin-left:10px;">
        <label>Password</label>
        <input type="password" name="password" class="form-control" placeholder="Leave empty to generate">
    </div>

    <button type="submit" class="btn btn-primary" style="margin-left:10px;">Create Mailbox</button>
</form>

<table class="table table-striped">
    <thead>
        <tr>
            <th>Email</th>
            <th>Quota</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        ' . $mailboxRows . '
    </tbody>
</table>

<hr>

<div class="row">
    <center><strong>DNS Records</strong></center><br>
</div>

<div class="row">
    <div class="col-sm-5 text-right"><strong>' . $domain . ' MX:</strong></div>
    <div class="col-sm-7 text-left"><pre>10 ' . $mxHost . '</pre></div>
</div>

<div class="row">
    <div class="col-sm-5 text-right"><strong>dkim._domainkey.' . $domain . ' TXT:</strong></div>
    <div class="col-sm-7 text-left"><pre>' . $dkimEsc . '</pre></div>
</div>

<div class="row">
    <div class="col-sm-5 text-right"><strong>_dmarc.' . $domain . ' TXT:</strong></div>
    <div class="col-sm-7 text-left"><pre>v=DMARC1; p=none</pre></div>
</div>

<div class="row">
    <div class="col-sm-5 text-right"><strong>' . $domain . ' TXT:</strong></div>
    <div class="col-sm-7 text-left"><pre>' . $spfRecord . '</pre></div>
</div>';
}