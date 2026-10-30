<?php

/*
 * Namingo EPP Registrar module for FOSSBilling
 *
 * Copyright (c) 2023-2026 Taras Kondratyuk
 * Copyright (c) 2025-2026 Namingo contributors
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Based on Generic EPP with DNSsec Registrar Module for WHMCS written in 2019 by Lilian Rudenco (info@xpanel.com)
 * Work of Lilian Rudenco is under http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 * https://github.com/xpanel/Generic-EPP-with-DNSsec-Registrar-Module-for-WHMCS
 *
 * Licensed under the MIT License.
 * See the LICENSE file distributed with this software for the full license text.
 *
 * SPDX-License-Identifier: MIT
 */

$autoload = __DIR__ . '/../../../namingo/vendor/autoload.php';

if (file_exists($autoload)) {
    require_once $autoload;
}

use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Pinga\Tembo\EppRegistryFactory;

class Registrar_Adapter_EPP extends Registrar_AdapterAbstract
{
    public $config = array();

    public function __construct(array $options)
    {
        $this->config = [
            // EPP credentials
            'clid'     => $options['clid'] ?? null,
            'pw' => $options['pw'] ?? null,

            // Connection
            'host' => $options['host'] ?? null,
            'port' => isset($options['port']) ? (int) $options['port'] : 700,

            // TLS / SSL
            'tls_version' => !empty($options['tls_version']),
            'verify_peer' => !empty($options['verify_peer']),
            'cafile'      => $options['cafile'] ?? null,
            'local_cert'    => $options['local_cert'] ?? null,
            'local_pk'     => $options['local_pk'] ?? null,
            'passphrase'  => $options['passphrase'] ?? null,

            // Registry behaviour
            'registrarprefix'      => $options['registrarprefix'] ?? '',
            'contact_postal_type'  => $options['contact_postal_type'] ?? 'int',
            'set_authinfo_on_info' => !empty($options['set_authinfo_on_info']),
            'min_data_set'         => !empty($options['min_data_set']),
            'registry_profile'     => $options['registry_profile'] ?? 'generic',
            'ns_mode'              => $options['ns_mode'] ?? 'hostObj',

            // Objects
            'login_objects' => isset($options['login_objects']) && $options['login_objects'] !== ''
                ? array_map('trim', explode(',', $options['login_objects']))
                : [],

            // Extensions
            'login_extensions' => isset($options['login_extensions']) && $options['login_extensions'] !== ''
                ? array_map('trim', explode(',', $options['login_extensions']))
                : [],

            // EURid
            'eurid_billing_contact' => $options['eurid_billing_contact'] ?? null,

            // RoTLD: client-profile field mappings, never shared holder values.
            'ro_person_type_field' => $options['ro_person_type_field'] ?? 'custom_1',
            'ro_fiscal_code_field' => $options['ro_fiscal_code_field'] ?? 'custom_2',
            'ro_registration_number_field' => $options['ro_registration_number_field'] ?? 'company_number',

            // NASK
            'pl_contact_prefix' => $options['pl_contact_prefix'] ?? null,

            'debug_log' => !empty($options['debug_log']),
            'debug_log_path' => $options['debug_log_path'] ?? (__DIR__ . '/../log'),
        ];
    }

    public function getTlds()
    {
        return array();
    }

    public static function getConfig()
    {
        $roTypeFields = ['auto' => 'Automatic: person or company'];
        $roDataFields = [
            'auto' => 'Automatic from standard client/contact fields',
            'company_vat' => 'Company VAT / fiscal code',
            'company_number' => 'Company registration number',
            'document_nr' => 'Contact identity document number',
        ];
        foreach (range(1, 20) as $number) {
            $roTypeFields['custom_' . $number] = 'Client custom field ' . $number;
            $roDataFields['custom_' . $number] = 'Client custom field ' . $number;
        }

        return [
            'label' => 'Connect FOSSBilling to any domain registry using the standard EPP protocol.',
            'form'  => [
                'host' => ['text', [
                    'label'       => 'EPP Hostname:',
                    'required'    => true,
                    'description' => 'Registry EPP endpoint hostname (e.g. epp.registry.tld).',
                ]],

                'port' => ['text', [
                    'label'       => 'EPP Port:',
                    'required'    => true,
                    'default'     => '700',
                    'description' => 'TCP port used by the registry (700 is the standard EPP port, but some registries use a different value).',
                ]],

                'tls_version' => ['radio', [
                    'multiOptions' => ['1' => 'Yes', '0' => 'No'],
                    'label'        => 'Prefer TLS 1.3:',
                    'description'  => 'Use TLS 1.3 when available; falls back to older TLS if the registry does not support it.',
                ]],

                'verify_peer' => ['radio', [
                    'multiOptions' => ['1' => 'Yes', '0' => 'No'],
                    'label'        => 'Verify TLS Certificate:',
                    'description'  => 'Validate the registry server certificate (recommended). Disable only for test environments.',
                ]],

                'cafile' => ['text', [
                    'label'       => 'CA Bundle Path',
                    'required'    => false,
                    'default'     => '',
                    'description' => 'Path to a CA bundle file used to verify the registry certificate (required when “Verify TLS Certificate” is enabled).',
                ]],

                'local_cert' => ['text', [
                    'label'       => 'Client Certificate (PEM)',
                    'required'    => true,
                    'default'     => 'cert.pem',
                    'description' => 'Path to your registrar client certificate in PEM format.',
                ]],

                'local_pk' => ['text', [
                    'label'       => 'Client Private Key',
                    'required'    => true,
                    'default'     => 'key.pem',
                    'description' => 'Path to your private key file (PEM).',
                ]],

                'passphrase' => ['password', [
                    'label'          => 'Private Key Passphrase',
                    'required'       => false,
                    'default'        => '',
                    'renderPassword' => true,
                    'description'    => 'Passphrase for the private key (leave blank if the key is not encrypted).',
                ]],

                'clid' => ['text', [
                    'label'       => 'Client ID (clID)',
                    'required'    => true,
                    'description' => 'Registrar identifier provided by the registry.',
                ]],

                'pw' => ['password', [
                    'label'          => 'Client Password',
                    'required'       => true,
                    'renderPassword' => true,
                    'description'    => 'EPP login password provided by the registry.',
                ]],

                'registrarprefix' => ['text', [
                    'label'       => 'Object ID Prefix',
                    'required'    => true,
                    'description' => 'Prefix used when generating registry object IDs (contacts/hosts). Use the value required by the registry, if any.',
                ]],

                'contact_postal_type' => ['select', [
                    'label'        => 'Contact Postal Address Type',
                    'required'     => true,
                    'default'      => 'int',
                    'multiOptions' => [
                        'int' => 'Internationalized (int)',
                        'loc' => 'Localized (loc)',
                    ],
                    'description'  => 'EPP postalInfo type used when creating contacts. Use int unless the registry specifically requires loc.',
                ]],

                'registry_profile' => ['select', [
                    'label'        => 'Registry Profile',
                    'required'     => true,
                    'default'      => 'generic',
                    'multiOptions' => [
                        'generic' => 'generic',
                        'EU'      => 'EU',
                        'FR'      => 'FR',
                        'FRED'    => 'FRED',
                        'GE'      => 'GE',
                        'HR'      => 'HR',
                        'LV'      => 'LV',
                        'MX'      => 'MX',
                        'PL'      => 'PL',
                        'PT'      => 'PT',
                        'RO'      => 'RO',
                        'SE'      => 'SE',
                        'SI'      => 'SI',
                        'SWITCH'  => 'SWITCH',
                        'UA'      => 'UA',
                        'VRSN'    => 'VRSN',
                    ],
                    'description'  => 'Select the registry profile matching the registry implementation. List of profiles: https://github.com/getnamingo/fossbilling-epp-registrar',
                ]],

                'ns_mode' => ['select', [
                    'label'        => 'Nameserver Mode',
                    'required'     => true,
                    'default'      => 'hostObj',
                    'multiOptions' => [
                        'hostObj'  => 'hostObj',
                        'hostAttr' => 'hostAttr',
                    ],
                    'description'  => 'hostObj uses EPP host objects (default). hostAttr embeds nameservers in domain commands and disables DomainX glue hostname management.',
                ]],

                'set_authinfo_on_info' => ['radio', [
                    'multiOptions' => ['1' => 'Yes', '0' => 'No'],
                    'label'        => 'Set AuthInfo on Request',
                    'description'  => 'Enable if the registry does not return the transfer code on domain info and requires setting it manually first.',
                ]],

                'login_objects' => ['textarea', [
                    'label'       => 'EPP Login Objects',
                    'required'    => false,
                    'default'     => 'urn:ietf:params:xml:ns:domain-1.0, urn:ietf:params:xml:ns:contact-1.0, urn:ietf:params:xml:ns:host-1.0',
                    'description' =>
                        "Comma-separated EPP login object URIs.\n" .
                        "Example: urn:ietf:params:xml:ns:domain-1.0, urn:ietf:params:xml:ns:contact-1.0, urn:ietf:params:xml:ns:host-1.0",
                ]],

                'login_extensions' => ['textarea', [
                    'label'       => 'EPP Login Extensions',
                    'required'    => false,
                    'default'     => 'urn:ietf:params:xml:ns:secDNS-1.1, urn:ietf:params:xml:ns:rgp-1.0',
                    'description' =>
                        "Comma-separated EPP login extension URIs.\n" .
                        "Example: urn:ietf:params:xml:ns:secDNS-1.1, urn:ietf:params:xml:ns:rgp-1.0",
                ]],

                'min_data_set' => ['radio', [
                    'multiOptions' => ['1' => 'Yes', '0' => 'No'],
                    'label'        => 'Enable Minimum Data Set',
                    'description'  =>
                        'Enable this for gTLD registries that follow ICANN Minimum Data Set rules. ' .
                        'When enabled, contact data is managed at account level and cannot be edited per domain.',
                ]],

                'eurid_billing_contact' => ['text', [
                    'label'       => 'EURid Billing Contact ID',
                    'required'    => false,
                    'default'     => '',
                    'description' => 'Optional billing contact handle for EURid. Used only when EPP profile is EU.',
                ]],

                'pl_contact_prefix' => ['text', [
                    'label'       => 'NASK (.pl) Contact Prefix',
                    'required'    => false,
                    'default'     => '',
                    'description' => 'Optional contact ID prefix for NASK (.pl). Used when EPP profile is PL.',
                ]],

                'ro_person_type_field' => ['select', [
                    'label' => 'RoTLD Person Type Field',
                    'default' => 'custom_1',
                    'multiOptions' => $roTypeFields,
                    'description' => 'RO only. Client-profile field containing p, ap, nc, c, gi, pi or o. Empty values use p/c based on Company Name.',
                ]],

                'ro_fiscal_code_field' => ['select', [
                    'label' => 'RoTLD CNP / Fiscal Code Field',
                    'default' => 'custom_2',
                    'multiOptions' => $roDataFields,
                    'description' => 'RO only. Empty custom values fall back to Company VAT for companies or the contact document number for individuals.',
                ]],

                'ro_registration_number_field' => ['select', [
                    'label' => 'RoTLD Registration Number Field',
                    'default' => 'company_number',
                    'multiOptions' => $roDataFields,
                    'description' => 'RO only. Company registration number; required for Romanian commercial companies.',
                ]],

                'debug_log' => ['radio', [
                    'multiOptions' => ['1' => 'Yes', '0' => 'No'],
                    'label'        => 'EPP Debug Logging',
                    'default'      => '0',
                    'description'  =>
                        'Write EPP requests and responses to the Module Log for troubleshooting. ' .
                        'Enable only while debugging, then disable.',
                ]],

                'debug_log_path' => ['text', [
                    'label'       => 'EPP Debug Log Path',
                    'required'    => false,
                    'default'     => __DIR__ . '/../log',
                    'description' => 'Absolute directory for raw EPP request and response logs.',
                ]],

            ],
        ];
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Checking if domain can be transferred: ' . $domain->getName());

        if (($this->config['registry_profile'] ?? 'generic') === 'RO') {
            // Foreign-domain info may be restricted; the transfer key is checked on request.
            if ($this->isDomainAvailable($domain)) {
                throw new Registrar_Exception('An unregistered domain cannot be transferred.');
            }
            return true;
        }

        try {
            $epp = $this->epp_client();
            $domain_name = $domain->getName();
            $domain_name = function_exists('idn_to_ascii')
                ? (idn_to_ascii($domain_name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain_name)
                : $domain_name;

            $info = $epp->domainInfo([
                'domainname' => $domain_name,
            ]);

            if (!empty($info['error'])) {
                throw new Registrar_Exception((string)$info['error']);
            }

            if (!empty($this->config['debug_log'])) {
                $this->getLog()->debug(
                    'EPP domainInfo (transfer check) ' . $domain->getName() . ': ' .
                    json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }

            $statuses = $info['status'] ?? [];
            $statuses = is_array($statuses) ? $statuses : [$statuses];

            $blocked = [
                'clientTransferProhibited',
                'serverTransferProhibited',
            ];

            foreach ($statuses as $st) {

                if ($st === null || $st === '') {
                    continue;
                }

                if (is_array($st)) {
                    $st = $st['s'] ?? $st['status'] ?? $st['value'] ?? '';
                }

                if (in_array($st, $blocked, true)) {
                    throw new Registrar_Exception(
                        'Domain transfer is prohibited (status: ' . $st . '). ' .
                        'Please unlock the domain at the current registrar and try again.'
                    );
                }

                if ($st === 'pendingTransfer') {
                    throw new Registrar_Exception('Domain already has a pending transfer.');
                }
            }

            return true;
        } catch (Registrar_Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Registrar_Exception(
                'Domain transfer check failed. Please try again later.'
            );
        } finally {
            if (isset($epp)) {
                $this->epp_client_logout($epp);
            }
        }
    }

    public function isDomainAvailable(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Checking domain availability: ' . $domain->getName());
        
        try {
            $epp = $this->epp_client();
            $domain_name = $domain->getName();
            $domain_name = function_exists('idn_to_ascii')
                ? (idn_to_ascii($domain_name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain_name)
                : $domain_name;

            $domainCheck = $epp->domainCheck([
                'domains' => [$domain_name],
            ]);

            if (!empty($domainCheck['error'])) {
                throw new Registrar_Exception((string)$domainCheck['error']);
            }

            if (!empty($this->config['debug_log'])) {
                $this->getLog()->debug(
                    'EPP domainCheck ' . $domain->getName() . ': ' .
                    json_encode($domainCheck, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }

            $item = array_values($domainCheck['domains'] ?? [])[0] ?? null;

            if (!$item || empty($item['name'])) {
                throw new Registrar_Exception('Domain check failed: empty response');
            }

            if (!array_key_exists('avail', $item)) {
                throw new Registrar_Exception(
                    'Domain check failed: missing availability status'
                );
            }

            $avail = filter_var($item['avail'] ?? false, FILTER_VALIDATE_BOOL);

            if ($avail) {
                return true;
            }

            return false;
        } catch (Registrar_Exception  $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Registrar_Exception(
                'Domain availability check failed. Please try again later.'
            );
        } finally {
            if (isset($epp)) {
                $this->epp_client_logout($epp);
            }
        }
    }

    public function modifyNs(Registrar_Domain $domain)
    {
        if (($this->config['registry_profile'] ?? 'generic') === 'FRED') {
            return $this->fredModifyNs($domain);
        }

        $this->getLog()->debug('Modifying nameservers: ' . $domain->getName());
        $this->getLog()->debug('Ns1: ' . $domain->getNs1());
        $this->getLog()->debug('Ns2: ' . $domain->getNs2());
        $this->getLog()->debug('Ns3: ' . $domain->getNs3());
        $this->getLog()->debug('Ns4: ' . $domain->getNs4());
        try {
            $epp = $this->epp_client();
            $domain_name = $domain->getName();
            $domain_name = function_exists('idn_to_ascii')
                ? (idn_to_ascii($domain_name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain_name)
                : $domain_name;

            $info = $epp->domainInfo([
                'domainname' => $domain_name,
            ]);

            if (isset($info['error'])) {
                throw new Registrar_Exception($info['error']);
            }

            if (!empty($this->config['debug_log'])) {
                $this->getLog()->debug(
                    'EPP domainInfo ' . $domain->getName() . ': ' .
                    json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }

            $current = [];
            foreach (($info['ns'] ?? []) as $ns) {
                $ns = (string)$ns;
                if ($ns !== '') {
                    $current[] = $ns;
                }
            }

            $add = [];
            foreach (['ns1','ns2','ns3','ns4'] as $k) {
                $v = $domain->{'get' . ucfirst($k)}();
                if (empty($v)) {
                    continue;
                }

                $v = (string)$v;
                $v = function_exists('idn_to_ascii')
                    ? (idn_to_ascii($v, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $v)
                    : $v;

                if (in_array($v, $current, true)) {
                    continue;
                }

                $add[$k] = $v;
            }

            $profile = $this->config['registry_profile'] ?? 'generic';
            if (($this->config['ns_mode'] ?? 'hostObj') !== 'hostAttr' && !in_array($profile, ['EU', 'HR', 'LV', 'GE'], true)) {
                if (!empty($add)) {
                    foreach ($add as $k => $nsName) {
                        $nsName = trim((string)$nsName);
                        if ($nsName === '') {
                            continue;
                        }

                        $hostCheck = $epp->hostCheck([
                            'hostname' => $nsName,
                        ]);

                        if (!empty($hostCheck['error'])) {
                            throw new Registrar_Exception((string)$hostCheck['error']);
                        }

                        if (!empty($this->config['debug_log'])) {
                            $this->getLog()->debug(
                                'EPP hostCheck ' . $domain->getName() . ': ' .
                                json_encode($hostCheck, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                            );
                        }

                        $items = array_values($hostCheck['hosts'] ?? []);
                        $item  = $items[0] ?? null;

                        if (!$item) {
                            continue;
                        }

                        $avail = filter_var($item['avail'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                        $avail = $avail ?? ((int)($item['avail'] ?? 0) === 1);

                        if (!$avail) {
                            continue;
                        }

                        // host:create
                        $hostCreate = $epp->hostCreate([
                            'hostname' => $nsName,
                        ]);

                        if (!empty($hostCreate['error'])) {
                            throw new Registrar_Exception((string)$hostCreate['error']);
                        }

                        if (!empty($this->config['debug_log'])) {
                            $this->getLog()->debug(
                                'EPP hostCreate ' . $domain->getName() . ': ' .
                                json_encode($hostCreate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                            );
                        }
                    }
                }
            }
            
            $final = [];
            foreach (range(1, 4) as $i) {
              $k = "getNs$i";
              $v = $domain->{$k}();
              if (!$v) {
                continue;
              }

              $v = (string)$v;
              $v = function_exists('idn_to_ascii')
                  ? (idn_to_ascii($v, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $v)
                  : $v;

              $final["ns$i"] = $v;
            }

            if (($this->config['ns_mode'] ?? 'hostObj') === 'hostAttr' || in_array($profile, ['EU', 'HR', 'LV', 'GE'], true)) {
                $payload = [
                    'domainname' => $domain_name,
                    'nss'        => [],
                ];

                foreach (array_values($final) as $host) {
                    $ns = ['hostName' => $host];

                    if (preg_match('/\.(eu|hr|ge|lv)$/i', $host)) {
                        $a = @dns_get_record($host, DNS_A);
                        if (!empty($a[0]['ip'])) {
                            $ns['ipv4'] = $a[0]['ip'];
                        }

                        $aaaa = @dns_get_record($host, DNS_AAAA);
                        if (!empty($aaaa[0]['ipv6'])) {
                            $ns['ipv6'] = $aaaa[0]['ipv6'];
                        }
                    }

                    $payload['nss'][] = $ns;
                }

                $domainUpdateNS = $epp->domainUpdateNS($payload);
            } else {
                $payload = ['domainname' => $domain_name];

                foreach (array_values($final) as $idx => $host) {
                    $payload['ns' . ($idx + 1)] = $host;
                }

                $domainUpdateNS = $epp->domainUpdateNS($payload);
            }

            if (!empty($domainUpdateNS['error'])) {
                throw new Registrar_Exception((string)$domainUpdateNS['error']);
            }

            if (!empty($this->config['debug_log'])) {
                $this->getLog()->debug(
                    'EPP domainUpdateNS ' . $domain->getName() . ': ' .
                    json_encode($domainUpdateNS, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }

            return true;
        } catch (Registrar_Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Registrar_Exception(
                'Nameserver modification failed. Please try again later.'
            );
        } finally {
            if (isset($epp)) {
                $this->epp_client_logout($epp);
            }
        }
    }

    public function transferDomain(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Transfering domain: ' . $domain->getName());
        try {
            $epp = $this->epp_client();
            $domain_name = $domain->getName();
            $domain_name = function_exists('idn_to_ascii')
                ? (idn_to_ascii($domain_name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain_name)
                : $domain_name;

            $profile = $this->config['registry_profile'] ?? 'generic';
            if ($profile === 'RO') {
                if (trim((string)$domain->getEpp()) === '') {
                    throw new Registrar_Exception('The holder must supply a RoTLD registrar-transfer key.');
                }
                $domainTransfer = $epp->domainTransfer([
                    'domainname' => $domain_name,
                    'authInfoPw' => $domain->getEpp(),
                    'op' => 'request',
                ]);
            } else if ($profile === 'FR') {
                $domainInfo = $epp->domainInfo([
                    'domainname' => $domain_name,
                ]);

                if (isset($domainInfo['error'])) {
                    throw new Registrar_Exception($domainInfo['error']);
                }

                if (!empty($this->config['debug_log'])) {
                    $this->getLog()->debug(
                        'EPP domainInfo ' . $domain->getName() . ': ' .
                        json_encode($domainInfo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    );
                }

                $adminId = null;
                $techId  = null;

                foreach (($domainInfo['contact'] ?? []) as $c) {
                    if (($c['type'] ?? '') === 'admin') {
                        $adminId = $c['id'] ?? null;
                    } elseif (($c['type'] ?? '') === 'tech') {
                        $techId = $c['id'] ?? null;
                    }
                }

                $domainTransfer = $epp->domainTransfer([
                    'domainname' => $domain_name,
                    'years'      => 1,
                    'authInfoPw' => $domain->getEpp(),
                    'op'         => 'request',
                    'admin'      => $adminId,
                    'tech'       => $techId,
                ]);
            } else {
                $domainTransfer = $epp->domainTransfer([
                    'domainname' => $domain_name,
                    'years'      => 1,
                    'authInfoPw' => $domain->getEpp(),
                    'op'         => 'request',
                ]);
            }

            if (isset($domainTransfer['error'])) {
                throw new Registrar_Exception($domainTransfer['error']);
            }

            if (!empty($this->config['debug_log'])) {
                $this->getLog()->debug(
                    'EPP domainTransfer ' . $domain->getName() . ': ' .
                    json_encode($domainTransfer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }

            if ($profile === 'RO') {
                if ((int)($domainTransfer['code'] ?? 0) !== 1000) {
                    throw new Registrar_Exception('RoTLD did not confirm an immediate transfer.');
                }
                $info = $epp->domainInfo(['domainname' => $domain_name]);
                if (!empty($info['error'])) {
                    throw new Registrar_Exception((string)$info['error']);
                }
                if (empty($this->config['clid']) || (string)($info['clID'] ?? '') !== (string)$this->config['clid']) {
                    throw new Registrar_Exception('RoTLD transfer sponsorship could not be confirmed.');
                }
                $expires = !empty($info['exDate']) ? strtotime((string)$info['exDate']) : false;
                if ($expires !== false) {
                    $domain->setExpirationTime($expires);
                }
            }

            return true;
        } catch (Registrar_Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Registrar_Exception(
                'Domain transfer failed. Please try again later.'
            );
        } finally {
            if (isset($epp)) {
                $this->epp_client_logout($epp);
            }
        }
    }

    public function getDomainDetails(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Getting domain details: ' . $domain->getName());
        try {
            $epp = $this->epp_client();
            $domain_name = $domain->getName();
            $domain_name = function_exists('idn_to_ascii')
                ? (idn_to_ascii($domain_name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain_name)
                : $domain_name;

            $info = $epp->domainInfo([
                'domainname' => $domain_name,
            ]);

            if (!empty($info['error'])) {
                throw new Registrar_Exception((string)$info['error']);
            }

            if (!empty($this->config['debug_log'])) {
                $this->getLog()->debug(
                    'EPP domainInfo ' . $domain->getName() . ': ' .
                    json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }

            $crDate = (string)$info['crDate'];
            $exDate = (string)$info['exDate'];
            $eppcode = (string)$info['authInfo'];

            $ns = [];
            $i = 1;
            foreach (($info['ns'] ?? []) as $nsa) {
                if ($nsa === null || $nsa === '') {
                    continue;
                }
                $ns[$i] = (string) $nsa;
                $i++;
            }

            $crDate = strtotime($crDate);
            $exDate = strtotime($exDate);

            $domain->setRegistrationTime($crDate);
            $domain->setExpirationTime($exDate);
            if (($this->config['registry_profile'] ?? 'generic') !== 'RO') {
                $domain->setEpp($eppcode);
            }

            $domain->setNs1(isset($ns[0]) ? $ns[0] : '');
            $domain->setNs2(isset($ns[1]) ? $ns[1] : '');
            $domain->setNs3(isset($ns[2]) ? $ns[2] : '');
            $domain->setNs4(isset($ns[3]) ? $ns[3] : '');

            $statuses = $info['status'] ?? [];
            $statuses = is_array($statuses) ? $statuses : [$statuses];

            $domain->setLocked(
                array_reduce(
                    $statuses,
                    fn($carry, $s) => $carry || str_ends_with((string)$s, 'Prohibited'),
                    false
                )
            );

            $domain->setPrivacyEnabled(
                array_reduce(
                    $statuses,
                    fn($carry, $s) => $carry || str_contains((string)$s, 'hidden'),
                    false
                )
            );

            return $domain;
        } catch (Registrar_Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Registrar_Exception(
                'Domain information failed. Please try again later.'
            );
        } finally {
            if (isset($epp)) {
                $this->epp_client_logout($epp);
            }
        }
    }

    public function deleteDomain(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Removing domain: ' . $domain->getName());
        try {
            $epp = $this->epp_client();
            $domain_name = $domain->getName();
            $domain_name = function_exists('idn_to_ascii')
                ? (idn_to_ascii($domain_name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain_name)
                : $domain_name;

            $domainDelete = $epp->domainDelete([
                'domainname' => $domain_name,
            ]);

            if (isset($domainDelete['error'])) {
                throw new Registrar_Exception($domainDelete['error']);
            }

            if (!empty($this->config['debug_log'])) {
                $this->getLog()->debug(
                    'EPP domainDelete ' . $domain->getName() . ': ' .
                    json_encode($domainDelete, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }

            return true;
        } catch (Registrar_Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Registrar_Exception(
                'Domain deletion failed. Please try again later.'
            );
        } finally {
            if (isset($epp)) {
                $this->epp_client_logout($epp);
            }
        }
    }

    public function registerDomain(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Registering domain: ' . $domain->getName(). ' for '.$domain->getRegistrationPeriod(). ' years');
        $client = $domain->getContactRegistrar();

        $roFields = ($this->config['registry_profile'] ?? 'generic') === 'RO'
            ? $this->getRoContactFields($client)
            : [];

        try {
            $epp = $this->epp_client();
            $domain_name = $domain->getName();
            $domain_name = function_exists('idn_to_ascii')
                ? (idn_to_ascii($domain_name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain_name)
                : $domain_name;

            $domainCheck = $epp->domainCheck([
                'domains' => [$domain_name],
            ]);

            if (!empty($domainCheck['error'])) {
                throw new Registrar_Exception((string)$domainCheck['error']);
            }

            if (!empty($this->config['debug_log'])) {
                $this->getLog()->debug(
                    'EPP domainCheck ' . $domain->getName() . ': ' .
                    json_encode($domainCheck, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }

            $item = array_values($domainCheck['domains'] ?? [])[0] ?? null;

            if (!$item || empty($item['name'])) {
                throw new Registrar_Exception('Domain check failed: empty response');
            }

            $avail = filter_var($item['avail'] ?? false, FILTER_VALIDATE_BOOL);
            $reason = (string)($item['reason'] ?? '');

            if (!$avail) {
                throw new Registrar_Exception(
                    'Domain is not available' . ($reason ? ': ' . $reason : '')
                );
            }
            
            if (empty($this->config['min_data_set'])) {
                $contacts = [];

                $contactTypeMap = [
                    'EU'      => ['registrant', 'tech'],                 // EURid
                    'SWITCH'=> ['registrant', 'tech'],
                    'PL'=> ['registrant'],
                    'GE'=> ['registrant'],
                    'RO'=> ['registrant'],
                    'FRED'=> ['registrant'], // the same contact is also used as nsset tech contact
                    'generic'=> ['registrant', 'admin', 'tech', 'billing'],
                    'VRSN'   => ['registrant', 'admin', 'tech', 'billing'],
                ];

                $profile = $this->config['registry_profile'] ?? 'generic';
                
                $ptValidation = null;
                if ($profile === 'PT') {
                    $ptValidation = $this->getPtValidation(
                        (string) ($client->getEmail() ?? '')
                    );
                }

                $contactTypes = $contactTypeMap[$profile]
                    ?? $contactTypeMap['generic'];

                $registrarPrefix = strtoupper(trim((string)($this->config['registrarprefix'] ?? '')));
                $contactPostalType = ($this->config['contact_postal_type'] ?? 'int') === 'loc' ? 'loc' : 'int';

                foreach ($contactTypes as $i => $contactType) {

                    $id = strtoupper($this->epp_random_contact_id()) . ($registrarPrefix !== '' ? '-' . $registrarPrefix : '');
                    if ($profile === 'PL') {
                        $prefix = trim($this->config['pl_contact_prefix'] ?? '');

                        if ($prefix !== '') {
                            $id = $prefix . $id;
                        }
                    }
                    $authInfoPw = $this->epp_random_auth_pw();

                    $contactCreate = $epp->contactCreate([
                        'id'              => $id,
                        'type'            => $contactPostalType,
                        'firstname'       => $client->getFirstName() ?? '',
                        'lastname'        => $client->getLastName() ?? '',
                        'companyname'     => $client->getCompany() ?? '',
                        'address1'        => $client->getAddress1() ?? '',
                        'address2'        => $client->getAddress2() ?? '',
                        'city'            => $client->getCity() ?? '',
                        'state'           => $client->getState() ?? '',
                        'postcode'        => $client->getZip() ?? '',
                        'country'         => $client->getCountry() ?? '',
                        'fullphonenumber' => 
                            ($cc = preg_replace('/\D+/', '', (string) $client->getTelCc())) &&
                            ($tel = preg_replace('/\D+/', '', (string) $client->getTel()))
                                ? '+' . $cc . '.' . $tel
                                : '',
                        'email'           => $client->getEmail() ?? '',
                        'authInfoPw'      => $authInfoPw,
                        // RoTLD holder fields
                        'person_type' => $roFields['person_type'] ?? null,
                        'cnp_fiscal_code' => $roFields['cnp_fiscal_code'] ?? null,
                        'registration_number' => $roFields['registration_number'] ?? null,
                        // EU-only extras
                        'euType'    => ($profile === 'EU') ? $contactType : null,
                        // SE-only extras
                        'orgno'  => ($profile === 'SE') ? ($client->getCompanyNumber() ?? null) : null,
                        'vatno' => ($profile === 'SE' && $client->getCompanyNumber())
                            ? strtoupper($client->getCountry()) . $client->getCompanyNumber()
                            : null,
                        // LV-only extras
                        'regNr' => ($profile === 'LV')
                            ? ($client->getCompanyNumber() ? $client->getCompanyNumber() : ($client->getDocumentNr() ?? null))
                            : null,
                        'vatNr' => ($profile === 'LV' && $client->getCompanyNumber())
                            ? strtoupper($client->getCountry()) . $client->getCompanyNumber()
                            : null,
                        // HR-only extras
                        'nin' => ($profile === 'HR')
                            ? ($client->getCompanyNumber() ? $client->getCompanyNumber() : ($client->getDocumentNr() ?? null))
                            : null,
                        'nin_type' => ($profile === 'SI')
                            ? (trim((string)($client->getCompany() ?? '')) !== '' ? 'org' : 'person')
                            : (($profile === 'HR')
                                ? ($client->getCompanyNumber() ? 'company' : 'personal')
                                : null),
                        // PT-only extras
                        'vat' => ($profile === 'PT' && $client->getCompanyNumber())
                            ? strtoupper($client->getCountry()) . $client->getCompanyNumber()
                            : null,
                        'validated' => ($profile === 'PT') ? $ptValidation['validated']
                            : null,
                        'validatedDate' => ($profile === 'PT') ? $ptValidation['validatedDate']
                            : null,
                        // GE-only extras
                        'nin' => ($profile === 'GE')
                            ? ($client->getCompanyNumber() ? $client->getCompanyNumber() : ($client->getDocumentNr() ?? null))
                            : null,
                    ]);

                    if (!empty($contactCreate['error'])) {
                        throw new Registrar_Exception((string)$contactCreate['error']);
                    }
                    
                    if (!empty($this->config['debug_log'])) {
                        $this->getLog()->debug(
                            'EPP contactCreate ' . $domain->getName() . ': ' .
                            json_encode($contactCreate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        );
                    }

                    if ($profile === 'RO' && empty($contactCreate['id'])) {
                        throw new Registrar_Exception('RoTLD did not return its assigned contact ID.');
                    }
                    $createdId = $contactCreate['id'] ?? $id;
                    $contacts[$i + 1] = $createdId;
                }
            }

            $profile = $this->config['registry_profile'] ?? 'generic';
            if ($profile === 'FRED' && !empty($this->config['min_data_set'])) {
                throw new Registrar_Exception('FRED requires a registrant contact. Disable "Minimum Data Set" for this registrar.');
            }
            // FRED has no host objects: nameservers are grouped in an nsset created further below.
            if (($this->config['ns_mode'] ?? 'hostObj') !== 'hostAttr' && !in_array($profile, ['EU', 'HR', 'LV', 'GE', 'FRED'], true)) {
                foreach (['ns1','ns2','ns3','ns4'] as $nsKey) {
                    $hostname = $domain->{'get' . ucfirst($nsKey)}();
                    if (empty($hostname)) {
                        continue;
                    }

                    $hostname = (string)$hostname;
                    $hostname = function_exists('idn_to_ascii')
                        ? (idn_to_ascii($hostname, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $hostname)
                        : $hostname;

                    $hostCheck = $epp->hostCheck([
                        'hostname' => $hostname,
                    ]);

                    if (!empty($hostCheck['error'])) {
                        throw new Registrar_Exception((string)$hostCheck['error']);
                    }

                    if (!empty($this->config['debug_log'])) {
                        $this->getLog()->debug(
                            'EPP hostCheck ' . $domain->getName() . ': ' .
                            json_encode($hostCheck, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        );
                    }

                    $items = array_values($hostCheck['hosts'] ?? []);
                    $item  = $items[0] ?? null;

                    if (!$item) {
                        continue;
                    }

                    $avail = filter_var($item['avail'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                    $avail = $avail ?? ((int)($item['avail'] ?? 0) === 1);

                    if (!$avail) {
                        continue;
                    }

                    $hostCreate = $epp->hostCreate([
                        'hostname' => $hostname,
                    ]);

                    if (!empty($hostCreate['error'])) {
                        throw new Registrar_Exception((string)$hostCreate['error']);
                    }

                    if (!empty($this->config['debug_log'])) {
                        $this->getLog()->debug(
                            'EPP hostCreate ' . $domain->getName() . ': ' .
                            json_encode($hostCreate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        );
                    }
                }
            }

            $period     = (int)($domain->getRegistrationPeriod() ?? 1);
            
            $nss = [];
            if (($this->config['ns_mode'] ?? 'hostObj') === 'hostAttr' || in_array($profile, ['EU', 'HR', 'LV', 'GE'], true)) {
                foreach (['ns1','ns2','ns3','ns4'] as $nsKey) {
                    $host = $domain->{'get' . ucfirst($nsKey)}();
                    if (empty($host)) {
                        continue;
                    }

                    $host = (string)$host;
                    $host = function_exists('idn_to_ascii')
                        ? (idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host)
                        : $host;

                    $ns = ['hostName' => $host];

                    if (preg_match('/\.(eu|hr|ge|lv)$/i', $host)) {
                        $a = @dns_get_record($host, DNS_A);
                        if (!empty($a[0]['ip'])) {
                            $ns['ipv4'] = $a[0]['ip'];
                        }

                        $aaaa = @dns_get_record($host, DNS_AAAA);
                        if (!empty($aaaa[0]['ipv6'])) {
                            $ns['ipv6'] = $aaaa[0]['ipv6'];
                        }
                    }

                    $nss[] = $ns;
                }
            } else {
                foreach (['ns1','ns2','ns3','ns4'] as $nsKey) {
                    $hostname = $domain->{'get' . ucfirst($nsKey)}();
                    if (!empty($hostname)) {
                        $hostname = (string)$hostname;
                        $hostname = function_exists('idn_to_ascii')
                            ? (idn_to_ascii($hostname, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $hostname)
                            : $hostname;

                        $nss[] = $hostname;
                    }
                }
            }

            $authInfoPw = $this->epp_random_auth_pw();

            $fredNsid = null;
            if ($profile === 'FRED') {
                $nss = array_values(array_filter(array_map(
                    static fn($n) => is_array($n) ? ($n['hostName'] ?? '') : $n,
                    $nss
                )));

                if (count($nss) < 2) {
                    throw new Registrar_Exception('FRED requires at least two nameservers.');
                }

                if (empty($contacts[1])) {
                    throw new Registrar_Exception('FRED requires a registrant contact. Disable "Minimum Data Set" for this registrar.');
                }

                // Glue IP addresses are only needed for nameservers inside the registered domain.
                $fredGlue = static function (string $host, string $domainName): string {
                    if (!preg_match('/(^|\.)' . preg_quote($domainName, '/') . '$/i', $host)) {
                        return '';
                    }
                    $a = @dns_get_record($host, DNS_A);
                    return $a[0]['ip'] ?? '';
                };

                $fredPrefix = strtoupper(trim((string)($this->config['registrarprefix'] ?? '')));
                $fredNsid = 'NSID-' . strtoupper($this->epp_random_contact_id()) . ($fredPrefix !== '' ? '-' . $fredPrefix : '');

                // Only the first two nameservers are sent: FredEpp::hostCreate builds a two-server nsset.
                $nsCreate = $epp->hostCreate([
                    'hostname'   => $nss[0],
                    'ipaddress'  => $fredGlue($nss[0], $domain_name),
                    'hostname2'  => $nss[1],
                    'ipaddress2' => $fredGlue($nss[1], $domain_name),
                    'nsid'       => $fredNsid,
                    'nstech'     => $contacts[1],
                ]);

                if (!empty($nsCreate['error'])) {
                    throw new Registrar_Exception((string)$nsCreate['error']);
                }

                if (!empty($this->config['debug_log'])) {
                    $this->getLog()->debug(
                        'EPP nssetCreate ' . $domain->getName() . ': ' .
                        json_encode($nsCreate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    );
                }
            }

            $payload = [
                'domainname' => $domain_name,
                'period'     => $period,
                'nss'        => $nss,
                'authInfoPw' => $authInfoPw,
            ];

            if ($profile === 'FRED') {
                $payload['nsid']  = $fredNsid;
                $payload['admin'] = '';
            }

            if (empty($this->config['min_data_set'])) {
                $payload['registrant'] = $contacts[1] ?? null;

                $mapIndex = [
                    'admin'   => 2,
                    'tech'    => 3,
                    'billing' => 4,
                ];

                if (in_array('tech', $contactTypes, true) && !in_array('admin', $contactTypes, true) && !in_array('billing', $contactTypes, true)) {
                    $mapIndex['tech'] = 2;
                }

                $contactsPayload = [];

                foreach (['admin','tech','billing'] as $role) {
                    if (!in_array($role, $contactTypes, true)) {
                        continue;
                    }

                    $idx = $mapIndex[$role] ?? null;
                    if ($idx && !empty($contacts[$idx])) {
                        $contactsPayload[$role] = $contacts[$idx];
                    }
                }

                if ($profile === 'EU') {
                    $euridBilling = trim($this->config['eurid_billing_contact'] ?? '');
                    if ($euridBilling !== '') {
                        $contactsPayload['billing'] = $euridBilling;
                    }
                }

                if (!empty($contactsPayload)) {
                    $payload['contacts'] = $contactsPayload;
                }
            }

            $domainCreate = $epp->domainCreate($payload);

            if (!empty($domainCreate['error'])) {
                throw new Registrar_Exception((string)$domainCreate['error']);
            }

            if ($profile === 'GE' && trim((string)($client->getCompany() ?? '')) === '') {
                $clTRID = str_replace('.', '', round(microtime(1), 3));

                $xml = array(
                    'xml' => '<?xml version="1.0" encoding="UTF-8"?>
                        <epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
                           <command>
                              <update>
                                 <domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
                                    <domain:name>'.$domain_name.'</domain:name>
                                    <domain:add>
                                       <domain:status s="hiddenInWhoIs" lang="en" />
                                    </domain:add>
                                 </domain:update>
                              </update>
                            <clTRID>'.$clTRID.'</clTRID>
                          </command>
                        </epp>');
                $rawXml = $epp->rawXml($xml);

                if (isset($rawXml['error'])) {
                    throw new Registrar_Exception('GE privacy update failed: ' . $rawXml['error']);
                }

                if (!empty($this->config['debug_log'])) {
                    $this->getLog()->debug(
                        'EPP rawXml ' . $domain->getName() . ': ' .
                        json_encode($rawXml, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    );
                }
            }

            if (!empty($this->config['debug_log'])) {
                $this->getLog()->debug(
                    'EPP domainCreate ' . $domain->getName() . ': ' .
                    json_encode($domainCreate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }

            return true;
        } catch (Registrar_Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Registrar_Exception(
                'Domain registration failed. Please try again later.'
            );
        } finally {
            if (isset($epp)) {
                $this->epp_client_logout($epp);
            }
        }
    }

    public function renewDomain(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Renewing domain: ' . $domain->getName());
        $profile = $this->config['registry_profile'] ?? 'generic';
        if ($profile === 'LV') {
            throw new Registrar_Exception("Not supported.");
        }
        try {
            $epp = $this->epp_client();
            $domain_name = $domain->getName();
            $domain_name = function_exists('idn_to_ascii')
                ? (idn_to_ascii($domain_name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain_name)
                : $domain_name;

            $domainRenew = $epp->domainRenew([
                'domainname' => $domain_name,
                'regperiod'  => 1,
            ]);

            if (isset($domainRenew['error'])) {
                throw new Registrar_Exception($domainRenew['error']);
            }

            if (!empty($this->config['debug_log'])) {
                $this->getLog()->debug(
                    'EPP domainRenew ' . $domain->getName() . ': ' .
                    json_encode($domainRenew, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }

            if ((int)($domainRenew['code'] ?? 0) === 1000) {
                $exDate = strtotime($domainRenew['exDate']);
                $domain->setExpirationTime($exDate);

                return true;
            } else {
                return false;
            }
        } catch (Registrar_Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Registrar_Exception(
                'Domain renewal failed. Please try again later.'
            );
        } finally {
            if (isset($epp)) {
                $this->epp_client_logout($epp);
            }
        }
    }

    public function modifyContact(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Updating contact info: ' . $domain->getName());

        if ($this->config['min_data_set'] === true) {
            throw new Registrar_Exception("To change contact information, please update your account profile.");
        }

        $client = $domain->getContactRegistrar();
        $profile = $this->config['registry_profile'] ?? 'generic';
        $contactPostalType = ($this->config['contact_postal_type'] ?? 'int') === 'loc' ? 'loc' : 'int';
        $ptValidation = null;
        if ($profile === 'PT') {
            $ptValidation = $this->getPtValidation(
                (string) ($client->getEmail() ?? '')
            );
        }
        try {
            $epp = $this->epp_client();
            $domain_name = $domain->getName();
            $domain_name = function_exists('idn_to_ascii')
                ? (idn_to_ascii($domain_name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain_name)
                : $domain_name;

            $info = $epp->domainInfo([
                'domainname' => $domain_name,
            ]);

            if (isset($info['error'])) {
                throw new Registrar_Exception($info['error']);
            }

            if (!empty($this->config['debug_log'])) {
                $this->getLog()->debug(
                    'EPP domainInfo ' . $domain->getName() . ': ' .
                    json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }

            $dcontact = [];

            if (!empty($info['registrant'])) {
                $dcontact['registrant'] = (string)$info['registrant'];
            }

            $contacts = $info['contact'] ?? [];
            if (!is_array($contacts)) {
                $contacts = [];
            }

            foreach ($contacts as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $type = (string)($row['type'] ?? '');
                $cid  = (string)($row['id'] ?? '');

                if ($type === '' || $cid === '') {
                    continue;
                }

                if (in_array($type, ['admin','tech','billing'], true)) {
                    $dcontact[$type] = $cid;
                }
            }

            foreach($dcontact as $type => $id) {
                $a = array();
                if ($type == 'registrant') {
                    $a = $domain->getContactRegistrar();
                }
                elseif ($type == 'admin') {
                    $a = $domain->getContactAdmin();
                }
                elseif ($type == 'tech') {
                    $a = $domain->getContactTech();
                }
                elseif ($type == 'billing') {
                    $a = $domain->getContactBilling();
                }

                if (empty($a)) {
                    continue;
                }

                $contactUpdate = $epp->contactUpdate([
                    'id'               => $id,
                    'type'             => $contactPostalType,
                    'firstname'        => $client->getFirstName(),
                    'lastname'         => $client->getLastName(),
                    'companyname'      => $client->getCompany(),
                    'address1'         => $client->getAddress1(),
                    'address2'         => $client->getAddress2(),
                    'city'             => $client->getCity(),
                    'state'            => $client->getState(),
                    'postcode'         => $client->getZip(),
                    'country'          => $client->getCountry(),
                    'fullphonenumber' => 
                        ($cc = preg_replace('/\D+/', '', (string) $client->getTelCc())) &&
                        ($tel = preg_replace('/\D+/', '', (string) $client->getTel()))
                            ? '+' . $cc . '.' . $tel
                            : '',
                    'email'            => $client->getEmail(),
                    'validated' => ($profile === 'PT') ? $ptValidation['validated'] : null,
                    'validatedDate' => ($profile === 'PT') ? $ptValidation['validatedDate'] : null,
                ]);

                if (isset($contactUpdate['error'])) {
                    throw new Registrar_Exception((string)$contactUpdate['error']);
                }

                if (!empty($this->config['debug_log'])) {
                    $this->getLog()->debug(
                        'EPP contactUpdate ' . $domain->getName() . ': ' .
                        json_encode($contactUpdate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    );
                }
            }
            
            return true;
        } catch (Registrar_Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Registrar_Exception(
                'Contact update failed. Please try again later.'
            );
        } finally {
            if (isset($epp)) {
                $this->epp_client_logout($epp);
            }
        }
    }

    public function enablePrivacyProtection(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Enabling Privacy protection: ' . $domain->getName());

        if ($this->config['min_data_set'] === true) {
            throw new Registrar_Exception("Privacy protection is controlled by the registry and cannot be changed.");
        }

        $profile = $this->config['registry_profile'] ?? 'generic';
        $contactPostalType = ($this->config['contact_postal_type'] ?? 'int') === 'loc'
            ? 'loc'
            : 'int';

        if ($profile === 'GE') {
            try {
                $epp = $this->epp_client();
                $domain_name = $domain->getName();
                $domain_name = function_exists('idn_to_ascii')
                    ? (idn_to_ascii($domain_name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain_name)
                    : $domain_name;
                $clTRID = str_replace('.', '', round(microtime(1), 3));

                $xml = array(
                    'xml' => '<?xml version="1.0" encoding="UTF-8"?>
                        <epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
                           <command>
                              <update>
                                 <domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
                                    <domain:name>'.$domain_name.'</domain:name>
                                    <domain:add>
                                       <domain:status s="hiddenInWhoIs" lang="en" />
                                    </domain:add>
                                 </domain:update>
                              </update>
                            <clTRID>'.$clTRID.'</clTRID>
                          </command>
                        </epp>');
                $rawXml = $epp->rawXml($xml);

                if (isset($rawXml['error'])) {
                    throw new Registrar_Exception($rawXml['error']);
                }

                if (!empty($this->config['debug_log'])) {
                    $this->getLog()->debug(
                        'EPP rawXml ' . $domain->getName() . ': ' .
                        json_encode($rawXml, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    );
                }

                return true;
            } catch (Registrar_Exception $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new Registrar_Exception(
                    'Privacy protection enable failed. Please try again later.'
                );
            } finally {
                if (isset($epp)) {
                    $this->epp_client_logout($epp);
                }
            }
        } else {
            try {
                $epp = $this->epp_client();
                $domain_name = $domain->getName();
                $domain_name = function_exists('idn_to_ascii')
                    ? (idn_to_ascii($domain_name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain_name)
                    : $domain_name;

                $info = $epp->domainInfo([
                    'domainname' => $domain_name,
                ]);

                if (isset($info['error'])) {
                    throw new Registrar_Exception($info['error']);
                }

                if (!empty($this->config['debug_log'])) {
                    $this->getLog()->debug(
                        'EPP domainInfo ' . $domain->getName() . ': ' .
                        json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    );
                }

                $dcontact = [];
                if (!empty($info['registrant'])) {
                    $dcontact['registrant'] = (string)$info['registrant'];
                }

                $rows = $info['contact'] ?? [];
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        if (!is_array($row)) continue;

                        $type = (string)($row['type'] ?? '');
                        $id   = (string)($row['id'] ?? '');

                        if ($type === '' || $id === '') continue;

                        $dcontact[$type] = $id;
                    }
                }

                $contact = [];
                foreach ($dcontact as $id) {
                    if (isset($contact[$id])) {
                        continue;
                    }
                    
                    $clTRID = str_replace('.', '', round(microtime(1), 3));

                    $xml = array(
                        'xml' => '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
                <epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
                     xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                     xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
                  <command>
                    <update>
                      <contact:update xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
                        <contact:id>'.$id.'</contact:id>
                        <contact:chg>
                          <contact:disclose flag="0">
                            <contact:name type="'.$contactPostalType.'"/>
                            <contact:addr type="'.$contactPostalType.'"/>
                            <contact:voice/>
                            <contact:fax/>
                            <contact:email/>
                          </contact:disclose>
                        </contact:chg>
                      </contact:update>
                    </update>
                    <clTRID>'.$clTRID.'</clTRID>
                  </command>
                </epp>
                ');
                    $rawXml = $epp->rawXml($xml);
                    
                    if (isset($rawXml['error'])) {
                        throw new Registrar_Exception($rawXml['error']);
                    }

                    if (!empty($this->config['debug_log'])) {
                        $this->getLog()->debug(
                            'EPP rawXml ' . $domain->getName() . ': ' .
                            json_encode($rawXml, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        );
                    }
                }

                return true;
            } catch (Registrar_Exception $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new Registrar_Exception(
                    'Privacy protection enable failed. Please try again later.'
                );
            } finally {
                if (isset($epp)) {
                    $this->epp_client_logout($epp);
                }
            }
        }
    }

    public function disablePrivacyProtection(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Disabling Privacy protection: ' . $domain->getName());

        if ($this->config['min_data_set'] === true) {
            throw new Registrar_Exception("Privacy protection is controlled by the registry and cannot be changed.");
        }

        $profile = $this->config['registry_profile'] ?? 'generic';
        $contactPostalType = ($this->config['contact_postal_type'] ?? 'int') === 'loc'
            ? 'loc'
            : 'int';

        if ($profile === 'GE') {
            try {
                $epp = $this->epp_client();
                $domain_name = $domain->getName();
                $domain_name = function_exists('idn_to_ascii')
                    ? (idn_to_ascii($domain_name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain_name)
                    : $domain_name;
                $clTRID = str_replace('.', '', round(microtime(1), 3));

                $xml = array(
                    'xml' => '<?xml version="1.0" encoding="UTF-8"?>
                        <epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
                           <command>
                              <update>
                                 <domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
                                    <domain:name>'.$domain_name.'</domain:name>
                                    <domain:rem>
                                       <domain:status s="hiddenInWhoIs" lang="en" />
                                    </domain:rem>
                                 </domain:update>
                              </update>
                            <clTRID>'.$clTRID.'</clTRID>
                          </command>
                        </epp>');
                $rawXml = $epp->rawXml($xml);

                if (isset($rawXml['error'])) {
                    throw new Registrar_Exception($rawXml['error']);
                }

                if (!empty($this->config['debug_log'])) {
                    $this->getLog()->debug(
                        'EPP rawXml ' . $domain->getName() . ': ' .
                        json_encode($rawXml, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    );
                }

                return true;
            } catch (Registrar_Exception $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new Registrar_Exception(
                    'Privacy protection disable failed. Please try again later.'
                );
            } finally {
                if (isset($epp)) {
                    $this->epp_client_logout($epp);
                }
            }
        } else {
            try {
                $epp = $this->epp_client();
                $domain_name = $domain->getName();
                $domain_name = function_exists('idn_to_ascii')
                    ? (idn_to_ascii($domain_name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain_name)
                    : $domain_name;

                $info = $epp->domainInfo([
                    'domainname' => $domain_name,
                ]);

                if (isset($info['error'])) {
                    throw new Registrar_Exception($info['error']);
                }

                if (!empty($this->config['debug_log'])) {
                    $this->getLog()->debug(
                        'EPP domainInfo ' . $domain->getName() . ': ' .
                        json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    );
                }

                $dcontact = [];
                if (!empty($info['registrant'])) {
                    $dcontact['registrant'] = (string)$info['registrant'];
                }

                $rows = $info['contact'] ?? [];
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        if (!is_array($row)) continue;

                        $type = (string)($row['type'] ?? '');
                        $id   = (string)($row['id'] ?? '');

                        if ($type === '' || $id === '') continue;

                        $dcontact[$type] = $id;
                    }
                }

                $contact = [];
                foreach ($dcontact as $id) {
                    if (isset($contact[$id])) {
                        continue;
                    }
                    
                    $clTRID = str_replace('.', '', round(microtime(1), 3));

                    $xml = array(
                        'xml' => '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
                <epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
                     xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                     xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
                  <command>
                    <update>
                      <contact:update xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
                        <contact:id>'.$id.'</contact:id>
                        <contact:chg>
                          <contact:disclose flag="1">
                            <contact:name type="'.$contactPostalType.'"/>
                            <contact:addr type="'.$contactPostalType.'"/>
                            <contact:voice/>
                            <contact:fax/>
                            <contact:email/>
                          </contact:disclose>
                        </contact:chg>
                      </contact:update>
                    </update>
                    <clTRID>'.$clTRID.'</clTRID>
                  </command>
                </epp>
                ');
                    $rawXml = $epp->rawXml($xml);
                    
                    if (isset($rawXml['error'])) {
                        throw new Registrar_Exception($rawXml['error']);
                    }

                    if (!empty($this->config['debug_log'])) {
                        $this->getLog()->debug(
                            'EPP rawXml ' . $domain->getName() . ': ' .
                            json_encode($rawXml, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        );
                    }
                }

                return true;
            } catch (Registrar_Exception $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new Registrar_Exception(
                    'Privacy protection disable failed. Please try again later.'
                );
            } finally {
                if (isset($epp)) {
                    $this->epp_client_logout($epp);
                }
            }
        }
    }

    public function getEpp(Registrar_Domain $domain)
    {
        if (($this->config['registry_profile'] ?? 'generic') === 'RO') {
            throw new Registrar_Exception('The domain holder must generate the registrar-transfer key at https://www.rotld.ro/domadmin. The domain management password is not a transfer key.');
        }

        $this->getLog()->debug('Retrieving domain transfer code: ' . $domain->getName());
        try {
            $epp = $this->epp_client();
            $domain_name = $domain->getName();
            $domain_name = function_exists('idn_to_ascii')
                ? (idn_to_ascii($domain_name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain_name)
                : $domain_name;
            $eppcode = null;
            
            if (!empty($this->config['set_authinfo_on_info'])) {
                $eppcode = $this->epp_random_auth_pw();

                $info = $epp->domainUpdateAuthinfo([
                    'domainname' => $domain_name,
                    'authInfo'   => $eppcode,
                ]);
                
                if (isset($info['error'])) {
                    throw new Registrar_Exception($info['error']);
                }

                if (!empty($this->config['debug_log'])) {
                    $this->getLog()->debug(
                        'EPP domainUpdateAuthinfo ' . $domain->getName() . ': ' .
                        json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    );
                }
            } else {
                $info = $epp->domainInfo([
                    'domainname' => $domain_name,
                ]);

                if (!empty($info['error'])) {
                    throw new Registrar_Exception((string)$info['error']);
                }

                if (!empty($this->config['debug_log'])) {
                    $this->getLog()->debug(
                        'EPP domainInfo ' . $domain->getName() . ': ' .
                        json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    );
                }

                $eppcode = (string)$info['authInfo'];
            }

            return $eppcode;
        } catch (Registrar_Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Registrar_Exception(
                'Domain authcode generation failed. Please try again later.'
            );
        } finally {
            if (isset($epp)) {
                $this->epp_client_logout($epp);
            }
        }
    }

    public function lock(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Locking domain: ' . $domain->getName());
        $return = array();
        try {
            $epp = $this->epp_client();
            $domain_name = $domain->getName();
            $domain_name = function_exists('idn_to_ascii')
                ? (idn_to_ascii($domain_name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain_name)
                : $domain_name;

            $info = $epp->domainInfo([
                'domainname' => $domain_name,
            ]);
                
            if (isset($info['error'])) {
                throw new Registrar_Exception($info['error']);
            }

            if (!empty($this->config['debug_log'])) {
                $this->getLog()->debug(
                    'EPP domainInfo ' . $domain->getName() . ': ' .
                    json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }

            $statuses = $info['status'] ?? [];
            if (!is_array($statuses)) {
                $statuses = [$statuses];
            }

            $status = [];
            foreach ($statuses as $st) {
                $st = (string)$st;
                if ($st === '') {
                    continue;
                }

                if (!preg_match('/^client.+Prohibited$/i', $st)) {
                    continue;
                }

                $status[$st] = true;
            }

            $add = [];

            $profile = $this->config['registry_profile'] ?? 'generic';

            $statuses = ['clientDeleteProhibited', 'clientTransferProhibited'];

            if ($profile === 'GE') {
                $statuses = ['clientTransferProhibited', 'clientUpdateProhibited'];
            }

            foreach ($statuses as $st) {
                if (!isset($status[$st])) {
                    $add[] = $st;
                }
            }

            foreach ($add as $st) {
                $resp = $epp->domainUpdateStatus([
                    'domainname' => $domain_name,
                    'command'    => 'add',
                    'status'     => $st,
                ]);

                if (!empty($resp['error'])) {
                    throw new Registrar_Exception((string)$resp['error']);
                }

                if (!empty($this->config['debug_log'])) {
                    $this->getLog()->debug(
                        'EPP domainUpdateStatus ' . $domain->getName() . ': ' .
                        json_encode($resp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    );
                }
            }

            return $return;
        } catch (Registrar_Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Registrar_Exception(
                'Domain lock failed. Please try again later.'
            );
        } finally {
            if (isset($epp)) {
                $this->epp_client_logout($epp);
            }
        }
    }

    public function unlock(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Unlocking: ' . $domain->getName());
        $return = array();
        try {
            $epp = $this->epp_client();
            $domain_name = $domain->getName();
            $domain_name = function_exists('idn_to_ascii')
                ? (idn_to_ascii($domain_name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain_name)
                : $domain_name;

            $info = $epp->domainInfo([
                'domainname' => $domain_name,
            ]);
                
            if (isset($info['error'])) {
                throw new Registrar_Exception($info['error']);
            }

            if (!empty($this->config['debug_log'])) {
                $this->getLog()->debug(
                    'EPP domainInfo ' . $domain->getName() . ': ' .
                    json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }

            $statuses = $info['status'] ?? [];
            if (!is_array($statuses)) {
                $statuses = [$statuses];
            }

            $status = [];
            foreach ($statuses as $st) {
                $st = (string)$st;
                if ($st === '') {
                    continue;
                }

                if (!preg_match('/^client.+Prohibited$/i', $st)) {
                    continue;
                }

                $status[$st] = true;
            }

            $rem = [];

            $profile = $this->config['registry_profile'] ?? 'generic';

            $statuses = ['clientDeleteProhibited', 'clientTransferProhibited'];

            if ($profile === 'GE') {
                $statuses = ['clientTransferProhibited', 'clientUpdateProhibited'];
            }

            foreach ($statuses as $st) {
                if (isset($status[$st])) {
                    $rem[] = $st;
                }
            }

            foreach ($rem as $st) {
                $resp = $epp->domainUpdateStatus([
                    'domainname' => $domain_name,
                    'command'    => 'rem',
                    'status'     => $st,
                ]);

                if (!empty($resp['error'])) {
                    throw new Registrar_Exception((string)$resp['error']);
                }

                if (!empty($this->config['debug_log'])) {
                    $this->getLog()->debug(
                        'EPP domainUpdateStatus ' . $domain->getName() . ': ' .
                        json_encode($resp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    );
                }
            }

            return $return;
        } catch (Registrar_Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Registrar_Exception(
                'Domain unlock failed. Please try again later.'
            );
        } finally {
            if (isset($epp)) {
                $this->epp_client_logout($epp);
            }
        }
    }

    /**
     * DomainX host-object capability check. Nameservers managed as domain
     * hostAttr values have no independent EPP host object to edit.
     */
    public function supportsGlue(Registrar_Domain $domain): bool
    {
        // FRED has no host objects: glue addresses are stored with the nameserver inside the nsset.
        if (($this->config['registry_profile'] ?? 'generic') === 'FRED') {
            return true;
        }

        if (($this->config['ns_mode'] ?? 'hostObj') !== 'hostObj') {
            return false;
        }

        $profile = trim((string) ($this->config['registry_profile'] ?? 'generic'));

        if (in_array($profile, ['EE', 'EU', 'FI', 'GE', 'GR', 'HR', 'IS', 'IT', 'LT', 'LV'], true)) {
            return false;
        }

        if ($profile === 'generic') {
            return in_array(
                'urn:ietf:params:xml:ns:host-1.0',
                $this->config['login_objects'] ?? [],
                true
            );
        }

        return true;
    }

    /** @param array<string, mixed> $params */
    public function getGlueHost(Registrar_Domain $domain, array $params): array
    {
        return $this->glueCommand($domain, 'info', $params);
    }

    /** @param array<string, mixed> $params */
    public function createGlueHost(Registrar_Domain $domain, array $params): array
    {
        return $this->glueCommand($domain, 'create', $params);
    }

    /** @param array<string, mixed> $params */
    public function updateGlueHost(Registrar_Domain $domain, array $params): array
    {
        return $this->glueCommand($domain, 'update', $params);
    }

    /** @param array<string, mixed> $params */
    public function deleteGlueHost(Registrar_Domain $domain, array $params): array
    {
        return $this->glueCommand($domain, 'delete', $params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function glueCommand(Registrar_Domain $domain, string $command, array $params): array
    {
        if (!$this->supportsGlue($domain)) {
            throw new Registrar_Exception('Glue host objects are not supported for this TLD.');
        }

        if (($this->config['registry_profile'] ?? 'generic') === 'FRED') {
            return $this->fredGlueCommand($domain, $command, $params);
        }

        $methods = [
            'info' => 'hostInfo',
            'create' => 'hostCreate',
            'update' => 'hostUpdate',
            'delete' => 'hostDelete',
        ];
        if (!isset($methods[$command])) {
            throw new Registrar_Exception('Unsupported glue operation.');
        }

        $request = ['hostname' => $this->glueHostname($domain, $params['hostname'] ?? null)];
        if ($command === 'create') {
            $request['ipaddress'] = $this->glueIpAddress($params['ipaddress'] ?? null);
        } elseif ($command === 'update') {
            if (isset($params['currentipaddress']) && $params['currentipaddress'] !== '') {
                $request['currentipaddress'] = $this->glueIpAddress($params['currentipaddress']);
            }
            if (isset($params['newipaddress']) && $params['newipaddress'] !== '') {
                $request['newipaddress'] = $this->glueIpAddress($params['newipaddress']);
            }
            if (!isset($request['currentipaddress']) && !isset($request['newipaddress'])) {
                throw new Registrar_Exception('An IP address to add or remove is required.');
            }
        }

        $method = $methods[$command];
        $epp = null;

        try {
            $epp = $this->epp_client();
            if (!is_callable([$epp, $method])) {
                throw new Registrar_Exception('The installed EPP client does not support glue host objects.');
            }

            $result = $epp->{$method}($request);
            if (!is_array($result)) {
                throw new Registrar_Exception('The registry returned an invalid glue response.');
            }
            if (!empty($result['error'])) {
                throw new Registrar_Exception('Glue operation failed: ' . (string) $result['error']);
            }

            $code = (int) ($result['code'] ?? 0);
            $message = $result['msg'] ?? 'Unknown registry response';
            if (is_array($message)) {
                $message = implode(' ', array_map(
                    static fn ($part): string => trim((string) $part),
                    $message
                ));
            }
            $message = trim((string) $message);

            if ($code < 1000 || $code >= 2000) {
                throw new Registrar_Exception(sprintf(
                    'The registry rejected the glue operation (%d): %s',
                    $code,
                    $message !== '' ? $message : 'Unknown registry response'
                ));
            }

            if ($command === 'info' && !is_array($result['addr'] ?? null)) {
                throw new Registrar_Exception('The registry returned invalid glue address data.');
            }

            if (!empty($this->config['debug_log'])) {
                $this->getLog()->debug(
                    'EPP ' . $method . ' ' . $request['hostname'] . ': ' .
                    json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }

            $result['code'] = $code;
            $result['msg'] = $message !== '' ? $message : 'Command completed successfully';

            return $result;
        } catch (Registrar_Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Registrar_Exception('Glue operation failed. Please try again later.');
        } finally {
            if ($epp !== null) {
                $this->epp_client_logout($epp);
            }
        }
    }

    // ---------------------------------------------------------------------
    // FRED (CZ.NIC): nameservers and their glue addresses live in nsset objects.
    // A change creates a new nsset, points the domain at it and removes the old one.
    // ---------------------------------------------------------------------

    private const FRED_NSSET_NS  = 'http://www.nic.cz/xml/epp/nsset-1.2';
    private const FRED_DOMAIN_NS = 'http://www.nic.cz/xml/epp/domain-1.4';

    private function fredIdn(string $name): string
    {
        $name = strtolower(rtrim(trim($name), '.'));

        return function_exists('idn_to_ascii')
            ? strtolower(idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $name)
            : $name;
    }

    /** Sends one command wrapped in <command>; throws on registry errors. */
    private function fredRaw($epp, string $body, string $what): \SimpleXMLElement
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>'
            . '<epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            . ' xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd"><command>' . $body
            . '<clTRID>' . htmlspecialchars('fred-' . $what . '-' . str_replace('.', '', (string) round(microtime(true), 3)))
            . '</clTRID></command></epp>';

        $r = $epp->rawXml(['xml' => $xml]);

        if (!empty($r['error'])) {
            throw new Registrar_Exception(ucfirst(str_replace('-', ' ', $what)) . ' failed: ' . (string) $r['error']);
        }

        $parsed = simplexml_load_string((string) ($r['xml'] ?? ''));
        if ($parsed === false) {
            throw new Registrar_Exception('The registry returned an invalid response.');
        }

        return $parsed;
    }

    /** @return array{ns: array<string, list<string>>, tech: list<string>, reportlevel: int} */
    private function fredNssetInfo($epp, string $nsid): array
    {
        $r = $this->fredRaw(
            $epp,
            '<info><nsset:info xmlns:nsset="' . self::FRED_NSSET_NS . '" xsi:schemaLocation="' . self::FRED_NSSET_NS
            . ' nsset-1.2.2.xsd"><nsset:id>' . htmlspecialchars($nsid) . '</nsset:id></nsset:info></info>',
            'nsset-info'
        );

        $inf = $r->response->resData->children(self::FRED_NSSET_NS)->infData;

        $ns = [];
        foreach ($inf->ns as $n) {
            $name = strtolower((string) $n->name);
            $ns[$name] = [];
            foreach ($n->addr as $a) {
                $ns[$name][] = strtolower(trim((string) $a));
            }
        }

        $tech = [];
        foreach ($inf->tech as $t) {
            $tech[] = (string) $t;
        }

        return ['ns' => $ns, 'tech' => $tech, 'reportlevel' => (int) ($inf->reportlevel ?? 0)];
    }

    /** @param array<string, list<string>> $ns @param list<string> $tech */
    private function fredNssetCreate($epp, string $nsid, array $ns, array $tech, int $reportlevel): void
    {
        $nsXml = '';
        foreach ($ns as $name => $ips) {
            $nsXml .= '<nsset:ns><nsset:name>' . htmlspecialchars((string) $name) . '</nsset:name>';
            foreach ($ips as $ip) {
                $nsXml .= '<nsset:addr>' . htmlspecialchars($ip) . '</nsset:addr>';
            }
            $nsXml .= '</nsset:ns>';
        }

        $techXml = '';
        foreach ($tech as $t) {
            $techXml .= '<nsset:tech>' . htmlspecialchars($t) . '</nsset:tech>';
        }

        $this->fredRaw(
            $epp,
            '<create><nsset:create xmlns:nsset="' . self::FRED_NSSET_NS . '" xsi:schemaLocation="' . self::FRED_NSSET_NS
            . ' nsset-1.2.2.xsd"><nsset:id>' . htmlspecialchars($nsid) . '</nsset:id>' . $nsXml . $techXml
            . '<nsset:reportlevel>' . $reportlevel . '</nsset:reportlevel></nsset:create></create>',
            'nsset-create'
        );
    }

    private function fredNssetDelete($epp, string $nsid): void
    {
        $this->fredRaw(
            $epp,
            '<delete><nsset:delete xmlns:nsset="' . self::FRED_NSSET_NS . '" xsi:schemaLocation="' . self::FRED_NSSET_NS
            . ' nsset-1.2.2.xsd"><nsset:id>' . htmlspecialchars($nsid) . '</nsset:id></nsset:delete></delete>',
            'nsset-delete'
        );
    }

    private function fredCurrentNssetId($epp, string $domainName): string
    {
        $info = $epp->domainInfo(['domainname' => $domainName]);

        if (!empty($info['error'])) {
            throw new Registrar_Exception((string) $info['error']);
        }

        $id = (string) (array_values($info['ns'] ?? [])[0] ?? '');
        if ($id === '') {
            throw new Registrar_Exception('This domain has no nsset in the registry.');
        }

        return $id;
    }

    /**
     * Applies $mutate to the domain's nameserver map (name => glue IPs) by creating a new nsset
     * and switching the domain to it. The previous nsset is removed when nothing else uses it.
     *
     * @param callable(array<string, list<string>>): array<string, list<string>> $mutate
     * @return array<string, list<string>>
     */
    private function fredReplaceNsset(Registrar_Domain $domain, callable $mutate): array
    {
        $epp = null;

        try {
            $epp = $this->epp_client();
            $domainName = $this->fredIdn($domain->getName());

            $oldId = $this->fredCurrentNssetId($epp, $domainName);
            $cur = $this->fredNssetInfo($epp, $oldId);
            // FRED unlinks the keyset whenever the nsset changes, unless the update names it again.
            $keysetId = $this->fredDomainLinks($epp, $domainName)['keyset'];

            $new = $mutate($cur['ns']);

            if (count($new) < 2) {
                throw new Registrar_Exception('FRED requires at least two nameservers.');
            }
            if (count($new) > 10) {
                throw new Registrar_Exception('FRED allows at most ten nameservers.');
            }

            $norm = static function (array $m): string {
                ksort($m);
                foreach ($m as &$ips) {
                    sort($ips);
                }
                return json_encode($m);
            };
            if ($norm($new) === $norm($cur['ns'])) {
                return $new;
            }

            $prefix = strtoupper(trim((string) ($this->config['registrarprefix'] ?? '')));
            $newId = 'NSID-' . strtoupper($this->epp_random_contact_id()) . ($prefix !== '' ? '-' . $prefix : '');

            $this->fredNssetCreate($epp, $newId, $new, $cur['tech'], $cur['reportlevel']);

            try {
                $this->fredRaw(
                    $epp,
                    '<update><domain:update xmlns:domain="' . self::FRED_DOMAIN_NS . '" xsi:schemaLocation="'
                    . self::FRED_DOMAIN_NS . ' domain-1.4.2.xsd"><domain:name>' . htmlspecialchars($domainName)
                    . '</domain:name><domain:chg><domain:nsset>' . htmlspecialchars($newId) . '</domain:nsset>'
                    . ($keysetId !== '' ? '<domain:keyset>' . htmlspecialchars($keysetId) . '</domain:keyset>' : '')
                    . '</domain:chg></domain:update></update>',
                    'domain-update-nsset'
                );
            } catch (\Throwable $e) {
                // Do not leave the unused nsset behind.
                try {
                    $this->fredNssetDelete($epp, $newId);
                } catch (\Throwable $ignored) {
                }
                throw $e;
            }

            // Best effort: fails harmlessly while other domains still use the old nsset.
            try {
                $this->fredNssetDelete($epp, $oldId);
            } catch (\Throwable $ignored) {
            }

            if (!empty($this->config['debug_log'])) {
                $this->getLog()->debug(
                    'EPP FRED nsset ' . $oldId . ' -> ' . $newId . ' for ' . $domain->getName() . ': '
                    . json_encode($new, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }

            return $new;
        } catch (Registrar_Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Registrar_Exception('Nameserver update failed. Please try again later.');
        } finally {
            if ($epp !== null) {
                $this->epp_client_logout($epp);
            }
        }
    }

    private function fredModifyNs(Registrar_Domain $domain): bool
    {
        $desired = [];
        foreach (['ns1', 'ns2', 'ns3', 'ns4'] as $key) {
            $host = $domain->{'get' . ucfirst($key)}();
            if (!empty($host)) {
                $desired[] = $this->fredIdn((string) $host);
            }
        }
        $desired = array_values(array_unique($desired));

        $domainName = $this->fredIdn($domain->getName());

        $this->fredReplaceNsset($domain, function (array $current) use ($desired, $domainName): array {
            $new = [];
            foreach ($desired as $host) {
                if (isset($current[$host])) {
                    $new[$host] = $current[$host]; // keep the glue addresses already registered
                    continue;
                }

                // A nameserver inside the domain itself needs an address; try DNS if none is known.
                $ips = [];
                if (preg_match('/(^|\.)' . preg_quote($domainName, '/') . '$/', $host)) {
                    foreach ((@dns_get_record($host, DNS_A) ?: []) as $rec) {
                        if (!empty($rec['ip'])) {
                            $ips[] = $rec['ip'];
                        }
                    }
                }
                $new[$host] = $ips;
            }

            return $new;
        });

        return true;
    }

    /**
     * Glue for FRED: the addresses of a nameserver stored in the domain's nsset.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function fredGlueCommand(Registrar_Domain $domain, string $command, array $params): array
    {
        $hostname = $this->glueHostname($domain, $params['hostname'] ?? null);
        $ok = ['code' => 1000, 'msg' => 'Command completed successfully'];

        if ($command === 'info') {
            $epp = null;

            try {
                $epp = $this->epp_client();
                $nsid = $this->fredCurrentNssetId($epp, $this->fredIdn($domain->getName()));
                $cur = $this->fredNssetInfo($epp, $nsid);

                return $ok + ['addr' => array_values($cur['ns'][$hostname] ?? [])];
            } catch (Registrar_Exception $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new Registrar_Exception('Glue lookup failed. Please try again later.');
            } finally {
                if ($epp !== null) {
                    $this->epp_client_logout($epp);
                }
            }
        }

        $this->fredReplaceNsset($domain, function (array $ns) use ($command, $hostname, $params): array {
            if (!isset($ns[$hostname])) {
                // FRED needs the address at the moment a nameserver inside the domain is added, so
                // creating glue for a host that is not yet a nameserver adds it as one.
                if ($command !== 'create') {
                    throw new Registrar_Exception(
                        'FRED stores glue addresses with the nameserver. ' . $hostname . ' is not a nameserver of this domain.'
                    );
                }
                $ns[$hostname] = [];
            }

            switch ($command) {
                case 'create':
                    $ns[$hostname][] = $this->glueIpAddress($params['ipaddress'] ?? null);
                    break;
                case 'update':
                    if (!empty($params['currentipaddress'])) {
                        $remove = $this->glueIpAddress($params['currentipaddress']);
                        $ns[$hostname] = array_values(array_filter(
                            $ns[$hostname],
                            static fn(string $ip): bool => $ip !== $remove
                        ));
                    }
                    if (!empty($params['newipaddress'])) {
                        $ns[$hostname][] = $this->glueIpAddress($params['newipaddress']);
                    }
                    break;
                case 'delete':
                    $ns[$hostname] = [];
                    break;
            }

            $ns[$hostname] = array_values(array_unique($ns[$hostname]));

            return $ns;
        });

        return $ok;
    }

    private function glueHostname(Registrar_Domain $domain, $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new Registrar_Exception('Glue hostname is required.');
        }

        $hostname = strtolower(rtrim(trim($value), '.'));
        $domainName = strtolower(rtrim($domain->getName(), '.'));
        if (function_exists('idn_to_ascii')) {
            $hostname = idn_to_ascii($hostname, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '';
            $domainName = idn_to_ascii($domainName, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '';
        }
        $hostname = strtolower($hostname);
        $domainName = strtolower($domainName);

        if (strlen($hostname) > 253 || !str_ends_with($hostname, '.' . $domainName)) {
            throw new Registrar_Exception('Glue hostname must be within this domain.');
        }
        foreach (explode('.', $hostname) as $label) {
            if (strlen($label) > 63 || !preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/', $label)) {
                throw new Registrar_Exception('Glue hostname is invalid.');
            }
        }

        return $hostname;
    }

    private function glueIpAddress($value): string
    {
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_IP) === false) {
            throw new Registrar_Exception('A valid IPv4 or IPv6 address is required.');
        }

        return strtolower($value);
    }

    // ---------------------------------------------------------------------
    // FRED DNSSEC. FRED stores DNSKEYs in keyset objects, not DS records. DomainX collects DS
    // values, so for an "add" the DNSKEY that produces the given DS is looked up on the domain's own
    // nameservers and verified against the DS before the keyset is written. The zone must already
    // be signed and served by those nameservers. DS shown to the customer are derived (SHA-256).
    // ---------------------------------------------------------------------

    private const FRED_KEYSET_NS = 'http://www.nic.cz/xml/epp/keyset-1.3';
    private const FRED_DS_DIGESTS = [1 => 'sha1', 2 => 'sha256', 4 => 'sha384'];

    private static function fredWireName(string $name): string
    {
        $out = '';
        foreach (explode('.', strtolower(rtrim($name, '.'))) as $label) {
            if ($label !== '') {
                $out .= chr(strlen($label)) . $label;
            }
        }

        return $out . "\0";
    }

    /** @param array{flags:int,protocol:int,alg:int,pubKey:string} $key */
    private static function fredDnskeyRdata(array $key): string
    {
        return pack('nCC', $key['flags'], $key['protocol'], $key['alg'])
            . (string) base64_decode(preg_replace('/\s+/', '', $key['pubKey']), true);
    }

    /** RFC 4034 appendix B key tag. */
    private static function fredKeyTag(string $rdata): int
    {
        $sum = 0;
        for ($i = 0, $n = strlen($rdata); $i < $n; $i++) {
            $sum += ($i & 1) ? ord($rdata[$i]) : (ord($rdata[$i]) << 8);
        }
        $sum += ($sum >> 16) & 0xFFFF;

        return $sum & 0xFFFF;
    }

    /**
     * DS record that a DNSKEY produces (RFC 4034 section 5.1.4).
     *
     * @param array{flags:int,protocol:int,alg:int,pubKey:string} $key
     * @return array{key_tag:int,algorithm:int,digest_type:int,digest:string}|null
     */
    private function fredDnskeyToDs(string $domainName, array $key, int $digestType): ?array
    {
        if (!isset(self::FRED_DS_DIGESTS[$digestType])) {
            return null;
        }

        $rdata = self::fredDnskeyRdata($key);

        return [
            'key_tag' => self::fredKeyTag($rdata),
            'algorithm' => $key['alg'],
            'digest_type' => $digestType,
            'digest' => strtoupper(hash(self::FRED_DS_DIGESTS[$digestType], self::fredWireName($domainName) . $rdata)),
        ];
    }

    private static function fredKeyId(array $key): string
    {
        return $key['flags'] . '|' . $key['protocol'] . '|' . $key['alg'] . '|' . preg_replace('/\s+/', '', $key['pubKey']);
    }

    /** @return list<array{flags:int,protocol:int,alg:int,pubKey:string}> DNSKEYs from a DNS response. */
    private function fredParseDnskeyResponse(string $resp): array
    {
        if (strlen($resp) < 12) {
            return [];
        }

        $h = unpack('nid/nflags/nqd/nan', $resp);
        if (($h['flags'] & 0x000F) !== 0) {
            return [];
        }

        $skipName = static function (string $m, int $pos): int {
            while ($pos < strlen($m)) {
                $len = ord($m[$pos]);
                if ($len === 0) {
                    return $pos + 1;
                }
                if (($len & 0xC0) === 0xC0) {
                    return $pos + 2;
                }
                $pos += $len + 1;
            }

            return $pos;
        };

        $pos = 12;
        for ($i = 0; $i < $h['qd']; $i++) {
            $pos = $skipName($resp, $pos) + 4;
        }

        $keys = [];
        for ($i = 0; $i < $h['an'] && $pos < strlen($resp); $i++) {
            $pos = $skipName($resp, $pos);
            if ($pos + 10 > strlen($resp)) {
                break;
            }
            $rr = unpack('ntype/nclass/Nttl/nlen', substr($resp, $pos, 10));
            $pos += 10;
            $rdata = substr($resp, $pos, $rr['len']);
            $pos += $rr['len'];

            if ($rr['type'] === 48 && strlen($rdata) > 4) {
                $f = unpack('nflags/Cprotocol/Calg', $rdata);
                $keys[] = [
                    'flags' => $f['flags'],
                    'protocol' => $f['protocol'],
                    'alg' => $f['alg'],
                    'pubKey' => base64_encode(substr($rdata, 4)),
                ];
            }
        }

        return $keys;
    }

    private static function fredReadExact($fp, int $len): string
    {
        $buf = '';
        while (strlen($buf) < $len) {
            $chunk = fread($fp, $len - strlen($buf));
            if ($chunk === false || $chunk === '') {
                return '';
            }
            $buf .= $chunk;
        }

        return $buf;
    }

    /**
     * Asks the domain's own nameservers for its DNSKEY set (TCP, so large answers fit).
     *
     * @param array<string, list<string>> $nsMap nameserver => glue addresses
     * @return list<array{flags:int,protocol:int,alg:int,pubKey:string}>
     */
    protected function fredFetchDnskeys(string $domainName, array $nsMap): array
    {
        $servers = [];
        foreach ($nsMap as $name => $ips) {
            foreach ($ips as $ip) {
                $servers[$ip] = true;
            }
            foreach (@dns_get_record((string) $name, DNS_A | DNS_AAAA) ?: [] as $rec) {
                $ip = $rec['ip'] ?? $rec['ipv6'] ?? null;
                if ($ip) {
                    $servers[$ip] = true;
                }
            }
        }

        $query = pack('nnnnnn', random_int(0, 65535), 0x0100, 1, 0, 0, 0)
            . self::fredWireName($domainName) . pack('nn', 48, 1);

        foreach (array_keys($servers) as $ip) {
            $host = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
            $fp = @stream_socket_client('tcp://' . $host . ':53', $errno, $errstr, 4);
            if (!$fp) {
                continue;
            }
            stream_set_timeout($fp, 4);
            fwrite($fp, pack('n', strlen($query)) . $query);
            $lenBytes = self::fredReadExact($fp, 2);
            $resp = $lenBytes === '' ? '' : self::fredReadExact($fp, unpack('n', $lenBytes)[1]);
            fclose($fp);

            $keys = $this->fredParseDnskeyResponse($resp);
            if ($keys !== []) {
                return $keys;
            }
        }

        return [];
    }

    /** @return array{nsset:string,keyset:string} */
    private function fredDomainLinks($epp, string $domainName): array
    {
        $r = $this->fredRaw(
            $epp,
            '<info><domain:info xmlns:domain="' . self::FRED_DOMAIN_NS . '" xsi:schemaLocation="' . self::FRED_DOMAIN_NS
            . ' domain-1.4.2.xsd"><domain:name>' . htmlspecialchars($domainName) . '</domain:name></domain:info></info>',
            'domain-info'
        );

        $inf = $r->response->resData->children(self::FRED_DOMAIN_NS)->infData;

        return ['nsset' => trim((string) $inf->nsset), 'keyset' => trim((string) $inf->keyset)];
    }

    /** @return array{keys: list<array{flags:int,protocol:int,alg:int,pubKey:string}>, tech: list<string>} */
    private function fredKeysetInfo($epp, string $id): array
    {
        $r = $this->fredRaw(
            $epp,
            '<info><keyset:info xmlns:keyset="' . self::FRED_KEYSET_NS . '" xsi:schemaLocation="' . self::FRED_KEYSET_NS
            . ' keyset-1.3.4.xsd"><keyset:id>' . htmlspecialchars($id) . '</keyset:id></keyset:info></info>',
            'keyset-info'
        );

        $inf = $r->response->resData->children(self::FRED_KEYSET_NS)->infData;

        $keys = [];
        foreach ($inf->dnskey as $k) {
            $keys[] = [
                'flags' => (int) $k->flags,
                'protocol' => (int) $k->protocol,
                'alg' => (int) $k->alg,
                'pubKey' => preg_replace('/\s+/', '', (string) $k->pubKey),
            ];
        }

        $tech = [];
        foreach ($inf->tech as $t) {
            $tech[] = (string) $t;
        }

        return ['keys' => $keys, 'tech' => $tech];
    }

    private static function fredKeysXml(array $keys): string
    {
        $xml = '';
        foreach ($keys as $k) {
            $xml .= '<keyset:dnskey><keyset:flags>' . (int) $k['flags'] . '</keyset:flags><keyset:protocol>'
                . (int) $k['protocol'] . '</keyset:protocol><keyset:alg>' . (int) $k['alg']
                . '</keyset:alg><keyset:pubKey>' . htmlspecialchars($k['pubKey']) . '</keyset:pubKey></keyset:dnskey>';
        }

        return $xml;
    }

    private function fredKeysetCreate($epp, string $id, array $keys, array $tech): void
    {
        $techXml = '';
        foreach ($tech as $t) {
            $techXml .= '<keyset:tech>' . htmlspecialchars($t) . '</keyset:tech>';
        }

        $this->fredRaw(
            $epp,
            '<create><keyset:create xmlns:keyset="' . self::FRED_KEYSET_NS . '" xsi:schemaLocation="' . self::FRED_KEYSET_NS
            . ' keyset-1.3.4.xsd"><keyset:id>' . htmlspecialchars($id) . '</keyset:id>' . self::fredKeysXml($keys) . $techXml
            . '</keyset:create></create>',
            'keyset-create'
        );
    }

    private function fredKeysetUpdate($epp, string $id, array $add, array $rem): void
    {
        $this->fredRaw(
            $epp,
            '<update><keyset:update xmlns:keyset="' . self::FRED_KEYSET_NS . '" xsi:schemaLocation="' . self::FRED_KEYSET_NS
            . ' keyset-1.3.4.xsd"><keyset:id>' . htmlspecialchars($id) . '</keyset:id>'
            . ($add ? '<keyset:add>' . self::fredKeysXml($add) . '</keyset:add>' : '')
            . ($rem ? '<keyset:rem>' . self::fredKeysXml($rem) . '</keyset:rem>' : '')
            . '</keyset:update></update>',
            'keyset-update'
        );
    }

    private function fredKeysetDelete($epp, string $id): void
    {
        $this->fredRaw(
            $epp,
            '<delete><keyset:delete xmlns:keyset="' . self::FRED_KEYSET_NS . '" xsi:schemaLocation="' . self::FRED_KEYSET_NS
            . ' keyset-1.3.4.xsd"><keyset:id>' . htmlspecialchars($id) . '</keyset:id></keyset:delete></delete>',
            'keyset-delete'
        );
    }

    /** Links the domain to a keyset, or unlinks it when $id is null. */
    private function fredDomainSetKeyset($epp, string $domainName, ?string $id): void
    {
        $this->fredRaw(
            $epp,
            '<update><domain:update xmlns:domain="' . self::FRED_DOMAIN_NS . '" xsi:schemaLocation="' . self::FRED_DOMAIN_NS
            . ' domain-1.4.2.xsd"><domain:name>' . htmlspecialchars($domainName) . '</domain:name><domain:chg>'
            . ($id === null ? '<domain:keyset/>' : '<domain:keyset>' . htmlspecialchars($id) . '</domain:keyset>')
            . '</domain:chg></domain:update></update>',
            'domain-update-keyset'
        );
    }

    /** @return list<array{key_tag:int,algorithm:int,digest_type:int,digest:string}> */
    private function fredGetDnssec(Registrar_Domain $domain): array
    {
        $epp = null;

        try {
            $epp = $this->epp_client();
            $domainName = $this->fredIdn($domain->getName());

            $links = $this->fredDomainLinks($epp, $domainName);
            if ($links['keyset'] === '') {
                return [];
            }

            $records = [];
            foreach ($this->fredKeysetInfo($epp, $links['keyset'])['keys'] as $key) {
                $ds = $this->fredDnskeyToDs($domainName, $key, 2);
                if ($ds !== null) {
                    $records[] = $ds;
                }
            }

            return $records;
        } catch (Registrar_Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Registrar_Exception('DNSSEC record lookup failed. Please try again later.');
        } finally {
            if ($epp !== null) {
                $this->epp_client_logout($epp);
            }
        }
    }

    /** @param array<string, mixed> $params the DomainX parameters (record 1 = add or remove, record 2 = added by addrem) */
    private function fredUpdateDnssec(Registrar_Domain $domain, string $command, array $params): array
    {
        $ds = static fn (string $n): array => [
            'key_tag' => (int) $params['keyTag_' . $n],
            'algorithm' => (int) $params['alg_' . $n],
            'digest_type' => (int) $params['digestType_' . $n],
            'digest' => strtoupper(preg_replace('/\s+/', '', (string) $params['digest_' . $n])),
        ];

        $toRemove = $command === 'add' ? null : $ds('1');
        $toAdd = $command === 'rem' ? null : ($command === 'add' ? $ds('1') : $ds('2'));

        $epp = null;

        try {
            $epp = $this->epp_client();
            $domainName = $this->fredIdn($domain->getName());

            $links = $this->fredDomainLinks($epp, $domainName);
            if ($links['nsset'] === '') {
                throw new Registrar_Exception('This domain has no nsset in the registry.');
            }

            $cur = ['keys' => [], 'tech' => []];
            if ($links['keyset'] !== '') {
                $cur = $this->fredKeysetInfo($epp, $links['keyset']);
            }

            $matches = static fn (array $d, array $k, self $self): bool => ($c = $self->fredDnskeyToDs($domainName, $k, $d['digest_type'])) !== null
                && $c['key_tag'] === $d['key_tag'] && $c['algorithm'] === $d['algorithm'] && $c['digest'] === $d['digest'];

            // Keys that stay: everything currently set, minus the key behind the removed DS.
            $keep = [];
            $removed = [];
            foreach ($cur['keys'] as $k) {
                if ($toRemove !== null && $matches($toRemove, $k, $this)) {
                    $removed[] = $k;
                } else {
                    $keep[] = $k;
                }
            }
            if ($toRemove !== null && $removed === []) {
                throw new Registrar_Exception('The DS record to remove is not registered for this domain.');
            }

            // Keys to add: DNSKEYs published by the domain's nameservers that produce the given DS.
            $added = [];
            if ($toAdd !== null) {
                $nsInfo = $this->fredNssetInfo($epp, $links['nsset']);
                $found = $this->fredFetchDnskeys($domainName, $nsInfo['ns']);
                foreach ($found as $k) {
                    if ($matches($toAdd, $k, $this)) {
                        $added[self::fredKeyId($k)] = $k;
                    }
                }
                if ($added === []) {
                    throw new Registrar_Exception(
                        $found === []
                            ? 'No DNSKEY could be read from the nameservers of this domain. FRED needs the public key, '
                                . 'so sign the zone, make sure the nameservers answer DNSKEY queries and try again.'
                            : 'None of the DNSKEYs published by the nameservers match this DS record.'
                    );
                }
            }

            $have = [];
            foreach ($keep as $k) {
                $have[self::fredKeyId($k)] = true;
            }
            $new = $keep;
            $addNew = [];
            foreach ($added as $id => $k) {
                if (!isset($have[$id])) {
                    $new[] = $k;
                    $addNew[] = $k;
                }
            }

            if (count($new) > 10) {
                throw new Registrar_Exception('FRED allows at most ten DNSKEYs per domain.');
            }

            if ($addNew === [] && $removed === []) {
                return ['code' => 1000, 'msg' => 'Command completed successfully'];
            }

            if ($new === []) {
                // Last key removed: unlink first, then drop the keyset.
                $this->fredDomainSetKeyset($epp, $domainName, null);
                try {
                    $this->fredKeysetDelete($epp, $links['keyset']);
                } catch (\Throwable $ignored) {
                }
            } elseif ($links['keyset'] === '') {
                $prefix = strtoupper(trim((string) ($this->config['registrarprefix'] ?? '')));
                $newId = 'KSID-' . strtoupper($this->epp_random_contact_id()) . ($prefix !== '' ? '-' . $prefix : '');
                $tech = $this->fredNssetInfo($epp, $links['nsset'])['tech'];

                $this->fredKeysetCreate($epp, $newId, $new, $tech);
                try {
                    $this->fredDomainSetKeyset($epp, $domainName, $newId);
                } catch (\Throwable $e) {
                    try {
                        $this->fredKeysetDelete($epp, $newId);
                    } catch (\Throwable $ignored) {
                    }
                    throw $e;
                }
            } else {
                $this->fredKeysetUpdate($epp, $links['keyset'], $addNew, $removed);
            }

            if (!empty($this->config['debug_log'])) {
                $this->getLog()->debug('EPP FRED DNSSEC ' . $command . ' ' . $domain->getName() . ': ' . count($new) . ' key(s)');
            }

            return ['code' => 1000, 'msg' => 'Command completed successfully'];
        } catch (Registrar_Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Registrar_Exception('DNSSEC update failed. Please try again later.');
        } finally {
            if ($epp !== null) {
                $this->epp_client_logout($epp);
            }
        }
    }

    public function getDNSSEC(Registrar_Domain $domain): array
    {
        if (($this->config['registry_profile'] ?? 'generic') === 'FRED') {
            return $this->fredGetDnssec($domain);
        }

        $this->getLog()->debug('Getting DNSSEC records: ' . $domain->getName());

        try {
            $epp = $this->epp_client();

            $domainName = $domain->getName();
            $domainName = function_exists('idn_to_ascii')
                ? (idn_to_ascii($domainName, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domainName)
                : $domainName;

            $info = $epp->domainInfo([
                'domainname' => $domainName,
            ]);

            if (!empty($info['error'])) {
                throw new Registrar_Exception((string) $info['error']);
            }

            $records = [];
            $dsData = $info['dsData'] ?? [];

            if (!is_array($dsData)) {
                return [];
            }

            foreach ($dsData as $record) {
                if (
                    !is_array($record)
                    || !isset(
                        $record['keyTag'],
                        $record['alg'],
                        $record['digestType'],
                        $record['digest']
                    )
                    || !is_numeric($record['keyTag'])
                    || !is_numeric($record['alg'])
                    || !is_numeric($record['digestType'])
                    || !is_scalar($record['digest'])
                ) {
                    continue;
                }

                $records[] = [
                    'key_tag' => (int) $record['keyTag'],
                    'algorithm' => (int) $record['alg'],
                    'digest_type' => (int) $record['digestType'],
                    'digest' => strtoupper(
                        (string) preg_replace(
                            '/\s+/',
                            '',
                            (string) $record['digest']
                        )
                    ),
                ];
            }

            return $records;
        } catch (Registrar_Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Registrar_Exception(
                'DNSSEC record lookup failed. Please try again later.'
            );
        } finally {
            if (isset($epp)) {
                $this->epp_client_logout($epp);
            }
        }
    }

    public function updateDNSSEC(Registrar_Domain $domain, array $params): array
    {
        $this->getLog()->debug('Updating DNSSEC for domain: ' . $domain->getName());

        $command = strtolower(trim((string) ($params['command'] ?? '')));

        if (!in_array($command, ['add', 'rem', 'addrem'], true)) {
            throw new Registrar_Exception('Unsupported DNSSEC command.');
        }

        $required = [
            'keyTag_1',
            'alg_1',
            'digestType_1',
            'digest_1',
        ];

        if ($command === 'addrem') {
            $required = array_merge($required, [
                'keyTag_2',
                'alg_2',
                'digestType_2',
                'digest_2',
            ]);
        }

        foreach ($required as $field) {
            if (
                !array_key_exists($field, $params)
                || $params[$field] === ''
                || $params[$field] === null
            ) {
                throw new Registrar_Exception(
                    'Missing required DNSSEC field: ' . $field
                );
            }
        }

        if (($this->config['registry_profile'] ?? 'generic') === 'FRED') {
            return $this->fredUpdateDnssec($domain, $command, $params);
        }

        $domainName = $domain->getName();
        $domainName = function_exists('idn_to_ascii')
            ? (idn_to_ascii(
                $domainName,
                IDNA_DEFAULT,
                INTL_IDNA_VARIANT_UTS46
            ) ?: $domainName)
            : $domainName;

        $request = [
            'domainname' => $domainName,
            'command' => $command,
            'keyTag_1' => (int) $params['keyTag_1'],
            'alg_1' => (int) $params['alg_1'],
            'digestType_1' => (int) $params['digestType_1'],
            'digest_1' => strtoupper(trim((string) $params['digest_1'])),
        ];

        if ($command === 'addrem') {
            $request['keyTag_2'] = (int) $params['keyTag_2'];
            $request['alg_2'] = (int) $params['alg_2'];
            $request['digestType_2'] = (int) $params['digestType_2'];
            $request['digest_2'] = strtoupper(
                trim((string) $params['digest_2'])
            );
        }

        $epp = null;

        try {
            $epp = $this->epp_client();

            if (!method_exists($epp, 'domainUpdateDNSSEC')) {
                throw new Registrar_Exception(
                    'The installed EPP client does not support DNSSEC updates.'
                );
            }

            $result = $epp->domainUpdateDNSSEC($request);

            if (!is_array($result)) {
                throw new Registrar_Exception(
                    'The registry returned an invalid DNSSEC response.'
                );
            }

            if (!empty($result['error'])) {
                throw new Registrar_Exception(
                    'DNSSEC update failed: ' . (string) $result['error']
                );
            }

            $code = (int) ($result['code'] ?? 0);
            $message = $result['msg'] ?? 'Unknown registry response';

            if (is_array($message)) {
                $message = implode(' ', array_map(
                    static fn ($part): string => trim((string) $part),
                    $message
                ));
            }

            $message = trim((string) $message);

            if ($code < 1000 || $code >= 2000) {
                throw new Registrar_Exception(
                    sprintf(
                        'The registry rejected the DNSSEC update (%d): %s',
                        $code,
                        $message !== '' ? $message : 'Unknown registry response'
                    )
                );
            }

            if (!empty($this->config['debug_log'])) {
                $this->getLog()->debug(
                    'EPP domainUpdateDNSSEC ' . $domain->getName() . ': ' .
                    json_encode(
                        $result,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    )
                );
            }

            $result['code'] = $code;
            $result['msg'] = $message !== ''
                ? $message
                : 'Command completed successfully';

            return $result;
        } catch (Registrar_Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Registrar_Exception(
                'DNSSEC update failed. Please try again later.'
            );
        } finally {
            if ($epp !== null) {
                $this->epp_client_logout($epp);
            }
        }
    }

    private function getRoContactFields(Registrar_Domain_Contact $contact): array
    {
        global $di;

        // The associated order identifies the client; never guess by contact email.
        $order = $this->_order;
        $clientId = is_object($order) && method_exists($order, 'getClientId')
            ? $order->getClientId()
            : ($order->client_id ?? null);
        if (empty($clientId) || !isset($di['pdo'])) {
            throw new Registrar_Exception('RoTLD registration requires the associated FOSSBilling client order.');
        }
        $statement = $di['pdo']->prepare('SELECT * FROM client WHERE id = :id LIMIT 1');
        $statement->execute(['id' => (int)$clientId]);
        $client = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!$client) {
            throw new Registrar_Exception('The RoTLD holder client profile could not be found.');
        }

        $company = trim((string)$contact->getCompany());
        $values = [
            'person_type' => $company !== '' ? 'c' : 'p',
            'cnp_fiscal_code' => $company !== ''
                ? trim((string)($client['company_vat'] ?? ''))
                : trim((string)$contact->getDocumentNr()),
            'registration_number' => trim((string)$contact->getCompanyNumber()),
        ];
        $mappings = [
            'person_type' => $this->config['ro_person_type_field'],
            'cnp_fiscal_code' => $this->config['ro_fiscal_code_field'],
            'registration_number' => $this->config['ro_registration_number_field'],
        ];
        foreach ($mappings as $field => $source) {
            $source = trim((string)$source);
            if ($source === '' || $source === 'auto') {
                continue;
            }
            if ($source === 'document_nr') {
                $value = trim((string)$contact->getDocumentNr());
            } else if (in_array($source, ['company_vat', 'company_number'], true)
                || preg_match('/^custom_(?:[1-9]|1[0-9]|20)$/D', $source)) {
                $value = trim((string)($client[$source] ?? ''));
            } else {
                throw new Registrar_Exception('Invalid RoTLD client-field mapping: ' . $source);
            }
            if ($value !== '') {
                $values[$field] = $value;
            }
        }
        $values['person_type'] = strtolower($values['person_type']);
        if (!in_array($values['person_type'], ['p', 'ap', 'nc', 'c', 'gi', 'pi', 'o'], true)) {
            throw new Registrar_Exception('RoTLD person type must be p, ap, nc, c, gi, pi or o.');
        }
        if (!in_array($values['person_type'], ['p', 'ap'], true) && $company === '') {
            throw new Registrar_Exception('A legal entity name is required in the holder Company Name field.');
        }
        if (strtoupper((string)$contact->getCountry()) === 'RO') {
            if ($values['cnp_fiscal_code'] === '') {
                throw new Registrar_Exception('CNP / fiscal code is required for Romanian holders.');
            }
            if ($values['person_type'] === 'c' && $values['registration_number'] === '') {
                throw new Registrar_Exception('Company registration number is required for Romanian companies.');
            }
        }

        return $values;
    }

    private function getPtValidation(string $email): array
    {
        global $di;

        $extensions = $di['mod_service']('extension');

        $moduleActive = $extensions->isExtensionActive(
            'mod',
            'registrar'
        ) || $extensions->isExtensionActive(
            'mod',
            'domaincontactvalidation'
        );

        if (!$moduleActive) {
            throw new Registrar_Exception(
                'PT contact operations require Registrar or '
                . 'DomainContactValidation to be active.'
            );
        }

        $email = trim($email);

        $statement = $di['pdo']->prepare(
            'SELECT id FROM client WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => $email]);

        $clientId = $statement->fetchColumn();

        if (!$clientId) {
            throw new Registrar_Exception(
                'No FOSSBilling client was found for the PT contact email.'
            );
        }

        $statement = $di['pdo']->prepare(
            'SELECT is_validated, validation_checked_at
             FROM domain_contact_validation
             WHERE client_id = :client_id
             ORDER BY validation_checked_at DESC
             LIMIT 1'
        );
        $statement->execute(['client_id' => $clientId]);

        $validation = $statement->fetch(\PDO::FETCH_ASSOC);

        if (!$validation) {
            throw new Registrar_Exception(
                'No contact-validation record was found for this client.'
            );
        }

        return [
            'validated' => (int) $validation['is_validated'] === 1
                ? 'true'
                : 'false',
            'validatedDate' => str_replace(' ', 'T', (string) $validation['validation_checked_at']) . 'Z',
        ];
    }

    public function epp_client()
    {
        $profile = $this->config['registry_profile'] ?? 'generic';

        $epp = EppRegistryFactory::create($profile);

        if (!empty($this->config['debug_log'])) {
            if (
                !class_exists(\Monolog\Logger::class)
                || !class_exists(\Monolog\Handler\RotatingFileHandler::class)
                || !class_exists(\Monolog\Formatter\LineFormatter::class)
            ) {
                throw new Registrar_Exception(
                    'EPP debug logging requires monolog/monolog.'
                );
            }

            $logPath = trim((string) ($this->config['debug_log_path'] ?? ''));
            $epp->setLogPath($logPath !== '' ? $logPath : __DIR__ . '/../log');
        } else {
            $epp->disableLogging();
        }

        $tls_version = '1.2';
        if (!empty($this->config['tls_version'])) {
            $tls_version = '1.3';
        }
        
        $verify_peer = false;
        if ($this->config['verify_peer'] == 'on') {
            $verify_peer = true;
        }

        $moduleDir = __DIR__;

        $certPath = trim($this->config['local_cert'] ?? '');
        $keyPath  = trim($this->config['local_pk'] ?? '');

        if ($certPath === '' || $keyPath === '') {
            throw new Registrar_Exception('Client certificate and private key are required.');
        }

        if ($certPath[0] !== '/' && !preg_match('~^[A-Za-z]:[\\\\/]~', $certPath)) {
            $certPath = $moduleDir . '/' . $certPath;
        }
        if ($keyPath[0] !== '/' && !preg_match('~^[A-Za-z]:[\\\\/]~', $keyPath)) {
            $keyPath = $moduleDir . '/' . $keyPath;
        }

        $certPath = realpath($certPath);
        $keyPath  = realpath($keyPath);

        if ($certPath === false || $keyPath === false) {
            throw new Registrar_Exception(
                'EPP TLS certificate or key not found or not readable. '
                . 'cert=' . ($certPath ?: 'false') . ' key=' . ($keyPath ?: 'false')
            );
        }

        $info = [
            'host'    => $this->config['host'] ?? '',
            'port'    => (int)($this->config['port'] ?? 700),
            'timeout' => 30,
            'tls'     => $tls_version ?? '1.2',
            'bind'    => false,
            'bindip'  => '1.2.3.4:0',
            'verify_peer'      => !empty($verify_peer),
            'verify_peer_name' => false,
            'cafile'           => $this->config['cafile'] ?? '',
            'local_cert' => $certPath,
            'local_pk' => $keyPath,
            'passphrase'       => $this->config['passphrase'] ?? '',
            'allow_self_signed'=> true,
        ];
        if ($profile === 'generic') {
            $rawObjects = $this->config['login_objects'] ?? '';

            if (is_array($rawObjects)) {
                $info['loginObjects'] = array_values(array_filter(array_map('trim', $rawObjects)));
            } else {
                $info['loginObjects'] = trim($rawObjects) !== ''
                    ? array_values(array_filter(array_map('trim', preg_split('/[,\s]+/', $rawObjects))))
                    : [
                        'urn:ietf:params:xml:ns:domain-1.0',
                        'urn:ietf:params:xml:ns:contact-1.0',
                        'urn:ietf:params:xml:ns:host-1.0',
                    ];
            }

            $epp->setLoginObjects($info['loginObjects']);

            $rawExtensions = $this->config['login_extensions'] ?? '';

            if (is_array($rawExtensions)) {
                $info['loginExtensions'] = array_values(array_filter(array_map('trim', $rawExtensions)));
            } else {
                $info['loginExtensions'] = trim($rawExtensions) !== ''
                    ? array_values(array_filter(array_map('trim', preg_split('/[,\s]+/', $rawExtensions))))
                    : [
                        'urn:ietf:params:xml:ns:secDNS-1.1',
                        'urn:ietf:params:xml:ns:rgp-1.0',
                    ];
            }

            $epp->setLoginExtensions($info['loginExtensions']);
        }

        if ($profile === 'FRED') {
            // FRED (CZ.NIC) uses its own object mappings, not the RFC 5731/5733/5732 ones.
            $epp->setLoginObjects([
                'http://www.nic.cz/xml/epp/contact-1.6',
                'http://www.nic.cz/xml/epp/domain-1.4',
                'http://www.nic.cz/xml/epp/nsset-1.2',
                'http://www.nic.cz/xml/epp/keyset-1.3',
            ]);
        }

        if (empty($info['host']) || empty($info['port'])) {
            throw new Registrar_Exception('EPP host/port not configured');
        }

        $epp->connect($info);

        $login = $epp->login([
            'clID'   => $this->config['clid'] ?? '',
            'pw'     => $this->config['pw'] ?? '',
            'prefix' => $this->config['registrarprefix'] ?? 'epp',
        ]);

        if (isset($login['error'])) {
            throw new Registrar_Exception('Login Error: ' . $login['error']);
        }

        return $epp;
    }

    public function epp_client_logout($epp)
    {
        try { $epp->logout(); } catch (\Throwable $e) {}
    }
    
    public function epp_random_contact_id(int $len = 10): string {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return strtoupper($out);
    }

    public function epp_random_auth_pw(int $len = 16): string {
        $result = '';
        $uppercaseChars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
        $lowercaseChars = "abcdefghijklmnopqrstuvwxyz";
        $numbers = "1234567890";
        $specialSymbols = "!=+-";
        $minLength = 16;
        $maxLength = 16;
        $length = mt_rand($minLength, $maxLength);

        // Include at least one character from each set
        $result .= $uppercaseChars[mt_rand(0, strlen($uppercaseChars) - 1)];
        $result .= $lowercaseChars[mt_rand(0, strlen($lowercaseChars) - 1)];
        $result .= $numbers[mt_rand(0, strlen($numbers) - 1)];
        $result .= $specialSymbols[mt_rand(0, strlen($specialSymbols) - 1)];

        // Append random characters to reach the desired length
        while (strlen($result) < $length) {
            $chars = $uppercaseChars . $lowercaseChars . $numbers . $specialSymbols;
            $result .= $chars[mt_rand(0, strlen($chars) - 1)];
        }

        return $result;
    }
}
