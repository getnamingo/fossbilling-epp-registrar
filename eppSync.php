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

require_once __DIR__ . '/load.php';
$di = include __DIR__ . '/di.php';

$autoload = __DIR__ . '/namingo/vendor/autoload.php';

if (file_exists($autoload)) {
    require_once $autoload;
}

use Pinga\Tembo\EppRegistryFactory;

$dbConfig = \FOSSBilling\Config::getProperty('db', []);
$registrar = "Epp";

try
{
    $dsn = $dbConfig["type"] . ":host=" . $dbConfig["host"] . ";port=" . $dbConfig["port"] . ";dbname=" . $dbConfig["name"];
    $pdo = new PDO($dsn, $dbConfig["user"], $dbConfig["password"]);
    $stmt = $pdo->prepare("SELECT * FROM tld_registrar WHERE registrar = :registrar");
    $stmt->bindValue(":registrar", $registrar);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $config = [];

    foreach ($rows as $row)
    {
        $config = json_decode($row["config"], true);
        $registrar_id = $row["id"];
    }

    if (empty($config))
    {
        exit("Database cannot be accessed right now.".PHP_EOL);
    }

} catch(PDOException $e) {
    exit("Database error: " . $e->getMessage().PHP_EOL);
} catch(Exception $e) {
    exit("General error: " . $e->getMessage().PHP_EOL);
}

try {
    // Fetch all domains
    $stmt = $pdo->prepare('SELECT sld, tld FROM service_domain WHERE tld_registrar_id = :registrar');
    $stmt->bindValue(':registrar', $registrar_id);
    $stmt->execute();
    $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $epp = epp_client($config);

    $sqlCheck = 'SELECT COUNT(*) FROM extension WHERE name = :name AND status = :status';
    $stmtCheck = $pdo->prepare($sqlCheck);
    $stmtCheck->bindValue(':name', 'registrar');
    $stmtCheck->bindValue(':status', 'installed');
    $stmtCheck->execute();
    $count = (int)$stmtCheck->fetchColumn();

    foreach ($domains as $domainRow) {
        $domain = rtrim($domainRow['sld'], '.') . '.' . ltrim($domainRow['tld'], '.');
        $domainInfo = $epp->domainInfo([
            'domainname' => $domain,
        ]);
        
        $code = $domainInfo['code'] ?? null;
        $err  = $domainInfo['error'] ?? '';

        if (array_key_exists("error", $domainInfo) || ($code == 2303)) {
            $isNotExist = ($code == 2303) || (is_string($err) && strpos($err, "Domain does not exist") !== false);

            if ($isNotExist) {
                $stmt = $pdo->prepare('SELECT id FROM service_domain WHERE sld = :sld AND tld = :tld');
                $stmt->bindValue(':sld', $domainRow['sld']);
                $stmt->bindValue(':tld', $domainRow['tld']);
                $stmt->execute();
                $serviceDomain = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($serviceDomain) {
                    $serviceId = $serviceDomain['id'];
                    $stmt = $pdo->prepare('UPDATE client_order SET canceled_at = :canceled_at, status = :status, reason = :reason WHERE service_id = :service_id');
                    $stmt->bindValue(':canceled_at', date('Y-m-d H:i:s'));
                    $stmt->bindValue(':status', 'cancelled');
                    $stmt->bindValue(':reason', 'domain deleted');
                    $stmt->bindValue(':service_id', $serviceId);
                    $stmt->execute();
                }

                $stmt = $pdo->prepare('DELETE FROM service_domain WHERE sld = :sld AND tld = :tld');
                $stmt->bindValue(':sld', $domainRow['sld']);
                $stmt->bindValue(':tld', $domainRow['tld']);
                $stmt->execute();
            }

            echo (($err !== '' ? $err : ('EPP error code ' . (string)$code)) . " (" . $domain . ")") . PHP_EOL;
            continue;
        }

        $ns = $domainInfo['ns'] ?? null;
        $ns1 = $ns2 = $ns3 = $ns4 = null;

        if (is_array($ns) && count($ns) > 0) {
            $ns = array_values($ns);

            $ns1 = $ns[0] ?? null;
            $ns2 = $ns[1] ?? null;
            $ns3 = $ns[2] ?? null;
            $ns4 = $ns[3] ?? null;
        }

        $exDate = $domainInfo['exDate'] ?? null;
        $formattedExDate = null;
        if ($exDate) {
            try { $formattedExDate = (new DateTime($exDate))->format('Y-m-d H:i:s'); } catch (\Throwable $e) { $formattedExDate = null; }
        }

        $statuses = $domainInfo['status'] ?? [];
        if (!is_array($statuses)) $statuses = [$statuses];

        $clientStatuses = ['clientDeleteProhibited', 'clientTransferProhibited', 'clientUpdateProhibited'];
        $serverStatuses = ['serverDeleteProhibited', 'serverTransferProhibited', 'serverUpdateProhibited'];

        // Check if all client statuses are present in the $statuses array
        $clientProhibited = count(array_intersect($clientStatuses, $statuses)) === count($clientStatuses);

        // Check if all server statuses are present in the $statuses array
        $serverProhibited = count(array_intersect($serverStatuses, $statuses)) === count($serverStatuses);

        if ($clientProhibited || $serverProhibited) {
           $locked = 1;
        } else {
           $locked = 0;
        }

        $authInfo = $domainInfo['authInfo'] ?? null;

        // Prepare the UPDATE statement
        $stmt = $pdo->prepare('UPDATE service_domain SET ns1 = :ns1, ns2 = :ns2, ns3 = :ns3, ns4 = :ns4, expires_at = :expires_at, locked = :locked, synced_at = :synced_at, transfer_code = :transfer_code WHERE sld = :sld AND tld = :tld');
        $stmt->bindValue(':ns1', $ns1);
        $stmt->bindValue(':ns2', $ns2);
        $stmt->bindValue(':ns3', $ns3);
        $stmt->bindValue(':ns4', $ns4);
        $stmt->bindValue(':expires_at', $formattedExDate);
        $stmt->bindValue(':locked', $locked);
        $stmt->bindValue(':synced_at', date('Y-m-d H:i:s'));
        $stmt->bindValue(':transfer_code', $authInfo);
        $stmt->bindValue(':sld', $domainRow['sld']);
        $stmt->bindValue(':tld', $domainRow['tld']);
        $stmt->execute();

        $stmt = $pdo->prepare('SELECT id FROM service_domain WHERE sld = :sld AND tld = :tld');
        $stmt->bindValue(':sld', $domainRow['sld']);
        $stmt->bindValue(':tld', $domainRow['tld']);
        $stmt->execute();
        $serviceDomain = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($serviceDomain) {
            $serviceId = $serviceDomain['id'];
            $stmt = $pdo->prepare('UPDATE client_order SET expires_at = :expires_at WHERE service_id = :service_id');
            $stmt->bindValue(':expires_at', $formattedExDate);
            $stmt->bindValue(':service_id', $serviceId);
            $stmt->execute();

            if ($count > 0) {
                $stmt = $pdo->prepare('SELECT registrant_contact_id FROM domain_meta WHERE domain_id = :id');
                $stmt->bindValue(':id', $serviceId);
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $registrant_contact_id = $result['registrant_contact_id'] ?? null;

                if (!empty($registrant_contact_id)) {
                    $contactInfo = $epp->contactInfo(['contact' => $registrant_contact_id]);
                    
                    if (isset($contactInfo['error'])) {
                        echo $contactInfo['error'];
                    }

                    try {
                        $stmt = $pdo->prepare('
                            UPDATE service_domain 
                            SET 
                                contact_email = :contact_email,
                                contact_company = :contact_company,
                                contact_first_name = :contact_first_name,
                                contact_last_name = :contact_last_name,
                                contact_address1 = :contact_address1,
                                contact_address2 = :contact_address2,
                                contact_city = :contact_city,
                                contact_state = :contact_state,
                                contact_postcode = :contact_postcode,
                                contact_country = :contact_country,
                                contact_phone_cc = :contact_phone_cc,
                                contact_phone = :contact_phone
                           WHERE id = :id
                        ');

                        // Split name into first and last names
                        $nameParts = explode(' ', $contactInfo['name'] ?? '');
                        $contactFirstName = array_shift($nameParts);
                        $contactLastName = implode(' ', $nameParts);

                        // Split phone into country code and number
                        $phoneParts = explode('.', $contactInfo['voice'] ?? '');
                        $contactPhoneCC = isset($phoneParts[0]) ? $phoneParts[0] : null;
                        $contactPhone = isset($phoneParts[1]) ? $phoneParts[1] : null;

                        $stmt->bindValue(':contact_email', !empty($contactInfo['email']) ? $contactInfo['email'] : null);
                        $stmt->bindValue(':contact_company', !empty($contactInfo['org']) ? $contactInfo['org'] : null);
                        $stmt->bindValue(':contact_first_name', !empty($contactFirstName) ? $contactFirstName : null);
                        $stmt->bindValue(':contact_last_name', !empty($contactLastName) ? $contactLastName : null);
                        $stmt->bindValue(':contact_address1', !empty($contactInfo['street1']) ? $contactInfo['street1'] : null);
                        $stmt->bindValue(':contact_address2', !empty($contactInfo['street2']) ? $contactInfo['street2'] : null);
                        $stmt->bindValue(':contact_city', !empty($contactInfo['city']) ? $contactInfo['city'] : null);
                        $stmt->bindValue(':contact_state', !empty($contactInfo['state']) ? $contactInfo['state'] : null);
                        $stmt->bindValue(':contact_postcode', !empty($contactInfo['postal']) ? $contactInfo['postal'] : null);
                        $stmt->bindValue(':contact_country', !empty($contactInfo['country']) ? $contactInfo['country'] : null);
                        $stmt->bindValue(':contact_phone_cc', !empty($contactPhoneCC) ? $contactPhoneCC : null);
                        $stmt->bindValue(':contact_phone', !empty($contactPhone) ? $contactPhone : null);
                        $stmt->bindValue(':id', $serviceId);
                        $stmt->execute();

                        echo "Update successful for contact: ".$contactInfo['id'].PHP_EOL;

                    } catch (PDOException $e) {
                        exit("Database error: " . $e->getMessage().PHP_EOL);
                    }
                }
            }
        }

        if ($count > 0) {
            $roid = $domainInfo['roid'] ?? null;
            $registrant = $domainInfo['registrant'] ?? null;
            
            if (empty($roid) && empty($registrant) && empty($domainInfo['contact']) && empty($domainInfo['status'])) {
                // ignore
            } else {
                $selectStmt = $pdo->prepare('SELECT id FROM service_domain WHERE sld = :sld AND tld = :tld LIMIT 1');
                $selectStmt->bindValue(':sld', $domainRow['sld']);
                $selectStmt->bindValue(':tld', $domainRow['tld']);
                $selectStmt->execute();
                $domainId = $selectStmt->fetchColumn();

                $sqlMeta = '
                    INSERT INTO domain_meta (domain_id, registry_domain_id, registrant_contact_id, admin_contact_id, tech_contact_id, billing_contact_id, created_at, updated_at)
                    VALUES (:domain_id, :registry_domain_id, :registrant_contact_id, :admin_contact_id, :tech_contact_id, :billing_contact_id, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        registry_domain_id = VALUES(registry_domain_id),
                        registrant_contact_id = VALUES(registrant_contact_id),
                        admin_contact_id = VALUES(admin_contact_id),
                        tech_contact_id = VALUES(tech_contact_id),
                        billing_contact_id = VALUES(billing_contact_id),
                       updated_at = NOW();
                ';
                $stmtMeta = $pdo->prepare($sqlMeta);
                $stmtMeta->bindValue(':domain_id', $domainId);
                $stmtMeta->bindValue(':registry_domain_id', $roid);
                $stmtMeta->bindValue(':registrant_contact_id', $registrant);
                $admin_contact_id = null;
                $tech_contact_id = null;
                $billing_contact_id = null;
                foreach (($domainInfo['contact'] ?? []) as $contact) {
                    if ($contact['type'] === 'admin') {
                        $admin_contact_id = $contact['id'];
                    } elseif ($contact['type'] === 'tech') {
                        $tech_contact_id = $contact['id'];
                    } elseif ($contact['type'] === 'billing') {
                        $billing_contact_id = $contact['id'];
                    }
                }
                $stmtMeta->bindValue(':admin_contact_id', $admin_contact_id);
                $stmtMeta->bindValue(':tech_contact_id', $tech_contact_id);
                $stmtMeta->bindValue(':billing_contact_id', $billing_contact_id);
                $stmtMeta->execute();

                $status = $domainInfo['status'] ?? 'ok';
                $sqlStatus = '
                    INSERT INTO domain_status (domain_id, status, created_at)
                    VALUES (:domain_id, :status, NOW())
                    ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        created_at = NOW();
                ';
                $stmtStatus = $pdo->prepare($sqlStatus);

                if (is_array($status)) {
                    foreach ($status as $singleStatus) {
                        $stmtStatus->bindValue(':domain_id', $domainId);
                        $stmtStatus->bindValue(':status', $singleStatus);
                        $stmtStatus->execute();
                    }
                } else {
                    $stmtStatus->bindValue(':domain_id', $domainId);
                    $stmtStatus->bindValue(':status', $status);
                    $stmtStatus->execute();
                }
            }

            echo "Update successful for domain: " . $domain . PHP_EOL;
        }
    }
} catch (PDOException $e) {
    exit("Database error: " . $e->getMessage().PHP_EOL);
} catch(EppException $e) {
    exit("Error: " . $e->getMessage().PHP_EOL);
} finally {
    epp_client_logout($epp);
}

function epp_client($config)
{
    $profile = $config['registry_profile'] ?? 'generic';

    $epp = EppRegistryFactory::create($profile);
    $epp->disableLogging();

    $tls_version = '1.2';
    if (!empty($config['tls_version'])) {
        $tls_version = '1.3';
    }
        
    $verify_peer = false;
    if ($config['verify_peer'] == 'on') {
        $verify_peer = true;
    }

    $moduleDir = __DIR__;

    $certPath = trim($config['local_cert'] ?? '');
    $keyPath  = trim($config['local_pk'] ?? '');

    if ($certPath === '' || $keyPath === '') {
        echo 'Client certificate and private key are required.';
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
        echo 'EPP TLS certificate or key not found or not readable. '
            . 'cert=' . ($certPath ?: 'false') . ' key=' . ($keyPath ?: 'false');
    }

    $info = [
        'host'    => $config['host'] ?? '',
        'port'    => (int)($config['port'] ?? 700),
        'timeout' => 30,
        'tls'     => $tls_version ?? '1.2',
        'bind'    => false,
        'bindip'  => '1.2.3.4:0',
        'verify_peer'      => !empty($verify_peer),
        'verify_peer_name' => false,
        'cafile'           => $config['cafile'] ?? '',
        'local_cert' => $certPath,
        'local_pk' => $keyPath,
        'passphrase'       => $config['passphrase'] ?? '',
        'allow_self_signed'=> true,
    ];
    if ($profile === 'generic') {
        $raw = $config['login_extensions'] ?? '';

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
        echo 'EPP host/port not configured';
    }

    $epp->connect($info);

    $login = $epp->login([
        'clID'   => $config['clid'] ?? '',
        'pw'     => $config['pw'] ?? '',
        'prefix' => $config['registrarprefix'] ?? 'epp',
    ]);

    if (isset($login['error'])) {
        echo 'Login Error: ' . $login['error'];
    }

    return $epp;
}

function epp_client_logout($epp)
{
    try { $epp->logout(); } catch (\Throwable $e) {}
}