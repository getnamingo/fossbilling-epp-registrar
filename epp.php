<?php
/**
 * Namingo EPP Registrar module for FOSSBilling (https://fossbilling.org/)
 *
 * Written in 2024-2026 by Taras Kondratyuk (https://namingo.org)
 * Based on Generic EPP with DNSsec Registrar Module for WHMCS written in 2019 by Lilian Rudenco (info@xpanel.com)
 * Work of Lilian Rudenco is under http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 *
 * @license MIT
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
            'set_authinfo_on_info' => !empty($options['set_authinfo_on_info']),
            'min_data_set'         => !empty($options['min_data_set']),
            'registry_profile'     => $options['registry_profile'] ?? 'generic',

            // Extensions
            'login_extensions' => isset($options['login_extensions']) && $options['login_extensions'] !== ''
                ? array_map('trim', explode(',', $options['login_extensions']))
                : [],
            
            // EURid
            'eurid_billing_contact' => $options['eurid_billing_contact'] ?? null,

            // NASK
            'pl_contact_prefix' => $options['pl_contact_prefix'] ?? null,

            'epp_debug_log' => !empty($options['epp_debug_log']),
        ];
    }

    public function getTlds()
    {
        return array();
    }

    public static function getConfig()
    {
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

                'registry_profile' => ['select', [
                    'label'        => 'Registry Profile',
                    'required'     => true,
                    'default'      => 'generic',
                    'multiOptions' => [
                        'generic' => 'generic',
                        'EU'      => 'EU',
                        'FR'      => 'FR',
                        'HR'      => 'HR',
                        'LV'      => 'LV',
                        'MX'      => 'MX',
                        'PL'      => 'PL',
                        'PT'      => 'PT',
                        'SE'      => 'SE',
                        'SWITCH'  => 'SWITCH',
                        'UA'      => 'UA',
                        'VRSN'    => 'VRSN',
                    ],
                    'description'  => 'Select the registry profile matching the registry implementation. List of profiles: https://github.com/getnamingo/whmcs-epp-registrar',
                ]],

                'set_authinfo_on_info' => ['radio', [
                    'multiOptions' => ['1' => 'Yes', '0' => 'No'],
                    'label'        => 'Set AuthInfo on Request',
                    'description'  => 'Enable if the registry does not return the transfer code on domain info and requires setting it manually first.',
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

                'epp_debug_log' => ['radio', [
                    'multiOptions' => ['1' => 'Yes', '0' => 'No'],
                    'label'        => 'EPP Debug Logging',
                    'default'      => '0',
                    'description'  =>
                        'Write EPP requests and responses to the Module Log for troubleshooting. ' .
                        'Enable only while debugging, then disable.',
                ]],

            ],
        ];
    }
    
    public function isDomaincanBeTransferred(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Checking if domain can be transferred: ' . $domain->getName());
        return true;
    }

    public function isDomainAvailable(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Checking domain availability: ' . $domain->getName());
        
        try {
            $epp = $this->epp_client();

            $domainCheck = $epp->domainCheck([
                'domains' => [$domain->getName()],
            ]);

            if (!empty($domainCheck['error'])) {
                throw new Registrar_Exception((string)$domainCheck['error']);
            }

            if (!empty($this->config['epp_debug_log'])) {
                $this->getLog()->debug(
                    'EPP domainCheck ' . $domain->getName() . ': ' .
                    json_encode($domainCheck, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }

            $item = ($domainCheck['domains'][0] ?? null);

            if (!$item || empty($item['name'])) {
                throw new Registrar_Exception('Domain check failed: empty response');
            }

            $avail = filter_var($item['avail'] ?? false, FILTER_VALIDATE_BOOL);
            $reason = (string)($item['reason'] ?? '');

            if ($avail) {
                return true;
            }

            throw new Registrar_Exception(
                'Domain is not available' . ($reason ? ': ' . $reason : '')
            );
        } catch (Registrar_Exception  $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Registrar_Exception(
                'Domain availability check failed. Please try again later.'
            );
        } finally {
            $this->epp_client_logout($epp);
        }
    }

    public function modifyNs(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Modifying nameservers: ' . $domain->getName());
        $this->getLog()->debug('Ns1: ' . $domain->getNs1());
        $this->getLog()->debug('Ns2: ' . $domain->getNs2());
        $this->getLog()->debug('Ns3: ' . $domain->getNs3());
        $this->getLog()->debug('Ns4: ' . $domain->getNs4());
        try {
            $epp = $this->epp_client();

            $info = $epp->domainInfo([
                'domainname' => $domain->getName(),
            ]);

            if (isset($info['error'])) {
                throw new Registrar_Exception($info['error']);
            }

            if (!empty($this->config['epp_debug_log'])) {
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

                if (in_array($v, $current, true)) {
                    continue;
                }

                $add[$k] = $v;
            }

            $profile = $this->config['registry_profile'] ?? 'generic';
            if (!in_array($profile, ['EU', 'HR', 'LV', 'GE'], true)) {
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

                        if (!empty($this->config['epp_debug_log'])) {
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

                        if (!empty($this->config['epp_debug_log'])) {
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

              $final["ns$i"] = $v;
            }

            if (in_array($profile, ['EU', 'HR', 'LV', 'GE'], true)) {
                $payload = [
                    'domainname' => $domain->getName(),
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
                $payload = ['domainname' => $domain->getName()];

                foreach (array_values($final) as $idx => $host) {
                    $payload['ns' . ($idx + 1)] = $host;
                }

                $domainUpdateNS = $epp->domainUpdateNS($payload);
            }

            if (!empty($domainUpdateNS['error'])) {
                throw new Registrar_Exception((string)$domainUpdateNS['error']);
            }

            if (!empty($this->config['epp_debug_log'])) {
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
            $this->epp_client_logout($epp);
        }
    }

    public function transferDomain(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Transfering domain: ' . $domain->getName());
        $this->getLog()->debug('Epp code: ' . $domain->getEpp());
        try {
            $epp = $this->epp_client();
            
            $profile = $this->config['registry_profile'] ?? 'generic';
            if ($profile === 'FR') {
                $domainInfo = $epp->domainInfo([
                    'domainname' => $domain->getName(),
                ]);

                if (isset($domainInfo['error'])) {
                    throw new Registrar_Exception($domainInfo['error']);
                }

                if (!empty($this->config['epp_debug_log'])) {
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
                    'domainname' => $domain->getName(),
                    'years'      => 1,
                    'authInfoPw' => $domain->getEpp(),
                    'op'         => 'request',
                    'admin'      => $adminId,
                    'tech'       => $techId,
                ]);
            } else {
                $domainTransfer = $epp->domainTransfer([
                    'domainname' => $domain->getName(),
                    'years'      => 1,
                    'authInfoPw' => $domain->getEpp(),
                    'op'         => 'request',
                ]);
            }

            if (isset($domainTransfer['error'])) {
                throw new Registrar_Exception($domainTransfer['error']);
            }

            if (!empty($this->config['epp_debug_log'])) {
                $this->getLog()->debug(
                    'EPP domainTransfer ' . $domain->getName() . ': ' .
                    json_encode($domainTransfer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }

            return true;
        } catch (Registrar_Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Registrar_Exception(
                'Domain transfer failed. Please try again later.'
            );
        } finally {
            $this->epp_client_logout($epp);
        }
    }

    public function getDomainDetails(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Getting domain details: ' . $domain->getName());
        try {
            $epp = $this->epp_client();

            $info = $epp->domainInfo([
                'domainname' => $domain->getName(),
            ]);

            if (!empty($info['error'])) {
                throw new Registrar_Exception((string)$info['error']);
            }

            if (!empty($this->config['epp_debug_log'])) {
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
            $domain->setEpp($eppcode);

            $domain->setNs1(isset($ns[0]) ? $ns[0] : '');
            $domain->setNs2(isset($ns[1]) ? $ns[1] : '');
            $domain->setNs3(isset($ns[2]) ? $ns[2] : '');
            $domain->setNs4(isset($ns[3]) ? $ns[3] : '');

            return $domain;
        } catch (Registrar_Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Registrar_Exception(
                'Domain information failed. Please try again later.'
            );
        } finally {
            $this->epp_client_logout($epp);
        }
    }

    public function deleteDomain(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Removing domain: ' . $domain->getName());
        try {
            $epp = $this->epp_client();

            $domainDelete = $epp->domainDelete([
                'domainname' => $domain->getName(),
            ]);

            if (isset($domainDelete['error'])) {
                throw new Registrar_Exception($domainDelete['error']);
            }

            if (!empty($this->config['epp_debug_log'])) {
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
            $this->epp_client_logout($epp);
        }
    }

    public function registerDomain(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Registering domain: ' . $domain->getName(). ' for '.$domain->getRegistrationPeriod(). ' years');
        $client = $domain->getContactRegistrar();
        
        try {
            $epp = $this->epp_client();

            $domainCheck = $epp->domainCheck([
                'domains' => [$domain->getName()],
            ]);

            if (!empty($domainCheck['error'])) {
                throw new Registrar_Exception((string)$domainCheck['error']);
            }

            if (!empty($this->config['epp_debug_log'])) {
                $this->getLog()->debug(
                    'EPP domainCheck ' . $domain->getName() . ': ' .
                    json_encode($domainCheck, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }

            $item = ($domainCheck['domains'][0] ?? null);

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
                    'generic'=> ['registrant', 'admin', 'tech', 'billing'],
                    'VRSN'   => ['registrant', 'admin', 'tech', 'billing'],
                ];

                $profile = $this->config['registry_profile'] ?? 'generic';

                $contactTypes = $contactTypeMap[$profile]
                    ?? $contactTypeMap['generic'];

                foreach ($contactTypes as $i => $contactType) {

                    $id = strtoupper($this->epp_random_contact_id());
                    if ($profile === 'PL') {
                        $prefix = trim($this->config['pl_contact_prefix'] ?? '');

                        if ($prefix !== '') {
                            $id = $prefix . $id;
                        }
                    }
                    $authInfoPw = $this->epp_random_auth_pw();

                    $contactCreate = $epp->contactCreate([
                        'id'              => $id,
                        'type'            => 'int',
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
                        'nin_type' => ($profile === 'HR')
                            ? ($client->getCompanyNumber() ? 'company' : 'personal')
                            : null,
                        // PT-only extras
                        'vat' => ($profile === 'PT' && $client->getCompanyNumber())
                            ? strtoupper($client->getCountry()) . $client->getCompanyNumber()
                            : null,
                    ]);

                    if (!empty($contactCreate['error'])) {
                        throw new Registrar_Exception((string)$contactCreate['error']);
                    }
                    
                    if (!empty($this->config['epp_debug_log'])) {
                        $this->getLog()->debug(
                            'EPP contactCreate ' . $domain->getName() . ': ' .
                            json_encode($contactCreate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        );
                    }

                    $createdId = $contactCreate['id'] ?? $id;
                    $contacts[$i + 1] = $createdId;
                }
            }

            $profile = $this->config['registry_profile'] ?? 'generic';
            if (!in_array($profile, ['EU', 'HR', 'LV', 'GE'], true)) {
                foreach (['ns1','ns2','ns3','ns4'] as $nsKey) {
                    $hostname = $domain->{'get' . ucfirst($nsKey)}();
                    if (empty($hostname)) {
                        continue;
                    }

                    $hostCheck = $epp->hostCheck([
                        'hostname' => $hostname,
                    ]);

                    if (!empty($hostCheck['error'])) {
                        throw new Registrar_Exception((string)$hostCheck['error']);
                    }

                    if (!empty($this->config['epp_debug_log'])) {
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

                    if (!empty($this->config['epp_debug_log'])) {
                        $this->getLog()->debug(
                            'EPP hostCreate ' . $domain->getName() . ': ' .
                            json_encode($hostCreate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        );
                    }
                }
            }

            $period     = (int)($domain->getRegistrationPeriod() ?? 1);
            
            $nss = [];
            if (in_array($profile, ['EU', 'HR', 'LV', 'GE'], true)) {
                foreach (['ns1','ns2','ns3','ns4'] as $nsKey) {
                    $host = $domain->{'get' . ucfirst($nsKey)}();
                    if (empty($host)) {
                        continue;
                    }

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
                        $nss[] = $hostname;
                    }
                }
            }

            $authInfoPw = $this->epp_random_auth_pw();

            $payload = [
                'domainname' => $domain->getName(),
                'period'     => $period,
                'nss'        => $nss,
                'authInfoPw' => $authInfoPw,
            ];

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

            if (!empty($this->config['epp_debug_log'])) {
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
            $this->epp_client_logout($epp);
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
        
            $domainRenew = $epp->domainRenew([
                'domainname' => $domain->getName(),
                'regperiod'  => 1,
            ]);

            if (isset($domainRenew['error'])) {
                throw new Registrar_Exception($domainRenew['error']);
            }

            if (!empty($this->config['epp_debug_log'])) {
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
            $this->epp_client_logout($epp);
        }
    }

    public function modifyContact(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Updating contact info: ' . $domain->getName());

        if ($this->config['min_data_set'] === true) {
            throw new Registrar_Exception("To change contact information, please update your account profile.");
        }

        $client = $domain->getContactRegistrar();
        try {
            $epp = $this->epp_client();

            $info = $epp->domainInfo([
                'domainname' => $domain->getName(),
            ]);

            if (isset($info['error'])) {
                throw new Registrar_Exception($info['error']);
            }

            if (!empty($this->config['epp_debug_log'])) {
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
                    'type'             => 'int',
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
                ]);

                if (isset($contactUpdate['error'])) {
                    echo 'ContactUpdate Error: ' . $contactUpdate['error'] . PHP_EOL;
                    return;
                }

                if (!empty($this->config['epp_debug_log'])) {
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
            $this->epp_client_logout($epp);
        }
    }
    
    public function enablePrivacyProtection(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Enabling Privacy protection: ' . $domain->getName());

        if ($this->config['min_data_set'] === true) {
            throw new Registrar_Exception("Privacy protection is controlled by the registry and cannot be changed.");
        }

        try {
            $epp = $this->epp_client();

            $info = $epp->domainInfo([
                'domainname' => $domain->getName(),
            ]);

            if (isset($info['error'])) {
                throw new Registrar_Exception($info['error']);
            }

            if (!empty($this->config['epp_debug_log'])) {
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
                        <contact:name type="int"/>
                        <contact:addr type="int"/>
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

                if (!empty($this->config['epp_debug_log'])) {
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
            $this->epp_client_logout($epp);
        }
    }

    public function disablePrivacyProtection(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Disabling Privacy protection: ' . $domain->getName());

        if ($this->config['min_data_set'] === true) {
            throw new Registrar_Exception("Privacy protection is controlled by the registry and cannot be changed.");
        }

        try {
            $epp = $this->epp_client();

            $info = $epp->domainInfo([
                'domainname' => $domain->getName(),
            ]);

            if (isset($info['error'])) {
                throw new Registrar_Exception($info['error']);
            }

            if (!empty($this->config['epp_debug_log'])) {
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
                        <contact:name type="int"/>
                        <contact:addr type="int"/>
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

                if (!empty($this->config['epp_debug_log'])) {
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
            $this->epp_client_logout($epp);
        }
    }

    public function getEpp(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Retrieving domain transfer code: ' . $domain->getName());
        try {
            $epp = $this->epp_client();
            $eppcode = null;
            
            if (!empty($this->config['set_authinfo_on_info'])) {
                $eppcode = $this->epp_random_auth_pw();

                $info = $epp->domainUpdateAuthinfo([
                    'domainname' => $domain->getName(),
                    'authInfo'   => $eppcode,
                ]);
                
                if (isset($info['error'])) {
                    throw new Registrar_Exception($info['error']);
                }

                if (!empty($this->config['epp_debug_log'])) {
                    $this->getLog()->debug(
                        'EPP domainUpdateAuthinfo ' . $domain->getName() . ': ' .
                        json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    );
                }
            } else {
                $info = $epp->domainInfo([
                    'domainname' => $domain->getName(),
                ]);

                if (!empty($info['error'])) {
                    throw new Registrar_Exception((string)$info['error']);
                }

                if (!empty($this->config['epp_debug_log'])) {
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
            $this->epp_client_logout($epp);
        }
    }

    public function lock(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Locking domain: ' . $domain->getName());
        $return = array();
        try {
            $epp = $this->epp_client();

            $info = $epp->domainInfo([
                'domainname' => $domain->getName(),
            ]);
                
            if (isset($info['error'])) {
                throw new Registrar_Exception($info['error']);
            }

            if (!empty($this->config['epp_debug_log'])) {
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

            foreach (['clientDeleteProhibited', 'clientTransferProhibited', 'clientUpdateProhibited'] as $st) {
                if (!isset($status[$st])) {
                    $add[] = $st;
                }
            }

            foreach ($add as $st) {
                $resp = $epp->domainUpdateStatus([
                    'domainname' => $domain->getName(),
                    'command'    => 'add',
                    'status'     => $st,
                ]);

                if (!empty($resp['error'])) {
                    throw new Registrar_Exception((string)$resp['error']);
                }

                if (!empty($this->config['epp_debug_log'])) {
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
            $this->epp_client_logout($epp);
        }
    }

    public function unlock(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Unlocking: ' . $domain->getName());
        $return = array();
        try {
            $epp = $this->epp_client();

            $info = $epp->domainInfo([
                'domainname' => $domain->getName(),
            ]);
                
            if (isset($info['error'])) {
                throw new Registrar_Exception($info['error']);
            }

            if (!empty($this->config['epp_debug_log'])) {
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

            foreach (['clientDeleteProhibited', 'clientTransferProhibited', 'clientUpdateProhibited'] as $st) {
                if (isset($status[$st])) {
                    $rem[] = $st;
                }
            }

            foreach ($rem as $st) {
                $resp = $epp->domainUpdateStatus([
                    'domainname' => $domain->getName(),
                    'command'    => 'rem',
                    'status'     => $st,
                ]);

                if (!empty($resp['error'])) {
                    throw new Registrar_Exception((string)$resp['error']);
                }

                if (!empty($this->config['epp_debug_log'])) {
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
            $this->epp_client_logout($epp);
        }
    }

    public function epp_client()
    {
        $profile = $this->config['registry_profile'] ?? 'generic';

        $epp = EppRegistryFactory::create($profile);
        $epp->disableLogging();

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
            $raw = $this->config['login_extensions'] ?? '';

            if (is_array($raw)) {
                $info['loginExtensions'] = array_values(array_filter(array_map('trim', $raw)));
            } else {
                $info['loginExtensions'] = trim($raw) !== ''
                    ? array_values(array_filter(array_map('trim', preg_split('/[,\s]+/', $raw))))
                    : [
                        'urn:ietf:params:xml:ns:secDNS-1.1',
                        'urn:ietf:params:xml:ns:rgp-1.0',
                    ];
            }

            $epp->setLoginExtensions($info['loginExtensions']);
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