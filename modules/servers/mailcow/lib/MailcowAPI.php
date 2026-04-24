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

namespace Mailcow;

class MailcowAPI
{
    private string $apiKey;
    private string $baseurl;

    private int|string $aliases;
    private int|string $numMailboxes;
    private int|string $mailboxQuota;
    private int|string $defaultQuota;
    private int|string $unlimitedMailboxes;
    private int|string $rateLimitValue;
    private int|string $rateLimitFrame;

    private const DEFAULT_ALIASES = 100;
    private const DEFAULT_MAILBOXES = 10;
    private const DEFAULT_MAILBOX_QUOTA = 1024;
    private const DEFAULT_DEF_QUOTA = 1024;
    private const DEFAULT_DOMAIN_QUOTA = 10240;
    private const DEFAULT_RL_VALUE = 10;
    private const DEFAULT_RL_FRAME = 's';

    public function __construct($params)
    {
        if (!empty($params['serveraccesshash'])) {
            $this->apiKey = $params['serveraccesshash'];
        } else {
            throw new \Exception('API Key is not provided.');
        }

        $this->aliases = self::DEFAULT_ALIASES;
        $this->numMailboxes = self::DEFAULT_MAILBOXES;
        $this->mailboxQuota = self::DEFAULT_MAILBOX_QUOTA;
        $this->defaultQuota = self::DEFAULT_DEF_QUOTA;
        $this->unlimitedMailboxes = self::DEFAULT_DOMAIN_QUOTA;
        $this->rateLimitValue = self::DEFAULT_RL_VALUE;
        $this->rateLimitFrame = self::DEFAULT_RL_FRAME;
        $this->baseurl = 'https://' . rtrim($params['serverhostname'], '/');
    }

    /* Domain functions */
    public function addDomain($params){
        return $this->_manageDomain($params['domain'], $params['configoptions'], 'create');
    }

    public function addDkim($params){
        return $this->_manageDomain($params['domain'], $params['configoptions'], 'create');
    }

    public function editDomain($params){
        return $this->_manageDomain($params['domain'], $params['configoptions'], 'edit');
    }

    public function disableDomain($params){
        return $this->_manageDomain($params['domain'], $params['configoptions'], 'disable');
    }

    public function activateDomain($params){
        return $this->_manageDomain($params['domain'], $params['configoptions'], 'activate');
    }

    public function removeDomain($params){
        return $this->_manageDomain($params['domain'], $params['configoptions'], 'remove');
    }

    private function _manageDomain($domain, $product_config, $action)
    {
        $uri = '/api/v1/edit/domain';

        $attr = [
            'description' => (string) $domain,
            'aliases' => (string) $this->aliases,
            'mailboxes' => (string) $this->numMailboxes,
            'defquota' => (string) $this->defaultQuota,
            'maxquota' => (string) $this->mailboxQuota,
            'quota' => (string) $this->mailboxQuota,
            'backupmx' => '0',
            'relay_all_recipients' => '0',
            'rl_value' => (string) $this->rateLimitValue,
            'rl_frame' => (string) $this->rateLimitFrame,
        ];

        switch ($action) {
            case 'create':
                $uri = '/api/v1/add/domain';
                $payload = $attr + [
                    'active' => '1',
                    'domain' => $domain,
                    'restart_sogo' => '1',
                    'tags' => $domain,
                ];
                break;

            case 'edit':
                $payload = [
                    'items' => [$domain],
                    'attr' => $attr,
                ];
                break;

            case 'disable':
                $payload = [
                    'items' => [$domain],
                    'attr' => $attr + ['active' => 0],
                ];
                break;

            case 'activate':
                $payload = [
                    'items' => [$domain],
                    'attr' => $attr + ['active' => 1],
                ];
                break;

            case 'remove':
                $uri = '/api/v1/delete/domain';
                $payload = [$domain];
                break;

            default:
                throw new \InvalidArgumentException('Invalid domain action: ' . $action);
        }

        return $this->request($uri, $payload);
    }

    /* Domain Administrator Functions*/
    public function addDomainAdmin($params)
    {
        return $this->_manageDomainAdmin($params['domain'], $params['username'], $params['password'], 'create');
    }

    public function editDomainAdmin($params)
    {
        return $this->_manageDomainAdmin($params['domain'], $params['username'], $params['password'], 'edit');
    }

    public function disableDomainAdmin($params)
    {
        return $this->_manageDomainAdmin($params['domain'], $params['username'], $params['password'], 'disable');
    }

    public function activateDomainAdmin($params)
    {
        return $this->_manageDomainAdmin($params['domain'], $params['username'], $params['password'], 'activate');
    }

    public function changePasswordDomainAdmin($params)
    { 
        return $this->_manageDomainAdmin($params['domain'], $params['username'], $params['password'], 'changepass');
    }

    public function removeDomainAdmin($params)
    {
        return $this->_manageDomainAdmin($params['domain'], $params['username'], null, 'remove');
    }

    private function _manageDomainAdmin($domain, $username, $password, $action)
    {
        $uri = '/api/v1/edit/domain-admin';

        switch ($action) {
            case 'create':
                $uri = '/api/v1/add/domain-admin';
                $payload = [
                    'active' => '1',
                    'domains' => [$domain],
                    'username' => $username,
                    'password' => $password,
                    'password2' => $password,
                ];
                break;

            case 'edit':
            case 'changepass':
                $payload = [
                    'items' => [$username],
                    'attr' => [
                        'domains' => [$domain],
                        'username_new' => $username,
                        'password' => $password,
                        'password2' => $password,
                        'active' => '1',
                    ],
                ];
                break;

            case 'disable':
                $payload = [
                    'items' => [$username],
                    'attr' => [
                        'domains' => [$domain],
                        'username_new' => $username,
                        'password' => $password,
                        'password2' => $password,
                        'active' => 0,
                    ],
                ];
                break;

            case 'activate':
                $payload = [
                    'items' => [$username],
                    'attr' => [
                        'domains' => [$domain],
                        'username_new' => $username,
                        'password' => $password,
                        'password2' => $password,
                        'active' => 1,
                    ],
                ];
                break;

            case 'remove':
                $uri = '/api/v1/delete/domain-admin';
                $payload = [$username];
                break;

            default:
                throw new \InvalidArgumentException('Invalid domain admin action: ' . $action);
        }

        return $this->request($uri, $payload);
    }

    public function removeDomainMailbox($params)
    {
        return $this->_removeMailboxes($params['domain'], $params['username'], null, 'remove');
    }

    private function _removeMailboxes($domain, $username, $password, $action)
    {
        $mailboxes = $this->request('/api/v1/get/mailbox/all/' . rawurlencode($domain), [], 'GET');

        $usernames = [];

        foreach ($mailboxes as $item) {
            if (!empty($item['username'])) {
                $usernames[] = $item['username'];
            }
        }

        if ($usernames === []) {
            return ['status' => 'success', 'message' => 'No mailboxes found'];
        }

        return $this->request('/api/v1/delete/mailbox', $usernames);
    }

    public function removeDomainAliases($params)
    {
        return $this->_removeAliases($params['domain'], $params['username'], null, 'remove');
    }

    private function _removeAliases($domain, $username, $password, $action)
    {
        $aliases = $this->request('/api/v1/get/alias/all', [], 'GET');

        $ids = [];

        foreach ($aliases as $item) {
            if (($item['domain'] ?? null) === $domain && !empty($item['id'])) {
                $ids[] = $item['id'];
            }
        }

        if ($ids === []) {
            return ['status' => 'success', 'message' => 'No aliases found'];
        }

        return $this->request('/api/v1/delete/alias', $ids);
    }

    private function request(string $uri, array $payload, string $method = 'POST'): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseurl . $uri,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'X-API-Key: ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception('Curl error: ' . $error);
        }

        curl_close($ch);

        $decoded = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \Exception('Mailcow API error: HTTP ' . $httpCode . ' - ' . $response);
        }

        return is_array($decoded) ? $decoded : ['raw' => $response];
    }

}