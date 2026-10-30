<?php
/**
 * Offline test for the FRED nameserver-change and glue code in the patched epp.php.
 * A small in-memory "mini FRED" answers the EPP commands, so the real adapter methods
 * (modifyNs, supportsGlue, get/create/update/deleteGlueHost) run end to end without a network.
 *
 * Usage: php fred_ns_glue_test.php /path/to/patched/epp.php /path/to/tembo/src
 */
[$eppFile, $temboSrc] = [$argv[1] ?? '', $argv[2] ?? ''];

spl_autoload_register(function ($c) use ($temboSrc) {
    $p = 'Pinga\\Tembo\\';
    if (strpos($c, $p) === 0) {
        $f = $temboSrc . '/' . str_replace('\\', '/', substr($c, strlen($p))) . '.php';
        if (is_file($f)) require $f;
    }
});

// Minimal stand-ins for the FOSSBilling classes epp.php extends/uses.
class Registrar_Exception extends Exception {}
class Registrar_AdapterAbstract {
    public function getLog() { return new class { public function debug($m) {} }; }
}
class Registrar_Domain {
    public array $ns = [];
    public function __construct(public string $name) {}
    public function getName() { return $this->name; }
    public function getNs1() { return $this->ns[0] ?? null; }
    public function getNs2() { return $this->ns[1] ?? null; }
    public function getNs3() { return $this->ns[2] ?? null; }
    public function getNs4() { return $this->ns[3] ?? null; }
}
require $eppFile;

use Pinga\Tembo\Exception\EppException;

/** In-memory FRED: remembers domains and nssets, enforces the rules that matter here. */
class MiniFred extends Pinga\Tembo\Registries\FredEpp {
    public array $domains = [];   // name => nsset id
    public array $nssets  = [];   // id => ['ns' => [name => [ips]], 'tech' => [..], 'rl' => int]
    public array $log = [];

    public function __construct() { $this->isLoggedIn = true; $this->disableLogging(); }

    private function reply(string $inner = ''): SimpleXMLElement {
        return simplexml_load_string('<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>ok</msg></result>' . $inner . '</response></epp>');
    }

    public function writeRequest($xml) {
        $this->log[] = $xml;
        $x = simplexml_load_string($xml);
        $cmd = $x->command;

        if (isset($cmd->info)) {
            $d = $cmd->info->children('http://www.nic.cz/xml/epp/domain-1.4');
            if (isset($d->info)) {
                $name = (string) $d->info->name;
                if (!isset($this->domains[$name])) throw new EppException('Object does not exist');
                return $this->reply('<resData><domain:infData xmlns:domain="http://www.nic.cz/xml/epp/domain-1.4"><domain:name>' . $name
                    . '</domain:name><domain:roid>D1-EPP</domain:roid><domain:registrant>CID-1</domain:registrant><domain:nsset>'
                    . $this->domains[$name] . '</domain:nsset><domain:clID>REG-T</domain:clID></domain:infData></resData>');
            }
            $n = $cmd->info->children('http://www.nic.cz/xml/epp/nsset-1.2');
            if (isset($n->info)) {
                $id = (string) $n->info->id;
                if (!isset($this->nssets[$id])) throw new EppException('Object does not exist');
                $s = $this->nssets[$id];
                $out = '<nsset:id>' . $id . '</nsset:id>';
                foreach ($s['ns'] as $name => $ips) {
                    $out .= '<nsset:ns><nsset:name>' . $name . '</nsset:name>';
                    foreach ($ips as $ip) $out .= '<nsset:addr>' . $ip . '</nsset:addr>';
                    $out .= '</nsset:ns>';
                }
                foreach ($s['tech'] as $t) $out .= '<nsset:tech>' . $t . '</nsset:tech>';
                $out .= '<nsset:reportlevel>' . $s['rl'] . '</nsset:reportlevel>';
                return $this->reply('<resData><nsset:infData xmlns:nsset="http://www.nic.cz/xml/epp/nsset-1.2">' . $out . '</nsset:infData></resData>');
            }
        }

        if (isset($cmd->create)) {
            $n = $cmd->create->children('http://www.nic.cz/xml/epp/nsset-1.2');
            if (isset($n->create)) {
                $c = $n->create; $id = (string) $c->id;
                if (isset($this->nssets[$id])) throw new EppException('Object exists');
                $ns = [];
                foreach ($c->ns as $e) {
                    $name = (string) $e->name; $ns[$name] = [];
                    foreach ($e->addr as $a) $ns[$name][] = (string) $a;
                }
                if (count($ns) < 2) throw new EppException('Not enough nameservers');
                foreach ($ns as $name => $ips) {               // glue rule: names under a managed domain need an address
                    foreach (array_keys($this->domains) as $dom) {
                        if (str_ends_with($name, '.' . $dom) && !$ips) throw new EppException("Glue address required for $name");
                    }
                }
                $tech = []; foreach ($c->tech as $t) $tech[] = (string) $t;
                if (!$tech) throw new EppException('Tech contact required');
                $this->nssets[$id] = ['ns' => $ns, 'tech' => $tech, 'rl' => (int) $c->reportlevel];
                return $this->reply('<resData><nsset:creData xmlns:nsset="http://www.nic.cz/xml/epp/nsset-1.2"><nsset:id>' . $id . '</nsset:id></nsset:creData></resData>');
            }
        }

        if (isset($cmd->update)) {
            $d = $cmd->update->children('http://www.nic.cz/xml/epp/domain-1.4');
            if (isset($d->update)) {
                $name = (string) $d->update->name;
                $new = (string) $d->update->chg->nsset;
                if (!isset($this->domains[$name])) throw new EppException('Object does not exist');
                if (!isset($this->nssets[$new])) throw new EppException('nsset does not exist');
                $this->domains[$name] = $new;
                return $this->reply();
            }
        }

        if (isset($cmd->delete)) {
            $n = $cmd->delete->children('http://www.nic.cz/xml/epp/nsset-1.2');
            if (isset($n->delete)) {
                $id = (string) $n->delete->id;
                if (in_array($id, $this->domains, true)) throw new EppException('nsset is linked to a domain');
                unset($this->nssets[$id]);
                return $this->reply();
            }
        }

        throw new EppException('Unexpected command in test: ' . substr($xml, 0, 120));
    }
}

class TestAdapter extends Registrar_Adapter_EPP {
    public MiniFred $fred;
    public function epp_client() { return $this->fred; }
    public function epp_client_logout($epp) {}
}

$fail = 0;
function check(bool $c, string $m): void { global $fail; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; if (!$c) $fail++; }
function throws(callable $f, string $needle): bool {
    try { $f(); } catch (Registrar_Exception $e) { return str_contains($e->getMessage(), $needle); }
    return false;
}
function fresh(): array {
    $fred = new MiniFred();
    $fred->nssets['NSID-OLD'] = ['ns' => ['ns1.mytest786.org' => ['1.2.3.4'], 'ns2.example.net' => []], 'tech' => ['CID-1'], 'rl' => 0];
    $fred->domains['mytest786.org'] = 'NSID-OLD';
    $a = new TestAdapter(['registry_profile' => 'FRED', 'registrarprefix' => 'KANSAL']);
    $a->fred = $fred;
    return [$a, $fred, new Registrar_Domain('mytest786.org')];
}

// 1. supportsGlue
[$a, $f, $d] = fresh();
check($a->supportsGlue($d) === true, 'supportsGlue() is true for FRED');

// 2. glue info
$r = $a->getGlueHost($d, ['hostname' => 'ns1.mytest786.org']);
check($r['code'] === 1000 && $r['addr'] === ['1.2.3.4'], 'getGlueHost returns the nsset address list');
$r = $a->getGlueHost($d, ['hostname' => 'ns9.mytest786.org']);
check($r['addr'] === [], 'getGlueHost for a host that is not a nameserver -> empty addr');

// 3. glue update (replace IP): new nsset, domain switched, old nsset removed
$r = $a->updateGlueHost($d, ['hostname' => 'ns1.mytest786.org', 'currentipaddress' => '1.2.3.4', 'newipaddress' => '5.6.7.8']);
$id = $f->domains['mytest786.org'];
check($r['code'] === 1000, 'updateGlueHost returns 1000');
check($id !== 'NSID-OLD' && str_starts_with($id, 'NSID-') && str_ends_with($id, '-KANSAL'), "domain now uses a new nsset ($id)");
check($f->nssets[$id]['ns']['ns1.mytest786.org'] === ['5.6.7.8'], 'glue IP replaced (1.2.3.4 -> 5.6.7.8)');
check(array_keys($f->nssets[$id]['ns']) === ['ns1.mytest786.org', 'ns2.example.net'], 'other nameserver kept');
check($f->nssets[$id]['tech'] === ['CID-1'], 'tech contact carried over');
check(!isset($f->nssets['NSID-OLD']), 'old nsset deleted');

// 4. glue create adds a second address (IPv6 too)
$a->createGlueHost($d, ['hostname' => 'ns1.mytest786.org', 'ipaddress' => '2001:db8::1']);
$id = $f->domains['mytest786.org'];
check($f->nssets[$id]['ns']['ns1.mytest786.org'] === ['5.6.7.8', '2001:db8::1'], 'createGlueHost adds an address');

// 5. glue for a host that is not a nameserver yet: create adds it as a nameserver together with its address
$a->createGlueHost($d, ['hostname' => 'ns7.mytest786.org', 'ipaddress' => '9.9.9.9']);
$id = $f->domains['mytest786.org'];
check(($f->nssets[$id]['ns']['ns7.mytest786.org'] ?? null) === ['9.9.9.9'], 'createGlueHost on a new host adds it as a nameserver with its IP');
check(count($f->nssets[$id]['ns']) === 3, 'nameserver count is now 3');
check(throws(fn() => $a->updateGlueHost($d, ['hostname' => 'ns8.mytest786.org', 'newipaddress' => '9.9.9.9']), 'is not a nameserver'),
    'update/delete on a host that is not a nameserver -> clear error');
// host outside the domain is rejected by the existing validator
check(throws(fn() => $a->createGlueHost($d, ['hostname' => 'ns1.other.org', 'ipaddress' => '9.9.9.9']), 'within this domain'),
    'glue hostname outside the domain is rejected');
// deleting the only address of an in-domain nameserver is refused by the registry; domain must be left untouched
$before = $f->domains['mytest786.org'];
check(throws(fn() => $a->deleteGlueHost($d, ['hostname' => 'ns1.mytest786.org']), 'Glue address required'),
    'deleteGlueHost on in-domain NS -> registry error surfaced');
check($f->domains['mytest786.org'] === $before, 'failed change leaves the domain on its old nsset');
check(count(array_filter(array_keys($f->nssets), fn($k) => $k !== $before)) === 0, 'no orphan nsset left behind after a failure');

// 6. modifyNs: replace one NS, add a third
[$a, $f, $d] = fresh();
$d->ns = ['ns1.mytest786.org', 'ns3.example.org', 'NS4.Example.ORG.'];
check($a->modifyNs($d) === true, 'modifyNs returns true');
$id = $f->domains['mytest786.org'];
check(array_keys($f->nssets[$id]['ns']) === ['ns1.mytest786.org', 'ns3.example.org', 'ns4.example.org'], 'new nameserver set applied (lower-cased, dot stripped)');
check($f->nssets[$id]['ns']['ns1.mytest786.org'] === ['1.2.3.4'], 'existing glue address kept for ns1.mytest786.org');

// 7. modifyNs with no real change does not touch the registry
[$a, $f, $d] = fresh();
$d->ns = ['ns1.mytest786.org', 'ns2.example.net'];
$a->modifyNs($d);
check($f->domains['mytest786.org'] === 'NSID-OLD' && isset($f->nssets['NSID-OLD']), 'unchanged nameservers -> no new nsset');

// 8. fewer than two nameservers
[$a, $f, $d] = fresh();
$d->ns = ['ns1.example.net'];
check(throws(fn() => $a->modifyNs($d), 'at least two nameservers'), 'one nameserver -> clear error');
check($f->domains['mytest786.org'] === 'NSID-OLD', 'domain untouched after that error');

// 9. new in-domain nameserver without a known address: registry refuses, nothing is left half-done
[$a, $f, $d] = fresh();
$d->ns = ['ns1.mytest786.org', 'ns5.mytest786.org'];
check(throws(fn() => $a->modifyNs($d), 'Glue address required'), 'new in-domain NS without IP -> registry error surfaced');
check($f->domains['mytest786.org'] === 'NSID-OLD' && count($f->nssets) === 1, 'no half-applied change');


echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit($fail ? 1 : 0);
